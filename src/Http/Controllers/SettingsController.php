<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Http\Csrf;
use App\App;
use App\Config;
use App\Http\Auth;
use App\Osm\OsmApi;
use App\Store\SettingsStore;
use App\Store\WordpressSiteKeyStore;
use App\Waiting\WaitingListService as WL;
use Throwable;
final class SettingsController
{
    public function index(): void
    {
        $token = Auth::requireLogin();
        $api = new OsmApi();
        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable) {
            $sections = [];
        }
        $saved = SettingsStore::all();
        $cutoffs = array_merge(Config::DEFAULT_CUTOFFS, is_array($saved['cutoffs'] ?? null) ? $saved['cutoffs'] : []);
        $capacities = is_array($saved['capacities'] ?? null) ? $saved['capacities'] : [];
        $visible = is_array($saved['visibleSections'] ?? null) ? $saved['visibleSections'] : [];
        $equipmentSectionId = (string) ($saved['equipmentSectionId'] ?? '');
        $equipmentSectionType = (string) ($saved['equipmentSectionType'] ?? '');
        $financeSectionId = (string) ($saved['financeSectionId'] ?? '');
        $financeSectionType = (string) ($saved['financeSectionType'] ?? '');
        $equipmentLocations = self::normaliseLocations($saved['equipmentLocations'] ?? null);

        $displayCutoffs = [];
        foreach (['squirrels', 'beavers', 'cubs', 'scouts', 'explorers'] as $key) {
            $decimal = (float) ($cutoffs[$key] ?? 0);
            $years = (int) floor($decimal);
            $months = (int) round(($decimal - $years) * 12);
            $displayCutoffs[$key] = ['years' => $years, 'months' => $months];
        }

        $excluded = ['waiting', 'unknown'];
        $youthSections = [];
        $allSections = [];
        foreach ($sections as $sec) {
            if (!is_array($sec) || empty($sec['section_id'])) {
                continue;
            }
            $id = (string) $sec['section_id'];
            $type = (string) ($sec['section_type'] ?? '');
            $row = [
                'id' => $id,
                'name' => (string) ($sec['section_name'] ?? ''),
                'type' => $type,
                'typeLabel' => Config::FRIENDLY_SECTION_TYPES[$type] ?? $type,
            ];
            $allSections[] = $row;
            if (!in_array($type, $excluded, true) && $type !== 'adults') {
                $youthSections[] = array_merge($row, [
                    'capacity' => $capacities[$id] ?? (Config::DEFAULT_CAPACITIES[$type] ?? ''),
                    'defaultCapacity' => Config::DEFAULT_CAPACITIES[$type] ?? 'Not set',
                    'visible' => !isset($visible[$id]) || $visible[$id] !== false,
                ]);
            }
        }

        $waitingLists = WL::discoverLists($sections)['lists'];
        $osmUserId = (string) ($_SESSION['osmUserId'] ?? '');
        $wpRow = $osmUserId !== '' ? WordpressSiteKeyStore::findByOsmUserId($osmUserId) : null;
        $wpFlash = $_SESSION['wp_waiting_flash'] ?? null;
        unset($_SESSION['wp_waiting_flash']);

