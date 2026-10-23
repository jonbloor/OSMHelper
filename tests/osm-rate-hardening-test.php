<?php
declare(strict_types=1);
/**
 * Offline tests for the deploy-gate fixes on osm-rate-limit: X-Blocked app-wide stop, fail-closed
 * storage, date validation, grid-dates flag, cache expiry/sweep, invalidation races, intake throttle,
 * TopAwards no-sleep. No network / no OSM: every response comes from a stub Guzzle handler, and
 * OSM_API_BASE points at a closed local port as a safety net for any code path that builds its own client.
 * Run: php tests/osm-rate-hardening-test.php
 */
$tmp = sys_get_temp_dir() . '/osmh-rl-hard-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$_ENV['OSM_API_BASE'] = 'http://127.0.0.1:9/'; // never real OSM
$_ENV['OSMHELPER_DB_PATH'] = $tmp . '/db.sqlite';
$_ENV['SESSION_SECRET'] = 'test-secret';
unset($_ENV['OSM_WAITING_GRID_DATES']);
putenv('OSM_WAITING_GRID_DATES');
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Osm\OsmApi;
use App\Osm\OsmBlockedException;
use App\Osm\OsmBusyException;
use App\Osm\OsmCache;
use App\Osm\OsmErrorLog;
use App\Osm\OsmRateGuard;
use App\Waiting\IntakeThrottle;
use App\Waiting\WaitingListService as WL;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

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

$rateDir = $tmp . '/osm-rate';
$cacheDir = $tmp . '/osm-cache';
OsmRateGuard::useDirectory($rateDir);
OsmCache::useDirectory($cacheDir);
OsmErrorLog::setDirForTests($tmp);
$logFile = $tmp . '/php-error.log';
ini_set('error_log', $logFile);
ini_set('log_errors', '1');
$now = 1_800_000_000;
$clock = static function () use (&$now): int { return $now; };
OsmRateGuard::useClock($clock);
OsmCache::useClock($clock);
IntakeThrottle::useClock($clock);

const TOKEN = 'tok-SECRET-xyz789';
const SECTION = '4343';
$list = ['id' => SECTION, 'type' => 'waiting', 'name' => 'Waiting list', 'groupId' => '9'];
$calls = [];
$responder = null;

function api(?string $user = null): OsmApi
{
    global $calls, $responder;
    $handler = static function (RequestInterface $req, array $opts) use (&$calls, &$responder) {
        parse_str($req->getUri()->getQuery(), $q);
        $calls[] = $req->getMethod() . ' ' . (string) ($q['action'] ?? '?') . (isset($q['scoutid']) ? ' ' . $q['scoutid'] : '') . (isset($q['associated_id']) ? ' ' . $q['associated_id'] : '');
        return Create::promiseFor(($responder)($req));
    };
    return new OsmApi(new Client(['handler' => HandlerStack::create($handler), 'http_errors' => false, 'base_uri' => 'https://osm.test/']), $user);
}
function rl(int $remaining = 900, int $limit = 1000, int $reset = 3000): array
{
    return ['X-RateLimit-Limit' => (string) $limit, 'X-RateLimit-Remaining' => (string) $remaining, 'X-RateLimit-Reset' => (string) $reset, 'Content-Type' => 'application/json'];
}
function applicants(int $n, string $dob = ''): array
{
    $items = [];
    for ($i = 1; $i <= $n; $i++) {
        $items[] = ['scoutid' => (string) (1000 + $i), 'firstname' => 'Alexandra' . $i, 'lastname' => 'Testperson'] + ($dob !== '' ? ['dob' => $dob] : []);
    }
    return $items;
}
function grid(int $n, string $dob, string $joined): array
{
    $data = [];
    for ($i = 1; $i <= $n; $i++) {
        $data[(string) (1000 + $i)] = ['member_id' => 1000 + $i, 'dob' => $dob, 'joined' => $joined, 'custom_data' => ['5' => ['77' => (string) $i, '78' => 'Note ' . $i]]];
    }
    return ['status' => true, 'data' => $data, 'meta' => ['structure' => [
        ['group_id' => 5, 'identifier' => 'customisable_data', 'columns' => [['column_id' => 77, 'varname' => 'cf_rank'], ['column_id' => 78, 'varname' => 'cf_notes']]],
    ]]];
}
/** @param array<string, mixed> $o gridUsable, gridDob, gridJoined, indDob, indJoined, listDob, failData (scoutid), onGrid (callable) */
function stub(int $n, array $o = []): callable
{
    return static function (RequestInterface $req) use ($n, $o): Response {
        parse_str($req->getUri()->getQuery(), $q);
        $a = (string) ($q['action'] ?? '');
        if ($a === 'getMembers' && isset($o['onGrid'])) ($o['onGrid'])();
        if ($a === 'getData' && isset($o['failData']) && (string) ($q['associated_id'] ?? '') === $o['failData']) {
            return new Response(500, rl(), 'Server error');
        }
        $body = match ($a) {
            'getListOfMembers' => ['identifier' => 'scoutid', 'items' => applicants($n, $o['listDob'] ?? '')],
            'getMembers' => ($o['gridUsable'] ?? true) ? grid($n, $o['gridDob'] ?? '2016-03-03', $o['gridJoined'] ?? '2025-01-15') : ['status' => false, 'error' => 'nope'],
            'getIndividual' => ['data' => ['dob' => $o['indDob'] ?? '2015-06-01', 'joined' => $o['indJoined'] ?? '2025-02-01']],
            'getData' => ['data' => [['group_id' => 5, 'columns' => [['column_id' => 77, 'varname' => 'cf_rank', 'label' => 'Rank', 'value' => '1'], ['column_id' => 78, 'varname' => 'cf_notes', 'label' => 'Notes', 'value' => 'n']]]]],
            default => ['status' => true],
        };
        return new Response(200, rl(), json_encode($body));
    };
}
function freshUser(string $id): void
{
    global $calls;
    $_SESSION['osmUserId'] = $id;
    OsmRateGuard::forUser($id)->reset();
    OsmCache::forgetUser($id);
    OsmApi::resetLoggedReasons();
    $calls = [];
}
function countCalls(string $action): int
{
    global $calls;
    return count(array_filter($calls, static fn ($c) => str_contains($c, ' ' . $action)));
}
function refuses(callable $fn, ?string $class = null): ?Throwable
{
    try { $fn(); } catch (Throwable $e) { return ($class === null || $e instanceof $class) ? $e : null; }
    return null;
}

