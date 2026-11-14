<?php
declare(strict_types=1);
/**
 * Offline tests for Gift Aid. No network / no OSM (Guzzle MockHandler, or a pre-seeded session).
 * Run: php tests/gift-aid-test.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/isolate-storage.php';

use App\Config;
use App\GiftAid\GiftAidGrid;
use App\GiftAid\GiftAidLoader;
use App\Http\Controllers\GiftAidController;
use App\Http\Csrf;
use App\Osm\LoginSections;
use App\Osm\OsmApi;
use App\Osm\OsmErrorLog;
use App\Osm\OsmRateGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];
GiftAidLoader::$pauseOverrideUs = 0;
$logDir = sys_get_temp_dir() . '/osmh-ga-log-' . bin2hex(random_bytes(3));
mkdir($logDir, 0700, true);
OsmErrorLog::setDirForTests($logDir);
$phpLog = $logDir . '/php-error.log';
ini_set('log_errors', '1');
ini_set('error_log', $phpLog);

$failed = 0;
$passed = 0;
function check(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) { $passed++; echo "OK  $msg\n"; } else { $failed++; echo "FAIL $msg\n"; }
}
/** @param list<Response> $responses @param array<int, mixed> $history */
function mockApi(array $responses, ?array &$history = null): OsmApi
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    OsmRateGuard::forUser(null)->reset();
    return new OsmApi(new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://osm.test/']));
}
function grid(string $name): array
{
    $raw = file_get_contents(dirname(__DIR__) . '/tests/fixtures/' . $name);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('bad fixture ' . $name);
    }
    return $data;
}
/** @param list<array<string,mixed>> $rows */
function tag(array $rows, string $section, string $id, string $group = '4th Ashby'): array
{
    foreach ($rows as &$row) {
        $row['group'] = $group;
        $row['section'] = $section;
        $row['sectionId'] = $id;
    }
    return $rows;
}
function byScout(array $register): array
{
    $out = [];
    foreach ($register as $row) {
        $out[$row['scoutid']] = $row;
    }
    return $out;
}
/** @param list<array<string,mixed>> $sections */
function sectionRow(string $id, string $name, string $type, array $terms, string $current = '501'): array
{
    return [
        'section_id' => $id,
        'section_name' => $name,
        'section_type' => $type,
        'group_name' => '4th Ashby',
        'current_term_id' => $current,
        'terms' => $terms,
    ];
}

$hdr = ['X-RateLimit-Limit' => '1000', 'X-RateLimit-Remaining' => '900', 'X-RateLimit-Reset' => '3000'];
$from = '2025-10-06';
$to = '2026-01-12';
$claim = '4th Ashby Scout Group';
$termA = grid('giftaid-grid-term-a.json');
$termB = grid('giftaid-grid-term-b.json');

// Trimming, structure dates, blanks and zeros.
$parsedA = GiftAidGrid::parseGrid($termA);
$a = [];
foreach ($parsedA as $row) {
    $a[$row['scoutid']] = $row;
}
check(count($parsedA) === 18 && isset($a['9001'], $a['9016']), 'parses every item and trims scout ids');
check($a['9001']['youthFirst'] === 'Alex' && $a['9001']['youthLast'] === 'River' && $a['9001']['title'] === 'Mrs', 'trims youth names and title');
check($a['9001']['parentname'] === 'Mrs Jane River' && $a['9001']['address'] === '12' && $a['9001']['postcode'] === 'le65 1ab', 'trims and collapses the declarant fields');
check($a['9001']['status'] === 'entered' && $a['9001']['charity'] === '4th Ashby Scout Group' && $a['9001']['lastupdated'] === '2025-09-01', 'trims status, charity and declaration date');
check($a['9001']['donations']['2025-10-05']['pence'] === 100 && $a['9001']['donations']['2025-10-06']['pence'] === 1050
    && $a['9001']['donations']['2025-10-06']['charity'] === '4th Ashby Scout Group' && count($a['9001']['donations']) === 2, 'structure dates only; trailing-space field, "" and 0 and total and stray keys ignored');
check($a['9015']['donations'] === [], '0, 0.00 and "" are not donations');
check($a['9016']['youthFirst'] === 'Wynn' && $a['9016']['donations']['2025-10-06']['pence'] === 350, 'trims a spaced amount to pence');
check(GiftAidGrid::parseGrid(['items' => [], 'structure' => []]) === [], 'items:[] is an empty grid');
check(GiftAidGrid::badResponseReason(['items' => []]) === null, 'items array is usable');
check(GiftAidGrid::badResponseReason([], 'empty-list') === 'no gift aid list', 'literal [] is not a grid');
check(GiftAidGrid::badResponseReason([], 'empty') === 'empty reply' && GiftAidGrid::badResponseReason([], 'empty-object') === 'empty object', 'empty body and {} are unusable');
check(GiftAidGrid::badResponseReason(['identifier' => 'scoutid']) === 'no gift aid list', 'no items array is unusable');
check(GiftAidGrid::badResponseReason(['error' => 'Secret Jane LE65 9ZZ', 'items' => []]) !== null, 'an error key is unusable even with items');
check(GiftAidGrid::reasonCode('error: Secret Jane') === 'osm-error' && GiftAidGrid::reasonCode('no gift aid list') === 'no-gift-aid-list', 'reason codes drop OSM text');

// Name split, hyphens, postcode, charity.
$split = GiftAidGrid::splitName('Mrs', 'Mrs Jane River');
check($split['first'] === 'Jane' && $split['last'] === 'River' && $split['flags'] === [] && $split['hmrcTitle'] === 'Mrs', 'a matching leading title is not part of the name');
$multi = GiftAidGrid::splitName('Mrs', 'Fleur Catherine Wybraniec');
check($multi['first'] === 'Fleur' && $multi['last'] === 'Catherine Wybraniec' && $multi['flags'] === ['name-multi-part'], 'first token is the HMRC first name; the rest is the last name');
$badTitle = GiftAidGrid::splitName('Mr', 'Mrs Jo Smith');
check($badTitle['first'] === 'Jo' && $badTitle['last'] === 'Smith' && $badTitle['flags'] === ['title-name-mismatch'] && $badTitle['hmrcTitle'] === 'Mr', 'a conflicting title is stripped from the name, and the title field is kept');
$blankTitle = GiftAidGrid::splitName('', 'Mr Jo Smith');
check($blankTitle['first'] === 'Jo' && $blankTitle['last'] === 'Smith' && $blankTitle['flags'] === [] && $blankTitle['hmrcTitle'] === 'Mr', 'a blank title takes the title word from the name');
$dotted = GiftAidGrid::splitName('mrs.', 'MRS. Jane River');
check($dotted['first'] === 'Jane' && $dotted['flags'] === [] && $dotted['hmrcTitle'] === 'mrs.', 'title matching ignores case and a trailing dot');
$one = GiftAidGrid::splitName('Ms', 'Cher');
check($one['first'] === '' && $one['last'] === 'Cher' && $one['flags'] === ['name-unsplit'], 'a single token is the last name and is flagged');
$empty = GiftAidGrid::splitName('Mrs', '   ');
check($empty['first'] === '' && $empty['last'] === '' && $empty['flags'] === ['name-unsplit'], 'an empty name is flagged name-unsplit');
check(GiftAidGrid::normalisePostcode(' le65 1ab ') === 'LE65 1AB', 'postcode uppercased with a space');
check(GiftAidGrid::normalisePostcode('LE651CD') === 'LE65 1CD' && GiftAidGrid::normalisePostcode('m1 1ae') === 'M1 1AE', 'compact postcodes gain one space');
check(GiftAidGrid::charityKind('  4TH ashby scout group ', $claim) === 'ok', 'charity match ignores case and surrounding space');
check(GiftAidGrid::charityKind('   ', $claim) === 'blank' && GiftAidGrid::charityKind('Ashby ESU', $claim) === 'mismatch', 'blank and other charities are not the claim charity');
check(GiftAidGrid::claimCharity() === '4th Ashby Scout Group', 'the claim charity is locked to 4th Ashby Scout Group');
check(GiftAidGrid::ukPostcode('NOT A CODE') === null && GiftAidGrid::ukPostcode(' le65 1ab ') === 'LE65 1AB', 'only a UK postcode parses');
[$tyFrom, $tyTo] = GiftAidGrid::taxYearRange(new DateTimeImmutable('2026-11-14', new DateTimeZone('Europe/London')));
check($tyFrom === '2026-04-06' && $tyTo === '2027-04-05', 'tax year on 14 Nov 2026 is 6 April 2026 to 5 April 2027');
[$tyFrom, $tyTo] = GiftAidGrid::taxYearRange(new DateTimeImmutable('2026-04-05', new DateTimeZone('Europe/London')));
check($tyFrom === '2025-04-06' && $tyTo === '2026-04-05', '5 April still belongs to the previous tax year');
[$tyFrom, $tyTo] = GiftAidGrid::taxYearRange(new DateTimeImmutable('2026-04-06', new DateTimeZone('Europe/London')));
check($tyFrom === '2026-04-06' && $tyTo === '2027-04-05', '6 April starts the new tax year');
check(GiftAidGrid::hmrcDate('2026-04-06') === '06/04/26' && GiftAidGrid::ukDate('2026-04-06') === '06/04/2026', 'HMRC date is DD/MM/YY and the register date is dd/mm/yyyy');
check(GiftAidGrid::money(1050) === '10.50' && GiftAidGrid::money(1250) === '12.50', 'amounts are 2dp with no pound sign');

