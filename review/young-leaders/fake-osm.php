<?php
declare(strict_types=1);
/**
 * FAKE OSM for local review only: `php -S 127.0.0.1:8099 review/young-leaders/fake-osm.php`.
 * Serves tests/fixtures/young-leaders (all made-up people) with OSM-style rate-limit headers.
 * It never contacts OSM. Options (environment): FAKE_OSM_LIMIT (default 1000),
 * FAKE_OSM_REMAINING (starting allowance, default the limit), FAKE_OSM_429_AT=<scoutid> (answer 429 for that member), FAKE_OSM_ERROR_AT=<scoutid> (answer 500).
 */
$fx = dirname(__DIR__, 2) . '/tests/fixtures/young-leaders';
$state = sys_get_temp_dir() . '/osmhelper-yl-review/fake-osm-remaining.txt';
@mkdir(dirname($state), 0700, true);
$limit = (int) (getenv('FAKE_OSM_LIMIT') ?: 1000);
$remaining = is_file($state) ? (int) file_get_contents($state) : (int) (getenv('FAKE_OSM_REMAINING') ?: $limit);
$remaining = max(0, $remaining - 1);
file_put_contents($state, (string) $remaining);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $q);
$action = (string) ($q['action'] ?? '');
$sid = (string) ($q['scoutid'] ?? '');
error_log(sprintf('fake-osm %s %s%s remaining=%d', $_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $action !== '' ? " action=$action" . ($sid !== '' ? " scoutid=$sid" : '') : '', $remaining));

header('Content-Type: application/json');
header('X-RateLimit-Limit: ' . $limit);
header('X-RateLimit-Remaining: ' . $remaining);
header('X-RateLimit-Reset: 3000');

if ($sid !== '' && $sid === (string) getenv('FAKE_OSM_429_AT')) {
    http_response_code(429);
    header('Retry-After: 600');
    echo '{"error":"Too many requests"}';
    return true;
}
if ($sid !== '' && $sid === (string) getenv('FAKE_OSM_ERROR_AT')) {
    http_response_code(500);
    echo '{"error":"fake server error"}';
    return true;
}
if ($path === '/oauth/resource') {
    echo json_encode(['status' => true, 'data' => [
        'user_id' => 9001, 'full_name' => 'Review Leader', 'email' => 'review@example.invalid',
        'sections' => json_decode((string) file_get_contents("$fx/sections.json"), true),
    ]]);
    return true;
}
if ($path === '/ext/members/contact/' && $action === 'getListOfMembers') {
    readfile("$fx/members.json");
    return true;
}
if ($path === '/ext/members/contact/' && $action === 'getIndividual') {
    $all = json_decode((string) file_get_contents("$fx/individuals.json"), true);
    echo json_encode($all[$sid] ?? ['ok' => false, 'error' => 'Member not found']);
    return true;
}
http_response_code(404);
echo '{"error":"not in the fake"}';
return true;