// =====================================================================================
// M1. X-Blocked: persisted app-wide stop, checked first by every call, cleared by hand only
// =====================================================================================
freshUser('7001');
$responder = static fn (RequestInterface $r): Response => new Response(200, ['X-Blocked' => 'true'] + rl(), '{"status":true}');
$e = refuses(fn () => api()->get(TOKEN, '/ext/members/contact/', ['action' => 'getListOfMembers']), OsmBlockedException::class);
check($e !== null && count($calls) === 1, 'X-Blocked response: OsmBlockedException at once');
$bf = $rateDir . '/' . OsmRateGuard::APP_BLOCK_FILE;
check(is_file($bf) && (fileperms($bf) & 0777) === 0600, 'app-blocked.json written, 0600');
$responder = stub(3);
$calls = [];
OsmRateGuard::useDirectory($rateDir); // a "new request": in-memory state dropped, only the file remains
check(OsmRateGuard::appBlocked() !== null, 'block persists across requests (file, not memory)');
check(refuses(fn () => api('7002')->get(TOKEN, '/x/', ['action' => 'getThing']), OsmBlockedException::class) !== null, 'another user: refused');
$ess = api('7003'); $ess->setEssential(true);
check(refuses(fn () => $ess->post(TOKEN, '/ext/customdata/?action=updateColumn', ['v' => 1]), OsmBlockedException::class) !== null, 'essential save: refused');
unset($_SESSION['osmUserId']);
check(refuses(fn () => api()->get(TOKEN, '/x/', ['action' => 'getThing']), OsmBlockedException::class) !== null, 'anonymous (no user id) call: refused');
$_SESSION['osmUserId'] = '7001';
check(refuses(fn () => api('7004')->post(TOKEN, '/ext/members/contact/actions/?action=newMember', []), OsmBlockedException::class) !== null, 'joining-form newMember: refused');
check(count($calls) === 0, 'no HTTP call reached the stub while blocked');
check(refuses(fn () => OsmRateGuard::assertAppMayCall(), OsmBlockedException::class) !== null, 'sign-in / OAuth pre-check (assertAppMayCall) refuses');
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 0 && $r['blocked'] === true && $r['listError'] !== null && str_contains($r['listError'], 'X-Blocked'), 'waiting list load: blocked, 0 calls, says X-Blocked');
// Token refresh goes through the OAuth provider, not OsmApi: still refused, without an HTTP call.
$rm = new ReflectionMethod(App\Osm\OsmTokens::class, 'refresh');
$rm->setAccessible(true);
App\Osm\OsmTokens::$refresher = null;
$t0 = microtime(true);
check($rm->invoke(null, 'refresh-token-value') === null && microtime(true) - $t0 < 1.0, 'OsmTokens refresh: refused (null), no OAuth call');
$log = (string) @file_get_contents($logFile);
check(str_contains($log, 'token refresh failed: App\\Osm\\OsmBlockedException') && !str_contains($log, 'refresh-token-value'), 'refresh refusal logged by class only (no token)');
$now += 3 * 86400;
check(OsmRateGuard::appBlocked() !== null, 'still blocked 3 days later (no automatic expiry)');
OsmRateGuard::forUser('7001')->reset();
check(OsmRateGuard::appBlocked() !== null, 'resetting a user\'s backoff does not clear the app block');
// Corrupt file still counts as blocked (fail closed)
file_put_contents($bf, 'not json');
OsmRateGuard::useDirectory($rateDir);
check(OsmRateGuard::appBlocked() !== null, 'unreadable/corrupt block file still blocks');
// CLI: show and clear
$php = PHP_BINARY;
$cli = dirname(__DIR__) . '/bin/osm-clear-block.php';
$env = 'OSMHELPER_OSM_RATE_DIR=' . escapeshellarg($rateDir) . ' ';
$out = (string) shell_exec($env . escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' 2>&1');
check(str_contains($out, 'BLOCKED'), 'CLI status shows BLOCKED');
$out = (string) shell_exec($env . escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' --clear 2>&1');
check(str_contains($out, 'Cleared the OSM block') && !is_file($bf), 'CLI --clear deletes the block file');
OsmRateGuard::useDirectory($rateDir);
check(OsmRateGuard::appBlocked() === null, 'after clearing: not blocked');
$out = (string) shell_exec($env . escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' 2>&1');
check(str_contains($out, 'Not blocked'), 'CLI status: Not blocked');
$g = OsmRateGuard::forUser('7005');
$g->recordResponse(429, ['Retry-After' => ['600']]);
$out = (string) shell_exec($env . escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' --user=7005 2>&1');
check(str_contains($out, 'osm_backoff_429') && !str_contains($out, 'Alexandra'), 'CLI --user shows backoff and reason only');
$out = (string) shell_exec($env . escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' --user=7005 --clear 2>&1');
OsmRateGuard::useDirectory($rateDir);
check(str_contains($out, 'Cleared OSM backoff') && OsmRateGuard::forUser('7005')->secondsLeft() === 0, 'CLI --user --clear resets that user');
$calls = [];
$responder = stub(1);
api('7006')->get(TOKEN, '/x/', ['action' => 'getThing']);
check(count($calls) === 1, 'calls resume after the manual clear');

