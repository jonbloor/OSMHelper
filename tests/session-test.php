<?php
declare(strict_types=1);
/**
 * Offline session tests: configurable 2-hour limit, new session ID on sign-in, safe return-to,
 * "Signed in until" notice. No network, no OSM. All values are fake.
 * Run: php tests/session-test.php
 */
ob_start(); // keep headers unsent so session_regenerate_id() works under the CLI
require dirname(__DIR__) . '/vendor/autoload.php';

use App\App;
use App\Config;

$failed = 0;
$passed = 0;
$out = [];
function check(bool $cond, string $msg): void
{
    global $failed, $passed, $out;
    if ($cond) { $passed++; $out[] = "OK  $msg"; } else { $failed++; $out[] = "FAIL $msg"; }
}

$tmp = sys_get_temp_dir() . '/osmh-session-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
ini_set('session.use_cookies', '1');
session_save_path($tmp);
session_name('osmhelper');
session_start();

// ---------- Config ----------
unset($_ENV['SESSION_ABSOLUTE_SECONDS'], $_ENV['SESSION_IDLE_SECONDS']);
check(Config::sessionAbsoluteSeconds() === 7200 && Config::sessionIdleSeconds() === 7200, 'config: defaults 7200 / 7200');
check(App::sessionLifetimeSeconds() === 7200, 'config: cookie + gc lifetime 7200 by default');
$_ENV['SESSION_ABSOLUTE_SECONDS'] = '3600';
$_ENV['SESSION_IDLE_SECONDS'] = '1800';
check(Config::sessionAbsoluteSeconds() === 3600 && Config::sessionIdleSeconds() === 1800, 'config: .env overrides');
check(App::sessionLifetimeSeconds() === 3600, 'config: lifetime = the longer limit');
$_ENV['SESSION_IDLE_SECONDS'] = '9999';
check(Config::sessionIdleSeconds() === 3600 && App::sessionLifetimeSeconds() >= Config::sessionAbsoluteSeconds(), 'config: idle never longer than absolute; lifetime >= absolute');
$_ENV['SESSION_ABSOLUTE_SECONDS'] = '5';
check(Config::sessionAbsoluteSeconds() === 300, 'config: clamped to at least 300');
$_ENV['SESSION_ABSOLUTE_SECONDS'] = '999999';
check(Config::sessionAbsoluteSeconds() === 86400, 'config: clamped to at most 86400');
$_ENV['SESSION_ABSOLUTE_SECONDS'] = 'two hours';
check(Config::sessionAbsoluteSeconds() === 7200, 'config: junk value → default');
unset($_ENV['SESSION_ABSOLUTE_SECONDS'], $_ENV['SESSION_IDLE_SECONDS']);

// ---------- Hard 2-hour limit, even while working ----------
$now = 1_800_000_000;
$_SESSION = ['accessToken' => 'fake', '_startedAt' => $now - 7199, '_lastSeen' => $now - 1];
check(App::enforceSessionMaxAge($now) === false && isset($_SESSION['accessToken']), 'limit: 1 s before 2 h kept');
$_SESSION = ['accessToken' => 'fake', '_startedAt' => $now - 7200, '_lastSeen' => $now - 1];
check(App::enforceSessionMaxAge($now) === true && !isset($_SESSION['accessToken']), 'limit: ends at 2 h even while active');
$_ENV['SESSION_ABSOLUTE_SECONDS'] = '600';
$_SESSION = ['accessToken' => 'fake', '_startedAt' => $now - 601, '_lastSeen' => $now - 1];
check(App::enforceSessionMaxAge($now) === true, 'limit: follows SESSION_ABSOLUTE_SECONDS');
unset($_ENV['SESSION_ABSOLUTE_SECONDS']);
$_ENV['SESSION_IDLE_SECONDS'] = '900';
$_SESSION = ['accessToken' => 'fake', '_startedAt' => $now - 1000, '_lastSeen' => $now - 901];
check(App::enforceSessionMaxAge($now) === true, 'limit: follows SESSION_IDLE_SECONDS');
unset($_ENV['SESSION_IDLE_SECONDS']);

