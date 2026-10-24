<?php
declare(strict_types=1);
/**
 * Sign-in loop guard tests (phone hands the OSM link to the OSM app, so the sign-in finishes in a
 * different browser and every page sends the leader back to OSM). No network beyond 127.0.0.1 and
 * no OSM: the HTTP part runs the app under `php -S` with review/signin-loop/app-router.php, whose
 * OSM_API_BASE is review/signin-loop/fake-osm.php on another 127.0.0.1 port. The fake answers with
 * made-up data and logs each request, so whole sign-ins can be run and their OSM calls counted.
 * Run: php tests/signin-loop-test.php
 */
ob_start();
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/isolate-storage.php';

use App\App;
use App\Http\OAuthState;
use App\Http\SignInGuard;

$failed = 0;
$passed = 0;
$out = [];
function check(bool $cond, string $msg): void
{
    global $failed, $passed, $out;
    if ($cond) { $passed++; $out[] = "OK  $msg"; } else { $failed++; $out[] = "FAIL $msg"; }
}

// ---------- Unit: SignInGuard ----------
$now = 1_800_000_000;
$s = [];
check(SignInGuard::recordAttempt($s, $now) === 1, 'guard: first /auth visit counts 1');
check(SignInGuard::shouldPause(1, false, false) === false, 'guard: first visit redirects automatically');
check(SignInGuard::recordAttempt($s, $now + 60) === 2, 'guard: second visit within 5 min counts 2');
check(SignInGuard::shouldPause(2, false, false) === true, 'guard: second visit within 5 min pauses');
check(SignInGuard::shouldPause(5, false, true) === false, 'guard: Continue (?go=1) always goes through');
check(SignInGuard::shouldPause(1, true, false) === true, 'guard: ?retry=1 always pauses');
check(SignInGuard::shouldPause(1, true, true) === false, 'guard: ?go=1 wins over ?retry=1');
$s = [];
SignInGuard::recordAttempt($s, $now);
check(SignInGuard::recordAttempt($s, $now + SignInGuard::WINDOW_SECONDS) === 1, 'guard: visits older than 5 min are forgotten');
$s = [SignInGuard::SESSION_KEY => ['junk', $now + 999, -1, $now - 10]];
check(SignInGuard::recordAttempt($s, $now) === 2, 'guard: junk / future / old timestamps ignored');
$s = [];
for ($i = 0; $i < 30; $i++) { SignInGuard::recordAttempt($s, $now + $i); }
check(count($s[SignInGuard::SESSION_KEY]) === 10, 'guard: session list capped at 10');
SignInGuard::clear($s);
check(!isset($s[SignInGuard::SESSION_KEY]), 'guard: clear() removes the counter');

// Recent successful sign-ins (signed cookie that success adds to, so a success-then-session-lost loop is caught).
$sec = 'unit-test-secret-not-real';
check(SignInGuard::successesInWindow('', $sec, $now) === [], 'successes: no cookie counts none');
$c = SignInGuard::addSuccess('', $sec, $now);
check(SignInGuard::successesInWindow($c, $sec, $now + 1) === [$now], 'successes: one sign-in recorded');
$c = SignInGuard::addSuccess($c, $sec, $now + 60);
$c = SignInGuard::addSuccess($c, $sec, $now + 120);
check(count(SignInGuard::successesInWindow($c, $sec, $now + 121)) === 3, 'successes: three sign-ins within 5 min counted');
check(count(SignInGuard::successesInWindow($c, $sec, $now + SignInGuard::WINDOW_SECONDS)) === 2, 'successes: ones older than 5 min are forgotten');
check(SignInGuard::successesInWindow($c, 'another-secret', $now + 121) === [], 'successes: cookie signed with another secret ignored');
[$list, $sig] = explode('.', $c, 2);
check(SignInGuard::successesInWindow($list . ',' . ($now + 100) . '.' . $sig, $sec, $now + 121) === [], 'successes: edited list (bad signature) ignored');
check(SignInGuard::successesInWindow('junk', $sec, $now) === [] && SignInGuard::successesInWindow('.abc', $sec, $now) === [], 'successes: malformed cookie ignored');
$f = SignInGuard::addSuccess('', $sec, $now + 999);
check(SignInGuard::successesInWindow($f, $sec, $now) === [], 'successes: future times ignored');
$c = '';
for ($i = 0; $i < 30; $i++) { $c = SignInGuard::addSuccess($c, $sec, $now + $i); }
check(count(SignInGuard::successesInWindow($c, $sec, $now + 30)) === 10, 'successes: cookie capped at 10');
check(SignInGuard::shouldPause(1, false, false, 2) === false, 'successes: two recent sign-ins still redirect automatically');
check(SignInGuard::shouldPause(1, false, false, SignInGuard::MAX_RECENT_SUCCESSES) === true, 'successes: three recent sign-ins pause');
check(SignInGuard::shouldPause(1, false, true, 9) === false, 'successes: Continue (?go=1) still goes through');

