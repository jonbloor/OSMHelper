<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Config;
use App\Http\Auth;
use App\Http\Csrf;
use App\Osm\LoginSections;
use App\Osm\OsmApi;
use App\Store\CapacitiesStore;
use App\Store\SettingsStore;
use App\Osm\OsmLists;
use App\Waiting\WaitingListService as WL;
use Throwable;
final class MembershipDashboardController
{
    public function index(): void
    {
        $token = Auth::requireLogin();
        [$choices, $sectionError] = $this->choices($token);
        $result = $_SESSION['membershipDashboardResult'] ?? null;
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
        $this->renderDashboard($choices, $selected, $sectionError, $result);
    }

    public function run(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
        [$choices, $sectionError] = $this->choices($token);
        if ($sectionError !== null) {
            $this->renderDashboard($choices, [], $sectionError, null);
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
            $this->renderDashboard($choices, $selected, 'Tick at least one section, then load the numbers.', null);
            return;
        }
        $all = LoginSections::fromSession() ?? [];
        $data = $this->build($token, $picked, $all);
        $data['sectionIds'] = array_map(static fn (array $sec): string => (string) $sec['section_id'], $picked);
        $_SESSION['membershipDashboardResult'] = $data;
        header('Location: /membership-dashboard/');
        exit;
    }

    /**
     * @return array{0: list<array{id:string,name:string,type:string,typeLabel:string,groupName:string,termId:mixed}>, 1: ?string}
     */
    private function choices(string $token): array
    {
        try {
            $all = LoginSections::ensure($token);
        } catch (Throwable) {
            return [[], 'Could not read the sections from this login. Sign out and connect again.'];
        }
        $saved = SettingsStore::all();
        $visible = is_array($saved['visibleSections'] ?? null) ? $saved['visibleSections'] : [];
        $hidden = [];
        foreach ($visible as $id => $on) {
            if ($on === false) {
                $hidden[(string) $id] = true;
            }
        }
        $choices = array_values(array_filter(
            LoginSections::dashboardChoices($all),
            static fn (array $choice): bool => !isset($hidden[$choice['id']])
        ));
        return [$choices, null];
    }

    /**
     * @param list<array{id:string,name:string,type:string,typeLabel:string,groupName:string,termId:mixed}> $choices
     * @param list<string> $selectedIds
     * @param array<string, mixed>|null $result
     */
    private function renderDashboard(array $choices, array $selectedIds, ?string $sectionError, ?array $result): void
    {
        $loaded = is_array($result) && is_array($result['grouped'] ?? null);
        $groups = LoginSections::groupChoices($choices);
        $context = [
            'title' => 'Group numbers',
            'groups' => $groups,
            'choiceCount' => count($choices),
            'multiGroup' => count($groups) > 1,
            'selectedIds' => $selectedIds,
            'sectionError' => $sectionError,
            'loaded' => $loaded,
        ];
        if ($loaded) {
            $context = array_merge($result, $context);
        }
        App::render('membership-dashboard.twig', Auth::baseContext($context));
    }

