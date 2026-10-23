<?php
declare(strict_types=1);
/**
 * Offline tests for Find a member. No network / no OSM (Guzzle MockHandler only).
 * Run: php tests/find-member-test.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Members\ContactIndex;
use App\Osm\OsmApi;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$failed = 0;
$passed = 0;
function check(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) { $passed++; echo "OK  $msg\n"; } else { $failed++; echo "FAIL $msg\n"; }
}

// Normalising
check(ContactIndex::normalisePostcode(' le65 1ab ') === 'LE651AB', 'postcode ignores spaces/case');
foreach (['07700 900123', '+44 7700 900123', '0044 (7700) 900-123', '+44 (0)7700 900123', '447700900123'] as $p) {
    check(ContactIndex::normalisePhone($p) === '7700900123', "phone $p normalises");
}
check(ContactIndex::normalisePhone('01530 412345') === '1530412345', 'landline drops trunk 0');

// Fixture grid: one with meta.structure, data keyed by scoutid
$grid = [
    'status' => true,
    'data' => [
        '101' => ['member_id' => 101, 'first_name' => 'Alex', 'last_name' => 'Smith', 'patrol' => 'Red', 'custom_data' => [
            '6' => ['11' => 'LE65 1AB', '18' => '01530 111222'],
            '1' => ['2' => 'Jane', '3' => 'Smith', '11' => 'le65 1ab', '18' => '07700 900123', '20' => '(0116) 496 0000'],
            '2' => ['2' => 'Tom', '3' => 'Jones', '11' => 'DE12 7XY', '18' => '+44 7700 900999'],
            '4' => ['2' => 'Dr', '3' => 'Who', '18' => '07700 900123'],
        ]],
        '102' => ['member_id' => 102, 'first_name' => 'Sam', 'last_name' => 'Lee', 'patrol' => 'Leaders', 'custom_data' => [
            '1' => ['2' => 'Leader', '3' => 'Parent', '11' => 'LE65 1AB'],
        ]],
    ],
    'meta' => ['structure' => [
        ['group_id' => 1, 'identifier' => 'contact_primary_1', 'columns' => [
            ['column_id' => 2, 'varname' => 'firstname'], ['column_id' => 3, 'varname' => 'lastname'],
            ['column_id' => 11, 'varname' => 'postcode'], ['column_id' => 18, 'varname' => 'phone1'], ['column_id' => 20, 'varname' => 'phone2'],
        ]],
    ]],
];
$rows = ContactIndex::parseGrid($grid, '55', 'Beavers');
check(count($rows) === 1, 'Leaders patrol row skipped');
check(count($rows[0]['contacts']) === 3, 'doctor group not indexed');

$r = ContactIndex::search($rows, 'le651', 'postcode');
check(count($r) === 2 && $r[0]['name'] === 'Alex Smith' && $r[0]['section'] === 'Beavers', 'partial postcode matches member + contact 1');
$r = ContactIndex::search($rows, '+44 7700 900 123', 'phone');
check(count($r) === 1 && $r[0]['contactName'] === 'Jane Smith' && $r[0]['field'] === 'Phone (phone1)', 'phone across +44/0 formats, doctor excluded');
$r = ContactIndex::search($rows, '0116-496', 'phone');
check(count($r) === 1 && $r[0]['field'] === 'Phone (phone2)', 'phone2 partial');
$r = ContactIndex::search($rows, 'jones', 'name');
check(count($r) === 1 && $r[0]['contactGroup'] === 'Primary contact 2', 'contact name');
$r = ContactIndex::search($rows, 'alex', 'name');
check($r === [], 'member own name not a contact-name match');
check(ContactIndex::search($rows, 'x', 'any') === [], 'too-short query returns nothing');

// Build: one POST per section, stops at first error, no retry.
$mock = new MockHandler([
    new Response(200, ['X-RateLimit-Limit' => '1000', 'X-RateLimit-Remaining' => '900', 'X-RateLimit-Reset' => '3000'], json_encode($grid)),
    new Response(429, ['Retry-After' => '60'], '{"error":"Too many"}'),
    new Response(200, [], json_encode($grid)),
]);
$history = [];
$stack = HandlerStack::create($mock);
$stack->push(\GuzzleHttp\Middleware::history($history));
\App\Osm\OsmRateGuard::forUser(null)->reset(); // fresh allowance state per scenario
$api = new OsmApi(new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://osm.test/']));
$secs = [
    ['section_id' => '55', 'section_name' => 'Beavers', 'section_type' => 'beavers', 'current_term_id' => 9],
    ['section_id' => '56', 'section_name' => 'Cubs', 'section_type' => 'cubs', 'current_term_id' => 9],
    ['section_id' => '57', 'section_name' => 'Scouts', 'section_type' => 'scouts', 'current_term_id' => 9],
];
$b = ContactIndex::build($api, 'tok', $secs);
check($b['calls'] === 2 && count($history) === 2, 'stopped after 429, no retry, third section not called');
check($b['loaded'] === ['Beavers'] && $b['skipped'] === ['Cubs', 'Scouts'] && $b['stopReason'] !== null, 'partial index reported');
check(str_contains((string) $history[0]['request']->getUri(), 'grid/?action=getMembers'), 'uses members grid endpoint');

// Low allowance: no call at all
$_SESSION['osmRateLimit'] = ['limit' => 1000, 'remaining' => 50, 'resetInSec' => 600, 'lastUpdatedMs' => (int) round(microtime(true) * 1000)];
$history = [];
$mock2 = new MockHandler([new Response(200, [], json_encode($grid))]);
$stack2 = HandlerStack::create($mock2);
$stack2->push(\GuzzleHttp\Middleware::history($history));
\App\Osm\OsmRateGuard::forUser(null)->reset(); // fresh allowance state per scenario
$b = ContactIndex::build(new OsmApi(new Client(['handler' => $stack2, 'http_errors' => false, 'base_uri' => 'https://osm.test/'])), 'tok', $secs);
check($b['calls'] === 0 && $history === [], 'low allowance: no OSM call');

// 200 responses that are not usable must stop the build before the next section.
unset($_SESSION['osmRateLimit']);
$hdr = ['X-RateLimit-Limit' => '1000', 'X-RateLimit-Remaining' => '900', 'X-RateLimit-Reset' => '3000'];
$cases = [
    'not JSON' => '<html>Oops</html>',
    'error key' => json_encode(['error' => 'Access denied']),
    'status false' => json_encode(['status' => false, 'data' => []]),
    'data not array' => json_encode(['status' => true, 'data' => 'nope']),
    // Empty bodies decode to [] in OsmApi; they must still stop and keep the old index.
    'empty body' => '',
    'empty object' => '{}',
    'empty list' => '[]',
];
foreach ($cases as $label => $body) {
    $history = [];
    $m = new MockHandler([new Response(200, $hdr, $body), new Response(200, $hdr, json_encode($grid))]);
    $st = HandlerStack::create($m);
    $st->push(\GuzzleHttp\Middleware::history($history));
    \App\Osm\OsmRateGuard::forUser(null)->reset(); // fresh allowance state per scenario
    $b = ContactIndex::build(new OsmApi(new Client(['handler' => $st, 'http_errors' => false, 'base_uri' => 'https://osm.test/'])), 'tok', $secs);
    check($b['calls'] === 1 && count($history) === 1 && $b['loaded'] === [] && $b['skipped'] === ['Beavers', 'Cubs', 'Scouts']
        && is_string($b['stopReason']) && str_contains($b['stopReason'], 'unexpected reply'), "200 $label: stops, next section not called");
    check(ContactIndex::shouldReplace($b) === false, "200 $label: existing index not replaced");
}
check(ContactIndex::badResponseReason(['status' => true, 'data' => []]) === null, 'valid empty grid accepted');

// Replace rule
check(ContactIndex::shouldReplace(['stopReason' => null, 'loaded' => []]), 'clean build replaces');
check(ContactIndex::shouldReplace(['stopReason' => 'x', 'loaded' => ['Beavers']]), 'partial build with a section replaces');
check(!ContactIndex::shouldReplace(['stopReason' => 'x', 'loaded' => []]), 'failed build keeps old index');

// 429 message surfaces Retry-After
$m = new MockHandler([new Response(429, ['Retry-After' => '120'], '{"error":"Too many"}')]);
\App\Osm\OsmRateGuard::forUser(null)->reset(); // fresh allowance state per scenario
$b = ContactIndex::build(new OsmApi(new Client(['handler' => HandlerStack::create($m), 'http_errors' => false, 'base_uri' => 'https://osm.test/'])), 'tok', [$secs[0]]);
check(str_contains((string) $b['stopReason'], 'about 2 minutes'), '429 Retry-After shown to user');

// Missing rate headers flagged
unset($_SESSION['osmRateLimit']);
$m = new MockHandler([new Response(200, [], json_encode($grid))]);
\App\Osm\OsmRateGuard::forUser(null)->reset(); // fresh allowance state per scenario
$b = ContactIndex::build(new OsmApi(new Client(['handler' => HandlerStack::create($m), 'http_errors' => false, 'base_uri' => 'https://osm.test/'])), 'tok', [$secs[0]]);
check($b['rateUnknown'] === true && $b['loaded'] === ['Beavers'], 'missing X-RateLimit headers flagged');

// Session index expiry (~2h)
$now = time();
$_SESSION[ContactIndex::SESSION_KEY] = ['rows' => [], 'builtAtTs' => $now - 7000];
check(ContactIndex::load($now) !== null, 'index kept under 2h');
$_SESSION[ContactIndex::SESSION_KEY] = ['rows' => [], 'builtAtTs' => $now - 7200];
check(ContactIndex::load($now) === null && !isset($_SESSION[ContactIndex::SESSION_KEY]), 'index cleared at 2h');
$_SESSION[ContactIndex::SESSION_KEY] = ['rows' => []];
check(ContactIndex::load($now) === null && !isset($_SESSION[ContactIndex::SESSION_KEY]), 'index without timestamp cleared');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