// Successful sign-in clears the counter.
$tmp = sys_get_temp_dir() . '/osmh-signin-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
session_save_path($tmp);
session_name('osmhelper');
session_start();
$_SESSION = [SignInGuard::SESSION_KEY => [$now, $now + 5], 'returnTo' => '/members/'];
App::beginAuthenticatedSession($now + 10);
check(!isset($_SESSION[SignInGuard::SESSION_KEY]), 'guard: successful sign-in clears the counter');
check(($_SESSION['returnTo'] ?? null) === '/members/', 'guard: sign-in still keeps return-to');

// ---------- Unit: OAuthState empty-session detection ignores housekeeping keys ----------
$_COOKIE = [];
$_SESSION = ['_init' => 'x', '_csrf' => 'y', '_startedAt' => $now, '_lastSeen' => $now];
check(OAuthState::consume('some-state') === 'empty_session', 'state: session with only _init/_csrf/_startedAt/_lastSeen counts as empty');
$_SESSION = ['_init' => 'x', '_startedAt' => $now, '_lastSeen' => $now, 'returnTo' => '/members/'];
check(OAuthState::consume('some-state') === 'state_missing', 'state: session with real content but no state → state_missing');
$_SESSION = ['oauth2state' => 'aaa', '_startedAt' => $now];
check(OAuthState::consume('bbb') === 'state_mismatch', 'state: different saved state → state_mismatch');
$_SESSION = ['oauth2state' => 'aaa'];
check(OAuthState::consume('aaa') === 'ok', 'state: matching session state → ok');
$_SESSION = [];
check(OAuthState::consume('') === 'state_param_missing', 'state: no state from OSM → state_param_missing');
check(OAuthState::detail('empty_session') !== null && OAuthState::detail('nonsense') === null, 'state: detail text for known reasons only');
session_write_close();

// ---------- HTTP: the real pages under php -S (no OSM) ----------
$port = 0;
$sock = @stream_socket_server('tcp://127.0.0.1:0');
if ($sock !== false) {
    $name = stream_socket_get_name($sock, false);
    $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    fclose($sock);
}
$root = dirname(__DIR__);
$reviewTmp = $tmp . '/review';
mkdir($reviewTmp, 0700, true);
$log = $tmp . '/server.log';
// Made-up local OSM (review/signin-loop/fake-osm.php) so whole sign-ins can run and OSM calls be counted.
$osmPort = 0;
$sock = @stream_socket_server('tcp://127.0.0.1:0');
if ($sock !== false) {
    $name = stream_socket_get_name($sock, false);
    $osmPort = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    fclose($sock);
}
$fakeLog = $tmp . '/fake-osm.log';
touch($fakeLog);
$fake = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$osmPort", $root . '/review/signin-loop/fake-osm.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $fpipes,
    $root,
    array_merge(getenv(), ['SIGNIN_REVIEW_FAKE_LOG' => $fakeLog])
);
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root . '/public', $root . '/review/signin-loop/app-router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
    $root,
    array_merge(getenv(), ['SIGNIN_REVIEW_TMP' => $reviewTmp, 'SIGNIN_REVIEW_OSM_PORT' => (string) $osmPort])
);
$base = "http://127.0.0.1:$port";
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100_000);
    $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($c) { $up = true; fclose($c); }
}
$fakeUp = false;
for ($i = 0; $i < 50 && !$fakeUp; $i++) {
    $c = @fsockopen('127.0.0.1', $osmPort, $e1, $e2, 0.2);
    if ($c) { $fakeUp = true; fclose($c); } else { usleep(100_000); }
}
check($up, 'http: local server started');
check($fakeUp, 'http: local fake OSM started');
$osmBase = "http://127.0.0.1:$osmPort";
/** How many times the fake OSM has been asked for $path so far. */
function osmCalls(string $fakeLog, string $path): int
{
    return substr_count((string) file_get_contents($fakeLog), ' ' . $path . "\n");
}