// ---------- New session ID on sign-in ----------
$_SESSION = ['_startedAt' => $now - 9000, 'membersListResult' => ['fake'], 'returnTo' => '/members/', 'attacker' => 'planted'];
session_write_close();
$fixedId = 'fixation' . bin2hex(random_bytes(8));
session_id($fixedId);
session_start();
$_SESSION = ['returnTo' => '/members/', 'membersListResult' => ['fake'], 'attacker' => 'planted'];
$before = session_id();
App::beginAuthenticatedSession($now);
$after = session_id();
check($before === $fixedId && $after !== '' && $after !== $before, 'sign-in: session ID regenerated (no fixation)');
check(!isset($_SESSION['membersListResult']) && !isset($_SESSION['attacker']), 'sign-in: nothing from before sign-in carries over');
check(($_SESSION['_startedAt'] ?? null) === $now && ($_SESSION['_lastSeen'] ?? null) === $now, 'sign-in: 2-hour clock starts at sign-in');
check(($_SESSION['returnTo'] ?? null) === '/members/', 'sign-in: return-to kept');
$cookies = implode("\n", array_filter(headers_list(), static fn ($h) => stripos($h, 'Set-Cookie: osmhelper=') === 0));
check($cookies === '' || str_contains($cookies, $after), 'sign-in: cookie re-sent with the new ID (when headers are available)');
$cb = (string) file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/AuthController.php');
$posBegin = strpos($cb, 'App::beginAuthenticatedSession();');
$posTok = strpos($cb, "\$_SESSION['accessToken'] = \$accessToken;");
check($posBegin !== false && $posTok !== false && $posBegin < $posTok, 'callback: regenerates before storing the token');
check(str_contains($cb, "header('Location: ' . App::takeReturnPath());"), 'callback: redirects to the safe return path');
$appSrc = (string) file_get_contents(dirname(__DIR__) . '/src/App.php');
check(str_contains($appSrc, 'session_regenerate_id(true);'), 'App: regenerate deletes the old session file (true)');

// ---------- Safe return-to ----------
$good = ['/members/', '/nights-away/?refresh=1', '/top-awards/?section=123', '/help/#privacy'];
foreach ($good as $p) {
    check(App::safeReturnPath($p) === $p, "return-to: allows $p");
}
$bad = ['', 'members', '//evil.example/', '/\\evil.example', 'https://evil.example/', "/members/\r\nX: y", '/auth/', '/callback?code=x', '/logout/', '/login', 'javascript:alert(1)', '/' . str_repeat('a', 600), null, ['x']];
foreach ($bad as $p) {
    check(App::safeReturnPath($p) === null, 'return-to: rejects ' . json_encode($p));
}
$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/members/';
App::rememberReturnPath();
check(!isset($_SESSION['returnTo']), 'return-to: POST pages not remembered');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '//evil.example/';
App::rememberReturnPath();
check(!isset($_SESSION['returnTo']), 'return-to: unsafe GET path not remembered');
$_SERVER['REQUEST_URI'] = '/equipment/';
App::rememberReturnPath();
check(($_SESSION['returnTo'] ?? null) === '/equipment/', 'return-to: GET page remembered');
check(App::takeReturnPath() === '/equipment/' && !isset($_SESSION['returnTo']), 'return-to: taken once');
check(App::takeReturnPath() === '/', 'return-to: default home');
$_SESSION['returnTo'] = 'https://evil.example/';
check(App::takeReturnPath() === '/', 'return-to: tampered session value ignored');
$authSrc = (string) file_get_contents(dirname(__DIR__) . '/src/Http/Auth.php');
check(str_contains($authSrc, 'App::rememberReturnPath();'), 'requireLogin remembers the page');

// ---------- "Signed in until" ----------
$_SESSION = ['accessToken' => 'fake', '_startedAt' => $now, '_lastSeen' => $now + 60];
check(App::sessionEndsAt() === $now + 7200, 'notice: ends 2 h after sign-in');
$_SESSION = [];
check(App::sessionEndsAt() === null, 'notice: none when signed out');
$layout = (string) file_get_contents(dirname(__DIR__) . '/templates/layout.twig');
check(str_contains($layout, 'Signed in until {{ sessionEndsText }}') && str_contains($layout, 'class="session-ends"'), 'notice: shown in layout');
$noticeBlock = substr($layout, (int) strpos($layout, 'session-ends'), 400);
check(!str_contains($noticeBlock, '<script') && !str_contains($noticeBlock, 'style='), 'notice: plain text, no inline script/style (CSP-safe)');
check(str_contains($appSrc, "'Europe/London'") && str_contains($appSrc, "'sessionEndsText'"), 'notice: time shown in UK time');

session_write_close();
foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmp);
ob_end_clean();
echo implode("\n", $out), "\n\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
