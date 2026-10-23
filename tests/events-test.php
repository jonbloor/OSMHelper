<?php
declare(strict_types=1);
/**
 * Offline tests for Events. No network / no OSM (Guzzle MockHandler only).
 * Run: php tests/events-test.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Events\EventList;
use App\Http\Controllers\EventsController;
use App\Osm\OsmApi;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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
/** @param list<Response> $responses @param array<int, mixed> $history */
function mockApi(array $responses, ?array &$history = null): OsmApi
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    \App\Osm\OsmRateGuard::forUser(null)->reset(); // fresh allowance state per scenario
    return new OsmApi(new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://osm.test/']));
}

$hdr = ['X-RateLimit-Limit' => '1000', 'X-RateLimit-Remaining' => '900', 'X-RateLimit-Reset' => '3000'];
// Sample shapes from the community OSM API docs (getEvents: ISO startdate; get: UK + startdate_g).
$main = ['identifier' => 'eventid', 'label' => 'name', 'items' => [
    ['eventid' => '3', 'name' => 'Winter Sleepover', 'startdate' => '2025-12-06', 'attachments' => false],
    ['eventid' => '1', 'name' => 'Summer Camp', 'startdate' => '2025-07-19'],
    ['eventid' => '4', 'name' => 'Undated idea', 'startdate' => '0000-00-00'],
    ['eventid' => '5', 'name' => 'Flagged in main list', 'startdate' => '19/06/2024', 'archived' => '1'],
    ['eventid' => '6', 'name' => '=HYPERLINK("http://x")', 'startdate_g' => '2026-01-10', 'startdate' => '10/01/2026'],
]];
$arch = ['identifier' => 'eventid', 'label' => 'name', 'items' => [
    ['eventid' => '2', 'name' => 'District Camp', 'startdate' => '2024-06-19', 'attachments' => false],
    ['eventid' => '1', 'name' => 'Summer Camp', 'startdate' => '2025-07-19'], // also in main list
]];

// Parsing
$p = EventList::parseItems($main);
check(count($p) === 5, 'parses all items');
check($p[0]['date'] === '2025-12-06' && $p[3]['date'] === '2024-06-19' && $p[4]['date'] === '2026-01-10', 'ISO, UK dd/mm/yyyy and startdate_g dates');
check($p[2]['date'] === null, '0000-00-00 treated as no date');
check($p[0]['archived'] === null && $p[3]['archived'] === true, 'archived from the row field only (null when absent)');
check(array_column(EventList::parseItems($arch), 'archived') === [null, null], 'archived-request rows are not forced archived');
check(EventList::parseItems([]) === [] && EventList::parseItems(['items' => 'x']) === [], 'empty / odd payloads give no rows');
check(EventList::parseItems(['data' => ['items' => [['eventid' => 9, 'name' => 'X', 'startdate' => '2024-01-01']]]])[0]['id'] === '9', 'items under data');
check(EventList::parseItems(['items' => [['eventid' => 1, 'name' => 'a']]])[0]['archived'] === null, 'no archived field: null');
foreach ([['archived' => 0], ['archived' => '0'], ['archived' => false], ['archived' => 'n']] as $f) {
    check(EventList::parseItems(['items' => [['eventid' => 1, 'name' => 'a'] + $f]])[0]['archived'] === false, 'not archived: ' . json_encode($f));
}
foreach ([['archived' => 1], ['archived' => true], ['archived' => 'yes']] as $f) {
    check(EventList::parseItems(['items' => [['eventid' => 1, 'name' => 'a'] + $f]])[0]['archived'] === true, 'archived: ' . json_encode($f));
}

