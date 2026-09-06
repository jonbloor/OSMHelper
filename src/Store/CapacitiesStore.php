<?php
declare(strict_types=1);
namespace App\Store;
use App\Config;
final class CapacitiesStore
{
    public static function get(string|int $sectionId, string $sectionType): int|string
    {
        $id = (string) $sectionId;
        $caps = SettingsStore::get('capacities', []);
        if (!is_array($caps)) {
            $caps = [];
        }
        // also honour in-session override if present (same request after save)
        $sessionCaps = $_SESSION['capacities'] ?? null;
        if (is_array($sessionCaps) && isset($sessionCaps[$id]) && is_numeric($sessionCaps[$id])) {
            return (int) $sessionCaps[$id];
        }
        if (isset($caps[$id]) && is_numeric($caps[$id])) {
            return (int) $caps[$id];
        }
        return Config::DEFAULT_CAPACITIES[$sectionType] ?? 'Not set';
    }
}
