<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\App;
use App\Http\Auth;
use App\Http\Csrf;
use App\Osm\OsmApi;
use App\Osm\OsmDebug;
use App\Osm\OsmLists;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Nights away from OSM's staged Nights Away badge (badge_id 94, type_id 3).
 * Jon Network capture (Scouts 7711): getBadgeRecords + payload=1, plus
 * GET /v3/badges/records/other-sections?badge_id=94&member_id=&section_id=
 */
final class NightsAwayController
{
    private const CACHE_TTL_SEC = 7200;
    private const RATE_LOW_REMAINING = 40;
    private const RECORDS_DELAY_US = 150000;
    private const SESSION_KEY = 'nightsAwayBadgeIndex_v4';

    /** OSM Nights Away staged activity badge (UK). */
    private const BADGE_ID = '94';
    private const BADGE_VERSION = '0';
    private const TYPE_STAGED = 3;

    /** Youth section types that hold the staged badge. */
    private const YOUTH_TYPES = ['earlyyears', 'beavers', 'cubs', 'scouts', 'explorers'];

    /** @var list<string> */
    private const META_FIELDS = [
        'scoutid', 'scout_id', 'member_id', 'memberid', 'id',
        'firstname', 'first_name', 'lastname', 'last_name',
        'patrol', 'patrolid', 'patrolleader', 'photo_guid', 'pic',
        'sectionid', 'section_id', 'enddate', 'age', 'active',
        'completed', 'awarded', 'awardeddate', 'awarded_date', 'dateawarded',
        'awarded_level', 'awardedlevel', 'level', 'stage',
        'due', 'badgecompleted', 'patrol_role_level_label',
        'badge_id', 'badge_version', 'type_id',
        'section_name', 'sectionname', 'section_type', 'sectiontype',
        'group_name', 'groupname', 'badge_identifier',
        'columns', 'structure', 'records', 'values', 'fields',
        // Staged-badge bookkeeping — never camp nights.
        'eligible', 'eligibility', 'progress', 'percentage', 'pct',
        'module', 'modules', 'challenge', 'challenges',
        'initial', 'preosm', 'pre_osm', 'started', 'startedsection',
    ];

    /** Prefer the member's current highest section as the v3 other-sections context. */
    private const TYPE_RANK = [
        'explorers' => 5,
        'scouts' => 4,
        'cubs' => 3,
        'beavers' => 2,
        'earlyyears' => 1,
    ];

    public function index(): void
    {
        $token = Auth::requireLogin();
        $scoutIds = self::requestedScoutIds();
        $forceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';
        $this->renderPage($token, $scoutIds, $forceRefresh);
    }