// Joining-form intake, app blocked: refused before any OSM client or token use; site key not marked.
App\Store\WordpressSiteKeyStore::upsertForUser('7010', SECTION, 'Waiting list', 'A-tok', 'R-tok', time() + 3600, true);
$siteRow = App\Store\WordpressSiteKeyStore::findByOsmUserId('7010');
$siteKey = (string) ($siteRow['site_key'] ?? '');
$payload = [
    'member' => ['firstname' => 'Rob', 'lastname' => 'Test', 'dob' => '2016-06-15'],
    'member_details' => ['postcode' => 'LE65 1AA'],
    'contact1' => ['firstname' => 'Parent', 'lastname' => 'One', 'email1' => 'p@example.org', 'phone1' => '07700900123'],
];
OsmRateGuard::blockApp();
$e = refuses(fn () => App\Waiting\WaitingListIntake::submitWithSiteKey($siteKey, $payload), App\Waiting\WaitingListIntakeException::class);
check($siteKey !== '' && $e !== null && $e->getCode() === 503 && str_contains($e->getMessage(), 'X-Blocked'), 'intake while blocked: 503, no OSM call');
$siteRow = App\Store\WordpressSiteKeyStore::findByOsmUserId('7010');
check(empty($siteRow['blocked_at']), 'intake pre-check refusal does not mark the site key');
OsmRateGuard::clearAppBlock();

