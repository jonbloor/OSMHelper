<?php
declare(strict_types=1);
namespace App\GiftAid;
use App\Store\SettingsStore;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure Gift Aid parsing, the HMRC / register mapping, and the two CSVs.
 * No OSM calls and no files: callers pass the JSON body and write the CSV from the returned string.
 *
 * A getGrid body is usable only when it has an items array (the same "unusable 200" idea as
 * EventList::badResponseReason()). structure[].field / structure[].name is the only list of
 * donation dates; other keys on an item, including total, are ignored.
 */
final class GiftAidGrid
{
    /** Claim charity when settings have not overridden it. Matched case-insensitively after trim. */
    public const DEFAULT_CLAIM_CHARITY = '4th Ashby Scout Group';
    /** settings.json key. Not donor data. Empty or missing uses DEFAULT_CLAIM_CHARITY. */
    public const CLAIM_CHARITY_SETTING = 'gift_aid_charity';
    public const HMRC_ROW_WARN = 1000;
    public const TITLE_MAX = 4;
    public const NAME_MAX = 35;
    public const HOUSE_MAX = 40;

    /** Flag order on the register and in the problem list. */
    public const FLAG_ORDER = [
        'title-name-mismatch',
        'missing-postcode',
        'missing-address',
        'name-unsplit',
        'charity-blank',
        'charity-mismatch',
        'missing-declaration-date',
        'title-too-long',
    ];

    /** These flags mean the donation cannot go on the HMRC schedule. */
    private const BLOCKING_FLAGS = [
        'missing-postcode',
        'missing-address',
        'name-unsplit',
        'charity-blank',
        'charity-mismatch',
    ];

    /** @var list<string> */
    private const TITLE_WORDS = [
        'mr', 'mrs', 'ms', 'miss', 'mx', 'dr', 'rev', 'prof', 'sir', 'lady', 'lord', 'dame',
        'capt', 'col', 'maj', 'master',
    ];

    /**
     * Why a 200 body cannot be used, or null when it has an items array.
     * A literal [] is not a grid (no items array), so it is unusable and must not wipe a stored copy.
     *
     * @param array<string, mixed> $res
     */
    public static function badResponseReason(array $res, ?string $bodyKind = null): ?string
    {
        if (array_key_exists('_raw', $res)) {
            return 'not JSON';
        }
        if (array_key_exists('error', $res) && $res['error'] !== null && $res['error'] !== false && $res['error'] !== '') {
            $e = $res['error'];
            $msg = is_array($e) ? ($e['message'] ?? null) : $e;
            return 'error' . (is_string($msg) && $msg !== '' ? ': ' . mb_substr($msg, 0, 120) : '');
        }
        if (array_key_exists('status', $res) && $res['status'] === false) {
            return 'status false';
        }
        if ($res === []) {
            return match ($bodyKind) {
                'empty-list' => 'no gift aid list',
                'empty' => 'empty reply',
                'empty-object' => 'empty object',
                default => 'empty reply',
            };
        }
        $items = $res['items'] ?? ($res['data']['items'] ?? null);
        if (!is_array($items)) {
            return 'no gift aid list';
        }
        return null;
    }

    /** Fixed log code for a badResponseReason() string. Drops any OSM-supplied text after the colon. */
    public static function reasonCode(string $reason): string
    {
        $head = strtolower(trim(explode(':', $reason, 2)[0]));
        $codes = [
            'not json' => 'not-json',
            'error' => 'osm-error',
            'status false' => 'status-false',
            'empty reply' => 'empty-reply',
            'empty object' => 'empty-object',
            'no gift aid list' => 'no-gift-aid-list',
        ];
        return $codes[$head] ?? 'other';
    }

