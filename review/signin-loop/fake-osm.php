<?php
declare(strict_types=1);
/**
 * Made-up stand-in for OSM, for tests/signin-loop-test.php only. Run under `php -S 127.0.0.1:PORT`
 * (it refuses any other address). It answers /oauth/authorize, /oauth/token and /oauth/resource with
 * fake data and writes one line per request ("METHOD /path") to SIGNIN_REVIEW_FAKE_LOG so tests can
 * count how many OSM calls a flow would have made. Nothing here is a real credential, and it never
 * contacts OSM. Not deployed (deploy.sh excludes review/).
 */
if (($_SERVER['SERVER_NAME'] ?? '') !== '127.0.0.1' && ($_SERVER['SERVER_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(500);
    exit('fake OSM: local only');
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $q);
$log = getenv('SIGNIN_REVIEW_FAKE_LOG') ?: (sys_get_temp_dir() . '/osmhelper-fake-osm.log');
file_put_contents($log, ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $path . "\n", FILE_APPEND | LOCK_EX);
header('X-RateLimit-Limit: 1000');
header('X-RateLimit-Remaining: 900');
header('X-RateLimit-Reset: 3600');
if ($path === '/oauth/authorize') {
    $back = (string) ($q['redirect_uri'] ?? '');
    if (!str_starts_with($back, 'http://127.0.0.1:')) {
        http_response_code(400);
        exit('fake OSM: local redirect_uri only');
    }
    $sep = str_contains($back, '?') ? '&' : '?';
    header('Location: ' . $back . $sep . 'code=fake-code-' . bin2hex(random_bytes(4)) . '&state=' . rawurlencode((string) ($q['state'] ?? '')));
    exit;
}
header('Content-Type: application/json');
if ($path === '/oauth/token') {
    echo json_encode(['access_token' => 'fake-at-' . bin2hex(random_bytes(4)), 'refresh_token' => 'fake-rt', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    exit;
}
if ($path === '/oauth/resource') {
    echo json_encode(['status' => true, 'data' => [
        'user_id' => 4242, 'full_name' => 'Test Leader', 'email' => 'leader@example.invalid',
        'sections' => [['section_id' => 7001, 'section_name' => 'Beavers', 'group_name' => '4th Test', 'section_type' => 'beavers', 'terms' => [], 'upgrades' => []]],
    ]]);
    exit;
}
echo json_encode(['status' => true, 'items' => []]);
