<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\App;
use App\Http\Auth;
use App\Http\Csrf;
use App\Osm\OsmApi;
use App\Osm\OsmDebug;
use App\Osm\OsmLists;
use App\Store\SettingsStore;
use Throwable;

/**
 * Top-awards: count activity+staged awards since startedsection, propose Chief Scout
 * progress strings, review/export, then optional write to challenge badge completed.
 */
final class TopAwardsController
{
    private const TYPE_CHALLENGE = 1;
    private const TYPE_ACTIVITY = 2;
    private const TYPE_STAGED = 3;

    /** @var array<string, int> */
    private const THRESHOLDS = [
        'beavers' => 4,
        'cubs' => 6,
        'scouts' => 6,
        'explorers' => 6,
    ];

    /** Youth section types eligible for Top-awards (skip adults/waiting). */
    private const YOUTH_TYPES = ['beavers', 'cubs', 'scouts', 'explorers'];

    public function index(): void
    {
        $token = Auth::requireLogin();
        $flash = $_SESSION['topAwardsFlash'] ?? null;
        unset($_SESSION['topAwardsFlash']);
        $api = new OsmApi();
        $error = null;
        $scopeHint = null;
        $sectionMeta = null;
        $rows = [];
        $notes = [];
        $challengeBadge = null;
        $sectionOptions = [];
        $threshold = null;
        $awardLabel = 'Gold';
        $debugMeta = [
            'awardedDateKeysSeen' => [],
            'badgeApiCalls' => 0,
            'mode' => null,
            'progressField' => null,
            'writeHypothesis' => null,
        ];

        try {
            $sections = $api->getDynamicSections($token);
            $sectionOptions = self::youthSectionOptions($sections);

            $picked = self::resolvePickedSection($sectionOptions);
            if ($picked === null) {
                App::render('top-awards.twig', Auth::baseContext([
                    'title' => 'Top awards',
                    'sectionOptions' => $sectionOptions,
                    'section' => null,
                    'threshold' => null,
                    'awardLabel' => $awardLabel,
                    'rows' => [],
                    'notes' => $sectionOptions === []
                        ? ['No Beavers / Cubs / Scouts / Explorers sections found on this OSM login.']
                        : ['Pick a youth section to calculate Top awards.'],
                    'error' => null,
                    'scopeHint' => null,
                    'debugMeta' => $debugMeta,
                    'challengeBadge' => null,
                    'needsSection' => true,
                    'changesCount' => 0,
                    'flash' => is_array($flash) ? $flash : null,
                ]));
                return;
            }

            $sectionId = $picked['id'];
            $sectionType = $picked['type'];
            $sectionName = $picked['name'];
            $termId = $picked['termId'];
            $threshold = self::THRESHOLDS[$sectionType];
            $awardLabel = self::awardLabelForType($sectionType);
            $sectionMeta = [
                'id' => $sectionId,
                'name' => $sectionName,
                'type' => $sectionType,
                'termId' => $termId,
            ];

            if ($termId === '' || $termId === '-1') {
                throw new \RuntimeException('Selected section is missing a current term.');
            }

            $calc = self::calculate($api, $token, $sectionMeta, $threshold, $debugMeta, $notes, $scopeHint);
            $rows = $calc['rows'];
            $challengeBadge = $calc['challengeBadge'];
            $debugMeta = $calc['debugMeta'];
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if (str_contains(strtolower($error), '403') || str_contains(strtolower($error), 'forbidden') || str_contains(strtolower($error), 'scope')) {
                $scopeHint = $error;
            }
        }

        $changesCount = 0;
        foreach ($rows as $r) {
            if (!empty($r['will_update'])) {
                $changesCount++;
            }
        }

        $payload = [
            'at' => gmdate('c'),
            'section' => $sectionMeta,
            'threshold' => $threshold,
            'awardLabel' => $awardLabel,
            'rows' => $rows,
            'notes' => $notes,
            'error' => $error,
            'scopeHint' => $scopeHint,
            'debugMeta' => $debugMeta,
            'challengeBadge' => $challengeBadge,
            'oauthScopesConfigured' => \App\Config::OAUTH_SCOPES,
        ];
        self::writeDryRun($payload);

        App::render('top-awards.twig', Auth::baseContext([
            'title' => 'Top awards',
            'sectionOptions' => $sectionOptions,
            'section' => $sectionMeta,
            'threshold' => $threshold,
            'awardLabel' => $awardLabel,
            'rows' => $rows,
            'notes' => $notes,
            'error' => $error,
            'scopeHint' => $scopeHint,
            'debugMeta' => $debugMeta,
            'challengeBadge' => $challengeBadge,
            'needsSection' => false,
            'changesCount' => $changesCount,
            'flash' => is_array($flash) ? $flash : null,
        ]));
    }

