<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\App;
use App\Http\Auth;
use App\Osm\OsmApi;
use App\Osm\OsmDebug;
use App\Osm\OsmLists;
use Throwable;

/**
 * Read-only Top-awards dry-run: count activity+staged awards since startedsection.
 * No OSM writes.
 */
final class TopAwardsController
{
    private const THRESHOLD_SCOUTS = 6;
    private const TYPE_ACTIVITY = 2;
    private const TYPE_STAGED = 3;
    private const TYPE_CHALLENGE = 1;

    public function index(): void
    {
        $token = Auth::requireLogin();
        $api = new OsmApi();
        $error = null;
        $scopeHint = null;
        $sectionMeta = null;
        $rows = [];
        $notes = [];
        $debugMeta = [
            'awardedDateKeysSeen' => [],
            'badgeApiCalls' => 0,
            'mode' => null,
        ];

        try {
            $sections = $api->getDynamicSections($token);
            $scouts = [];
            foreach ($sections as $sec) {
                $type = strtolower((string) ($sec['section_type'] ?? $sec['section'] ?? ''));
                if ($type === 'scouts') {
                    $scouts[] = $sec;
                }
            }
            if ($scouts === []) {
                throw new \RuntimeException('No section with type=scouts found on this OSM login.');
            }
            // Prefer known Scouts section from Jon's Network capture when present.
            $sec = $scouts[0];
            foreach ($scouts as $cand) {
                $cid = (string) ($cand['section_id'] ?? $cand['sectionid'] ?? '');
                if ($cid === '60831') {
                    $sec = $cand;
                    break;
                }
            }
            if (count($scouts) > 1) {
                $names = array_map(static function ($s) {
                    return (string) ($s['section_name'] ?? $s['sectionid'] ?? '?')
                        . ' [' . (string) ($s['section_id'] ?? $s['sectionid'] ?? '') . ']';
                }, $scouts);
                $picked = (string) ($sec['section_id'] ?? $sec['sectionid'] ?? '');
                $notes[] = 'Multiple Scouts sections; using ' . $picked . ' from: ' . implode(', ', $names);
            }
            $sectionId = (string) ($sec['section_id'] ?? $sec['sectionid'] ?? '');
            $termId = (string) ($sec['current_term_id'] ?? '');
            $sectionName = (string) ($sec['section_name'] ?? 'Scouts');
            $sectionMeta = [
                'id' => $sectionId,
                'name' => $sectionName,
                'type' => 'scouts',
                'termId' => $termId,
            ];
            if ($sectionId === '' || $termId === '' || $termId === '-1') {
                throw new \RuntimeException('Scouts section missing id or current term.');
            }

            // Members in section
            $listRes = $api->get($token, '/ext/members/contact/', [
                'action' => 'getListOfMembers',
                'sectionid' => $sectionId,
                'termid' => $termId,
                'section' => 'scouts',
            ]);
            $members = OsmLists::items($listRes);
            $youth = [];
            foreach ($members as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $patrol = (string) ($m['patrol'] ?? '');
                if (self::isLeaderPatrol($patrol) || self::isYoungLeaderPatrol($patrol)) {
                    continue;
                }
                $sid = (string) ($m['scoutid'] ?? $m['scout_id'] ?? '');
                if ($sid === '') {
                    continue;
                }
                $youth[$sid] = [
                    'scoutid' => $sid,
                    'firstname' => (string) ($m['firstname'] ?? ''),
                    'lastname' => (string) ($m['lastname'] ?? ''),
                    'patrol' => $patrol,
                    'startedsection' => '',
                ];
            }

            // startedsection via getIndividual
            foreach (array_keys($youth) as $sid) {
                try {
                    $ind = $api->get($token, '/ext/members/contact/', [
                        'action' => 'getIndividual',
                        'sectionid' => $sectionId,
                        'scoutid' => $sid,
                        'termid' => $termId,
                        'context' => 'members',
                    ]);
                    $d = self::individualPayload($ind);
                    $start = (string) ($d['startedsection'] ?? $d['started_section'] ?? '');
                    if ($start === '' && isset($d['startedsection'])) {
                        $start = (string) $d['startedsection'];
                    }
                    $youth[$sid]['startedsection'] = $start;
                    if (($youth[$sid]['firstname'] ?? '') === '' && !empty($d['firstname'])) {
                        $youth[$sid]['firstname'] = (string) $d['firstname'];
                    }
                    if (($youth[$sid]['lastname'] ?? '') === '' && !empty($d['lastname'])) {
                        $youth[$sid]['lastname'] = (string) $d['lastname'];
                    }
                } catch (Throwable $e) {
                    $notes[] = 'getIndividual failed for scoutid ' . $sid . ': ' . $e->getMessage();
                }
                usleep(80000);
            }

            // Awards: try badgesbyperson first
            $awardsByMember = [];
            $byPersonOk = false;
            try {
                $byPerson = $api->get($token, '/ext/badges/badgesbyperson/', [
                    'action' => 'loadBadgesByMember',
                    'section' => 'scouts',
                    'sectionid' => $sectionId,
                    'term_id' => $termId,
                ]);
                $debugMeta['badgeApiCalls']++;
                OsmDebug::log('top_awards_byperson_keys', [
                    'keys' => array_keys($byPerson),
                    'data0' => is_array($byPerson['data'][0] ?? null)
                        ? array_keys($byPerson['data'][0])
                        : null,
                    'badge0' => is_array(($byPerson['data'][0]['badges'][0] ?? null))
                        ? array_keys($byPerson['data'][0]['badges'][0])
                        : null,
                ]);
                $people = OsmLists::items($byPerson);
                if ($people === [] && isset($byPerson['data']) && is_array($byPerson['data'])) {
                    $people = array_values(array_filter($byPerson['data'], 'is_array'));
                }
                foreach ($people as $person) {
                    $sid = (string) ($person['scoutid'] ?? $person['member_id'] ?? '');
                    if ($sid === '' || !isset($youth[$sid])) {
                        continue;
                    }
                    $badges = $person['badges'] ?? [];
                    if (!is_array($badges)) {
                        continue;
                    }
                    foreach ($badges as $b) {
                        if (!is_array($b)) {
                            continue;
                        }
                        $typeId = (int) ($b['type_id'] ?? $b['typeid'] ?? $b['badge_type'] ?? 0);
                        // badgesbyperson may omit type_id — skip filter here, refine below if needed
                        $parsed = self::parseAward($b, $debugMeta);
                        if ($parsed === null) {
                            continue;
                        }
                        // If type known, only activity/staged
                        if ($typeId !== 0 && $typeId !== self::TYPE_ACTIVITY && $typeId !== self::TYPE_STAGED) {
                            continue;
                        }
                        // If type unknown, keep and tag
                        $awardsByMember[$sid][] = $parsed + ['type_id' => $typeId, 'source' => 'byperson'];
                    }
                }
                $byPersonOk = $people !== [];
                $debugMeta['mode'] = 'badgesbyperson';
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $notes[] = 'badgesbyperson failed: ' . $msg;
                if (str_contains(strtolower($msg), '403') || str_contains(strtolower($msg), 'scope') || str_contains(strtolower($msg), 'forbidden')) {
                    $scopeHint = $msg;
                }
            }

            // Prefer typed records when byperson lacks type_id (avoid double-count).
            if (!$byPersonOk || self::needsTypeFilter($awardsByMember)) {
                if ($byPersonOk && self::needsTypeFilter($awardsByMember)) {
                    $awardsByMember = [];
                    $notes[] = 'badgesbyperson lacked type_id — using getBadgeRecords for activity/staged only.';
                }
                $debugMeta['mode'] = ($debugMeta['mode'] ?? '') . '+records';
                foreach ([self::TYPE_ACTIVITY => 'activity', self::TYPE_STAGED => 'staged'] as $typeId => $label) {
                    try {
                        $avail = $api->get($token, '/ext/badges/records/', [
                            'action' => 'getAvailableBadges',
                            'section' => 'scouts',
                            'section_id' => $sectionId,
                            'sectionid' => $sectionId,
                            'term_id' => $termId,
                            'type_id' => $typeId,
                            'payload' => 1,
                            'context' => 'none',
                        ]);
                        $debugMeta['badgeApiCalls']++;
                        $badgeList = OsmLists::items($avail);
                        if ($badgeList === [] && isset($avail['data']) && is_array($avail['data'])) {
                            $badgeList = array_values(array_filter($avail['data'], 'is_array'));
                        }
                        OsmDebug::log('top_awards_available_' . $label, [
                            'count' => count($badgeList),
                            'first_keys' => isset($badgeList[0]) && is_array($badgeList[0]) ? array_keys($badgeList[0]) : [],
                        ]);
                        foreach ($badgeList as $badge) {
                            if (!is_array($badge)) {
                                continue;
                            }
                            $badgeId = (string) ($badge['badge_id'] ?? $badge['badgeid'] ?? '');
                            $badgeVersion = (string) ($badge['badge_version'] ?? $badge['badgeversion'] ?? '0');
                            $badgeName = (string) ($badge['name'] ?? $badgeId);
                            if ($badgeId === '') {
                                continue;
                            }
                            try {
                                $recs = $api->get($token, '/ext/badges/records/', [
                                    'action' => 'getBadgeRecords',
                                    'section' => 'scouts',
                                    'sectionid' => $sectionId,
                                    'section_id' => $sectionId,
                                    'term_id' => $termId,
                                    'type_id' => $typeId,
                                    'badge_id' => $badgeId,
                                    'badge_version' => $badgeVersion,
                                    'payload' => 1,
                                    'member_id' => 0,
                                ]);
                                $debugMeta['badgeApiCalls']++;
                                $items = self::badgeRecordMembers($recs);
                                if ($items === [] && empty($debugMeta['emptyRecordsLogged'])) {
                                    $debugMeta['emptyRecordsLogged'] = true;
                                    $notes[] = 'getBadgeRecords returned no data.members (check shape). data keys: '
                                        . (isset($recs['data']) && is_array($recs['data'])
                                            ? implode(',', array_keys($recs['data']))
                                            : gettype($recs['data'] ?? null));
                                }
                                foreach ($items as $row) {
                                    if (!is_array($row)) {
                                        continue;
                                    }
                                    $sid = self::memberIdFromRow($row);
                                    if ($sid === '' || !isset($youth[$sid])) {
                                        continue;
                                    }
                                    $parsed = self::parseAward($row, $debugMeta);
                                    if ($parsed === null) {
                                        continue;
                                    }
                                    $parsed['type_id'] = $typeId;
                                    $parsed['badge_name'] = $badgeName;
                                    $parsed['source'] = 'records';
                                    $awardsByMember[$sid][] = $parsed;
                                }
                            } catch (Throwable $e) {
                                $notes[] = "getBadgeRecords {$label} {$badgeId}: " . $e->getMessage();
                                if (str_contains(strtolower($e->getMessage()), '403')) {
                                    $scopeHint = $e->getMessage();
                                }
                            }
                            usleep(100000);
                        }
                    } catch (Throwable $e) {
                        $notes[] = "getAvailableBadges {$label}: " . $e->getMessage();
                        if (str_contains(strtolower($e->getMessage()), '403') || str_contains(strtolower($e->getMessage()), 'forbidden')) {
                            $scopeHint = $e->getMessage();
                        }
                    }
                }
            }

            // Build rows
            foreach ($youth as $sid => $m) {
                $start = trim((string) ($m['startedsection'] ?? ''));
                $startTs = $start !== '' ? strtotime($start) : false;
                $activity = 0;
                $staged = 0;
                $unknownType = 0;
                $skippedNoDate = 0;
                $skippedBefore = 0;
                $missingStart = ($start === '' || $startTs === false);

                $seen = []; // dedupe badge_id+level+date
                foreach ($awardsByMember[$sid] ?? [] as $a) {
                    $typeId = (int) ($a['type_id'] ?? 0);
                    // When using byperson without types, count all awarded with date (soft)
                    if ($typeId !== 0 && $typeId !== self::TYPE_ACTIVITY && $typeId !== self::TYPE_STAGED) {
                        continue;
                    }
                    $dateStr = (string) ($a['awarded_date'] ?? '');
                    $dateTs = $dateStr !== '' ? strtotime($dateStr) : false;
                    if ($dateTs === false) {
                        $skippedNoDate++;
                        continue;
                    }
                    if ($missingStart) {
                        continue;
                    }
                    if ($dateTs < $startTs) {
                        $skippedBefore++;
                        continue;
                    }
                    $key = ($a['badge_id'] ?? '') . '|' . ($a['level'] ?? '') . '|' . $dateStr;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    if ($typeId === self::TYPE_STAGED) {
                        $staged++;
                    } elseif ($typeId === self::TYPE_ACTIVITY) {
                        $activity++;
                    } else {
                        $unknownType++;
                    }
                }
                $total = $activity + $staged + $unknownType;
                $rows[] = [
                    'scoutid' => $sid,
                    'name' => trim(($m['firstname'] ?? '') . ' ' . ($m['lastname'] ?? '')),
                    'firstname' => $m['firstname'],
                    'lastname' => $m['lastname'],
                    'section_name' => $sectionName,
                    'patrol' => $m['patrol'],
                    'startedsection' => $start,
                    'activity' => $activity,
                    'staged' => $staged,
                    'unknown_type' => $unknownType,
                    'total' => $total,
                    'gold' => self::goldString($total, self::THRESHOLD_SCOUTS),
                    'missing_start' => $missingStart,
                    'skipped_no_date' => $skippedNoDate,
                    'skipped_before_start' => $skippedBefore,
                ];
            }

            usort($rows, static function ($a, $b) {
                return strcasecmp((string) $a['lastname'] . $a['firstname'], (string) $b['lastname'] . $b['firstname']);
            });
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if (str_contains(strtolower($error), '403') || str_contains(strtolower($error), 'forbidden') || str_contains(strtolower($error), 'scope')) {
                $scopeHint = $error;
            }
        }

        $payload = [
            'at' => gmdate('c'),
            'section' => $sectionMeta,
            'threshold' => self::THRESHOLD_SCOUTS,
            'rows' => $rows,
            'notes' => $notes,
            'error' => $error,
            'scopeHint' => $scopeHint,
            'debugMeta' => $debugMeta,
            'oauthScopesConfigured' => \App\Config::OAUTH_SCOPES,
        ];
        self::writeDryRun($payload);

        App::render('top-awards.twig', Auth::baseContext([
            'title' => 'Top awards (dry-run)',
            'section' => $sectionMeta,
            'threshold' => self::THRESHOLD_SCOUTS,
            'rows' => $rows,
            'notes' => $notes,
            'error' => $error,
            'scopeHint' => $scopeHint,
            'debugMeta' => $debugMeta,
        ]));
    }


