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
check(OsmErrorLog::pruneOld($now) === 1 && is_file(OsmErrorLog::path()) && filesize(OsmErrorLog::path()) === 0, 'errorlog: all-old file emptied in place (0 bytes)');
@unlink(OsmErrorLog::path());
check(OsmErrorLog::pruneOld($now) === 0, 'errorlog: missing file is fine');
check(!str_contains($logText, 'Bad member') && !str_contains($logText, 'osm_message'), 'errorlog: OSM message text never stored');

// Legacy lines (written before this change) are scrubbed by bin/scrub-error-log.php
file_put_contents(OsmErrorLog::path(), implode("\n", [
    json_encode(['at' => gmdate('c', $now - 60), 'kind' => 'http', 'osm_message' => 'Jane Doe not found', 'scoutid' => '551122', 'osm_code' => 'Some text here']),
    json_encode(['at' => gmdate('c', $now - 50), 'kind' => 'write', 'detail' => 'response keys: ok=false, error=Jane Doe has no badge, status=403']),
    json_encode(['at' => gmdate('c', $now - 40), 'kind' => 'http', 'http_status' => 429, 'section_id' => '123', 'osm_code' => 'rate-limited']),
    'garbage line',
]) . "\n");
$changed = OsmErrorLog::scrubLegacyFields();
$logText = (string) file_get_contents(OsmErrorLog::path());
check($changed === 3, "errorlog scrub: 2 legacy lines changed + 1 unreadable dropped (changed $changed)");
check(!str_contains($logText, 'Jane') && !str_contains($logText, '551122') && !str_contains($logText, 'osm_message') && !str_contains($logText, 'Some text'), 'errorlog scrub: OSM text, scoutid gone');
check(str_contains($logText, 'error:text') && str_contains($logText, 'status=403') && str_contains($logText, '"osm_code":"rate-limited"') && str_contains($logText, '"section_id":"123"'), 'errorlog scrub: codes and section ids kept');
check(count(file(OsmErrorLog::path(), FILE_SKIP_EMPTY_LINES)) === 3, 'errorlog scrub: line count right');
check(OsmErrorLog::scrubLegacyFields() === 0, 'errorlog scrub: safe to re-run');
$recent = OsmErrorLog::recent(10);
check(count($recent) === 3 && !array_key_exists('osm_message', $recent[0]), 'errorlog: recent() never returns osm_message');
$cli = (string) shell_exec('OSMHELPER_ERRORLOG_DIR=' . escapeshellarg($tmp) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/scrub-error-log.php') . ' 2>&1');
check(!str_contains($cli, 'Jane') && preg_match('/^osm-errors.jsonl entries scrubbed: 0, older than 30 days removed: 0$/', trim($cli)) === 1, 'errorlog scrub CLI (temp dir) prints counts only');

// Concurrency: appends racing with prune/rotate rewrites must not lose or corrupt lines.
@unlink(OsmErrorLog::path());
file_put_contents(OsmErrorLog::path(), json_encode(['at' => gmdate('c', $now - 40 * 86400), 'old' => 1]) . "\n");
$worker = $tmp . '/worker.php';
file_put_contents($worker, '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
    . '\App\Osm\OsmErrorLog::setDirForTests(' . var_export($tmp, true) . ');'
    . 'for ($i = 0; $i < 40; $i++) { \App\Osm\OsmErrorLog::log(["method" => "GET", "endpoint" => "/x", "http_status" => 500, "kind" => "http", "section_id" => (string) $argv[1]]); \App\Osm\OsmErrorLog::pruneOld(); }');
$procs = [];
for ($w = 1; $w <= 4; $w++) {
    $procs[] = proc_open([PHP_BINARY, $worker, (string) $w], [], $pipes);
}
foreach ($procs as $p) { proc_close($p); }
$all = file(OsmErrorLog::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$valid = array_filter($all, static fn ($l) => is_array(json_decode($l, true)));
check(count($all) === 160 && count($valid) === 160, 'errorlog: 4 concurrent writers + pruning keep all 160 lines intact (got ' . count($all) . ')');
check(!str_contains(implode("\n", $all), '"old":1'), 'errorlog: old entry pruned during concurrent writes');
// Same race, but the file starts over the rotation thresholds (> 200 entries and > 32 KiB), so every
// append also runs the locked rotate. Expect exactly the newest 200: all 160 new lines + 40 seed lines.
$seed = '';
for ($i = 0; $i < 250; $i++) {
    $seed .= json_encode(['at' => gmdate('c', $now - 3600), 'kind' => 'seed', 'detail' => str_repeat('x', 150), 'n' => $i]) . "\n";
}
file_put_contents(OsmErrorLog::path(), $seed);
check(filesize(OsmErrorLog::path()) > 32768, 'errorlog rotate test: seed file is over the 32 KiB rotate check');
$procs = [];
for ($w = 1; $w <= 4; $w++) {
    $procs[] = proc_open([PHP_BINARY, $worker, (string) $w], [], $pipes);
}
foreach ($procs as $p) { proc_close($p); }
$all = file(OsmErrorLog::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$dec = array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), $all), 'is_array'));
$perWorker = [];
foreach ($dec as $d) { if (($d['kind'] ?? '') === 'http') { $perWorker[$d['section_id']] = ($perWorker[$d['section_id']] ?? 0) + 1; } }
ksort($perWorker);
$seedLeft = array_column(array_filter($dec, static fn ($d) => ($d['kind'] ?? '') === 'seed'), 'n');
check(count($all) === 200 && count($dec) === 200, 'errorlog: concurrent appends + rotation keep exactly 200 intact lines (got ' . count($all) . ')');
check($perWorker === ['1' => 40, '2' => 40, '3' => 40, '4' => 40], 'errorlog: rotation under concurrency loses none of the 160 new lines');
check($seedLeft === range(210, 249), 'errorlog: rotation drops the oldest lines first (seed 210..249 kept)');
@unlink($worker);
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