/** @return array{status:int, headers:string, body:string} */
function req(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}
function hasLocation(array $r): bool { return preg_match('/^location:/mi', $r['headers']) === 1; }
function autoRedirects(array $r): bool
{
    return hasLocation($r) || str_contains($r['body'], 'http-equiv="refresh"') || str_contains($r['body'], 'location.replace(');
}

if ($up) {
    // 1. State missing: "another browser" (fresh cookie jar, like the OSM app's Chrome).
    $other = $tmp . '/jar-other.txt';
    $r = req("$base/callback?code=fake-code&state=fake-state", $other);
    check($r['status'] === 400, 'callback state missing: HTTP 400');
    check(!autoRedirects($r), 'callback state missing: no redirect of any kind');
    check(str_contains($r['body'], 'Sign-in couldn&#039;t be finished in this browser. This usually happens when it started in a different browser or app (for example the OSM app). Please try again here.'), 'callback state missing: explains the usual cause (different browser or app) without claiming it is the only one');
    check(!str_contains($r['body'], 'Sign-in started in a different browser'), 'callback state missing: no longer states one cause as fact');
    check(str_contains($r['body'], 'href="/auth/?retry=1"') && str_contains($r['body'], 'Try again here'), 'callback state missing: Try again here → /auth/?retry=1');
    check(str_contains($r['body'], 'Open supported links') && str_contains($r['body'], 'Open links in apps'), 'callback state missing: app tip shown');
    check(str_contains($r['body'], 'Open links in apps</strong> and choose <strong>Never</strong>'), 'app tip: Firefox/Fennec told to choose Never (not a switch)');
    check(str_contains($r['body'], '(or whatever the OSM icon is called)') && str_contains($r['body'], 'Set as default'), 'app tip: Android route names the OSM icon and Samsung\'s "Set as default"');
    check(str_contains($r['body'], "Chrome doesn't have a setting like this") && !str_contains($r['body'], "this browser's settings"), 'app tip: Chrome users not sent looking for a setting that isn\'t there');
    check(str_contains($r['body'], 'Details: This browser had no sign-in in progress.'), 'callback state missing (fresh browser): plain-English detail shown');
    // Same browser that has been here before (has a session cookie) but no saved state: Jon's Chrome case.
    $seen = $tmp . '/jar-seen.txt';
    req("$base/", $seen);
    $r = req("$base/callback?code=fake-code&state=fake-state", $seen);
    check($r['status'] === 400 && str_contains($r['body'], 'Details: Your browser came back from OSM without the sign-in details we saved.'), 'callback state missing (known browser): plain-English detail');
    check(!str_contains($r['body'], 'login state'), 'callback state missing: no developer wording ("login state")');

    // 1b. State mismatch in the same browser (a real /auth first, then a wrong state).
    $jar = $tmp . '/jar-mismatch.txt';
    $a = req("$base/auth/", $jar);
    check($a['status'] === 200 && str_contains($a['body'], 'location.replace('), 'auth: first visit still auto-redirects (normal flow)');
    check(str_contains($a['body'], "$osmBase/oauth/authorize"), 'auth: authorize URL points at the local fake OSM only');
    $r = req("$base/callback?code=fake-code&state=wrong-state", $jar);
    check($r['status'] === 400 && !autoRedirects($r), 'callback state mismatch: 400, no redirect');
    check(str_contains($r['body'], 'with a different sign-in from the one we were expecting') && str_contains($r['body'], 'href="/auth/?retry=1"'), 'callback state mismatch: retry page with detail');

    // 2. OSM error=… : cancelled or refused page with the same retry button.
    $r = req("$base/callback?error=access_denied&error_description=nope&state=x", $tmp . '/jar-err.txt');
    check($r['status'] === 400 && !autoRedirects($r), 'callback error: 400, no redirect');
    check(str_contains($r['body'], 'OSM sign-in was cancelled or refused'), 'callback error: cancelled or refused wording');
    check(str_contains($r['body'], 'href="/auth/?retry=1"'), 'callback error: Try again here button');
    check(!str_contains($r['body'], 'nope'), 'callback error: OSM error text not echoed');
    $r = req("$base/callback", $tmp . '/jar-none.txt');
    check($r['status'] === 400 && str_contains($r['body'], 'Missing code parameter.'), 'callback nothing: still "Missing code parameter."');

    // 3. Loop guard.
    $jar = $tmp . '/jar-loop.txt';
    $a1 = req("$base/auth/", $jar);
    check($a1['status'] === 200 && autoRedirects($a1), 'loop guard: 1st /auth goes to OSM automatically');
    $a2 = req("$base/auth/", $jar);
    check($a2['status'] === 200 && !autoRedirects($a2), 'loop guard: 2nd /auth within 5 min does not redirect');
    check(str_contains($a2['body'], 'Continue to OSM') && str_contains($a2['body'], 'href="/auth/?go=1"'), 'loop guard: shows Continue to OSM → /auth/?go=1');
    check(str_contains($a2['body'], 'Open supported links'), 'loop guard: app tip shown');
    check(!str_contains($a2['body'], 'oauth/authorize'), 'loop guard: page has no OSM URL');
    check(!preg_match('/^set-cookie: osmhelper_oauth=/mi', $a2['headers']), 'loop guard: no new OAuth state while paused');
    $g = req("$base/auth/?go=1", $jar);
    check($g['status'] === 200 && str_contains($g['body'], 'location.replace(') && str_contains($g['body'], "$osmBase/oauth/authorize"), 'loop guard: Continue starts the normal flow');
    check(preg_match('/^set-cookie: osmhelper_oauth=/mi', $g['headers']) === 1, 'loop guard: Continue sets the OAuth state cookie');
    $r = req("$base/auth/?retry=1", $tmp . '/jar-retry.txt');
    check($r['status'] === 200 && !autoRedirects($r) && str_contains($r['body'], 'Continue to OSM'), 'loop guard: ?retry=1 shows Continue even on the first visit');
    $p = req("$base/dashboard/", $tmp . '/jar-retry.txt');
    check($p['status'] === 302 && str_contains($p['headers'], '/auth/'), 'loop guard: signed-out pages still send to /auth/ (which now pauses)');
    $a = req("$base/auth/", $tmp . '/jar-retry.txt');
    check(!autoRedirects($a) && str_contains($a['body'], 'Continue to OSM'), 'loop guard: so a loop stops at the Continue page');
    check(osmCalls($fakeLog, '/oauth/token') === 0 && osmCalls($fakeLog, '/oauth/resource') === 0, 'failed callbacks and paused pages: no OSM token or resource calls');

    // 4. Whole sign-ins against the local fake OSM.
    /**
     * One automatic pass: page → /auth → (fake) OSM → /callback. Returns 'paused' (Continue page),
     * 'signed-in:<Location>' or 'other:<status>'.
     */
    $pass = static function (string $startPath, string $jar) use ($base): string {
        $p = req($base . $startPath, $jar);
        if ($p['status'] !== 302 || !preg_match('/^location:\s*(\S+)/mi', $p['headers'], $m) || !str_contains($m[1], '/auth/')) {
            return 'other:' . $p['status'];
        }
        $a = req($base . '/auth/', $jar);
        if (!autoRedirects($a)) {
            return str_contains($a['body'], 'Continue to OSM') ? 'paused' : 'other:' . $a['status'];
        }
        if (!preg_match('/http-equiv="refresh" content="0;url=([^"]+)"/', $a['body'], $m)) {
            return 'other:no-osm-url';
        }
        $o = req(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), $jar);
        if (!preg_match('/^location:\s*(\S+)/mi', $o['headers'], $m)) {
            return 'other:osm-' . $o['status'];
        }
        // The harness's REDIRECT_URI is fixed at 127.0.0.1:8097; this test's app runs on another local port.
        $cb = req(preg_replace('#^http://127\.0\.0\.1:8097#', $base, $m[1]), $jar);
        if ($cb['status'] === 302 && preg_match('/^location:\s*(\S+)/mi', $cb['headers'], $m)) {
            return 'signed-in:' . $m[1];
        }
        return 'other:callback-' . $cb['status'];
    };
    /** The session is lost after sign-in (the case being guarded): drop the session cookie, keep the rest. */
    $loseSession = static function (string $jar): void {
        $lines = file($jar, FILE_IGNORE_NEW_LINES) ?: [];
        $lines = array_filter($lines, static fn (string $l) => (explode("\t", $l)[5] ?? '') !== 'osmhelper');
        file_put_contents($jar, implode("\n", $lines) . "\n");
    };

    // 4a. Normal first sign-in: straight through with no extra click, back to the page that needed it.
    $t0 = osmCalls($fakeLog, '/oauth/token');
    $jar = $tmp . '/jar-normal.txt';
    check($pass('/members/', $jar) === 'signed-in:/members/', 'whole sign-in: first sign-in goes straight through and back to /members/');
    check(osmCalls($fakeLog, '/oauth/token') - $t0 === 1, 'whole sign-in: one token call');
    check(str_contains((string) file_get_contents($jar), "\tosmhelper_signins\t"), 'whole sign-in: success noted in its own cookie');
    // Sign out and straight back in: still automatic (sign-out forgets recent sign-ins).
    req("$base/logout", $jar);
    check(!str_contains((string) file_get_contents($jar), "\tosmhelper_signins\t"), 'sign-out: clears the recent sign-ins cookie');
    check($pass('/members/', $jar) === 'signed-in:/members/', 'sign-out then sign in again: still automatic');

    // 4b. Geoffrey's harness K: sign-in succeeds but the session is lost each time. Before, 5 passes = 5 token calls.
    $jar = $tmp . '/jar-lost.txt';
    $t0 = osmCalls($fakeLog, '/oauth/token');
    $r0 = osmCalls($fakeLog, '/oauth/resource');
    $results = [];
    for ($i = 1; $i <= 5; $i++) {
        $results[] = $pass('/members/', $jar);
        $loseSession($jar);
    }
    $tokens = osmCalls($fakeLog, '/oauth/token') - $t0;
    $resources = osmCalls($fakeLog, '/oauth/resource') - $r0;
    check(array_slice($results, 0, 3) === array_fill(0, 3, 'signed-in:/members/'), 'session lost: first 3 passes sign in (each looks normal)');
    check(array_slice($results, 3) === ['paused', 'paused'], 'session lost: 4th and 5th passes stop at Continue to OSM');
    check($tokens === 3 && $resources === 3, "session lost: 5 passes now make 3 token and 3 resource calls, not 5 (got $tokens/$resources)");
    $a = req("$base/auth/", $jar);
    check(!autoRedirects($a) && str_contains($a['body'], 'signed in several times in the last few minutes'), 'session lost: Continue page explains repeated sign-ins');
    check(!str_contains($a['body'], 'oauth/authorize'), 'session lost: paused page has no OSM URL');
    $g = req("$base/auth/?go=1", $jar);
    check($g['status'] === 200 && str_contains($g['body'], 'location.replace(') && str_contains($g['body'], "$osmBase/oauth/authorize"), 'session lost: Continue (?go=1) still proceeds to OSM');
    check(osmCalls($fakeLog, '/oauth/token') - $t0 === 3, 'session lost: pausing and Continue page make no token calls');
}

proc_terminate($proc);
proc_close($proc);
proc_terminate($fake);
proc_close($fake);
exec('rm -rf ' . escapeshellarg($tmp));
ob_end_clean();
echo implode("\n", $out), "\n\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