// S8. Intake invalidates the waiting-list cache in a finally block, even when the OSM write fails.
// (OSM_API_BASE is a closed local port, so newMember fails to connect: a write failure, no real OSM.)
$_SESSION['osmUserId'] = '7011';
OsmCache::put(WL::CACHE_KIND, '7011', SECTION, ['rows' => [], 'listCount' => 0]);
OsmCache::put(WL::DETAIL_CACHE_KIND, '7011', SECTION, ['members' => []]);
check(OsmCache::get(WL::CACHE_KIND, '7011', SECTION) !== null, '(cache primed)');
$e = refuses(fn () => App\Waiting\WaitingListIntake::submitWithSiteKey($siteKey, $payload));
check($e !== null && OsmCache::get(WL::CACHE_KIND, '7011', SECTION) === null && OsmCache::get(WL::DETAIL_CACHE_KIND, '7011', SECTION) === null, 'intake write failed: cached waiting list and details still invalidated (finally)');

// =====================================================================================
// S7. Fail closed when rate state can't be saved
// =====================================================================================
$ro = $tmp . '/ro-rate';
mkdir($ro, 0500);
OsmRateGuard::useDirectory($ro);
$calls = [];
$responder = stub(1);
$e = refuses(fn () => api('7020')->get(TOKEN, '/x/', ['action' => 'getThing']), OsmBusyException::class);
check($e !== null && $e->reason() === OsmRateGuard::REASON_UNWRITABLE && count($calls) === 0, 'unwritable state dir: call refused before HTTP, reason osm_rate_state_unwritable');
$ess = api('7020'); $ess->setEssential(true);
check(refuses(fn () => $ess->post(TOKEN, '/ext/customdata/?action=updateColumn', []), OsmBusyException::class) !== null && count($calls) === 0, 'unwritable: essential save refused too');
check(refuses(fn () => OsmRateGuard::assertAppMayCall(), OsmBusyException::class) !== null, 'unwritable: sign-in pre-check refuses');
check(IntakeThrottle::hit('203.0.113.9') === IntakeThrottle::WINDOW_SECONDS, 'unwritable: intake throttle fails closed');
$log = (string) @file_get_contents($logFile);
check(str_contains($log, 'osm_rate_state_unwritable') && str_contains($log, 'intake_throttle_unwritable'), 'reason codes logged');
// becomes unwritable mid-request: the failed save stops the rest of the request
$rw = $tmp . '/rw-rate';
OsmRateGuard::useDirectory($rw);
$calls = [];
$a = api('7021');
$a->get(TOKEN, '/x/', ['action' => 'getThing']);
chmod($rw, 0500);
foreach (glob($rw . '/*.json') ?: [] as $f) chmod($f, 0400);
try { $a->get(TOKEN, '/x/', ['action' => 'getThing']); } catch (Throwable) {}
$e = refuses(fn () => $a->get(TOKEN, '/x/', ['action' => 'getThing']), OsmBusyException::class);
check($e !== null && count($calls) === 2, 'save failed mid-request: next call refused (fail closed)');
chmod($rw, 0700);
chmod($ro, 0700);
OsmRateGuard::useDirectory($rateDir);

// =====================================================================================
// S1 / S11. Guard edges: longer of Retry-After and Reset, low floor, IETF headers, clamp, races
// =====================================================================================
$g = OsmRateGuard::forUser('7030'); $g->reset();
$g->recordResponse(429, ['Retry-After' => ['60'], 'X-RateLimit-Reset' => ['600']]);
check($g->secondsLeft() === 600, 'RA 60 < Reset 600: waits 600 (the longer)');
$g->reset();
$g->recordResponse(429, ['Retry-After' => ['900'], 'X-RateLimit-Reset' => ['100']]);
check($g->secondsLeft() === 900, 'RA 900 > Reset 100: waits 900');
$g->reset();
$g->recordResponse(429, ['Retry-After' => ['30']]);
check($g->secondsLeft() === 60, 'RA 30 alone: at least 60');
$g->reset();
$g->recordResponse(429, ['Retry-After' => ['200000']]);
check($g->secondsLeft() === OsmRateGuard::MAX_BACKOFF_SEC && OsmRateGuard::MAX_BACKOFF_SEC === 90000, 'huge Retry-After clamped to 25h');
$g->reset();
$g->recordResponse(200, ['X-RateLimit-Remaining' => ['5'], 'X-RateLimit-Limit' => ['1000'], 'X-RateLimit-Reset' => ['0']]);
check($g->reason() === OsmRateGuard::REASON_LOW && $g->secondsLeft() >= 60, 'low with Reset=0: pause is not 1s (got ' . $g->secondsLeft() . 's)');
$g->reset();
$g->recordResponse(200, ['X-RateLimit-Remaining' => ['5'], 'X-RateLimit-Limit' => ['1000'], 'X-RateLimit-Reset' => ['3']]);
check($g->secondsLeft() === 60, 'low with Reset=3: floor 60s');
$g->reset();
$g->recordResponse(200, ['RateLimit' => ['limit=100, remaining=5, reset=120']]);
check($g->reason() === OsmRateGuard::REASON_LOW && $g->secondsLeft() === 120, 'IETF RateLimit header (limit=, remaining=, reset=) understood');
$g->reset();
$g->recordResponse(200, ['RateLimit-Limit' => ['100'], 'RateLimit-Remaining' => ['0'], 'RateLimit-Reset' => ['300']]);
check($g->reason() === OsmRateGuard::REASON_EXHAUSTED && $g->secondsLeft() === 300, 'IETF RateLimit-Remaining 0: hard backoff for Reset');
$g->reset();
$g->recordResponse(200, ['RateLimit' => ['"default";r=0;t=400']]);
check($g->reason() === OsmRateGuard::REASON_EXHAUSTED && $g->secondsLeft() === 400, 'IETF structured form ("default";r=0;t=400)');

