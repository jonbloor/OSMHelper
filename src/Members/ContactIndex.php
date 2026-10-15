<?php
declare(strict_types=1);
namespace App\Members;
use App\Osm\OsmApi;
use Throwable;

/**
 * Find a member: a small, session-held index of member and contact postcodes, phones and names.
 *
 * Searching never calls OSM. Only build() calls OSM: one members-grid request per ticked section
 * (POST /ext/members/contact/grid/?action=getMembers), which returns every member with their
 * contact groups in a single response. No per-member calls. The build stops at the first error
 * or 429, and before each call if the OSM allowance is getting low.
 */
final class ContactIndex
{
    public const SESSION_KEY = 'findMemberIndex';
    /** Minimum gap between rebuilds for one session. */
    public const REFRESH_COOLDOWN_SEC = 300;
    /** Stop before the next section call at or below this many remaining requests. */
    public const RATE_LOW_REMAINING = 60;
    /** Pause between section calls. */
    private const CALL_DELAY_US = 300000;

    /** OSM contact groups searched (group_id => label). Doctor (4) and custom data (5) are not searched. */
    public const GROUPS = [
        6 => 'Member',
        1 => 'Primary contact 1',
        2 => 'Primary contact 2',
        3 => 'Emergency contact',
    ];
    /** Fallback when the grid response has no meta.structure: OSM core column ids. */
    private const GROUP_IDENTIFIERS = [
        'contact_primary_member' => 6,
        'contact_primary_1' => 1,
        'contact_primary_2' => 2,
        'emergency' => 3,
    ];
    private const CORE_COLUMNS = [
        2 => 'firstname',
        3 => 'lastname',
        11 => 'postcode',
        18 => 'phone1',
        20 => 'phone2',
    ];

