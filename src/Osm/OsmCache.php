<?php
declare(strict_types=1);
namespace App\Osm;

/**
 * Short-lived cache of OSM read results, per OSM user and section, so reopening a page within a
 * few minutes doesn't spend the user's OSM allowance again.
 *
 * Privacy:
 *  - Files live in storage/osm-cache/ (dir 0700, files 0600) and expire after TTL_SECONDS.
 *    Expired files are swept on every write and at sign-out.
 *  - Never put tokens in here. Callers store only what the page needs (for the waiting list:
 *    names, dates and the custom-data values; no contact details).
 *  - File names are hashes, so they carry no section or member ids.
 *  - Cleared for the user at sign-out / session expiry; cleared for every user of a section when
 *    OSMHelper writes to that section (rank, notes, new applicant).
 */
final class OsmCache
{
    public const TTL_SECONDS = 300;

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
    public static function get(string $kind, ?string $userId, string $scope, int $ttl = self::TTL_SECONDS): ?array
    {
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

    /** @param array<string, mixed> $value */
    public static function put(string $kind, ?string $userId, string $scope, array $value): void
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
        $json = json_encode(['at' => self::now(), 'value' => $value], JSON_INVALID_UTF8_SUBSTITUTE);
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
        if (!@rename($tmp, $p)) {
            @unlink($tmp);
        }
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
        foreach (glob(self::dir() . '/' . self::kindPart($kind) . '-' . self::hash('scope:' . $scope) . '-*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** Forget everything cached for a user (sign-out / session expiry). */
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

    /** Delete expired entries (and stray temp files). */
    public static function sweep(): void
    {
        $now = self::now();
        foreach (glob(self::dir() . '/*') ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $m = @filemtime($f);
            if ($m !== false && $now - $m >= self::TTL_SECONDS) {
                @unlink($f);
            }
        }
    }

    private static function path(string $kind, string $userId, string $scope): string
    {
        return self::dir() . '/' . self::kindPart($kind) . '-' . self::hash('scope:' . $scope) . '-' . self::hash('user:' . $userId) . '.json';
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