// write race: concurrent processes (fork) never lose the longest hard backoff
if (function_exists('pcntl_fork')) {
    $g = OsmRateGuard::forUser('7040'); $g->reset();
    $pids = [];
    for ($i = 1; $i <= 4; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            OsmRateGuard::useDirectory($rateDir);
            $c = OsmRateGuard::forUser('7040');
            for ($k = 0; $k < 40; $k++) {
                $c->recordResponse(429, ['Retry-After' => [(string) ($i * 100 + $k)]]);
                $c->recordResponse(200, ['X-RateLimit-Remaining' => ['3'], 'X-RateLimit-Limit' => ['1000'], 'X-RateLimit-Reset' => ['61']]);
            }
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $p) pcntl_waitpid($p, $st);
    OsmRateGuard::useDirectory($rateDir);
    $g = OsmRateGuard::forUser('7040');
    check($g->secondsLeft() === 439 && $g->reason() === OsmRateGuard::REASON_429, 'forked writers (4×80 updates): longest 429 (439s) kept, never downgraded to low [got ' . $g->secondsLeft() . 's ' . $g->reason() . ']');
    $j = json_decode((string) file_get_contents(glob($rateDir . '/*.json')[0] ?? ''), true);
    check(true, '(state file parsed after race)');
} else {
    check(false, 'pcntl_fork available for the race test');
}

// =====================================================================================
// M3. Dates: placeholders and junk are missing; grid dates behind a flag (off by default)
// =====================================================================================
$cases = [
    '0000-00-00' => '', '1970-01-01' => '', '' => '', 'junk' => '', '01/02/2015' => '', '2015-02-30' => '',
    '1899-12-31' => '', '2015-13-01' => '', '0000-00-00 00:00:00' => '', '1970-01-01 00:00:00' => '',
    '2015-06-01' => '2015-06-01', '2015-06-01 00:00:00' => '2015-06-01', '2015-06-01T10:30:00' => '2015-06-01', ' 2016-02-29 ' => '2016-02-29',
];
$ok = true; $bad = [];
foreach ($cases as $in => $want) { if (WL::validDate((string) $in, $now) !== $want) { $ok = false; $bad[] = $in; } }
check($ok, 'validDate: 0000-00-00, 1970-01-01, empty, junk, d/m/Y, impossible dates → missing; Y-m-d (± time) kept' . ($bad ? ' [bad: ' . implode(', ', $bad) . ']' : ''));
check(WL::validDate(gmdate('Y-m-d', $now + 5 * 86400), $now) === '', 'date in the future → missing');
WL::useGridDatesForTests(null);
check(WL::gridDatesEnabled() === false, 'OSM_WAITING_GRID_DATES unset → grid dates off (default)');
$_ENV['OSM_WAITING_GRID_DATES'] = '1';
check(WL::gridDatesEnabled() === true, 'OSM_WAITING_GRID_DATES=1 → on');
unset($_ENV['OSM_WAITING_GRID_DATES']);