// Multi-term merge, inclusive range, charity filter, HMRC rows.
$people = tag(GiftAidGrid::mergePeople(array_merge(GiftAidGrid::parseGrid($termA), GiftAidGrid::parseGrid($termB))), 'Cubs', '10');
$report = GiftAidGrid::report($people, $from, $to, $claim);
$reg = byScout($report['register']);
check(count($report['register']) === 19, 'one register row per youth after the merge, including the spring-only youth');
check($reg['9001']['status'] === 'entered' && $reg['9001']['declarationDate'] === '01/02/2026' && $reg['9001']['donations'] === '15.00', 'later entered declaration wins and its amount overlays the same date');
check($reg['9001']['declarantFirst'] === 'Jane' && $reg['9001']['declarantLast'] === 'River' && $reg['9001']['flags'] === '', 'matching title is split and not flagged');
check($reg['9002']['status'] === 'entered' && $reg['9002']['donations'] === '3.50' && $reg['9002']['declarationDate'] === '01/02/2026', 'entered beats an earlier no_record');
check($reg['9003']['status'] === 'entered' && $reg['9003']['declarationDate'] === '01/01/2020' && $reg['9003']['house'] === '4' && $reg['9003']['donations'] === '8.00', 'entered beats a later declined copy, whose amounts are dropped');
check($reg['9004']['flags'] === 'charity-blank' && $reg['9005']['flags'] === 'charity-mismatch', 'blank and other charities are flagged');
check($reg['9006']['flags'] === 'missing-postcode' && $reg['9007']['flags'] === 'missing-address', 'missing postcode and house are flagged');
check($reg['9008']['flags'] === 'title-name-mismatch' && $reg['9008']['declarantFirst'] === 'Pat' && $reg['9008']['declarantLast'] === 'Example' && $reg['9008']['parentname'] === 'Mrs Pat Example' && $reg['9008']['title'] === 'Mr', 'a conflicting title is flagged, stripped from the split, and the raw name is kept');
check($reg['9009']['flags'] === 'name-unsplit' && $reg['9009']['declarantLast'] === 'Cher' && $reg['9010']['flags'] === 'name-unsplit', 'single-token and empty names are flagged');
check($reg['9011']['declarantLast'] === 'Shaw-Brown' && $reg['9012']['status'] === 'declined' && $reg['9012']['donations'] === '15.00', 'register keeps the hyphen and declined donations');
check($reg['9013']['flags'] === 'title-too-long' && $reg['9015']['donations'] === '0.00' && $reg['9015']['flags'] === '', 'long title flagged; zero gifts stay on the register');
check($reg['9016']['postcode'] === 'M1 1AE' && $reg['9016']['charity'] === '4TH ASHBY SCOUT GROUP' && $reg['9016']['donations'] === '3.50', 'postcode normalised; charity text kept for the register');
check($reg['9017']['donations'] === '0.00' && $reg['9018']['flags'] === 'missing-declaration-date', 'a gift after the range is excluded; a blank declaration date is flagged');
$hmrcNames = array_map(static fn (array $r): string => $r['last'] . '|' . $r['date'] . '|' . $r['amount'], $report['hmrc']);
check($hmrcNames === [
    'Long|06/10/25|1.00',
    'River|06/10/25|11.00',
    'Shaw Brown|06/10/25|12.50',
    'Space|06/10/25|3.50',
    'Undated|06/10/25|1.50',
    'Pines|03/11/25|5.00',
    'Pines|12/01/26|3.00',
    'Quill|12/01/26|3.50',
    'River|12/01/26|4.00',
], 'HMRC rows: ' . implode(', ', $hmrcNames));
check($report['hmrc'][0]['title'] === 'Reve' && $report['hmrc'][2]['last'] === 'Shaw Brown' && $report['hmrc'][2]['postcode'] === 'LE65 1CD', 'title clipped to 4, hyphen becomes a space, postcode normalised');
check($report['hmrc'][0]['item'] === '' && $report['hmrc'][8]['item'] === '', 'the Item column is left blank for HMRC to number');
$hmrcBlob = GiftAidGrid::hmrcCsv($report['hmrc']);
check(!str_contains($hmrcBlob, 'Alex') && !str_contains($hmrcBlob, 'Ashby ESU') && !str_contains($hmrcBlob, 'Dee') && !str_contains($hmrcBlob, '99.00') && !str_contains($hmrcBlob, '15.00') && !str_contains($hmrcBlob, '6.50') && !str_contains($hmrcBlob, 'Example') && !str_contains($hmrcBlob, '=SUM'), 'HMRC file omits youth names, other charities, declined rows, title clashes and formula-like names');
check(!str_contains($hmrcBlob, '02/02/26') && !str_contains($hmrcBlob, '20.00') && !str_contains($hmrcBlob, ',8.00') && !str_contains($hmrcBlob, '05/10/25'), 'dates outside the inclusive range are omitted');
$edge = GiftAidGrid::report($people, '2025-10-05', '2025-10-05', $claim);
check(array_column($edge['hmrc'], 'amount') === ['1.00'] && $edge['hmrc'][0]['date'] === '05/10/25', 'the range includes the start day and nothing after it');
$end = GiftAidGrid::report($people, '2026-01-12', '2026-01-12', $claim);
check(array_column($end['hmrc'], 'date') === ['12/01/26', '12/01/26', '12/01/26'], 'the range includes the end day');
$feb = GiftAidGrid::report($people, '2026-02-02', '2026-02-02', $claim);
check(array_column($feb['hmrc'], 'amount') === ['8.00', '20.00'], 'February gifts are included only on that day, and the declined copy is not');
$preview = $report['preview'];
check($preview['entered'] === 17 && $preview['declined'] === 1 && $preview['noRecord'] === 1 && $preview['hmrcRows'] === 9, 'preview counts entered, declined, no_record and HMRC rows: ' . json_encode([$preview['entered'], $preview['declined'], $preview['noRecord'], $preview['hmrcRows']]));
check($preview['charityBlank'] === 1 && $preview['charityMismatch'] === 1 && $preview['titleMismatch'] === 1 && $preview['missingPostcode'] === 1 && $preview['missingAddress'] === 1, 'preview problem counts');
check($preview['warnSplit'] === false, '9 rows do not ask for a split claim');
$charityNames = array_column($preview['charities'], 'name');
check(in_array('(blank)', $charityNames, true) && in_array('Ashby ESU', $charityNames, true) && in_array('4th Ashby Scout Group', $charityNames, true), 'charity breakdown lists blank, the other charity and the claim charity');
check($preview['problems'] !== [] && !isset($preview['problems'][0]['postcode']) && !isset($preview['problems'][0]['house']), 'problem rows carry flags, not addresses');
// Same scout in another section stays a second declaration.
$other = tag(GiftAidGrid::parseGrid($termA), 'Scouts', '20');
$other = array_values(array_filter($other, static fn (array $r): bool => $r['scoutid'] === '9004'));
$two = GiftAidGrid::report(array_merge($people, $other), $from, $to, $claim);
check(count(array_filter($two['register'], static fn (array $r): bool => $r['scoutid'] === '9004')) === 2, 'dedupe is per section, not across sections');