// ---------- ensure-token-key.php ----------
require_once dirname(__DIR__) . '/deploy/ensure-token-key.php';
$fakeKey = base64_encode(str_repeat("\x01", 32)); // valid-shaped fake key, never a real one
$K = 'OSMHELPER_TOKEN_KEY';
$cases = [
    // [env text, expected status, label]
    ["A=1\n", 'missing', 'no definition'],
    ["# $K=$fakeKey\n", 'missing', 'commented line is not a definition'],
    ["$K=$fakeKey\n", 'set', 'valid key'],
    ["$K=\"$fakeKey\"\n", 'set', 'quoted valid key'],
    ["$K=\n$K=$fakeKey\n", 'set', 'empty then valid: last definition wins'],
    ["$K=$fakeKey\n$K=\n", 'invalid', 'valid then empty: last (empty) wins'],
    ["$K=\n$K=abc\n", 'invalid', 'empty then abc: defined, abc not a key'],
    ["export $K=$fakeKey\n", 'set', 'export form'],
    ["export $K=\n", 'invalid', 'export empty'],
    ["  $K=$fakeKey\n", 'set', 'leading space'],
    ["  $K=\n", 'invalid', 'leading space empty'],
    ["$K = $fakeKey\n", 'set', 'KEY = value'],
    ["$K = abc\n", 'invalid', 'KEY = abc'],
    ["$K= # todo\n", 'invalid', '= # todo (comment only)'],
    ["$K=\" \"\n", 'invalid', '" " (quoted blank)'],
    ["$K=\"\"\n", 'invalid', '"" (quoted empty)'],
    ["$K=''\n", 'invalid', "'' (quoted empty)"],
    ["$K=\nB=2\n", 'invalid', 'empty does not borrow next line'],
    ["A=1\r\n$K=$fakeKey\r\nB=2\r\n", 'set', 'CRLF valid'],
    ["A=1\r\n$K=\r\nB=2\r\n", 'invalid', 'CRLF empty'],
];
foreach ($cases as [$env, $want, $label]) {
    $got = osmhelper_token_key_status($env);
    check($got === $want, "tokenkey: $label → $want" . ($got === $want ? '' : " (got $got)"));
}
check(osmhelper_with_token_key("A=1", $fakeKey) === "A=1\n$K=$fakeKey\n", 'tokenkey: appended with newline');
check(osmhelper_with_token_key("A=1\r\nB=2\r\n", $fakeKey) === "A=1\r\nB=2\r\n$K=$fakeKey\r\n", 'tokenkey: CRLF file keeps CRLF');
check(osmhelper_token_key_status(osmhelper_with_token_key("A=1\n", $fakeKey)) === 'set', 'tokenkey: set after append');
// CLI runs in a temp app dir: never prints the key, never appends next to an existing definition.
$envDir = $tmp . '/envtest';
mkdir($envDir, 0700);
$envFile = $envDir . '/.env';
$cmd = 'cd ' . escapeshellarg($envDir) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/deploy/ensure-token-key.php') . ' 2>&1; echo "rc=$?"';
file_put_contents($envFile, "SESSION_SECRET=x\nCLIENT_ID=y\n");
chmod($envFile, 0640);
$st0 = stat($envFile);
$o1 = trim((string) shell_exec($cmd));
$envAfter = (string) file_get_contents($envFile);
preg_match('/^OSMHELPER_TOKEN_KEY=(\S+)$/m', $envAfter, $km);
$newKey = $km[1] ?? '';
clearstatcache();
$st1 = stat($envFile);
check($o1 === "OSMHELPER_TOKEN_KEY set\nrc=0" && strlen((string) base64_decode($newKey, true)) === 32, 'tokenkey CLI: missing → 32-byte key appended');
check($newKey !== '' && !str_contains($o1, $newKey), 'tokenkey CLI: key not printed');
check(str_starts_with($envAfter, "SESSION_SECRET=x\nCLIENT_ID=y\n") && substr_count($envAfter, 'OSMHELPER_TOKEN_KEY=') === 1, 'tokenkey CLI: other lines kept, one key line');
check(($st1['mode'] & 07777) === 0640 && $st1['uid'] === $st0['uid'] && $st1['gid'] === $st0['gid'] && $st1['ino'] !== $st0['ino'], 'tokenkey CLI: temp+rename keeps owner, group, mode 0640');
check(glob($envDir . '/.env.tmp-*') === [], 'tokenkey CLI: no temp file left');
$o2 = trim((string) shell_exec($cmd));
check($o2 === "OSMHELPER_TOKEN_KEY already set\nrc=0" && (string) file_get_contents($envFile) === $envAfter, 'tokenkey CLI: second run changes nothing');
foreach (["$K=\n$K=abc\n", "export $K=\n", "  $K=\n", "$K = abc\n", "$K=$fakeKey\n$K=\n", "$K= # todo\n", "$K=\" \"\n", "A=1\r\n$K=\r\n"] as $bad) {
    file_put_contents($envFile, $bad);
    $ob = (string) shell_exec($cmd);
    check(str_contains($ob, 'rc=1') && str_contains($ob, 'Nothing was changed') && (string) file_get_contents($envFile) === $bad && !str_contains($ob, 'abc'), 'tokenkey CLI: defined-but-invalid exits 1, file untouched: ' . json_encode($bad === "$K=$fakeKey\n$K=\n" ? 'valid then empty' : str_replace($fakeKey, 'KEY', $bad)));
}
// Runbook php -r check: prints only set/missing
$runbook = (string) file_get_contents(dirname(__DIR__) . '/docs/DEPLOY-RUNBOOK.md');
preg_match("/^    (php -r 'require \"vendor\\/autoload.php\";.*)$/m", $runbook, $rm);
$rbCmd = $rm[1] ?? '';
check($rbCmd !== '' && str_contains($rbCmd, 'Dotenv\\Dotenv::parse'), 'runbook: php -r Dotenv::parse check present');
check(!str_contains($runbook, "grep -E '^OSMHELPER_TOKEN_KEY=' .env") && !str_contains($runbook, "'^OSMHELPER_TOKEN_KEY=.'"), 'runbook: old grep checks gone');
@symlink(dirname(__DIR__) . '/vendor', $envDir . '/vendor');
$rb = static function (string $env) use ($envFile, $envDir, $rbCmd): string {
    file_put_contents($envFile, $env);
    $rbBin = str_replace('php -r', escapeshellarg(PHP_BINARY) . ' -r', $rbCmd);
    return trim((string) shell_exec('cd ' . escapeshellarg($envDir) . ' && ' . $rbBin . ' 2>&1'));
};
check($rb("$K=$fakeKey\n") === 'set' && $rb("$K=\n$K=$fakeKey\n") === 'set' && $rb("export $K=$fakeKey\n") === 'set', 'runbook check: valid / last-wins / export → set');
check($rb("A=1\n") === 'missing' && $rb("$K=\n") === 'missing' && $rb("$K=$fakeKey\n$K=\n") === 'missing' && $rb("$K=\" \"\n") === 'missing' && $rb("$K = abc\n") === 'missing', 'runbook check: missing / empty / last empty / blank / abc → missing');
check($rb("A=\"unterminated\n$K=$fakeKey\n") === 'missing', 'runbook check: unparseable file → missing, no .env text printed');
check(str_contains($runbook, 'intentionally excluded from every backup') && str_contains($runbook, 'off the server'), 'runbook: .env/key excluded from backups, off-server copy');
@unlink($envDir . '/vendor');
foreach (glob($envDir . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
@rmdir($envDir);

// ---------- Production fails closed without a token key ----------
TokenCrypto::setKeyForTests('');
TokenCrypto::setProductionForTests(true);
check(TokenCrypto::writesBlocked() && TokenCrypto::problem() === 'token_key_missing', 'prod: no key → writes blocked, reason token_key_missing');
$threw = false;
try { TokenCrypto::encrypt('fake-prod-token'); } catch (\App\Store\TokenKeyUnavailable) { $threw = true; }
check($threw, 'prod: encrypt refuses (no plaintext save)');
check(TokenCrypto::encrypt(null) === null, 'prod: null still fine');
check(str_contains(phpLog(), 'token_write_refused_no_key'), 'prod: refusal logged as reason code');
$_ENV['OSMHELPER_DB_PATH'] = $tmp . '/prod.sqlite';
Db::reset();
$threw = false;
try { WordpressSiteKeyStore::upsertForUser('9100', '123', 'Beavers', 'fake-prod-acc', 'fake-prod-ref', time() + 3600); } catch (\App\Store\TokenKeyUnavailable) { $threw = true; }
$cnt = (int) Db::pdo()->query("SELECT COUNT(*) FROM wordpress_site_keys")->fetchColumn();
check($threw && $cnt === 0, 'prod: upsert saves nothing without a key');
$ins = Db::pdo()->prepare('INSERT INTO wordpress_site_keys (osm_user_id, site_key, section_id, section_name, access_token, refresh_token, token_expires_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
$ins->execute(['9101', 'sk-prod-legacy', '123', 'Beavers', 'legacy-prod-acc', null, time() + 3600, gmdate('c'), gmdate('c')]);
check((WordpressSiteKeyStore::findBySiteKey('sk-prod-legacy')['access_token'] ?? null) === 'legacy-prod-acc', 'prod: existing plaintext row still readable');
$id = (int) Db::pdo()->query("SELECT id FROM wordpress_site_keys WHERE osm_user_id='9101'")->fetchColumn();
$threw = false;
try { WordpressSiteKeyStore::updateTokens($id, 'fake-new-acc', null, null); } catch (\App\Store\TokenKeyUnavailable) { $threw = true; }
check($threw && Db::pdo()->query("SELECT access_token FROM wordpress_site_keys WHERE id=$id")->fetchColumn() === 'legacy-prod-acc', 'prod: token refresh not saved as plaintext');
TokenCrypto::setProductionForTests(false);
check(!TokenCrypto::writesBlocked() && TokenCrypto::encrypt('fake-dev') === 'fake-dev', 'dev: no key → still plaintext (development only)');
TokenCrypto::setProductionForTests(null);
$_ENV['APP_ENV'] = 'production';
check(\App\Config::isProduction() && TokenCrypto::writesBlocked(), 'prod: APP_ENV=production detected');
$_ENV['APP_ENV'] = 'development';
$_SERVER['HTTP_HOST'] = 'osmhelper.co.uk';
check(!\App\Config::isProduction(), 'prod: explicit APP_ENV wins over host');
unset($_ENV['APP_ENV']);
check(\App\Config::isProduction(), 'prod: live host counts as production when APP_ENV unset');
foreach (['dev', 'local', 'Development', ' test ', 'testing'] as $v) {
    $_ENV['APP_ENV'] = $v;
    check(!\App\Config::isProduction(), 'prod: APP_ENV=' . json_encode($v) . ' is not production');
}
foreach (['production', 'prod', 'staging', 'Production ', 'prodution'] as $v) {
    $_ENV['APP_ENV'] = $v;
    check(\App\Config::isProduction(), 'prod: APP_ENV=' . json_encode($v) . ' counts as production (fail closed)');
}
$_ENV['APP_ENV'] = '';
check(\App\Config::isProduction(), 'prod: empty APP_ENV falls back to the live host');
$hostWas = $_SERVER['HTTP_HOST'] ?? null;
$_SERVER['HTTP_HOST'] = 'localhost:8137';
check(!\App\Config::isProduction(), 'prod: empty APP_ENV on another host is not production');
if ($hostWas === null) { unset($_SERVER['HTTP_HOST']); } else { $_SERVER['HTTP_HOST'] = $hostWas; }
unset($_ENV['APP_ENV']);
unset($_SERVER['HTTP_HOST']);
TokenCrypto::setKeyForTests($key);
check(!TokenCrypto::writesBlocked() && TokenCrypto::problem() === null, 'prod: with key → not blocked');
Db::reset();
$wpTpl = (string) file_get_contents(dirname(__DIR__) . '/templates/wordpress-form.twig');
check(str_contains($wpTpl, 'tokenWritesBlocked') && str_contains($wpTpl, 'tokenKeyProblem'), 'prod: Joining form page shows the warning (admin sees reason)');
$wpCtl = (string) file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/WordpressFormController.php');
check(str_contains($wpCtl, 'catch (TokenKeyUnavailable)') && str_contains($wpCtl, 'Config::isAdmin($osmUserId) ? TokenCrypto::problem()'), 'prod: save shows a message; reason code for admins only');

// ---------- Nights away: member IDs kept out of URLs ----------
use App\Http\Controllers\NightsAwayController;
$_SESSION = [];
NightsAwayController::storeSelection(['551122', '551133']);
check(NightsAwayController::selectedScoutIds() === ['551122', '551133'], 'nights: selection held in session');
check(NightsAwayController::idsFromQuery(['scoutid' => '551122']) === ['551122'] && NightsAwayController::idsFromQuery(['scoutids' => '1,2,x,2']) === ['1', '2'], 'nights: legacy ?scoutid(s)= parsed (numbers only)');
NightsAwayController::storeSelection([]);
check(NightsAwayController::selectedScoutIds() === [], 'nights: empty selection clears');
foreach (['find-member.twig', 'members.twig', 'nights-away.twig'] as $t) {
    $tt = (string) file_get_contents(dirname(__DIR__) . '/templates/' . $t);
    check(!preg_match('/nights-away\/\?scoutids?=/', $tt), "nights: no ?scoutid links in $t");
}
foreach (['find-member.twig', 'members.twig'] as $t) {
    $tt = (string) file_get_contents(dirname(__DIR__) . '/templates/' . $t);
    check(str_contains($tt, 'action="/nights-away/select/"') && str_contains($tt, 'name="scoutid"'), "nights: $t posts to /nights-away/select/");
}
$naCtl = (string) file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/NightsAwayController.php');
check(!str_contains($naCtl, "'?scoutids='") && str_contains($naCtl, "header('Location: /nights-away/', true, 303)"), 'nights: select redirects to a clean URL');
check(is_file(dirname(__DIR__) . '/deploy/route-stubs/nights-away/select/index.php'), 'nights: select route stub exists');

// ---------- Error log callers + copy ----------
$srcAll = (string) shell_exec('grep -rn "osm_message" ' . escapeshellarg(dirname(__DIR__) . '/src'));
check(!preg_match("/'osm_message'\s*=>/", $srcAll), 'errorlog: no caller passes osm_message');
$wls = (string) file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/WaitingListSubmitController.php');
check(!str_contains($wls, '$e->getMessage()') || str_contains($wls, 'LogSafe::reasonCode($e->getMessage())'), 'intake: error_log keeps reason code only');
check(!str_contains((string) file_get_contents(dirname(__DIR__) . '/templates/equipment-move-result.twig'), 'osm-debug'), 'equipment: no osm-debug mention');
$copy = (string) file_get_contents(dirname(__DIR__) . '/templates/home.twig') . file_get_contents(dirname(__DIR__) . '/templates/help.twig');
check(substr_count($copy, 'Every session ends 2 hours after you sign in, or when you sign out') >= 2, 'copy: 2-hour wording on home + help');
check(substr_count($copy, 'OSM user number so we know whose settings are whose') >= 2, 'copy: OSM user number mentioned on home + help');
check(substr_count($copy, 'cleaned up shortly after') >= 2 && str_contains($copy, 'never OSM’s message text'), 'copy: file cleanup + no OSM message text');
$deploy2 = (string) file_get_contents(dirname(__DIR__) . '/deploy/deploy.sh');
$posKey = strpos($deploy2, 'ensure-token-key.php');
$posEnc = strpos($deploy2, '(umask 007; php bin/encrypt-site-tokens.php)');
check($posKey !== false && $posEnc !== false && $posEnc > $posKey && str_contains($deploy2, '(umask 007; php bin/scrub-error-log.php)'), 'deploy: encrypt + scrub migrations run after ensure-token-key, umask 007');
$osmApi = (string) file_get_contents(dirname(__DIR__) . '/src/Osm/OsmApi.php');
check(!preg_match('/error_log\([^;]*\$deprecated/', $osmApi) && !preg_match('/RuntimeException\([^;]*\$deprecated/', $osmApi) && str_contains($osmApi, "'osm_deprecated'"), 'OsmApi: X-Deprecated logged as a code only, header text never logged or shown');
$readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
check(!str_contains(strtolower($readme), 'atomically') && str_contains($readme, 'exclusive file lock'), 'README: says locked, not atomic');
$wpT = (string) file_get_contents(dirname(__DIR__) . '/templates/wordpress-form.twig');
$rbT = (string) file_get_contents(dirname(__DIR__) . '/docs/DEPLOY-RUNBOOK.md');
check(!str_contains($wpT, 'keeps working.') && str_contains($wpT, 'may stop working once its saved OSM access needs renewing') && str_contains($rbT, 'may stop once its saved OSM access needs') && str_contains($rbT, 'so the form may stop working'), 'WordPress alert and runbook agree: existing form may stop at token renewal');
$posPreKey = strpos($deploy2, 'php /home/osmhelper/tmp/ensure-token-key.php');
$posExtract = strpos($deploy2, 'tar -xzf tmp/osmhelper-app.tgz');
check($posPreKey !== false && $posExtract !== false && $posPreKey < $posExtract, 'deploy: ensure-token-key runs against live .env before the code is unpacked');
check($posExtract < $posEnc && $posExtract < (int) strpos($deploy2, 'php bin/scrub-error-log.php'), 'deploy: encrypt + scrub run after extraction');
check(str_contains($deploy2, '|| rc=$?; fi; rm -f /home/osmhelper/tmp/ensure-token-key.php; exit $rc'), 'deploy: pre-extraction key failure stops the deploy, temp script removed');

// cleanup
foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmp);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