        App::render('settings.twig', Auth::baseContext([
            'title' => 'Settings',
            'displayCutoffs' => $displayCutoffs,
            'displaySections' => $youthSections,
            'allSections' => $allSections,
            'equipmentSectionId' => $equipmentSectionId,
            'equipmentSectionType' => $equipmentSectionType,
            'financeSectionId' => $financeSectionId,
            'financeSectionType' => $financeSectionType,
            'equipmentLocations' => $equipmentLocations,
            'saved' => !empty($saved),
            'waitingLists' => $waitingLists,
            'wpSiteKey' => is_array($wpRow) ? (string) ($wpRow['site_key'] ?? '') : '',
            'wpSectionId' => is_array($wpRow) ? (string) ($wpRow['section_id'] ?? '') : '',
            'wpBlocked' => is_array($wpRow) && !empty($wpRow['blocked_at']),
            'wpFlash' => is_array($wpFlash) ? $wpFlash : null,
            'wpEndpoint' => 'https://osmhelper.co.uk/api/waiting-list/submit',
        ]));
    }

    public function updateCutoffs(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $cutoffs = [];
        foreach (['squirrels', 'beavers', 'cubs', 'scouts', 'explorers'] as $type) {
            $years = (int) ($_POST[$type . '_years'] ?? 0);
            $months = (int) ($_POST[$type . '_months'] ?? 0);
            $cutoffs[$type] = $years + ($months / 12);
        }
        SettingsStore::merge(['cutoffs' => $cutoffs]);
        $_SESSION['cutoffs'] = $cutoffs;
        header('Location: /settings/');
        exit;
    }

    public function updateSections(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $capacities = [];
        $visible = [];
        foreach ($_POST as $key => $value) {
            if (str_starts_with((string) $key, 'capacity_')) {
                $id = substr((string) $key, 9);
                if (is_numeric($value)) {
                    $capacities[$id] = (int) $value;
                }
            } elseif (str_starts_with((string) $key, 'visible_')) {
                $id = substr((string) $key, 8);
                $visible[$id] = ($value === 'on');
            }
        }
        foreach ($capacities as $id => $_) {
            if (!isset($visible[$id])) {
                $visible[$id] = false;
            }
        }
        SettingsStore::merge([
            'capacities' => $capacities,
            'visibleSections' => $visible,
        ]);
        $_SESSION['capacities'] = $capacities;
        $_SESSION['visibleSections'] = $visible;
        header('Location: /settings/');
        exit;
    }

    public function updateToolSections(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $equip = (string) ($_POST['equipmentSectionId'] ?? '');
        $equipType = (string) ($_POST['equipmentSectionType'] ?? '');
        $finance = (string) ($_POST['financeSectionId'] ?? '');
        $financeType = (string) ($_POST['financeSectionType'] ?? '');

        // Allow type to be passed as "id|type" from a single select
        if (str_contains($equip, '|')) {
            [$equip, $equipType] = explode('|', $equip, 2);
        }
        if (str_contains($finance, '|')) {
            [$finance, $financeType] = explode('|', $finance, 2);
        }

        $patch = [
            'equipmentSectionId' => $equip,
            'equipmentSectionType' => $equipType !== '' ? $equipType : 'adults',
            'financeSectionId' => $finance,
            'financeSectionType' => $financeType !== '' ? $financeType : 'adults',
        ];
        SettingsStore::merge($patch);
        if ($finance !== '') {
            $_SESSION['financeSection'] = ['sectionId' => $finance, 'sectionType' => $patch['financeSectionType']];
        }
        header('Location: /settings/');
        exit;
    }

    public function updateEquipmentLocations(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $raw = $_POST['locations'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $locations = self::normaliseLocations($raw);
        SettingsStore::merge(['equipmentLocations' => $locations]);
        header('Location: /settings/');
        exit;
    }


    public function updateWordpressWaitingList(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
        $osmUserId = (string) ($_SESSION['osmUserId'] ?? '');
        if ($osmUserId === '') {
            $_SESSION['wp_waiting_flash'] = ['type' => 'error', 'message' => 'Could not identify your OSM user. Sign out and sign in again.'];
            header('Location: /settings/');
            exit;
        }

        $action = (string) ($_POST['wp_action'] ?? 'save');
        if ($action === 'disable') {
            WordpressSiteKeyStore::deleteForUser($osmUserId);
            $_SESSION['wp_waiting_flash'] = ['type' => 'success', 'message' => 'WordPress waiting-list site key removed.'];
            header('Location: /settings/');
            exit;
        }
        if ($action === 'clear_block') {
            $row = WordpressSiteKeyStore::findByOsmUserId($osmUserId);
            if ($row !== null) {
                WordpressSiteKeyStore::clearBlocked((int) $row['id']);
            }
            $_SESSION['wp_waiting_flash'] = ['type' => 'success', 'message' => 'WordPress waiting-list OSM block cleared. Fix the cause before accepting new form submissions.'];
            header('Location: /settings/');
            exit;
        }

        $sectionId = trim((string) ($_POST['waiting_list_section_id'] ?? ''));
        if ($sectionId === '' || !ctype_digit($sectionId)) {
            $_SESSION['wp_waiting_flash'] = ['type' => 'error', 'message' => 'Choose a waiting-list section.'];
            header('Location: /settings/');
            exit;
        }

        $api = new OsmApi();
        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable) {
            $sections = [];
        }
        $lists = WL::discoverLists($sections)['lists'];
        $sectionName = '';
        $allowed = false;
        foreach ($lists as $list) {
            if ($list['id'] === $sectionId) {
                $allowed = true;
                $sectionName = (string) ($list['name'] ?? '');
                break;
            }
        }
        if (!$allowed) {
            $_SESSION['wp_waiting_flash'] = ['type' => 'error', 'message' => 'That waiting list is not one this login can see.'];
            header('Location: /settings/');
            exit;
        }

        $access = (string) ($_SESSION['accessToken'] ?? '');
        if ($access === '') {
            $_SESSION['wp_waiting_flash'] = ['type' => 'error', 'message' => 'Missing OSM access token. Sign in again.'];
            header('Location: /settings/');
            exit;
        }
        $refresh = isset($_SESSION['refreshToken']) && is_string($_SESSION['refreshToken']) ? $_SESSION['refreshToken'] : null;
        $expires = isset($_SESSION['accessTokenExpiresAt']) && is_numeric($_SESSION['accessTokenExpiresAt'])
            ? (int) $_SESSION['accessTokenExpiresAt']
            : null;
        $regenerate = $action === 'regenerate' || !empty($_POST['regenerate_key']);

        try {
            $saved = WordpressSiteKeyStore::upsertForUser(
                $osmUserId,
                $sectionId,
                $sectionName,
                $access,
                $refresh,
                $expires,
                $regenerate
            );
        } catch (Throwable $e) {
            error_log('OSMHelper WordPress site key save failed: ' . $e->getMessage());
            $_SESSION['wp_waiting_flash'] = ['type' => 'error', 'message' => 'Could not save the WordPress site key.'];
            header('Location: /settings/');
            exit;
        }

        $msg = $saved['regenerated']
            ? 'WordPress site key created. Copy it into your WordPress plugin settings now.'
            : 'WordPress waiting-list settings saved. OSM token refreshed for intake.';
        $_SESSION['wp_waiting_flash'] = ['type' => 'success', 'message' => $msg];
        header('Location: /settings/');
        exit;
    }

    /**
     * Ordered unique non-empty location labels (trim; preserve order; drop blanks/dupes case-insensitively).
     * @param mixed $raw
     * @return list<string>
     */
    private static function normaliseLocations(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }
            $label = trim((string) $item);
            if ($label === '') {
                continue;
            }
            $key = strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $label;
        }
        return $out;
    }
}
