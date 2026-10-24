<?php
declare(strict_types=1);
/**
 * Local review of the Young Leaders page against the FAKE OSM (review/young-leaders/fake-osm.php).
 * Run with review/young-leaders/run.sh. Not deployed (deploy.sh excludes review/), not part of the app,
 * and it refuses to start unless every OSM request goes to 127.0.0.1.
 */
$root = dirname(__DIR__, 2);
$fakeBase = 'http://127.0.0.1:8099';
$tmp = sys_get_temp_dir() . '/osmhelper-yl-review';
foreach (['sessions', 'osm-cache', 'osm-rate', 'yl-limits'] as $d) {
    @mkdir("$tmp/$d", 0700, true);
}
$env = [
    'OSM_API_BASE' => $fakeBase,
    'APP_ENV' => 'dev',
    'OSMHELPER_SESSION_PATH' => "$tmp/sessions",
    // Made-up values so the app boots; nothing here is a real credential.
    'SESSION_SECRET' => 'review-only-not-a-secret-0000000000000000',
    'CLIENT_ID' => 'review-client',
    'CLIENT_SECRET' => 'review-not-a-secret',
    'REDIRECT_URI' => 'http://127.0.0.1:8098/callback',
];
foreach ($env as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}
require $root . '/vendor/autoload.php';
if (!str_starts_with(\App\Config::osmApiBase(), 'http://127.0.0.1:')) {
    http_response_code(500);
    exit('Review harness refused to run: OSM_API_BASE is not the local fake.');
}
\App\Osm\OsmCache::useDirectory("$tmp/osm-cache");
\App\Osm\OsmRateGuard::useDirectory("$tmp/osm-rate");
// Young Leaders limits in the temp dir too, never the repo's storage/yl-limits.
// REVIEW_YL_LIMITS=unwritable points it at a plain file instead, to see the fail-closed refusal.
if (getenv('REVIEW_YL_LIMITS') === 'unwritable') {
    if (!is_file("$tmp/yl-limits-broken")) {
        @file_put_contents("$tmp/yl-limits-broken", "not a directory\n");
    }
    \App\Members\YoungLeaderLimits::useDirectory("$tmp/yl-limits-broken");
} else {
    \App\Members\YoungLeaderLimits::useDirectory("$tmp/yl-limits");
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && is_file($root . '/public' . $path)) {
    return false; // assets
}

session_name('osmhelper');
session_save_path("$tmp/sessions");
session_set_cookie_params(['lifetime' => 7200, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

if ($path === '/__review/sign-in') {
    // A fake signed-in session: fake token (only ever sent to the fake), sections from the fixture.
    session_regenerate_id(true);
    $_SESSION = [
        '_startedAt' => time(),
        '_lastSeen' => time(),
        'accessToken' => 'fake-review-token',
        'accessTokenExpiresAt' => time() + 86400,
        'osmUserId' => '9001',
        'fullName' => 'Review Leader',
        'groupName' => '4th Ashby de la Zouch (fake)',
    ];
    \App\Osm\LoginSections::remember(json_decode((string) file_get_contents($root . '/tests/fixtures/young-leaders/sections.json'), true));
    header('Location: /young-leaders/', true, 303);
    exit;
}
require $root . '/public/index.php';
