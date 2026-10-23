<?php
declare(strict_types=1);
/**
 * Offline tests for OSM allowance protection (rate guard, cache, bulk waiting list load).
 * No network / no OSM: every response comes from a stub Guzzle handler in this file.
 * Run: php tests/waiting-list-rate-limit-test.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Osm\OsmApi;
use App\Osm\OsmBusyException;
use App\Osm\OsmCache;
use App\Osm\OsmErrorLog;
use App\Osm\OsmRateGuard;
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

// ---- isolated storage, clock and log -------------------------------------------------------
$tmp = sys_get_temp_dir() . '/osmh-rl-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
OsmRateGuard::useDirectory($tmp . '/osm-rate');
OsmCache::useDirectory($tmp . '/osm-cache');
OsmErrorLog::setDirForTests($tmp);
$logFile = $tmp . '/php-error.log';
ini_set('error_log', $logFile);
ini_set('log_errors', '1');
$now = 1_800_000_000;
$clock = static function () use (&$now): int { return $now; };
OsmRateGuard::useClock($clock);
OsmCache::useClock($clock);
// Sections 1-7 test the grid-dates mode (OSM_WAITING_GRID_DATES=1). The default (off) is section 8.
WL::useGridDatesForTests(true);

const TOKEN = 'tok-SECRET-abc123';
const SECTION = '4242';
$list = ['id' => SECTION, 'type' => 'waiting', 'name' => 'Waiting list', 'groupId' => '9'];

/** @var list<string> $calls  "METHOD action" for every HTTP request that reached the stub */
$calls = [];
/** @var callable(RequestInterface): Response $responder */
$responder = null;

function api(?string $user = null): OsmApi
{
    global $calls, $responder;
    $handler = static function (RequestInterface $req, array $opts) use (&$calls, &$responder) {
        parse_str($req->getUri()->getQuery(), $q);
        $calls[] = $req->getMethod() . ' ' . (string) ($q['action'] ?? '?');
        return Create::promiseFor(($responder)($req));
    };
    return new OsmApi(new Client(['handler' => HandlerStack::create($handler), 'http_errors' => false, 'base_uri' => 'https://osm.test/']), $user);
}

function rl(int $remaining = 900, int $limit = 1000, int $reset = 3000): array
{
    return ['X-RateLimit-Limit' => (string) $limit, 'X-RateLimit-Remaining' => (string) $remaining, 'X-RateLimit-Reset' => (string) $reset, 'Content-Type' => 'application/json'];
}

function applicants(int $n): array
{
    $items = [];
    for ($i = 1; $i <= $n; $i++) {
        $items[] = ['scoutid' => (string) (1000 + $i), 'firstname' => 'Alexandra' . $i, 'lastname' => 'Testperson', 'email1' => 'parent' . $i . '@example.com'];
    }
    return $items;
}

function grid(int $n, bool $withDates = true): array
{
    $data = [];
    for ($i = 1; $i <= $n; $i++) {
        $m = ['member_id' => 1000 + $i, 'first_name' => 'Alexandra' . $i, 'custom_data' => [
            '5' => ['77' => (string) $i, '78' => 'Note ' . $i, '79' => $i % 2 ? 'Yes' : ''],
            '1' => ['12' => 'parent' . $i . '@example.com'], // contact group: must be ignored
        ]];
        if ($withDates) {
            $m['dob'] = '2016-03-0' . (($i % 9) + 1);
            $m['joined'] = '2025-01-15';
        }
        $data[(string) (1000 + $i)] = $m;
    }
    return ['status' => true, 'data' => $data, 'meta' => ['structure' => [
        ['group_id' => 1, 'identifier' => 'contact_primary_member', 'columns' => [['column_id' => 12, 'varname' => 'email1']]],
        ['group_id' => 5, 'identifier' => 'customisable_data', 'columns' => [
            ['column_id' => 77, 'varname' => 'cf_rank'], ['column_id' => 78, 'varname' => 'cf_notes'], ['column_id' => 79, 'varname' => 'cf_willing'],
        ]],
    ]]];
}

