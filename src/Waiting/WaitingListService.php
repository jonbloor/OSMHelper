<?php
declare(strict_types=1);
namespace App\Waiting;
use App\Config;
use App\Osm\OsmApi;
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

    /**
     * Load applicants exactly as the scored waiting list always has (list + getIndividual +
     * getData per applicant), additionally keeping scoutid and all custom column values.
     * $map (saved field mapping, optional): its willing_column_id, when set, supplies "willing to help".
     * @param array<string, float|int> $cutoffs
     * @return array{applicants: list<array<string, mixed>>, listCount: int, listError: ?string}
     */
    public static function loadApplicants(OsmApi $api, string $token, array $list, array $cutoffs, ?array $map = null): array
    {
        $waitingId = $list['id'];
        $listCount = 0;
        $listError = null;
        try {
            $listData = self::fetchListRows($api, $token, $list);
            $listCount = count($listData);
        } catch (Throwable) {
            $listData = [];
            $listError = 'We couldn’t load this waiting list from OSM. Try again in a few minutes. If it keeps happening, sign out and sign in again.';
        }

        $applicants = [];
        $now = time();
        foreach ($listData as $applicant) {
            if (!is_array($applicant)) continue;
            $scoutid = $applicant['scoutid'] ?? $applicant['id'] ?? null;
            if ($scoutid === null || $scoutid === '') continue;
            try {
                $ind = $api->get($token, '/ext/members/contact/', [
                    'action' => 'getIndividual',
                    'sectionid' => $waitingId,
                    'scoutid' => $scoutid,
                    'termid' => -1,
                    'context' => 'members',
                ]);
                $d = $ind['data'] ?? $ind;
                if (!is_array($d)) $d = [];
                $dobRaw = $d['dob'] ?? $applicant['dob'] ?? '';
                $dob = strtotime((string) $dobRaw);
                $age = $dob !== false ? ($now - $dob) / (365.25 * 24 * 60 * 60) : null;
                $ageMonths = $dob !== false ? (int) floor(($now - $dob) / (30.4375 * 24 * 60 * 60)) : null;
                $ageDisplay = $ageMonths !== null
                    ? ((int) floor($ageMonths / 12)) . ' y ' . ($ageMonths % 12) . ' m'
                    : 'Unknown';
                $join = strtotime((string) ($d['joined'] ?? $d['applicationdate'] ?? $d['started'] ?? $applicant['joined'] ?? ''));
                $timeOnList = $join !== false ? (int) floor(($now - $join) / 86400) : 0;
                $leadersNotes = '';
                $custom = [];
                $customOk = false;
                try {
                    $cd = self::fetchCustomData($api, $token, (string) $waitingId, (string) $scoutid);
                    $cols = self::extractCustomColumns($cd);
                    $customOk = true;
                    foreach ($cols['columns'] as $col) {
                        $custom[$col['column_id']] = $col['value'];
                        if ($col['varname'] === 'cf_notes') $leadersNotes = $col['value'];
                    }
                } catch (Throwable) {}

                // Willing to help: optional mapped custom field (same getData group); unmapped = no +20.
                $willingCol = is_array($map) ? (string) ($map['willing_column_id'] ?? '') : '';
                $willingRaw = $willingCol !== '' ? (string) ($custom[$willingCol] ?? '') : '';
                $willingYes = $willingCol !== '' && self::isWilling($willingRaw);
                $baseScore = self::baseScore($age, $timeOnList);
                $scoreNum = $baseScore + ($willingYes ? self::WILLING_BONUS : 0);
                $applicants[] = [
                    'scoutid' => (string) $scoutid,
                    'firstName' => (string) ($applicant['firstname'] ?? $d['firstname'] ?? ''),
                    'lastName' => (string) ($applicant['lastname'] ?? $d['lastname'] ?? ''),
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
                    'leadersNotes' => $leadersNotes,
                    'idealSection' => self::idealSection($age ?? 0, $cutoffs),
                    'scoreNum' => $scoreNum,
                    'score' => number_format($scoreNum, 1),
                    'rank' => 0,
                    'custom' => $custom,
                    'customOk' => $customOk,
                ];
            } catch (Throwable) {
                $applicants[] = [
                    'scoutid' => (string) $scoutid,
                    'firstName' => (string) ($applicant['firstname'] ?? ''),
                    'lastName' => (string) ($applicant['lastname'] ?? ''),
                    'age' => 'Unknown',
                    'timeOnList' => 'N/A',
                    'willingToHelp' => 'N/A',
                    'willingRaw' => '',
                    'willingYes' => false,
                    'willingMapped' => false,
                    'baseScore' => -INF,
                    'ageYears' => null,
                    'scoreBreakdown' => 'Could not read this applicant’s details from OSM, so there is no score.',
                    'mappedNotes' => '',
                    'leadersNotes' => '',
                    'idealSection' => 'Unknown',
                    'scoreNum' => -INF,
                    'score' => 'N/A',
                    'rank' => 0,
                    'custom' => [],
                    'customOk' => false,
                ];
            }
        }
        usort($applicants, static fn ($a, $b) => ($b['scoreNum'] <=> $a['scoreNum']));
        foreach ($applicants as $i => &$a) { $a['rank'] = $i + 1; }
        unset($a);
        return ['applicants' => $applicants, 'listCount' => $listCount, 'listError' => $listError];
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
            'osm_message' => 'status not true',
            'kind' => 'write',
            'section_id' => $sectionId,
            'badge_id' => null,
            'scoutid' => $memberId,
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