// Merge + sort
$m = EventList::merge(EventList::parseItems($main), EventList::parseItems($arch, true));
check(array_column($m, 'id') === ['2', '5', '1', '3', '6', '4'], 'merged, deduped, date ascending (same day by name), undated last');
$byId = array_column($m, null, 'id');
check($byId['1']['archived'] === false, 'in both lists, no field: not archived');
check($byId['2']['archived'] === true, 'no field, only in archived reply: archived (fallback)');
check($byId['3']['archived'] === false && $byId['5']['archived'] === true, 'own field used');
// OSM ignores the archive params: second reply == first. Nothing is marked except by own field.
$same = EventList::merge(EventList::parseItems($main), EventList::parseItems($main));
check(array_column($same, 'archived', 'id') === ['5' => true, '1' => false, '3' => false, '6' => false, '4' => false], 'params ignored: only own-field archived');
check(count($same) === 5, 'params ignored: deduped by id');
// Own field beats the fallback both ways
$own = EventList::merge(
    EventList::parseItems(['items' => [['eventid' => '10', 'name' => 'A', 'startdate' => '2025-01-01', 'archived' => '1']]]),
    EventList::parseItems(['items' => [['eventid' => '11', 'name' => 'B', 'startdate' => '2025-01-02', 'archived' => '0'], ['eventid' => '10', 'name' => 'A', 'startdate' => '2025-01-01']]])
);
check(array_column($own, 'archived', 'id') === ['10' => true, '11' => false], 'own field wins over list membership');
$s = EventList::sort([
    ['id' => 'b', 'name' => 'beta', 'date' => '2025-01-01', 'archived' => false],
    ['id' => 'a', 'name' => 'Alpha', 'date' => '2025-01-01', 'archived' => false],
    ['id' => 'c', 'name' => 'Old', 'date' => '2020-05-05', 'archived' => false],
]);
check(array_column($s, 'id') === ['c', 'a', 'b'], 'same day sorts by name');

// Local date filtering
check(count(EventList::filter($m, null, null)) === 6, 'all time keeps everything incl. undated');
check(array_column(EventList::filter($m, '2025-01-01', '2025-12-31'), 'id') === ['1', '3'], 'range inclusive');
check(array_column(EventList::filter($m, '2025-07-19', '2025-07-19'), 'id') === ['1'], 'single-day range includes that day');
check(array_column(EventList::filter($m, '2026-01-01', null), 'id') === ['6'], 'from only');
check(array_column(EventList::filter($m, null, '2024-12-31'), 'id') === ['2', '5'], 'to only');

// Range parsing (UK display)
check(EventsController::range([]) === [null, null, null, true, false], 'default is all time');
check(EventsController::range(['range' => 'all', 'from' => '01/01/2025']) === [null, null, null, true, false], 'All time ignores dates');
check(EventsController::range(['range' => 'dates', 'from' => '01/09/2025', 'to' => '31/08/2026']) === ['2025-09-01', '2026-08-31', null, false, false], 'dd/mm/yyyy parsed');
check(EventsController::range(['range' => 'dates', 'from' => '31/12/2026', 'to' => '01/01/2026']) === ['2026-01-01', '2026-12-31', null, false, true], 'reversed dates swapped and flagged');
$bad = EventsController::range(['range' => 'dates', 'from' => '31/02/2026']);
check($bad[3] === true && is_string($bad[2]), 'invalid date: error and all time');
check(EventsController::range(['range' => 'dates', 'from' => ['x']]) === [null, null, null, true, false], 'array param ignored');
check(EventList::ukDate('2026-03-07') === '07/03/2026' && EventList::ukDate(null) === '', 'UK display date');