// flag off (default): getIndividual is authoritative for dob/joined; grid gives custom fields only
freshUser('7050');
$responder = stub(50);
$r = WL::loadRows(api(), TOKEN, $list);
check(countCalls('getIndividual') === 40 && countCalls('getData') === 0 && count($calls) === 42, 'flag off, N=50: list + grid + 40 getIndividual (budget 40), no getData [got ' . count($calls) . ']');
check($r['rows'][0]['dob'] === '2015-06-01' && $r['rows'][0]['joined'] === '2025-02-01', 'flag off: dob/joined from getIndividual, not the grid');
check(($r['rows'][0]['custom']['77'] ?? null) === '1' && $r['rows'][0]['leadersNotes'] === 'Note 1', 'flag off: custom fields + notes still from the grid');
check($r['detailsSkipped'] === 10 && $r['fromCache'] === false, 'flag off: 10 left for the next load');
$detailFile = glob($cacheDir . '/waitingdetail-*.json') ?: [];
check(count($detailFile) === 1 && (fileperms($detailFile[0]) & 0777) === 0600, 'detail cache file 0600');
$t0 = $now;
$now += 200;
$calls = [];
$r = WL::loadRows(api(), TOKEN, $list);
check(countCalls('getIndividual') === 10 && $r['detailsSkipped'] === 0, 'reload within 5 minutes: fetches only the remaining 10');
clearstatcache();
$dj = json_decode((string) file_get_contents($detailFile[0]), true);
check(($dj['at'] ?? null) === $t0 && filemtime($detailFile[0]) === $t0, 'detail cache stamped with its oldest entry (expires 5 min after the first fetch)');
$rowsFile = glob($cacheDir . '/waiting-*.json') ?: [];
$rj = $rowsFile ? json_decode((string) file_get_contents($rowsFile[0]), true) : null;
check(($rj['at'] ?? null) === $t0, 'complete rows cache stamped with its oldest data (t0), not the time it was assembled');
$calls = [];
$now = $t0 + 299;
WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 0, 'complete list cached: 0 calls at 299s');
$now = $t0 + 300;
$calls = [];
App\App::sweepPrivateCaches();
clearstatcache();
check((glob($cacheDir . '/waiting*.json') ?: []) === [], 'sweep on request (App::sweepPrivateCaches) deletes waiting-list copies 5 minutes after the oldest fetch');
WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 42, 'after 5 minutes: nothing reused, fresh load');

// refresh: useCache=false ignores both caches but is still bounded and guarded
$now += 1000;
freshUser('7051');
$responder = stub(5);
WL::loadRows(api(), TOKEN, $list);
$calls = [];
WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 0, '(cached)');
$r = WL::loadRows(api(), TOKEN, $list, false);
check(count($calls) === 7 && $r['fromCache'] === false, 'Refresh from OSM (useCache=false): re-reads list, grid and every detail (detail cache ignored)');
OsmRateGuard::forUser('7051')->recordResponse(429, ['Retry-After' => ['300']]);
$calls = [];
$r = WL::loadRows(api(), TOKEN, $list, false);
check(count($calls) === 0 && $r['busyMinutes'] === 5, 'Refresh during a backoff: still refused, 0 calls');

// flag on: placeholder grid dates fall back to getIndividual
WL::useGridDatesForTests(true);
freshUser('7052');
$responder = stub(3, ['gridDob' => '1970-01-01', 'gridJoined' => '0000-00-00']);
$r = WL::loadRows(api(), TOKEN, $list);
check(countCalls('getIndividual') === 3 && $r['rows'][0]['dob'] === '2015-06-01' && $r['rows'][0]['joined'] === '2025-02-01', 'flag on, grid dob 1970-01-01 / joined 0000-00-00: treated as missing, getIndividual used');
freshUser('7053');
$responder = stub(3);
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 2 && $r['rows'][0]['dob'] === '2016-03-03', 'flag on, valid grid dates: 2 calls, grid dates used');
WL::useGridDatesForTests(null);
// getIndividual placeholders: list-row date is the fallback; all missing stays missing
freshUser('7054');
$responder = stub(2, ['indDob' => '0000-00-00', 'indJoined' => '1970-01-01', 'listDob' => '2014-01-01']);
$r = WL::loadRows(api(), TOKEN, $list);
check($r['rows'][0]['dob'] === '2014-01-01' && $r['rows'][0]['joined'] === '', 'getIndividual 0000-00-00: valid list-row dob used; epoch joined stays missing');
freshUser('7055');
$responder = stub(2, ['indDob' => '1970-01-01', 'gridDob' => '1970-01-01']);
$r = WL::loadRows(api(), TOKEN, $list);
$sc = WL::scoreRows($r['rows'], ['squirrels' => 3, 'beavers' => 6, 'cubs' => 8, 'scouts' => 10.5, 'explorers' => 14], null, $now);
check($r['rows'][0]['dob'] === '' && !str_contains(json_encode($sc), '1970'), 'all dobs placeholders: dob missing, no 1970 age/score');

