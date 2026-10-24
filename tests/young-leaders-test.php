<?php
declare(strict_types=1);
/**
 * Offline tests for the Young Leaders page. Fake fixtures only: every OSM reply comes from a
 * Guzzle handler in this file, never the network.
 * Run: php tests/young-leaders-test.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/isolate-storage.php';

use App\Http\Controllers\YoungLeadersController as YLC;
use App\Members\Patrols;
use App\Members\YoungLeaderLimits as Limits;
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
OsmCache::useClock(static function () use (&$now): int|float { return $now; });

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
check(Patrols::isYoungLeaderPatrolName('Young Leaders') && Patrols::isYoungLeaderPatrolName('YL Team') && Patrols::isYoungLeaderPatrolName('yls'), 'Young Leaders patrol by name');
check(!Patrols::isYoungLeaderPatrolName('Yellow') && !Patrols::isYoungLeaderPatrolName(''), 'other patrols are not Young Leaders');
check(!method_exists(Patrols::class, 'isYoungLeader') && !defined(Patrols::class . '::YOUNG_LEADERS_ID'), 'S4/M1: one Young Leader patrol rule only (no trimmed variant, unused -3 constant removed)');
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
check(!$membersRule(' yl') && !$membersRule("yl\t") && !Patrols::isYoungLeaderPatrolName(' yls'), 'padded names stay ordinary patrols everywhere (old untrimmed rule)');
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
check(YL::pauseUs(null, 10.0, true) === 1_000_000 && YL::pauseUs(null, 10.0, false) === 2_000_000 && YL::pauseUs(9.5, 10.0, true) === 500000 && YL::pauseUs(9.5, 10.0, false) === 1500000 && YL::pauseUs(5.0, 10.0, true) === 0, 'pause between calls (1s, 2s when allowance unknown; the full pause when no last-call time is known)');

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
function ctx(?float $last = null, ?float $refresh = null): array
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
check(count($sleeps) === 7 && min($sleeps) >= 1 && $sleeps[0] === YL::PAUSE_UNKNOWN_US && $r['loaded'] === 6 && $r['total'] === 6, 'paused before every call (the first too: no last-call time known yet)');
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
check($r['calls'] === 1 && $r2['calls'] === 0, 'S6: the 429 step reports calls:1, the refused step calls:0');
$busy510 = YL::busyMessage('510');
check(is_string($busy510) && str_contains($busy510, 'OSM is busy') && YL::busyMessage('511') === null, '(b): busyMessage reports this user\'s backoff only');

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
check($r['calls'] === 0, 'S6: guard-refused step reports calls:0');
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

// (c) Geoffrey's case: a load where the last member (203, Testcase) is fetched in the same whole
// second that Refresh is pressed. Before the fix 203 was skipped by the reload (whole-second compare).
freshYlUser('541');
$now = 1_800_100_000.2;
$responderC = $responder;
$responder = static function (RequestInterface $r, array $q) use (&$now, $responderC): Response {
    $now += 1.0; // each OSM call lands in the next whole second (.2 past it)
    return $responderC($r, $q);
};
$last = null;
do { $r = YL::step(ylApi(), 'tok', $sec, '541', ctx($last)); $last = $r['lastCallAt']; } while ($r['state'] === 'running');
check($r['state'] === 'done' && end($calls) === 'getIndividual 203', '(c) setup: 203 is the last member fetched');
$now += 0.5; // Refresh pressed straight after, same whole second as 203's fetch
$refreshSince = OsmCache::currentMicrotime(); // what Refresh stores (YoungLeaderLimits::startRefresh)
check((int) $refreshSince === (int) ($now - 0.5), '(c) setup: Refresh and 203 share one whole second');
$v = YL::view('541', '7881', $refreshSince);
check($v['listStale'] && $v['pending'] === 6, '(c) same-second Refresh: every member counts as older (none skipped)');
$calls = [];
do { $r = YL::step(ylApi(), 'tok', $sec, '541', ctx($last, $refreshSince)); $last = $r['lastCallAt']; } while ($r['state'] === 'running');
check($r['state'] === 'done' && count($calls) === 7 && in_array('getIndividual 203', $calls, true), '(c) same-second Refresh: list + all 6 members reloaded, 203 included');
$responder = $responderC;
$now = 1_800_100_100;

// S3: more than CONFIRM_ABOVE to load needs confirmed=1 on the server, not only in the browser.
freshYlUser('545');
$mid = ['items' => []];
for ($i = 1; $i <= YL::CONFIRM_ABOVE + 2; $i++) { $mid['items'][] = ['scoutid' => (string) (6000 + $i), 'firstname' => 'M' . $i, 'lastname' => 'L', 'patrolid' => '0', 'age' => '15 / 01']; }
$responder3 = $responder;
$responder = static fn (RequestInterface $r, array $q): Response => ($q['action'] ?? '') === 'getListOfMembers'
    ? new Response(200, rlh(800), json_encode($mid))
    : new Response(200, rlh(800), json_encode(['data' => ['firstname' => 'M', 'lastname' => 'L']]));
$r = YL::step(ylApi(), 'tok', $sec, '545', ctx());
check($r['state'] === 'running' && $r['confirm'] === true && count($calls) === 1, 'S3: list loaded (1 call), confirm requested');
$calls = [];
$r = YL::step(ylApi(), 'tok', $sec, '545', ctx($r['lastCallAt']));
check($r['state'] === 'confirm' && $calls === [] && $r['calls'] === 0, 'S3: a member step without confirmed=1 is refused on the server, no call');
$r = YL::step(ylApi(), 'tok', $sec, '545', ctx($r['lastCallAt']) + ['confirmed' => true]);
check($r['state'] === 'running' && count($calls) === 1, 'S3: with confirmed=1 the next member loads (1 call)');
$responder = $responder3;

// Too-big sections are refused after the 1 list call
freshYlUser('550');
$big = ['items' => []];
for ($i = 1; $i <= YL::MAX_MEMBERS + 1; $i++) { $big['items'][] = ['scoutid' => (string) (5000 + $i), 'firstname' => 'F' . $i, 'lastname' => 'L', 'patrolid' => '0', 'age' => '15 / 01']; }
$responder2 = $responder;
$responder = static fn (RequestInterface $r, array $q): Response => new Response(200, rlh(800), json_encode($big));
$r = YL::step(ylApi(), 'tok', $sec, '550', ctx());
check($r['state'] === 'stopped' && count($calls) === 1 && str_contains((string) $r['message'], 'more than'), 'section over MAX_MEMBERS refused after 1 call');
$calls = [];
$r = YL::step(ylApi(), 'tok', $sec, '550', ctx($r['lastCallAt']));
check($r['state'] === 'stopped' && $calls === [] && $r['calls'] === 0 && str_contains((string) $r['message'], (string) (YL::MAX_MEMBERS + 1) . ' members'), 'S2: pressing Load again for a too-large section makes no call');
$v = YL::view('550', '7881');
check($v['tooLarge'] === YL::MAX_MEMBERS + 1 && $v['rows'] === [], 'S2: only the count is kept for a too-large section (no names)');
$raw550 = '';
foreach (glob(OsmCache::dir() . '/ylsection-*.json') ?: [] as $f) { $raw550 .= file_get_contents($f); }
check(!str_contains($raw550, 'F1"') && !str_contains($raw550, '5001'), 'S2: too-large marker stores no member names or ids');
// S1: the too-large refusal is per OSM user, so signing out (which clears the YL copy) and in again doesn't reset it.
OsmCache::forgetUser('550');
$_SESSION = ['osmUserId' => '550'];
$calls = [];
$r = YL::step(ylApi(), 'tok', $sec, '550', ctx());
check($r['state'] === 'stopped' && $calls === [] && $r['calls'] === 0, 'S1: after sign-out and sign-in the too-large refusal still makes no call');
$now += 10;
$r = YL::step(ylApi(), 'tok', $sec, '550', ctx(null, (float) $now));
check(count($calls) === 1, 'S2: Refresh checks the list again (one call)');
$responder = $responder2;

// (b): during a backoff the page hides Load/Continue and Refresh and says why.
$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/templates'), ['strict_variables' => false]);
$twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $p): string => '/assets/' . ltrim($p, '/')));
$pageCtx = ['authed' => true, 'csrfToken' => 'tok', 'section' => $sec, 'ylChoices' => [$sec], 'otherChoices' => [], 'groupName' => 'G',
    'view' => YL::view('510', '7881'), 'confirmAbove' => YL::CONFIRM_ABOVE, 'maxMembers' => YL::MAX_MEMBERS, 'pauseSeconds' => 1, 'cooldownMinutes' => 5];
$okPage = $twig->render('young-leaders.twig', $pageCtx + ['busy' => null]);
$busyPage = $twig->render('young-leaders.twig', $pageCtx + ['busy' => $busy510]);
check(str_contains($okPage, 'id="yl-start"') && str_contains($okPage, '/young-leaders/refresh/'), '(b): normally Continue and Refresh are shown');
check(!str_contains($busyPage, 'id="yl-start"') && !str_contains($busyPage, '/young-leaders/refresh/') && str_contains($busyPage, 'id="yl-busy"'), '(b): during a backoff Continue and Refresh are hidden, with a message');

// S1: pause, Refresh cooldown and Refresh progress are per OSM user and survive signing in again.
$_SESSION = ['osmUserId' => '560'];
check(YLC::refreshWait('560', '7881') === 0 && Limits::lastCallAt('560') === null, 'S1: nothing recorded yet');
Limits::setLastCallAt('560', (float) $now);
Limits::startRefresh('560', '7881', (float) $now);
OsmCache::forgetCurrentUser();
$_SESSION = [];
$_SESSION = ['osmUserId' => '560'];
check(YLC::refreshWait('560', '7881') > 0 && YLC::refreshWait('560', '7881') <= YLC::REFRESH_COOLDOWN_SEC, 'S1: Refresh cooldown survives sign-out and sign-in');
check(Limits::lastCallAt('560') === (float) $now && Limits::refreshSince('560', '7881') === (float) $now, 'S1: last-call time (pause) and Refresh progress survive too');
check(YLC::refreshWait('561', '7881') === 0 && Limits::lastCallAt('561') === null, 'S1: limits are per OSM user (another user is unaffected)');
Limits::endRefresh('560', '7881');
check(Limits::refreshSince('560', '7881') === null && YLC::refreshWait('560', '7881') > 0, 'S1: finishing the reload keeps the cooldown');
$now += YLC::REFRESH_COOLDOWN_SEC + 1;
check(YLC::refreshWait('560', '7881') === 0, 'S1: cooldown ends after 5 minutes');
$limFiles = glob(Limits::dir() . '/*.json') ?: [];
$limRaw = implode('', array_map('file_get_contents', $limFiles));
check($limFiles !== [] && (fileperms($limFiles[0]) & 0777) === 0600 && !str_contains($limRaw, '560') && !preg_match('/[A-Za-z]{2,}[0-9]*"\s*:\s*"[A-Za-z]/', $limRaw), 'S1: limits files are 0600, keyed by hashed user id, no names');
$now += Limits::KEEP_SEC + 1;
check(Limits::lastCallAt('560') === null && YL::view('550', '7881')['tooLarge'] === null, 'S1: limits entries drop after 24 hours');

// MF1: storage/yl-limits can't be written. Loading must fail closed: no OSM call (with a clear
// message), and even the session fallback keeps the full pause, the cooldown and the too-large marker.
$limitsDir = Limits::dir();
$brokenDir = sys_get_temp_dir() . '/osmh-yl-broken-' . getmypid();
@unlink($brokenDir);
file_put_contents($brokenDir, 'not a directory'); // as Geoffrey's r2-nolimits run: a plain file where the dir should be
Limits::useDirectory($brokenDir);
freshYlUser('570');
check(!Limits::canSave('570') && !Limits::looksSavable('570'), 'MF1: an unwritable yl-limits dir is detected');
check(Limits::setLastCallAt('570', 1.0) === false, 'MF1: saving a limit reports the failure (no silent return)');
$_SESSION = ['osmUserId' => '570'];
$mfLog = [];
$mfClock = (float) $now; // same clock as the 24-hour pruning
$mfCtx = ['sleep' => static function (int $us) use (&$mfClock): void { $mfClock += $us / 1e6; }, 'now' => static function () use (&$mfClock): float { return $mfClock; }];
$responderMf = $responder;
$responder = static function (RequestInterface $r, array $q) use (&$mfLog, &$mfClock, $responderMf): Response { $mfLog[] = $mfClock; return $responderMf($r, $q); };
$states = [];
for ($i = 0; $i < 7; $i++) {
    $r = YLC::loadStep(ylApi(), 'tok', $sec, '570', true, $mfCtx);
    $states[] = $r['state'];
}
check($calls === [] && $mfLog === [] && $r['calls'] === 0, 'MF1: 7 Load steps with an unwritable yl-limits dir make 0 OSM calls');
check(array_unique($states) === ['stopped'] && $r['message'] === Limits::UNAVAILABLE_MESSAGE && str_contains($r['message'], 'hasn’t contacted OSM'), 'MF1: each step is refused with a clear message');
check(($_SESSION['ylFlash']['message'] ?? '') === Limits::UNAVAILABLE_MESSAGE, 'MF1: the page shows the refusal after reloading');
$pageMf = $twig->render('young-leaders.twig', $pageCtx + ['busy' => null, 'limitsError' => Limits::UNAVAILABLE_MESSAGE]);
check(str_contains($pageMf, 'id="yl-limits-error"') && !str_contains($pageMf, 'id="yl-start"') && !str_contains($pageMf, '/young-leaders/refresh/'), 'MF1: the page says why and hides Load and Refresh');
$m = YLC::startRefreshFor('570', '7881');
check($m === Limits::UNAVAILABLE_MESSAGE && Limits::refreshSince('570', '7881') === null, 'MF1: Refresh is refused too (the cooldown can\'t be skipped)');
// The session fallback on its own (as if the step were let through): calls stay >= 1s apart.
$calls = [];
$mfLog = [];
$sleeps = [];
for ($i = 0; $i < 20; $i++) {
    $r = YL::step(ylApi(), 'tok', $sec, '570', ['lastCallAt' => Limits::lastCallAt('570'), 'refreshSince' => null] + $mfCtx);
    if ($r['lastCallAt'] !== null) { Limits::setLastCallAt('570', (float) $r['lastCallAt']); }
    if ($r['state'] !== 'running') break;
}
$gaps = [];
for ($i = 1; $i < count($mfLog); $i++) { $gaps[] = $mfLog[$i] - $mfLog[$i - 1]; }
check(count($mfLog) === 7 && min($gaps) >= 1.0 && Limits::lastCallAt('570') !== null, 'MF1: with only the session copy, 7 calls are still >= 1s apart (min gap ' . (count($gaps) ? sprintf('%.2f', min($gaps)) : '-') . 's)');
$_SESSION = ['osmUserId' => '570'];
$calls = [];
$mfLog = [];
$mfStart = $mfClock + 60;
$mfClock = $mfStart;
Limits::useDirectory($brokenDir);
YL::step(ylApi(), 'tok', $sec, '570', ['lastCallAt' => Limits::lastCallAt('570'), 'refreshSince' => (float) $now + 1] + $mfCtx);
check(count($mfLog) === 1 && $mfLog[0] >= 1.0 + $mfStart, 'MF1: a new session with nothing saved still gets the full pause before its first call');
$responder = $responderMf;
// Cooldown and too-large marker hold in the session while the file can't be written.
$_SESSION = ['osmUserId' => '571'];
Limits::startRefresh('571', '7881', (float) $now);
Limits::setTooLarge('571', '7881', YL::MAX_MEMBERS + 5, (float) $now);
check(YLC::refreshWait('571', '7881') > 0 && Limits::tooLarge('571', '7881')['count'] === YL::MAX_MEMBERS + 5, 'MF1: cooldown and too-large marker hold for the session while the file can\'t be written');
$calls = [];
$r = YL::step(ylApi(), 'tok', $sec, '571', ctx(null));
check($r['state'] === 'stopped' && $calls === [] && str_contains((string) $r['message'], 'more than'), 'MF1: too-large marker from the session still refuses with no call');
@unlink($brokenDir);
// The file and the session disagree: the stricter value wins.
Limits::useDirectory($limitsDir);
$_SESSION = ['osmUserId' => '572'];
Limits::setLastCallAt('572', $now - 100.0);
$_SESSION = ['osmUserId' => '572', 'ylLimits' => ['572' => ['lastCallAt' => $now - 150.0]]];
check(Limits::lastCallAt('572') === $now - 100.0, 'MF1: the later last-call time wins (file over an older session copy)');
$_SESSION = ['osmUserId' => '572', 'ylLimits' => ['572' => ['lastCallAt' => $now - 50.0]]];
check(Limits::lastCallAt('572') === $now - 50.0, 'MF1: the later last-call time wins (session over an older file)');

// Should-fix 1: the pacing slot is reserved under the lock, so two tabs / PHP workers can't share it.
$_SESSION = ['osmUserId' => '580'];
$T = (float) $now;
$w1 = Limits::reserveCall('580', YL::PAUSE_US, $T);
$w2 = Limits::reserveCall('580', YL::PAUSE_US, $T); // a second tab asking at the same moment
$w3 = Limits::reserveCall('580', YL::PAUSE_US, $T + 0.5);
check($w1 === 1_000_000 && $w2 === 2_000_000 && $w3 === 2_500_000, 'SF1: same-moment reservations get slots 1s apart (waits 1s, 2s, then 2.5s half a second later)');
$_SESSION = ['osmUserId' => '580']; // another browser: only the file
check(Limits::reserveCall('580', YL::PAUSE_US, $T + 0.5) === 3_500_000, 'SF1: another session of the same user queues behind the saved slot');
check(Limits::reserveCall('580', YL::PAUSE_US, $T + 1000) === 0 && Limits::reserveCall('581', YL::PAUSE_US, $T) === 1_000_000, 'SF1: no wait once the slot has passed; other users are separate');
check(Limits::reserveCall('582', YL::PAUSE_US, $T - 3600) === 1_000_000, 'SF1: a saved slot far in the future (clock change) still means at most the full pause');
// Real processes at the same time (as PHP-FPM workers would be): slots must be >= 1s apart.
$procDir = sys_get_temp_dir() . '/osmh-yl-proc-' . getmypid();
@mkdir($procDir, 0700, true);
$script = '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; App\Members\YoungLeaderLimits::useDirectory(' . var_export($procDir, true) . ');'
    . ' $t = microtime(true); $w = App\Members\YoungLeaderLimits::reserveCall("590", 1000000, $t); echo $w === null ? "null" : sprintf("%.6f", $t + $w / 1e6);';
$sf = $procDir . '/reserve.php';
file_put_contents($sf, $script);
$procs = [];
for ($i = 0; $i < 4; $i++) {
    $procs[] = proc_open([PHP_BINARY, $sf], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$slots = [];
foreach ($procs as $i => $p) { $slots[] = (float) stream_get_contents($pipes[$i][1]); proc_close($p); }
sort($slots);
$gaps = [];
for ($i = 1; $i < count($slots); $i++) { $gaps[] = $slots[$i] - $slots[$i - 1]; }
check(count($slots) === 4 && min($slots) > 0 && min($gaps) >= 0.999, 'SF1: 4 processes reserving at once get slots >= 1s apart (min gap ' . sprintf('%.3f', $gaps ? min($gaps) : 0) . 's)');
array_map('unlink', glob($procDir . '/*') ?: []);
@rmdir($procDir);
// loadStep uses the reservation: two tabs taking turns step by step still make calls >= 1s apart.
freshYlUser('595');
$tabLog = [];
$tabClock = (float) $now;
$tabCtx = ['sleep' => static function (int $us) use (&$tabClock): void { $tabClock += $us / 1e6; }, 'now' => static function () use (&$tabClock): float { return $tabClock; }];
$responderTab = $responder;
$responder = static function (RequestInterface $r, array $q) use (&$tabLog, &$tabClock, $responderTab): Response { $tabLog[] = $tabClock; return $responderTab($r, $q); };
$sec2 = $sec;
for ($i = 0; $i < 8; $i++) {
    $_SESSION = ['osmUserId' => '595', 'tab' => $i % 2]; // alternate between two sessions
    $tabClock -= 0.9; // the other tab asks before this one's pause ended
    $r = YLC::loadStep(ylApi(), 'tok', $i % 2 ? $sec2 : $sec, '595', true, $tabCtx);
    if ($r['state'] === 'done') break;
}
$responder = $responderTab;
$gaps = [];
for ($i = 1; $i < count($tabLog); $i++) { $gaps[] = $tabLog[$i] - $tabLog[$i - 1]; }
check(count($tabLog) === 7 && min($gaps) >= 0.999, 'SF1: two interleaved tabs: 7 calls, all >= 1s apart (min gap ' . sprintf('%.3f', $gaps ? min($gaps) : 0) . 's)');

// Refresh's cooldown is checked and saved in one locked step too.
$_SESSION = [];
$rT = (float) $now;
check(Limits::startRefreshIfDue('596', '7881', $rT, 300) === true, 'SF1: first Refresh starts');
$_SESSION = []; // a second tab/session pressing Refresh at the same moment
check(Limits::startRefreshIfDue('596', '7881', $rT + 0.2, 300) === false && Limits::lastRefreshAt('596', '7881') === (int) $rT, 'SF1: a second Refresh at the same moment is refused and the first is kept');
check(Limits::startRefreshIfDue('596', '7881', $rT + 301, 300) === true, 'SF1: Refresh is allowed again after the cooldown');

// Should-fix 3: files private from creation, keyed names, locked reads, stale files swept.
$h3Dir = sys_get_temp_dir() . '/osmh-yl-h3-' . getmypid();
Limits::useDirectory($h3Dir);
$oldUmask = umask(0022);
$_SESSION = [];
check(Limits::setLastCallAt('600', (float) $now), 'SF3: saves to a new directory');
umask($oldUmask);
$h3Files = glob($h3Dir . '/*.json') ?: [];
check(count($h3Files) === 1 && (fileperms($h3Files[0]) & 0777) === 0600 && (fileperms($h3Dir) & 0777) === 0700, 'SF3: under a loose umask the file is still created 0600 in a 0700 dir');
check(is_file($h3Dir . '/.salt') && (fileperms($h3Dir . '/.salt') & 0777) === 0600, 'SF3: with no real SESSION_SECRET a per-install salt is generated, 0600');
check(basename($h3Files[0]) !== hash('sha256', 'yl-limits|600') . '.json', 'SF3: the file name is not a plain hash of the user id');
$_ENV['SESSION_SECRET'] = 'test-only-secret';
check(Limits::setLastCallAt('600', (float) $now) && is_file($h3Dir . '/' . hash_hmac('sha256', 'yl-limits|600', 'test-only-secret') . '.json'), 'SF3: with SESSION_SECRET set the name is keyed with it');
unset($_ENV['SESSION_SECRET']);
$_SESSION = [];
check(Limits::lastCallAt('600') === (float) $now, 'SF3: the salted file reads back (fresh session)');
// A reader waits for a writer's lock rather than reading a half-written file.
$h3Lock = fopen($h3Files[0], 'r+');
flock($h3Lock, LOCK_EX);
$h3Child = proc_open([PHP_BINARY, '-r', 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; App\Members\YoungLeaderLimits::useDirectory(' . var_export($h3Dir, true) . '); App\Osm\OsmCache::useClock(fn () => ' . $now . '); $v = App\Members\YoungLeaderLimits::lastCallAt("600"); printf("%.6f %s", microtime(true), var_export($v, true));'], [1 => ['pipe', 'w']], $h3Pipes);
usleep(500_000);
$h3Unlocked = microtime(true);
flock($h3Lock, LOCK_UN);
fclose($h3Lock);
$h3Out = stream_get_contents($h3Pipes[1]);
proc_close($h3Child);
[$h3Done, $h3Val] = explode(' ', $h3Out . ' ', 2);
check((float) $h3Done >= $h3Unlocked && trim($h3Val) === var_export((float) $now, true), 'SF3: a read waits until the write lock is released, then reads the value');
// Sweep: a file with nothing left after 24h is deleted on a later write (at most hourly).
$_SESSION = [];
Limits::setLastCallAt('601', (float) $now);
$h3Before = count(glob($h3Dir . '/*.json') ?: []);
$h3Saved = $now;
$now += Limits::KEEP_SEC + 3600;
$_SESSION = [];
Limits::setLastCallAt('602', (float) $now);
$h3After = glob($h3Dir . '/*.json') ?: [];
check($h3Before === 3 && count($h3After) === 1, 'SF3: stale users\' files are swept on a later write (' . $h3Before . ' -> ' . count($h3After) . ')');
$_SESSION = [];
check(Limits::lastCallAt('602') === (float) $now && Limits::lastCallAt('600') === null, 'SF3: the fresh one is kept; the stale one is gone');
$now = $h3Saved;
foreach (array_diff(scandir($h3Dir) ?: [], ['.', '..']) as $f) { unlink($h3Dir . '/' . $f); }
@rmdir($h3Dir);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