    /** Upper-case, letters and digits only. */
    public static function normalisePostcode(string $v): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($v)) ?? '';
    }

    /** Digits only, with +44 / 0044 / leading 0 removed, so 07700 900123 == +44 7700 900123. */
    public static function normalisePhone(string $v): string
    {
        $t = trim($v);
        $digits = preg_replace('/\D/', '', $t) ?? '';
        if (str_starts_with($t, '+44') || str_starts_with($digits, '0044')) {
            $digits = substr($digits, str_starts_with($digits, '0044') ? 4 : 2);
        } elseif (strlen($digits) >= 12 && str_starts_with($digits, '44')) {
            $digits = substr($digits, 2);
        }
        return ltrim($digits, '0');
    }

    public static function normaliseName(string $v): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($v)) ?? '');
    }

    /**
     * Turn one section's grid response into index rows.
     * @param array<string, mixed> $res
     * @return list<array{scoutid:string,firstname:string,lastname:string,section:string,sectionId:string,contacts:list<array{group:string,name:string,fields:array<string,string>}>}>
     */
    public static function parseGrid(array $res, string $sectionId, string $sectionName): array
    {
        $colMap = self::columnMap($res);
        $data = $res['data'] ?? [];
        if (is_array($data) && isset($data['members']) && is_array($data['members'])) {
            $data = $data['members'];
        }
        if (!is_array($data)) {
            return [];
        }
        $rows = [];
        foreach ($data as $key => $m) {
            if (!is_array($m)) {
                continue;
            }
            $scoutid = (string) ($m['member_id'] ?? $m['scoutid'] ?? (is_string($key) || is_int($key) ? $key : ''));
            if ($scoutid === '' || !ctype_digit($scoutid)) {
                continue;
            }
            $patrol = (string) ($m['patrol'] ?? '');
            if (trim($patrol) === 'Leaders') {
                continue; // same rule as the Members list
            }
            $custom = $m['custom_data'] ?? [];
            $contacts = [];
            if (is_array($custom)) {
                foreach (self::GROUPS as $gid => $label) {
                    $g = $custom[$gid] ?? $custom[(string) $gid] ?? null;
                    if (!is_array($g)) {
                        continue;
                    }
                    $fields = [];
                    foreach ($g as $colId => $val) {
                        if (!is_scalar($val) || trim((string) $val) === '') {
                            continue;
                        }
                        $var = $colMap[$gid][(string) $colId] ?? self::CORE_COLUMNS[(int) $colId] ?? null;
                        if ($var === null || !self::isSearchableVar($var)) {
                            continue;
                        }
                        $fields[$var] = trim((string) $val);
                    }
                    $name = trim(($fields['firstname'] ?? '') . ' ' . ($fields['lastname'] ?? ''));
                    unset($fields['firstname'], $fields['lastname']);
                    if ($fields === [] && $name === '') {
                        continue;
                    }
                    $contacts[] = ['group' => $label, 'name' => $name, 'fields' => $fields];
                }
            }
            $rows[] = [
                'scoutid' => $scoutid,
                'firstname' => (string) ($m['first_name'] ?? $m['firstname'] ?? ''),
                'lastname' => (string) ($m['last_name'] ?? $m['lastname'] ?? ''),
                'section' => $sectionName,
                'sectionId' => $sectionId,
                'contacts' => $contacts,
            ];
        }
        return $rows;
    }

    private static function isSearchableVar(string $var): bool
    {
        return in_array($var, ['firstname', 'lastname', 'postcode'], true) || (bool) preg_match('/^phone\d+$/', $var);
    }

    /**
     * group_id => column_id => varname from meta.structure (when OSM sends it).
     * @param array<string, mixed> $res
     * @return array<int, array<string, string>>
     */
    private static function columnMap(array $res): array
    {
        $out = [];
        $structure = $res['meta']['structure'] ?? $res['structure'] ?? [];
        if (!is_array($structure)) {
            return $out;
        }
        foreach ($structure as $grp) {
            if (!is_array($grp)) {
                continue;
            }
            $gid = isset($grp['group_id']) && is_numeric($grp['group_id']) ? (int) $grp['group_id'] : null;
            if ($gid === null && isset($grp['identifier'])) {
                $gid = self::GROUP_IDENTIFIERS[(string) $grp['identifier']] ?? null;
            }
            if ($gid === null || !is_array($grp['columns'] ?? null)) {
                continue;
            }
            foreach ($grp['columns'] as $col) {
                if (is_array($col) && isset($col['column_id'], $col['varname'])) {
                    $out[$gid][(string) $col['column_id']] = (string) $col['varname'];
                }
            }
        }
        return $out;
    }

    /**
     * Search the index. $type: any|postcode|phone|name. Never calls OSM.
     * @param list<array<string, mixed>> $rows
     * @return list<array{scoutid:string,name:string,section:string,sectionId:string,contactGroup:string,contactName:string,field:string,value:string}>
     */
    public static function search(array $rows, string $query, string $type = 'any'): array
    {
        $pc = self::normalisePostcode($query);
        $ph = self::normalisePhone($query);
        $nm = self::normaliseName($query);
        $doPc = ($type === 'any' || $type === 'postcode') && strlen($pc) >= 2;
        $doPh = ($type === 'any' || $type === 'phone') && strlen($ph) >= 4 && !preg_match('/[a-z]/i', $query);
        $doNm = ($type === 'any' || $type === 'name') && mb_strlen($nm) >= 2;
        $hits = [];
        foreach ($rows as $r) {
            $memberName = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
            foreach ($r['contacts'] ?? [] as $c) {
                $add = static function (string $field, string $value) use (&$hits, $r, $c, $memberName): void {
                    $k = $r['scoutid'] . '|' . $r['sectionId'] . '|' . $c['group'] . '|' . $field;
                    $hits[$k] = [
                        'scoutid' => (string) $r['scoutid'],
                        'name' => $memberName,
                        'section' => (string) $r['section'],
                        'sectionId' => (string) $r['sectionId'],
                        'contactGroup' => (string) $c['group'],
                        'contactName' => (string) $c['name'],
                        'field' => $field,
                        'value' => $value,
                    ];
                };
                foreach ($c['fields'] as $var => $val) {
                    if ($var === 'postcode' && $doPc && str_contains(self::normalisePostcode($val), $pc)) {
                        $add('Postcode', $val);
                    } elseif ($var !== 'postcode' && $doPh) {
                        $n = self::normalisePhone($val);
                        if ($n !== '' && str_contains($n, $ph)) {
                            $add('Phone (' . $var . ')', $val);
                        }
                    }
                }
                // Contact names only (the member's own name is in the Members list already).
                if ($doNm && $c['group'] !== 'Member' && $c['name'] !== '' && str_contains(self::normaliseName($c['name']), $nm)) {
                    $add('Name', $c['name']);
                }
            }
        }
        $out = array_values($hits);
        usort($out, static fn ($a, $b) => [$a['name'], $a['section']] <=> [$b['name'], $b['section']]);
        return $out;
    }

    /**
     * Rebuild from OSM: one grid call per section, throttled, stopping at the first problem.
     * @param list<array{section_id:string,section_name:string,section_type:string,current_term_id:mixed}> $sections
     * @return array{rows:list<array<string,mixed>>,loaded:list<string>,skipped:list<string>,stopReason:?string,calls:int}
     */
    public static function build(OsmApi $api, string $token, array $sections): array
    {
        $rows = [];
        $loaded = $skipped = [];
        $stop = null;
        $calls = 0;
        foreach ($sections as $sec) {
            $name = (string) $sec['section_name'];
            if ($stop !== null) {
                $skipped[] = $name;
                continue;
            }
            if (in_array((string) $sec['section_type'], ['waiting', 'unknown'], true)) {
                $skipped[] = $name;
                continue;
            }
            if ($api->isRateLow(self::RATE_LOW_REMAINING)) {
                $stop = 'Stopped to protect your OSM allowance (few requests left). Try again after it resets.';
                $skipped[] = $name;
                continue;
            }
            if ($calls > 0) {
                usleep(self::CALL_DELAY_US);
            }
            try {
                $calls++;
                $res = $api->post($token, '/ext/members/contact/grid/?action=getMembers', [
                    'section_id' => (string) $sec['section_id'],
                    'term_id' => (string) ($sec['current_term_id'] ?? -1),
                ]);
            } catch (Throwable $e) {
                $stop = $e->getCode() === 429
                    ? 'OSM said too many requests, so OSMHelper stopped. Wait for your allowance to reset.'
                    : 'OSM returned an error for ' . $name . ', so OSMHelper stopped without trying again.';
                $skipped[] = $name;
                continue;
            }
            foreach (self::parseGrid($res, (string) $sec['section_id'], $name) as $row) {
                $rows[] = $row;
            }
            $loaded[] = $name;
        }
        return ['rows' => $rows, 'loaded' => $loaded, 'skipped' => $skipped, 'stopReason' => $stop, 'calls' => $calls];
    }
}
