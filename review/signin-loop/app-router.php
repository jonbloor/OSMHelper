<?php
declare(strict_types=1);
/**
 * Local review of the sign-in retry / "Continue to OSM" pages. Run with review/signin-loop/run.sh;
 * tests/signin-loop-test.php uses it too. Not deployed (deploy.sh excludes review/), and it refuses
 * to start unless OSM_API_BASE is 127.0.0.1. Nothing here ever calls OSM: /auth only builds the
 * authorize URL (pointing at the local placeholder), and the /callback paths shown fail before any
 * token exchange.
 */
$root = dirname(__DIR__, 2);
$tmp = getenv('SIGNIN_REVIEW_TMP') ?: (sys_get_temp_dir() . '/osmhelper-signin-review');
foreach (['sessions', 'osm-cache', 'osm-rate', 'yl-limits'] as $d) {
    @mkdir("$tmp/$d", 0700, true);
}
// Tests can run review/signin-loop/fake-osm.php on another local port; it is always 127.0.0.1.
$osmPort = (string) getenv('SIGNIN_REVIEW_OSM_PORT');
$osmPort = ctype_digit($osmPort) && (int) $osmPort > 0 && (int) $osmPort < 65536 ? $osmPort : '8099';
$env = [
    'OSM_API_BASE' => 'http://127.0.0.1:' . $osmPort,
    'APP_ENV' => 'dev',
    'OSMHELPER_SESSION_PATH' => "$tmp/sessions",
    // Made-up values so the app boots; nothing here is a real credential.
    'SESSION_SECRET' => 'review-only-not-a-secret-0000000000000000',
    'CLIENT_ID' => 'review-client',
    'CLIENT_SECRET' => 'review-not-a-secret',
    'REDIRECT_URI' => 'http://127.0.0.1:8097/callback',
];
foreach ($env as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}
require $root . '/vendor/autoload.php';
if (!str_starts_with(\App\Config::osmApiBase(), 'http://127.0.0.1:')) {
    http_response_code(500);
    exit('Review harness refused to run: OSM_API_BASE is not local.');
}
\App\Osm\OsmCache::useDirectory("$tmp/osm-cache");
\App\Osm\OsmRateGuard::useDirectory("$tmp/osm-rate");
\App\Members\YoungLeaderLimits::useDirectory("$tmp/yl-limits");

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && is_file($root . '/public' . $path)) {
    return false; // assets
}
require $root . '/public/index.php';
