<?php
declare(strict_types=1);
namespace App\Waiting;
use App\Config;
use App\Osm\OsmApi;
use App\Osm\OsmBusyException;
use App\Osm\OsmCache;
use App\Osm\OsmErrorLog;
use App\Osm\OsmLists;
use Throwable;
/**
 * Waiting list reads (shared by the scored list and the rank/notes screens),
 * custom-field helpers, rank sorting/diffing, and the single custom-data write.
 *
 * Write call — Jon browser capture 2026-09-26 (section 60830):
 *   POST /ext/customdata/?action=updateColumn&section_id=S
 *   form: associated_type=member, associated_id, group_id=5, column_id, value, context=members
 *   success: {status:true, data:{column_id, varname, value, ...}, meta:{last_updated...}}
 * Only this action is used — never alternate guessed actions.
 */
final class WaitingListService
{
    /** OSM custom-data group holding section custom fields (identifier customisable_data). */
    public const FIELD_GROUP_ID = '5';
    public const NOTES_MAX_LEN = 2000;      // whole OSM notes value (history included); we warn, never truncate
    public const NOTE_ENTRY_MAX_LEN = 500;  // one new note typed by the user
    public const RANK_MAX = 9999;

    /** @param array<string, float|int> $cutoffs */
    public static function idealSection(float $age, array $cutoffs): string
    {
        $sq = (float) ($cutoffs['squirrels'] ?? 4.0);
        $bv = (float) ($cutoffs['beavers'] ?? 5.75);
        $cb = (float) ($cutoffs['cubs'] ?? 7.5);
        $sc = (float) ($cutoffs['scouts'] ?? 10.0);
        $ex = (float) ($cutoffs['explorers'] ?? 13.5);
        if ($age < $sq) return 'Too Young';
        if ($age < $bv) return 'Squirrels';
        if ($age < $cb) return 'Beavers';
        if ($age < $sc) return 'Cubs';
        if ($age < $ex) return 'Scouts';
        return 'Explorers';
    }

    /**
     * All waiting-list sections this login can see. Typed `waiting` sections win; the old
     * name-contains-"waiting" match is only used when no typed section exists.
     * @param list<array<string, mixed>> $sections
     * @return array{lists: list<array{id:string,name:string,type:string,groupId:string,groupName:string}>, usedNameFallback: bool}
     */
    public static function discoverLists(array $sections): array
    {
        $typed = [];
        $named = [];
        $seen = [];
        foreach ($sections as $sec) {
            if (!is_array($sec) || !isset($sec['section_id']) || (string) $sec['section_id'] === '') continue;
            $id = (string) $sec['section_id'];
            if (isset($seen[$id])) continue;
            $type = (string) ($sec['section_type'] ?? '');
            $name = (string) ($sec['section_name'] ?? '');
            $row = [
                'id' => $id,
                'name' => $name !== '' ? $name : ('Section ' . $id),
                'type' => $type !== '' ? $type : 'waiting',
                'groupId' => (string) ($sec['group_id'] ?? ''),
                'groupName' => (string) ($sec['group_name'] ?? ''),
            ];
            if ($type === 'waiting') {
                $typed[] = $row;
                $seen[$id] = true;
            } elseif (str_contains(strtolower($name), 'waiting')) {
                $named[] = $row;
                $seen[$id] = true;
            }
        }
        if ($typed !== []) {
            return ['lists' => $typed, 'usedNameFallback' => false];
        }
        return ['lists' => $named, 'usedNameFallback' => $named !== []];
    }

    /**
     * @param list<array{id:string}> $lists
     * @return array<string, string>|null
     */
    public static function pickList(array $lists, ?string $requested, ?string $remembered): ?array
    {
        foreach ([$requested, $remembered] as $want) {
            if ($want === null || $want === '') continue;
            foreach ($lists as $l) {
                if ($l['id'] === $want) return $l;
            }
        }
        return $lists[0] ?? null;
    }

    /** Group key for shared mappings: OSM group id, else a per-section fallback. */
    public static function groupKey(array $list): string
    {
        $g = (string) ($list['groupId'] ?? '');
        return $g !== '' ? $g : ('section-' . (string) ($list['id'] ?? ''));
    }

    /** @return list<array<string, mixed>> */
    public static function fetchListRows(OsmApi $api, string $token, array $list): array
    {
        $res = $api->get($token, '/ext/members/contact/', [
            'action' => 'getListOfMembers',
            'sectionid' => $list['id'],
            'termid' => -1,
            'section' => $list['type'] ?? 'waiting',
            'sort' => 'dob',
        ]);
        return OsmLists::items($res);
    }

    /** @return array<string, mixed> raw getData response */
    public static function fetchCustomData(OsmApi $api, string $token, string $sectionId, string $scoutid): array
    {
        return $api->get($token, '/ext/customdata/', [
            'action' => 'getData',
            'section_id' => $sectionId,
            'associated_id' => $scoutid,
            'associated_type' => 'member',
            'context' => 'members',
        ]);
    }