    /**
     * @param list<array{section_id:string,section_name:string,section_type:string,group_name:string,current_term_id:mixed}> $picked
     * @param list<array{section_id:string,section_name:string,section_type:string,group_name:string,current_term_id:mixed}> $allSections
     * @return array<string, mixed>
     */
    private function build(string $token, array $picked, array $allSections): array
    {
        $api = new OsmApi();
        $sections = $allSections;
        $filtered = $picked;
        $saved = SettingsStore::all();
        $cutoffs = array_merge(Config::DEFAULT_CUTOFFS, is_array($saved['cutoffs'] ?? null) ? $saved['cutoffs'] : []);

        $waitingIncomplete = false;
        $waitingCounts = ['tooYoung' => 0, 'squirrels' => 0, 'beavers' => 0, 'cubs' => 0, 'scouts' => 0, 'explorers' => 0];
        try {
            $waitingSection = null;
            foreach ($sections as $sec) {
                if (!is_array($sec)) continue;
                $type = (string) ($sec['section_type'] ?? '');
                $name = strtolower((string) ($sec['section_name'] ?? ''));
                if ($type === 'waiting' || str_contains($name, 'waiting')) {
                    $waitingSection = $sec;
                    break;
                }
            }
            if ($waitingSection) {
                $waitingId = $waitingSection['section_id'];
                $waitingType = $waitingSection['section_type'] ?? 'waiting';
                // Shared, cached loader: a constant number of OSM calls (list + grid) instead of one per applicant.
                $loadedWaiting = WL::loadRows($api, $token, [
                    'id' => (string) $waitingId,
                    'type' => (string) $waitingType,
                    'name' => (string) ($waitingSection['section_name'] ?? ''),
                ]);
                $waitingIncomplete = $loadedWaiting['busyMinutes'] !== null || $loadedWaiting['detailsSkipped'] > 0;
                $now = time();
                foreach ($loadedWaiting['rows'] as $applicant) {
                    $dob = strtotime((string) ($applicant['dob'] ?? ''));
                    if ($dob === false) continue;
                    $age = ($now - $dob) / (365.25 * 24 * 60 * 60);
                    if ($age < $cutoffs['squirrels']) $waitingCounts['tooYoung']++;
                    elseif ($age < $cutoffs['beavers']) $waitingCounts['squirrels']++;
                    elseif ($age < $cutoffs['cubs']) $waitingCounts['beavers']++;
                    elseif ($age < $cutoffs['scouts']) $waitingCounts['cubs']++;
                    elseif ($age < $cutoffs['explorers']) $waitingCounts['scouts']++;
                    else $waitingCounts['explorers']++;
                }
            }
        } catch (Throwable) {
            // waiting optional
        }

        $grouped = [];
        $totals = ['members' => 0, 'leaders' => 0, 'yls' => 0, 'capacity' => 0, 'spaces' => 0];
        foreach ($filtered as $sec) {
            $type = (string) ($sec['section_type'] ?? 'unknown');
            $friendly = Config::FRIENDLY_SECTION_TYPES[$type] ?? 'Other';
            if (!isset($grouped[$friendly])) {
                $grouped[$friendly] = [
                    'sections' => [],
                    'subtotalMembers' => 0,
                    'subtotalLeaders' => 0,
                    'subtotalYLs' => 0,
                    'subtotalCapacity' => 0,
                    'subtotalSpaces' => 0,
                    'subtotalWaiting' => 0,
                ];
            }
            $termId = $sec['current_term_id'] ?? -1;
            $secMembers = $secLeaders = $secYLs = 0;
            $leaderInitials = $ylInitials = [];
            try {
                $listRes = $api->get($token, '/ext/members/contact/', [
                    'action' => 'getListOfMembers',
                    'sectionid' => $sec['section_id'],
                    'termid' => $termId,
                    'section' => $type,
                    'sort' => 'patrol',
                ]);
                $members = OsmLists::items($listRes);
                foreach ($members as $m) {
                    if (!is_array($m)) continue;
                    $patrol = (string) ($m['patrol'] ?? '');
                    $initials = strtoupper(substr((string) ($m['firstname'] ?? ''), 0, 1) . substr((string) ($m['lastname'] ?? ''), 0, 1));
                    if ($patrol === 'Leaders') {
                        $secLeaders++;
                        if ($initials !== '') $leaderInitials[] = $initials;
                    } elseif ($patrol === 'Young Leaders (YLs)' || $patrol === 'Young Leaders') {
                        $secYLs++;
                        if ($initials !== '') $ylInitials[] = $initials;
                    } else {
                        $secMembers++;
                    }
                }
                sort($leaderInitials);
                sort($ylInitials);
            } catch (Throwable) {
                // skip failed section
            }
            $capacity = CapacitiesStore::get($sec['section_id'] ?? '', $type);
            $spaces = is_int($capacity) ? ($capacity - $secMembers) : '-';
            $grouped[$friendly]['sections'][] = [
                'name' => (string) ($sec['section_name'] ?? ''),
                'members' => $secMembers,
                'leaders' => $secLeaders,
                'youngLeaders' => $secYLs,
                'leaderInitials' => $leaderInitials ? implode(', ', $leaderInitials) : ' ',
                'youngLeaderInitials' => $ylInitials ? implode(', ', $ylInitials) : ' ',
                'capacity' => $capacity,
                'spaces' => $spaces,
            ];
            $grouped[$friendly]['subtotalMembers'] += $secMembers;
            $grouped[$friendly]['subtotalLeaders'] += $secLeaders;
            $grouped[$friendly]['subtotalYLs'] += $secYLs;
            $grouped[$friendly]['subtotalCapacity'] += is_int($capacity) ? $capacity : 0;
            $grouped[$friendly]['subtotalSpaces'] += is_int($spaces) ? $spaces : 0;
            $totals['members'] += $secMembers;
            $totals['leaders'] += $secLeaders;
            $totals['yls'] += $secYLs;
            $totals['capacity'] += is_int($capacity) ? $capacity : 0;
            $totals['spaces'] += is_int($spaces) ? $spaces : 0;
        }

        foreach ($grouped as $typeName => &$g) {
            $key = strtolower(str_replace([' ', '/'], '', $typeName));
            // map friendly names to waitingCounts keys
            $map = [
                'squirrels' => 'squirrels', 'beavers' => 'beavers', 'cubs' => 'cubs',
                'scouts' => 'scouts', 'explorers' => 'explorers',
            ];
            $g['subtotalWaiting'] = $waitingCounts[$map[$key] ?? $key] ?? 0;
        }
        unset($g);

        // Show Squirrels when waiting squirrels exist even without an earlyyears OSM section
        if (($waitingCounts['squirrels'] ?? 0) > 0 && !isset($grouped['Squirrels'])) {
            $grouped['Squirrels'] = [
                'sections' => [],
                'subtotalMembers' => 0,
                'subtotalLeaders' => 0,
                'subtotalYLs' => 0,
                'subtotalCapacity' => 0,
                'subtotalSpaces' => 0,
                'subtotalWaiting' => (int) $waitingCounts['squirrels'],
            ];
        } elseif (isset($grouped['Squirrels'])) {
            $grouped['Squirrels']['subtotalWaiting'] = (int) ($waitingCounts['squirrels'] ?? $grouped['Squirrels']['subtotalWaiting']);
        }

        $sorted = [];
        foreach (Config::SECTION_TYPE_ORDER as $name) {
            if (isset($grouped[$name])) $sorted[$name] = $grouped[$name];
        }
        foreach ($grouped as $k => $v) {
            if (!isset($sorted[$k])) $sorted[$k] = $v;
        }

        // Waiting of age for Beavers+Cubs+Scouts subtotals only (plus Squirrels when shown)
        $waitingOfAgeYouth = (int) ($waitingCounts['beavers'] ?? 0)
            + (int) ($waitingCounts['cubs'] ?? 0)
            + (int) ($waitingCounts['scouts'] ?? 0);
        $squirrelsWaiting = (int) ($waitingCounts['squirrels'] ?? 0);
        $explorersWaiting = (int) ($waitingCounts['explorers'] ?? 0);
        $tooYoungWaiting = (int) ($waitingCounts['tooYoung'] ?? 0);
        $totalWaiting = array_sum(array_column($sorted, 'subtotalWaiting'));

        // Charts use the same in-memory page data — no extra OSM calls.
        $chartSections = [];
        foreach ($sorted as $typeName => $g) {
            foreach ($g['sections'] as $s) {
                $cap = $s['capacity'];
                $spacesVal = $s['spaces'];
                $chartSections[] = [
                    'name' => (string) $s['name'],
                    'typeName' => (string) $typeName,
                    'members' => (int) $s['members'],
                    'spaces' => is_int($spacesVal) ? $spacesVal : null,
                    'capacity' => is_int($cap) ? $cap : null,
                    'overCapacity' => is_int($spacesVal) && $spacesVal < 0,
                ];
            }
        }
        $chartWaiting = [
            ['label' => 'Too young', 'band' => 'too-young', 'count' => $tooYoungWaiting],
            ['label' => 'Squirrels', 'band' => 'squirrels', 'count' => $squirrelsWaiting],
            ['label' => 'Beavers', 'band' => 'beavers', 'count' => (int) ($waitingCounts['beavers'] ?? 0)],
            ['label' => 'Cubs', 'band' => 'cubs', 'count' => (int) ($waitingCounts['cubs'] ?? 0)],
            ['label' => 'Scouts', 'band' => 'scouts', 'count' => (int) ($waitingCounts['scouts'] ?? 0)],
            ['label' => 'Explorers', 'band' => 'explorers', 'count' => $explorersWaiting],
        ];
        $chartFullness = [];
        foreach ($sorted as $typeName => $g) {
            $mem = (int) $g['subtotalMembers'];
            $cap = (int) $g['subtotalCapacity'];
            // Omit types with no numeric capacity ("Not set" contributes 0 and no section ints).
            $hasCap = false;
            foreach ($g['sections'] as $s) {
                if (is_int($s['capacity'])) {
                    $hasCap = true;
                    break;
                }
            }
            if (!$hasCap || $cap <= 0) {
                continue;
            }
            $band = strtolower(preg_replace('/[^a-z]/i', '', (string) $typeName) ?? '');
            if (str_contains(strtolower((string) $typeName), 'squirrel')) {
                $band = 'squirrels';
            } elseif (str_contains(strtolower((string) $typeName), 'beaver')) {
                $band = 'beavers';
            } elseif (str_contains(strtolower((string) $typeName), 'cub')) {
                $band = 'cubs';
            } elseif (str_contains(strtolower((string) $typeName), 'explorer')) {
                $band = 'explorers';
            } elseif (str_contains(strtolower((string) $typeName), 'scout')) {
                $band = 'scouts';
            } else {
                $band = 'other';
            }
            $chartFullness[] = [
                'typeName' => (string) $typeName,
                'band' => $band,
                'members' => $mem,
                'capacity' => $cap,
                'pct' => (int) round(($mem / $cap) * 100),
            ];
        }

        $chartMaxMembersSpaces = 1;
        foreach ($chartSections as $s) {
            $chartMaxMembersSpaces = max($chartMaxMembersSpaces, abs((int) $s['members']), abs((int) ($s['spaces'] ?? 0)));
        }
        $chartMaxWaiting = 1;
        foreach ($chartWaiting as $w) {
            $chartMaxWaiting = max($chartMaxWaiting, (int) $w['count']);
        }

        return [
            'grouped' => $sorted,
            'totalMembers' => $totals['members'],
            'totalLeaders' => $totals['leaders'],
            'totalYLs' => $totals['yls'],
            'totalCapacity' => $totals['capacity'],
            'totalSpaces' => $totals['spaces'],
            'totalWaiting' => $waitingOfAgeYouth,
            'waitingIncomplete' => $waitingIncomplete,
            'squirrelsWaiting' => $squirrelsWaiting,
            'explorersWaiting' => $explorersWaiting,
            'tooYoungWaiting' => $tooYoungWaiting,
            'totalWaitingFull' => $waitingOfAgeYouth + $squirrelsWaiting + $tooYoungWaiting + $explorersWaiting,
            'dataUpdated' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('d/m/y H:i'),
            'chartSections' => $chartSections,
            'chartWaiting' => $chartWaiting,
            'chartFullness' => $chartFullness,
            'chartMaxMembersSpaces' => $chartMaxMembersSpaces,
            'chartMaxWaiting' => $chartMaxWaiting,
        ];
    }
}
