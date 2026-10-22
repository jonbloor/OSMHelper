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
check($p[0]['archived'] === false && $p[3]['archived'] === true, 'archived field honoured in main list');
check(array_column(EventList::parseItems($arch, true), 'archived') === [true, true], 'onlyArchived list marks all archived');
check(EventList::parseItems([]) === [] && EventList::parseItems(['items' => 'x']) === [], 'empty / odd payloads give no rows');
check(EventList::parseItems(['data' => ['items' => [['eventid' => 9, 'name' => 'X', 'startdate' => '2024-01-01']]]])[0]['id'] === '9', 'items under data');
foreach ([['archived' => 0], ['archived' => '0'], ['archived' => false], ['archived' => 'n'], []] as $f) {
    check(EventList::parseItems(['items' => [['eventid' => 1, 'name' => 'a'] + $f]])[0]['archived'] === false, 'not archived: ' . json_encode($f));
}
foreach ([['archived' => 1], ['archived' => true], ['archived' => 'yes']] as $f) {
    check(EventList::parseItems(['items' => [['eventid' => 1, 'name' => 'a'] + $f]])[0]['archived'] === true, 'archived: ' . json_encode($f));
}

// Merge + sort
$m = EventList::merge(EventList::parseItems($main), EventList::parseItems($arch, true));
check(array_column($m, 'id') === ['2', '5', '1', '3', '6', '4'], 'merged, deduped, date ascending (same day by name), undated last');
$byId = array_column($m, null, 'id');
check($byId['1']['archived'] === true && $byId['2']['archived'] === true && $byId['3']['archived'] === false, 'archived from the archived call wins on merge');
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
check(EventsController::range([]) === [null, null, null, true], 'default is all time');
check(EventsController::range(['range' => 'all', 'from' => '01/01/2025']) === [null, null, null, true], 'All time ignores dates');
check(EventsController::range(['range' => 'dates', 'from' => '01/09/2025', 'to' => '31/08/2026']) === ['2025-09-01', '2026-08-31', null, false], 'dd/mm/yyyy parsed');
check(EventsController::range(['range' => 'dates', 'from' => '31/12/2026', 'to' => '01/01/2026']) === ['2026-01-01', '2026-12-31', null, false], 'reversed dates swapped');
$bad = EventsController::range(['range' => 'dates', 'from' => '31/02/2026']);
check($bad[3] === true && is_string($bad[2]), 'invalid date: error and all time');
check(EventsController::range(['range' => 'dates', 'from' => ['x']]) === [null, null, null, true], 'array param ignored');
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
check(str_contains($u1, 'action=getEvents&sectionid=55&onlyArchived=1'), 'call 2: onlyArchived=1');
check($r['rateUnknown'] === false, 'rate headers seen');

// Empty lists ([] or items: []) are fine
$api = mockApi([new Response(200, $hdr, '[]'), new Response(200, $hdr, '{"identifier":"eventid","items":[]}')], $h);
$r = EventList::load($api, 'tok', ['section_id' => '55', 'section_name' => 'Beavers']);
check($r['stopReason'] === null && $r['events'] === [], 'empty lists accepted');

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
foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx"] as $v) {
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
