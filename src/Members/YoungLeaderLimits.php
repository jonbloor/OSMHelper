<?php
declare(strict_types=1);
namespace App\Members;

use App\Osm\OsmCache;

/**
 * Young Leaders allowance limits, kept per OSM user (not per session), so signing out and in
 * again doesn't reset them:
 *  - lastCallAt: when this user's last Young Leaders OSM call went out (the pause between calls);
 *  - refresh[section]: when Refresh was last pressed (the 5-minute cooldown) and the moment the
 *    reload started from (members fetched before it are reloaded);
 *  - tooLarge[section]: the member count of a section over the 150 limit, for 24 hours, so Load
 *    doesn't spend the list call again.
 * No names or member data: timestamps, section ids and counts only. One file per OSM user
 * (hashed id) in storage/yl-limits/ (dir 0700, files 0600). Unlike the Young Leaders copy of
 * members (OsmCache), this is NOT cleared at sign-out, by design.
 */
final class YoungLeaderLimits
{
    /** Entries older than this are dropped (the cached data they refer to has expired). */
    public const KEEP_SEC = 86400;

    private static ?string $dirOverride = null;

    public static function useDirectory(?string $dir): void
    {
        self::$dirOverride = $dir;
    }

    public static function dir(): string
    {
        return self::$dirOverride ?? (dirname(__DIR__, 2) . '/storage/yl-limits');
    }

    public static function lastCallAt(string $userId): ?float
    {
        $v = self::load($userId)['lastCallAt'] ?? null;
        return is_int($v) || is_float($v) ? (float) $v : null;
    }

    public static function setLastCallAt(string $userId, float $at): void
    {
        self::update($userId, static function (array $s) use ($at): array {
            $s['lastCallAt'] = $at;
            return $s;
        });
    }

    /** Unix time Refresh was last pressed for this section, or 0. */
    public static function lastRefreshAt(string $userId, string $sectionId): int
    {
        return (int) (self::load($userId)['refresh'][$sectionId]['at'] ?? 0);
    }

    /** Members fetched before this moment are reloaded (a Refresh in progress), or null. */
    public static function refreshSince(string $userId, string $sectionId): ?float
    {
        $v = self::load($userId)['refresh'][$sectionId]['since'] ?? null;
        return is_int($v) || is_float($v) ? (float) $v : null;
    }

    public static function startRefresh(string $userId, string $sectionId, float $since): void
    {
        self::update($userId, static function (array $s) use ($sectionId, $since): array {
            $s['refresh'][$sectionId] = ['at' => (int) $since, 'since' => $since];
            return $s;
        });
    }

    /** The reload finished: keep the cooldown, forget the "reload from" moment. */
    public static function endRefresh(string $userId, string $sectionId): void
    {
        self::update($userId, static function (array $s) use ($sectionId): array {
            if (isset($s['refresh'][$sectionId])) {
                unset($s['refresh'][$sectionId]['since']);
            }
            return $s;
        });
    }

    /** @return array{count:int, at:float}|null */
    public static function tooLarge(string $userId, string $sectionId): ?array
    {
        $t = self::load($userId)['tooLarge'][$sectionId] ?? null;
        if (!is_array($t) || !is_int($t['count'] ?? null) || !(is_int($t['at'] ?? null) || is_float($t['at'] ?? null))) {
            return null;
        }
        return ['count' => $t['count'], 'at' => (float) $t['at']];
    }

    public static function setTooLarge(string $userId, string $sectionId, ?int $count, float $at): void
    {
        self::update($userId, static function (array $s) use ($sectionId, $count, $at): array {
            if ($count === null) {
                unset($s['tooLarge'][$sectionId]);
            } else {
                $s['tooLarge'][$sectionId] = ['count' => $count, 'at' => $at];
            }
            return $s;
        });
    }

    private static function file(string $userId): string
    {
        return self::dir() . '/' . hash('sha256', 'yl-limits|' . $userId) . '.json';
    }

    /** @return array<string, mixed> */
    private static function load(string $userId): array
    {
        $raw = @file_get_contents(self::file($userId));
        $s = is_string($raw) ? json_decode($raw, true) : null;
        return self::prune(is_array($s) ? $s : []);
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private static function prune(array $s): array
    {
        $cut = OsmCache::currentMicrotime() - self::KEEP_SEC;
        if ((float) ($s['lastCallAt'] ?? 0) < $cut) {
            unset($s['lastCallAt']);
        }
        foreach (['refresh', 'tooLarge'] as $k) {
            if (!is_array($s[$k] ?? null)) {
                unset($s[$k]);
                continue;
            }
            foreach ($s[$k] as $sid => $e) {
                if (!is_array($e) || (float) ($k === 'refresh' ? ($e['since'] ?? $e['at'] ?? 0) : ($e['at'] ?? 0)) < $cut) {
                    unset($s[$k][$sid]);
                }
            }
        }
        return $s;
    }

    /** Read-modify-write under an exclusive lock (two tabs or sessions of the same user). */
    private static function update(string $userId, callable $fn): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $path = self::file($userId);
        $h = @fopen($path, 'c+');
        if ($h === false) {
            return;
        }
        @chmod($path, 0600);
        try {
            if (!flock($h, LOCK_EX)) {
                return;
            }
            $raw = stream_get_contents($h);
            $s = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $s = $fn(self::prune(is_array($s) ? $s : []));
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, (string) json_encode($s));
            fflush($h);
            flock($h, LOCK_UN);
        } finally {
            fclose($h);
        }
    }
}