    public function select(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $sectionId = (string) ($_POST['sectionId'] ?? '');
        $sectionType = (string) ($_POST['sectionType'] ?? '');
        if (str_contains($sectionId, '|')) {
            [$sectionId, $sectionType] = explode('|', $sectionId, 2);
        }
        $sectionType = strtolower(trim($sectionType));
        if ($sectionId !== '' && isset(self::THRESHOLDS[$sectionType])) {
            SettingsStore::merge([
                'topAwardsSectionId' => $sectionId,
                'topAwardsSectionType' => $sectionType,
            ]);
        }
        header('Location: /top-awards/');
        exit;
    }

    public function review(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();

        $savedCopy = isset($_POST['saved_copy']) && (string) $_POST['saved_copy'] === '1';
        if (!$savedCopy) {
            $_SESSION['topAwardsFlash'] = [
                'type' => 'error',
                'message' => 'Tick “I have saved a copy” (export CSV / Excel / PDF) before reviewing updates.',
            ];
            header('Location: /top-awards/');
            exit;
        }

        $api = new OsmApi();
        $notes = [];
        $scopeHint = null;
        $debugMeta = [
            'awardedDateKeysSeen' => [],
            'badgeApiCalls' => 0,
            'mode' => null,
            'progressField' => null,
            'writeHypothesis' => null,
        ];

        try {
            $sections = $api->getDynamicSections($token);
            $sectionOptions = self::youthSectionOptions($sections);
            $picked = self::resolvePickedSection($sectionOptions);
            if ($picked === null) {
                throw new \RuntimeException('No Top-awards section selected.');
            }
            $sectionMeta = [
                'id' => $picked['id'],
                'name' => $picked['name'],
                'type' => $picked['type'],
                'termId' => $picked['termId'],
            ];
            $threshold = self::THRESHOLDS[$picked['type']];
            $awardLabel = self::awardLabelForType($picked['type']);
            $calc = self::calculate($api, $token, $sectionMeta, $threshold, $debugMeta, $notes, $scopeHint);
            $changes = [];
            foreach ($calc['rows'] as $r) {
                if (empty($r['will_update'])) {
                    continue;
                }
                $changes[] = [
                    'scoutid' => $r['scoutid'],
                    'name' => $r['name'],
                    'patrol' => $r['patrol'],
                    'current' => (string) ($r['current_completed'] ?? ''),
                    'proposed' => (string) $r['proposed'],
                    'total' => $r['total'],
                ];
            }
            if ($calc['challengeBadge'] === null) {
                throw new \RuntimeException(
                    'Could not identify the Chief Scout / top challenge badge for this section. '
                    . implode(' ', $notes)
                );
            }
            if ($changes === []) {
                $_SESSION['topAwardsFlash'] = [
                    'type' => 'info',
                    'message' => 'No progress strings need updating (current already matches proposed, or no youth rows).',
                ];
                header('Location: /top-awards/');
                exit;
            }

            $_SESSION['topAwardsPending'] = [
                'at' => time(),
                'section' => $sectionMeta,
                'threshold' => $threshold,
                'awardLabel' => $awardLabel,
                'challengeBadge' => $calc['challengeBadge'],
                'progressField' => $calc['debugMeta']['progressField'] ?? 'completed',
                'changes' => $changes,
            ];

            App::render('top-awards-confirm.twig', Auth::baseContext([
                'title' => 'Confirm Top awards write',
                'section' => $sectionMeta,
                'threshold' => $threshold,
                'awardLabel' => $awardLabel,
                'challengeBadge' => $calc['challengeBadge'],
                'changes' => $changes,
                'progressField' => $calc['debugMeta']['progressField'] ?? 'completed',
                'notes' => $notes,
            ]));
        } catch (Throwable $e) {
            $_SESSION['topAwardsFlash'] = [
                'type' => 'error',
                'message' => 'Review failed: ' . $e->getMessage(),
            ];
            header('Location: /top-awards/');
            exit;
        }
    }