// Exact headers, BOM, DD/MM/YY, 2dp, csvSafe.
$hmrc = GiftAidGrid::hmrcCsv($report['hmrc']);
$hmrcHeader = "\xEF\xBB\xBF" . 'Item,Title,"First name or initial","Last name","House name or number",Postcode,"Aggregated donations","Sponsored event","Donation date",Amount' . "\n";
check(str_starts_with($hmrc, $hmrcHeader), 'HMRC header with UTF-8 BOM');
check(str_contains($hmrc, ",Mrs,Jane,River,12,\"LE65 1AB\",,,06/10/25,11.00\n"), 'HMRC row: blank Item, DD/MM/YY, 2dp, blank aggregated and sponsored columns');
check(!str_contains($hmrc, "'="), 'the HMRC file does not prefix a quote onto a formula-like cell');
check(str_contains($hmrc, ',Mrs,Rita,"Shaw Brown",3,"LE65 1CD",,,06/10/25,12.50' . "\n"), 'hyphenated last name and compact postcode in the HMRC file');
$regCsv = GiftAidGrid::declarationsCsv($report['register']);
$regHeader = "\xEF\xBB\xBF" . 'Group,Section,"Youth first name","Youth last name",Status,"Declaration date (dd/mm/yyyy)","Declarant title","Declarant name as held in OSM","Declarant first name","Declarant last name","House name or number",Postcode,"Charity name","Donations in range (2dp)",Flags' . "\n";
check(str_starts_with($regCsv, $regHeader), 'register header with UTF-8 BOM');
check(str_contains($regCsv, '"4th Ashby",Cubs,Alex,River,entered,01/02/2026,Mrs,"Mrs Jane River",Jane,River,12,"LE65 1AB","4th Ashby Scout Group",15.00,' . "\n"), 'register row uses dd/mm/yyyy and 2dp');
check(str_contains($regCsv, ",'=Alex,Equals,"), 'csvSafe guards a youth name that starts with =');
check(str_contains($regCsv, 'charity-blank') && str_contains($regCsv, 'charity-mismatch') && str_contains($regCsv, 'name-unsplit') && str_contains($regCsv, 'title-name-mismatch') && str_contains($regCsv, ",'=SUM(A1),"), 'register keeps csvSafe and the title-mismatch flag');
foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", ' =1+1', "  @x", "\u{00A0}=1", "\n-2", "\r=1"] as $v) {
    check(GiftAidGrid::csvSafe($v) === "'" . $v, 'csvSafe guards ' . json_encode($v));
}
check(GiftAidGrid::csvSafe('Camp - 2025') === 'Camp - 2025' && GiftAidGrid::csvSafe('') === '', 'csvSafe leaves ordinary text');
check(GiftAidGrid::csvFilename('hmrc', '2026-04-06', '2027-04-05') === 'gift-aid-hmrc-20260406-to-20270405.csv', 'HMRC filename is dates only');
check(GiftAidGrid::csvFilename('declarations', '2026-04-06', '2027-04-05') === 'gift-aid-declarations-20260406-to-20270405.csv', 'register filename is dates only');
check(!str_contains(GiftAidGrid::csvFilename('hmrc', '2026-04-06', '2027-04-05'), 'River'), 'filename has no personal data');

// More than 1,000 HMRC rows warns.
$manyDon = [];
$day = new DateTimeImmutable('2020-01-01');
for ($i = 0; $i < 1001; $i++) {
    $manyDon[$day->format('Y-m-d')] = ['pence' => 100, 'charity' => $claim];
    $day = $day->modify('+1 day');
}
$many = GiftAidGrid::report([[
    'group' => '4th Ashby', 'section' => 'Cubs', 'sectionId' => '10', 'scoutid' => '1',
    'youthFirst' => 'Alex', 'youthLast' => 'River', 'title' => 'Mrs', 'parentname' => 'Jane River',
    'address' => '12', 'postcode' => 'LE65 1AB', 'lastupdated' => '2020-01-01', 'status' => 'entered',
    'charity' => $claim, 'donations' => $manyDon,
]], '2020-01-01', '2025-01-01', $claim);
check($many['preview']['hmrcRows'] === 1001 && $many['preview']['warnSplit'] === true && GiftAidGrid::warnSplit(1000) === false, 'more than 1,000 HMRC rows warns; 1,000 does not');

// Plans: overlapping terms, a gap, a missing date, and an old session.
$terms = [
    ['id' => '501', 'start' => '2025-09-01', 'end' => '2025-12-31'],
    ['id' => '502', 'start' => '2026-01-01', 'end' => '2026-04-30'],
    ['id' => '500', 'start' => '2025-01-01', 'end' => '2025-04-30'],
];
$cubs = sectionRow('10', 'Cubs', 'cubs', $terms);
check(GiftAidLoader::plan($cubs, $from, $to)['termIds'] === ['501', '502'], 'only terms that overlap the inclusive range are requested');
check(GiftAidLoader::plan($cubs, '2026-02-01', '2026-02-28')['termIds'] === ['502'], 'a range inside one term makes one request');
check(GiftAidLoader::plan($cubs, '2024-01-01', '2024-02-01') === ['termIds' => [], 'fallback' => false], 'no overlapping term means no request and no current-term fallback');
$undated = sectionRow('10', 'Cubs', 'cubs', [['id' => '777', 'start' => null, 'end' => null]]);
check(GiftAidLoader::plan($undated, $from, $to)['termIds'] === ['777'], 'a term with no dates is fetched, because its grid might hold the range');
$old = ['section_id' => '10', 'section_name' => 'Cubs', 'section_type' => 'cubs', 'group_name' => '4th Ashby', 'current_term_id' => 424];
check(GiftAidLoader::plan($old, $from, $to) === ['termIds' => ['424'], 'fallback' => true], 'a session without terms uses current_term_id');
check(GiftAidLoader::requestCount([$cubs, sectionRow('11', 'Scouts', 'scouts', $terms, '501')], $from, $to) === 4, 'request count is one call per overlapping term');

