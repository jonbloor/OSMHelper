<?php
declare(strict_types=1);
namespace App\Members;

/**
 * OSM's special patrols, shared by Members, Checks and Young Leaders.
 * OSM uses patrol id -2 for the Leaders patrol. Young Leader patrols are matched by name only,
 * with the same rule everywhere (no trimming), exactly as Members / Checks always did.
 */
final class Patrols
{
    public const LEADERS_ID = '-2';

    /**
     * Young Leader patrol: the Members / Checks rule, kept exactly as it was before the Young
     * Leaders page. Name only and NOT trimmed, so " yl" or "yl\t" are ordinary patrols.
     */
    public static function isYoungLeaderPatrolName(string $patrolName): bool
    {
        $p = strtolower($patrolName);
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