    public function apply(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();

        $pending = $_SESSION['topAwardsPending'] ?? null;
        unset($_SESSION['topAwardsPending']);
        if (!is_array($pending) || empty($pending['changes']) || empty($pending['section']) || empty($pending['challengeBadge'])) {
            $_SESSION['topAwardsFlash'] = [
                'type' => 'error',
                'message' => 'Nothing to apply — open Top awards, export a copy, then Review updates again.',
            ];
            header('Location: /top-awards/');
            exit;
        }

        // Stale pending (>30 min) discarded
        $at = (int) ($pending['at'] ?? 0);
        if ($at < time() - 1800) {
            $_SESSION['topAwardsFlash'] = [
                'type' => 'error',
                'message' => 'Confirm session expired. Recalculate and review again before writing.',
            ];
            header('Location: /top-awards/');
            exit;
        }

        $section = $pending['section'];
        $badge = $pending['challengeBadge'];
        $progressField = (string) ($pending['progressField'] ?? 'completed');
        $awardLabel = (string) ($pending['awardLabel'] ?? 'Gold');
        $api = new OsmApi();
        $results = [];
        $anyOk = false;
        $writePathUsed = null;
        $delayUs = 150000;

        foreach ($pending['changes'] as $i => $change) {
            if (!is_array($change)) {
                continue;
            }
            if ($i > 0) {
                usleep($delayUs);
            }
            $write = self::writeProgress(
                $api,
                $token,
                $section,
                $badge,
                $progressField,
                (string) $change['scoutid'],
                (string) $change['proposed']
            );
            if ($write['ok']) {
                $anyOk = true;
            }
            if ($writePathUsed === null && !empty($write['path'])) {
                $writePathUsed = $write['path'];
            }
            $results[] = [
                'scoutid' => $change['scoutid'],
                'name' => $change['name'],
                'current' => $change['current'],
                'proposed' => $change['proposed'],
                'ok' => $write['ok'],
                'message' => $write['message'],
                'path' => $write['path'],
            ];
            OsmDebug::log('top_awards_write', [
                'scoutid' => $change['scoutid'],
                'ok' => $write['ok'],
                'path' => $write['path'],
                'message' => $write['message'],
            ]);
        }

        App::render('top-awards-result.twig', Auth::baseContext([
            'title' => 'Top awards write result',
            'section' => $section,
            'awardLabel' => $awardLabel,
            'challengeBadge' => $badge,
            'results' => $results,
            'wrote' => $anyOk,
            'writePathUsed' => $writePathUsed,
        ]));
    }

