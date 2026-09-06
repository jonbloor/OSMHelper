<?php
declare(strict_types=1);
namespace App\Store;
use App\Config;
final class CapacitiesStore
{
    public static function get(string|int $sectionId, string $sectionType): int|string
    {
        $id = (string) $sectionId;
        $sessionCaps = $_SESSION['capacities'] ?? [];
        if (is_array($sessionCaps) && isset($sessionCaps[$id]) && is_numeric($sessionCaps[$id])) {
            return (int) $sessionCaps[$id];
        }
        $defaults = Config::DEFAULT_CAPACITIES;
        return $defaults[$sectionType] ?? 'Not set';
    }
}