/** Standard OSM stub: list, grid, getIndividual, getData, updateColumn. */
function standard(int $n, array $headers = [], bool $gridUsable = true): callable
{
    $headers = $headers ?: rl();
    return static function (RequestInterface $req) use ($n, $headers, $gridUsable): Response {
        parse_str($req->getUri()->getQuery(), $q);
        $a = (string) ($q['action'] ?? '');
        $body = match ($a) {
            'getListOfMembers' => ['identifier' => 'scoutid', 'items' => applicants($n)],
            'getMembers' => $gridUsable ? grid($n) : ['status' => false, 'error' => 'nope'],
            'getIndividual' => ['data' => ['dob' => '2015-06-01', 'joined' => '2025-02-01', 'firstname' => 'X']],
            'getData' => ['data' => [['group_id' => 5, 'columns' => [
                ['column_id' => 77, 'varname' => 'cf_rank', 'label' => 'Rank', 'value' => '1'],
                ['column_id' => 78, 'varname' => 'cf_notes', 'label' => 'Notes', 'value' => 'n'],
            ]]]],
            'updateColumn' => ['status' => true, 'data' => ['value' => 'x']],
            default => ['status' => true],
        };
        return new Response(200, $headers, json_encode($body));
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

$cutoffs = ['squirrels' => 3, 'beavers' => 6, 'cubs' => 8, 'scouts' => 10.5, 'explorers' => 14];

// ---- 1. constant call count, not 2N -------------------------------------------------------------
foreach ([1, 5, 50, 200] as $n) {
    freshUser('9001');
    $responder = standard($n);
    $r = WL::loadRows(api(), TOKEN, $list);
    check(count($calls) === 2, "N=$n applicants: 2 OSM calls (list + grid), not " . (2 * $n + 1) . ' [got ' . count($calls) . ']');
    check(count($r['rows']) === $n && $r['detailsSkipped'] === 0 && $r['fromCache'] === false, "N=$n: all rows loaded from the grid");
}
check($calls === ['GET getListOfMembers', 'POST getMembers'], 'calls are getListOfMembers then grid getMembers');
$row = $r['rows'][0];
check($row['dob'] === '2016-03-02' && $row['joined'] === '2025-01-15', 'grid dob / joined used');
check(($row['custom']['77'] ?? null) === '1' && ($row['custom']['78'] ?? null) === 'Note 1' && $row['customOk'] === true, 'grid custom (group 5) values keyed by column id');
check($row['leadersNotes'] === 'Note 1', 'cf_notes picked up as leaders notes');
check(!isset($row['custom']['12']) && !str_contains(json_encode($row), '@example.com'), 'contact group / emails not kept');

// Empty list: 1 call, no grid
freshUser('9001');
$responder = standard(0);
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 1 && $r['rows'] === [] && $r['listCount'] === 0, 'empty waiting list: 1 call, grid skipped');

// ---- 2. cache --------------------------------------------------------------------------------
freshUser('9001');
$responder = standard(30);
WL::loadRows(api(), TOKEN, $list);
$calls = [];
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 0 && $r['fromCache'] === true && count($r['rows']) === 30, 'second load within 5 minutes: 0 OSM calls (cache hit)');
$a = WL::loadApplicants(api(), TOKEN, $list, $cutoffs, ['willing_column_id' => '79', 'rank_column_id' => '77', 'notes_column_id' => '78']);
check(count($calls) === 0 && count($a['applicants']) === 30 && $a['fromCache'] === true, 'loadApplicants (index/rank/dashboard) also hits the cache');
check(isset($a['applicants'][0]['score']) || isset($a['applicants'][0]['baseScore']) || isset($a['applicants'][0]['ageYears']), 'cached rows are still scored');
$now += 200;
WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 0, 'still cached at 200s');
$now += 101;
WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 2, 'expired after 5 minutes: fresh load (2 calls)');
$calls = [];
$_SESSION['osmUserId'] = '9002';
OsmCache::forgetUser('9002');
OsmRateGuard::forUser('9002')->reset();
WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 2, 'cache is per OSM user (another user does not see it)');
$_SESSION['osmUserId'] = '9001';
$calls = [];
WL::invalidateCache(SECTION);
WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 2, 'invalidateCache (after a write) forces a fresh load');
$calls = [];
OsmCache::forgetCurrentUser();
WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 2, 'forgetCurrentUser (sign-out) clears the cache');
$calls = [];
$r = WL::loadRows(api(), TOKEN, $list, false);
check(count($calls) === 2 && $r['fromCache'] === false, 'useCache=false always reads OSM');
unset($_SESSION['osmUserId']);
$calls = [];
WL::loadRows(api(), TOKEN, $list);
WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 4, 'no user id: nothing is cached');
$_SESSION['osmUserId'] = '9001';