    /**
     * Attempt OSM write for one member's challenge progress string.
     * Tries legacy challenges.php updatesingle, then /ext/badges/records/ variants.
     * Never invents silent success.
     *
     * @param array{id:string,name:string,type:string,termId:string} $section
     * @param array<string, mixed> $badge
     * @return array{ok:bool,message:string,path:?string}
     */
    private static function writeProgress(
        OsmApi $api,
        string $token,
        array $section,
        array $badge,
        string $progressField,
        string $memberId,
        string $proposed
    ): array {
        $chal = (string) ($badge['chal'] ?? $badge['shortname'] ?? $badge['osm_key'] ?? '');
        $badgeId = (string) ($badge['badge_id'] ?? '');
        $badgeVersion = (string) ($badge['badge_version'] ?? '0');
        $col = $progressField !== '' ? $progressField : 'completed';
        $sectionId = $section['id'];
        $sectionType = $section['type'];
        $termId = $section['termId'];

        $attempts = [];

        // 1) Legacy challenges.php updatesingle
        $attempts[] = [
            'label' => 'challenges.php?action=updatesingle',
            'path' => 'challenges.php?action=updatesingle',
            'form' => [
                'action' => 'updatesingle',
                'id' => $memberId,
                'col' => $col,
                'value' => $proposed,
                'chal' => $chal !== '' ? $chal : $badgeId,
                'sectionid' => $sectionId,
                'section' => $sectionType,
                'type' => 'challenge',
                'termid' => $termId,
            ],
        ];

        // 2) /ext/badges/records/ action=updatesingle
        $attempts[] = [
            'label' => '/ext/badges/records/?action=updatesingle',
            'path' => '/ext/badges/records/?action=updatesingle',
            'form' => [
                'id' => $memberId,
                'member_id' => $memberId,
                'col' => $col,
                'column' => $col,
                'value' => $proposed,
                'chal' => $chal !== '' ? $chal : $badgeId,
                'badge_id' => $badgeId,
                'badge_version' => $badgeVersion,
                'sectionid' => $sectionId,
                'section_id' => $sectionId,
                'section' => $sectionType,
                'type' => 'challenge',
                'type_id' => self::TYPE_CHALLENGE,
                'term_id' => $termId,
                'termid' => $termId,
            ],
        ];

        // 3) /ext/badges/records/ action=update
        $attempts[] = [
            'label' => '/ext/badges/records/?action=update',
            'path' => '/ext/badges/records/?action=update',
            'form' => [
                'member_id' => $memberId,
                'scoutid' => $memberId,
                'column' => $col,
                'col' => $col,
                'value' => $proposed,
                'completed' => $proposed,
                'badge_id' => $badgeId,
                'badge_version' => $badgeVersion,
                'sectionid' => $sectionId,
                'section_id' => $sectionId,
                'section' => $sectionType,
                'type_id' => self::TYPE_CHALLENGE,
                'term_id' => $termId,
            ],
        ];

        $errors = [];
        foreach ($attempts as $attempt) {
            try {
                $res = $api->post($token, $attempt['path'], $attempt['form']);
                $ok = self::responseLooksSuccessful($res);
                if ($ok) {
                    return [
                        'ok' => true,
                        'message' => 'Updated via ' . $attempt['label'],
                        'path' => $attempt['label'],
                    ];
                }
                $snippet = self::briefResponse($res);
                $errors[] = $attempt['label'] . ' → unexpected response: ' . $snippet;
            } catch (Throwable $e) {
                $errors[] = $attempt['label'] . ' → ' . $e->getMessage();
            }
        }

        return [
            'ok' => false,
            'message' => 'All write attempts failed. ' . implode(' | ', $errors),
            'path' => null,
        ];
    }

    /** @param array<string, mixed> $res */
    private static function responseLooksSuccessful(array $res): bool
    {
        if (($res['ok'] ?? null) === true || ($res['ok'] ?? null) === 'true' || ($res['ok'] ?? null) === 1) {
            return true;
        }
        if (($res['status'] ?? null) === true || ($res['status'] ?? null) === 'ok') {
            return true;
        }
        if (($res['result'] ?? null) === 'ok' || ($res['success'] ?? null) === true) {
            return true;
        }
        // Some legacy endpoints echo back the value / sectionid
        if (isset($res['sectionid']) || isset($res['data']['ok'])) {
            if (($res['data']['ok'] ?? null) === true) {
                return true;
            }
            // Avoid treating empty/error JSON as success
            if (!isset($res['error']) && !isset($res['status'])) {
                // Weak signal — require something positive
                return false;
            }
        }
        if (isset($res['error']) && $res['error'] !== null && $res['error'] !== '' && $res['error'] !== false) {
            return false;
        }
        return false;
    }

    /** @param array<string, mixed> $res */
    private static function briefResponse(array $res): string
    {
        $json = json_encode($res, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '(unencodable)';
        }
        return strlen($json) > 240 ? substr($json, 0, 240) . '…' : $json;
    }

