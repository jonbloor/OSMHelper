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
 *
 * Fails closed: every change is also kept in this session (so a broken store never makes the pause,
 * the cooldown or the too-large refusal vanish mid-session), reads take the stricter of the file and
 * the session, and when the file can't be saved canSave() is false and the controller refuses to
 * contact OSM at all (like OsmRateGuard when storage/osm-rate is unwritable).
 */
final class YoungLeaderLimits
{
    /** Shown when the limits can't be saved: nothing is loaded from OSM until they can. */
    public const UNAVAILABLE_MESSAGE = 'OSMHelper can’t save its Young Leaders limits on the server right now (the pause between OSM requests and the Refresh wait), so it hasn’t contacted OSM. What has loaded is kept. Please tell whoever runs OSMHelper.';
    private const SESSION_KEY = 'ylLimits';

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

    public static function setLastCallAt(string $userId, float $at): bool
    {
        return self::update($userId, static function (array $s) use ($at): array {
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

    public static function startRefresh(string $userId, string $sectionId, float $since): bool
    {
        return self::update($userId, static function (array $s) use ($sectionId, $since): array {
            $s['refresh'][$sectionId] = ['at' => (int) $since, 'since' => $since];
            return $s;
        });
    }

    /** The reload finished: keep the cooldown, forget the "reload from" moment. */
    public static function endRefresh(string $userId, string $sectionId): bool
    {
        return self::update($userId, static function (array $s) use ($sectionId): array {
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

    public static function setTooLarge(string $userId, string $sectionId, ?int $count, float $at): bool
    {
        return self::update($userId, static function (array $s) use ($sectionId, $count, $at): array {
            if ($count === null) {
                unset($s['tooLarge'][$sectionId]);
            } else {
                $s['tooLarge'][$sectionId] = ['count' => $count, 'at' => $at];
            }
            return $s;
        });
    }

    /**
     * True when this user's limits file can be opened, locked and saved right now (it is created if
     * missing; its contents are left as they are). False = refuse to contact OSM.
     */
    public static function canSave(string $userId): bool
    {
        return self::update($userId, static fn (array $s): array => $s, false);
    }

    /** Like canSave(), without creating anything (for showing the page). */
    public static function looksSavable(string $userId): bool
    {
        $dir = self::dir();
        $path = self::file($userId);
        if (is_file($path)) {
            return is_readable($path) && is_writable($path);
        }
        return is_dir($dir) ? is_writable($dir) : (!file_exists($dir) && is_writable(dirname($dir)));
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
        return self::stricter(self::prune(is_array($s) ? $s : []), self::prune(self::sessionCopy($userId)));
    }

    /** @return array<string, mixed> this user's limits as kept in this session */
    private static function sessionCopy(string $userId): array
    {
        $s = $_SESSION[self::SESSION_KEY][$userId] ?? null;
        return is_array($s) ? $s : [];
    }

    /**
     * File and session copies combined, the stricter value winning: the later last-call time and
     * Refresh press, and a too-large marker from either.
     * @param array<string, mixed> $a @param array<string, mixed> $b @return array<string, mixed>
     */
    private static function stricter(array $a, array $b): array
    {
        if (isset($b['lastCallAt']) && (float) $b['lastCallAt'] > (float) ($a['lastCallAt'] ?? -INF)) {
            $a['lastCallAt'] = $b['lastCallAt'];
        }
        foreach ($b['refresh'] ?? [] as $sid => $e) {
            $mine = $a['refresh'][$sid] ?? null;
            if (!is_array($mine)) {
                $a['refresh'][$sid] = $e;
                continue;
            }
            if ((int) ($e['at'] ?? 0) > (int) ($mine['at'] ?? 0)) {
                $a['refresh'][$sid] = $e;
            }
        }
        foreach ($b['tooLarge'] ?? [] as $sid => $e) {
            if (!isset($a['tooLarge'][$sid])) {
                $a['tooLarge'][$sid] = $e;
            }
        }
        return $a;
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

    /**
     * Read-modify-write under an exclusive lock (two tabs or sessions of the same user). The change
     * is applied to this session's copy too (unless $mirror is false), so it holds for this session
     * even when the file can't be saved. False = the file was not saved.
     */
    private static function update(string $userId, callable $fn, bool $mirror = true): bool
    {
        if ($mirror) {
            $_SESSION[self::SESSION_KEY][$userId] = $fn(self::prune(self::sessionCopy($userId)));
        }
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $path = self::file($userId);
        $h = @fopen($path, 'c+');
        if ($h === false) {
            return false;
        }
        @chmod($path, 0600);
        try {
            if (!flock($h, LOCK_EX)) {
                return false;
            }
            $raw = stream_get_contents($h);
            if ($raw === false) {
                return false;
            }
            $s = $raw !== '' ? json_decode($raw, true) : [];
            $s = $fn(self::prune(is_array($s) ? $s : []));
            $json = (string) json_encode($s);
            $ok = ftruncate($h, 0) && rewind($h) && fwrite($h, $json) === strlen($json) && fflush($h);
            flock($h, LOCK_UN);
            return $ok;
        } finally {
            fclose($h);
        }
    }
}