    /**
     * Custom (customisable_data / group 5) columns from a getData response.
     * @param array<string, mixed> $res
     * @return array{groupId:string, columns: list<array{column_id:string,varname:string,label:string,orig_label:string,type:string,value:string,read_only:bool}>}
     */
    public static function extractCustomColumns(array $res): array
    {
        $groups = $res['data'] ?? [];
        $out = ['groupId' => self::FIELD_GROUP_ID, 'columns' => []];
        if (!is_array($groups)) return $out;
        foreach ($groups as $group) {
            if (!is_array($group)) continue;
            $isCustom = ($group['identifier'] ?? '') === 'customisable_data'
                || (string) ($group['group_id'] ?? '') === self::FIELD_GROUP_ID;
            if (!$isCustom) continue;
            if (isset($group['group_id']) && (string) $group['group_id'] !== '') {
                $out['groupId'] = (string) $group['group_id'];
            }
            foreach (($group['columns'] ?? []) as $col) {
                if (!is_array($col) || !isset($col['column_id'])) continue;
                $v = $col['value'] ?? '';
                $out['columns'][] = [
                    'column_id' => (string) $col['column_id'],
                    'varname' => (string) ($col['varname'] ?? ''),
                    'label' => (string) ($col['label'] ?? ''),
                    'orig_label' => (string) ($col['orig_label'] ?? ''),
                    'type' => (string) ($col['type'] ?? ''),
                    'value' => is_scalar($v) ? (string) $v : '',
                    'read_only' => in_array(strtolower((string) ($col['force_read_only'] ?? 'no')), ['yes', '1', 'true'], true),
                ];
            }
            break;
        }
        return $out;
    }

