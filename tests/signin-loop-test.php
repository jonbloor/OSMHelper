<?php
declare(strict_types=1);
/**
 * Sign-in loop guard tests (phone hands the OSM link to the OSM app, so the sign-in finishes in a
 * different browser and every page sends the leader back to OSM). No network beyond 127.0.0.1 and
 * no OSM: the HTTP part runs the app under `php -S` with review/signin-loop/app-router.php, whose
 * OSM_API_BASE is a local placeholder that is never contacted (/auth only builds a URL, and the
 * /callback paths tested stop before any token exchange).
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
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root . '/public', $root . '/review/signin-loop/app-router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
    $root,
    array_merge(getenv(), ['SIGNIN_REVIEW_TMP' => $reviewTmp])
);
$base = "http://127.0.0.1:$port";
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100_000);
    $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($c) { $up = true; fclose($c); }
}
check($up, 'http: local server started');

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
    check(str_contains($r['body'], 'Sign-in started in a different browser or app (for example the OSM app), so we can&#039;t finish it here. Please try again in this browser.'), 'callback state missing: explains different browser or app');
    check(str_contains($r['body'], 'href="/auth/?retry=1"') && str_contains($r['body'], 'Try again here'), 'callback state missing: Try again here → /auth/?retry=1');
    check(str_contains($r['body'], 'Open supported links') && str_contains($r['body'], 'Open links in apps'), 'callback state missing: app tip shown');
    check(str_contains($r['body'], 'Open links in apps</strong> and choose <strong>Never</strong>'), 'app tip: Firefox/Fennec told to choose Never (not a switch)');
    check(str_contains($r['body'], '(or whatever the OSM icon is called)') && str_contains($r['body'], 'Set as default'), 'app tip: Android route names the OSM icon and Samsung\'s "Set as default"');
    check(str_contains($r['body'], "Chrome doesn't have a setting like this") && !str_contains($r['body'], "this browser's settings"), 'app tip: Chrome users not sent looking for a setting that isn\'t there');
    check(str_contains($r['body'], 'Details:'), 'callback state missing: reason detail shown');

    // 1b. State mismatch in the same browser (a real /auth first, then a wrong state).
    $jar = $tmp . '/jar-mismatch.txt';
    $a = req("$base/auth/", $jar);
    check($a['status'] === 200 && str_contains($a['body'], 'location.replace('), 'auth: first visit still auto-redirects (normal flow)');
    check(str_contains($a['body'], 'http://127.0.0.1:8099/oauth/authorize'), 'auth: authorize URL points at the local placeholder only');
    $r = req("$base/callback?code=fake-code&state=wrong-state", $jar);
    check($r['status'] === 400 && !autoRedirects($r), 'callback state mismatch: 400, no redirect');
    check(str_contains($r['body'], 'saved login state did not match') && str_contains($r['body'], 'href="/auth/?retry=1"'), 'callback state mismatch: retry page with detail');

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
    check($g['status'] === 200 && str_contains($g['body'], 'location.replace(') && str_contains($g['body'], 'http://127.0.0.1:8099/oauth/authorize'), 'loop guard: Continue starts the normal flow');
    check(preg_match('/^set-cookie: osmhelper_oauth=/mi', $g['headers']) === 1, 'loop guard: Continue sets the OAuth state cookie');
    $r = req("$base/auth/?retry=1", $tmp . '/jar-retry.txt');
    check($r['status'] === 200 && !autoRedirects($r) && str_contains($r['body'], 'Continue to OSM'), 'loop guard: ?retry=1 shows Continue even on the first visit');
    $p = req("$base/dashboard/", $tmp . '/jar-retry.txt');
    check($p['status'] === 302 && str_contains($p['headers'], '/auth/'), 'loop guard: signed-out pages still send to /auth/ (which now pauses)');
    $a = req("$base/auth/", $tmp . '/jar-retry.txt');
    check(!autoRedirects($a) && str_contains($a['body'], 'Continue to OSM'), 'loop guard: so a loop stops at the Continue page');
}

proc_terminate($proc);
proc_close($proc);
exec('rm -rf ' . escapeshellarg($tmp));
ob_end_clean();
echo implode("\n", $out), "\n\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
