<?php
declare(strict_types=1);
namespace App\Waiting;

use App\Config;
use App\Osm\OsmRateGuard;

/**
 * Per-IP throttle for the public WordPress joining-form submit (POST /api/waiting-list/submit).
 * Joining-form writes are "essential", so the low-allowance pause doesn't hold them back: this
 * caps how fast one caller can spend the OSM allowance (and slows site-key guessing).
 *
 * The caller is normally the WordPress server, so its IP is shared by every family using that
 * form; the WordPress plugin separately limits each visitor. MAX_PER_WINDOW is set well above
 * normal joining rates.
 *
 * Privacy: files in storage/osm-rate/ (dir 0700, files 0600) are named by a keyed hash of the IP
 * (never the IP itself) and hold only timestamps. Fails closed: if the count can't be saved, the
 * submit is refused.
 */
final class IntakeThrottle
{
    public const MAX_PER_WINDOW = 20;
    public const WINDOW_SECONDS = 900;

    /** @var callable():int|null */
    private static $clock = null;

    /** Test helper: fake "now" (unix seconds). */
    public static function useClock(?callable $clock): void
    {
        self::$clock = $clock;
    }

    private static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    /**
     * Count this attempt. Returns null when allowed, else seconds until the caller may try again.
     */
    public static function hit(string $ip): ?int
    {
        $ip = trim($ip) !== '' ? trim($ip) : 'unknown';
        $dir = OsmRateGuard::dir();
        $old = umask(0077);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $key = hash_hmac('sha256', 'intake-ip|' . $ip, (string) (Config::get('SESSION_SECRET') ?? 'osmhelper'));
        $path = $dir . '/intake-' . substr($key, 0, 32) . '.json';
        $fh = @fopen($path, 'c+');
        umask($old);
        if ($fh === false || !@flock($fh, LOCK_EX)) {
            if ($fh !== false) fclose($fh);
            @error_log('OSMHelper intake_throttle_unwritable');
            return self::WINDOW_SECONDS; // fail closed
        }
        try {
            @chmod($path, 0600);
            $now = self::now();
            $raw = stream_get_contents($fh);
            $times = is_string($raw) ? json_decode($raw, true) : null;
            $times = is_array($times) ? array_values(array_filter($times, static fn ($t) => is_int($t) && $t > $now - self::WINDOW_SECONDS && $t <= $now)) : [];
            if (count($times) >= self::MAX_PER_WINDOW) {
                return max(1, min($times) + self::WINDOW_SECONDS - $now);
            }
            $times[] = $now;
            if (!ftruncate($fh, 0) || !rewind($fh) || fwrite($fh, (string) json_encode($times)) === false) {
                @error_log('OSMHelper intake_throttle_unwritable');
                return self::WINDOW_SECONDS;
            }
            fflush($fh);
            return null;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Delete throttle files with no attempt inside the window (no personal data in them anyway). */
    public static function sweep(): void
    {
        $now = self::now();
        foreach (glob(OsmRateGuard::dir() . '/intake-*.json') ?: [] as $f) {
            $m = @filemtime($f);
            if ($m !== false && $now - $m >= self::WINDOW_SECONDS) {
                @unlink($f);
            }
        }
    }
}
