<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Http\Csrf;
use App\Members\Patrols;
use App\Osm\LoginSections;
use App\Osm\OsmApi;
use App\Osm\OsmLists;
use Throwable;
final class MembersController
{
    private const INDIVIDUAL_CONCURRENCY = 5;

    /** Unchanged Members / Checks rule (untrimmed name match); see Patrols::isYoungLeaderPatrolName. */
    private static function isYoungLeaderPatrol(string $patrol): bool
    {
        return Patrols::isYoungLeaderPatrolName($patrol);
    }

    private static function isLeaderPatrol(string $patrol): bool
    {
        return Patrols::isLeader($patrol);
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

    /**
     * Shared OSM membership load (list + checks).
     * Pass login sections to query only those; null loads every section (member checks).
     *
     * @param list<array<string, mixed>>|null $sections
     * @return array{
     *   groupName: string,
     *   flatMembers: list<array<string, mixed>>,
     *   sectionNames: list<string>,
     *   youthDuplicates: list<array<string, mixed>>,
     *   ylMembers: list<array<string, mixed>>,
     *   leaderMembers: list<array<string, mixed>>,
     *   errorMessage: ?string,
     *   fetchedAt: string
     * }
     */
    private static function loadData(string $token, ?array $sections = null): array
    {
        $api = new OsmApi();
        $groupName = (string) ($_SESSION['groupName'] ?? 'OSM Helper');
        $youthDuplicates = $ylMembers = $leaderMembers = $flatMembers = [];
        $errorMessage = null;
        $sectionNames = [];
        try {
            if ($sections === null) {
                $sections = $api->getDynamicSections($token);
            }
            foreach ($sections as $sec) {
                if (!is_array($sec)) {
                    continue;
                }
                if (($sec['section_type'] ?? '') !== 'adults' && !empty($sec['group_name'])) {
                    $groupName = (string) $sec['group_name'];
                    break;
                }
            }

            /** @var array<string, array<string, mixed>> $all */
            $all = [];
            /** @var list<array{sectionId: string, sectionType: string, sectionName: string, termId: mixed, scoutid: string}> $needIndividual */
            $needIndividual = [];

            foreach ($sections as $sec) {
                if (!is_array($sec)) {
                    continue;
                }
                $sectionType = (string) ($sec['section_type'] ?? 'unknown');
                if (in_array($sectionType, ['waiting', 'unknown'], true)) {
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
                        'sort' => 'patrol',
                    ]);
                    $list = OsmLists::items($res);
                } catch (Throwable) {
                    continue;
                }
                foreach ($list as $raw) {
                    if (!is_array($raw)) {
                        continue;
                    }
                    $scoutid = (string) ($raw['scoutid'] ?? $raw['id'] ?? '');
                    if ($scoutid === '') {
                        continue;
                    }
                    $dob = (string) ($raw['dob'] ?? '');
                    $patrol = (string) ($raw['patrol'] ?? '');
                    if (!isset($all[$scoutid])) {
                        $all[$scoutid] = [
                            'firstname' => $raw['firstname'] ?? '',
                            'lastname' => $raw['lastname'] ?? '',
                            'dob' => $dob,
                            'age' => 0.0,
                            'sections' => [],
                            'scoutid' => $scoutid,
                        ];
                    } else {
                        if ($dob !== '' && ($all[$scoutid]['dob'] ?? '') === '') {
                            $all[$scoutid]['dob'] = $dob;
                        }
                        if (($all[$scoutid]['firstname'] ?? '') === '' && !empty($raw['firstname'])) {
                            $all[$scoutid]['firstname'] = $raw['firstname'];
                        }
                        if (($all[$scoutid]['lastname'] ?? '') === '' && !empty($raw['lastname'])) {
                            $all[$scoutid]['lastname'] = $raw['lastname'];
                        }
                    }
                    $all[$scoutid]['sections'][] = [
                        'name' => $sectionName,
                        'type' => $sectionType,
                        'patrol' => $patrol !== '' ? $patrol : 'None',
                        'sectionId' => $sectionId,
                        'termId' => $termId,
                    ];
                    if ($dob === '' || $patrol === '') {
                        $needIndividual[] = [
                            'sectionId' => $sectionId,
                            'sectionType' => $sectionType,
                            'sectionName' => $sectionName,
                            'termId' => $termId,
                            'scoutid' => $scoutid,
                        ];
                    }
                }
            }

            $needIndividual = array_values(array_unique($needIndividual, SORT_REGULAR));
            foreach (array_chunk($needIndividual, self::INDIVIDUAL_CONCURRENCY) as $chunk) {
                foreach ($chunk as $job) {
                    $scoutid = $job['scoutid'];
                    try {
                        $ind = $api->get($token, '/ext/members/contact/', [
                            'action' => 'getIndividual',
                            'sectionid' => $job['sectionId'],
                            'scoutid' => $scoutid,
                            'termid' => $job['termId'],
                            'context' => 'members',
                        ]);
                        $d = self::individualPayload($ind);
                        if (!isset($all[$scoutid])) {
                            continue;
                        }
                        if (!empty($d['dob']) && ($all[$scoutid]['dob'] ?? '') === '') {
                            $all[$scoutid]['dob'] = (string) $d['dob'];
                        }
                        if (!empty($d['firstname']) && ($all[$scoutid]['firstname'] ?? '') === '') {
                            $all[$scoutid]['firstname'] = $d['firstname'];
                        }
                        if (!empty($d['lastname']) && ($all[$scoutid]['lastname'] ?? '') === '') {
                            $all[$scoutid]['lastname'] = $d['lastname'];
                        }
                        $indPatrol = (string) ($d['patrol'] ?? '');
                        if ($indPatrol !== '') {
                            foreach ($all[$scoutid]['sections'] as &$secRow) {
                                if (($secRow['sectionId'] ?? '') === $job['sectionId'] && ($secRow['patrol'] ?? 'None') === 'None') {
                                    $secRow['patrol'] = $indPatrol;
                                }
                            }
                            unset($secRow);
                        }
                    } catch (Throwable) {
                        continue;
                    }
                }
            }

            foreach ($all as &$m) {
                $dob = (string) ($m['dob'] ?? '');
                $age = 0.0;
                if ($dob !== '') {
                    $t = strtotime($dob);
                    if ($t !== false) {
                        $age = abs((time() - $t) / (365.25 * 86400));
                    }
                }
                $m['age'] = $age;
            }
            unset($m);

            $sectionSet = [];
            foreach ($all as $m) {
                $hasYL = false;
                $hasLeader = false;
                foreach ($m['sections'] as $s) {
                    $patrol = (string) ($s['patrol'] ?? '');
                    if (self::isYoungLeaderPatrol($patrol)) {
                        $hasYL = true;
                    }
                    if (self::isLeaderPatrol($patrol)) {
                        $hasLeader = true;
                    }
                }

                if ($hasYL) {
                    $issue = '';
                    if (!array_filter($m['sections'], fn ($s) => ($s['type'] ?? '') === 'explorers')) {
                        $issue .= 'Not in Explorers; ';
                    }
                    if (count($m['sections']) !== 2) {
                        $issue .= 'In ' . count($m['sections']) . ' sections; ';
                    }
                    $status = rtrim($issue, '; ') ?: 'OK';
                    $ylMembers[] = [
                        'firstname' => $m['firstname'],
                        'lastname' => $m['lastname'],
                        'sections' => implode(', ', array_column($m['sections'], 'name')),
                        'issue' => $status,
                        'statusOk' => $status === 'OK',
                    ];
                }

                if ($hasLeader) {
                    $ok = (bool) array_filter($m['sections'], fn ($s) => ($s['type'] ?? '') === 'adults');
                    $status = $ok ? 'OK' : 'Not in Adults';
                    $leaderMembers[] = [
                        'firstname' => $m['firstname'],
                        'lastname' => $m['lastname'],
                        'sections' => implode(', ', array_column($m['sections'], 'name')),
                        'issue' => $status,
                        'statusOk' => $status === 'OK',
                    ];
                }

                if ($m['age'] < 18 && count($m['sections']) > 1 && !$hasYL && !$hasLeader) {
                    $youthDuplicates[] = [
                        'firstname' => $m['firstname'],
                        'lastname' => $m['lastname'],
                        'age' => number_format((float) $m['age'], 1),
                        'sections' => implode(', ', array_column($m['sections'], 'name')),
                    ];
                }

                foreach ($m['sections'] as $sec) {
                    // Members list excludes pure Leaders patrol rows
                    if (self::isLeaderPatrol((string) ($sec['patrol'] ?? ''))) {
                        continue;
                    }
                    $secName = (string) ($sec['name'] ?? '');
                    if ($secName !== '') {
                        $sectionSet[$secName] = true;
                    }
                    $flatMembers[] = [
                        'section_type' => $sec['type'],
                        'section_name' => $secName,
                        'firstname' => $m['firstname'],
                        'lastname' => $m['lastname'],
                        'dob' => $m['dob'],
                        'patrol' => $sec['patrol'],
                        'age' => number_format((float) $m['age'], 1),
                        'scoutid' => $m['scoutid'],
                    ];
                }
            }
            $sectionNames = array_keys($sectionSet);
            sort($sectionNames, SORT_NATURAL | SORT_FLAG_CASE);
            $ylMembers = array_values(array_unique($ylMembers, SORT_REGULAR));
            $leaderMembers = array_values(array_unique($leaderMembers, SORT_REGULAR));
        } catch (Throwable) {
            $errorMessage = 'Could not load membership data. Please try again later.';
        }