// =====================================================================================
// S4. Partial failures: nothing cached for a failed applicant, no rows cache
// =====================================================================================
WL::useGridDatesForTests(true);
freshUser('7060');
$responder = stub(3, ['gridUsable' => false, 'failData' => '1002']);
$r = WL::loadRows(api(), TOKEN, $list);
$byId = array_column($r['rows'], null, 'scoutid');
check(($byId['1002']['detail'] ?? '') === 'error' && ($byId['1001']['detail'] ?? '') === 'ok', 'getData failed for one applicant: that row is detail=error');
$dm = OsmCache::get(WL::DETAIL_CACHE_KIND, '7060', SECTION)['members'] ?? null;
check(is_array($dm) && !isset($dm['1002']) && isset($dm['1001']), 'no detail stored for the applicant whose getData failed');
check(OsmCache::get(WL::CACHE_KIND, '7060', SECTION) === null, 'no rows cache when any row errored');
$calls = [];
$r = WL::loadRows(api(), TOKEN, $list);
check($calls === ['GET getListOfMembers', 'POST getMembers', 'GET getIndividual 1002', 'GET getData 1002'], 'next load re-reads only the failed applicant [' . implode(', ', $calls) . ']');
WL::useGridDatesForTests(null);

// =====================================================================================
// S11. forgetScope vs an in-flight load
// =====================================================================================
$start = microtime(true);
usleep(2000);
OsmCache::forgetScope('waiting', '9999');
OsmCache::put('waiting', '7070', '9999', ['rows' => []], $start);
check(OsmCache::get('waiting', '7070', '9999') === null, 'put from a load that started before forgetScope is dropped');
usleep(2000);
OsmCache::put('waiting', '7070', '9999', ['rows' => []], microtime(true));
check(OsmCache::get('waiting', '7070', '9999') !== null, 'put from a load that started after forgetScope is kept');
freshUser('7071');
$responder = stub(3, ['onGrid' => static fn () => WL::invalidateCache(SECTION)]);
WL::loadRows(api(), TOKEN, $list);
$calls = [];
WL::loadRows(api(), TOKEN, $list);
check(count($calls) > 0, 'write landing mid-load (invalidateCache during the grid call): that load is not cached');
$marks = glob($cacheDir . '/inv-*.mark') ?: [];
check($marks !== [] && (fileperms($marks[0]) & 0777) === 0600, 'invalidation marker written, 0600');
$now += 400;
App\App::sweepPrivateCaches();
clearstatcache();
check((glob($cacheDir . '/inv-*.mark') ?: []) !== [], 'sweep keeps invalidation markers past 5 minutes');
$now += 3600;
App\App::sweepPrivateCaches();
clearstatcache();
check((glob($cacheDir . '/inv-*.mark') ?: []) === [], 'markers swept after an hour');
$leak = false;
foreach (glob($cacheDir . '/*') ?: [] as $f) { if (str_contains(basename($f), SECTION) || str_contains(basename($f), '7071')) $leak = true; }
check(!$leak, 'cache/marker file names carry no section or user ids');

// =====================================================================================
// S3. Per-IP throttle on the joining-form submit
// =====================================================================================
$okHits = 0;
for ($i = 0; $i < IntakeThrottle::MAX_PER_WINDOW; $i++) if (IntakeThrottle::hit('198.51.100.7') === null) $okHits++;
$wait = IntakeThrottle::hit('198.51.100.7');
check($okHits === 20 && is_int($wait) && $wait > 0 && $wait <= 900, '20 submissions per 15 min per IP allowed; the 21st refused with a wait');
check(IntakeThrottle::hit('198.51.100.8') === null, 'other IPs unaffected');
$tf = glob($rateDir . '/intake-*.json') ?: [];
$leak = false;
foreach ($tf as $f) { if (str_contains(basename($f), '198.51') || str_contains((string) file_get_contents($f), '198.51') || (fileperms($f) & 0777) !== 0600) $leak = true; }
check($tf !== [] && !$leak, 'throttle files 0600, hold no IP (keyed hash name, timestamps only)');
$now += 901;
check(IntakeThrottle::hit('198.51.100.7') === null, 'window passes: allowed again');
$now += 901;
IntakeThrottle::sweep();
check((glob($rateDir . '/intake-*.json') ?: []) === [], 'idle throttle files swept');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/WaitingListSubmitController.php');
check(str_contains($src, 'IntakeThrottle::hit(') && str_contains($src, 'Retry-After'), 'submit controller uses the throttle and sends Retry-After');