    /**
     * getBadgeRecords returns { data: { members: [ { member_id, awarded, awardeddate, ... } ] } }.
     * OsmLists::items misses this shape (data is an object, not a row list).
     * @param array<string, mixed> $res
     * @return list<array<string, mixed>>
     */
    private static function badgeRecordMembers(array $res): array
    {
        $candidates = [
            $res['data']['members'] ?? null,
            $res['members'] ?? null,
            $res['data']['data']['members'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (!is_array($c) || $c === []) {
                continue;
            }
            $rows = array_is_list($c) ? $c : array_values($c);
            $out = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        // last resort: flat list parsers
        return OsmLists::items($res);
    }

    /** Member id from badge record row (Jon capture: member_id). */
    private static function memberIdFromRow(array $row): string
    {
        foreach (['member_id', 'memberid', 'scoutid', 'scout_id'] as $k) {
            if (isset($row[$k]) && (string) $row[$k] !== '') {
                return (string) $row[$k];
            }
        }
        return '';
    }

    /** @param array<string, mixed> $b @param array<string, mixed> $debugMeta */
    private static function parseAward(array $b, array &$debugMeta): ?array
    {
        // Jon: awardeddate is the authority. Empty awardeddate = not awarded for this calc.
        // Prefer key awardeddate; awarded (0|1 / level) is secondary for level / soft check.
        $completedRaw = $b['completed'] ?? null;

        $dateKeys = ['awardeddate', 'awarded_date', 'date_awarded', 'awardedDate', 'dateawarded'];
        $dateStr = '';
        foreach ($dateKeys as $k) {
            if (!array_key_exists($k, $b)) {
                continue;
            }
            $v = trim((string) $b[$k]);
            if ($v !== '' && $v !== '0000-00-00') {
                $dateStr = $v;
                if (!in_array($k, $debugMeta['awardedDateKeysSeen'], true)) {
                    $debugMeta['awardedDateKeysSeen'][] = $k;
                }
                break;
            }
        }
        if ($dateStr === '') {
            return null;
        }

        $awardedRaw = $b['awarded'] ?? $b['awarded_level'] ?? null;
        $level = 1;
        if (is_numeric($awardedRaw) && (int) $awardedRaw > 0) {
            $level = (int) $awardedRaw;
        } elseif (is_string($awardedRaw) && is_numeric(trim($awardedRaw)) && (int) trim($awardedRaw) > 0) {
            $level = (int) trim($awardedRaw);
        } elseif ($awardedRaw === true || $awardedRaw === '1') {
            $level = 1;
        }
        // If awarded is explicitly 0/false but date present, still count (date is authority).
        return [
            'badge_id' => (string) ($b['badge_id'] ?? $b['badgeid'] ?? ''),
            'level' => $level,
            'awarded_date' => $dateStr,
            'completed' => $completedRaw,
        ];
    }

    /** @param array<string, list<array<string, mixed>>> $awardsByMember */
    private static function needsTypeFilter(array $awardsByMember): bool
    {
        foreach ($awardsByMember as $list) {
            foreach ($list as $a) {
                if ((int) ($a['type_id'] ?? 0) === 0) {
                    return true;
                }
            }
        }
        // empty → need records path
        return $awardsByMember === [];
    }

    private static function goldString(int $count, int $threshold): string
    {
        $n = $count;
        if ($n >= $threshold) {
            return $n . '/' . $threshold;
        }
        return 'x ' . $n . '/' . $threshold;
    }

    private static function isYoungLeaderPatrol(string $patrol): bool
    {
        $p = strtolower($patrol);
        if ($p === '') {
            return false;
        }
        if (str_contains($p, 'young leader')) {
            return true;
        }
        if ($p === 'yl' || $p === 'yls' || str_starts_with($p, 'yl ')) {
            return true;
        }
        return false;
    }

    private static function isLeaderPatrol(string $patrol): bool
    {
        return trim($patrol) === 'Leaders';
    }

    /** @param array<string, mixed> $ind @return array<string, mixed> */
    private static function individualPayload(array $ind): array
    {
        if (isset($ind['data']) && is_array($ind['data'])) {
            $d = $ind['data'];
            if (isset($d['data']) && is_array($d['data'])) {
                return $d['data'];
            }
            return $d;
        }
        return $ind;
    }

    /** @param array<string, mixed> $payload */
    private static function writeDryRun(array $payload): void
    {
        $dir = dirname(__DIR__, 3) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $path = $dir . '/top-awards-dryrun.json';
        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
        @chmod($path, 0660);
    }
}
