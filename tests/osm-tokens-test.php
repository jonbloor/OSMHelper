<?php
declare(strict_types=1);
/**
 * Offline tests for OSM token renewal (session + WordPress form row). No network / no OSM.
 * A fake token endpoint mimics OSM: each refresh token works once and is then retired.
 * Run: php tests/osm-tokens-test.php
 */
$dbFile = sys_get_temp_dir() . '/osm-tokens-test-' . bin2hex(random_bytes(4)) . '.sqlite';
$_ENV['OSMHELPER_DB_PATH'] = $dbFile;
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Osm\OsmApi;
use App\Osm\OsmTokens;
use App\Store\WordpressSiteKeyStore;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

$failed = 0;
$passed = 0;
function check(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) { $passed++; echo "OK  $msg\n"; } else { $failed++; echo "FAIL $msg\n"; }
}

// Fake OSM token endpoint with refresh-token rotation.
$valid = [];
$calls = [];
$n = 0;
OsmTokens::$refresher = static function (string $rt) use (&$valid, &$calls, &$n): ?array {
    $calls[] = $rt;
    if (empty($valid[$rt])) {
        return null; // retired or unknown → invalid_grant
    }
    unset($valid[$rt]);
    $n++;
    $newRt = 'RT' . $n;
    $valid[$newRt] = true;
    return ['access' => 'A' . $n, 'refresh' => $newRt, 'expires' => time() + 3600];
};
function reset_world(): void
{
    global $valid, $calls, $n;
    $valid = ['RT0' => true];
    $calls = [];
    $n = 0;
    $_SESSION = ['accessToken' => 'A0', 'refreshToken' => 'RT0', 'accessTokenExpiresAt' => time() - 10, 'osmUserId' => 'u1'];
    WordpressSiteKeyStore::deleteForUser('u1');
}
function save_row(string $access, string $refresh, int $exp): array
{
    $saved = WordpressSiteKeyStore::upsertForUser('u1', '60830', 'Waiting list', $access, $refresh, $exp, true);
    return WordpressSiteKeyStore::findByOsmUserId('u1');
}

// 1. Fresh token: no refresh.
reset_world();
$_SESSION['accessTokenExpiresAt'] = time() + 1800;
check(OsmTokens::sessionToken() === 'A0' && $calls === [], 'fresh session token used as is, no OSM call');

// 2. Expired, no form row: refresh with the session refresh token.
reset_world();
$t = OsmTokens::sessionToken();
check($t === 'A1' && $_SESSION['refreshToken'] === 'RT1' && $_SESSION['accessTokenExpiresAt'] > time() + 3000, 'expired session token is refreshed and stored');
check($calls === ['RT0'], 'one refresh call');

// 3. Session refresh keeps the form row working when they share a refresh token (after Save).
reset_world();
save_row('A0', 'RT0', time() - 10);
OsmTokens::sessionToken();
$row = WordpressSiteKeyStore::findByOsmUserId('u1');
check($row['refresh_token'] === 'RT1' && $row['access_token'] === 'A1', 'form row updated with the rotated tokens');
$r = OsmTokens::renewRow($row);
check($r !== null && $r['access'] === 'A1' && count($calls) === 1, 'form then uses the fresh token without another refresh');

// 4. Jon's case: form refreshes first (rotates RT0), then the session token expires.
reset_world();
$row = save_row('A0', 'RT0', time() - 10);
$r = OsmTokens::renewRow($row);
check($r !== null && $r['access'] === 'A1', 'form refresh works');
$t = OsmTokens::sessionToken();
check($t === 'A1' && $_SESSION['refreshToken'] === 'RT1', 'session adopts the form’s fresh token instead of failing');
check(count($calls) === 1, 'no extra OSM call (no attempt with the retired refresh token)');

// 5. Form rotated the token and its access token has expired too: continue from the form’s chain.
reset_world();
$row = save_row('A0', 'RT0', time() - 10);
OsmTokens::renewRow($row);
WordpressSiteKeyStore::updateTokens((int) $row['id'], 'A1', 'RT1', time() - 5);
$t = OsmTokens::sessionToken();
$row = WordpressSiteKeyStore::findByOsmUserId('u1');
check($t === 'A2' && $_SESSION['refreshToken'] === 'RT2' && $row['refresh_token'] === 'RT2', 'session and form both move to the new tokens');
check($calls === ['RT0', 'RT0', 'RT1'], 'tried the session token, then the form’s token');

// 6. OSM said "not logged in" (token revoked early): renewed on the next page.
reset_world();
$_SESSION['accessTokenExpiresAt'] = time() + 1800;
$mock = new MockHandler([new Response(403, [], json_encode(['error' => ['code' => 'access-error-2', 'message' => 'You must be logged in to use this.']]))]);
$api = new OsmApi(new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://example.invalid/', 'http_errors' => false]));
try { $api->get('A0', '/oauth/resource'); } catch (Throwable) {}
check(isset($_SESSION['osmTokenRejected']), 'OsmApi marks the session token as rejected on access-error-2');
$t = OsmTokens::sessionToken();
check($t === 'A1' && !isset($_SESSION['osmTokenRejected']), 'rejected token is renewed and the flag cleared');
try { $api2 = new OsmApi(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(403, [], '{"error":{"code":"access-error-2","message":"x"}}')])), 'base_uri' => 'https://example.invalid/', 'http_errors' => false])); $api2->get('someone-else', '/x'); } catch (Throwable) {}
check(!isset($_SESSION['osmTokenRejected']), 'a different token (e.g. the form’s) does not flag the session');

// 7. Nothing works: sign in again.
reset_world();
$valid = [];
check(OsmTokens::sessionToken() === null && !isset($_SESSION['accessToken']) && !isset($_SESSION['refreshToken']), 'unrenewable token → null and session tokens cleared (redirect to sign in)');
check(($_SESSION['osmUserId'] ?? '') === 'u1', 'other session data kept');

// 8. Not-logged-in detection.
check(OsmTokens::isNotLoggedInError(403, 'access-error-2', null), 'detects access-error-2');
check(OsmTokens::isNotLoggedInError(401, null, 'Unauthenticated.'), 'detects 401 Unauthenticated');
check(!OsmTokens::isNotLoggedInError(403, 'permission', 'You do not have permission'), 'ordinary 403 permission error is not treated as signed out');
check(!OsmTokens::isNotLoggedInError(429, null, 'must be logged in'), '429 is not treated as signed out');

// 9. Form row with no refresh token still fails cleanly.
reset_world();
$row = save_row('A0', 'RT0', time() - 10);
WordpressSiteKeyStore::updateTokens((int) $row['id'], 'A0', null, time() - 10);
$row = WordpressSiteKeyStore::findByOsmUserId('u1');
check(OsmTokens::renewRow($row) === null, 'form row without a refresh token → null (intake asks the leader to Save again)');

@unlink($dbFile); @unlink($dbFile . '-wal'); @unlink($dbFile . '-shm');
echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