// Load: exactly two GETs, one list call + one archived call, no per-event calls
unset($_SESSION['osmRateLimit']);
$api = mockApi([new Response(200, $hdr, json_encode($main)), new Response(200, $hdr, json_encode($arch)), new Response(200, $hdr, '{}')], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
check($r['stopReason'] === null && $r['calls'] === 2 && count($h) === 2, 'load uses exactly 2 calls');
check(count($r['events']) === 6, 'load merges both lists');
$u0 = (string) $h[0]['request']->getUri();
$u1 = (string) $h[1]['request']->getUri();
check($h[0]['request']->getMethod() === 'GET' && str_contains($u0, 'ext/events/summary/?action=getEvents&sectionid=55') && !str_contains($u0, 'onlyArchived') && !str_contains($u0, 'termid'), 'call 1: getEvents, no term, not archived-only');
check(str_contains($u1, 'action=getEvents&sectionid=55&onlyArchived=1&showArchived=true'), 'call 2: onlyArchived=1 and showArchived=true');
check($r['rateUnknown'] === false, 'rate headers seen');

// Empty lists (literal [] or items: []) are fine
$api = mockApi([new Response(200, $hdr, '[]'), new Response(200, $hdr, '{"identifier":"eventid","items":[]}')], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
check($r['stopReason'] === null && $r['events'] === [], 'literal [] and items:[] accepted as empty');
// Empty body / {} / whitespace are unusable: stop, nothing returned to store (cached list kept)
foreach (['empty body' => '', 'whitespace' => "  \n", 'empty object' => '{}'] as $label => $body) {
    foreach ([0, 1] as $which) {
        $resps = $which === 0
            ? [new Response(200, $hdr, $body), new Response(200, $hdr, json_encode($arch))]
            : [new Response(200, $hdr, json_encode($main)), new Response(200, $hdr, $body)];
        $api = mockApi($resps, $h);
        $r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
        check($r['events'] === null && count($h) === $which + 1 && str_contains((string) $r['stopReason'], 'unexpected reply'), "200 $label on call " . ($which + 1) . ': stops, nothing to store');
    }
}
check(EventList::badResponseReason([], 'empty-list') === null && EventList::badResponseReason([], 'empty') !== null
    && EventList::badResponseReason([], 'empty-object') !== null && EventList::badResponseReason([]) !== null, 'badResponseReason: [] only ok as a literal list');
// Controller-level rule: failed load leaves the stored copy alone
unset($_SESSION[EventList::SESSION_KEY]);
EventList::store('55', 'Beavers', [['id' => '1', 'name' => 'Kept', 'date' => '2025-01-01', 'archived' => false]], 2);
$api = mockApi([new Response(200, $hdr, '')], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
check($r['events'] === null && EventList::cached('55')['events'][0]['name'] === 'Kept', 'empty 200 does not wipe cached list');
unset($_SESSION[EventList::SESSION_KEY]);

// Logs carry only a reason code + section id, never OSM's error text
foreach (['error: Secret OSM text' => 'osm-error', 'not JSON' => 'not-json', 'status false' => 'status-false', 'empty reply' => 'empty-reply',
    'empty object' => 'empty-object', 'no event list' => 'no-event-list', 'weird: x' => 'other'] as $in => $code) {
    check(EventList::reasonCode($in) === $code, "reasonCode $code");
}
$logFile = tempnam(sys_get_temp_dir(), 'evlog');
$prevLog = ini_set('error_log', $logFile);
$osmLog = dirname(__DIR__) . '/storage/osm-errors.jsonl';
$api = mockApi([new Response(200, $hdr, json_encode(['error' => 'Secret OSM text for Jane Smith']))], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
ini_set('error_log', (string) $prevLog);
$logged = (string) file_get_contents($logFile);
@unlink($logFile);
check(str_contains($logged, 'section 55') && str_contains($logged, 'osm-error') && !str_contains($logged, 'Secret') && !str_contains($logged, 'Jane'), 'error_log: code + section only, no OSM text');
$lines = is_file($osmLog) ? file($osmLog, FILE_IGNORE_NEW_LINES) : [];
$last = json_decode((string) end($lines), true);
check(is_array($last) && $last['kind'] === 'bad-200' && $last['osm_code'] === 'osm-error' && $last['osm_message'] === null
    && $last['section_id'] === '55' && !str_contains((string) json_encode($last), 'Secret'), 'OSM error log bad-200: code + section only, no OSM text');

// Events permission (OAuth scope section:event:read)
check(str_contains(\App\Config::OAUTH_SCOPES, 'section:event:read'), 'OAuth scopes request section:event:read');
$saved = [$_SESSION['grantedScopes'] ?? null, $_SESSION['requestedScopes'] ?? null];
unset($_SESSION[EventList::SCOPE_DENIED_KEY], $_SESSION['grantedScopes'], $_SESSION['requestedScopes']);
check(EventList::scopeHint() === EventList::SCOPE_MESSAGE, 'no scopes recorded (old session): hint');
$_SESSION['requestedScopes'] = 'section:member:write section:badge:write';
check(EventList::scopeHint() === EventList::SCOPE_MESSAGE, 'requested without events: hint');
$_SESSION['requestedScopes'] = \App\Config::OAUTH_SCOPES;
check(EventList::scopeHint() === null, 'requested with events, nothing granted recorded: ok');
$_SESSION['grantedScopes'] = 'section:member:write section:quartermaster:write';
check(EventList::scopeHint() === EventList::SCOPE_MESSAGE, 'granted lacks events: hint (granted wins over requested)');
$_SESSION['grantedScopes'] = 'section:member:write section:event:read';
check(EventList::scopeHint() === null, 'granted has events: ok');
$_SESSION[EventList::SCOPE_DENIED_KEY] = true;
check(EventList::scopeHint() === EventList::SCOPE_MESSAGE, 'OSM refused earlier: hint');
unset($_SESSION[EventList::SCOPE_DENIED_KEY]);
check(str_contains(EventList::SCOPE_MESSAGE, "permission to read events") && str_contains(EventList::SCOPE_MESSAGE, 'Sign out and sign in again'), 'friendly scope message');

// Live case: 200 {"error":"You do not have access to this resource."} -> scope message, stop, no retry, cache kept
EventList::store('55', 'Beavers', [['id' => '1', 'name' => 'Kept', 'date' => '2025-01-01', 'archived' => false]], 2);
$api = mockApi([new Response(200, $hdr, json_encode(['error' => 'You do not have access to this resource.'])), new Response(200, $hdr, json_encode($arch))], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Challenger Scouts (Tuesday)']);
check($r['scopeDenied'] === true && $r['stopReason'] === EventList::SCOPE_MESSAGE && $r['calls'] === 1 && count($h) === 1, '200 access refusal: scope message, one call, no retry');
check(!empty($_SESSION[EventList::SCOPE_DENIED_KEY]) && EventList::cached('55')['events'][0]['name'] === 'Kept', 'access refusal: flagged for this session, cache kept');
unset($_SESSION[EventList::SCOPE_DENIED_KEY]);
$api = mockApi([new Response(200, $hdr, json_encode(['error' => ['message' => 'Insufficient permission']]))], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'B']);
check($r['scopeDenied'] === true && count($h) === 1, '200 error mentioning permission: scope message');
unset($_SESSION[EventList::SCOPE_DENIED_KEY]);
$api = mockApi([new Response(403, $hdr, '{"error":"Forbidden"}'), new Response(200, $hdr, json_encode($main))], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'B']);
check($r['scopeDenied'] === true && count($h) === 1 && !empty($_SESSION[EventList::SCOPE_DENIED_KEY]), 'HTTP 403: scope message, flagged, no retry');
unset($_SESSION[EventList::SCOPE_DENIED_KEY]);
$api = mockApi([new Response(401, $hdr, '{"error":"Unauthorised"}'), new Response(200, $hdr, json_encode($main))], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'B']);
check($r['scopeDenied'] === true && count($h) === 1 && empty($_SESSION[EventList::SCOPE_DENIED_KEY]), 'HTTP 401: scope message, not sticky (may be an expired token)');
$api = mockApi([new Response(200, $hdr, json_encode(['error' => 'Access denied']))], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'B']);
check($r['scopeDenied'] === false && str_contains((string) $r['stopReason'], 'unexpected reply'), 'other OSM errors keep the generic message');
$api = mockApi([new Response(500, $hdr, 'err')], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'B']);
check($r['scopeDenied'] === false, 'HTTP 500 is not a scope refusal');
unset($_SESSION[EventList::SESSION_KEY], $_SESSION[EventList::SCOPE_DENIED_KEY]);
[$g, $q] = $saved;
if ($g === null) { unset($_SESSION['grantedScopes']); } else { $_SESSION['grantedScopes'] = $g; }
if ($q === null) { unset($_SESSION['requestedScopes']); } else { $_SESSION['requestedScopes'] = $q; }