    /**
     * @param list<array<string, mixed>> $sectionOptions
     * @return array{id:string,name:string,type:string,termId:string}|null
     */
    private static function resolvePickedSection(array $sectionOptions): ?array
    {
        $wantId = '';
        $wantType = '';

        $q = (string) ($_GET['section'] ?? '');
        if ($q !== '') {
            if (str_contains($q, '|')) {
                [$wantId, $wantType] = explode('|', $q, 2);
            } else {
                $wantId = $q;
            }
        }
        if ($wantId === '') {
            $wantId = (string) SettingsStore::get('topAwardsSectionId', '');
            $wantType = (string) SettingsStore::get('topAwardsSectionType', '');
        }
        $wantType = strtolower(trim($wantType));

        foreach ($sectionOptions as $opt) {
            if ($wantId !== '' && $opt['id'] === $wantId) {
                return $opt;
            }
        }
        // type-only match if unique
        if ($wantType !== '' && isset(self::THRESHOLDS[$wantType])) {
            $matches = array_values(array_filter($sectionOptions, static fn ($o) => $o['type'] === $wantType));
            if (count($matches) === 1) {
                return $matches[0];
            }
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return list<array{id:string,name:string,type:string,termId:string}>
     */
    private static function youthSectionOptions(array $sections): array
    {
        $out = [];
        foreach ($sections as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $type = strtolower((string) ($sec['section_type'] ?? $sec['section'] ?? ''));
            if (!isset(self::THRESHOLDS[$type])) {
                continue;
            }
            $id = (string) ($sec['section_id'] ?? $sec['sectionid'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string) ($sec['section_name'] ?? $id),
                'type' => $type,
                'termId' => (string) ($sec['current_term_id'] ?? ''),
            ];
        }
        usort($out, static function ($a, $b) {
            $order = array_flip(self::YOUTH_TYPES);
            $oa = $order[$a['type']] ?? 99;
            $ob = $order[$b['type']] ?? 99;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    private static function awardLabelForType(string $type): string
    {
        return match ($type) {
            'beavers' => 'Bronze',
            'cubs' => 'Silver',
            'scouts' => 'Gold',
            'explorers' => 'Chief Scout',
            default => 'Top award',
        };
    }

    /**
     * @param array{id:string,name:string,type:string,termId:string} $sectionMeta
     * @param array<string, mixed> $debugMeta
     * @param list<string> $notes
     * @return array{rows:list<array<string,mixed>>,challengeBadge:?array<string,mixed>,debugMeta:array<string,mixed>}
     */
    private static function calculate(
        OsmApi $api,
        string $token,
        array $sectionMeta,
        int $threshold,
        array $debugMeta,
        array &$notes,
        ?string &$scopeHint
    ): array {
        $sectionId = $sectionMeta['id'];
        $sectionType = $sectionMeta['type'];
        $sectionName = $sectionMeta['name'];
        $termId = $sectionMeta['termId'];

        $listRes = $api->get($token, '/ext/members/contact/', [
            'action' => 'getListOfMembers',
            'sectionid' => $sectionId,
            'termid' => $termId,
            'section' => $sectionType,
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

        $awardsByMember = [];
        $byPersonOk = false;
        try {
            $byPerson = $api->get($token, '/ext/badges/badgesbyperson/', [
                'action' => 'loadBadgesByMember',
                'section' => $sectionType,
                'sectionid' => $sectionId,
                'term_id' => $termId,
            ]);
            $debugMeta['badgeApiCalls']++;
            OsmDebug::log('top_awards_byperson_keys', [
                'keys' => array_keys($byPerson),
                'data0' => is_array($byPerson['data'][0] ?? null)
                    ? array_keys($byPerson['data'][0])
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
                    $parsed = self::parseAward($b, $debugMeta);
                    if ($parsed === null) {
                        continue;
                    }
                    if ($typeId !== 0 && $typeId !== self::TYPE_ACTIVITY && $typeId !== self::TYPE_STAGED) {
                        continue;
                    }
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
                        'section' => $sectionType,
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
                                'section' => $sectionType,
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

        // Challenge badge (Chief Scout Bronze/Silver/Gold/…) — current completed strings
        $challengeBadge = null;
        $currentByMember = [];
        try {
            $chalAvail = $api->get($token, '/ext/badges/records/', [
                'action' => 'getAvailableBadges',
                'section' => $sectionType,
                'section_id' => $sectionId,
                'sectionid' => $sectionId,
                'term_id' => $termId,
                'type_id' => self::TYPE_CHALLENGE,
                'payload' => 1,
                'context' => 'none',
            ]);
            $debugMeta['badgeApiCalls']++;
            $chalList = OsmLists::items($chalAvail);
            if ($chalList === [] && isset($chalAvail['data']) && is_array($chalAvail['data'])) {
                $chalList = array_values(array_filter($chalAvail['data'], 'is_array'));
            }
            $pick = self::pickChallengeBadge($chalList, $sectionType, $notes);
            if ($pick !== null) {
                $challengeBadge = $pick;
                $recs = $api->get($token, '/ext/badges/records/', [
                    'action' => 'getBadgeRecords',
                    'section' => $sectionType,
                    'sectionid' => $sectionId,
                    'section_id' => $sectionId,
                    'term_id' => $termId,
                    'type_id' => self::TYPE_CHALLENGE,
                    'badge_id' => $pick['badge_id'],
                    'badge_version' => $pick['badge_version'],
                    'payload' => 1,
                    'member_id' => 0,
                ]);
                $debugMeta['badgeApiCalls']++;
                OsmDebug::log('top_awards_challenge_records', [
                    'badge' => $pick,
                    'top_keys' => array_keys($recs),
                    'data_keys' => isset($recs['data']) && is_array($recs['data']) ? array_keys($recs['data']) : null,
                    'details' => $recs['data']['details'] ?? ($recs['details'] ?? null),
                    'requirements' => $recs['data']['requirements'] ?? ($recs['requirements'] ?? null),
                    'sample_member' => self::badgeRecordMembers($recs)[0] ?? null,
                ]);
                $field = self::detectProgressField($recs);
                $debugMeta['progressField'] = $field;
                foreach (self::badgeRecordMembers($recs) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $sid = self::memberIdFromRow($row);
                    if ($sid === '') {
                        continue;
                    }
                    $currentByMember[$sid] = self::progressValueFromRow($row, $field);
                }
            }
        } catch (Throwable $e) {
            $notes[] = 'Challenge badge fetch failed: ' . $e->getMessage();
            if (str_contains(strtolower($e->getMessage()), '403') || str_contains(strtolower($e->getMessage()), 'forbidden')) {
                $scopeHint = $e->getMessage();
            }
        }

        $rows = [];
        foreach ($youth as $sid => $m) {
            $start = trim((string) ($m['startedsection'] ?? ''));
            $startTs = $start !== '' ? strtotime($start) : false;
            $activity = 0;
            $staged = 0;
            $unknownType = 0;
            $skippedNoDate = 0;
            $skippedBefore = 0;
            $missingStart = ($start === '' || $startTs === false);

            $seen = [];
            foreach ($awardsByMember[$sid] ?? [] as $a) {
                $typeId = (int) ($a['type_id'] ?? 0);
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
            $proposed = self::goldString($total, $threshold);
            $current = (string) ($currentByMember[$sid] ?? '');
            $willUpdate = $challengeBadge !== null
                && !$missingStart
                && self::normalizeProgress($current) !== self::normalizeProgress($proposed);

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
                'gold' => $proposed,
                'proposed' => $proposed,
                'current_completed' => $current,
                'will_update' => $willUpdate,
                'missing_start' => $missingStart,
                'skipped_no_date' => $skippedNoDate,
                'skipped_before_start' => $skippedBefore,
            ];
        }

        usort($rows, static function ($a, $b) {
            return strcasecmp((string) $a['lastname'] . $a['firstname'], (string) $b['lastname'] . $b['firstname']);
        });

        return [
            'rows' => $rows,
            'challengeBadge' => $challengeBadge,
            'debugMeta' => $debugMeta,
        ];
    }

    /**
     * @param list<array<string, mixed>> $badgeList
     * @param list<string> $notes
     * @return array<string, mixed>|null
     */
    private static function pickChallengeBadge(array $badgeList, string $sectionType, array &$notes): ?array
    {
        $candidates = [];
        foreach ($badgeList as $b) {
            if (!is_array($b)) {
                continue;
            }
            $name = (string) ($b['name'] ?? $b['badge'] ?? '');
            if ($name === '') {
                continue;
            }
            $match = false;
            if ($sectionType === 'beavers' && preg_match('/bronze/i', $name)) {
                $match = true;
            } elseif ($sectionType === 'cubs' && preg_match('/silver/i', $name)) {
                $match = true;
            } elseif ($sectionType === 'scouts' && preg_match('/gold/i', $name)) {
                $match = true;
            } elseif ($sectionType === 'explorers') {
                if (preg_match('/platinum|diamond/i', $name) || preg_match('/chief/i', $name)) {
                    $match = true;
                }
            }
            if (!$match) {
                continue;
            }
            $chal = (string) ($b['shortname'] ?? $b['osm_key'] ?? $b['badge_identifier'] ?? $b['identifier'] ?? $b['chal'] ?? '');
            if ($chal === '' && isset($b['config']) && is_string($b['config'])) {
                $cfg = json_decode($b['config'], true);
                if (is_array($cfg)) {
                    $chal = (string) ($cfg['shortname'] ?? $cfg['osm_key'] ?? $cfg['identifier'] ?? '');
                }
            }
            $candidates[] = [
                'badge_id' => (string) ($b['badge_id'] ?? $b['badgeid'] ?? ''),
                'badge_version' => (string) ($b['badge_version'] ?? $b['badgeversion'] ?? '0'),
                'name' => $name,
                'chal' => $chal,
                'shortname' => $chal,
                'osm_key' => $chal,
                'raw_keys' => array_keys($b),
            ];
        }

        if ($candidates === []) {
            $names = [];
            foreach ($badgeList as $b) {
                if (is_array($b) && !empty($b['name'])) {
                    $names[] = (string) $b['name'];
                }
            }
            $notes[] = 'No matching Chief Scout / top challenge badge for type=' . $sectionType
                . '. Available challenge names: ' . ($names === [] ? '(none)' : implode(', ', $names));
            return null;
        }

        if (count($candidates) > 1) {
            // Prefer name containing "Chief Scout"
            usort($candidates, static function ($a, $b) {
                $sa = preg_match('/chief\s*scout/i', $a['name']) ? 0 : 1;
                $sb = preg_match('/chief\s*scout/i', $b['name']) ? 0 : 1;
                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }
                // explorers: platinum before diamond before other chief
                $rank = static function (string $n): int {
                    if (preg_match('/platinum/i', $n)) {
                        return 0;
                    }
                    if (preg_match('/diamond/i', $n)) {
                        return 1;
                    }
                    return 2;
                };
                return $rank($a['name']) <=> $rank($b['name']);
            });
            $picked = $candidates[0];
            $notes[] = 'Ambiguous challenge badge match; using "' . $picked['name'] . '" from: '
                . implode(', ', array_map(static fn ($c) => $c['name'], $candidates));
            return $picked;
        }

        return $candidates[0];
    }

    /** @param array<string, mixed> $recs */
    private static function detectProgressField(array $recs): string
    {
        $members = self::badgeRecordMembers($recs);
        $sample = $members[0] ?? null;
        if (!is_array($sample)) {
            // details/requirements may name columns
            $details = $recs['data']['details'] ?? ($recs['details'] ?? null);
            if (is_array($details)) {
                foreach ($details as $d) {
                    if (!is_array($d)) {
                        continue;
                    }
                    $fn = (string) ($d['field'] ?? $d['column'] ?? $d['name'] ?? $d['id'] ?? '');
                    if ($fn !== '' && preg_match('/completed|progress|gold|bronze|silver/i', $fn)) {
                        return $fn;
                    }
                }
            }
            return 'completed';
        }

        // Prefer keys whose value looks like "x 3/6" or "3/6"
        foreach ($sample as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $sv = is_scalar($v) ? trim((string) $v) : '';
            if ($sv !== '' && preg_match('/^x?\s*\d+\s*\/\s*\d+$/i', $sv)) {
                return $k;
            }
        }
        foreach (['completed', 'progress', 'value', 'a'] as $k) {
            if (array_key_exists($k, $sample)) {
                return $k;
            }
        }
        return 'completed';
    }

    /** @param array<string, mixed> $row */
    private static function progressValueFromRow(array $row, string $field): string
    {
        if ($field !== '' && array_key_exists($field, $row)) {
            return trim((string) $row[$field]);
        }
        if (isset($row['completed'])) {
            return trim((string) $row['completed']);
        }
        return '';
    }

    private static function normalizeProgress(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s;
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
        return OsmLists::items($res);
    }

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