// cache files: private, no names / user id / token in file names, no token or contact details inside
WL::loadRows(api(), TOKEN, $list);
$files = glob($tmp . '/osm-cache/*') ?: [];
check($files !== [], 'cache file written');
check((fileperms($tmp . '/osm-cache') & 0777) === 0700, 'cache dir is 0700');
$allPrivate = true; $leak = false;
foreach ($files as $f) {
    if ((fileperms($f) & 0777) !== 0600) $allPrivate = false;
    $base = basename($f);
    $c = (string) file_get_contents($f);
    if (str_contains($base, '9001') || str_contains($base, 'Alexandra') || str_contains($base, SECTION)) $leak = true;
    if (str_contains($c, TOKEN) || str_contains($c, '@example.com')) $leak = true;
}
check($allPrivate, 'cache files are 0600');
check(!$leak, 'cache file names carry no ids/names; contents carry no token or contact details');

// ---- 3. grid unusable: bounded fallback, fills in over loads ----------------------------------
freshUser('9003');
$responder = standard(50, [], false);
$r = WL::loadRows(api(), TOKEN, $list);
$per = WL::DETAIL_FALLBACK_MAX;
check(count($calls) === 2 + 2 * $per, "grid unusable, N=50: capped at 2 + 2×$per calls (old code: 101) [got " . count($calls) . ']');
check($r['detailsSkipped'] === 50 - $per, 'the rest are marked skipped, not fetched');
$skipped = array_values(array_filter($r['rows'], static fn ($x) => $x['detail'] === 'skipped'));
check(count($skipped) === 50 - $per, 'skipped rows flagged detail=skipped');
$calls = [];
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 2 + 2 * $per && $r['detailsSkipped'] === 50 - 2 * $per, 'next load reuses fetched details and fetches the next batch');
$calls = [];
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 2 + 2 * (50 - 2 * $per) && $r['detailsSkipped'] === 0, 'third load completes the list');
$calls = [];
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 0 && $r['fromCache'] === true, 'complete load is cached: 0 calls');
$sc = WL::scoreRows([$skipped[0]], $cutoffs);
check(str_contains(var_export($sc, true), 'protect your OSM allowance'), 'skipped row explains why in its breakdown');

// ---- 4. a 429 stops everything at once, no retry -------------------------------------------------
freshUser('9004');
$responder = static function (RequestInterface $req): Response {
    parse_str($req->getUri()->getQuery(), $q);
    if (($q['action'] ?? '') === 'getMembers') {
        return new Response(429, rl(0) + ['Retry-After' => '120'], json_encode(['error' => 'Too many requests']));
    }
    return (standard(40))($req);
};
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 2, '429 on the grid: no further calls (no retry, no per-applicant fallback) [got ' . count($calls) . ']');
check($r['busyMinutes'] === 50, '429 Retry-After 120s but Reset 3000s → busy for the longer, 50 minutes [got ' . var_export($r['busyMinutes'], true) . ']');
check($r['detailsSkipped'] === 40, 'every applicant marked skipped');
$calls = [];
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 0, 'incomplete load was not cached, and the next load makes 0 calls during backoff');
check($r['listError'] !== null && str_contains($r['listError'], 'OSM is busy, try again in 50 minutes'), 'friendly "OSM is busy, try again in N minutes"');
$threw = null;
try { api()->get(TOKEN, '/ext/members/contact/', ['action' => 'getListOfMembers']); } catch (OsmBusyException $e) { $threw = $e; }
check($threw !== null && count($calls) === 0 && $threw->getCode() === 429, 'new OsmApi instance refuses a read during backoff (shared per-user state)');
$threw = null;
try { api()->post(TOKEN, '/ext/customdata/?action=updateColumn&section_id=1', ['value' => 'x']); } catch (OsmBusyException $e) { $threw = $e; }
check($threw !== null && count($calls) === 0, '429 backoff refuses even essential writes');
$w = WL::writeColumn(api(), TOKEN, SECTION, '1001', '5', '77', '3');
check(!$w['ok'] && !empty($w['notSent']) && !$w['forbidden'] && count($calls) === 0, 'writeColumn: not sent, no HTTP, not mistaken for a permission error');
$intakeApi = api('9004'); // e.g. WordPress form using this user's token
$threw = null;
try { $intakeApi->post(TOKEN, '/ext/members/contact/actions/?action=newMember', []); } catch (OsmBusyException $e) { $threw = $e; }
check($threw !== null && count($calls) === 0, 'token-owner client (WordPress form) shares the same backoff');
$now += 121;
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 0 && $r['busyMinutes'] !== null, 'Retry-After passed but Reset has not: still 0 calls');
$now += 3000;
$responder = standard(40);
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 2 && $r['busyMinutes'] === null, 'after the longer of Retry-After and Reset passes, calls resume');

