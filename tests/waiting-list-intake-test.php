<?php
declare(strict_types=1);
/**
 * Offline unit tests for waiting-list intake (no network / no OSM).
 * Run: php tests/waiting-list-intake-test.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Osm\OsmApi;
use App\Waiting\WaitingListIntake;
use App\Waiting\WaitingListIntakeException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$failed = 0;
$passed = 0;

function expect_true(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "OK  $msg\n";
    } else {
        $failed++;
        echo "FAIL $msg\n";
    }
}

function expect_eq(mixed $got, mixed $want, string $msg): void
{
    expect_true($got === $want, $msg . ' (got ' . var_export($got, true) . ', want ' . var_export($want, true) . ')');
}

// --- parseNewMemberScoutId ---
expect_eq(WaitingListIntake::parseNewMemberScoutId(['result' => 'ok', 'scoutid' => 3100005]), 3100005, 'top-level int scoutid');
expect_eq(WaitingListIntake::parseNewMemberScoutId(['result' => 'ok', 'scoutid' => '3100005']), 3100005, 'top-level string scoutid');
expect_eq(WaitingListIntake::parseNewMemberScoutId(['data' => ['scoutid' => 99]]), 99, 'nested data.scoutid');
expect_eq(WaitingListIntake::parseNewMemberScoutId(['scout_id' => '42']), 42, 'scout_id alias');
expect_eq(WaitingListIntake::parseNewMemberScoutId(['member_id' => 7]), 7, 'member_id alias');
expect_eq(WaitingListIntake::parseNewMemberScoutId(['result' => 'ok']), 0, 'missing scoutid → 0');
expect_eq(WaitingListIntake::parseNewMemberScoutId(['scoutid' => 0]), 0, 'zero scoutid → 0');
expect_eq(WaitingListIntake::parseNewMemberScoutId(['scoutid' => 'abc']), 0, 'non-numeric scoutid → 0');

// --- normalizeContactFields ---
$norm = WaitingListIntake::normalizeContactFields([
    'line_1' => '1 High St',
    'line_3' => 'Ashby',
    'postcode' => 'LE65 1AA',
    'firstname' => 'Sam',
    '' => 'x',
    'empty' => '',
]);
expect_eq($norm['address1'] ?? null, '1 High St', 'line_1 → address1');
expect_eq($norm['address3'] ?? null, 'Ashby', 'line_3 → address3');
expect_eq($norm['postcode'] ?? null, 'LE65 1AA', 'postcode kept');
expect_eq($norm['firstname'] ?? null, 'Sam', 'firstname kept');
expect_true(!isset($norm['line_1']), 'line_1 key removed after alias');
expect_true(!isset($norm['empty']), 'empty values dropped');

// --- contactUpdatePath ---
expect_eq(
    WaitingListIntake::contactUpdatePath('60830'),
    '/ext/customdata/?action=update&section_id=60830',
    'contact update uses customdata path'
);

// --- buildContactUpdateForm ---
$form = WaitingListIntake::buildContactUpdateForm(12345, 6, [
    'line_1' => '1 High St',
    'postcode' => 'LE65 1AA',
]);
expect_eq($form['associated_type'] ?? null, 'member', 'associated_type');
expect_eq($form['associated_id'] ?? null, '12345', 'associated_id string');
expect_eq($form['group_id'] ?? null, '6', 'group_id 6 member');
expect_eq($form['context'] ?? null, 'members', 'context members');
expect_true(!isset($form['sectionid']), 'no sectionid in body');
expect_eq($form['data[address1]'] ?? null, '1 High St', 'data[address1] from line_1');
expect_eq($form['data[postcode]'] ?? null, 'LE65 1AA', 'data[postcode]');

$form1 = WaitingListIntake::buildContactUpdateForm(99, 1, [
    'firstname' => 'Parent',
    'lastname' => 'One',
    'email1' => 'p@example.org',
    'phone1' => '07700900123',
]);
expect_eq($form1['group_id'] ?? null, '1', 'group_id 1 primary contact');
expect_eq($form1['data[firstname]'] ?? null, 'Parent', 'contact firstname');
expect_eq($form1['data[email1]'] ?? null, 'p@example.org', 'contact email1');

// --- Full createMember flow with Guzzle mock (no network) ---
$history = [];
$mock = new MockHandler([
    new Response(200, [], json_encode(['result' => 'ok', 'scoutid' => 551122])),
    new Response(200, [], json_encode(['status' => true, 'error' => null, 'data' => ['postcode' => 'LE65 1AA']])),
    new Response(200, [], json_encode(['status' => true, 'error' => null, 'data' => ['firstname' => 'Parent']])),
]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$client = new Client(['handler' => $stack, 'http_errors' => false]);
$api = new OsmApi($client);

$payload = [
    'member' => [
        'firstname' => 'Rob',
        'lastname' => 'Test',
        'dob' => '2016-06-15',
        'started' => '2026-10-05',
        'startedsection' => '2026-10-05',
    ],
    'member_details' => [
        'line_1' => '1 High St',
        'postcode' => 'LE65 1AA',
    ],
    'contact1' => [
        'firstname' => 'Parent',
        'lastname' => 'One',
        'email1' => 'p@example.org',
        'phone1' => '07700900123',
    ],
];

$result = WaitingListIntake::createMember($api, 'fake-token', '60830', $payload, 1);
expect_eq($result['scoutid'] ?? null, 551122, 'createMember returns parsed scoutid');
expect_eq(count($history), 3, 'three OSM calls: newMember + member_details + contact1');

$req0 = $history[0]['request'];
$uri0 = (string) $req0->getUri();
expect_true(str_contains($uri0, 'ext/members/contact/actions/') && str_contains($uri0, 'action=newMember'), 'call 0 is newMember');
expect_eq($req0->getMethod(), 'POST', 'newMember is POST');
$body0 = (string) $req0->getBody();
expect_true(str_contains($body0, 'firstname=Rob'), 'newMember body has firstname');
expect_true(str_contains($body0, 'sectionid=60830'), 'newMember body has sectionid');
$ct0 = $req0->getHeaderLine('Content-Type');
expect_true(str_contains($ct0, 'application/x-www-form-urlencoded'), 'newMember is form-urlencoded');

$req1 = $history[1]['request'];
$uri1 = (string) $req1->getUri();
expect_true(str_contains($uri1, 'ext/customdata'), 'call 1 is customdata not members/contact');
expect_true(str_contains($uri1, 'action=update'), 'call 1 action=update');
expect_true(str_contains($uri1, 'section_id=60830'), 'call 1 section_id in query');
$body1 = urldecode((string) $req1->getBody());
expect_true(str_contains($body1, 'associated_id=551122'), 'update uses associated_id from parsed scoutid');
expect_true(str_contains($body1, 'group_id=6'), 'member details group_id 6');
expect_true(str_contains($body1, 'data[address1]=1 High St') || str_contains($body1, 'data[address1]=1+High+St'), 'address1 in form');
expect_true(str_contains($body1, 'data[postcode]=LE65'), 'postcode in form');
expect_true(!str_contains($body1, 'sectionid='), 'no sectionid in customdata body');

$req2 = $history[2]['request'];
$body2 = urldecode((string) $req2->getBody());
expect_true(str_contains((string) $req2->getUri(), 'ext/customdata'), 'call 2 is customdata');
expect_true(str_contains($body2, 'group_id=1'), 'contact1 group_id 1');
expect_true(str_contains($body2, 'data[firstname]=Parent'), 'contact1 firstname');

// --- Abort when scoutid missing (no further OSM calls) ---
$historyBad = [];
$mockBad = new MockHandler([
    new Response(200, [], json_encode(['result' => 'ok'])), // no scoutid
]);
$stackBad = HandlerStack::create($mockBad);
$stackBad->push(Middleware::history($historyBad));
$apiBad = new OsmApi(new Client(['handler' => $stackBad, 'http_errors' => false]));
$threw = false;
try {
    WaitingListIntake::createMember($apiBad, 'tok', '60830', $payload, 1);
} catch (WaitingListIntakeException $e) {
    $threw = true;
    expect_eq($e->status(), 502, 'missing scoutid → 502');
    expect_true(str_contains($e->getMessage(), 'member ID'), 'missing scoutid message');
}
expect_true($threw, 'throws when scoutid missing');
expect_eq(count($historyBad), 1, 'aborts after newMember — no update calls');

// --- Abort remaining steps when first update fails (HTTP 405) ---
$historyFail = [];
$mockFail = new MockHandler([
    new Response(200, [], json_encode(['result' => 'ok', 'scoutid' => 77])),
    new Response(405, [], json_encode(['error' => ['code' => 'parameter', 'message' => 'Invalid parameter']])),
]);
$stackFail = HandlerStack::create($mockFail);
$stackFail->push(Middleware::history($historyFail));
$apiFail = new OsmApi(new Client(['handler' => $stackFail, 'http_errors' => false]));
$threwFail = false;
try {
    WaitingListIntake::createMember($apiFail, 'tok', '60830', $payload, 1);
} catch (Throwable $e) {
    $threwFail = true;
    expect_true((int) $e->getCode() === 405 || str_contains($e->getMessage(), '405'), 'propagates 405');
}
expect_true($threwFail, 'throws on update HTTP error');
expect_eq(count($historyFail), 2, 'stops after failed member_details — no contact1 call');

// --- Abort when customdata returns status:false ---
$historyStatus = [];
$mockStatus = new MockHandler([
    new Response(200, [], json_encode(['result' => 'ok', 'scoutid' => 88])),
    new Response(200, [], json_encode(['status' => false, 'error' => 'nope'])),
]);
$stackStatus = HandlerStack::create($mockStatus);
$stackStatus->push(Middleware::history($historyStatus));
$apiStatus = new OsmApi(new Client(['handler' => $stackStatus, 'http_errors' => false]));
$threwStatus = false;
try {
    WaitingListIntake::createMember($apiStatus, 'tok', '60830', $payload, 1);
} catch (WaitingListIntakeException $e) {
    $threwStatus = true;
    expect_eq($e->status(), 502, 'status false → 502');
}
expect_true($threwStatus, 'throws when customdata status is false');
expect_eq(count($historyStatus), 2, 'no further calls after status:false');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