// Load: two terms, the real query, no giftaid.php. Pause is overridden to 0 except one test below.
$api = mockApi([
    new Response(200, $hdr, json_encode($termA)),
    new Response(200, $hdr, json_encode($termB)),
], $h);
$loaded = GiftAidLoader::load($api, 'tok', [$cubs], $from, $to);
check($loaded['stopReason'] === null && $loaded['calls'] === 2 && count($h) === 2, 'one getGrid per overlapping term');
$u0 = (string) $h[0]['request']->getUri();
$u1 = (string) $h[1]['request']->getUri();
check(str_contains($u0, '/ext/finances/giftaid/?action=getGrid&sectionid=10&termid=501&section=cubs&no-total=1'), 'call 1: getGrid, section type, no-total: ' . $u0);
check(str_contains($u1, 'termid=502&section=cubs') && !str_contains($u0 . $u1, 'giftaid.php'), 'call 2 uses the next term and never giftaid.php');
check(count($loaded['completed'][0]['declarations']) === 19, 'loader merges both terms');
$by = [];
foreach ($loaded['completed'][0]['declarations'] as $row) {
    $by[$row['scoutid']] = $row;
}
check($by['9001']['donations']['2025-10-06']['pence'] === 1100 && $by['9003']['status'] === 'entered' && !isset($by['9003']['donations']['2026-02-02']), 'loader merge matches the pure rules');

