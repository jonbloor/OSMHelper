<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Osm\OsmApi;
use Throwable;
final class MembersController
{
    public function index(): void
    {
        $token = Auth::requireLogin();
        $api = new OsmApi();
        $groupName = (string) ($_SESSION['groupName'] ?? 'OSM Helper');
        $mainMembers = $youthDuplicates = $ylMembers = $leaderMembers = $flatMembers = [];
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
            $all = [];
            foreach ($sections as $sec) {
                if (!is_array($sec)) continue;
                $sectionType = (string) ($sec['section_type'] ?? 'unknown');
                if (in_array($sectionType, ['waiting', 'unknown'], true)) continue;
                $sectionId = $sec['section_id'] ?? $sec['id'] ?? null;
                if (!$sectionId) continue;
                $sectionName = (string) ($sec['section_name'] ?? $sec['name'] ?? '');
                $termId = $sec['current_term_id'] ?? -1;
                try {
                    $res = $api->get($token, '/ext/members/contact/', [
                        'action' => 'getListOfMembers', 'sectionid' => $sectionId,
                        'termid' => $termId, 'section' => $sectionType, 'sort' => 'patrol',
                    ]);
                    $list = $res['items'] ?? $res['data'] ?? [];
                    if (!is_array($list)) $list = [];
                } catch (Throwable) { continue; }
                foreach ($list as $raw) {
                    if (!is_array($raw)) continue;
                    $scoutid = (string) ($raw['scoutid'] ?? $raw['id'] ?? '');
                    if ($scoutid === '') continue;
                    $dob = $raw['dob'] ?? '';
                    $age = 0.0;
                    if ($dob) {
                        $t = strtotime((string) $dob);
                        if ($t !== false) $age = abs((time() - $t) / (365.25 * 86400));
                    }
                    if (!isset($all[$scoutid])) {
                        $all[$scoutid] = [
                            'firstname' => $raw['firstname'] ?? '',
                            'lastname' => $raw['lastname'] ?? '',
                            'dob' => $dob,
                            'age' => $age,
                            'sections' => [],
                            'scoutid' => $scoutid,
                        ];
                    }
                    $all[$scoutid]['sections'][] = [
                        'name' => $sectionName,
                        'type' => $sectionType,
                        'patrol' => (string) ($raw['patrol'] ?? 'None'),
                    ];
                }
            }
            foreach ($all as $m) {
                $hasYL = false;
                foreach ($m['sections'] as $s) {
                    if (str_contains($s['patrol'], 'Young Leaders')) $hasYL = true;
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
                }
                if ($m['age'] < 18 && count($m['sections']) > 1 && !$hasYL) {
                    $youthDuplicates[] = $m;
                }
                foreach ($m['sections'] as $sec) {
                    $flatMembers[] = [
                        'section_type' => $sec['type'],
                        'section_name' => $sec['name'],
                        'firstname' => $m['firstname'],
                        'lastname' => $m['lastname'],
                        'dob' => $m['dob'],
                        'patrol' => $sec['patrol'],
                        'age' => number_format($m['age'], 1),
                    ];
                }
            }
            $mainMembers = array_values($all);
            usort($mainMembers, fn ($a, $b) => strcmp($a['firstname'].$a['lastname'], $b['firstname'].$b['lastname']));
        } catch (Throwable $e) {
            $errorMessage = 'Could not load membership data. Please try again later.';
        }
        App::render('members.twig', Auth::baseContext([
            'title' => 'Members',
            'groupName' => $groupName,
            'mainMembers' => $mainMembers,
            'youthDuplicates' => $youthDuplicates,
            'ylMembers' => $ylMembers,
            'leaderMembers' => $leaderMembers,
            'members' => $flatMembers,
            'errorMessage' => $errorMessage,
            'fetchedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('d/m/y H:i'),
        ]));
    }
}