    /**
     * One parsed young person. Donations are structure dates only, in pence, and only when the
     * amount is numeric and above zero. "" and 0 are not stored.
     *
     * @param array<string, mixed> $res
     * @return list<array{scoutid:string,youthFirst:string,youthLast:string,title:string,parentname:string,address:string,postcode:string,lastupdated:?string,status:string,charity:string,donations:array<string,int>}>
     */
    public static function parseGrid(array $res): array
    {
        $items = $res['items'] ?? ($res['data']['items'] ?? null);
        if (!is_array($items)) {
            return [];
        }
        $dates = self::structureDates($res);
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $scoutid = self::plain($item['scoutid'] ?? '');
            if ($scoutid === '') {
                continue;
            }
            $donations = [];
            foreach ($dates as $iso => $field) {
                $raw = null;
                if (array_key_exists($iso, $item)) {
                    $raw = $item[$iso];
                } elseif ($field !== $iso && array_key_exists($field, $item)) {
                    $raw = $item[$field];
                }
                $pence = self::pence($raw);
                if ($pence !== null) {
                    $donations[$iso] = $pence;
                }
            }
            $out[] = [
                'scoutid' => $scoutid,
                'youthFirst' => self::text($item['firstname'] ?? ''),
                'youthLast' => self::text($item['lastname'] ?? ''),
                'title' => self::text($item['title'] ?? ''),
                'parentname' => self::text($item['parentname'] ?? ''),
                'address' => self::text($item['address'] ?? ''),
                'postcode' => self::text($item['postcode'] ?? ''),
                'lastupdated' => self::isoDate(self::plain($item['lastupdated'] ?? '')),
                'status' => self::status($item['status'] ?? ''),
                'charity' => self::text($item['charity_name'] ?? ''),
                'donations' => $donations,
            ];
        }
        return $out;
    }

    /**
     * structure is the only list of donation dates. The map is iso date => field name used on the item.
     *
     * @param array<string, mixed> $res
     * @return array<string, string>
     */
    public static function structureDates(array $res): array
    {
        $structure = $res['structure'] ?? ($res['data']['structure'] ?? null);
        if (!is_array($structure)) {
            return [];
        }
        $out = [];
        foreach ($structure as $col) {
            if (!is_array($col)) {
                continue;
            }
            $field = self::plain($col['field'] ?? $col['name'] ?? '');
            $iso = self::isoDate($field);
            if ($iso === null || isset($out[$iso])) {
                continue;
            }
            $out[$iso] = $field;
        }
        return $out;
    }

    /**
     * One row per scoutid. Status wins first (entered, then declined, then no_record), then the
     * latest declaration date. Donations are taken only from copies with that winning status, so a
     * declined term cannot add a claim, and the winning copy's amount is kept when the same date
     * appears twice. Dates that only the other same-status copy has are kept: a term grid may only
     * contain that term's dates.
     *
     * @param list<array{scoutid:string,youthFirst:string,youthLast:string,title:string,parentname:string,address:string,postcode:string,lastupdated:?string,status:string,charity:string,donations:array<string,int>}> $rows
     * @return list<array{scoutid:string,youthFirst:string,youthLast:string,title:string,parentname:string,address:string,postcode:string,lastupdated:?string,status:string,charity:string,donations:array<string,int>}>
     */
    public static function mergePeople(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['scoutid']][] = $row;
        }
        $out = [];
        foreach ($groups as $group) {
            $winner = $group[0];
            foreach ($group as $row) {
                if (self::prefer($row, $winner)) {
                    $winner = $row;
                }
            }
            $donations = [];
            foreach ($group as $row) {
                if ($row['status'] !== $winner['status']) {
                    continue;
                }
                foreach ($row['donations'] as $date => $pence) {
                    if (!isset($donations[$date])) {
                        $donations[$date] = $pence;
                    }
                }
            }
            foreach ($winner['donations'] as $date => $pence) {
                $donations[$date] = $pence;
            }
            ksort($donations);
            $winner['donations'] = $donations;
            $out[] = $winner;
        }
        return $out;
    }

    /**
     * @param array{status:string,lastupdated:?string} $candidate
     * @param array{status:string,lastupdated:?string} $current
     */
    private static function prefer(array $candidate, array $current): bool
    {
        $rank = self::statusRank($candidate['status']) <=> self::statusRank($current['status']);
        if ($rank !== 0) {
            return $rank < 0;
        }
        return ($candidate['lastupdated'] ?? '') > ($current['lastupdated'] ?? '');
    }

    public static function statusRank(string $status): int
    {
        return match ($status) {
            'entered' => 0,
            'declined' => 1,
            'no_record' => 2,
            default => 3,
        };
    }

    /**
     * Last token is the last name; the rest is the first name. A matching leading title is not part
     * of the name. A conflicting title is left in the name and flagged: it is never rewritten.
     * One token, or no tokens, is name-unsplit (the single token becomes the last name).
     *
     * @return array{first:string,last:string,flags:list<string>}
     */
    public static function splitName(string $title, string $parentname): array
    {
        $name = self::text($parentname);
        $flags = [];
        if ($name === '') {
            return ['first' => '', 'last' => '', 'flags' => ['name-unsplit']];
        }
        $tokens = explode(' ', $name);
        $titleNorm = self::normTitle($title);
        $leading = self::normTitle($tokens[0]);
        if (self::isTitleWord($leading) && $titleNorm !== '') {
            if ($leading !== $titleNorm) {
                $flags[] = 'title-name-mismatch';
            } else {
                array_shift($tokens);
            }
        }
        if ($tokens === [] || count($tokens) === 1) {
            $flags[] = 'name-unsplit';
            return ['first' => '', 'last' => $tokens[0] ?? '', 'flags' => $flags];
        }
        $last = (string) array_pop($tokens);
        return ['first' => implode(' ', $tokens), 'last' => $last, 'flags' => $flags];
    }

    /** Upper case, and a single space before the inward code when it is a UK postcode. */
    public static function normalisePostcode(string $postcode): string
    {
        $postcode = strtoupper(self::text($postcode));
        $compact = str_replace(' ', '', $postcode);
        if (preg_match('/^([A-Z]{1,2}\d[A-Z\d]?)(\d[A-Z]{2})$/', $compact, $m)) {
            return $m[1] . ' ' . $m[2];
        }
        return $postcode;
    }

    /** @return 'ok'|'blank'|'mismatch' */
    public static function charityKind(string $charity, string $claim): string
    {
        $charity = mb_strtolower(trim($charity));
        $claim = mb_strtolower(trim($claim));
        if ($charity === '') {
            return 'blank';
        }
        if ($claim === '' || $charity !== $claim) {
            return 'mismatch';
        }
        return 'ok';
    }

    /**
     * The claim charity: an explicit value, otherwise settings.json gift_aid_charity, otherwise
     * 4th Ashby Scout Group. Blank means "use the default", not "match nothing".
     */
    public static function claimCharity(?string $override = null): string
    {
        $v = $override !== null ? trim($override) : '';
        if ($v === '') {
            $stored = SettingsStore::get(self::CLAIM_CHARITY_SETTING, self::DEFAULT_CLAIM_CHARITY);
            $v = is_string($stored) ? trim($stored) : '';
        }
        if ($v === '') {
            $v = self::DEFAULT_CLAIM_CHARITY;
        }
        if (mb_strlen($v) > 120) {
            $v = mb_substr($v, 0, 120);
        }
        return $v;
    }

    /** Current UK tax year, 6 April to 5 April, in Europe/London. @return array{0:string,1:string} */
    public static function taxYearRange(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('now', new DateTimeZone('Europe/London'));
        $today = $today->setTimezone(new DateTimeZone('Europe/London'));
        $year = (int) $today->format('Y');
        $start = sprintf('%04d-04-06', $year);
        if ($today->format('Y-m-d') < $start) {
            $year--;
        }
        return [sprintf('%04d-04-06', $year), sprintf('%04d-04-05', $year + 1)];
    }

    /** A term with a missing start or end overlaps every range: we cannot tell, so the grid is fetched. */
    public static function termOverlaps(?string $start, ?string $end, string $from, string $to): bool
    {
        if ($start === null || $start === '' || $end === null || $end === '') {
            return true;
        }
        return $start <= $to && $end >= $from;
    }

    /** yyyy-mm-dd from yyyy-mm-dd[...] or dd/mm/yyyy, or null. */
    public static function isoDate(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if ($y < 1900 || !checkdate($mo, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    public static function ukDate(?string $iso): string
    {
        if ($iso === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return '';
        }
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    /** DD/MM/YY for the HMRC schedule. */
    public static function hmrcDate(?string $iso): string
    {
        if ($iso === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return '';
        }
        return $m[3] . '/' . $m[2] . '/' . sprintf('%02d', ((int) $m[1]) % 100);
    }

    public static function money(int $pence): string
    {
        $sign = $pence < 0 ? '-' : '';
        $pence = abs($pence);
        return $sign . intdiv($pence, 100) . '.' . str_pad((string) ($pence % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function warnSplit(int $hmrcRows): bool
    {
        return $hmrcRows > self::HMRC_ROW_WARN;
    }

    /**
     * Register rows, HMRC donation rows and the on-screen counts for one inclusive date range.
     * People are already merged and carry group, section and sectionId.
     *
     * @param list<array<string, mixed>> $people
     * @return array{register:list<array<string,mixed>>,hmrc:list<array<string,mixed>>,preview:array<string,mixed>}
     */
    public static function report(array $people, string $from, string $to, string $claimCharity): array
    {
        $register = [];
        $hmrc = [];
        foreach ($people as $person) {
            if (!is_array($person)) {
                continue;
            }
            $view = self::viewPerson($person, $from, $to, $claimCharity);
            $register[] = $view;
            if ($view['blocked']) {
                continue;
            }
            foreach ($view['gifts'] as $gift) {
                $hmrc[] = $gift + [
                    'sortLast' => mb_strtolower((string) $view['declarantLast']),
                    'sortFirst' => mb_strtolower((string) $view['declarantFirst']),
                    'section' => (string) $view['section'],
                    'scoutid' => (string) $view['scoutid'],
                ];
            }
        }
        usort($register, static function (array $a, array $b): int {
            return [
                mb_strtolower((string) $a['group']),
                mb_strtolower((string) $a['section']),
                mb_strtolower((string) $a['youthLast']),
                mb_strtolower((string) $a['youthFirst']),
                (string) $a['scoutid'],
            ] <=> [
                mb_strtolower((string) $b['group']),
                mb_strtolower((string) $b['section']),
                mb_strtolower((string) $b['youthLast']),
                mb_strtolower((string) $b['youthFirst']),
                (string) $b['scoutid'],
            ];
        });
        usort($hmrc, static function (array $a, array $b): int {
            return [$a['iso'], $a['sortLast'], $a['sortFirst'], $a['section'], $a['scoutid'], $a['pence']]
                <=> [$b['iso'], $b['sortLast'], $b['sortFirst'], $b['section'], $b['scoutid'], $b['pence']];
        });
        $hmrcRows = [];
        $n = 0;
        foreach ($hmrc as $row) {
            $n++;
            $hmrcRows[] = [
                'item' => $n,
                'title' => $row['title'],
                'first' => $row['first'],
                'last' => $row['last'],
                'house' => $row['house'],
                'postcode' => $row['postcode'],
                'date' => $row['date'],
                'amount' => $row['amount'],
                'pence' => $row['pence'],
            ];
        }
        return [
            'register' => $register,
            'hmrc' => $hmrcRows,
            'preview' => self::preview($register, $hmrcRows, $claimCharity),
        ];
    }

    /**
     * @param list<array<string, mixed>> $register
     * @param list<array<string, mixed>> $hmrc
     * @return array<string, mixed>
     */
    private static function preview(array $register, array $hmrc, string $claimCharity): array
    {
        $counts = ['entered' => 0, 'declined' => 0, 'no_record' => 0, 'other' => 0];
        $flagCounts = array_fill_keys(self::FLAG_ORDER, 0);
        $charities = [];
        $problems = [];
        foreach ($register as $row) {
            $status = (string) $row['status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            } else {
                $counts['other']++;
            }
            foreach ($row['flagList'] as $flag) {
                if (isset($flagCounts[$flag])) {
                    $flagCounts[$flag]++;
                }
            }
            $label = $row['charity'] !== '' ? (string) $row['charity'] : '(blank)';
            if (!isset($charities[$label])) {
                $charities[$label] = ['name' => $label, 'count' => 0, 'included' => self::charityKind((string) $row['charity'], $claimCharity) === 'ok'];
            }
            $charities[$label]['count']++;
            if ($row['flagList'] !== []) {
                $problems[] = [
                    'section' => (string) $row['section'],
                    'youth' => trim((string) $row['youthFirst'] . ' ' . (string) $row['youthLast']),
                    'status' => $status,
                    'charity' => (string) $row['charity'],
                    'flags' => (string) $row['flags'],
                ];
            }
        }
        ksort($charities, SORT_NATURAL | SORT_FLAG_CASE);
        $pence = 0;
        foreach ($hmrc as $row) {
            $pence += (int) $row['pence'];
        }
        return [
            'declarations' => count($register),
            'entered' => $counts['entered'],
            'declined' => $counts['declined'],
            'noRecord' => $counts['no_record'],
            'other' => $counts['other'],
            'missingPostcode' => $flagCounts['missing-postcode'],
            'missingAddress' => $flagCounts['missing-address'],
            'titleMismatch' => $flagCounts['title-name-mismatch'],
            'nameUnsplit' => $flagCounts['name-unsplit'],
            'charityBlank' => $flagCounts['charity-blank'],
            'charityMismatch' => $flagCounts['charity-mismatch'],
            'missingDeclarationDate' => $flagCounts['missing-declaration-date'],
            'titleTooLong' => $flagCounts['title-too-long'],
            'hmrcRows' => count($hmrc),
            'hmrcTotal' => self::money($pence),
            'warnSplit' => self::warnSplit(count($hmrc)),
            'charities' => array_values($charities),
            'problems' => $problems,
            'claimCharity' => $claimCharity,
        ];
    }

    /**
     * @param array<string, mixed> $person
     * @return array<string, mixed>
     */
    private static function viewPerson(array $person, string $from, string $to, string $claimCharity): array
    {
        $title = self::text($person['title'] ?? '');
        $parentname = self::text($person['parentname'] ?? '');
        $split = self::splitName($title, $parentname);
        $postcode = self::text($person['postcode'] ?? '');
        $address = self::text($person['address'] ?? '');
        $charity = self::text($person['charity'] ?? '');
        $status = (string) ($person['status'] ?? 'no_record');
        $flags = [];
        if (in_array('title-name-mismatch', $split['flags'], true)) {
            $flags[] = 'title-name-mismatch';
        }
        if ($postcode === '') {
            $flags[] = 'missing-postcode';
        }
        if ($address === '') {
            $flags[] = 'missing-address';
        }
        if (in_array('name-unsplit', $split['flags'], true)) {
            $flags[] = 'name-unsplit';
        }
        $kind = self::charityKind($charity, $claimCharity);
        if ($kind === 'blank') {
            $flags[] = 'charity-blank';
        } elseif ($kind === 'mismatch') {
            $flags[] = 'charity-mismatch';
        }
        $declared = isset($person['lastupdated']) && is_string($person['lastupdated']) ? $person['lastupdated'] : null;
        if ($declared === null || $declared === '') {
            $flags[] = 'missing-declaration-date';
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            $flags[] = 'title-too-long';
        }
        $blocked = $status !== 'entered';
        if (!$blocked) {
            foreach (self::BLOCKING_FLAGS as $flag) {
                if (in_array($flag, $flags, true)) {
                    $blocked = true;
                    break;
                }
            }
        }
        if ($split['first'] === '' || $split['last'] === '') {
            $blocked = true;
        }
        $gifts = [];
        $sum = 0;
        $donations = is_array($person['donations'] ?? null) ? $person['donations'] : [];
        foreach ($donations as $iso => $pence) {
            if (!is_string($iso) || !is_int($pence) || $pence <= 0) {
                continue;
            }
            if ($iso < $from || $iso > $to) {
                continue;
            }
            $sum += $pence;
            if ($blocked) {
                continue;
            }
            $hmrcTitle = $title;
            if (mb_strlen($hmrcTitle) > self::TITLE_MAX) {
                $hmrcTitle = mb_substr($hmrcTitle, 0, self::TITLE_MAX);
            }
            $first = $split['first'];
            if (mb_strlen($first) > self::NAME_MAX) {
                $first = mb_substr($first, 0, self::NAME_MAX);
            }
            $last = str_replace('-', ' ', $split['last']);
            $last = self::text($last);
            if (mb_strlen($last) > self::NAME_MAX) {
                $last = mb_substr($last, 0, self::NAME_MAX);
            }
            $house = $address;
            if (mb_strlen($house) > self::HOUSE_MAX) {
                $house = mb_substr($house, 0, self::HOUSE_MAX);
            }
            $gifts[] = [
                'iso' => $iso,
                'title' => $hmrcTitle,
                'first' => $first,
                'last' => $last,
                'house' => $house,
                'postcode' => self::normalisePostcode($postcode),
                'date' => self::hmrcDate($iso),
                'amount' => self::money($pence),
                'pence' => $pence,
            ];
        }
        return [
            'group' => self::text($person['group'] ?? ''),
            'section' => self::text($person['section'] ?? ''),
            'sectionId' => self::plain($person['sectionId'] ?? ''),
            'scoutid' => self::plain($person['scoutid'] ?? ''),
            'youthFirst' => self::text($person['youthFirst'] ?? ''),
            'youthLast' => self::text($person['youthLast'] ?? ''),
            'status' => $status,
            'declarationDate' => self::ukDate($declared),
            'title' => $title,
            'parentname' => $parentname,
            'declarantFirst' => $split['first'],
            'declarantLast' => $split['last'],
            'house' => $address,
            'postcode' => self::normalisePostcode($postcode),
            'charity' => $charity,
            'donations' => self::money($sum),
            'flagList' => $flags,
            'flags' => implode('; ', $flags),
            'blocked' => $blocked,
            'gifts' => $gifts,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    public static function declarationsCsv(array $rows): string
    {
        $fh = self::csvHandle();
        fputcsv($fh, [
            'Group', 'Section', 'Youth first name', 'Youth last name', 'Status',
            'Declaration date (dd/mm/yyyy)', 'Declarant title', 'Declarant name as held in OSM',
            'Declarant first name', 'Declarant last name', 'House name or number', 'Postcode',
            'Charity name', 'Donations in range (2dp)', 'Flags',
        ], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, array_map([self::class, 'csvSafe'], [
                (string) $row['group'],
                (string) $row['section'],
                (string) $row['youthFirst'],
                (string) $row['youthLast'],
                (string) $row['status'],
                (string) $row['declarationDate'],
                (string) $row['title'],
                (string) $row['parentname'],
                (string) $row['declarantFirst'],
                (string) $row['declarantLast'],
                (string) $row['house'],
                (string) $row['postcode'],
                (string) $row['charity'],
                (string) $row['donations'],
                (string) $row['flags'],
            ]), ',', '"', '');
        }
        return self::csvString($fh);
    }

    /** @param list<array<string, mixed>> $rows */
    public static function hmrcCsv(array $rows): string
    {
        $fh = self::csvHandle();
        fputcsv($fh, [
            'Item', 'Title', 'First name or initial', 'Last name', 'House name or number', 'Postcode',
            'Aggregated donations', 'Sponsored event', 'Donation date', 'Amount',
        ], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, array_map([self::class, 'csvSafe'], [
                (string) $row['item'],
                (string) $row['title'],
                (string) $row['first'],
                (string) $row['last'],
                (string) $row['house'],
                (string) $row['postcode'],
                '',
                '',
                (string) $row['date'],
                (string) $row['amount'],
            ]), ',', '"', '');
        }
        return self::csvString($fh);
    }

    /** @return resource */
    private static function csvHandle()
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('Could not build CSV');
        }
        return $fh;
    }

    /** @param resource $fh */
    private static function csvString($fh): string
    {
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $out;
    }

    /** Leading quote for cells a spreadsheet could run as a formula, including after leading whitespace. */
    public static function csvSafe(string $v): string
    {
        if (preg_match('/^[\s\x{00A0}]*[=+\-@]/u', $v) || preg_match('/^[\t\r]/', $v)) {
            return "'" . $v;
        }
        return $v;
    }

    /** gift-aid-hmrc-20260406-to-20270405.csv. Dates only: no names and no section. */
    public static function csvFilename(string $kind, string $fromIso, string $toIso): string
    {
        $kind = $kind === 'hmrc' ? 'hmrc' : 'declarations';
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromIso) ? str_replace('-', '', $fromIso) : 'start';
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toIso) ? str_replace('-', '', $toIso) : 'end';
        return 'gift-aid-' . $kind . '-' . $from . '-to-' . $to . '.csv';
    }

    private static function status(mixed $raw): string
    {
        $s = strtolower(self::plain($raw));
        return match ($s) {
            'entered', 'declined', 'no_record' => $s,
            '' => 'no_record',
            default => $s,
        };
    }

    /** Positive pence, or null for blank, zero, negative and non-numeric values. */
    private static function pence(mixed $raw): ?int
    {
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        if ($raw === '' || $raw === null || is_bool($raw) || !is_numeric($raw)) {
            return null;
        }
        $pence = (int) round(((float) $raw) * 100);
        return $pence > 0 ? $pence : null;
    }

    private static function plain(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    private static function text(mixed $v): string
    {
        $s = self::plain($v);
        $s = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    private static function normTitle(string $title): string
    {
        return rtrim(strtolower(self::text($title)), '.');
    }

    private static function isTitleWord(string $norm): bool
    {
        return $norm !== '' && in_array($norm, self::TITLE_WORDS, true);
    }
}