// Bad 200 does not replace a good copy. Personal text stays out of the log and the message.
GiftAidLoader::store($loaded['completed'][0], 1_800_000_000);
$secret = 'Jane Secret LE65 9ZZ scout 4242';
$api = mockApi([new Response(200, $hdr, json_encode(['error' => $secret, 'items' => []]))], $h);
$bad = GiftAidLoader::load($api, 'tok', [$cubs], $from, $to);
$kept = GiftAidLoader::cached('10', 1_800_000_000);
check($bad['completed'] === [] && $bad['calls'] === 1 && count($h) === 1, 'bad 200 stops after one call and returns nothing to store');
check(is_array($kept) && count($kept['declarations']) === 19, 'bad 200 does not wipe the stored copy');
check(str_contains((string) $bad['stopReason'], 'osm-error') && !str_contains((string) $bad['stopReason'], 'Jane') && !str_contains((string) $bad['stopReason'], '4242') && !str_contains((string) $bad['stopReason'], 'LE65'), 'the message shown to the user has a reason code only');
$logged = (string) file_get_contents($phpLog);
$lines = file($logDir . '/osm-errors.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
$last = json_decode((string) end($lines), true);
check(str_contains($logged, 'section 10') && str_contains($logged, 'osm-error') && !str_contains($logged, 'Jane') && !str_contains($logged, 'LE65') && !str_contains($logged, '4242'), 'error_log: reason code and section id only');
check(is_array($last) && $last['kind'] === 'bad-200' && $last['osm_code'] === 'osm-error' && $last['section_id'] === '10' && !isset($last['osm_message']) && !str_contains((string) json_encode($last), 'Jane'), 'OSM error log: code and section only');

// Empty body does not wipe either.
$api = mockApi([new Response(200, $hdr, '')], $h);
$empty = GiftAidLoader::load($api, 'tok', [$cubs], $from, $to);
check($empty['completed'] === [] && str_contains((string) $empty['stopReason'], 'empty-reply') && GiftAidLoader::cached('10', 1_800_000_000) !== null, 'empty 200 keeps the earlier copy');

// Permission: 200 access refusal, 403 sticky, 401 not sticky. A good copy stays.
unset($_SESSION[GiftAidLoader::SCOPE_DENIED_KEY]);
$api = mockApi([new Response(200, $hdr, json_encode(['error' => 'You do not have access to this resource.'])), new Response(200, $hdr, json_encode($termB))], $h);
$denied = GiftAidLoader::load($api, 'tok', [$cubs], $from, $to);
check($denied['scopeDenied'] === true && $denied['stopReason'] === GiftAidLoader::SCOPE_MESSAGE && count($h) === 1, '200 access refusal: finance message, one call, no retry');
check(!empty($_SESSION[GiftAidLoader::SCOPE_DENIED_KEY]) && GiftAidLoader::scopeHint() === GiftAidLoader::SCOPE_MESSAGE, '200 refusal is remembered for the session');
check(GiftAidLoader::cached('10', 1_800_000_000) !== null, 'access refusal keeps the earlier copy');
unset($_SESSION[GiftAidLoader::SCOPE_DENIED_KEY]);
$api = mockApi([new Response(403, $hdr, json_encode(['error' => $secret]))], $h);
$http403 = GiftAidLoader::load($api, 'tok', [$cubs], $from, $to);
check($http403['scopeDenied'] === true && !empty($_SESSION[GiftAidLoader::SCOPE_DENIED_KEY]) && !str_contains((string) $http403['stopReason'], 'Jane'), 'HTTP 403: finance message, sticky, no personal text');
unset($_SESSION[GiftAidLoader::SCOPE_DENIED_KEY]);
$api = mockApi([new Response(401, $hdr, '{"error":"Unauthorised"}')], $h);
$http401 = GiftAidLoader::load($api, 'tok', [$cubs], $from, $to);
check($http401['scopeDenied'] === true && empty($_SESSION[GiftAidLoader::SCOPE_DENIED_KEY]), 'HTTP 401: finance message, not sticky');

// 429, block, low allowance.
unset($_SESSION[GiftAidLoader::REFRESH_KEY]);
$api = mockApi([new Response(429, ['Retry-After' => '120'] + $hdr, '{"error":"Too many"}'), new Response(200, $hdr, json_encode($termB))], $h);
$limited = GiftAidLoader::load($api, 'tok', [$cubs], $from, $to);
check($limited['calls'] === 1 && count($h) === 1 && str_contains((string) $limited['stopReason'], 'about 2 minutes') && $limited['completed'] === [], '429 stops, no retry, nothing stored for the half-loaded section');
OsmRateGuard::clearAppBlock();
$api = mockApi([new Response(200, ['X-Blocked' => 'true'] + $hdr, json_encode($termA))], $h);
$blocked = GiftAidLoader::load($api, 'tok', [$cubs], $from, $to);
check($blocked['calls'] === 1 && str_contains((string) $blocked['stopReason'], 'blocked'), 'X-Blocked stops at once');
OsmRateGuard::clearAppBlock();
$_SESSION['osmRateLimit'] = ['limit' => 1000, 'remaining' => 60, 'resetInSec' => 600, 'lastUpdatedMs' => (int) round(microtime(true) * 1000)];
$api = mockApi([new Response(200, $hdr, json_encode($termA))], $h);
$low = GiftAidLoader::load($api, 'tok', [$cubs], $from, $to);
check($low['calls'] === 0 && $h === [] && str_contains((string) $low['stopReason'], 'allowance'), 'remaining <= 60: no OSM call');
unset($_SESSION['osmRateLimit']);
$lowHdr = ['X-RateLimit-Limit' => '1000', 'X-RateLimit-Remaining' => '55', 'X-RateLimit-Reset' => '3000'];
$api = mockApi([new Response(200, $lowHdr, json_encode($termA)), new Response(200, $hdr, json_encode($termB))], $h);
$drop = GiftAidLoader::load($api, 'tok', [$cubs], $from, $to);
check($drop['calls'] === 1 && count($h) === 1 && $drop['completed'] === [], 'allowance drops after call 1: the second term is not requested and the section is not stored');

// Cooldown is per section.
$now = 1_800_000_000;
$_SESSION[GiftAidLoader::REFRESH_KEY] = [];
check(GiftAidLoader::cooldownRemaining('10', $now) === 0, 'no cooldown before the first load');
GiftAidLoader::markRefreshed('10', $now);
check(GiftAidLoader::cooldownRemaining('10', $now + 10) === 290, 'cooldown after a load');
check(GiftAidLoader::cooldownRemaining('10', $now + 300) === 0, 'cooldown ends after 5 minutes');
check(GiftAidLoader::cooldownRemaining('11', $now + 10) === 0, 'cooldown is per section');

// Cache TTL and the 12-section cap. Session wipe drops it; nothing is written under storage/.
unset($_SESSION[GiftAidLoader::SESSION_KEY]);
GiftAidLoader::store(['sectionId' => '10', 'declarations' => $people, 'loadedFrom' => $from, 'loadedTo' => $to, 'sectionName' => 'Cubs'], $now);
check(GiftAidLoader::cached('10', $now + 7000) !== null && GiftAidLoader::covers(GiftAidLoader::cached('10', $now + 7000), '2025-11-01', '2025-12-01'), 'cache kept under 2 hours and covers a narrower range');
check(GiftAidLoader::cached('10', $now + 7200) === null, 'cache cleared at 2 hours');
for ($i = 0; $i < 14; $i++) {
    GiftAidLoader::store(['sectionId' => (string) (100 + $i), 'declarations' => [], 'loadedFrom' => $from, 'loadedTo' => $to], $now);
}
check(count($_SESSION[GiftAidLoader::SESSION_KEY]) === GiftAidLoader::MAX_CACHED_SECTIONS && !isset($_SESSION[GiftAidLoader::SESSION_KEY]['100']), 'at most 12 sections, oldest dropped');
$_SESSION = [];
check(GiftAidLoader::cached('10', $now) === null, 'clearing the session drops Gift Aid');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/GiftAid/GiftAidGrid.php')
    . (string) file_get_contents(dirname(__DIR__) . '/src/GiftAid/GiftAidLoader.php')
    . (string) file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/GiftAidController.php');
check(!str_contains($src, 'OsmCache') && !str_contains($src, 'file_put_contents'), 'Gift Aid does not use OsmCache or write a file');
check(substr_count($src, 'giftaid.php') === 1 && str_contains($src, 'giftaid.php endpoint is not used'), 'giftaid.php is mentioned only to say it is not called');
check(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Config.php'), "section:finance:read") && !str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Config.php'), 'section:finance:write'), 'no new OAuth scope; finance read was already requested');
check(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/AuthController.php'), 'SCOPE_DENIED_KEY'), 'sign-in clears a remembered finance refusal');
check(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/App.php'), '$_SESSION = []'), 'sign-out and session expiry empty the session, which holds the Gift Aid copy');

// Controller: request count, old-session note, login-list ids, cooldown, bad 200, CSV with no OSM call.
$_SESSION = [];
$_SESSION['grantedScopes'] = Config::OAUTH_SCOPES;
$_SESSION['requestedScopes'] = Config::OAUTH_SCOPES;
LoginSections::remember([
    ['section_id' => '10', 'section_name' => 'Cubs', 'section_type' => 'cubs', 'group_name' => '4th Ashby', 'current_term_id' => 501, 'terms' => [
        ['term_id' => 501, 'startdate' => '2025-09-01', 'enddate' => '2025-12-31'],
        ['term_id' => 502, 'startdate' => '2026-01-01', 'enddate' => '2026-04-30'],
    ]],
    ['section_id' => '11', 'section_name' => 'Scouts', 'section_type' => 'scouts', 'group_name' => '4th Ashby', 'current_term_id' => 501, 'terms' => [
        ['term_id' => 501, 'startdate' => '2025-09-01', 'enddate' => '2025-12-31'],
    ]],
]);
$view = GiftAidController::viewData(['from' => '06/10/2025', 'to' => '12/01/2026', 'show' => '1', 'section_ids' => ['10', '11', '999']]);
check($view['requestCount'] === 3 && str_contains($view['requestNote'], '3 OSM requests'), 'the page counts OSM requests before the load, and ignores an id that is not on the login list');
check($view['termsNote'] === null && $view['selectedIds'] === ['10', '11'], 'both chosen sections are on the login list');
check(GiftAidController::range(['from' => '12/01/2026', 'to' => '06/10/2025'], false)[3] === true, 'reversed dates are swapped');
check(GiftAidController::range([], true)[0] === GiftAidGrid::taxYearRange()[0], 'an empty form uses the current tax year');
$_SESSION[LoginSections::SESSION_KEY] = [[
    'section_id' => '10', 'section_name' => 'Cubs', 'section_type' => 'cubs', 'group_name' => '4th Ashby', 'current_term_id' => 424,
]];
$oldView = GiftAidController::viewData(['from' => '06/10/2025', 'to' => '12/01/2026', 'show' => '1', 'section_ids' => ['10']]);
check($oldView['requestCount'] === 1 && is_string($oldView['termsNote']) && str_contains($oldView['termsNote'], 'Sign out and in again to use date ranges across terms'), 'old session: current term only, with the sign-in note');

// Restore the term list and exercise handleLoad.
$_SESSION = ['grantedScopes' => Config::OAUTH_SCOPES, 'requestedScopes' => Config::OAUTH_SCOPES];
LoginSections::remember([
    ['section_id' => '10', 'section_name' => 'Cubs', 'section_type' => 'cubs', 'group_name' => '4th Ashby', 'current_term_id' => 501, 'terms' => [
        ['term_id' => 501, 'startdate' => '2025-09-01', 'enddate' => '2025-12-31'],
        ['term_id' => 502, 'startdate' => '2026-01-01', 'enddate' => '2026-04-30'],
    ]],
]);
$post = ['section_ids' => ['10', '999'], 'from' => '06/10/2025', 'to' => '12/01/2026', 'charity' => '4th Ashby Scout Group'];
$api = mockApi([new Response(200, $hdr, ''), new Response(200, $hdr, json_encode($termB))], $h);
$first = GiftAidController::handleLoad('tok', $post, $api);
check(count($h) === 1 && str_contains((string) $h[0]['request']->getUri(), 'sectionid=10') && !str_contains((string) $h[0]['request']->getUri(), '999'), 'load calls only login-list section ids');
check(GiftAidLoader::cached('10') === null && str_contains($first['flash']['message'], 'empty-reply'), 'a bad first term stores nothing');
GiftAidLoader::store([
    'sectionId' => '10', 'sectionName' => 'Cubs', 'declarations' => [['scoutid' => '1', 'youthFirst' => 'Kept', 'youthLast' => 'Copy', 'title' => 'Mrs', 'parentname' => 'Ada Copy', 'address' => '1', 'postcode' => 'LE65 1AB', 'lastupdated' => '2025-01-01', 'status' => 'entered', 'charity' => $claim, 'donations' => [], 'group' => '4th Ashby', 'section' => 'Cubs', 'sectionId' => '10']],
    'loadedFrom' => $from, 'loadedTo' => $to,
]);
$_SESSION[GiftAidLoader::REFRESH_KEY] = [];
$api = mockApi([new Response(200, $hdr, '')], $h);
$again = GiftAidController::handleLoad('tok', $post, $api);
$still = GiftAidLoader::cached('10');
check(is_array($still) && ($still['declarations'][0]['youthFirst'] ?? '') === 'Kept', 'controller does not replace a good copy when OSM sends a bad 200');
check(str_contains($again['flash']['message'], 'was kept') && !str_contains($again['flash']['message'], 'Kept'), 'the flash says the copy was kept and does not repeat the name');
$_SESSION[GiftAidLoader::REFRESH_KEY] = [];
GiftAidLoader::markRefreshed('10', time());
$api = mockApi([new Response(200, $hdr, json_encode($termA))], $h);
$cool = GiftAidController::handleLoad('tok', $post, $api);
check($h === [] && str_contains($cool['flash']['message'], 'loaded a moment ago'), 'cooldown blocks the load before any OSM call');

// CSV routes read the session and do not take an OSM client.
$_SESSION[GiftAidLoader::REFRESH_KEY] = [];
$export = GiftAidController::exportData(['from' => '06/10/2025', 'to' => '12/01/2026', 'show' => '1', 'section_ids' => ['10', '999']]);
check($export['ok'] === true && str_contains(GiftAidGrid::declarationsCsv($export['register']), 'Kept'), 'CSV is built from the session copy');
$missing = GiftAidController::exportData(['from' => '01/01/2020', 'to' => '02/01/2020', 'show' => '1', 'section_ids' => ['10']]);
check($missing['ok'] === false && str_contains($missing['message'], 'Load Gift Aid first'), 'a range the copy does not cover is not invented');
$ctl = (string) file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/GiftAidController.php');
check(substr_count($ctl, 'new OsmApi') === 1, 'the controller constructs OsmApi in one place');
check(preg_match('/function index\(\): void\s*\{\s*Auth::requireLogin\(\);/', $ctl) === 1, 'the page requires sign-in before anything else');
check(preg_match('/function load\(\): void\s*\{\s*\$token = Auth::requireLogin\(\);\s*Csrf::requireValid\(\);/', $ctl) === 1, 'load requires sign-in and CSRF before OSM');
check(preg_match('/function declarationsCsv\(\): void/', $ctl) === 1 && preg_match('/function hmrcCsv\(\): void/', $ctl) === 1, 'both CSV routes exist');
check(str_contains($ctl, "REQUEST_METHOD'] ?? 'GET')) === 'HEAD'"), 'CSV supports HEAD');
check(is_file(dirname(__DIR__) . '/deploy/route-stubs/gift-aid/index.php')
    && is_file(dirname(__DIR__) . '/deploy/route-stubs/gift-aid/load/index.php')
    && is_file(dirname(__DIR__) . '/deploy/route-stubs/gift-aid/declarations.csv/index.php')
    && is_file(dirname(__DIR__) . '/deploy/route-stubs/gift-aid/hmrc.csv/index.php'), 'route stubs exist');

// Scope hint without calling OSM.
unset($_SESSION[GiftAidLoader::SCOPE_DENIED_KEY], $_SESSION['grantedScopes'], $_SESSION['requestedScopes']);
check(GiftAidLoader::scopeHint() === GiftAidLoader::SCOPE_MESSAGE, 'no scopes recorded: ask the user to allow Finance');
$_SESSION['grantedScopes'] = 'section:member:write';
check(GiftAidLoader::scopeHint() === GiftAidLoader::SCOPE_MESSAGE, 'granted scopes without finance: hint');
$_SESSION['grantedScopes'] = Config::OAUTH_SCOPES;
check(GiftAidLoader::scopeHint() === null, 'granted finance scope: no hint');
$_SESSION[GiftAidLoader::SCOPE_DENIED_KEY] = true;
check(GiftAidLoader::scopeHint() === GiftAidLoader::SCOPE_MESSAGE, 'a refusal earlier in the session: hint, no OSM call');
unset($_SESSION[GiftAidLoader::SCOPE_DENIED_KEY]);

// The real pause when OSM sends no rate headers (one gap, the longer one).
GiftAidLoader::$pauseOverrideUs = null;
unset($_SESSION['osmRateLimit'], $_SESSION[GiftAidLoader::REFRESH_KEY]);
$api = mockApi([new Response(200, [], json_encode(['items' => [], 'structure' => []])), new Response(200, [], json_encode(['items' => [], 'structure' => []]))], $h);
$t = microtime(true);
$paced = GiftAidLoader::load($api, 'tok', [$cubs], $from, $to);
GiftAidLoader::$pauseOverrideUs = 0;
check($paced['rateUnknown'] === true && $paced['calls'] === 2 && (microtime(true) - $t) >= 1.4, 'missing X-RateLimit headers: longer pause between calls');

// Auth redirects and CSRF, in a fresh process so exit and header() can be observed.
$root = dirname(__DIR__);
$run = static function (string $body) use ($root): array {
    $dir = sys_get_temp_dir() . '/osmh-ga-http-' . bin2hex(random_bytes(3));
    mkdir($dir, 0700, true);
    $script = $dir . '/run.php';
    $hdrFile = $dir . '/headers.txt';
    $code = str_replace(['__ROOT__', '__HDR__', '__DIR__'], [var_export($root, true), var_export($hdrFile, true), var_export($dir, true)], $body);
    file_put_contents($script, $code);
    $out = [];
    $status = 0;
    exec('php ' . escapeshellarg($script) . ' 2>' . escapeshellarg($dir . '/err.txt'), $out, $status);
    $result = [
        'status' => $status,
        'out' => implode("\n", $out),
        'err' => (string) @file_get_contents($dir . '/err.txt'),
        'headers' => (string) @file_get_contents($hdrFile),
    ];
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    return $result;
};
$prelude = <<<'PHP'
<?php
namespace App\Http {
    function header(string $value, bool $replace = true, int $code = 0): void {
        file_put_contents(__HDR__, $value . "\n", FILE_APPEND);
    }
    function http_response_code(?int $code = null): int {
        if ($code !== null) {
            file_put_contents(__HDR__, 'status:' . $code . "\n", FILE_APPEND);
        }
        return $code ?? 200;
    }
}
namespace App\Http\Controllers {
    function header(string $value, bool $replace = true, int $code = 0): void {
        file_put_contents(__HDR__, $value . "\n", FILE_APPEND);
    }
}
namespace {
    require __ROOT__ . '/vendor/autoload.php';
    require __ROOT__ . '/tests/isolate-storage.php';
    session_save_path(__DIR__);
    session_start();
    $_SERVER['REQUEST_METHOD'] = getenv('GA_METHOD') ?: 'GET';
    $_SERVER['REQUEST_URI'] = '/gift-aid/';
PHP;
$guest = $run($prelude . "\n(new App\\Http\\Controllers\\GiftAidController())->index();\n}\n");
check($guest['status'] === 0 && str_contains($guest['headers'], 'Location: /auth/'), 'GET /gift-aid redirects when signed out');
foreach (['declarationsCsv', 'hmrcCsv', 'load'] as $method) {
    $ran = $run($prelude . "\n(new App\\Http\\Controllers\\GiftAidController())->$method();\n}\n");
    check($ran['status'] === 0 && str_contains($ran['headers'], 'Location: /auth/'), $method . ' redirects when signed out');
}
$csrf = $run($prelude . <<<'PHP'

$_SESSION['accessToken'] = 'tok';
$_POST = ['section_ids' => ['10'], 'from' => '06/10/2025', 'to' => '12/01/2026'];
try {
    (new App\Http\Controllers\GiftAidController())->load();
} catch (Throwable $e) {
    file_put_contents(__HDR__, 'threw:' . $e->getMessage() . "\n", FILE_APPEND);
}
}
PHP);
check(str_contains($csrf['headers'], 'status:403') && !str_contains($csrf['headers'] . $csrf['err'], 'Location: /auth/'), 'load without CSRF is forbidden and does not call OSM');

// Signed-in CSV: headers, body, HEAD, and it finishes without a network call.
$csvChild = $run($prelude . <<<'PHP'

$_SESSION['accessToken'] = 'tok';
$_SESSION['grantedScopes'] = App\Config::OAUTH_SCOPES;
App\Osm\LoginSections::remember([[
    'section_id' => '10', 'section_name' => 'Cubs', 'section_type' => 'cubs', 'group_name' => '4th Ashby', 'current_term_id' => 1,
    'terms' => [['term_id' => 1, 'startdate' => '2026-01-01', 'enddate' => '2026-12-31']],
]]);
App\GiftAid\GiftAidLoader::store([
    'sectionId' => '10', 'sectionName' => 'Cubs', 'groupName' => '4th Ashby', 'loadedFrom' => '2026-04-06', 'loadedTo' => '2027-04-05',
    'declarations' => [[
        'group' => '4th Ashby', 'section' => 'Cubs', 'sectionId' => '10', 'scoutid' => '1',
        'youthFirst' => 'Alex', 'youthLast' => 'River', 'title' => 'Mrs', 'parentname' => 'Jane River',
        'address' => '12', 'postcode' => 'LE65 1AB', 'lastupdated' => '2026-04-06', 'status' => 'entered',
        'charity' => '4th Ashby Scout Group', 'donations' => ['2026-05-01' => 1250],
    ]],
]);
$_GET = ['from' => '06/04/2026', 'to' => '05/04/2027', 'show' => '1', 'section_ids' => ['10']];
$_SERVER['REQUEST_URI'] = '/gift-aid/hmrc.csv';
(new App\Http\Controllers\GiftAidController())->hmrcCsv();
file_put_contents(__HDR__, "LIST\n" . implode("\n", headers_list()), FILE_APPEND);
}
PHP);
check(str_starts_with($csvChild['out'], "\xEF\xBB\xBF" . 'Item,Title,'), 'hmrc.csv body is the schedule, from the session');
check(str_contains($csvChild['out'], '01/05/26,12.50') && !str_contains($csvChild['out'], 'Alex'), 'hmrc.csv has the donation and not the youth name');
check(str_contains($csvChild['headers'], 'text/csv') && str_contains($csvChild['headers'], 'gift-aid-hmrc-20260406-to-20270405.csv') && str_contains($csvChild['headers'], 'no-store') && str_contains($csvChild['headers'], 'nosniff'), 'hmrc.csv headers');
check(!str_contains($csvChild['err'], 'osm.test') && !str_contains($csvChild['err'], 'onlinescoutmanager'), 'hmrc.csv made no OSM call');
putenv('GA_METHOD=HEAD');
$head = $run($prelude . <<<'PHP'

$_SESSION['accessToken'] = 'tok';
$_SESSION['grantedScopes'] = App\Config::OAUTH_SCOPES;
App\Osm\LoginSections::remember([[
    'section_id' => '10', 'section_name' => 'Cubs', 'section_type' => 'cubs', 'group_name' => '4th Ashby', 'current_term_id' => 1, 'terms' => [],
]]);
App\GiftAid\GiftAidLoader::store([
    'sectionId' => '10', 'sectionName' => 'Cubs', 'loadedFrom' => '2026-04-06', 'loadedTo' => '2027-04-05', 'declarations' => [],
]);
$_GET = ['from' => '06/04/2026', 'to' => '05/04/2027', 'show' => '1', 'section_ids' => ['10']];
(new App\Http\Controllers\GiftAidController())->declarationsCsv();
file_put_contents(__HDR__, "LIST\n" . implode("\n", headers_list()), FILE_APPEND);
}
PHP);
putenv('GA_METHOD');
check($head['out'] === '' && str_contains($head['headers'], 'text/csv') && str_contains($head['headers'], 'gift-aid-declarations-20260406-to-20270405.csv'), 'HEAD declarations.csv sends headers and no body');