// 429 on the very first call
freshUser('9005');
$responder = static fn (RequestInterface $req): Response => new Response(429, ['X-RateLimit-Reset' => '600'], '{}');
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 1 && $r['busyMinutes'] === 10, '429 on the list call: exactly 1 call; Reset 600s used when no Retry-After');
check(OsmRateGuard::forUser('9005')->reason() === OsmRateGuard::REASON_429, 'reason recorded');
freshUser('9005');
$responder = static fn (RequestInterface $req): Response => new Response(429, [], '{}');
try { api()->get(TOKEN, '/x/', ['action' => 'getThing']); } catch (Throwable) {}
check(OsmRateGuard::forUser('9005')->secondsLeft() === OsmRateGuard::DEFAULT_429_BACKOFF_SEC, '429 with no headers: default 15 minute backoff');
freshUser('9005');
$responder = static fn (RequestInterface $req): Response => new Response(429, ['Retry-After' => '0'], '{}');
try { api()->get(TOKEN, '/x/', ['action' => 'getThing']); } catch (Throwable) {}
check(OsmRateGuard::forUser('9005')->secondsLeft() >= OsmRateGuard::MIN_HARD_BACKOFF_SEC, 'Retry-After 0 still pauses at least a minute');
// a later 429 with a shorter wait never shortens the backoff
freshUser('9005');
$g = OsmRateGuard::forUser('9005');
$g->recordResponse(429, ['Retry-After' => ['900']]);
$g->recordResponse(429, ['Retry-After' => ['60']]);
$g->recordResponse(200, rl(20, 100) === [] ? [] : ['X-RateLimit-Remaining' => ['10'], 'X-RateLimit-Limit' => ['100']]);
check($g->secondsLeft() === 900 && $g->reason() === OsmRateGuard::REASON_429, 'hard backoff never shortened or downgraded to "low"');

// state file: private, no token, no names
$sf = glob($tmp . '/osm-rate/*.json') ?: [];
check($sf !== [] && (fileperms($tmp . '/osm-rate') & 0777) === 0700, 'backoff state dir is 0700');
$ok = $sf !== [];
foreach ($sf as $f) {
    $c = (string) file_get_contents($f);
    if ((fileperms($f) & 0777) !== 0600 || str_contains($c, TOKEN) || str_contains($c, 'Alexandra') || str_contains(basename($f), '900')) $ok = false;
}
check($ok, 'backoff state files are 0600 and hold no token, names or user id');

// ---- 5. low remaining → backoff for non-essential calls ------------------------------------------
check(OsmRateGuard::isLow(99, 1000) && !OsmRateGuard::isLow(100, 1000), 'limit 1000: low below 100 (10%)');
check(OsmRateGuard::isLow(19, 100) && !OsmRateGuard::isLow(20, 100), 'limit 100: low below 20 (floor)');
check(OsmRateGuard::isLow(19, null) && !OsmRateGuard::isLow(25, null), 'no limit header: low below 20');

