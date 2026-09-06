<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Config;
use App\Store\SettingsStore;
use App\Http\Auth;
use App\Osm\OsmApi;
use App\Osm\OsmLists;
use Throwable;
final class WaitingListController
{
    /** @param array<string, float|int> $cutoffs */
    private static function idealSection(float $age, array $cutoffs): string
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

    public function index(): void
    {
        $token = Auth::requireLogin();
        $saved = SettingsStore::all();
        $cutoffs = array_merge(Config::DEFAULT_CUTOFFS, is_array($saved['cutoffs'] ?? null) ? $saved['cutoffs'] : []);
        $api = new OsmApi();
        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable $e) {
            App::render('error.twig', Auth::baseContext([
                'title' => 'Waiting list',
                'message' => 'Could not load sections from OSM.',
            ]));
            return;
        }

        $waiting = null;
        foreach ($sections as $sec) {
            if (!is_array($sec)) continue;
            $type = (string) ($sec['section_type'] ?? '');
            $name = strtolower((string) ($sec['section_name'] ?? ''));
            if ($type === 'waiting' || str_contains($name, 'waiting')) {
                $waiting = $sec;
                break;
            }
        }
        if ($waiting === null) {
            App::render('error.twig', Auth::baseContext([
                'title' => 'Waiting list',
                'message' => 'No waiting list section found in your OSM access.',
            ]));
            return;
        }

        $waitingId = $waiting['section_id'];
        $waitingType = $waiting['section_type'] ?? 'waiting';
        $listCount = 0;
        $listError = null;
        try {
            $listRes = $api->get($token, '/ext/members/contact/', [
                'action' => 'getListOfMembers',
                'sectionid' => $waitingId,
                'termid' => -1,
                'section' => $waitingType,
                'sort' => 'dob',
            ]);
            $listData = OsmLists::items($listRes);
            $listCount = count($listData);
        } catch (Throwable $e) {
            $listData = [];
            $listError = 'OSM waiting-list fetch failed.';
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
                // Prefer list row fields when individual is thin
                $dobRaw = $d['dob'] ?? $applicant['dob'] ?? '';
                $dob = strtotime((string) $dobRaw);
                $age = $dob !== false ? ($now - $dob) / (365.25 * 24 * 60 * 60) : null;
                $ageMonths = $dob !== false ? (int) floor(($now - $dob) / (30.4375 * 24 * 60 * 60)) : null;
                $ageDisplay = $ageMonths !== null
                    ? ((int) floor($ageMonths / 12)) . ' y ' . ($ageMonths % 12) . ' m'
                    : 'Unknown';
                $willing = 'N';
                if (isset($d['customfields']) && is_array($d['customfields'])) {
                    $willing = (string) ($d['customfields']['customfield_123'] ?? 'N');
                }
                $join = strtotime((string) ($d['joined'] ?? $d['applicationdate'] ?? $d['started'] ?? $applicant['joined'] ?? ''));
                $timeOnList = $join !== false ? (int) floor(($now - $join) / 86400) : 0;
                $leadersNotes = $joiningComments = $placeAccepted = '';
                try {
                    $custom = $api->get($token, '/ext/customdata/', [
                        'action' => 'getData',
                        'section_id' => $waitingId,
                        'associated_id' => $scoutid,
                        'associated_type' => 'member',
                        'context' => 'members',
                    ]);
                    $groups = $custom['data'] ?? [];
                    if (is_array($groups)) {
                        foreach ($groups as $group) {
                            if (!is_array($group) || ($group['identifier'] ?? '') !== 'customisable_data') continue;
                            foreach (($group['columns'] ?? []) as $col) {
                                if (!is_array($col)) continue;
                                $vn = (string) ($col['varname'] ?? '');
                                if ($vn === 'cf_notes') $leadersNotes = (string) ($col['value'] ?? '');
                                if ($vn === 'cf_joining_comments') $joiningComments = (string) ($col['value'] ?? '');
                                if ($vn === 'cf_place_accepted_') $placeAccepted = (string) ($col['value'] ?? '');
                            }
                        }
                    }
                } catch (Throwable) {}

                $ageScore = $age ?? 0.0;
                $scoreNum = $ageScore * 3 + (($willing === 'Y') ? 20 : 0) + ($timeOnList / 30);
                $applicants[] = [
                    'firstName' => (string) ($applicant['firstname'] ?? $d['firstname'] ?? ''),
                    'lastName' => (string) ($applicant['lastname'] ?? $d['lastname'] ?? ''),
                    'age' => $ageDisplay,
                    'timeOnList' => $timeOnList,
                    'willingToHelp' => $willing,
                    'leadersNotes' => $leadersNotes,
                    'joiningComments' => $joiningComments,
                    'placeAccepted' => $placeAccepted,
                    'idealSection' => self::idealSection($age ?? 0, $cutoffs),
                    'scoreNum' => $scoreNum,
                    'score' => number_format($scoreNum, 1),
                    'rank' => 0,
                ];
            } catch (Throwable) {
                $applicants[] = [
                    'firstName' => (string) ($applicant['firstname'] ?? ''),
                    'lastName' => (string) ($applicant['lastname'] ?? ''),
                    'age' => 'Unknown',
                    'timeOnList' => 'N/A',
                    'willingToHelp' => 'N/A',
                    'leadersNotes' => '',
                    'joiningComments' => '',
                    'placeAccepted' => '',
                    'idealSection' => 'Unknown',
                    'scoreNum' => -INF,
                    'score' => 'N/A',
                    'rank' => 0,
                ];
            }
        }
        usort($applicants, static fn ($a, $b) => ($b['scoreNum'] <=> $a['scoreNum']));
        foreach ($applicants as $i => &$a) { $a['rank'] = $i + 1; }
        unset($a);

        App::render('waiting-list.twig', Auth::baseContext([
            'title' => 'Waiting list',
            'applicants' => $applicants,
            'applicantCount' => count($applicants),
            'listCount' => $listCount,
            'waitingSectionName' => (string) ($waiting['section_name'] ?? 'Waiting list'),
            'listError' => $listError,
            'fetchedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('d/m/y H:i'),
        ]));
    }
}
