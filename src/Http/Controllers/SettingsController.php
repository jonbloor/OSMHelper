<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Config;
use App\Http\Auth;
use App\Osm\OsmApi;
use App\Store\SettingsStore;
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

        App::render('settings.twig', Auth::baseContext([
            'title' => 'Settings',
            'displayCutoffs' => $displayCutoffs,
            'displaySections' => $youthSections,
            'allSections' => $allSections,
            'equipmentSectionId' => $equipmentSectionId,
            'equipmentSectionType' => $equipmentSectionType,
            'financeSectionId' => $financeSectionId,
            'financeSectionType' => $financeSectionType,
            'saved' => !empty($saved),
        ]));
    }

    public function updateCutoffs(): void
    {
        Auth::requireLogin();
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
}