freshUser('9006');
$responder = static fn (RequestInterface $req): Response => (standard(10, rl(95, 1000, 900)))($req);
$r = WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 1, 'remaining 95/1000 on the list call: grid (non-essential) not sent');
check($r['busyMinutes'] === 15, 'busy until the window resets (Reset 900s → 15 minutes)');
check(OsmRateGuard::forUser('9006')->reason() === OsmRateGuard::REASON_LOW, 'reason osm_rate_low');
$calls = [];
$threw = false;
try { api()->get(TOKEN, '/ext/members/contact/', ['action' => 'getListOfMembers']); } catch (OsmBusyException) { $threw = true; }
check($threw && count($calls) === 0, 'non-essential read refused while low (0 calls)');
$threw = false;
try { api()->post(TOKEN, '/ext/members/contact/grid/?action=getMembers', []); } catch (OsmBusyException) { $threw = true; }
check($threw && count($calls) === 0, 'POST read (action=get…) is non-essential too');
$responder = standard(10, rl(94, 1000, 890));
$res = api()->post(TOKEN, '/ext/customdata/?action=updateColumn&section_id=1', ['value' => 'x']);
check(count($calls) === 1 && ($res['status'] ?? null) === true, 'essential write (updateColumn) still allowed while low');
$e = api();
$e->setEssential(true);
$e->get(TOKEN, '/ext/customdata/', ['action' => 'getData']);
check(count($calls) === 2, 'setEssential(true) scope (confirmed save + its checks) allowed while low');
$responder = standard(10, rl(900, 1000, 3600));
$e->get(TOKEN, '/ext/customdata/', ['action' => 'getData']);
check(OsmRateGuard::forUser('9006')->busyMinutes() === null, 'a healthy reading (window reset) lifts the low backoff');
$calls = [];
WL::loadRows(api(), TOKEN, $list);
check(count($calls) === 2, 'normal loading resumes');

// exhausted → hard backoff
freshUser('9007');
$responder = standard(10, rl(0, 1000, 300));
try { api()->get(TOKEN, '/x/', ['action' => 'getThing']); } catch (Throwable) {}
$calls = [];
$threw = false;
try { api()->post(TOKEN, '/ext/customdata/?action=updateColumn', []); } catch (OsmBusyException) { $threw = true; }
check($threw && count($calls) === 0 && OsmRateGuard::forUser('9007')->reason() === OsmRateGuard::REASON_EXHAUSTED, 'remaining 0: even essential calls refused until reset');

// ---- 6. header parsing ------------------------------------------------------------------------
check(OsmRateGuard::resetSeconds('120') === 120, 'Reset as seconds');
check(OsmRateGuard::resetSeconds((string) ($now + 300)) === 300, 'Reset as epoch');
check(OsmRateGuard::retryAfterSeconds('45') === 45, 'Retry-After seconds');
check(OsmRateGuard::retryAfterSeconds(gmdate('D, d M Y H:i:s', $now + 600) . ' GMT') === 600, 'Retry-After HTTP date');
check(OsmRateGuard::retryAfterSeconds('garbage') === null, 'bad Retry-After ignored');
check(OsmBusyException::friendly(61) === OsmBusyException::friendly(120) && str_contains(OsmBusyException::friendly(61), '2 minutes'), 'friendly message rounds up minutes');
check(str_contains(OsmBusyException::friendly(30), '1 minute,') || str_contains(OsmBusyException::friendly(30), '1 minute.') || str_contains(OsmBusyException::friendly(30), 'in 1 minute'), 'singular minute');

// ---- 7. logs: reason codes only ---------------------------------------------------------------
$log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
check(str_contains($log, 'osm_backoff_429') && str_contains($log, 'osm_rate_low'), 'reason codes logged');
check(!str_contains($log, 'Alexandra') && !str_contains($log, TOKEN) && !str_contains($log, '@example.com') && !str_contains($log, 'Too many requests'), 'log has no names, token, contact details or OSM text');
$errLog = is_file(OsmErrorLog::path()) ? (string) file_get_contents(OsmErrorLog::path()) : '';
check(!str_contains($errLog, 'Alexandra') && !str_contains($errLog, TOKEN), 'OSM error log has no names or token');

// cleanup
OsmRateGuard::useDirectory(null);
OsmCache::useDirectory(null);
OsmRateGuard::useClock(null);
OsmCache::useClock(null);
OsmErrorLog::setDirForTests(null);
exec('rm -rf ' . escapeshellarg($tmp));

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