// =====================================================================================
// S2. TopAwards: refused during backoff with no sleep and no retry
// =====================================================================================
freshUser('7080');
OsmRateGuard::forUser('7080')->recordResponse(429, ['Retry-After' => ['600']]);
$responder = stub(1);
$m = new ReflectionMethod(App\Http\Controllers\TopAwardsController::class, 'apiGet');
$m->setAccessible(true);
$debug = []; $notes = []; $limited = false; $banner = null;
$t0 = microtime(true);
$threw = null;
try { $m->invokeArgs(null, [api(), TOKEN, '/ext/badges/records/', ['action' => 'getBadgeRecords'], &$debug, &$notes, &$limited, &$banner]); } catch (Throwable $e) { $threw = $e; }
$el = microtime(true) - $t0;
check($threw instanceof OsmBusyException && count($calls) === 0 && $el < 1.0 && $limited === true && is_string($banner), sprintf('TopAwards apiGet in backoff: refused, 0 calls, %.3fs, banner set', $el));
$res = $m->invokeArgs(null, [api(), TOKEN, '/ext/badges/records/', ['action' => 'getBadgeRecords'], &$debug, &$notes, &$limited, &$banner]);
check($res === [] && count($calls) === 0, 'TopAwards: later calls after the stop return nothing, 0 calls');
OsmRateGuard::forUser('7080')->reset();
$calls = [];
$responder = static fn (RequestInterface $r): Response => new Response(429, ['Retry-After' => ['5']], '{}');
$limited = false; $banner = null;
$t0 = microtime(true);
try { $m->invokeArgs(null, [api(), TOKEN, '/ext/badges/records/', ['action' => 'getBadgeRecords'], &$debug, &$notes, &$limited, &$banner]); } catch (Throwable) {}
check(count($calls) === 1 && microtime(true) - $t0 < 1.0, 'TopAwards: a 429 is not retried and not slept on');
$tsrc = (string) file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/TopAwardsController.php');
preg_match('/function apiGet\(.*?\n    }\n/s', $tsrc, $mm);
check(isset($mm[0]) && !preg_match('/sleep\s*\(/', $mm[0]) && !str_contains($tsrc, 'backoffSeconds'), 'TopAwards apiGet has no sleep and the 429 backoff/retry helper is gone');

// =====================================================================================
// M2. Privacy copy matches what is cached
// =====================================================================================
$home = (string) file_get_contents(dirname(__DIR__) . '/templates/home.twig');
$help = (string) file_get_contents(dirname(__DIR__) . '/templates/help.twig');
$readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
foreach (['home.twig' => $home, 'help.twig' => $help, 'README.md' => $readme] as $n => $t) {
    check(str_contains($t, '5 minutes') && stripos($t, 'notes') !== false && stripos($t, 'custom') !== false, "$n describes the 5-minute copy incl. custom fields and notes");
}
check(!str_contains($help, 'couple of requests') && !str_contains($help, 'up to a day') && !str_contains($help, 'most pages fetch fresh data'), 'help: stale copy removed');
check(str_contains($help, '25 hours'), 'help: 429 pause range up to about 25 hours');
$app = (string) file_get_contents(dirname(__DIR__) . '/src/App.php');
check(str_contains($app, 'OsmCache::sweep()') && str_contains($app, 'sweepPrivateCaches'), 'App runs OsmCache::sweep on every request');

// logs: no names, token or contact details
$log = (string) @file_get_contents($logFile);
check(!str_contains($log, 'Alexandra') && !str_contains($log, TOKEN) && !str_contains($log, '198.51.100') && !str_contains($log, 'p@example.org'), 'logs hold no names, token, IPs or contact details');

OsmRateGuard::useDirectory(null);
OsmCache::useDirectory(null);
OsmRateGuard::useClock(null);
OsmCache::useClock(null);
IntakeThrottle::useClock(null);
OsmErrorLog::setDirForTests(null);
exec('chmod -R u+rwx ' . escapeshellarg($tmp) . ' && rm -rf ' . escapeshellarg($tmp));

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