// S1–S3 and L1–L6: name parts, titles, locked charity, postcode, per-term charity, bad dates, empty grid, HMRC cells.
$fleur = GiftAidGrid::report([[
    'group' => '4th Ashby', 'section' => 'Cubs', 'sectionId' => '10', 'scoutid' => '1',
    'youthFirst' => 'A', 'youthLast' => 'B', 'title' => 'Mrs', 'parentname' => 'Fleur Catherine Wybraniec',
    'address' => '1', 'postcode' => 'LE65 1AB', 'lastupdated' => '2026-01-01', 'status' => 'entered',
    'charity' => '4th Ashby Scout Group', 'donations' => ['2026-01-12' => ['pence' => 250, 'charity' => '4th Ashby Scout Group']],
]], '2026-01-01', '2026-01-31', $claim);
check($fleur['register'][0]['declarantFirst'] === 'Fleur' && $fleur['register'][0]['declarantLast'] === 'Catherine Wybraniec'
    && $fleur['register'][0]['parentname'] === 'Fleur Catherine Wybraniec' && $fleur['register'][0]['flags'] === 'name-multi-part'
    && $fleur['hmrc'][0]['first'] === 'Fleur' && $fleur['hmrc'][0]['last'] === 'Catherine Wybraniec', 'a multi-part name is split on the first word and flagged');
