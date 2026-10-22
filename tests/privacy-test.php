<?php
declare(strict_types=1);
/**
 * Offline privacy tests: log redaction, token encryption at rest, error-log pruning, 2-hour sessions,
 * Find a member search kept out of URLs. No network, no OSM. All values below are fake.
 * Run: php tests/privacy-test.php   (needs the sodium extension)
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\App;
use App\Http\Controllers\FindMemberController;
use App\Osm\OsmErrorLog;
use App\Store\Db;
use App\Store\TokenCrypto;
use App\Store\WordpressSiteKeyStore;
use App\Support\LogSafe;

$failed = 0;
$passed = 0;
function check(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) { $passed++; echo "OK  $msg\n"; } else { $failed++; echo "FAIL $msg\n"; }
}

$tmp = sys_get_temp_dir() . '/osmh-privacy-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
// Capture error_log output so we can check what would be written.
$phpLog = $tmp . '/php-error.log';
ini_set('log_errors', '1');
ini_set('error_log', $phpLog);
function phpLog(): string { global $phpLog; return is_file($phpLog) ? (string) file_get_contents($phpLog) : ''; }

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path($tmp);
    @session_start();
}

// ---------- LogSafe::redact ----------
$r = LogSafe::redact('Authorization: Bearer abcDEF123456789xyz.more failed');
check(!str_contains($r, 'abcDEF123456789xyz'), 'redact: bearer token removed');
$r = LogSafe::redact('access_token=FAKEaccess12345&refresh_token=FAKErefresh678');
check(!str_contains($r, 'FAKEaccess12345') && !str_contains($r, 'FAKErefresh678'), 'redact: token params removed');
$r = LogSafe::redact('{"access_token":"FAKEjsonTok999","ok":1}');
check(!str_contains($r, 'FAKEjsonTok999'), 'redact: JSON token value removed');
$r = LogSafe::redact('OSMHELPER_TOKEN_KEY=ZmFrZWtleWZha2VrZXlmYWtla2V5ZmFrZWtleQ==');
check(!str_contains($r, 'ZmFrZWtleWZha2Vr'), 'redact: token key value removed');
$r = LogSafe::redact('failed for jane.doe@example.com tel 07700 900123 at LE65 1AB');
check(!str_contains($r, 'jane.doe@example.com'), 'redact: email removed');
check(!str_contains($r, '900123'), 'redact: UK phone removed');
check(!str_contains($r, 'LE65 1AB') && !str_contains($r, 'LE651AB'), 'redact: postcode removed');
$r = LogSafe::redact('firstname=Jane&lastname=Doe&scoutid=551122&sectionid=123');
check(!str_contains($r, 'Jane') && !str_contains($r, 'Doe') && !str_contains($r, '551122'), 'redact: PII keys + scoutid removed');
$r = LogSafe::redact('GET https://www.onlinescoutmanager.co.uk/ext/members/?action=getX&scoutid=551122 failed');
check(!str_contains($r, '551122') && str_contains($r, '/ext/members/'), 'redact: query string stripped, path kept');
$r = LogSafe::redact('token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.c2lnbmF0dXJlc2lnbmF0dXJl here');
check(!str_contains($r, 'eyJzdWIiOiIxMjMifQ'), 'redact: JWT removed');
check(LogSafe::redact(null) === null, 'redact: null stays null');
check(LogSafe::redact('Section 123 not found') === 'Section 123 not found', 'redact: harmless text unchanged');
check(LogSafe::pathOnly('https://x.test/ext/members/contact/?action=get&q=LE65') === 'https://x.test/ext/members/contact/', 'pathOnly drops query, keeps host + path');
check(LogSafe::pathOnly('/ext/events/?a=1') === '/ext/events/', 'pathOnly on relative path');

// ---------- LogSafe::reason ----------
$e = new RuntimeException('OSM HTTP 429: {"error":"Jane Doe 07700900123"}', 429);
$reason = LogSafe::reason($e);
check(!str_contains($reason, 'Jane') && !str_contains($reason, '07700900123'), 'reason: no message text');
check(str_contains($reason, 'RuntimeException') && str_contains($reason, '429'), 'reason: class + code kept: ' . $reason);
check(LogSafe::reason(new PDOException('SQLSTATE[HY000] database is locked')) !== '' && !str_contains(LogSafe::reason(new PDOException('SQLSTATE secret jane@example.com')), 'jane'), 'reason: PDO message hidden');

// ---------- TokenCrypto ----------
$key = base64_encode(random_bytes(32));
TokenCrypto::setKeyForTests($key);
check(TokenCrypto::available(), 'crypto: key available');
$enc = TokenCrypto::encrypt('fake-access-token-1');
check(is_string($enc) && str_starts_with($enc, 'sb1:') && !str_contains($enc, 'fake-access-token-1'), 'crypto: encrypted with sb1 prefix');
check(TokenCrypto::encrypt('fake-access-token-1') !== $enc, 'crypto: random nonce (two ciphertexts differ)');
check(TokenCrypto::decrypt($enc) === 'fake-access-token-1', 'crypto: round-trip');
check(TokenCrypto::encrypt($enc) === $enc, 'crypto: already-encrypted value not double-encrypted');
check(TokenCrypto::decrypt('legacy-plain-token') === 'legacy-plain-token', 'crypto: plaintext passes through');
check(TokenCrypto::decrypt(null) === null && TokenCrypto::encrypt(null) === null, 'crypto: null passes through');
TokenCrypto::setKeyForTests(base64_encode(random_bytes(32)));
check(TokenCrypto::decrypt($enc) === null, 'crypto: wrong key → null');
check(str_contains(phpLog(), 'token_decrypt_failed'), 'crypto: wrong key logs reason code');
check(TokenCrypto::decrypt('sb1:@@@') === null && str_contains(phpLog(), 'token_decrypt_malformed'), 'crypto: malformed → null + reason');
TokenCrypto::setKeyForTests('');
check(!TokenCrypto::available(), 'crypto: no key → not available');
check(TokenCrypto::encrypt('fake-plain') === 'fake-plain', 'crypto: no key → fail-safe plaintext');
check(TokenCrypto::decrypt($enc) === null, 'crypto: no key → encrypted value unreadable (null)');
check(str_contains(phpLog(), 'token_key_missing'), 'crypto: missing key logs reason code');
TokenCrypto::setKeyForTests('c2hvcnQ=');
check(!TokenCrypto::available() && str_contains(phpLog(), 'token_key_invalid'), 'crypto: short key → invalid reason');
check(!str_contains(phpLog(), $key), 'crypto: key never logged');

// ---------- WordpressSiteKeyStore at rest ----------
$_ENV['OSMHELPER_DB_PATH'] = $tmp . '/test.sqlite';
Db::reset();
TokenCrypto::setKeyForTests($key);
WordpressSiteKeyStore::ensureSchema();
$up = WordpressSiteKeyStore::upsertForUser('9001', '123', 'Beavers', 'fake-acc-AAA', 'fake-ref-BBB', time() + 3600);
$raw = Db::pdo()->query("SELECT access_token, refresh_token FROM wordpress_site_keys WHERE osm_user_id='9001'")->fetch();
check(TokenCrypto::isEncrypted($raw['access_token']) && TokenCrypto::isEncrypted($raw['refresh_token']), 'store: new row encrypted at rest');
check(!str_contains(json_encode($raw), 'fake-acc-AAA'), 'store: no plaintext token in DB');
$row = WordpressSiteKeyStore::findByOsmUserId('9001');
check(($row['access_token'] ?? null) === 'fake-acc-AAA' && ($row['refresh_token'] ?? null) === 'fake-ref-BBB', 'store: find returns decrypted tokens');
$row2 = WordpressSiteKeyStore::findBySiteKey($up['siteKey']);
check(($row2['access_token'] ?? null) === 'fake-acc-AAA', 'store: findBySiteKey decrypts');
WordpressSiteKeyStore::updateTokens((int) $row['id'], 'fake-acc-CCC', null, null);
$raw = Db::pdo()->query("SELECT access_token, refresh_token FROM wordpress_site_keys WHERE osm_user_id='9001'")->fetch();
check(TokenCrypto::isEncrypted($raw['access_token']) && TokenCrypto::decrypt($raw['access_token']) === 'fake-acc-CCC', 'store: updateTokens encrypts');

// Legacy plaintext rows (saved before the key existed)
$now = gmdate('c');
$ins = Db::pdo()->prepare('INSERT INTO wordpress_site_keys (osm_user_id, site_key, section_id, section_name, access_token, refresh_token, token_expires_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
$ins->execute(['9002', 'sk-legacy-1', '124', 'Cubs', 'legacy-acc-1', 'legacy-ref-1', null, $now, $now]);
$ins->execute(['9003', 'sk-legacy-2', '125', 'Scouts', 'legacy-acc-2', null, null, $now, $now]);
$ins->execute(['9004', 'sk-legacy-3', '126', 'Explorers', 'legacy-acc-3', null, null, $now, $now]);
TokenCrypto::setKeyForTests('');
$row = WordpressSiteKeyStore::findByOsmUserId('9002');
check(($row['access_token'] ?? null) === 'legacy-acc-1', 'store: no key → legacy plaintext still readable');
$raw = Db::pdo()->query("SELECT access_token FROM wordpress_site_keys WHERE osm_user_id='9002'")->fetchColumn();
check($raw === 'legacy-acc-1', 'store: no key → row left as is');
$threw = false;
try { WordpressSiteKeyStore::encryptExistingRows(); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'store: migration refuses without key');
TokenCrypto::setKeyForTests($key);
$row = WordpressSiteKeyStore::findByOsmUserId('9002');
check(($row['access_token'] ?? null) === 'legacy-acc-1' && ($row['refresh_token'] ?? null) === 'legacy-ref-1', 'store: legacy row read with key');
$raw = Db::pdo()->query("SELECT access_token, refresh_token FROM wordpress_site_keys WHERE osm_user_id='9002'")->fetch();
check(TokenCrypto::isEncrypted($raw['access_token']) && TokenCrypto::isEncrypted($raw['refresh_token']), 'store: legacy row re-encrypted on read');
$res = WordpressSiteKeyStore::encryptExistingRows();
check($res === ['rows' => 3 + 1, 'encrypted' => 2, 'already' => 2], 'store: migration counts ' . json_encode($res));
$plainLeft = (int) Db::pdo()->query("SELECT COUNT(*) FROM wordpress_site_keys WHERE access_token NOT LIKE 'sb1:%' OR (refresh_token IS NOT NULL AND refresh_token NOT LIKE 'sb1:%')")->fetchColumn();
check($plainLeft === 0, 'store: no plaintext tokens left after migration');
check(WordpressSiteKeyStore::encryptExistingRows()['encrypted'] === 0, 'store: migration safe to re-run');
check((WordpressSiteKeyStore::findBySiteKey('sk-legacy-3')['access_token'] ?? null) === 'legacy-acc-3', 'store: migrated row decrypts');
TokenCrypto::setKeyForTests(base64_encode(random_bytes(32)));
check((WordpressSiteKeyStore::findBySiteKey('sk-legacy-3')['access_token'] ?? 'x') === '', 'store: wrong key → empty token (fail safe)');
TokenCrypto::setKeyForTests($key);
Db::reset();

// ---------- OsmErrorLog: no scoutid, 30-day pruning ----------
OsmErrorLog::setDirForTests($tmp);
@unlink(OsmErrorLog::path());
OsmErrorLog::log([
    'method' => 'GET', 'endpoint' => '/ext/members/contact/?action=getIndividual&scoutid=551122',
    'http_status' => 500, 'kind' => 'http', 'section_id' => '123', 'scoutid' => '551122',
    'osm_message' => 'Bad member Jane Doe jane@example.com', 'detail' => 'phone 07700 900123',
]);
$logText = (string) file_get_contents(OsmErrorLog::path());
check(!str_contains($logText, '551122'), 'errorlog: scoutid never written');
check(!str_contains($logText, 'jane@example.com') && !str_contains($logText, '900123'), 'errorlog: email/phone redacted');
check(str_contains($logText, '"section_id":"123"'), 'errorlog: section id kept');
$now = time();
$lines = [];
foreach ([40, 31, 29, 1] as $days) {
    $lines[] = json_encode(['at' => gmdate('c', $now - $days * 86400), 'kind' => 'http', 'n' => $days]);
}
$lines[] = 'not json';
file_put_contents(OsmErrorLog::path(), implode("\n", array_merge(array_slice($lines, 0, 2), [$lines[4]], array_slice($lines, 2, 2))) . "\n");
$removed = OsmErrorLog::pruneOld($now);
check($removed === 3, "errorlog: pruned 2 old + 1 unreadable (removed $removed)");
$kept = file(OsmErrorLog::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
check(count($kept) === 2 && str_contains($kept[0], '"n":29') && str_contains($kept[1], '"n":1'), 'errorlog: recent entries kept in order');
check(OsmErrorLog::pruneOld($now) === 0, 'errorlog: nothing to prune when first entry is recent');
file_put_contents(OsmErrorLog::path(), json_encode(['at' => gmdate('c', $now - 60 * 86400)]) . "\n");
check(OsmErrorLog::pruneOld($now) === 1 && !is_file(OsmErrorLog::path()), 'errorlog: all-old file removed');
check(OsmErrorLog::pruneOld($now) === 0, 'errorlog: missing file is fine');
OsmErrorLog::setDirForTests(null);

// ---------- 2-hour sessions ----------
check(App::SESSION_MAX_AGE_SEC === 7200, 'session: max age is 2 hours');
$_SESSION = ['accessToken' => 'fake', '_startedAt' => $now - 100, '_lastSeen' => $now - 50];
check(App::enforceSessionMaxAge($now) === false && ($_SESSION['accessToken'] ?? null) === 'fake', 'session: fresh session kept');
check(($_SESSION['_lastSeen'] ?? 0) === $now, 'session: last-seen updated');
$_SESSION = ['accessToken' => 'fake', '_startedAt' => $now - 7201, '_lastSeen' => $now - 10];
check(App::enforceSessionMaxAge($now) === true && !isset($_SESSION['accessToken']), 'session: absolute 2h limit logs out');
$_SESSION = ['accessToken' => 'fake', '_startedAt' => $now - 3600, '_lastSeen' => $now - 7300];
check(App::enforceSessionMaxAge($now) === true && !isset($_SESSION['accessToken']), 'session: 2h idle limit logs out');
$_SESSION = ['accessToken' => 'fake'];
check(App::enforceSessionMaxAge($now) === false && ($_SESSION['_startedAt'] ?? null) === $now, 'session: unstamped session gets a start time');

// ---------- Find a member search kept in session, not URL ----------
$_SESSION = [];
check(FindMemberController::currentSearch($now) === ['', 'any'], 'find: no search → empty');
$_SESSION[FindMemberController::SEARCH_KEY] = ['q' => ' LE65 ', 'type' => 'postcode', 'at' => $now - 60];
check(FindMemberController::currentSearch($now) === ['LE65', 'postcode'], 'find: search read from session');
$_SESSION[FindMemberController::SEARCH_KEY] = ['q' => 'LE65', 'type' => 'postcode', 'at' => $now - 7300];
check(FindMemberController::currentSearch($now) === ['', 'any'] && !isset($_SESSION[FindMemberController::SEARCH_KEY]), 'find: search dropped after 2h');
check(FindMemberController::normalise(['x'], 'bogus') === ['', 'any'], 'find: non-string q / bad type normalised');
check(mb_strlen(FindMemberController::normalise(str_repeat('a', 300), 'any')[0]) === 100, 'find: q clipped to 100 chars');
$_GET = ['q' => 'LE65'];
$_SESSION = [];
check(FindMemberController::currentSearch($now) === ['', 'any'], 'find: ?q= in the URL is ignored');
$tpl = (string) file_get_contents(dirname(__DIR__) . '/templates/find-member.twig');
check(str_contains($tpl, 'method="post"') && str_contains($tpl, '_csrf.twig') && str_contains($tpl, '/find-member/search/'), 'find: template posts with CSRF');
check(is_file(dirname(__DIR__) . '/deploy/route-stubs/find-member/search/index.php'), 'find: route stub exists');

// ---------- Debug dumps gone ----------
check(!is_file(dirname(__DIR__) . '/src/Osm/OsmDebug.php'), 'debug: OsmDebug removed');
$src = shell_exec('grep -rl "OsmDebug\|top-awards-dryrun\|writeDryRun" ' . escapeshellarg(dirname(__DIR__) . '/src')) ?? '';
check(trim((string) $src) === '', 'debug: no OsmDebug / dry-run references in src');

// ---------- deploy.sh backups skip .env ----------
$deploy = (string) file_get_contents(dirname(__DIR__) . '/deploy/deploy.sh');
check(str_contains($deploy, '--exclude=app/.env ') && str_contains($deploy, "--exclude='app/.env.bak*'"), 'deploy: predeploy tar excludes .env and .env.bak*');

// cleanup
foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmp);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
