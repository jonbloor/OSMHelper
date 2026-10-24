<?php
declare(strict_types=1);
namespace App\Members;

use App\Config;
use App\Osm\OsmCache;

/**
 * Young Leaders allowance limits, kept per OSM user (not per session), so signing out and in
 * again doesn't reset them:
 *  - lastCallAt: when this user's last Young Leaders OSM call went out (the pause between calls);
 *  - refresh[section]: when Refresh was last pressed (the 5-minute cooldown) and the moment the
 *    reload started from (members fetched before it are reloaded);
 *  - tooLarge[section]: the member count of a section over the 150 limit, for KEEP_SEC (2 hours),
 *    so Load doesn't spend the list call again.
 * No names or member data: timestamps, section ids and counts only. One file per OSM user in
 * storage/yl-limits/ (dir 0700, files created 0600), named by a keyed hash of the OSM user id
 * (HMAC with SESSION_SECRET, or with a random per-install salt kept in yl-limits/.salt when no
 * real secret is set), so the names can't be matched to user ids. Reads and writes are locked.
 * Entries are dropped after KEEP_SEC (2 hours, the Young Leaders copy's TTL); a sweep that runs at
 * most hourly (on a write, or on any request via App::sweepPrivateCaches) deletes files left with
 * nothing in them and rewrites the rest without their expired entries. Unlike the Young Leaders copy of members (OsmCache), this is
 * NOT cleared at sign-out, by design.
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

    /** A reserved slot more than this far ahead can only be a clock change, not queued tabs. */
    private const MAX_AHEAD_SEC = 60;

    /**
     * Timing entries (no names) older than this are dropped: 2 hours. Tied to the Young Leaders copy's
     * TTL (OsmCache::KIND_TTLS, the longer of the two kinds), so it can never be shorter than it and a
     * Refresh's "since" always outlives any member cached before it (that member expires first).
     */
    public const KEEP_SEC = OsmCache::KIND_TTLS[YoungLeaders::MEMBER_KIND] >= OsmCache::KIND_TTLS[YoungLeaders::LIST_KIND]
        ? OsmCache::KIND_TTLS[YoungLeaders::MEMBER_KIND]
        : OsmCache::KIND_TTLS[YoungLeaders::LIST_KIND];

    /** The sweep for files with nothing left in them runs at most this often. */
    private const SWEEP_EVERY_SEC = 3600;

    /** App::start()'s fallback when SESSION_SECRET is unset: not a secret, so the salt file is used. */
    private const DEV_SECRET = 'dev-insecure-change-me';

    private static ?string $dirOverride = null;

    /** @var array<string, string> per-install salt, by directory */
    private static array $salt = [];

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

    /**
     * Reserve this user's next Young Leaders call slot: read the last slot, work out the next one
     * (at least $wantUs after it; the full $wantUs when none is known) and save it, all under the
     * file's lock, so two tabs or PHP workers can't both take the same second.
     * Returns how long to wait (microseconds) before calling, or null when the slot couldn't be
     * saved (then don't call OSM).
     */
    public static function reserveCall(string $userId, int $wantUs, float $now): ?int
    {
        $wait = null;
        $ok = self::update($userId, static function (array $s) use ($wantUs, $now, &$wait): array {
            $last = $s['lastCallAt'] ?? null;
            $want = $wantUs / 1_000_000;
            // None known, or further ahead than any real queue of tabs could be (clock change): the full pause.
            $slot = $last === null || (float) $last > $now + self::MAX_AHEAD_SEC ? $now + $want : max($now, (float) $last + $want);
            $wait = (int) round(($slot - $now) * 1_000_000);
            $s['lastCallAt'] = $slot;
            return $s;
        });
        return $ok ? $wait : null;
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

    /**
     * Start a Refresh only if the last one for this section was at least $cooldownSec ago, checked
     * and saved under the file's lock (so two tabs can't both start one).
     * true = started, false = still cooling down, null = couldn't be saved.
     */
    public static function startRefreshIfDue(string $userId, string $sectionId, float $since, int $cooldownSec): ?bool
    {
        $started = false;
        $ok = self::update($userId, static function (array $s) use ($sectionId, $since, $cooldownSec, &$started): array {
            $started = false;
            $lastAt = (int) ($s['refresh'][$sectionId]['at'] ?? 0);
            if ($lastAt > 0 && (int) $since - $lastAt < $cooldownSec) {
                return $s;
            }
            $s['refresh'][$sectionId] = ['at' => (int) $since, 'since' => $since];
            $started = true;
            return $s;
        });
        return $ok ? $started : null;
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
        if ($path === null) {
            return false;
        }
        if (is_file($path)) {
            return is_readable($path) && is_writable($path);
        }
        return is_dir($dir) ? is_writable($dir) : (!file_exists($dir) && is_writable(dirname($dir)));
    }

    /** This user's file, or null when no key for the name can be had (then nothing is saved). */
    private static function file(string $userId): ?string
    {
        $key = self::key();
        return $key === null ? null : self::dir() . '/' . hash_hmac('sha256', 'yl-limits|' . $userId, $key) . '.json';
    }

    /** SESSION_SECRET if a real one is set, else this install's salt (created once, 0600). */
    private static function key(): ?string
    {
        $secret = Config::get('SESSION_SECRET');
        if ($secret !== null && $secret !== self::DEV_SECRET) {
            return $secret;
        }
        $dir = self::dir();
        if (isset(self::$salt[$dir])) {
            return self::$salt[$dir];
        }
        $path = $dir . '/.salt';
        if (!is_file($path)) {
            self::makeDir();
            // Written to a private temp file, then linked into place: link() fails if another
            // request got there first, so everyone ends up with the same salt.
            $tmp = $dir . '/.salt-' . bin2hex(random_bytes(6));
            $old = umask(0077);
            $ok = @file_put_contents($tmp, bin2hex(random_bytes(32))) !== false;
            umask($old);
            if ($ok) {
                @link($tmp, $path);
                @unlink($tmp);
            }
        }
        $v = @file_get_contents($path);
        if (!is_string($v) || strlen(trim($v)) < 32) {
            return null;
        }
        return self::$salt[$dir] = trim($v);
    }

    private static function makeDir(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            $old = umask(0077);
            @mkdir($dir, 0700, true);
            umask($old);
        }
    }

    /** @return array<string, mixed> */
    private static function load(string $userId): array
    {
        $s = null;
        $path = self::file($userId);
        $h = $path !== null ? @fopen($path, 'r') : false;
        if ($h !== false) {
            if (flock($h, LOCK_SH)) {
                $raw = stream_get_contents($h);
                flock($h, LOCK_UN);
                $s = is_string($raw) ? json_decode($raw, true) : null;
            }
            fclose($h);
        }
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
            // No empty containers left behind, so a file with nothing in date is [] and gets swept.
            if ($s[$k] === []) {
                unset($s[$k]);
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
        $session = self::prune(self::sessionCopy($userId));
        self::makeDir();
        $path = self::file($userId);
        $h = $path !== null ? self::openLocked($path) : false;
        if ($h === false) {
            // Not saved: still keep it in this session (the caller refuses OSM calls anyway).
            if ($mirror) {
                $_SESSION[self::SESSION_KEY][$userId] = $fn($session);
            }
            return false;
        }
        try {
            if (($raw = stream_get_contents($h)) === false) {
                if ($mirror) {
                    $_SESSION[self::SESSION_KEY][$userId] = $fn($session);
                }
                return false;
            }
            $s = $raw !== '' ? json_decode($raw, true) : [];
            // Decide once, on the stricter of the saved copy and this session's copy, and keep the
            // result in both.
            $s = $fn(self::stricter(self::prune(is_array($s) ? $s : []), $mirror ? $session : []));
            if ($mirror) {
                $_SESSION[self::SESSION_KEY][$userId] = $s;
            }
            $json = (string) json_encode($s);
            $ok = ftruncate($h, 0) && rewind($h) && fwrite($h, $json) === strlen($json) && fflush($h);
            flock($h, LOCK_UN);
        } finally {
            fclose($h);
        }
        if ($ok) {
            self::sweepIfDue();
        }
        return $ok;
    }

    /**
     * Open (creating 0600 if needed) and exclusively lock a limits file. If the sweep deleted the
     * file while we waited for the lock, open the new one instead. False if that can't be done.
     * @return resource|false
     */
    private static function openLocked(string $path)
    {
        for ($try = 0; $try < 3; $try++) {
            $old = umask(0077);
            $h = @fopen($path, 'c+');
            umask($old);
            if ($h === false) {
                return false;
            }
            if (!flock($h, LOCK_EX)) {
                fclose($h);
                return false;
            }
            clearstatcache(true, $path);
            $st = @stat($path);
            $mine = fstat($h);
            if ($st !== false && $mine !== false && $st['ino'] === $mine['ino'] && $st['dev'] === $mine['dev']) {
                return $h;
            }
            flock($h, LOCK_UN);
            fclose($h);
        }
        return false;
    }

    /**
     * Called on every request (App::sweepPrivateCaches), so expired users' files go even when
     * nobody saves anything: does nothing more than once an hour, or if the directory doesn't exist.
     */
    public static function sweep(): void
    {
        if (is_dir(self::dir())) {
            self::sweepIfDue();
        }
    }

    /** At most hourly: delete limits files that have nothing left in them after pruning; rewrite the rest without expired entries. */
    private static function sweepIfDue(): void
    {
        $dir = self::dir();
        $marker = $dir . '/.swept';
        $now = OsmCache::currentMicrotime();
        $last = @file_get_contents($marker);
        if (is_string($last) && (float) $last > $now - self::SWEEP_EVERY_SEC && (float) $last <= $now) {
            return;
        }
        $old = umask(0077);
        $wrote = @file_put_contents($marker, (string) $now, LOCK_EX);
        umask($old);
        if ($wrote === false) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $h = @fopen($path, 'r+');
            if ($h === false) {
                continue;
            }
            // Skip files in use; they'll be looked at next time.
            if (flock($h, LOCK_EX | LOCK_NB)) {
                $raw = stream_get_contents($h);
                $s = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
                $s = is_array($s) ? $s : [];
                $kept = self::prune($s);
                if ($kept === []) {
                    @unlink($path);
                } elseif ($kept !== $s) {
                    // Expired entries leave the disk too, not just when this user next writes.
                    $json = json_encode($kept);
                    if (is_string($json) && ftruncate($h, 0) && rewind($h)) {
                        fwrite($h, $json);
                        fflush($h);
                    }
                }
                flock($h, LOCK_UN);
            }
            fclose($h);
        }
    }
}