$clash = GiftAidGrid::report([[
    'group' => '4th Ashby', 'section' => 'Cubs', 'sectionId' => '10', 'scoutid' => '2',
    'youthFirst' => 'A', 'youthLast' => 'B', 'title' => 'Mr', 'parentname' => 'Mrs Jo Smith',
    'address' => '2', 'postcode' => 'LE65 1AB', 'lastupdated' => '2026-01-01', 'status' => 'entered',
    'charity' => '4th Ashby Scout Group', 'donations' => ['2026-01-12' => ['pence' => 300, 'charity' => '4th Ashby Scout Group']],
]], '2026-01-01', '2026-01-31', $claim);
check($clash['hmrc'] === [] && $clash['register'][0]['flags'] === 'title-name-mismatch' && $clash['register'][0]['declarantFirst'] === 'Jo', 'a conflicting title is excluded from the HMRC file');
$inferred = GiftAidGrid::report([[
    'group' => '4th Ashby', 'section' => 'Cubs', 'sectionId' => '10', 'scoutid' => '3',
    'youthFirst' => 'A', 'youthLast' => 'B', 'title' => '', 'parentname' => 'Mr Jo Smith',
    'address' => '3', 'postcode' => 'LE65 1AB', 'lastupdated' => '2026-01-01', 'status' => 'entered',
    'charity' => '4th Ashby Scout Group', 'donations' => ['2026-01-12' => ['pence' => 400, 'charity' => '4th Ashby Scout Group']],
]], '2026-01-01', '2026-01-31', $claim);
check($inferred['hmrc'][0]['title'] === 'Mr' && $inferred['hmrc'][0]['first'] === 'Jo' && $inferred['register'][0]['title'] === '' && $inferred['register'][0]['flags'] === '', 'a blank title uses the title word from the name');
$abroad = GiftAidGrid::report([[
    'group' => '4th Ashby', 'section' => 'Cubs', 'sectionId' => '10', 'scoutid' => '4',
    'youthFirst' => 'A', 'youthLast' => 'B', 'title' => 'Mrs', 'parentname' => 'Ann Abroad',
    'address' => '4', 'postcode' => 'OVERSEAS', 'lastupdated' => '2026-01-01', 'status' => 'entered',
    'charity' => '4th Ashby Scout Group', 'donations' => ['2026-01-12' => ['pence' => 500, 'charity' => '4th Ashby Scout Group']],
]], '2026-01-01', '2026-01-31', $claim);
check($abroad['hmrc'] === [] && $abroad['register'][0]['flags'] === 'postcode-unrecognised', 'a non-UK postcode is flagged and left out of the HMRC file');
$older = [
    'group' => '4th Ashby', 'section' => 'Cubs', 'sectionId' => '10', 'scoutid' => '5',
    'youthFirst' => 'A', 'youthLast' => 'B', 'title' => 'Mrs', 'parentname' => 'Ann Older',
    'address' => '5', 'postcode' => 'LE65 1AB', 'lastupdated' => '2025-01-01', 'status' => 'entered',
    'charity' => 'Ashby ESU', 'donations' => ['2025-10-06' => ['pence' => 500, 'charity' => 'Ashby ESU']],
];
$newer = $older;
$newer['lastupdated'] = '2026-02-01';
$newer['charity'] = '4th Ashby Scout Group';
$newer['donations'] = ['2026-01-12' => ['pence' => 700, 'charity' => '4th Ashby Scout Group']];
$perTerm = GiftAidGrid::report(GiftAidGrid::mergePeople([$older, $newer]), '2025-10-01', '2026-02-01', $claim);
check(array_column($perTerm['hmrc'], 'amount') === ['7.00'] && str_contains($perTerm['register'][0]['flags'], 'charity-mismatch') && $perTerm['register'][0]['donations'] === '12.00', 'each donation keeps the charity of its own term');
check(GiftAidGrid::unreadableDateColumns(['structure' => [['field' => '2025-10-06'], ['field' => 'not-a-date'], ['field' => '']]]) === 1, 'an unreadable date column is counted');
$_SESSION[GiftAidLoader::REFRESH_KEY] = [];
$api = mockApi([new Response(200, $hdr, json_encode(['items' => [[
    'scoutid' => '1', 'firstname' => 'Ann', 'lastname' => 'Older', 'title' => 'Mrs', 'parentname' => 'Ann Older',
    'address' => '5', 'postcode' => 'LE65 1AB', 'lastupdated' => '2026-01-01', 'status' => 'entered', 'charity_name' => '4th Ashby Scout Group', '2026-01-12' => 1,
]], 'structure' => [['field' => '2026-01-12'], ['field' => 'Autumn term']]]))], $h);
$dated = GiftAidLoader::load($api, 'tok', [sectionRow('78', 'Beavers', 'beavers', [['id' => '501', 'start' => '2025-09-01', 'end' => '2026-04-30']], '501')], '2026-01-01', '2026-01-31');
check(($dated['completed'][0]['unreadableDates'] ?? -1) === 1, 'the load remembers how many date columns were skipped');
GiftAidLoader::store($dated['completed'][0]);
$_SESSION['grantedScopes'] = Config::OAUTH_SCOPES;
LoginSections::remember([[
    'section_id' => '78', 'section_name' => 'Beavers', 'section_type' => 'beavers', 'group_name' => '4th Ashby', 'current_term_id' => 501,
    'terms' => [['term_id' => 501, 'startdate' => '2025-09-01', 'enddate' => '2026-04-30']],
]]);
$dateView = GiftAidController::viewData(['from' => '01/01/2026', 'to' => '31/01/2026', 'show' => '1', 'section_ids' => ['78']]);
check($dateView['unreadableNote'] === '1 donation column had an unreadable date and was skipped', 'the page says how many date columns were skipped');
check(GiftAidController::unreadableNote(3) === '3 donation columns had an unreadable date and were skipped', 'the plural note has no personal data');
$_SESSION[GiftAidLoader::REFRESH_KEY] = [];
GiftAidLoader::store([
    'sectionId' => '79', 'sectionName' => 'Scouts', 'declarations' => [[
        'group' => '4th Ashby', 'section' => 'Scouts', 'sectionId' => '79', 'scoutid' => '9',
        'youthFirst' => 'Kept', 'youthLast' => 'Copy', 'title' => 'Mrs', 'parentname' => 'Ada Copy',
        'address' => '1', 'postcode' => 'LE65 1AB', 'lastupdated' => '2026-01-01', 'status' => 'entered',
        'charity' => $claim, 'donations' => [],
    ]],
    'loadedFrom' => '2026-01-01', 'loadedTo' => '2026-01-31',
]);
$api = mockApi([new Response(200, $hdr, '{"items":[],"structure":[]}')], $h);
$noMembers = GiftAidLoader::load($api, 'tok', [sectionRow('79', 'Scouts', 'scouts', [['id' => '501', 'start' => '2025-09-01', 'end' => '2026-04-30']], '501')], '2026-01-01', '2026-01-31');
check($noMembers['completed'] === [] && $noMembers['emptyKept'] === ['Scouts'] && (GiftAidLoader::cached('79')['declarations'][0]['youthFirst'] ?? '') === 'Kept', 'an empty items list does not replace a stored copy');
$_SESSION[GiftAidLoader::REFRESH_KEY] = [];
LoginSections::remember([[
    'section_id' => '79', 'section_name' => 'Scouts', 'section_type' => 'scouts', 'group_name' => '4th Ashby', 'current_term_id' => 501,
    'terms' => [['term_id' => 501, 'startdate' => '2025-09-01', 'enddate' => '2026-04-30']],
]]);
$api = mockApi([new Response(200, $hdr, '{"items":[],"structure":[]}')], $h);
$noMembersPage = GiftAidController::handleLoad('tok', ['section_ids' => ['79'], 'from' => '01/01/2026', 'to' => '31/01/2026'], $api);
check($noMembersPage['flash']['message'] === 'OSM returned no members for Scouts; kept the earlier copy' && (GiftAidLoader::cached('79')['declarations'][0]['youthFirst'] ?? '') === 'Kept', 'the page says the earlier copy was kept');
$dash = GiftAidGrid::report([[
    'group' => '4th Ashby', 'section' => 'Cubs', 'sectionId' => '10', 'scoutid' => '8',
    'youthFirst' => 'A', 'youthLast' => 'B', 'title' => 'Mrs', 'parentname' => 'Ann House',
    'address' => '-12', 'postcode' => 'LE65 1AB', 'lastupdated' => '2026-01-01', 'status' => 'entered',
    'charity' => '4th Ashby Scout Group', 'donations' => ['2026-01-12' => ['pence' => 100, 'charity' => '4th Ashby Scout Group']],
]], '2026-01-01', '2026-01-31', $claim);
check($dash['hmrc'] === [] && $dash['register'][0]['flags'] === 'unsafe-leading-character' && str_contains(GiftAidGrid::declarationsCsv($dash['register']), "'-12"), 'a leading hyphen is excluded from HMRC and quoted on the register');
$page = (string) file_get_contents(dirname(__DIR__) . '/templates/gift-aid.twig');
$gridSrc = (string) file_get_contents(dirname(__DIR__) . '/src/GiftAid/GiftAidGrid.php');
check(!str_contains($page, 'name="charity"') && !str_contains($gridSrc, 'gift_aid_charity') && str_contains($page, 'Claim charity:'), 'the charity box and the settings override are gone');

// Help and the template say the required things, in UK spelling, and the problem table is not a generic export.
$help = (string) file_get_contents($root . '/templates/help.twig');
$tpl = (string) file_get_contents($root . '/templates/gift-aid.twig');
check(str_contains($help, 'R68GAD_V1_00_0_EN') && str_contains($help, 'Paste the values only, starting at the Title column') && str_contains($help, 'Do not rename the tab') && str_contains($help, '.ods'), 'help explains the HMRC paste steps');
check(str_contains($help, 'HMRC uses X for overseas donors'), 'help mentions HMRC’s X for overseas donors');
check(str_contains($help, 'session copy') && str_contains($help, 'cleared when you sign out or after 2 hours'), 'the privacy paragraph names the session copy and when it is cleared');
check(str_contains($help, 'sign out and in again to use date ranges across terms'), 'help mentions the old-session note');
check(str_contains($help, 'not written to disk') && str_contains($tpl, 'sign-in only'), 'help and the page describe the session-only copy');
check(!str_contains($tpl, 'data-table-tools'), 'the on-screen problem list has no generic table export');
check(str_contains($tpl, '1,000 donations'), 'the page warns when a claim needs splitting');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