        return [
            'groupName' => $groupName,
            'flatMembers' => $flatMembers,
            'sectionNames' => $sectionNames,
            'youthDuplicates' => $youthDuplicates,
            'ylMembers' => $ylMembers,
            'leaderMembers' => $leaderMembers,
            'errorMessage' => $errorMessage,
            'fetchedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('d/m/y H:i'),
        ];
    }

    public function index(): void
    {
        $token = Auth::requireLogin();
        [$choices, $sectionError] = self::choices($token);
        $result = $_SESSION['membersListResult'] ?? null;
        if (!is_array($result)) {
            $result = null;
        }
        $selected = [];
        if (is_array($result['sectionIds'] ?? null)) {
            foreach ($result['sectionIds'] as $id) {
                if (is_scalar($id)) {
                    $selected[] = (string) $id;
                }
            }
        }
        self::renderMembers($choices, $selected, $sectionError, $result);
    }

    public function run(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
        [$choices, $sectionError] = self::choices($token);
        if ($sectionError !== null) {
            self::renderMembers($choices, [], $sectionError, null);
            return;
        }
        $raw = $_POST['section_ids'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $picked = LoginSections::pickedSections($choices, $raw);
        $selected = [];
        foreach ($raw as $id) {
            if (is_scalar($id)) {
                $selected[] = trim((string) $id);
            }
        }
        if ($picked === []) {
            self::renderMembers($choices, $selected, 'Tick at least one section, then load the list.', null);
            return;
        }
        $data = self::loadData($token, $picked);
        $selectedIds = array_map(static fn (array $sec): string => (string) $sec['section_id'], $picked);
        $_SESSION['membersListResult'] = [
            'sectionIds' => $selectedIds,
            'groupName' => $data['groupName'],
            'flatMembers' => $data['flatMembers'],
            'sectionNames' => $data['sectionNames'],
            'errorMessage' => $data['errorMessage'],
            'fetchedAt' => $data['fetchedAt'],
        ];
        header('Location: /members/');
        exit;
    }

    /**
     * @return array{0: list<array{id:string,name:string,type:string,typeLabel:string,groupName:string,termId:mixed}>, 1: ?string}
     */
    private static function choices(string $token): array
    {
        try {
            return [LoginSections::memberListChoices(LoginSections::ensure($token)), null];
        } catch (Throwable) {
            return [[], 'Could not read the sections from this login. Sign out and connect again.'];
        }
    }

    /**
     * @param list<array{id:string,name:string,type:string,typeLabel:string,groupName:string,termId:mixed}> $choices
     * @param list<string> $selectedIds
     * @param array<string, mixed>|null $result
     */
    private static function renderMembers(array $choices, array $selectedIds, ?string $sectionError, ?array $result): void
    {
        $loaded = is_array($result);
        $groupName = $loaded
            ? (string) ($result['groupName'] ?? ($_SESSION['groupName'] ?? 'OSM Helper'))
            : (string) ($_SESSION['groupName'] ?? 'OSM Helper');
        $groups = LoginSections::groupChoices($choices);
        App::render('members.twig', Auth::baseContext([
            'title' => 'Member list',
            'groupName' => $groupName,
            'groups' => $groups,
            'choiceCount' => count($choices),
            'multiGroup' => count($groups) > 1,
            'selectedIds' => $selectedIds,
            'sectionError' => $sectionError,
            'loaded' => $loaded,
            'members' => $loaded && is_array($result['flatMembers'] ?? null) ? $result['flatMembers'] : [],
            'sectionNames' => $loaded && is_array($result['sectionNames'] ?? null) ? $result['sectionNames'] : [],
            'errorMessage' => $loaded ? ($result['errorMessage'] ?? null) : null,
            'fetchedAt' => $loaded ? (string) ($result['fetchedAt'] ?? '') : '',
        ]));
    }

    public function checks(): void
    {
        $token = Auth::requireLogin();
        $data = self::loadData($token);
        App::render('member-checks.twig', Auth::baseContext([
            'title' => 'Member checks',
            'groupName' => $data['groupName'],
            'youthDuplicates' => $data['youthDuplicates'],
            'ylMembers' => $data['ylMembers'],
            'leaderMembers' => $data['leaderMembers'],
            'errorMessage' => $data['errorMessage'],
            'fetchedAt' => $data['fetchedAt'],
        ]));
    }
}
