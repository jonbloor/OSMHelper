<?php
declare(strict_types=1);
namespace App\Members;

/**
 * OSM's special patrols, shared by Members, Checks and Young Leaders.
 * OSM uses patrol id -2 for the Leaders patrol and -3 for the Young Leaders patrol.
 */
final class Patrols
{
    public const LEADERS_ID = '-2';
    public const YOUNG_LEADERS_ID = '-3';

    /** Young Leaders patrol, by OSM's patrol id (-3) when given, else by its name. */
    public static function isYoungLeader(string $patrolName, int|string|null $patrolId = null): bool
    {
        if ($patrolId !== null && trim((string) $patrolId) === self::YOUNG_LEADERS_ID) {
            return true;
        }
        $p = strtolower(trim($patrolName));
        if ($p === '') {
            return false;
        }
        if (str_contains($p, 'young leader')) {
            return true;
        }
        return $p === 'yl' || $p === 'yls' || str_starts_with($p, 'yl ');
    }

    /** Leaders patrol, by OSM's patrol id (-2) when given, else by its exact name. */
    public static function isLeader(string $patrolName, int|string|null $patrolId = null): bool
    {
        if ($patrolId !== null && trim((string) $patrolId) === self::LEADERS_ID) {
            return true;
        }
        return trim($patrolName) === 'Leaders';
    }
}