// OsmApi body shapes
foreach (['' => 'empty', ' ' => 'empty', '{}' => 'empty-object', '[]' => 'empty-list', ' [ ] ' => 'empty-list', '{"a":1}' => 'json',
    '<html>' => 'not-json', 'var x = [];' => 'empty-list', 'null' => 'not-json'] as $b => $kind) {
    check(OsmApi::bodyKind((string) $b) === $kind, 'bodyKind ' . json_encode($b) . " = $kind");
}
$api = mockApi([new Response(200, $hdr, '{}')], $h);
$api->get('tok', '/x');
check($api->lastBodyKind() === 'empty-object', 'lastBodyKind recorded per request');

// Bad 200 replies stop before the next call
$cases = [
    'not JSON' => '<html>Oops</html>',
    'error key' => json_encode(['error' => 'Access denied']),
    'status false' => json_encode(['status' => false, 'items' => []]),
    'no items' => json_encode(['identifier' => 'eventid']),
    'items not array' => json_encode(['items' => 'nope']),
];
foreach ($cases as $label => $body) {
    $api = mockApi([new Response(200, $hdr, $body), new Response(200, $hdr, json_encode($arch))], $h);
    $r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
    check($r['events'] === null && $r['calls'] === 1 && count($h) === 1 && str_contains((string) $r['stopReason'], 'unexpected reply'), "200 $label on call 1: stops, archived call not made");
}
$api = mockApi([new Response(200, $hdr, json_encode($main)), new Response(200, $hdr, '{"status":false}')], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
check($r['events'] === null && $r['calls'] === 2 && str_contains((string) $r['stopReason'], 'archived'), 'bad archived reply: nothing stored (no half list)');

// 429 / error / X-Blocked: stop, no retry, Retry-After shown
$api = mockApi([new Response(429, ['Retry-After' => '120'], '{"error":"Too many"}'), new Response(200, $hdr, json_encode($main))], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
check($r['calls'] === 1 && count($h) === 1 && str_contains((string) $r['stopReason'], 'about 2 minutes'), '429: stops, no retry, Retry-After shown');
$api = mockApi([new Response(200, $hdr, json_encode($main)), new Response(429, ['Retry-After' => '45'], '')], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
check($r['events'] === null && str_contains((string) $r['stopReason'], '45 seconds'), '429 on archived call: stops, nothing stored');
$api = mockApi([new Response(500, $hdr, 'err'), new Response(200, $hdr, json_encode($main))], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
check($r['calls'] === 1 && count($h) === 1 && str_contains((string) $r['stopReason'], 'without trying again'), 'HTTP 500: stops, no retry');
$api = mockApi([new Response(200, ['X-Blocked' => 'true'] + $hdr, json_encode($main)), new Response(200, $hdr, json_encode($arch))], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
check($r['calls'] === 1 && count($h) === 1 && str_contains((string) $r['stopReason'], 'blocked'), 'X-Blocked: stops at once');

// Low allowance: no call at all; low after call 1: archived call not made
$_SESSION['osmRateLimit'] = ['limit' => 1000, 'remaining' => 60, 'resetInSec' => 600, 'lastUpdatedMs' => (int) round(microtime(true) * 1000)];
$api = mockApi([new Response(200, $hdr, json_encode($main))], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
check($r['calls'] === 0 && $h === [] && str_contains((string) $r['stopReason'], 'allowance'), 'remaining <= 60: no OSM call');
unset($_SESSION['osmRateLimit']);
$low = ['X-RateLimit-Limit' => '1000', 'X-RateLimit-Remaining' => '55', 'X-RateLimit-Reset' => '3000'];
$api = mockApi([new Response(200, $low, json_encode($main)), new Response(200, $hdr, json_encode($arch))], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
check($r['calls'] === 1 && count($h) === 1 && $r['events'] === null, 'allowance drops to 55 after call 1: stops before call 2');

// Missing rate headers: flagged, slower second call
unset($_SESSION['osmRateLimit']);
$api = mockApi([new Response(200, [], json_encode($main)), new Response(200, [], json_encode($arch))], $h);
$t = microtime(true);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
check($r['rateUnknown'] === true && $r['calls'] === 2 && (microtime(true) - $t) >= 1.4, 'missing X-RateLimit headers: flagged and longer pause');

// Cooldown (per section, 5 min)
$now = 1_800_000_000;
$_SESSION[EventList::REFRESH_KEY] = [];
check(EventList::cooldownRemaining('55', $now) === 0, 'no cooldown before first load');
EventList::markRefreshed('55', $now);
check(EventList::cooldownRemaining('55', $now + 10) === 290, 'cooldown after load');
check(EventList::cooldownRemaining('55', $now + 300) === 0, 'cooldown over after 5 min');
check(EventList::cooldownRemaining('56', $now + 10) === 0, 'cooldown is per section');

// Cache (~2h), filtering uses the cache only
unset($_SESSION[EventList::SESSION_KEY]);
EventList::store('55', 'Beavers', $m, 2, $now);
check(EventList::cached('55', $now + 7000)['events'] === $m, 'cache kept under 2h');
check(EventList::cached('55', $now + 7200) === null && !isset($_SESSION[EventList::SESSION_KEY]['55']), 'cache cleared at 2h');
check(EventList::cached('99', $now) === null, 'no cache for other section');
for ($i = 0; $i < 14; $i++) {
    EventList::store((string) (100 + $i), 'S' . $i, [], 2, $now);
}
check(count($_SESSION[EventList::SESSION_KEY]) === EventList::MAX_CACHED_SECTIONS && !isset($_SESSION[EventList::SESSION_KEY]['100']), 'cache capped, oldest dropped');

// CSV: header, UK dates, Yes/No, escaping, formula guard
$csv = EventList::csv('Beavers', EventList::filter($m, null, null));
check(str_starts_with($csv, "\xEF\xBB\xBF" . "Section,\"Start date\",\"Event name\",Archived\n"), 'CSV header (with UTF-8 BOM)');
check(str_contains($csv, "Beavers,19/06/2024,\"Flagged in main list\",Yes\n") && str_contains($csv, "Beavers,06/12/2025,\"Winter Sleepover\",No\n"), 'CSV rows dd/mm/yyyy + Yes/No');
check(str_contains($csv, "Beavers,10/01/2026,\"'=HYPERLINK(\"\"http://x\"\")\",No\n"), 'formula cell prefixed with quote and quotes escaped');
check(str_contains($csv, "Beavers,,\"Undated idea\",No\n"), 'undated row has empty date');
$csvRange = EventList::csv('Beavers', EventList::filter($m, '2025-01-01', '2025-12-31'));
check(substr_count($csvRange, "\n") === 3, 'CSV respects date range (header + 2 rows)');
foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", ' =1+1', "  @x", "\u{00A0}=1", "\n-2", "\r=1"] as $v) {
    check(EventList::csvSafe($v) === "'" . $v, 'csvSafe guards ' . json_encode($v));
}
check(EventList::csvSafe('Camp - 2025') === 'Camp - 2025' && EventList::csvSafe('') === '', 'csvSafe leaves normal text');
check(EventList::csv('=Evil, "Section"', [])
    === "\xEF\xBB\xBFSection,\"Start date\",\"Event name\",Archived\n", 'empty CSV is header only');
$c2 = EventList::csv('=Evil, "Section"', [['id' => '1', 'name' => "Line\nbreak", 'date' => '2025-01-01', 'archived' => false]]);
check(str_contains($c2, "\"'=Evil, \"\"Section\"\"\",01/01/2025,\"Line\nbreak\",No"), 'section name guarded + commas/quotes/newlines escaped');
check(EventList::csvFilename('1st Ashby: Beavers (Mon)', null, null) === 'events-1st-ashby-beavers-mon-all-time.csv', 'filename all time');
check(EventList::csvFilename('Cubs', '2025-09-01', '2026-08-31') === 'events-cubs-20250901-to-20260831.csv', 'filename range');
check(EventList::csvFilename("\"../\r\n", '2025-09-01', null) === 'events-section-20250901-to-end.csv', 'filename safe from odd names');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
