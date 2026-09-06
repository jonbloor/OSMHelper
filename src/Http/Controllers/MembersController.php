<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Osm\OsmApi;
use App\Osm\OsmLists;
use Throwable;
final class MembersController
{
    private const INDIVIDUAL_CONCURRENCY = 5;

    public function index(): void
    {
        $token = Auth::requireLogin();
        $api = new OsmApi();
        $groupName = (string) ($_SESSION['groupName'] ?? 'OSM Helper');
        $mainMembers = $youthDuplicates = $ylMembers = $leaderMembers = $flatMembers = $leaderRoster = [];
        $errorMessage = null;
        try {
            $sections = $api->getDynamicSections($token);
            foreach ($sections as $sec) {
                if (!is_array($sec)) continue;
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
                if (!is_array($sec)) continue;
                $sectionType = (string) ($sec['section_type'] ?? 'unknown');
                if (in_array($sectionType, ['waiting', 'unknown'], true)) continue;
                $sectionId = (string) ($sec['section_id'] ?? $sec['id'] ?? '');
                if ($sectionId === '') continue;
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
                    if (!is_array($raw)) continue;
                    $scoutid = (string) ($raw['scoutid'] ?? $raw['id'] ?? '');
                    if ($scoutid === '') continue;
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

            // Node parity: getIndividual when missing dob or patrol (batched)
            $needIndividual = array_values(array_unique($needIndividual, SORT_REGULAR));
            $chunks = array_chunk($needIndividual, self::INDIVIDUAL_CONCURRENCY);
            foreach ($chunks as $chunk) {
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
                        $d = $ind['data'] ?? $ind;
                        if (!is_array($d)) continue;
                        if (!isset($all[$scoutid])) continue;
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

            foreach ($all as $m) {
                $hasYL = false;
                foreach ($m['sections'] as $s) {
                    if (str_contains((string) $s['patrol'], 'Young Leaders')) $hasYL = true;
                }
                if ($hasYL) {
                    $issue = '';
                    if (!array_filter($m['sections'], fn ($s) => $s['type'] === 'explorers')) $issue .= 'Not in Explorers; ';
                    if (count($m['sections']) !== 2) $issue .= 'In ' . count($m['sections']) . ' sections; ';
                    $ylMembers[] = [
                        'firstname' => $m['firstname'], 'lastname' => $m['lastname'],
                        'sections' => implode(', ', array_column($m['sections'], 'name')),
                        'issue' => rtrim($issue, '; ') ?: 'OK',
                    ];
                }
                $hasLeader = array_filter($m['sections'], fn ($s) => $s['patrol'] === 'Leaders');
                if ($hasLeader) {
                    $ok = array_filter($m['sections'], fn ($s) => $s['type'] === 'adults');
                    $leaderMembers[] = [
                        'firstname' => $m['firstname'], 'lastname' => $m['lastname'],
                        'sections' => implode(', ', array_column($m['sections'], 'name')),
                        'issue' => $ok ? 'OK' : 'Not in Adults',
                    ];
                    $leaderRoster[] = [
                        'firstname' => $m['firstname'],
                        'lastname' => $m['lastname'],
                        'sections' => implode(', ', array_column($m['sections'], 'name')),
                        'dob' => $m['dob'],
                        'age' => number_format((float) $m['age'], 1),
                    ];
                }
                if ($m['age'] < 18 && count($m['sections']) > 1 && !$hasYL) {
                    $youthDuplicates[] = $m;
                }
                foreach ($m['sections'] as $sec) {
                    // Main members table: exclude pure Leaders patrol rows
                    if (($sec['patrol'] ?? '') === 'Leaders') continue;
                    $flatMembers[] = [
                        'section_type' => $sec['type'],
                        'section_name' => $sec['name'],
                        'firstname' => $m['firstname'],
                        'lastname' => $m['lastname'],
                        'dob' => $m['dob'],
                        'patrol' => $sec['patrol'],
                        'age' => number_format((float) $m['age'], 1),
                    ];
                }
            }
            $mainMembers = array_values($all);
            usort($mainMembers, fn ($a, $b) => strcmp($a['firstname'] . $a['lastname'], $b['firstname'] . $b['lastname']));
            $ylMembers = array_values(array_unique($ylMembers, SORT_REGULAR));
            $leaderMembers = array_values(array_unique($leaderMembers, SORT_REGULAR));
        } catch (Throwable) {
            $errorMessage = 'Could not load membership data. Please try again later.';
        }
        App::render('members.twig', Auth::baseContext([
            'title' => 'Members',
            'groupName' => $groupName,
            'mainMembers' => $mainMembers,
            'youthDuplicates' => $youthDuplicates,
            'ylMembers' => $ylMembers,
            'leaderMembers' => $leaderMembers,
            'leaderRoster' => $leaderRoster,
            'members' => $flatMembers,
            'errorMessage' => $errorMessage,
            'fetchedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('d/m/y H:i'),
        ]));
    }
}
