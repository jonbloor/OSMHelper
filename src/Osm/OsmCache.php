<?php
declare(strict_types=1);
namespace App\Osm;

/**
 * Short-lived cache of OSM read results, per OSM user and section, so reopening a page within a
 * few minutes doesn't spend the user's OSM allowance again.
 *
 * Privacy (this cache holds personal data; home.twig, README and Help describe it):
 *  - What: for the waiting list, applicants' names, dates of birth, dates joined, every
 *    waiting-list custom field value and the leaders' notes field (no parent contact details,
 *    which OSM keeps in other groups); and the user's section list.
 *  - Who: owner-only. Each entry is keyed to the OSM user who fetched it and is only ever read
 *    back for that same user.
 *  - How long: deleted 5 minutes (TTL_SECONDS) after it was fetched. sweep() runs on every
 *    request (App::sweepPrivateCaches) as well as on every write and at sign-out, so expired
 *    files don't linger when nobody reopens the page.
 *  - Files live in storage/osm-cache/ (dir 0700, files 0600, owned by the PHP-FPM user).
 *  - Never put tokens in here.
 *  - File names are hashes, so they carry no section or member ids.
 *  - Cleared for the user at sign-out, and for an expired session on that browser's next request
 *    (App::enforceSessionMaxAge) - not at the moment it expires; a new sign-in clears only the
 *    previous user in that browser. Cleared for every user of a section when
 *    OSMHelper writes to that section (rank, notes, new applicant).
 *
 * Long-lived kinds (KIND_TTLS): Young Leaders keeps, per section and per member, ONLY the member's
 * first name, last name, age text and the "Group: Section" names OSM lists as their other sections
 * (no dates of birth, contact or medical details: YoungLeaders::slimMember() strips them before
 * put()). These last up to 24 hours while you stay signed in, so filters and repeat views spend no
 * OSM allowance (owner-only). Like everything else here they are deleted for the user at sign-out
 * (an expired session: on that browser's next request; otherwise the 24-hour sweep); the page's
 * Refresh button forgets them early.
 */
final class OsmCache
{
    public const TTL_SECONDS = 300;
    public const MARKER_TTL_SECONDS = 3600;

    /**
     * Kinds that live longer than TTL_SECONDS (seconds), for get() and sweep(). Keep the list short:
     * only slimmed, low-sensitivity data belongs here (see the class comment).
     * @var array<string, int>
     */
    public const KIND_TTLS = [
        'ylsection' => 86400,
        'ylmember' => 86400,
    ];

    /** Expiry for a kind: KIND_TTLS entry, else TTL_SECONDS. */
    public static function ttlFor(string $kind): int
    {
        return self::KIND_TTLS[self::kindPart($kind)] ?? self::TTL_SECONDS;
    }

    private static ?string $dirOverride = null;
    /** @var callable():int|null */
    private static $clock = null;

    /** Test helper. */
    public static function useDirectory(?string $dir): void
    {
        self::$dirOverride = $dir;
    }

    /** Test helper: fake "now" (unix seconds). */
    public static function useClock(?callable $clock): void
    {
        self::$clock = $clock;
    }

    public static function dir(): string
    {
        return self::$dirOverride ?? (dirname(__DIR__, 2) . '/storage/osm-cache');
    }

    /** Current time on the cache's clock (tests can fake it with useClock()). */
    public static function currentTime(): int
    {
        return self::now();
    }

    /** Current time with microseconds (tests: the useClock() value, which may be a float). */
    public static function currentMicrotime(): float
    {
        return self::$clock !== null ? (float) (self::$clock)() : microtime(true);
    }

