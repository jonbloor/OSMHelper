<?php
declare(strict_types=1);
/**
 * Offline tests for the Young Leaders page. Fake fixtures only: every OSM reply comes from a
 * Guzzle handler in this file, never the network.
 * Run: php tests/young-leaders-test.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/isolate-storage.php';

use App\Members\Patrols;
use App\Members\YoungLeaders as YL;
use App\Osm\OsmApi;
use App\Osm\OsmCache;
use App\Osm\OsmRateGuard;
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

$now = 1_800_000_000;
OsmCache::useClock(static function () use (&$now): int { return $now; });

// --- OsmCache: per-kind TTL ---------------------------------------------------------------
check(OsmCache::ttlFor('ylmember') === 86400 && OsmCache::ttlFor('ylsection') === 86400, 'Young Leaders kinds last 24 hours');
check(OsmCache::ttlFor('waiting') === OsmCache::TTL_SECONDS, 'other kinds keep the 5-minute TTL');
OsmCache::put('ylmember', 'u1', '10:1', ['others' => ['A: B']]);
OsmCache::put('waiting', 'u1', '10', ['rows' => []]);
$now += 3600;
OsmCache::sweep();
check(OsmCache::get('ylmember', 'u1', '10:1') !== null, 'sweep keeps a 1-hour-old Young Leaders entry');
check(OsmCache::get('waiting', 'u1', '10') === null, 'sweep still drops 5-minute kinds');
check(OsmCache::get('ylmember', 'u2', '10:1') === null, 'entry is owner-only');
// Sign-out (logout, session expiry and a new sign-in all call forgetCurrentUser) clears this
// user's Young Leaders copy, but not other users' files or other cache types.
OsmCache::put('ylsection', 'u1', '10', ['members' => []]);
OsmCache::put('ylmember', 'u2', '10:1', ['others' => ['A: B']]);
OsmCache::put('ylsection', 'u2', '10', ['members' => []]);
OsmCache::put('waiting', 'u2', '10', ['rows' => []]);
OsmCache::forgetScope('waiting', '99');
$marksBefore = glob(OsmCache::dir() . '/*.mark') ?: [];
$before = count(glob(OsmCache::dir() . '/*.json') ?: []);
$_SESSION = ['osmUserId' => 'u1'];
OsmCache::forgetCurrentUser();
check(OsmCache::get('ylmember', 'u1', '10:1') === null && OsmCache::get('ylsection', 'u1', '10') === null, 'sign-out deletes that user\'s Young Leaders cache (member and section)');
check(OsmCache::get('ylmember', 'u2', '10:1') !== null && OsmCache::get('ylsection', 'u2', '10') !== null, 'sign-out leaves other users\' Young Leaders cache');
check(OsmCache::get('waiting', 'u2', '10') !== null, 'sign-out leaves other users\' other cache types');
check((glob(OsmCache::dir() . '/*.mark') ?: []) === $marksBefore && $marksBefore !== [], 'sign-out leaves section invalidation markers');
check(count(glob(OsmCache::dir() . '/*.json') ?: []) === $before - 2, 'sign-out removed exactly the user\'s two files');
$_SESSION = [];
OsmCache::forgetUser('u2');
OsmCache::put('ylmember', 'u1', '10:1', ['others' => []]);
$now += 86400;
OsmCache::sweep();
check(glob(OsmCache::dir() . '/ylmember-*') === [], 'sweep deletes Young Leaders files after 24 hours');

// --- Privacy copy names the Young Leaders copy and that sign-out deletes it ----------------
$homeTpl = (string) file_get_contents(dirname(__DIR__) . '/templates/home.twig');
$readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
$helpTpl = (string) file_get_contents(dirname(__DIR__) . '/templates/help.twig');
check(str_contains($homeTpl, 'Young Leaders keeps a copy of members’ names, ages and the names of the other sections') && str_contains($homeTpl, 'deleted 24 hours after it was fetched or when you sign out'), 'home privacy copy covers the Young Leaders copy and sign-out');
check(str_contains($readme, 'Young Leaders copy, up to 24 hours') && str_contains($readme, 'deleted at sign-out'), 'README storage list covers the Young Leaders copy');
check(!str_contains($helpTpl, 'kept when you sign out'), 'Help no longer says the copy survives sign-out');

// --- Patrols helper ---------------------------------------------------------------------
check(Patrols::isYoungLeader('', '-3') && Patrols::isYoungLeader('', -3), 'patrol id -3 is the Young Leaders patrol');
check(Patrols::isYoungLeader('Young Leaders') && Patrols::isYoungLeader('YL Team') && Patrols::isYoungLeader('yls'), 'Young Leaders patrol by name');
check(!Patrols::isYoungLeader('Yellow') && !Patrols::isYoungLeader('', '-2') && !Patrols::isYoungLeader(''), 'other patrols are not Young Leaders');
// Members / Checks must classify exactly as before the Young Leaders branch (6e2732c),
// including whitespace-padded names. Old rule copied verbatim; Geoffrey's 25 inputs.
$oldYoungLeaderPatrol = static function (string $patrol): bool {
    $p = strtolower($patrol);
    if ($p === '') {
        return false;
    }
    if (str_contains($p, 'young leader')) {
        return true;
    }
    if ($p === 'yl' || $p === 'yls' || str_starts_with($p, 'yl ')) {
        return true;
    }
    return false;
};
$membersRule = (new ReflectionMethod(\App\Http\Controllers\MembersController::class, 'isYoungLeaderPatrol'))->getClosure(null);
$geoffInputs = ['', 'yl', 'YL', 'yls', 'Yls', 'yl a', 'YL Team', 'Young Leaders', 'young leader', 'Young Leaders ', ' yl', 'yl ', ' yls', 'yls ', '  yl team', 'Leaders', 'yla', 'YLU', 'ylx', "yl\t", "\tyl", 'Explorers YL', 'None', 'Lions', "yl\n"];
check(count($geoffInputs) === 25, 'Members/Checks comparison uses all 25 inputs');
$diffs = [];
foreach ($geoffInputs as $in) {
    if ($membersRule($in) !== $oldYoungLeaderPatrol($in) || Patrols::isYoungLeaderPatrolName($in) !== $oldYoungLeaderPatrol($in)) {
        $diffs[] = json_encode($in);
    }
}
check($diffs === [], 'Members/Checks Young Leader patrol rule matches the old rule on all 25 inputs' . ($diffs ? ' (differs: ' . implode(', ', $diffs) . ')' : ''));
check(!$membersRule(' yl') && !$membersRule("yl\t") && Patrols::isYoungLeader(' yl'), 'padded names: Members/Checks unchanged (no), Young Leaders page trims (yes)');
check(Patrols::isLeader('Leaders') && Patrols::isLeader('', '-2') && !Patrols::isLeader('Young Leaders', '-3'), 'Leaders patrol by name or id -2');

// --- Fixtures (fake people only) ---------------------------------------------------------
$fx = __DIR__ . '/fixtures/young-leaders';
$sections = json_decode((string) file_get_contents("$fx/sections.json"), true);
$listFx = json_decode((string) file_get_contents("$fx/members.json"), true);
$indFx = json_decode((string) file_get_contents("$fx/individuals.json"), true);

// --- Section picker (sign-in list, 0 calls) ------------------------------------------------
check(YL::isYoungLeaderSectionName('Group Young Leaders') && YL::isYoungLeaderSectionName('ACES ylu') && !YL::isYoungLeaderSectionName('Cubs'), 'Young Leader / YLU names, any case');
$choices = YL::sectionChoices($sections);
$ids = array_column($choices, 'id');
check(array_slice($ids, 0, 2) === ['7881', '7900'], 'Young Leaders sections listed first (incl. unknown-type YLU)');
check(in_array('7002', $ids, true) && !in_array('7999', $ids, true), 'other sections follow; waiting list left out');
check(YL::findChoice($choices, '7881')['isYoungLeaders'] === true && YL::findChoice($choices, 'nope') === null, 'findChoice only accepts listed sections');

// --- Parsing / privacy ------------------------------------------------------------------
check(YL::ageInfo('15 years and 9 months') === ['15y 9m', 189] && YL::ageInfo('14 / 06') === ['14y 6m', 174] && YL::ageInfo('') === ['', -1], 'age text parsed');
$slimList = YL::slimList($listFx);
check(count($slimList) === 6 && !in_array('301', array_column($slimList, 'scoutid'), true), 'Leaders patrol (-2) not loaded');
check(array_keys($slimList[0]) === ['scoutid', 'firstname', 'lastname', 'age', 'ageMonths'], 'list row keeps only id, names, age');
$alexRow = array_values(array_filter($slimList, static fn ($r) => $r['scoutid'] === '201'))[0];
$slim = YL::slimMember($indFx['201'], $alexRow, '4th Ashby de la Zouch: Group Young Leaders');
check(array_keys($slim) === ['firstname', 'lastname', 'age', 'ageMonths', 'others'], 'member keeps only names, age, others');
check($slim['others'] === ['Ashby & Coalville District: ACES Young Leaders', 'Ashby & Coalville District: Odyssey ESU'], 'own section dropped, others sorted');
$enc = json_encode($slim);
check(!str_contains($enc, 'example.invalid') && !str_contains($enc, '07700') && !str_contains($enc, 'FAKE-') && !str_contains($enc, '2011-01-01'), 'no contact, medical or date of birth kept');
check(YL::splitOther('A: B: C') === ['A', 'B: C'] && YL::splitOther('Solo') === ['', 'Solo'], 'Group: Section split on first colon');
check(YL::pauseUs(null, 10.0, true) === 0 && YL::pauseUs(9.5, 10.0, true) === 500000 && YL::pauseUs(9.5, 10.0, false) === 1500000 && YL::pauseUs(5.0, 10.0, true) === 0, 'pause between calls (1s, 2s when allowance unknown)');

// --- Loading: 1 + N calls, paced, cached, stops -------------------------------------------
$calls = [];
$responder = null;
function ylApi(): OsmApi
{
    global $calls, $responder;
    $h = static function (RequestInterface $req, array $o) use (&$calls, &$responder) {
        parse_str($req->getUri()->getQuery(), $q);
        $calls[] = ($q['action'] ?? '?') . (isset($q['scoutid']) ? ' ' . $q['scoutid'] : '');
        return Create::promiseFor(($responder)($req, $q));
    };
    return new OsmApi(new Client(['handler' => HandlerStack::create($h), 'http_errors' => false, 'base_uri' => 'https://osm.test/']));
}
$limit = 1000;
function rlh(int $remaining = 900): array
{
    global $limit;
    return ['X-RateLimit-Limit' => (string) $limit, 'X-RateLimit-Remaining' => (string) $remaining, 'X-RateLimit-Reset' => '3000', 'Content-Type' => 'application/json'];
}
$remaining = 900;
$fail = [];
$responder = static function (RequestInterface $r, array $q) use (&$remaining, &$fail, $listFx, $indFx): Response {
    $remaining--;
    $a = (string) ($q['action'] ?? '');
    $sid = (string) ($q['scoutid'] ?? '');
    if (isset($fail[$sid])) {
        return $fail[$sid];
    }
    return match ($a) {
        'getListOfMembers' => new Response(200, rlh($remaining), json_encode($listFx)),
        'getIndividual' => new Response(200, rlh($remaining), json_encode($indFx[$sid] ?? ['ok' => false, 'error' => 'no'])),
        default => new Response(404, rlh($remaining), '{}'),
    };
};
$sleeps = [];
$clockF = 1000.0;
function ctx(?float $last = null, ?int $refresh = null): array
{
    global $sleeps, $clockF;
    return ['lastCallAt' => $last, 'refreshSince' => $refresh, 'sleep' => static function (int $us) use (&$sleeps, &$clockF): void { $sleeps[] = $us; $clockF += $us / 1e6; }, 'now' => static function () use (&$clockF): float { return $clockF; }];
}
function freshYlUser(string $id): void
{
    global $calls, $sleeps;
    $_SESSION = ['osmUserId' => $id];
    OsmRateGuard::forUser($id)->reset();
    OsmCache::forgetUser($id);
    OsmApi::resetLoggedReasons();
    $calls = [];
    $sleeps = [];
}
$sec = YL::findChoice($choices, '7881');
freshYlUser('500');
$last = null;
$states = [];
for ($i = 0; $i < 20; $i++) {
    $r = YL::step(ylApi(), 'tok', $sec, '500', ctx($last));
    $last = $r['lastCallAt'];
    $states[] = $r['state'];
    if ($r['state'] !== 'running') break;
}
check(end($states) === 'done' && count($calls) === 7, 'full load = 1 list + 6 members (1 + N), Leaders skipped');
check($calls[0] === 'getListOfMembers' && $calls[1] === 'getIndividual 201', 'list first, then one getIndividual per member');
check(count($sleeps) === 6 && min($sleeps) >= 1 && $r['loaded'] === 6 && $r['total'] === 6, 'paused before every call after the first');
$calls = [];
$r = YL::step(ylApi(), 'tok', $sec, '500', ctx($last));
$v = YL::view('500', '7881');
check($r['state'] === 'done' && $calls === [] && $r['calls'] === 0, 'repeat load: 0 calls (24-hour cache)');
check($v['loaded'] === 6 && $v['pending'] === 0 && count($v['options']) === 5, 'view from cache: 6 loaded, Helping in options built');
check($v['options'][0]['name'] === '4th Ashby de la Zouch: Beavers' && $v['options'][0]['count'] === 2, 'option counts');
$raw = '';
foreach (glob(OsmCache::dir() . '/yl*.json') ?: [] as $f) { $raw .= file_get_contents($f); }
check($raw !== '' && !str_contains($raw, 'example.invalid') && !str_contains($raw, '07700') && !str_contains($raw, 'FAKE-') && !str_contains($raw, '2011-01-01') && !str_contains($raw, 'Adultperson'), 'cache files hold no contact, medical, DOB or Leaders');
$f0 = (glob(OsmCache::dir() . '/ylmember-*.json') ?: [''])[0];
check($f0 !== '' && (fileperms($f0) & 0777) === 0600, 'cache files are owner-only (0600)');
check(YL::view('501', '7881')['listCached'] === false, 'another user sees nothing cached');

// Stop at the first 429, keep what loaded, no retry
freshYlUser('510');
// List order is by last name: Example 201, Fixture 205, Mockdata 206, Placeholder 204, Sample 202, Testcase 203.
$fail = ['206' => new Response(429, ['Retry-After' => '120'] + rlh(500), '{"error":"Too many"}')];
$last = null;
for ($i = 0; $i < 20; $i++) {
    $r = YL::step(ylApi(), 'tok', $sec, '510', ctx($last));
    $last = $r['lastCallAt'];
    if ($r['state'] !== 'running') break;
}
check($r['state'] === 'stopped' && str_contains((string) $r['message'], 'What has loaded is kept'), '429: stopped with a clear message');
check(count(array_filter($calls, static fn ($c) => $c === 'getIndividual 206')) === 1 && !in_array('getIndividual 204', $calls, true), '429: no retry, nothing after it');
check(YL::view('510', '7881')['loaded'] === 2, '429: the two members already loaded are kept');
$calls = [];
$r2 = YL::step(ylApi(), 'tok', $sec, '510', ctx($last));
check($r2['state'] === 'stopped' && $calls === [], 'after a 429 the shared guard refuses the next step with no call');

// Stop at the first error
freshYlUser('520');
$fail = ['205' => new Response(500, rlh(800), 'oops')];
$last = null;
for ($i = 0; $i < 20; $i++) {
    $r = YL::step(ylApi(), 'tok', $sec, '520', ctx($last));
    $last = $r['lastCallAt'];
    if ($r['state'] !== 'running') break;
}
check($r['state'] === 'stopped' && str_contains((string) $r['message'], 'Emery Fixture') && count($calls) === 3, 'error: stopped at the first one, member named, no retry');
$fail = ['205' => new Response(200, rlh(800), '{"ok":false,"error":"Access denied"}')];
freshYlUser('521');
$last = null;
for ($i = 0; $i < 20; $i++) {
    $r = YL::step(ylApi(), 'tok', $sec, '521', ctx($last));
    $last = $r['lastCallAt'];
    if ($r['state'] !== 'running') break;
}
check($r['state'] === 'stopped' && str_contains((string) $r['message'], 'unexpected reply') && count($calls) === 3, 'OSM error body: stopped, nothing cached for that member');
$fail = [];

// Stop when the allowance is low (before calling). Limit 300: the shared guard's own floor is 30,
// so this page's stricter 60 is what stops it.
freshYlUser('530');
$limit = 300;
$remaining = 64;
$last = null;
for ($i = 0; $i < 20; $i++) {
    $r = YL::step(ylApi(), 'tok', $sec, '530', ctx($last));
    $last = $r['lastCallAt'];
    if ($r['state'] !== 'running') break;
}
check($r['state'] === 'stopped' && $r['message'] === YL::STOP_RATE_LOW, 'rate low: stopped with the allowance message');
check(count($calls) === 4 && $remaining === 60, 'rate low: no call once 60 or fewer are left');
// Limit 1000: the shared guard (10% floor) refuses first; still a clean stop with no call.
freshYlUser('531');
$limit = 1000;
$remaining = 95;
$r = YL::step(ylApi(), 'tok', $sec, '531', ctx());
$calls = [];
$r = YL::step(ylApi(), 'tok', $sec, '531', ctx($r['lastCallAt']));
check($r['state'] === 'stopped' && $calls === [] && str_contains((string) $r['message'], 'allowance'), 'shared low-allowance guard: stopped, no call');
$remaining = 900;

// Refresh: older entries count as pending, are still shown, and get replaced
freshYlUser('540');
$last = null;
do { $r = YL::step(ylApi(), 'tok', $sec, '540', ctx($last)); $last = $r['lastCallAt']; } while ($r['state'] === 'running');
$now += 10;
$refreshSince = $now;
$v = YL::view('540', '7881', $refreshSince);
check($v['listStale'] && $v['pending'] === 6 && $v['loaded'] === 6, 'after Refresh: still shown, all pending');
$calls = [];
do { $r = YL::step(ylApi(), 'tok', $sec, '540', ctx($last, $refreshSince)); $last = $r['lastCallAt']; } while ($r['state'] === 'running');
check($r['state'] === 'done' && count($calls) === 7, 'Refresh reloads list + each member once');

// Too-big sections are refused after the 1 list call
freshYlUser('550');
$big = ['items' => []];
for ($i = 1; $i <= YL::MAX_MEMBERS + 1; $i++) { $big['items'][] = ['scoutid' => (string) (5000 + $i), 'firstname' => 'F' . $i, 'lastname' => 'L', 'patrolid' => '0', 'age' => '15 / 01']; }
$responder2 = $responder;
$responder = static fn (RequestInterface $r, array $q): Response => new Response(200, rlh(800), json_encode($big));
$r = YL::step(ylApi(), 'tok', $sec, '550', ctx());
check($r['state'] === 'stopped' && count($calls) === 1 && str_contains((string) $r['message'], 'more than'), 'section over MAX_MEMBERS refused after 1 call');
$responder = $responder2;

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
