<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Config;
use App\Http\Auth;
use App\Osm\OsmApi;
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
        $excluded = ['waiting', 'adults', 'unknown'];
        $filtered = array_values(array_filter($sections, static fn ($s) => is_array($s) && !in_array($s['section_type'] ?? '', $excluded, true)));
        $cutoffs = array_merge(Config::DEFAULT_CUTOFFS, is_array($_SESSION['cutoffs'] ?? null) ? $_SESSION['cutoffs'] : []);
        $capacities = is_array($_SESSION['capacities'] ?? null) ? $_SESSION['capacities'] : [];
        $visible = is_array($_SESSION['visibleSections'] ?? null) ? $_SESSION['visibleSections'] : [];
        $displayCutoffs = [];
        foreach (['squirrels', 'beavers', 'cubs', 'scouts', 'explorers'] as $key) {
            $decimal = (float) ($cutoffs[$key] ?? 0);
            $years = (int) floor($decimal);
            $months = (int) round(($decimal - $years) * 12);
            $displayCutoffs[$key] = ['years' => $years, 'months' => $months];
        }
        $displaySections = [];
        foreach ($filtered as $sec) {
            $id = (string) ($sec['section_id'] ?? '');
            $type = (string) ($sec['section_type'] ?? '');
            $displaySections[] = [
                'id' => $id,
                'name' => (string) ($sec['section_name'] ?? ''),
                'type' => Config::FRIENDLY_SECTION_TYPES[$type] ?? $type,
                'defaultCapacity' => Config::DEFAULT_CAPACITIES[$type] ?? 'Not set',
                'capacity' => $capacities[$id] ?? (Config::DEFAULT_CAPACITIES[$type] ?? ''),
                'visible' => !isset($visible[$id]) || $visible[$id] !== false,
            ];
        }
        App::render('settings.twig', Auth::baseContext([
            'title' => 'Settings',
            'displayCutoffs' => $displayCutoffs,
            'displaySections' => $displaySections,
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
            if (str_starts_with($key, 'capacity_')) {
                $id = substr($key, 9);
                if (is_numeric($value)) $capacities[$id] = (int) $value;
            } elseif (str_starts_with($key, 'visible_')) {
                $id = substr($key, 8);
                $visible[$id] = ($value === 'on');
            }
        }
        // unchecked checkboxes absent — mark missing as false for known capacity keys
        foreach ($capacities as $id => $_) {
            if (!isset($visible[$id])) $visible[$id] = false;
        }
        $_SESSION['capacities'] = $capacities;
        $_SESSION['visibleSections'] = $visible;
        header('Location: /settings/');
        exit;
    }
}