    private static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    /** Signed-in OSM user id, or null (then nothing is cached). */
    public static function currentUserId(): ?string
    {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['osmUserId']) && is_scalar($_SESSION['osmUserId'])) {
            $id = trim((string) $_SESSION['osmUserId']);
            return $id !== '' ? $id : null;
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null cached value, or null on miss/expired
     */
    public static function get(string $kind, ?string $userId, string $scope, ?int $ttl = null): ?array
    {
        $ttl ??= self::ttlFor($kind);
        if ($userId === null || $userId === '') {
            return null;
        }
        $p = self::path($kind, $userId, $scope);
        if (!is_file($p)) {
            return null;
        }
        $raw = @file_get_contents($p);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || !is_int($d['at'] ?? null) || !is_array($d['value'] ?? null)) {
            @unlink($p);
            return null;
        }
        if (self::now() - $d['at'] >= $ttl) {
            @unlink($p);
            return null;
        }
        return $d['value'];
    }

    /** Seconds since the cached value was fetched, or null. */
    public static function ageSeconds(string $kind, ?string $userId, string $scope): ?int
    {
        if ($userId === null || $userId === '') {
            return null;
        }
        $p = self::path($kind, $userId, $scope);
        $raw = is_file($p) ? @file_get_contents($p) : false;
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) && is_int($d['at'] ?? null) ? max(0, self::now() - $d['at']) : null;
    }

    /**
     * @param array<string, mixed> $value
     * @param float|null $loadStartedAt microtime(true) when the caller started fetching $value from
     *   OSM. When given, the write is dropped if forgetScope() ran for this scope after that moment
     *   (an OSMHelper write landed while this load was in flight, so $value may be out of date).
     * @param int|null $fetchedAt when the oldest data in $value was fetched (default now): the entry
     *   expires, and sweep() deletes it, ttlFor($kind) seconds after this.
     */
    public static function put(string $kind, ?string $userId, string $scope, array $value, ?float $loadStartedAt = null, ?int $fetchedAt = null): void
    {
        if ($userId === null || $userId === '') {
            return;
        }
        $dir = self::dir();
        if (!is_dir($dir)) {
            $old = umask(0077);
            @mkdir($dir, 0700, true);
            umask($old);
        }
        self::sweep();
        $p = self::path($kind, $userId, $scope);
        $tmp = $p . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $at = min($fetchedAt ?? self::now(), self::now());
        $json = json_encode(['at' => $at, 'value' => $value], JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return;
        }
        $old = umask(0077);
        $ok = @file_put_contents($tmp, $json, LOCK_EX);
        umask($old);
        if ($ok === false) {
            @error_log('OSMHelper osm_cache_unwritable');
            return;
        }
        @chmod($tmp, 0600);
        @touch($tmp, $at);
        if ($loadStartedAt !== null && self::invalidatedSince($kind, $scope, $loadStartedAt)) {
            @unlink($tmp);
            return;
        }
        if (!@rename($tmp, $p)) {
            @unlink($tmp);
            return;
        }
        // Re-check after the rename: forgetScope() writes its marker *before* deleting, so either its
        // delete saw this file, or this check sees its marker. No stale copy survives either way.
        if ($loadStartedAt !== null && self::invalidatedSince($kind, $scope, $loadStartedAt)) {
            @unlink($p);
        }
    }

    /** True when forgetScope($kind, $scope) ran at or after $since (microtime). */
    private static function invalidatedSince(string $kind, string $scope, float $since): bool
    {
        clearstatcache();
        $raw = @file_get_contents(self::markerPath($kind, $scope));
        return is_string($raw) && is_numeric(trim($raw)) && (float) trim($raw) >= $since;
    }

    private static function markerPath(string $kind, string $scope): string
    {
        return self::dir() . '/inv-' . self::kindPart($kind) . '-' . self::hash('scope:' . $scope) . '.mark';
    }

    /** Forget one user's entry. */
    public static function forget(string $kind, ?string $userId, string $scope): void
    {
        if ($userId === null || $userId === '') {
            return;
        }
        $p = self::path($kind, $userId, $scope);
        if (is_file($p)) {
            @unlink($p);
        }
    }

    /** Forget a section's entries for every user (after OSMHelper writes to that section). */
    public static function forgetScope(string $kind, string $scope): void
    {
        // Marker first (see put()): loads already in flight for this scope won't store their result.
        $dir = self::dir();
        $old = umask(0077);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        umask($old);
        if (is_dir($dir)) {
            $old = umask(0077);
            $m = self::markerPath($kind, $scope);
            $tmp = $m . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, sprintf('%.6F', microtime(true))) !== false) {
                @chmod($tmp, 0600);
                @touch($tmp, self::now()); // sweep() ages markers by mtime on the same clock
                if (!@rename($tmp, $m)) {
                    @unlink($tmp);
                }
            }
            umask($old);
        }
        foreach (glob(self::dir() . '/' . self::kindPart($kind) . '-' . self::hash('scope:' . $scope) . '-*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    /**
     * Forget everything cached for a user (sign-out; an expired session on its next request; a new
     * sign-in, for the user previously signed in on that browser), including the
     * 24-hour Young Leaders kinds. Other users' files and section invalidation markers are untouched.
     */
    public static function forgetUser(?string $userId): void
    {
        if ($userId !== null && $userId !== '') {
            foreach (glob(self::dir() . '/*-' . self::hash('user:' . $userId) . '.json') ?: [] as $f) {
                @unlink($f);
            }
        }
        self::sweep();
    }

    public static function forgetCurrentUser(): void
    {
        self::forgetUser(self::currentUserId());
    }

    /**
     * Delete expired entries (and stray temp files). Runs on every request. Invalidation markers
     * (no personal data, just a time) are kept for MARKER_TTL_SECONDS so slow loads still see them.
     */
    public static function sweep(): void
    {
        $now = self::now();
        foreach (glob(self::dir() . '/*') ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $m = @filemtime($f);
            $ttl = str_ends_with($f, '.mark') ? self::MARKER_TTL_SECONDS : (self::KIND_TTLS[self::kindOfFile($f)] ?? self::TTL_SECONDS);
            if ($m !== false && $now - $m >= $ttl) {
                @unlink($f);
            }
        }
    }

    private static function path(string $kind, string $userId, string $scope): string
    {
        return self::dir() . '/' . self::kindPart($kind) . '-' . self::hash('scope:' . $scope) . '-' . self::hash('user:' . $userId) . '.json';
    }

    /** Kind part of a cache file name (the text before the first "-"). */
    private static function kindOfFile(string $file): string
    {
        $base = basename($file);
        $dash = strpos($base, '-');
        return $dash === false ? '' : substr($base, 0, $dash);
    }

    private static function kindPart(string $kind): string
    {
        $k = preg_replace('/[^a-z0-9]/', '', strtolower($kind));
        return $k !== '' ? $k : 'x';
    }

    private static function hash(string $s): string
    {
        return substr(hash('sha256', 'osmhelper-cache|' . $s), 0, 20);
    }
}