    public function select(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $ids = [];
        $raw = $_POST['scoutids'] ?? $_POST['scoutid'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }
        foreach ($raw as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        $qs = $ids === [] ? '' : ('?scoutids=' . rawurlencode(implode(',', $ids)));
        header('Location: /nights-away/' . $qs, true, 303);
        exit;
    }

    /** @return list<string> */
    private static function requestedScoutIds(): array
    {
        $ids = [];
        if (isset($_GET['scoutids'])) {
            $raw = $_GET['scoutids'];
            if (is_array($raw)) {
                foreach ($raw as $id) {
                    $id = trim((string) $id);
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            } else {
                foreach (explode(',', (string) $raw) as $id) {
                    $id = trim($id);
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            }
        }
        if (isset($_GET['scoutid'])) {
            $raw = $_GET['scoutid'];
            if (is_array($raw)) {
                foreach ($raw as $id) {
                    $id = trim((string) $id);
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            } else {
                $id = trim((string) $raw);
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /** @param list<string> $scoutIds */
    private function renderPage(string $token, array $scoutIds, bool $forceRefresh): void
    {
        $api = new OsmApi();
        $error = null;
        $scopeHint = null;
        $rateLimitBanner = null;
        $notes = [];
        $members = [];
        $member = null;
        $selectedMembers = [];
        $memberSummaries = [];
        $rows = [];
        $yearTotals = [];
        $totalNights = 0;
        $listedNights = 0;
        $osmTotal = null;
        $awarded = null;
        $awardedDate = '';
        $fromCache = false;
        $cacheAt = null;
        $incomplete = false;
        $apiCalls = 0;
        $scoutid = $scoutIds[0] ?? '';
        $scoutIdsQuery = implode(',', $scoutIds);

        try {
            $members = self::loadMembers($api, $token);
        } catch (Throwable $e) {
            $error = 'Could not load members from OSM.';
            OsmDebug::log('nights_away_members_fail', ['message' => $e->getMessage()]);
        }

        if ($scoutIds !== []) {
            $byId = [];
            foreach ($members as $m) {
                $byId[(string) ($m['scoutid'] ?? '')] = $m;
            }
            foreach ($scoutIds as $sid) {
                if (isset($byId[$sid])) {
                    $selectedMembers[] = $byId[$sid];
                } else {
                    $selectedMembers[] = [
                        'scoutid' => $sid,
                        'member_id' => $sid,
                        'ids' => [$sid],
                        'firstname' => '',
                        'lastname' => '',
                        'sections' => '',
                        'sectionMeta' => [],
                    ];
                }
            }
            $member = $selectedMembers[0];

            try {
                $rateLow = $api->isRateLow(self::RATE_LOW_REMAINING);
                $cached = null;
                if ($forceRefresh && $rateLow) {
                    $cached = self::readIndex();
                    $rateLimitBanner = 'Refresh skipped — OSM quota is low (see remaining on Home). Showing cache when available.';
                } elseif (!$forceRefresh) {
                    $cached = self::readIndex();
                }

                if (is_array($cached)) {
                    $fromCache = true;
                    $cacheAt = $cached['atLabel'] ?? null;
                    $incomplete = !empty($cached['incomplete']);
                    $apiCalls = (int) ($cached['apiCalls'] ?? 0);
                    $notes = is_array($cached['notes'] ?? null) ? $cached['notes'] : [];
                    $byMember = is_array($cached['byMember'] ?? null) ? $cached['byMember'] : [];
                } else {
                    $built = self::buildIndex($api, $token, $notes, $rateLimitBanner, $scopeHint);
                    $apiCalls = $built['apiCalls'];
                    $incomplete = $built['incomplete'];
                    self::writeIndex($built);
                    $byMember = $built['byMember'];
                }

                foreach ($selectedMembers as $i => $sel) {
                    $sid = (string) ($sel['scoutid'] ?? '');
                    if ($sid === '') {
                        continue;
                    }
                    $ids = self::idsOf($sel);
                    $current = self::lookupParsed($byMember, $sel);
                    // Always pull this member's current section badge grid(s). The group
                    // session cache can miss a section (quota stop / order), which drops
                    // Discovery (etc.) camps even when OSM shows them on the open section.
                    if (!$api->isRateLow(self::RATE_LOW_REMAINING)) {
                        if ($i > 0) {
                            usleep(self::RECORDS_DELAY_US);
                        }
                        $live = self::fetchMemberCurrentSections(
                            $api,
                            $token,
                            $sel,
                            $apiCalls,
                            $notes,
                            $scopeHint
                        );
                        if ($live !== null) {
                            $current = $current === null ? $live : self::mergeMember($current, $live);
                        }
                    }
                    $ctxSectionId = self::primarySectionId($sel);
                    if ($ctxSectionId !== '' && !$api->isRateLow(self::RATE_LOW_REMAINING)) {
                        usleep(self::RECORDS_DELAY_US);
                        $extra = null;
                        foreach ($ids as $tryId) {
                            $extra = self::fetchOtherSections(
                                $api,
                                $token,
                                $tryId,
                                $ctxSectionId,
                                $apiCalls,
                                $notes,
                                $scopeHint
                            );
                            if ($extra !== null && ($extra['entries'] ?? []) !== []) {
                                break;
                            }
                        }
                        if ($extra !== null) {
                            $current = $current === null ? $extra : self::mergeMember($current, $extra);
                        }
                    } elseif ($ctxSectionId === '') {
                        $notes[] = 'No current section id for a selected member — skipped other-sections records.';
                    }
                    if ($current !== null) {
                        self::storeParsed($byMember, $current, $ids);
                    }

                    $filtered = self::rowsForMember($byMember, $sid, $sel);
                    $label = trim($filtered['firstname'] . ' ' . $filtered['lastname']);
                    if ($label === '') {
                        $label = 'Member ' . $sid;
                    }
                    if (($sel['firstname'] ?? '') === '' && $filtered['firstname'] !== '') {
                        $selectedMembers[$i]['firstname'] = $filtered['firstname'];
                        $selectedMembers[$i]['lastname'] = $filtered['lastname'];
                    }
                    $memberSummaries[] = [
                        'scoutid' => $sid,
                        'name' => $label,
                        'sections' => (string) ($sel['sections'] ?? ''),
                        'totalNights' => $filtered['totalNights'],
                        'listedNights' => $filtered['listedNights'],
                        'eventCount' => count($filtered['rows']),
                        'awarded' => $filtered['awarded'],
                        'awardedDate' => $filtered['awardedDate'],
                        'osmTotal' => $filtered['osmTotal'],
                    ];
                    $memberSort = strtolower(trim($filtered['lastname'] . ', ' . $filtered['firstname']));
                    if ($memberSort === ',' || $memberSort === '') {
                        $memberSort = strtolower($label);
                    }
                    foreach ($filtered['rows'] as $row) {
                        $row['memberName'] = $label;
                        $row['memberSort'] = $memberSort;
                        $row['scoutid'] = $sid;
                        $rows[] = $row;
                    }
                    $totalNights += $filtered['totalNights'];
                    $listedNights += $filtered['listedNights'];
                    foreach ($filtered['yearTotals'] as $y => $n) {
                        $yearTotals[(string) $y] = ($yearTotals[(string) $y] ?? 0) + (int) $n;
                    }
                }

                usort($rows, static function (array $a, array $b): int {
                    // Multi-member: group by member, then newest year, then camp name.
                    $mn = strcasecmp(
                        (string) ($a['memberSort'] ?? $a['memberName'] ?? ''),
                        (string) ($b['memberSort'] ?? $b['memberName'] ?? '')
                    );
                    if ($mn !== 0) {
                        return $mn;
                    }
                    $ya = (string) ($a['year'] ?? '');
                    $yb = (string) ($b['year'] ?? '');
                    $aNum = $ya !== '' && $ya !== '—' && ctype_digit($ya);
                    $bNum = $yb !== '' && $yb !== '—' && ctype_digit($yb);
                    if ($aNum && $bNum) {
                        $yc = ((int) $yb) <=> ((int) $ya);
                        if ($yc !== 0) {
                            return $yc;
                        }
                    } elseif ($aNum !== $bNum) {
                        return $aNum ? -1 : 1; // numbered years before "No year"
                    }
                    return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                });
                krsort($yearTotals, SORT_STRING);

                $member = $selectedMembers[0];
                if (count($selectedMembers) === 1 && $memberSummaries !== []) {
                    $osmTotal = $memberSummaries[0]['osmTotal'];
                    $awarded = $memberSummaries[0]['awarded'];
                    $awardedDate = $memberSummaries[0]['awardedDate'];
                }
            } catch (Throwable $e) {
                error_log('nights-away scoutids=' . implode(',', $scoutIds) . ' ' . $e->getMessage()
                    . ' @ ' . $e->getFile() . ':' . $e->getLine());
                OsmDebug::log('nights_away_fatal', [
                    'scoutid' => $scoutid,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                $error = 'Could not load nights away for this member (' . $e->getMessage() . ').';
            }
        }

        App::render('nights-away.twig', Auth::baseContext([
            'title' => 'Nights away',
            'members' => $members,
            'scoutid' => $scoutid,
            'scoutIds' => $scoutIds,
            'scoutIdsQuery' => $scoutIdsQuery,
            'member' => $member,
            'selectedMembers' => $selectedMembers,
            'memberSummaries' => $memberSummaries,
            'multi' => count($selectedMembers) > 1,
            'rows' => $rows,
            'yearTotals' => $yearTotals,
            'yearKeys' => array_keys($yearTotals),
            'totalNights' => $totalNights,
            'listedNights' => $listedNights,
            'osmTotal' => $osmTotal,
            'awarded' => $awarded,
            'awardedDate' => $awardedDate,
            'eventCount' => count($rows),
            'error' => $error,
            'scopeHint' => $scopeHint,
            'rateLimitBanner' => $rateLimitBanner,
            'notes' => $notes,
            'fromCache' => $fromCache,
            'cacheAt' => $cacheAt,
            'incomplete' => $incomplete,
            'apiCalls' => $apiCalls,
            'cacheTtlMinutes' => (int) (self::CACHE_TTL_SEC / 60),
            'needsBadgeScope' => is_string($scopeHint) && $scopeHint !== '',
        ]));
    }

    /**
     * @return list<array{scoutid:string,firstname:string,lastname:string,sections:string,sectionMeta:list<array{id:string,name:string,type:string}>}>
     */
    private static function loadMembers(OsmApi $api, string $token): array
    {
        $sections = $api->getDynamicSections($token);
        /** @var array<string, array{scoutid:string,firstname:string,lastname:string,sectionNames:list<string>,sectionMeta:list<array{id:string,name:string,type:string}>}> $all */
        $all = [];
        /** @var array<string, true> $youngLeaderIds scoutids seen in a Young Leaders / YL patrol in any section */
        $youngLeaderIds = [];
        foreach ($sections as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $sectionType = (string) ($sec['section_type'] ?? 'unknown');
            if (!in_array($sectionType, self::YOUTH_TYPES, true)) {
                continue;
            }
            $sectionId = (string) ($sec['section_id'] ?? $sec['id'] ?? '');
            if ($sectionId === '') {
                continue;
            }
            $sectionName = (string) ($sec['section_name'] ?? $sec['name'] ?? '');
            $termId = $sec['current_term_id'] ?? -1;
            try {
                $res = $api->get($token, '/ext/members/contact/', [
                    'action' => 'getListOfMembers',
                    'sectionid' => $sectionId,
                    'termid' => $termId,
                    'section' => $sectionType,
                    'sort' => 'lastname',
                ]);
                $list = OsmLists::items($res);
            } catch (Throwable) {
                continue;
            }
            foreach ($list as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $id = (string) ($raw['scoutid'] ?? $raw['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $patrol = (string) ($raw['patrol'] ?? $raw['patrol_name'] ?? '');
                // Same patrol rules as Members / Top awards (isYoungLeaderPatrol / isLeaderPatrol).
                // Drop Leaders rows. Mark Young Leaders even when they also appear as youth in another section (e.g. Explorers).
                if (self::isLeaderPatrol($patrol)) {
                    continue;
                }
                if (self::isYoungLeaderPatrol($patrol)) {
                    $youngLeaderIds[$id] = true;
                    unset($all[$id]);
                    continue;
                }
                if (isset($youngLeaderIds[$id])) {
                    continue;
                }
                $ids = self::idsFromRow($raw);
                if (!isset($all[$id])) {
                    $all[$id] = [
                        'scoutid' => $id,
                        'member_id' => self::firstString($raw['member_id'] ?? null, $raw['memberid'] ?? null, $id),
                        'ids' => $ids,
                        'firstname' => (string) ($raw['firstname'] ?? ''),
                        'lastname' => (string) ($raw['lastname'] ?? ''),
                        'sectionNames' => [],
                        'sectionMeta' => [],
                    ];
                } else {
                    $all[$id]['ids'] = array_values(array_unique(array_merge($all[$id]['ids'] ?? [$id], $ids)));
                    if (($all[$id]['member_id'] ?? '') === '' || ($all[$id]['member_id'] ?? '') === $id) {
                        $mid = self::firstString($raw['member_id'] ?? null, $raw['memberid'] ?? null);
                        if ($mid !== '') {
                            $all[$id]['member_id'] = $mid;
                        }
                    }
                    if ($all[$id]['firstname'] === '' && !empty($raw['firstname'])) {
                        $all[$id]['firstname'] = (string) $raw['firstname'];
                    }
                    if ($all[$id]['lastname'] === '' && !empty($raw['lastname'])) {
                        $all[$id]['lastname'] = (string) $raw['lastname'];
                    }
                }
                if ($sectionName !== '' && !in_array($sectionName, $all[$id]['sectionNames'], true)) {
                    $all[$id]['sectionNames'][] = $sectionName;
                }
                $seenId = false;
                foreach ($all[$id]['sectionMeta'] as $sm) {
                    if (($sm['id'] ?? '') === $sectionId) {
                        $seenId = true;
                        break;
                    }
                }
                if (!$seenId) {
                    $all[$id]['sectionMeta'][] = [
                        'id' => $sectionId,
                        'name' => $sectionName,
                        'type' => $sectionType,
                        'termId' => (string) $termId,
                    ];
                }
            }
        }
        $out = [];
        foreach ($all as $m) {
            $sid = (string) ($m['scoutid'] ?? '');
            if ($sid !== '' && isset($youngLeaderIds[$sid])) {
                continue;
            }
            $out[] = [
                'scoutid' => $m['scoutid'],
                'member_id' => $m['member_id'] ?? $m['scoutid'],
                'ids' => $m['ids'] ?? [$m['scoutid']],
                'firstname' => $m['firstname'],
                'lastname' => $m['lastname'],
                'sections' => implode(', ', $m['sectionNames']),
                'sectionMeta' => $m['sectionMeta'],
            ];
        }
        usort($out, static function (array $a, array $b): int {
            $ln = strcasecmp($a['lastname'], $b['lastname']);
            return $ln !== 0 ? $ln : strcasecmp($a['firstname'], $b['firstname']);
        });
        return $out;
    }

    /**
     * @param list<string> $notes
     * @return array{at:int,atLabel:string,byMember:array<string,array<string,mixed>>,incomplete:bool,apiCalls:int,notes:list<string>}
     */
    private static function buildIndex(
        OsmApi $api,
        string $token,
        array &$notes,
        ?string &$rateLimitBanner,
        ?string &$scopeHint
    ): array {
        /** @var array<string, array<string, mixed>> $byMember */
        $byMember = [];
        $apiCalls = 0;
        $incomplete = false;

        try {
            $sections = $api->getDynamicSections($token);
            $apiCalls++;
        } catch (Throwable $e) {
            $notes[] = 'Could not load sections: ' . $e->getMessage();
            $at = time();
            $london = new DateTimeZone('Europe/London');
            return [
                'at' => $at,
                'atLabel' => (new DateTimeImmutable('@' . $at))->setTimezone($london)->format('d/m/y H:i'),
                'byMember' => [],
                'incomplete' => true,
                'apiCalls' => $apiCalls,
                'notes' => $notes,
            ];
        }

        foreach ($sections as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $sectionType = (string) ($sec['section_type'] ?? 'unknown');
            if (!in_array($sectionType, self::YOUTH_TYPES, true)) {
                continue;
            }
            $sectionId = (string) ($sec['section_id'] ?? $sec['id'] ?? '');
            if ($sectionId === '') {
                continue;
            }
            $sectionName = (string) ($sec['section_name'] ?? $sec['name'] ?? '');
            $termId = $sec['current_term_id'] ?? -1;
            if ($termId === '' || (string) $termId === '-1') {
                $notes[] = $sectionName . ' has no current term — skipped Nights Away badge.';
                continue;
            }

            if ($api->isRateLow(self::RATE_LOW_REMAINING)) {
                $incomplete = true;
                $rateLimitBanner = 'Stopped loading further sections — OSM quota is low. Partial results shown.';
                break;
            }

            try {
                $res = $api->get($token, '/ext/badges/records/', [
                    'action' => 'getBadgeRecords',
                    'term_id' => $termId,
                    'section' => $sectionType,
                    'badge_id' => self::BADGE_ID,
                    'section_id' => $sectionId,
                    'sectionid' => $sectionId,
                    'badge_version' => self::BADGE_VERSION,
                    'payload' => 1,
                    'type_id' => self::TYPE_STAGED,
                ]);
                $apiCalls++;
            } catch (Throwable $e) {
                $apiCalls++;
                $msg = $e->getMessage();
                if (self::looksLikeScopeError($msg)) {
                    $scopeHint = $msg;
                }
                $notes[] = 'Nights Away badge records failed for ' . ($sectionName !== '' ? $sectionName : $sectionId) . '.';
                OsmDebug::log('nights_away_records_fail', [
                    'section_id' => $sectionId,
                    'message' => $msg,
                ]);
                continue;
            }

            $campLabels = self::requirementCampMap($res);
            $items = self::badgeRecordMembers($res);
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $ids = self::idsFromRow($row);
                $sid = $ids[0] ?? '';
                if ($sid === '') {
                    continue;
                }
                try {
                    $parsed = self::parseBadgeRecordsMember($row, $campLabels, $sectionName, $sectionType);
                } catch (Throwable $e) {
                    $notes[] = 'Skipped a badge row in ' . $sectionName . ': ' . $e->getMessage();
                    continue;
                }
                $parsed['scoutid'] = $sid;
                $parsed['ids'] = $ids;
                $existing = self::lookupParsed($byMember, ['ids' => $ids, 'firstname' => $parsed['firstname'] ?? '', 'lastname' => $parsed['lastname'] ?? '']);
                if ($existing !== null) {
                    $parsed = self::mergeMember($existing, $parsed);
                    $parsed['ids'] = array_values(array_unique(array_merge($existing['ids'] ?? [], $ids)));
                }
                self::storeParsed($byMember, $parsed, $parsed['ids']);
            }
            usleep(self::RECORDS_DELAY_US);
        }

        $at = time();
        $london = new DateTimeZone('Europe/London');
        return [
            'at' => $at,
            'atLabel' => (new DateTimeImmutable('@' . $at))->setTimezone($london)->format('d/m/y H:i'),
            'byMember' => $byMember,
            'incomplete' => $incomplete,
            'apiCalls' => $apiCalls,
            'notes' => $notes,
        ];
    }

    /**
     * Load badge 94 grids for sections this member is currently in, and keep only
     * that member's camp rows. Fills gaps when the group-wide session cache skipped
     * a section.
     *
     * @param array<string, mixed> $member
     * @param list<string> $notes
     * @return array<string, mixed>|null
     */
    private static function fetchMemberCurrentSections(
        OsmApi $api,
        string $token,
        array $member,
        int &$apiCalls,
        array &$notes,
        ?string &$scopeHint
    ): ?array {
        $meta = is_array($member['sectionMeta'] ?? null) ? $member['sectionMeta'] : [];
        if ($meta === []) {
            return null;
        }
        $wantIds = self::idsOf($member);
        $wantIdsLookup = [];
        foreach ($wantIds as $id) {
            $wantIdsLookup[$id] = true;
        }
        $fn = strtolower(trim(self::asString($member['firstname'] ?? '')));
        $ln = strtolower(trim(self::asString($member['lastname'] ?? '')));
        $merged = null;
        foreach ($meta as $sm) {
            if (!is_array($sm)) {
                continue;
            }
            $sectionId = (string) ($sm['id'] ?? '');
            $sectionType = (string) ($sm['type'] ?? '');
            $sectionName = (string) ($sm['name'] ?? '');
            $termId = $sm['termId'] ?? -1;
            if ($sectionId === '' || !in_array($sectionType, self::YOUTH_TYPES, true)) {
                continue;
            }
            if ($termId === '' || (string) $termId === '-1') {
                $notes[] = ($sectionName !== '' ? $sectionName : $sectionId)
                    . ' has no current term — skipped live Nights Away fetch.';
                continue;
            }
            if ($api->isRateLow(self::RATE_LOW_REMAINING)) {
                $notes[] = 'Stopped live section fetch — OSM quota is low.';
                break;
            }
            try {
                $res = $api->get($token, '/ext/badges/records/', [
                    'action' => 'getBadgeRecords',
                    'term_id' => $termId,
                    'section' => $sectionType,
                    'badge_id' => self::BADGE_ID,
                    'section_id' => $sectionId,
                    'sectionid' => $sectionId,
                    'badge_version' => self::BADGE_VERSION,
                    'payload' => 1,
                    'type_id' => self::TYPE_STAGED,
                ]);
                $apiCalls++;
            } catch (Throwable $e) {
                $apiCalls++;
                $msg = $e->getMessage();
                if (self::looksLikeScopeError($msg)) {
                    $scopeHint = $msg;
                }
                $notes[] = 'Live Nights Away records failed for '
                    . ($sectionName !== '' ? $sectionName : $sectionId) . '.';
                continue;
            }
            $campLabels = self::requirementCampMap($res);
            $found = false;
            foreach (self::badgeRecordMembers($res) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $ids = self::idsFromRow($row);
                $hit = false;
                foreach ($ids as $id) {
                    if (isset($wantIdsLookup[$id])) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit && $fn !== '' && $ln !== '') {
                    $rfn = strtolower(trim(self::asString($row['firstname'] ?? $row['first_name'] ?? '')));
                    $rln = strtolower(trim(self::asString($row['lastname'] ?? $row['last_name'] ?? '')));
                    $hit = ($rfn === $fn && $rln === $ln);
                }
                if (!$hit) {
                    continue;
                }
                $found = true;
                try {
                    $parsed = self::parseBadgeRecordsMember($row, $campLabels, $sectionName, $sectionType);
                } catch (Throwable $e) {
                    $notes[] = 'Skipped live badge row in ' . $sectionName . ': ' . $e->getMessage();
                    continue;
                }
                $parsed['scoutid'] = $ids[0] ?? (string) ($member['scoutid'] ?? '');
                $parsed['ids'] = array_values(array_unique(array_merge($ids, $wantIds)));
                $merged = $merged === null ? $parsed : self::mergeMember($merged, $parsed);
            }
            if (!$found) {
                $notes[] = 'No badge-94 row for this member in '
                    . ($sectionName !== '' ? $sectionName : $sectionId) . '.';
            }
            usleep(self::RECORDS_DELAY_US);
        }
        return $merged;
    }

    /**
     * OSM v3: nights (and other staged badge cells) recorded in sections this
     * member used to be in. Context section_id is the section you are viewing from.
     *
     * @param list<string> $notes
     * @return array<string, mixed>|null
     */
    private static function fetchOtherSections(
        OsmApi $api,
        string $token,
        string $memberId,
        string $sectionId,
        int &$apiCalls,
        array &$notes,
        ?string &$scopeHint
    ): ?array {
        try {
            $res = $api->get($token, '/v3/badges/records/other-sections', [
                'badge_id' => self::BADGE_ID,
                'member_id' => $memberId,
                'section_id' => $sectionId,
            ]);
            $apiCalls++;
        } catch (Throwable $e) {
            $apiCalls++;
            $msg = $e->getMessage();
            if (self::looksLikeScopeError($msg)) {
                $scopeHint = $msg;
            }
            $notes[] = 'Other-sections Nights Away records failed.';
            OsmDebug::log('nights_away_other_sections_fail', [
                'section_id' => $sectionId,
                'scoutid' => $memberId,
                'message' => $msg,
            ]);
            return null;
        }

        OsmDebug::log('nights_away_other_sections', [
            'section_id' => $sectionId,
            'scoutid' => $memberId,
            'top_keys' => array_keys($res),
            'data_keys' => is_array($res['data'] ?? null) ? array_keys($res['data']) : null,
        ]);

        $merged = null;
        try {
            foreach (self::collectCampsFromTree($res, 'Other section', 0) as $parsed) {
                $merged = $merged === null ? $parsed : self::mergeMember($merged, $parsed);
            }
        } catch (Throwable $e) {
            $notes[] = 'Other-sections parse failed: ' . $e->getMessage();
        }
        if ($merged === null) {
            $chunks = self::otherSectionChunks($res);
            foreach ($chunks as $blob) {
                try {
                    $parsed = self::flattenOtherSection($blob);
                } catch (Throwable $e) {
                    $notes[] = 'Skipped an other-sections row: ' . $e->getMessage();
                    continue;
                }
                if ($parsed === null) {
                    continue;
                }
                $merged = $merged === null ? $parsed : self::mergeMember($merged, $parsed);
            }
        }
        if ($merged === null && $res !== []) {
            $notes[] = 'OSM other-sections returned data but no camp night rows were parsed (keys: '
                . implode(', ', array_slice(array_map('strval', array_keys($res)), 0, 12)) . ').';
        }
        return $merged;
    }

    /**
     * Walk OSM's other-sections JSON for camp rows (name + nights + section subtitle).
     *
     * @return list<array<string, mixed>>
     */
    private static function collectCampsFromTree(mixed $node, string $sectionFallback, int $depth): array
    {
        if ($depth > 10 || !is_array($node) || $node === []) {
            return [];
        }
        if (isset($node['attributes']) && is_array($node['attributes'])) {
            $node = array_merge($node, $node['attributes']);
        }
        $sectionHere = self::otherSectionLabel($node);
        $label = ($sectionHere !== '' && $sectionHere !== 'Other section') ? $sectionHere : $sectionFallback;
        $out = [];
        $childCamps = [];
        foreach ($node as $k => $v) {
            if (!is_array($v)) {
                continue;
            }
            $kl = strtolower((string) $k);
            if (in_array($kl, ['meta', 'links', 'permissions', 'error', 'attributes'], true)) {
                continue;
            }
            foreach (self::collectCampsFromTree($v, $label, $depth + 1) as $parsed) {
                $childCamps[] = $parsed;
            }
        }
        // Prefer named child camps over a nameless/aggregate parent with a nights total.
        if ($childCamps !== []) {
            return $childCamps;
        }
        $self = self::campRowToMember($node, $label);
        if ($self !== null) {
            $out[] = $self;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $res
     * @return list<array<string, mixed>>
     */
    private static function otherSectionChunks(array $res): array
    {
        $payload = $res['data'] ?? $res;
        if (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }
        if (!is_array($payload) || $payload === []) {
            return [];
        }
        foreach (['other_sections', 'sections', 'items', 'records', 'camps', 'events', 'entries', 'columns', 'nights_away'] as $k) {
            if (isset($payload[$k]) && is_array($payload[$k]) && $payload[$k] !== []) {
                $payload = $payload[$k];
                break;
            }
        }
        if ($payload === []) {
            return [];
        }
        $vals = array_is_list($payload) ? $payload : array_values($payload);
        $out = [];
        foreach ($vals as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * OSM Other Sections UI is a list of camps: name, section subtitle, nights.
     * e.g. "Summer Camp 2024" / "4th Ashby de la Zouch: Seeonnee Cubs (Thursday)" / 6
     *
     * @param array<string, mixed> $blob
     * @return array<string, mixed>|null
     */
    private static function flattenOtherSection(array $blob): ?array
    {
        $sectionLabel = self::otherSectionLabel($blob);
        foreach (['records', 'columns', 'events', 'camps', 'entries', 'items', 'nights_away', 'data'] as $k) {
            $inner = $blob[$k] ?? null;
            if (!is_array($inner) || $inner === [] || !array_is_list($inner)) {
                continue;
            }
            if (!self::looksLikeCampList($inner)) {
                continue;
            }
            $merged = null;
            foreach ($inner as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $parsed = self::campRowToMember($row, $sectionLabel);
                if ($parsed === null) {
                    continue;
                }
                $merged = $merged === null ? $parsed : self::mergeMember($merged, $parsed);
            }
            if ($merged !== null) {
                return $merged;
            }
        }

        if (self::looksLikeCampRow($blob)) {
            return self::campRowToMember($blob, $sectionLabel);
        }

        // Fallback: classic badge-grid payload (custom_* columns).
        $sectionType = self::firstString(
            $blob['section_type'] ?? null,
            $blob['sectiontype'] ?? null,
            $blob['type'] ?? null,
        );
        $labels = self::columnLabels($blob);
        $record = null;
        foreach (['records', 'values', 'member', 'item', 'fields', 'badge_records'] as $k) {
            $cand = $blob[$k] ?? null;
            if (is_array($cand) && $cand !== [] && !array_is_list($cand)) {
                $looksNested = isset($cand['records']) || isset($cand['columns']) || isset($cand['structure']);
                if ($looksNested) {
                    continue;
                }
                $record = $cand;
                break;
            }
        }
        if ($record === null) {
            $record = $blob;
        }
        $parsed = self::parseMemberRow($record, $labels, $sectionLabel !== '' ? $sectionLabel : 'Other section', $sectionType);
        if (($parsed['entries'] ?? []) === [] && ($parsed['osmTotal'] ?? null) === null) {
            return null;
        }
        return $parsed;
    }

    /** @param list<mixed> $list */
    private static function looksLikeCampList(array $list): bool
    {
        $n = 0;
        $hits = 0;
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $n++;
            if (self::looksLikeCampRow($row)) {
                $hits++;
            }
            if ($n >= 8) {
                break;
            }
        }
        return $n > 0 && $hits >= (int) max(1, (int) ceil($n / 2));
    }

    /** @param array<string, mixed> $blob */
    private static function looksLikeCampRow(array $blob): bool
    {
        $nights = self::nightsFrom($blob);
        if ($nights === null || $nights < 1) {
            return false;
        }
        $name = self::campNameFrom($blob);
        if ($name !== '' && self::isNonCampMetaLabel('', $name)) {
            return false;
        }
        // Named camp leaf (OSM other-sections UI).
        if ($name !== '') {
            return true;
        }
        // Unnamed only if it still looks like a badge column cell, not a container.
        $field = self::firstString($blob['column_id'] ?? null, $blob['field'] ?? null, $blob['id'] ?? null);
        if ($field !== '' && preg_match('/^(custom_|f_|col_)/i', $field)) {
            return true;
        }
        return false;
    }

    /** @param array<string, mixed> $blob */
    private static function campNameFrom(array $blob): string
    {
        $nested = [];
        foreach (['column', 'event', 'badge_column', 'field', 'attributes'] as $k) {
            if (isset($blob[$k]) && is_array($blob[$k])) {
                $nested[] = $blob[$k]['column_name'] ?? null;
                $nested[] = $blob[$k]['name'] ?? null;
                $nested[] = $blob[$k]['label'] ?? null;
                $nested[] = $blob[$k]['title'] ?? null;
            }
        }
        return self::firstString(
            $blob['column_name'] ?? null,
            $blob['columnname'] ?? null,
            $blob['column_label'] ?? null,
            $blob['event_name'] ?? null,
            $blob['eventname'] ?? null,
            $blob['label'] ?? null,
            $blob['title'] ?? null,
            $blob['heading'] ?? null,
            $blob['raw_name'] ?? null,
            $blob['description'] ?? null,
            $blob['text'] ?? null,
            $blob['caption'] ?? null,
            // Prefer explicit name keys before generic "name" (often the section title).
            $blob['camp_name'] ?? null,
            $blob['campname'] ?? null,
            $blob['name'] ?? null,
            ...$nested,
        );
    }

    /** @param array<string, mixed> $blob */
    private static function nightsFrom(array $blob): ?int
    {
        foreach ([
            'nights', 'nights_away', 'nightsaway', 'night_count', 'nights_count',
            'num_nights', 'number_of_nights', 'value', 'data', 'count', 'amount',
            'text', 'content', 'number',
        ] as $k) {
            if (!array_key_exists($k, $blob)) {
                continue;
            }
            $n = self::nightsValue($blob[$k], self::campNameFrom($blob));
            if ($n !== null && $n >= 1) {
                return $n;
            }
        }
        foreach (['column', 'record', 'badge_record', 'attributes', 'pivot'] as $k) {
            if (isset($blob[$k]) && is_array($blob[$k])) {
                $n = self::nightsFrom($blob[$k]);
                if ($n !== null) {
                    return $n;
                }
            }
        }
        foreach ($blob as $k => $v) {
            $ks = (string) $k;
            if (self::isIdishKey($ks)) {
                continue;
            }
            if (in_array(strtolower($ks), self::META_FIELDS, true) || self::isNonCampMetaLabel($ks, '')) {
                continue;
            }
            $n = self::nightsValue($v, '');
            if ($n !== null && $n >= 1 && $n <= 400) {
                return $n;
            }
        }
        return null;
    }

    private static function isIdishKey(string $k): bool
    {
        $k = strtolower($k);
        return (bool) preg_match('/(^id$|_id$|id$|^order$|^width$|userid|user_id|patrol|term|badge|member|scout|column_id|sectionid)/', $k);
    }

    /**
     * Subtitle OSM shows under the camp: "Group: Section (day)".
     * Camp title lives in name — never use that as the section.
     *
     * @param array<string, mixed> $blob
     */
    private static function otherSectionLabel(array $blob): string
    {
        $section = is_array($blob['section'] ?? null) ? $blob['section'] : [];
        $groupObj = is_array($blob['group'] ?? null) ? $blob['group'] : [];
        $groupNested = is_array($section['group'] ?? null) ? $section['group'] : [];
        $group = self::firstString(
            $blob['group_name'] ?? null,
            $blob['groupname'] ?? null,
            is_array($blob['group'] ?? null) ? null : ($blob['group'] ?? null),
            $section['group_name'] ?? null,
            $section['groupname'] ?? null,
            $groupObj['name'] ?? null,
            $groupNested['name'] ?? null,
        );
        $sec = self::firstString(
            $blob['section_name'] ?? null,
            $blob['sectionname'] ?? null,
            $blob['section_label'] ?? null,
            $section['name'] ?? null,
            $section['section_name'] ?? null,
            is_array($blob['section'] ?? null) ? null : ($blob['section'] ?? null),
        );
        $day = self::firstString(
            $blob['meeting_day'] ?? null,
            $blob['meetingday'] ?? null,
            $section['meeting_day'] ?? null,
            $section['meeting_day_label'] ?? null,
        );
        if ($sec !== '' && $day !== '' && !str_contains($sec, $day)) {
            $sec .= ' (' . $day . ')';
        }
        if ($group !== '' && $sec !== '') {
            return $group . ': ' . $sec;
        }
        if ($sec !== '') {
            return $sec;
        }
        if ($group !== '') {
            return $group;
        }
        return 'Other section';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function campRowToMember(array $row, string $sectionFallback): ?array
    {
        if (!self::looksLikeCampRow($row)) {
            return null;
        }
        $nights = self::nightsFrom($row);
        if ($nights === null || $nights < 1) {
            return null;
        }
        $field = self::firstString($row['column_id'] ?? null, $row['field'] ?? null, $row['id'] ?? null);
        $name = self::campNameFrom($row);
        if ($name !== '' && self::isNonCampMetaLabel($field, $name)) {
            return null;
        }
        if ($name === '') {
            // Keep the night count when OSM omitted a title but left a column id.
            $name = $field !== '' ? ('Camp ' . $field) : 'Unnamed camp';
        }
        $sectionName = self::otherSectionLabel($row);
        if ($sectionName === 'Other section' && $sectionFallback !== '' && $sectionFallback !== 'Other section') {
            $sectionName = $sectionFallback;
        }
        // Never leave a vague "Other section" when the fallback is a real section name.
        if ($sectionName === 'Other section' && $sectionFallback !== '') {
            $sectionName = $sectionFallback;
        }
        $display = self::displayLabel($name);
        return [
            'firstname' => '',
            'lastname' => '',
            'osmTotal' => null,
            'awarded' => null,
            'awardedDate' => '',
            'entries' => [[
                'name' => $display,
                'nights' => $nights,
                'sectionName' => $sectionName,
                'sectionType' => self::firstString($row['section_type'] ?? null, $row['sectiontype'] ?? null),
                'year' => self::yearFromLabel($display),
                'field' => $field !== '' ? $field : $display,
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $member
     */
    private static function primarySectionId(array $member): string
    {
        $best = '';
        $bestRank = -1;
        foreach (is_array($member['sectionMeta'] ?? null) ? $member['sectionMeta'] : [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $id = (string) ($s['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $rank = self::TYPE_RANK[(string) ($s['type'] ?? '')] ?? 0;
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $id;
            }
        }
        return $best;
    }

    /**
     * @param array<string, mixed> $res
     * @return array<string, string> field => label
     */
    private static function columnLabels(array $res): array
    {
        $chunks = [
            $res['structure'] ?? null,
            $res['data']['structure'] ?? null,
            $res['badge_structure'] ?? null,
            $res['data']['badge_structure'] ?? null,
            $res['columns'] ?? null,
            $res['data']['columns'] ?? null,
            $res['badge']['structure'] ?? null,
            $res['data']['badge']['structure'] ?? null,
        ];
        $labels = [];
        foreach ($chunks as $chunk) {
            foreach (self::walkStructureRows($chunk) as $row) {
                $field = self::firstString(
                    $row['field'] ?? null,
                    $row['column_id'] ?? null,
                    $row['columnid'] ?? null,
                    $row['id'] ?? null,
                );
                $name = trim(self::firstString(
                    $row['name'] ?? null,
                    $row['column_name'] ?? null,
                    $row['columnname'] ?? null,
                    $row['label'] ?? null,
                    $row['raw_name'] ?? null,
                    $row['title'] ?? null,
                ));
                if ($field === '' || $name === '') {
                    continue;
                }
                if (self::isNonCampMetaLabel($field, $name) && !self::isTotalField($field, $name)) {
                    // still map totals for isTotalField lookups
                }
                $labels[$field] = $name;
            }
        }
        return $labels;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function walkStructureRows(mixed $node): array
    {
        if (!is_array($node) || $node === []) {
            return [];
        }
        $out = [];
        if (isset($node['field']) || isset($node['name'])) {
            $out[] = $node;
        }
        if (isset($node['rows']) && is_array($node['rows'])) {
            foreach ($node['rows'] as $r) {
                if (is_array($r)) {
                    $out = array_merge($out, self::walkStructureRows($r));
                }
            }
        }
        $skip = ['rows' => true, 'field' => true, 'name' => true, 'items' => true, 'members' => true];
        if (array_is_list($node) || self::isAssocList($node)) {
            foreach ($node as $k => $v) {
                if (isset($skip[$k])) {
                    continue;
                }
                if (is_array($v)) {
                    $out = array_merge($out, self::walkStructureRows($v));
                }
            }
        }
        return $out;
    }

    /** @param array<string|int, mixed> $node */
    private static function isAssocList(array $node): bool
    {
        foreach ($node as $v) {
            if (is_array($v)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function fetchStructureLabels(
        OsmApi $api,
        string $token,
        string $sectionType,
        string $sectionId,
        mixed $termId,
        int &$apiCalls
    ): array {
        try {
            $res = $api->get($token, '/ext/badges/records/', [
                'action' => 'getBadgeStructure',
                'section' => $sectionType,
                'section_id' => $sectionId,
                'sectionid' => $sectionId,
                'badge_id' => self::BADGE_ID,
                'badge_version' => self::BADGE_VERSION,
                'term_id' => $termId,
                'type_id' => self::TYPE_STAGED,
            ]);
            $apiCalls++;
            return self::columnLabels($res);
        } catch (Throwable) {
            $apiCalls++;
            return [];
        }
    }

    /**
     * @param array<string, mixed> $res
     * @return list<array<string, mixed>>
     */
    private static function badgeRecordMembers(array $res): array
    {
        $candidates = [
            $res['data']['members'] ?? null,
            $res['members'] ?? null,
            $res['data']['data']['members'] ?? null,
            $res['items'] ?? null,
            $res['data']['items'] ?? null,
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
        return OsmLists::items($res);
    }

    /** @param array<string, mixed> $row */
    private static function memberIdFromRow(array $row): string
    {
        $ids = self::idsFromRow($row);
        return $ids[0] ?? '';
    }

    /**
     * Every OSM identifier we have seen for this person (scoutid and v3 member_id can differ).
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function idsFromRow(array $row): array
    {
        $ids = [];
        foreach (['scoutid', 'scout_id', 'member_id', 'memberid', 'id'] as $k) {
            $id = self::asString($row[$k] ?? '');
            if ($id === '' || in_array($id, $ids, true)) {
                continue;
            }
            if ($k === 'id' && !preg_match('/^\d+$/', $id)) {
                continue;
            }
            $ids[] = $id;
        }
        return $ids;
    }

    /**
     * @param array<string, mixed> $member
     * @return list<string>
     */
    private static function idsOf(array $member): array
    {
        $ids = [];
        foreach (is_array($member['ids'] ?? null) ? $member['ids'] : [] as $id) {
            $id = self::asString($id);
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        foreach (['scoutid', 'member_id'] as $k) {
            $id = self::asString($member[$k] ?? '');
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * @param array<string, array<string, mixed>> $byMember
     * @param array<string, mixed> $member
     * @return array<string, mixed>|null
     */
    private static function lookupParsed(array $byMember, array $member): ?array
    {
        foreach (self::idsOf($member) as $id) {
            if (isset($byMember[$id]) && is_array($byMember[$id])) {
                return $byMember[$id];
            }
        }
        $fn = strtolower(trim(self::asString($member['firstname'] ?? '')));
        $ln = strtolower(trim(self::asString($member['lastname'] ?? '')));
        if ($fn === '' || $ln === '') {
            return null;
        }
        foreach ($byMember as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strtolower(trim(self::asString($row['firstname'] ?? ''))) === $fn
                && strtolower(trim(self::asString($row['lastname'] ?? ''))) === $ln) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $byMember
     * @param array<string, mixed> $parsed
     * @param list<string> $ids
     */
    private static function storeParsed(array &$byMember, array $parsed, array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id !== '')));
        $parsed['ids'] = array_values(array_unique(array_merge($parsed['ids'] ?? [], $ids)));
        foreach ($parsed['ids'] as $id) {
            $byMember[$id] = $parsed;
        }
    }

    /**
     * OSM getBadgeRecords (payload=1): camp titles live in data.requirements[].
     * module "y" = camp columns; "a" = Initial Pre-OSM; "b" = Total.
     *
     * @param array<string, mixed> $res
     * @return array<string, string> requirement_id => camp name
     */
    private static function requirementCampMap(array $res): array
    {
        $reqs = $res['data']['requirements'] ?? $res['requirements'] ?? null;
        if (!is_array($reqs) || $reqs === []) {
            return [];
        }
        $map = [];
        foreach ($reqs as $key => $req) {
            // List of objects, or map of id => object / id => name string.
            if (is_string($req) || is_int($req) || is_float($req)) {
                $id = self::asString($key);
                $name = trim(self::asString($req));
                $module = 'y';
            } elseif (is_array($req)) {
                $id = self::asString($req['requirement_id'] ?? $req['id'] ?? $key);
                $name = trim(self::asString($req['name'] ?? $req['label'] ?? ''));
                $module = strtolower(trim(self::asString($req['module'] ?? '')));
            } else {
                continue;
            }
            if ($id === '' || $name === '') {
                continue;
            }
            if ($id === '21036' || $id === '21037') {
                continue;
            }
            if ($module === 'a' || $module === 'b') {
                continue;
            }
            if (preg_match('/^(total|initial\s*pre[\s-]?osm)$/i', $name)) {
                continue;
            }
            // Prefer module y; also accept blank module with a real camp title.
            if ($module !== 'y' && $module !== '') {
                continue;
            }
            $map[$id] = $name;
        }
        return $map;
    }

    /**
     * Parse one member from getBadgeRecords using requirements + column_data only.
     * Never iterate top-level member keys (eligible, awarded, …) as camps.
     *
     * @param array<string, mixed> $member
     * @param array<string, string> $campLabels requirement_id => name
     * @return array<string, mixed>
     */
    private static function parseBadgeRecordsMember(
        array $member,
        array $campLabels,
        string $sectionName,
        string $sectionType
    ): array {
        $entries = [];
        $osmTotal = null;
        $columnData = $member['column_data'] ?? null;
        if (!is_array($columnData)) {
            $columnData = [];
        }

        // Total nights: requirement module b (commonly id 21037) or config levels_column_id.
        foreach ($columnData as $reqId => $rawVal) {
            $reqId = self::asString($reqId);
            if ($reqId === '21037' || $reqId === '21036') {
                $n = self::nightsValue($rawVal, '');
                if ($reqId === '21037' && $n !== null) {
                    $osmTotal = $n;
                }
                continue;
            }
            if (!isset($campLabels[$reqId])) {
                continue;
            }
            $nights = self::nightsValue($rawVal, $campLabels[$reqId]);
            if ($nights === null || $nights < 1) {
                continue;
            }
            $name = self::displayLabel($campLabels[$reqId]);
            $year = self::yearFromLabel($name);
            $entries[] = [
                'name' => $name,
                'nights' => $nights,
                'sectionName' => $sectionName,
                'sectionType' => $sectionType,
                'year' => $year,
                'field' => $reqId,
            ];
        }

        $awarded = self::intOrNull($member['awarded'] ?? $member['awarded_level'] ?? null);
        $awardedDate = '';
        foreach (['awardeddate', 'awarded_date', 'dateawarded'] as $k) {
            $v = trim(self::asString($member[$k] ?? ''));
            if ($v !== '' && $v !== '0000-00-00') {
                $awardedDate = $v;
                break;
            }
        }

        return [
            'firstname' => self::firstString($member['firstname'] ?? null, $member['first_name'] ?? null),
            'lastname' => self::firstString($member['lastname'] ?? null, $member['last_name'] ?? null),
            'osmTotal' => $osmTotal,
            'awarded' => $awarded,
            'awardedDate' => $awardedDate,
            'entries' => $entries,
        ];
    }

    private static function parseMemberRow(array $row, array $labels, string $sectionName, string $sectionType): array
    {
        $entries = [];
        $osmTotal = null;
        $flat = self::flattenBadgeMemberRow($row);
        // Prefer structure labels: walk known fields even if the member payload nests them.
        $fields = array_unique(array_merge(array_keys($labels), array_keys($flat)));
        foreach ($fields as $field) {
            $field = self::asString($field);
            if ($field === '' || in_array(strtolower($field), self::META_FIELDS, true)) {
                continue;
            }
            if (!array_key_exists($field, $flat)) {
                continue;
            }
            $rawVal = $flat[$field];
            $label = $labels[$field] ?? $field;
            $nights = self::nightsValue($rawVal, $label);
            if ($nights === null) {
                continue;
            }
            // Totals before meta skip — label "Total" is meta-ish but must set osmTotal.
            if (self::isTotalField($field, $label)) {
                $osmTotal = $nights;
                continue;
            }
            if (self::isNonCampMetaLabel($field, $label)) {
                continue;
            }
            // Only real camp/event columns — never "eligible", stage, etc.
            if (!self::isEventField($field, $label)) {
                continue;
            }
            if ($nights < 1) {
                continue;
            }
            $name = self::displayLabel($label);
            if ($name === '' || self::isNonCampMetaLabel($field, $name)) {
                continue;
            }
            // If structure labels missed, avoid showing a raw column id as the title.
            if (preg_match('/^(custom_|f_|col_)/i', $name) && !isset($labels[$field])) {
                $name = 'Camp ' . $field;
            }
            $year = self::yearFromLabel($name);
            $entries[] = [
                'name' => $name,
                'nights' => $nights,
                'sectionName' => $sectionName,
                'sectionType' => $sectionType,
                'year' => $year,
                'field' => $field,
            ];
        }

        $awarded = self::intOrNull($row['awarded'] ?? $row['awarded_level'] ?? null);
        $awardedDate = '';
        foreach (['awardeddate', 'awarded_date', 'dateawarded'] as $k) {
            $v = trim(self::asString($row[$k] ?? ''));
            if ($v !== '' && $v !== '0000-00-00') {
                $awardedDate = $v;
                break;
            }
        }

        return [
            'firstname' => self::firstString($row['firstname'] ?? null, $row['first_name'] ?? null),
            'lastname' => self::firstString($row['lastname'] ?? null, $row['last_name'] ?? null),
            'osmTotal' => $osmTotal,
            'awarded' => $awarded,
            'awardedDate' => $awardedDate,
            'entries' => $entries,
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    private static function mergeMember(array $a, array $b): array
    {
        if (($a['firstname'] ?? '') === '' && ($b['firstname'] ?? '') !== '') {
            $a['firstname'] = $b['firstname'];
            $a['lastname'] = $b['lastname'] ?? '';
        }
        $aTotal = $a['osmTotal'] ?? null;
        $bTotal = $b['osmTotal'] ?? null;
        if (is_int($bTotal) && (!is_int($aTotal) || $bTotal > $aTotal)) {
            $a['osmTotal'] = $bTotal;
        }
        $aAward = $a['awarded'] ?? null;
        $bAward = $b['awarded'] ?? null;
        if (is_int($bAward) && (!is_int($aAward) || $bAward > $aAward)) {
            $a['awarded'] = $bAward;
            if (($b['awardedDate'] ?? '') !== '') {
                $a['awardedDate'] = $b['awardedDate'];
            }
        }
        // Union camps by entryKey. Always drop meta-named rows (eligible, Total, …).
        // Do NOT wipe a whole section when $b only has a partial set — that dropped
        // Cub/Buxted detail when other-sections returned fewer rows than buildIndex.
        /** @var array<string, array<string, mixed>> $have */
        $have = [];
        foreach ([$a, $b] as $src) {
            foreach (is_array($src['entries'] ?? null) ? $src['entries'] : [] as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $nm = strtolower(trim((string) ($e['name'] ?? '')));
                if ($nm !== '' && self::isNonCampMetaLabel('', $nm)) {
                    continue;
                }
                $k = self::entryKey($e);
                if (!isset($have[$k]) || (int) ($e['nights'] ?? 0) > (int) ($have[$k]['nights'] ?? 0)) {
                    $have[$k] = $e;
                }
            }
        }
        $a['entries'] = array_values($have);
        return $a;
    }

    /** @param array<string, mixed> $e */
    private static function entryKey(array $e): string
    {
        return strtolower(trim((string) ($e['name'] ?? '')))
            . '|' . strtolower(trim((string) ($e['sectionName'] ?? '')))
            . '|' . (int) ($e['nights'] ?? 0);
    }

    /**
     * @param array<string, array<string, mixed>> $byMember
     * @param array<string, mixed> $member
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   yearTotals: array<string, int>,
     *   totalNights: int,
     *   listedNights: int,
     *   osmTotal: ?int,
     *   awarded: ?int,
     *   awardedDate: string,
     *   firstname: string,
     *   lastname: string
     * }
     */
    private static function rowsForMember(array $byMember, string $scoutid, array $member): array
    {
        $data = self::lookupParsed($byMember, array_merge($member, ['scoutid' => $scoutid]));
        $fn = (string) ($member['firstname'] ?? '');
        $ln = (string) ($member['lastname'] ?? '');
        if (!is_array($data)) {
            return [
                'rows' => [],
                'yearTotals' => [],
                'totalNights' => 0,
                'listedNights' => 0,
                'osmTotal' => null,
                'awarded' => null,
                'awardedDate' => '',
                'firstname' => $fn,
                'lastname' => $ln,
            ];
        }
        if ($fn === '' && !empty($data['firstname'])) {
            $fn = (string) $data['firstname'];
            $ln = (string) ($data['lastname'] ?? '');
        }
        $rows = [];
        $yearTotals = [];
        $sum = 0;
        foreach (is_array($data['entries'] ?? null) ? $data['entries'] : [] as $e) {
            if (!is_array($e)) {
                continue;
            }
            $name = (string) ($e['name'] ?? '');
            if ($name !== '' && self::isNonCampMetaLabel('', $name)) {
                continue;
            }
            $nights = (int) ($e['nights'] ?? 0);
            if ($nights < 1) {
                continue;
            }
            $year = (string) ($e['year'] ?? '');
            $yearLabel = $year !== '' ? $year : '—';
            $rows[] = [
                'name' => $name,
                'nights' => $nights,
                'sectionName' => (string) ($e['sectionName'] ?? ''),
                'year' => $yearLabel,
            ];
            $sum += $nights;
            $yearTotals[$yearLabel] = ($yearTotals[$yearLabel] ?? 0) + $nights;
        }
        usort($rows, static function (array $a, array $b): int {
            $y = strcmp((string) $b['year'], (string) $a['year']);
            return $y !== 0 ? $y : strcasecmp((string) $a['name'], (string) $b['name']);
        });
        krsort($yearTotals, SORT_STRING);
        $osmTotal = is_numeric($data['osmTotal'] ?? null) ? (int) $data['osmTotal'] : null;
        $total = max($osmTotal ?? 0, $sum);
        return [
            'rows' => $rows,
            'yearTotals' => $yearTotals,
            'totalNights' => $total,
            'listedNights' => $sum,
            'osmTotal' => $osmTotal,
            'awarded' => is_numeric($data['awarded'] ?? null) ? (int) $data['awarded'] : null,
            'awardedDate' => (string) ($data['awardedDate'] ?? ''),
            'firstname' => $fn,
            'lastname' => $ln,
        ];
    }

    private static function nightsValue(mixed $raw, string $label): ?int
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        // Booleans are flags (eligible, completed) — never camp nights.
        if (is_bool($raw)) {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return (int) $raw;
        }
        if (is_array($raw)) {
            foreach (['value', 'nights', 'count', 'amount', 'data', 'number'] as $k) {
                if (array_key_exists($k, $raw)) {
                    $n = self::nightsValue($raw[$k], $label);
                    if ($n !== null) {
                        return $n;
                    }
                }
            }
            return null;
        }
        if (!is_scalar($raw)) {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '' || strtolower($s) === 'no' || $s === '-') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $s)) {
            return (int) $s;
        }
        if (preg_match('/(\d+)\s*nights?/i', $s, $m)) {
            return (int) $m[1];
        }
        // "Camp name = 2" sometimes stored as the cell itself
        if (preg_match('/=\s*(\d+)\s*$/', $s, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/=\s*(\d+)\s*$/', $label, $m) && (strtolower($s) === 'yes' || strtolower($s) === 'y')) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Pull nested badge cell maps onto the top level (OSM sometimes nests values).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function flattenBadgeMemberRow(array $row): array
    {
        $flat = $row;
        foreach (['data', 'values', 'fields', 'badge', 'badge_data', 'record', 'attributes'] as $k) {
            $inner = $row[$k] ?? null;
            if (!is_array($inner) || $inner === [] || array_is_list($inner)) {
                continue;
            }
            // Skip nested structure blobs.
            if (isset($inner['structure']) || isset($inner['columns']) || isset($inner['members'])) {
                continue;
            }
            foreach ($inner as $ik => $iv) {
                $ik = self::asString($ik);
                if ($ik === '' || array_key_exists($ik, $flat)) {
                    continue;
                }
                $flat[$ik] = $iv;
            }
        }
        return $flat;
    }

    private static function isNonCampMetaLabel(string $field, string $label): bool
    {
        $f = strtolower(trim($field));
        $l = strtolower(trim($label));
        if ($f !== '' && in_array($f, self::META_FIELDS, true)) {
            return true;
        }
        if ($l === '') {
            return false;
        }
        if (preg_match(
            '/^(eligible|eligibility|completed|awarded|stage|level|progress|due|active|patrol|'
            . 'total|module\s*[a-z]?|initial(\s*pre[\s-]?osm)?|pre[\s-]?osm)$/i',
            $l
        )) {
            return true;
        }
        if (preg_match('/\b(eligib|awarded\s*stage|badge\s*completed|percentage)\b/i', $l)) {
            return true;
        }
        return false;
    }

    private static function isTotalField(string $field, string $label): bool
    {
        $f = strtolower($field);
        $l = strtolower($label);
        if ($f === 'y_01' || $f === 'y01' || $f === 'total') {
            return true;
        }
        if (preg_match('/total\s*nights|nights\s*away\s*total|^total$/i', $l)) {
            return true;
        }
        return false;
    }

    private static function isEventField(string $field, string $label): bool
    {
        if (self::isNonCampMetaLabel($field, $label) || self::isTotalField($field, $label)) {
            return false;
        }
        if (preg_match('/^custom_/i', $field)) {
            return true;
        }
        if (preg_match('/^f_\d+$/i', $field)) {
            return true;
        }
        if (preg_match('/^col_/i', $field)) {
            return true;
        }
        $l = strtolower($label);
        // Real overnight events — not the word "night" inside "fortnight" alone; require camp-ish terms.
        if (preg_match(
            '/camp|sleepover|pack\s*holiday|nights?\s*away|residential|expedition|'
            . '\bhike\b|dovedale|beaudesert|splash|wild\s*winter|culvert/i',
            $l
        )) {
            return true;
        }
        // Year-leading labels from OSM ("2025 Summer Camp", "District Camp 2009").
        if (preg_match('/\b(20\d{2})\b/', $l) && preg_match('/[a-z]/i', $l)) {
            return true;
        }
        return false;
    }

    private static function displayLabel(string $label): string
    {
        $s = preg_replace('/^[A-Z]:\s*/', '', $label) ?? $label;
        $s = preg_replace('/\s*=\s*\d+\s*$/', '', $s) ?? $s;
        $s = trim($s);
        return $s !== '' ? $s : $label;
    }

    private static function yearFromLabel(string $label): string
    {
        if (preg_match('/\b(20\d{2})\b/', $label, $m)) {
            return $m[1];
        }
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/', $label, $m)) {
            $y = (int) $m[3];
            if ($y < 100) {
                $y += 2000;
            }
            return (string) $y;
        }
        return '';
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

    private static function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === false) {
            return null;
        }
        if (is_numeric($v)) {
            $n = (int) $v;
            return $n > 0 ? $n : null;
        }
        return null;
    }

    private static function asString(mixed $v): string
    {
        if ($v === null || is_bool($v)) {
            return $v === true ? '1' : '';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        return '';
    }

    private static function firstString(mixed ...$vals): string
    {
        foreach ($vals as $v) {
            $s = self::asString($v);
            if ($s !== '') {
                return $s;
            }
        }
        return '';
    }

    private static function looksLikeScopeError(string $msg): bool
    {
        $m = strtolower($msg);
        return str_contains($m, '403')
            || str_contains($m, 'forbidden')
            || str_contains($m, 'scope')
            || str_contains($m, 'permission')
            || str_contains($m, 'not authorised')
            || str_contains($m, 'not authorized');
    }

    /** @return array<string, mixed>|null */
    private static function readIndex(): ?array
    {
        $raw = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($raw) || empty($raw['at']) || !is_array($raw['byMember'] ?? null)) {
            return null;
        }
        $at = (int) $raw['at'];
        if ($at <= 0 || (time() - $at) > self::CACHE_TTL_SEC) {
            return null;
        }
        return $raw;
    }

    /** @param array<string, mixed> $payload */
    private static function writeIndex(array $payload): void
    {
        $_SESSION[self::SESSION_KEY] = $payload;
    }
}