    /**
     * Suggest a column for 'rank' or 'notes' using label, orig_label and varname
     * (copes with prefixed labels such as "HNotes"). The legacy leaders' notes field
     * (varname cf_notes) is deliberately down-weighted: rank/notes use new fields.
     * @param list<array{column_id:string,varname:string,label:string,orig_label:string,read_only?:bool}> $columns
     */
    public static function suggestColumn(array $columns, string $kind, ?string $excludeId = null): ?string
    {
        $needles = match ($kind) { 'rank' => ['rank', 'priority', 'position'], 'willing' => ['willing', 'help'], default => ['note'] };
        $best = null;
        $bestScore = 0;
        foreach ($columns as $c) {
            if ($excludeId !== null && $c['column_id'] === $excludeId) continue;
            if (!empty($c['read_only'])) continue;
            $score = 0;
            foreach ($needles as $i => $n) {
                $w = $i === 0 ? 3 : 1;
                if (str_contains(strtolower($c['varname']), $n)) $score += $w;
                if (str_contains(strtolower($c['label']), $n)) $score += $w;
                if (str_contains(strtolower($c['orig_label']), $n)) $score += $w;
            }
            if ($score > 0 && strtolower($c['varname']) === 'cf_notes') $score -= 4;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $c['column_id'];
            }
        }
        return $best;
    }

    /** @return array{ok:bool, value:string, error:?string} */
    public static function normaliseRank(string $raw): array
    {
        $t = trim($raw);
        if ($t === '') return ['ok' => true, 'value' => '', 'error' => null];
        if (!preg_match('/^\d{1,4}$/', $t)) {
            return ['ok' => false, 'value' => $t, 'error' => 'Rank must be a whole number from 1 to ' . self::RANK_MAX . ', or left blank.'];
        }
        $n = (int) $t;
        if ($n < 1) {
            return ['ok' => false, 'value' => $t, 'error' => 'Rank must be 1 or more (leave blank for unranked).'];
        }
        return ['ok' => true, 'value' => (string) $n, 'error' => null];
    }

    public static function normaliseNotes(string $raw): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $raw));
    }

    public const WILLING_BONUS = 20;

    /**
     * Free-text "willing to help" → yes/no, case-insensitive. Yes when the trimmed value is Y, Yes, True
     * or 1, or starts with the word y, yes, yeah or yep (Y., yes please, Yeah!). A value containing a
     * slash is an unanswered placeholder (Y/N, Yes/No) and counts as no. Anything else, or blank, is no.
     */
    public static function isWilling(string $raw): bool
    {
        $t = strtolower(trim($raw));
        if ($t === '' || str_contains($t, '/')) return false;
        if (in_array($t, ['y', 'yes', 'true', '1'], true)) return true;
        return (bool) preg_match('/^(y|yes|yeah|yep)\b/u', $t);
    }

    /** Score without the willing bonus: age in years (with part-years) × 3 + days on the list ÷ 30. */
    public static function baseScore(?float $ageYears, int $daysOnList): float
    {
        return ($ageYears ?? 0.0) * 3 + ($daysOnList / 30);
    }

    /**
     * Plain breakdown, e.g. "Age 9.4 × 3 = 28.2 + 14.0 months + willing 20 = 62.2".
     * $willing: null = no Willing to help field chosen; true/false = the mapped value.
     * The score only ever lives in OSMHelper; it is never written to OSM.
     */
    public static function scoreBreakdown(?float $ageYears, int $daysOnList, ?bool $willing): string
    {
        $age = $ageYears ?? 0.0;
        $months = $daysOnList / 30;
        $total = $age * 3 + $months + ($willing ? self::WILLING_BONUS : 0);
        $s = ($ageYears === null ? 'Age unknown (counts as 0)' : ('Age ' . number_format($age, 1) . ' × 3 = ' . number_format($age * 3, 1)))
            . ' + ' . number_format($months, 1) . ' months';
        if ($willing === null) {
            $s .= ' (Willing to help not set up)';
        } else {
            $s .= ' + willing ' . ($willing ? self::WILLING_BONUS : 0);
        }
        return $s . ' = ' . number_format($total, 1);
    }

    /** A typed note entry as one tidy line (newlines and runs of spaces become single spaces). */
    public static function noteEntry(string $raw): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $raw));
    }

    /**
     * New OSM notes value: `dd/mm/yy hh:mm - Full Name - "entry"` (Europe/London) on the first line,
     * then the existing value unchanged (history kept).
     */
    public static function composeNote(string $entry, string $fullName, string $existing, \DateTimeImmutable $now): string
    {
        $when = $now->setTimezone(new \DateTimeZone('Europe/London'))->format('d/m/y H:i');
        $who = trim($fullName) !== '' ? trim($fullName) : 'Unknown user';
        $line = $when . ' - ' . $who . ' - "' . self::noteEntry($entry) . '"';
        $old = self::normaliseNotes($existing);
        return $old === '' ? $line : ($line . "\n" . $old);
    }

    /** Comparable form of an OSM rank value (so "03" == "3"; text left as-is). */
    public static function rankComparable(string $v): string
    {
        $t = trim($v);
        return preg_match('/^\d+$/', $t) ? (string) (int) $t : $t;
    }

    /**
     * Sort: numeric rank ascending, then non-numeric ranks, then blanks; ties by score desc, then name.
     * @param list<array<string, mixed>> $rows each with rankValue + scoreNum + firstName/lastName
     * @return list<array<string, mixed>>
     */
    public static function sortForRank(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $ka = self::rankBucket((string) ($a['rankValue'] ?? ''));
            $kb = self::rankBucket((string) ($b['rankValue'] ?? ''));
            if ($ka[0] !== $kb[0]) return $ka[0] <=> $kb[0];
            if ($ka[1] !== $kb[1]) return $ka[1] <=> $kb[1];
            $sa = (float) ($a['scoreNum'] ?? -INF);
            $sb = (float) ($b['scoreNum'] ?? -INF);
            if ($sa !== $sb) return $sb <=> $sa;
            return strcmp(
                strtolower(($a['lastName'] ?? '') . ' ' . ($a['firstName'] ?? '')),
                strtolower(($b['lastName'] ?? '') . ' ' . ($b['firstName'] ?? ''))
            );
        });
        return $rows;
    }

    /** @return array{0:int,1:int|string} */
    private static function rankBucket(string $v): array
    {
        $t = trim($v);
        if ($t === '') return [2, 0];
        if (preg_match('/^\d+$/', $t)) return [0, (int) $t];
        return [1, strtolower($t)];
    }

    /**
     * Proposed 1..N for rows already in display order. Only a proposal — nothing is written.
     * @param list<array<string, mixed>> $sortedRows
     * @return array<string, string> scoutid => proposed rank
     */
    public static function renumber(array $sortedRows): array
    {
        $out = [];
        $i = 1;
        foreach ($sortedRows as $r) {
            $id = (string) ($r['scoutid'] ?? '');
            if ($id === '') continue;
            $out[$id] = (string) $i++;
        }
        return $out;
    }

    /** Ranks shared by more than one applicant (for a soft warning). @return list<string> */
    public static function tiedRanks(array $rows): array
    {
        $count = [];
        foreach ($rows as $r) {
            $v = self::rankComparable((string) ($r['rankValue'] ?? ''));
            if ($v === '') continue;
            $count[$v] = ($count[$v] ?? 0) + 1;
        }
        $ties = array_keys(array_filter($count, static fn ($n) => $n > 1));
        sort($ties, SORT_NATURAL);
        return array_map('strval', $ties);
    }

    /**
     * Posted form → edited cells (compared with what the form loaded). Pure.
     * @param array<string, mixed> $post keys rank[], notes[], orig_rank[], orig_notes[] keyed by scoutid
     * @return array{changes: list<array{scoutid:string,field:string,orig:string,new:string}>, errors: list<string>}
     */
    public static function editedCells(array $post): array
    {
        // Applicant names posted with the form (who[scoutid]) are used only to word error messages.
        $who = is_array($post['who'] ?? null) ? $post['who'] : [];
        $label = static function (string $sid) use ($who): string {
            $n = is_string($who[$sid] ?? null) ? trim($who[$sid]) : '';
            return $n !== '' ? $n : ('Member ' . $sid);
        };
        $changes = [];
        $errors = [];
        $rank = is_array($post['rank'] ?? null) ? $post['rank'] : [];
        $notes = is_array($post['notes'] ?? null) ? $post['notes'] : [];
        $origRank = is_array($post['orig_rank'] ?? null) ? $post['orig_rank'] : [];
        $origNotes = is_array($post['orig_notes'] ?? null) ? $post['orig_notes'] : [];
        foreach ($rank as $sid => $raw) {
            $sid = (string) $sid;
            if (!preg_match('/^\d+$/', $sid) || !is_string($raw)) continue;
            $orig = is_string($origRank[$sid] ?? null) ? $origRank[$sid] : '';
            if (self::rankComparable($raw) === self::rankComparable($orig)) continue;
            $n = self::normaliseRank($raw);
            if (!$n['ok']) {
                $errors[] = $label($sid) . ': ' . lcfirst($n['error']);
                continue;
            }
            if ($n['value'] === self::rankComparable($orig)) continue;
            $changes[] = ['scoutid' => $sid, 'field' => 'rank', 'orig' => $orig, 'new' => $n['value']];
        }
        // Notes: the textbox holds a NEW entry only (empty = no change). It is prepended to the
        // existing OSM value later (composeNote), so history is kept.
        foreach ($notes as $sid => $raw) {
            $sid = (string) $sid;
            if (!preg_match('/^\d+$/', $sid) || !is_string($raw)) continue;
            $orig = is_string($origNotes[$sid] ?? null) ? $origNotes[$sid] : '';
            $entry = self::noteEntry($raw);
            if ($entry === '') continue;
            if (mb_strlen($entry) > self::NOTE_ENTRY_MAX_LEN) {
                $errors[] = $label($sid) . ': a new note can be at most ' . self::NOTE_ENTRY_MAX_LEN . ' characters.';
                continue;
            }
            $changes[] = ['scoutid' => $sid, 'field' => 'notes', 'orig' => $orig, 'new' => $entry];
        }
        // Willing to help: keep (default) | Yes | No | blank. Only an explicit choice that differs is a change.
        $willing = is_array($post['willing'] ?? null) ? $post['willing'] : [];
        $origWilling = is_array($post['orig_willing'] ?? null) ? $post['orig_willing'] : [];
        foreach ($willing as $sid => $choice) {
            $sid = (string) $sid;
            if (!preg_match('/^\d+$/', $sid) || !is_string($choice)) continue;
            $new = match ($choice) { 'Yes' => 'Yes', 'No' => 'No', 'blank' => '', default => null };
            if ($new === null) continue; // keep existing
            $orig = is_string($origWilling[$sid] ?? null) ? $origWilling[$sid] : '';
            if (trim($orig) === $new) continue;
            $changes[] = ['scoutid' => $sid, 'field' => 'willing', 'orig' => $orig, 'new' => $new];
        }
        return ['changes' => $changes, 'errors' => $errors];
    }

    /**
     * Compare edited cells with values re-read from OSM now. Pure.
     * @param list<array{scoutid:string,field:string,orig:string,new:string}> $edited
     * @param array<string, array{rank:?string,notes:?string,willing?:?string}|null> $current scoutid => current OSM values (null = could not re-read / not on list)
     * @return list<array{scoutid:string,field:string,orig:string,new:string,current:string,changedInOsm:bool,missing:bool,noop:bool}>
     */
    public static function reviewChanges(array $edited, array $current): array
    {
        $out = [];
        foreach ($edited as $e) {
            $cur = $current[$e['scoutid']] ?? null;
            $missing = !is_array($cur) || ($cur[$e['field']] ?? null) === null;
            $curVal = $missing ? '' : (string) $cur[$e['field']];
            if ($e['field'] === 'rank') {
                $changed = !$missing && self::rankComparable($curVal) !== self::rankComparable($e['orig']);
                $noop = !$missing && self::rankComparable($curVal) === self::rankComparable($e['new']);
            } elseif ($e['field'] === 'willing') {
                $changed = !$missing && trim($curVal) !== trim($e['orig']);
                $noop = !$missing && trim($curVal) === $e['new'];
            } else {
                $changed = !$missing && self::normaliseNotes($curVal) !== self::normaliseNotes($e['orig']);
                $noop = false; // a new note entry always adds a line
            }
            $out[] = $e + ['current' => $curVal, 'changedInOsm' => $changed, 'missing' => $missing, 'noop' => $noop];
        }
        return $out;
    }

    /** Cache kind for OsmCache (per OSM user and waiting-list section). */
    public const CACHE_KIND = 'waiting';
    /**
     * Most applicants per page load whose details may be fetched one by one, when the bulk grid
     * doesn't supply them. Keeps a load at a fixed maximum number of OSM calls whatever the list size.
     */
    public const DETAIL_FALLBACK_MAX = 20;
    /** Cache kind for applicants' one-by-one details (only used when the grid doesn't cover them). */
    public const DETAIL_CACHE_KIND = 'waitingdetail';

    /**
     * One bulk request for every member of the waiting list: the members grid already used by
     * Find a member (POST /ext/members/contact/grid/?action=getMembers, live since 15 Oct 2026).
     * @return array<string, mixed>
     */
    public static function fetchGrid(OsmApi $api, string $token, array $list): array
    {
        return $api->post($token, '/ext/members/contact/grid/?action=getMembers', [
            'section_id' => (string) $list['id'],
            'term_id' => '-1',
        ]);
    }

    /**
     * What the waiting list needs from a grid response, per member: dates (when present) and the
     * custom-data (group 5) values keyed by column id. Contact groups are ignored, never kept.
     * Pure.
     * @param array<string, mixed> $res
     * @return array{members: array<string, array{dob:string, joined:string, custom: array<string,string>, hasCustom: bool}>, notesColumnId: ?string, structureColumns: list<string>}
     */
    public static function parseGrid(array $res): array
    {
        $structureColumns = [];
        $notesColumnId = null;
        $structure = $res['meta']['structure'] ?? $res['structure'] ?? [];
        if (is_array($structure)) {
            foreach ($structure as $grp) {
                if (!is_array($grp)) continue;
                $gid = (string) ($grp['group_id'] ?? '');
                $ident = (string) ($grp['identifier'] ?? '');
                if ($gid !== self::FIELD_GROUP_ID && $ident !== 'customisable_data') continue;
                foreach (is_array($grp['columns'] ?? null) ? $grp['columns'] : [] as $col) {
                    if (!is_array($col) || !isset($col['column_id'])) continue;
                    $cid = (string) $col['column_id'];
                    $structureColumns[] = $cid;
                    if ((string) ($col['varname'] ?? '') === 'cf_notes') $notesColumnId = $cid;
                }
            }
        }
        $data = $res['data'] ?? [];
        if (is_array($data) && isset($data['members']) && is_array($data['members'])) {
            $data = $data['members'];
        }
        $members = [];
        if (is_array($data)) {
            foreach ($data as $key => $m) {
                if (!is_array($m)) continue;
                $sid = (string) ($m['member_id'] ?? $m['scoutid'] ?? (is_int($key) || is_string($key) ? $key : ''));
                if ($sid === '' || !ctype_digit($sid)) continue;
                $cd = is_array($m['custom_data'] ?? null) ? $m['custom_data'] : [];
                $g = $cd[self::FIELD_GROUP_ID] ?? $cd[(int) self::FIELD_GROUP_ID] ?? null;
                $custom = [];
                foreach ($structureColumns as $cid) $custom[$cid] = '';
                if (is_array($g)) {
                    foreach ($g as $cid => $val) {
                        if (is_scalar($val) || $val === null) $custom[(string) $cid] = (string) ($val ?? '');
                    }
                }
                $members[$sid] = [
                    'dob' => self::firstScalar($m, ['dob', 'date_of_birth']),
                    'joined' => self::firstScalar($m, ['joined', 'applicationdate', 'started']),
                    'custom' => $custom,
                    'hasCustom' => is_array($g) || $structureColumns !== [],
                ];
            }
        }
        return ['members' => $members, 'notesColumnId' => $notesColumnId, 'structureColumns' => $structureColumns];
    }

    /** @param array<string, mixed> $row @param list<string> $keys */
    private static function firstScalar(array $row, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && is_scalar($row[$k]) && trim((string) $row[$k]) !== '') return trim((string) $row[$k]);
        }
        return '';
    }

    private static function parsesAsDate(string $v): bool
    {
        return $v !== '' && strtotime($v) !== false;
    }

    /**
     * Raw waiting-list rows with as few OSM calls as possible, cached per OSM user and section for
     * OsmCache::TTL_SECONDS:
     *   cache hit: 0 calls. Otherwise 1 list call + 1 grid call, plus (only for applicants the grid
     *   didn't cover) at most DETAIL_FALLBACK_MAX applicants' individual calls.
     * Stops at once (no retry) when the OSM allowance guard refuses or OSM returns 429.
     * Cached rows hold names, dates and custom-data values only (no contact details, no tokens).
     *
     * @return array{rows: list<array<string, mixed>>, listCount: int, listError: ?string, busyMinutes: ?int, detailsSkipped: int, fromCache: bool, source: string}
     */
    public static function loadRows(OsmApi $api, string $token, array $list, bool $useCache = true): array
    {
        $sectionId = (string) $list['id'];
        $userId = OsmCache::currentUserId();
        if ($useCache) {
            $hit = OsmCache::get(self::CACHE_KIND, $userId, $sectionId);
            if (is_array($hit) && is_array($hit['rows'] ?? null)) {
                return [
                    'rows' => $hit['rows'],
                    'listCount' => (int) ($hit['listCount'] ?? count($hit['rows'])),
                    'listError' => null,
                    'busyMinutes' => null,
                    'detailsSkipped' => 0,
                    'fromCache' => true,
                    'source' => (string) ($hit['source'] ?? 'grid'),
                ];
            }
        }
        $out = ['rows' => [], 'listCount' => 0, 'listError' => null, 'busyMinutes' => null, 'detailsSkipped' => 0, 'fromCache' => false, 'source' => 'grid'];
        try {
            $listData = self::fetchListRows($api, $token, $list);
        } catch (OsmBusyException $e) {
            $out['busyMinutes'] = $e->waitMinutes();
            $out['listError'] = $e->getMessage();
            return $out;
        } catch (Throwable) {
            $out['listError'] = 'We couldn’t load this waiting list from OSM. Try again in a few minutes. If it keeps happening, sign out and sign in again.';
            return $out;
        }
        $out['listCount'] = count($listData);

        $grid = ['members' => [], 'notesColumnId' => null, 'structureColumns' => []];
        $gridOk = false;
        $anyApplicant = false;
        foreach ($listData as $applicant) {
            if (is_array($applicant) && (string) ($applicant['scoutid'] ?? $applicant['id'] ?? '') !== '') { $anyApplicant = true; break; }
        }
        if ($anyApplicant) {
            try {
                $res = self::fetchGrid($api, $token, $list);
                if (!array_key_exists('_raw', $res) && ($res['status'] ?? null) !== false && empty($res['error']) && is_array($res['data'] ?? null)) {
                    $grid = self::parseGrid($res);
                    $gridOk = true;
                } else {
                    @error_log('OSMHelper waiting-list grid_unusable');
                }
            } catch (OsmBusyException $e) {
                $out['busyMinutes'] = $e->waitMinutes();
            } catch (Throwable) {
                @error_log('OSMHelper waiting-list grid_error');
            }
        }

        // Details already fetched one by one in the last few minutes (only used when the grid
        // didn't cover them), so a long list fills in over a few loads instead of re-fetching.
        $now = time();
        $detailCache = OsmCache::get(self::DETAIL_CACHE_KIND, $userId, $sectionId) ?? [];
        $details = [];
        foreach (is_array($detailCache['members'] ?? null) ? $detailCache['members'] : [] as $sid => $d) {
            if (is_array($d) && is_int($d['at'] ?? null) && $now - $d['at'] < OsmCache::TTL_SECONDS) $details[(string) $sid] = $d;
        }
        $detailsChanged = false;

        $fallbackUsed = 0;
        $rows = [];
        foreach ($listData as $applicant) {
            if (!is_array($applicant)) continue;
            $scoutid = (string) ($applicant['scoutid'] ?? $applicant['id'] ?? '');
            if ($scoutid === '') continue;
            $g = $gridOk ? ($grid['members'][$scoutid] ?? null) : null;
            $dob = $g !== null && self::parsesAsDate($g['dob']) ? $g['dob'] : self::firstScalar($applicant, ['dob']);
            $joined = $g !== null && self::parsesAsDate($g['joined']) ? $g['joined'] : self::firstScalar($applicant, ['joined']);
            $custom = $g !== null ? $g['custom'] : [];
            $customOk = $g !== null && $g['hasCustom'];
            $notesCol = $grid['notesColumnId'];
            $leadersNotes = ($customOk && $notesCol !== null) ? (string) ($custom[$notesCol] ?? '') : '';
            $row = [
                'scoutid' => $scoutid,
                'firstname' => (string) ($applicant['firstname'] ?? ''),
                'lastname' => (string) ($applicant['lastname'] ?? ''),
                'dob' => $dob,
                'joined' => $joined,
                'custom' => $custom,
                'customOk' => $customOk,
                'leadersNotes' => $leadersNotes,
                'detail' => 'ok',
            ];
            $needInd = !self::parsesAsDate($dob) || !self::parsesAsDate($joined);
            $needCustom = !$customOk;
            $cachedDetail = $details[$scoutid] ?? null;
            if (($needInd || $needCustom) && is_array($cachedDetail)) {
                $row = self::mergeDetail($row, $cachedDetail, $needInd, $needCustom);
            } elseif ($needInd || $needCustom) {
                if ($out['busyMinutes'] !== null || $fallbackUsed >= self::DETAIL_FALLBACK_MAX) {
                    // Not fetched this time, to keep this load's OSM calls bounded (or OSM is busy).
                    $out['detailsSkipped']++;
                    $row['detail'] = 'skipped';
                } else {
                    $fallbackUsed++;
                    $out['source'] = $gridOk ? 'grid+individual' : 'individual';
                    $fetched = ['at' => $now];
                    try {
                        if ($needInd) {
                            $ind = $api->get($token, '/ext/members/contact/', [
                                'action' => 'getIndividual',
                                'sectionid' => $sectionId,
                                'scoutid' => $scoutid,
                                'termid' => -1,
                                'context' => 'members',
                            ]);
                            $d = $ind['data'] ?? $ind;
                            if (!is_array($d)) $d = [];
                            $fetched['dob'] = self::firstScalar($d, ['dob']);
                            $fetched['joined'] = self::firstScalar($d, ['joined', 'applicationdate', 'started']);
                            $fetched['firstname'] = self::firstScalar($d, ['firstname']);
                            $fetched['lastname'] = self::firstScalar($d, ['lastname']);
                        }
                        if ($needCustom) {
                            try {
                                $cols = self::extractCustomColumns(self::fetchCustomData($api, $token, $sectionId, $scoutid));
                                $fetched['custom'] = [];
                                $fetched['leadersNotes'] = '';
                                foreach ($cols['columns'] as $col) {
                                    $fetched['custom'][$col['column_id']] = $col['value'];
                                    if ($col['varname'] === 'cf_notes') $fetched['leadersNotes'] = $col['value'];
                                }
                            } catch (OsmBusyException $e) {
                                throw $e;
                            } catch (Throwable) {
                            }
                        }
                        $row = self::mergeDetail($row, $fetched, $needInd, $needCustom);
                        $details[$scoutid] = $fetched;
                        $detailsChanged = true;
                    } catch (OsmBusyException $e) {
                        $out['busyMinutes'] = $e->waitMinutes();
                        $out['detailsSkipped']++;
                        $row['detail'] = 'skipped';
                    } catch (Throwable) {
                        $row['detail'] = 'error';
                    }
                }
            }
            $rows[] = $row;
        }
        $out['rows'] = $rows;
        if ($detailsChanged) {
            OsmCache::put(self::DETAIL_CACHE_KIND, $userId, $sectionId, ['members' => $details]);
        }
        // Cache only a complete load: never one cut short by the allowance guard or the cap.
        if ($out['busyMinutes'] === null && $out['detailsSkipped'] === 0) {
            OsmCache::put(self::CACHE_KIND, $userId, $sectionId, [
                'rows' => $rows,
                'listCount' => $out['listCount'],
                'source' => $out['source'],
            ]);
        }
        return $out;
    }

    /**
     * Fill a row's missing pieces from one-by-one details (getIndividual / getData). Pure.
     * @param array<string, mixed> $row
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private static function mergeDetail(array $row, array $d, bool $needInd, bool $needCustom): array
    {
        if ($needInd) {
            if (!self::parsesAsDate((string) $row['dob'])) $row['dob'] = (string) ($d['dob'] ?? '');
            if (!self::parsesAsDate((string) $row['joined'])) $row['joined'] = (string) ($d['joined'] ?? '');
            if ($row['firstname'] === '') $row['firstname'] = (string) ($d['firstname'] ?? '');
            if ($row['lastname'] === '') $row['lastname'] = (string) ($d['lastname'] ?? '');
        }
        if ($needCustom && is_array($d['custom'] ?? null)) {
            $row['custom'] = $row['custom'] + $d['custom'];
            foreach ($d['custom'] as $k => $v) $row['custom'][(string) $k] = (string) $v;
            $row['customOk'] = true;
            $row['leadersNotes'] = (string) ($d['leadersNotes'] ?? '');
        }
        return $row;
    }

    /** Forget the cached waiting list for every user of a section (after OSMHelper writes to it). */
    public static function invalidateCache(string $sectionId): void
    {
        OsmCache::forgetScope(self::CACHE_KIND, $sectionId);
        OsmCache::forgetScope(self::DETAIL_CACHE_KIND, $sectionId);
    }

    /**
     * Load and score applicants (see loadRows for the OSM calls), keeping scoutid and all custom
     * column values. $map (saved field mapping, optional): its willing_column_id, when set, supplies
     * "willing to help".
     * @param array<string, float|int> $cutoffs
     * @return array{applicants: list<array<string, mixed>>, listCount: int, listError: ?string, busyMinutes: ?int, detailsSkipped: int, fromCache: bool}
     */
    public static function loadApplicants(OsmApi $api, string $token, array $list, array $cutoffs, ?array $map = null, bool $useCache = true): array
    {
        $raw = self::loadRows($api, $token, $list, $useCache);
        return [
            'applicants' => self::scoreRows($raw['rows'], $cutoffs, $map),
            'listCount' => $raw['listCount'],
            'listError' => $raw['listError'],
            'busyMinutes' => $raw['busyMinutes'],
            'detailsSkipped' => $raw['detailsSkipped'],
            'fromCache' => $raw['fromCache'],
        ];
    }

    /**
     * Score raw rows. Pure (no OSM calls), so the cache can hold rows and the score still follows
     * the current cut-offs and field mapping.
     * @param list<array<string, mixed>> $rows
     * @param array<string, float|int> $cutoffs
     * @return list<array<string, mixed>>
     */
    public static function scoreRows(array $rows, array $cutoffs, ?array $map = null, ?int $now = null): array
    {
        $now ??= time();
        $applicants = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $detail = (string) ($r['detail'] ?? 'ok');
            if ($detail !== 'ok') {
                $applicants[] = [
                    'scoutid' => (string) ($r['scoutid'] ?? ''),
                    'firstName' => (string) ($r['firstname'] ?? ''),
                    'lastName' => (string) ($r['lastname'] ?? ''),
                    'age' => 'Unknown',
                    'timeOnList' => 'N/A',
                    'willingToHelp' => 'N/A',
                    'willingRaw' => '',
                    'willingYes' => false,
                    'willingMapped' => false,
                    'baseScore' => -INF,
                    'ageYears' => null,
                    'scoreBreakdown' => $detail === 'skipped'
                        ? 'Not loaded yet, to protect your OSM allowance. Open this page again in a few minutes.'
                        : 'Could not read this applicant’s details from OSM, so there is no score.',
                    'mappedNotes' => '',
                    'leadersNotes' => '',
                    'idealSection' => 'Unknown',
                    'scoreNum' => -INF,
                    'score' => 'N/A',
                    'rank' => 0,
                    'custom' => [],
                    'customOk' => false,
                ];
                continue;
            }
            $custom = is_array($r['custom'] ?? null) ? $r['custom'] : [];
            $dob = strtotime((string) ($r['dob'] ?? ''));
            $age = $dob !== false ? ($now - $dob) / (365.25 * 24 * 60 * 60) : null;
            $ageMonths = $dob !== false ? (int) floor(($now - $dob) / (30.4375 * 24 * 60 * 60)) : null;
            $ageDisplay = $ageMonths !== null
                ? ((int) floor($ageMonths / 12)) . ' y ' . ($ageMonths % 12) . ' m'
                : 'Unknown';
            $join = strtotime((string) ($r['joined'] ?? ''));
            $timeOnList = $join !== false ? (int) floor(($now - $join) / 86400) : 0;
            // Willing to help: optional mapped custom field (group 5); unmapped = no +20.
            $willingCol = is_array($map) ? (string) ($map['willing_column_id'] ?? '') : '';
            $willingRaw = $willingCol !== '' ? (string) ($custom[$willingCol] ?? '') : '';
            $willingYes = $willingCol !== '' && self::isWilling($willingRaw);
            $baseScore = self::baseScore($age, $timeOnList);
            $scoreNum = $baseScore + ($willingYes ? self::WILLING_BONUS : 0);
            $applicants[] = [
                'scoutid' => (string) $r['scoutid'],
                'firstName' => (string) ($r['firstname'] ?? ''),
                'lastName' => (string) ($r['lastname'] ?? ''),
                'age' => $ageDisplay,
                'timeOnList' => $timeOnList,
                'willingToHelp' => $willingCol === '' ? '' : ($willingYes ? 'Yes' : 'No'),
                'willingRaw' => $willingRaw,
                'willingYes' => $willingYes,
                'willingMapped' => $willingCol !== '',
                'baseScore' => $baseScore,
                'ageYears' => $age,
                'scoreBreakdown' => self::scoreBreakdown($age, $timeOnList, $willingCol === '' ? null : $willingYes),
                'mappedNotes' => is_array($map) && ($map['notes_column_id'] ?? '') !== '' ? (string) ($custom[$map['notes_column_id']] ?? '') : '',
                'leadersNotes' => (string) ($r['leadersNotes'] ?? ''),
                'idealSection' => self::idealSection($age ?? 0, $cutoffs),
                'scoreNum' => $scoreNum,
                'score' => number_format($scoreNum, 1),
                'rank' => 0,
                'custom' => $custom,
                'customOk' => (bool) ($r['customOk'] ?? false),
            ];
        }
        usort($applicants, static fn ($a, $b) => ($b['scoreNum'] <=> $a['scoreNum']));
        foreach ($applicants as $i => &$a) { $a['rank'] = $i + 1; }
        unset($a);
        return $applicants;
    }

    /**
     * Can this session save ranks/notes? Uses the granted scope stored at login when OSM
     * returned one, else the scopes this login requested; sessions from before the scope
     * change (neither stored) stay read-only. A 403 from a write also flips this off.
     * @return array{canSave:bool, needsRelogin:bool, reason:string}
     */
    public static function saveAccess(): array
    {
        if (!empty($_SESSION['memberWriteDenied'])) {
            return ['canSave' => false, 'needsRelogin' => true,
                'reason' => 'OSM refused a save (permission or scope). Sign in again to enable saving ranks; if it still fails, check your OSM permissions for this waiting list.'];
        }
        $granted = $_SESSION['grantedScopes'] ?? null;
        if (is_string($granted) && $granted !== '') {
            if (str_contains($granted, 'section:member:write')) {
                return ['canSave' => true, 'needsRelogin' => false, 'reason' => ''];
            }
            return ['canSave' => false, 'needsRelogin' => true,
                'reason' => 'Your OSM sign-in does not include permission to update member data. Sign in again to enable saving ranks.'];
        }
        $requested = $_SESSION['requestedScopes'] ?? null;
        if (is_string($requested) && str_contains($requested, 'section:member:write')) {
            return ['canSave' => true, 'needsRelogin' => false, 'reason' => ''];
        }
        return ['canSave' => false, 'needsRelogin' => true,
            'reason' => 'You signed in before saving ranks was available. Sign in again to enable saving ranks.'];
    }

    /** Writes on unless the code constant or env WAITING_RANK_WRITES=off/false/0 disables them. */
    public static function writesEnabled(bool $codeSwitch): bool
    {
        if (!$codeSwitch) return false;
        $env = Config::get('WAITING_RANK_WRITES');
        if ($env !== null && in_array(strtolower(trim($env)), ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        return true;
    }

    /**
     * Single custom-field write (captured updateColumn). Success only when status === true.
     * @return array{ok:bool, message:string, raw:?string, forbidden:bool}
     */
    public static function writeColumn(
        OsmApi $api,
        string $token,
        string $sectionId,
        string $memberId,
        string $fieldGroupId,
        string $columnId,
        string $value
    ): array {
        $path = '/ext/customdata/?action=updateColumn&section_id=' . rawurlencode($sectionId);
        try {
            $res = $api->post($token, $path, [
                'associated_type' => 'member',
                'associated_id' => $memberId,
                'group_id' => $fieldGroupId !== '' ? $fieldGroupId : self::FIELD_GROUP_ID,
                'column_id' => $columnId,
                'value' => $value,
                'context' => 'members',
            ]);
        } catch (OsmBusyException $e) {
            // Not sent: the allowance guard refused (429 backoff). No retry.
            return ['ok' => false, 'message' => $e->getMessage(), 'raw' => null, 'forbidden' => false, 'returned' => null, 'notSent' => true];
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $lower = strtolower($msg);
            $forbidden = $e->getCode() === 403 || str_contains($lower, '403') || str_contains($lower, 'forbidden')
                || str_contains($lower, 'scope') || str_contains($lower, 'permission');
            return ['ok' => false, 'message' => $msg, 'raw' => null, 'forbidden' => $forbidden, 'returned' => null];
        }
        $raw = self::brief($res);
        if (($res['status'] ?? null) === true) {
            $note = 'OK';
            $data = is_array($res['data'] ?? null) ? $res['data'] : [];
            if (isset($data['column_id']) && (string) $data['column_id'] !== $columnId) {
                $note = 'OK (OSM echoed column ' . (string) $data['column_id'] . ')';
            }
            $echo = array_key_exists('value', $data) && is_scalar($data['value']) ? (string) $data['value'] : null;
            return ['ok' => true, 'message' => $note, 'raw' => $raw, 'forbidden' => false, 'returned' => $echo];
        }
        $lower = strtolower($raw);
        $forbidden = str_contains($lower, 'permission') || str_contains($lower, 'scope') || str_contains($lower, 'forbidden');
        OsmErrorLog::log([
            'method' => 'POST',
            'endpoint' => $path,
            'action' => 'updateColumn',
            'http_status' => null,
            'osm_code' => null,
            'kind' => 'write',
            'section_id' => $sectionId,
            'badge_id' => null,
            'detail' => 'column ' . $columnId,
        ]);
        return ['ok' => false, 'message' => 'OSM did not return status true.', 'raw' => $raw, 'forbidden' => $forbidden, 'returned' => null];
    }

    /** @param array<string, mixed> $res */
    public static function brief(array $res): string
    {
        $json = json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return '(unencodable response)';
        return strlen($json) > 600 ? substr($json, 0, 600) . '…' : $json;
    }
}
