<?php
declare(strict_types=1);
namespace App\Osm;

/**
 * One shared OSM allowance guard per OSM user, used by every OsmApi request.
 *
 * OSM locks a user out for 24 hours if the rate limit is ignored, so this is deliberately blunt:
 *  - Before every request: refuse while a backoff is active. A 429 backoff refuses everything;
 *    a "low remaining" backoff refuses only non-essential calls (reads), so a save the user
 *    already confirmed is not cut off half way.
 *  - After every response: read X-RateLimit-Limit / -Remaining / -Reset. When remaining drops
 *    below max(LOW_MIN_REMAINING, LOW_FRACTION of the limit), start a low backoff until the
 *    window resets. Remaining 0 is treated like a 429.
 *  - On 429: record backoff-until from Retry-After, else Reset, else DEFAULT_429_BACKOFF_SEC.
 *    Never retried here; callers get OsmBusyException.
 *
 * State is shared across requests and sessions for the same OSM user via a small JSON file in
 * storage/osm-rate/ (dir 0700, file 0600). The file holds only numbers and a reason code:
 * never tokens, names or member data. The file name is a hash of the OSM user id.
 * Before sign-in (no user id yet) state is kept in memory for this PHP request only.
 */
final class OsmRateGuard
{
    public const LOW_MIN_REMAINING = 20;
    public const LOW_FRACTION = 0.10;
    /** Used when OSM sends a 429 with no Retry-After and no Reset. */
    public const DEFAULT_429_BACKOFF_SEC = 900;
    /** A 429 / exhausted allowance always pauses at least this long, whatever the headers say. */
    public const MIN_HARD_BACKOFF_SEC = 60;
    /** Used for a low-remaining backoff when OSM sends no Reset. */
    public const DEFAULT_LOW_BACKOFF_SEC = 300;
    /** Never honour a header asking for longer than this (bad header guard). */
    public const MAX_BACKOFF_SEC = 86400 + 3600;

    public const REASON_429 = 'osm_backoff_429';
    public const REASON_EXHAUSTED = 'osm_backoff_exhausted';
    public const REASON_LOW = 'osm_rate_low';

    private static ?string $dirOverride = null;
    /** @var callable():int|null */
    private static $clock = null;
    /** @var array<string, array<string, mixed>> in-memory state for anonymous (pre-sign-in) use */
    private static array $memory = [];

    private string $key;
    private bool $persist;

    private function __construct(string $key, bool $persist)
    {
        $this->key = $key;
        $this->persist = $persist;
    }

    /** Guard for an explicit OSM user id (e.g. the WordPress form's token owner). */
    public static function forUser(?string $osmUserId): self
    {
        $id = $osmUserId !== null ? trim($osmUserId) : '';
        if ($id === '') {
            return new self('anon', false);
        }
        return new self(hash('sha256', 'osm-user:' . $id), true);
    }

    /** Guard for whoever is signed in to this session right now. */
    public static function forCurrentUser(): self
    {
        $id = null;
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['osmUserId']) && is_scalar($_SESSION['osmUserId'])) {
            $id = (string) $_SESSION['osmUserId'];
        }
        return self::forUser($id);
    }

    /** Test helper: keep state in another directory instead of storage/osm-rate. */
    public static function useDirectory(?string $dir): void
    {
        self::$dirOverride = $dir;
        self::$memory = [];
    }

    /** Test helper: fake "now" (unix seconds). */
    public static function useClock(?callable $clock): void
    {
        self::$clock = $clock;
    }

    public static function dir(): string
    {
        return self::$dirOverride ?? (dirname(__DIR__, 2) . '/storage/osm-rate');
    }

    private static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    /**
     * Throws OsmBusyException if this call must not go to OSM. Makes no HTTP call.
     */
    public function assertCanCall(bool $essential): void
    {
        $st = $this->load();
        $until = (int) ($st['backoffUntil'] ?? 0);
        $now = self::now();
        if ($until <= $now) {
            return;
        }
        $reason = (string) ($st['reason'] ?? self::REASON_429);
        if ($reason === self::REASON_LOW && $essential) {
            return;
        }
        throw new OsmBusyException($until - $now, $reason);
    }

    /**
     * Record allowance headers from a response. Returns the backoff reason if this response
     * started (or extended) a backoff, otherwise null.
     *
     * @param array<string, list<string>> $headers
     */
    public function recordResponse(int $status, array $headers): ?string
    {
        $limit = self::intHeader($headers, 'X-RateLimit-Limit');
        $remaining = self::intHeader($headers, 'X-RateLimit-Remaining');
        $resetSec = self::resetSeconds(self::header($headers, 'X-RateLimit-Reset'));
        $retryAfter = self::retryAfterSeconds(self::header($headers, 'Retry-After'));
        $now = self::now();

        $st = $this->load();
        if ($limit !== null) {
            $st['limit'] = $limit;
        }
        if ($remaining !== null) {
            $st['remaining'] = $remaining;
        }
        if ($resetSec !== null) {
            $st['resetAt'] = $now + $resetSec;
        }
        $st['updatedAt'] = $now;

        $started = null;
        if ($status === 429) {
            $wait = max(self::MIN_HARD_BACKOFF_SEC, $retryAfter ?? (($resetSec ?? 0) > 0 ? $resetSec : self::DEFAULT_429_BACKOFF_SEC));
            $started = self::REASON_429;
            $st = self::withBackoff($st, $now + self::clampWait($wait), $started);
        } elseif ($remaining !== null && $remaining <= 0) {
            $wait = max(self::MIN_HARD_BACKOFF_SEC, ($resetSec ?? 0) > 0 ? $resetSec : ($retryAfter ?? self::DEFAULT_429_BACKOFF_SEC));
            $started = self::REASON_EXHAUSTED;
            $st = self::withBackoff($st, $now + self::clampWait($wait), $started);
        } elseif ($remaining !== null && self::isLow($remaining, $limit ?? (isset($st['limit']) ? (int) $st['limit'] : null))) {
            $wait = $resetSec ?? self::DEFAULT_LOW_BACKOFF_SEC;
            $started = self::REASON_LOW;
            $st = self::withBackoff($st, $now + self::clampWait($wait), $started);
        } elseif ($remaining !== null && ($st['reason'] ?? null) === self::REASON_LOW) {
            // The window reset (an essential call saw a healthy allowance): lift the low backoff.
            $st['backoffUntil'] = 0;
            $st['reason'] = null;
        }
        $this->save($st);
        return $started;
    }

    /** True when remaining is under max(20, 10% of limit). */
    public static function isLow(int $remaining, ?int $limit): bool
    {
        $threshold = self::LOW_MIN_REMAINING;
        if ($limit !== null && $limit > 0) {
            $threshold = max($threshold, (int) ceil($limit * self::LOW_FRACTION));
        }
        return $remaining < $threshold;
    }

    /** Seconds left on an active backoff, or 0. */
    public function secondsLeft(): int
    {
        $st = $this->load();
        return max(0, (int) ($st['backoffUntil'] ?? 0) - self::now());
    }

    /** Whole minutes to show the user ("try again in N minutes"), or null when not busy. */
    public function busyMinutes(): ?int
    {
        $s = $this->secondsLeft();
        return $s > 0 ? max(1, (int) ceil($s / 60)) : null;
    }

    public function reason(): ?string
    {
        if ($this->secondsLeft() <= 0) {
            return null;
        }
        $r = $this->load()['reason'] ?? null;
        return is_string($r) ? $r : null;
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return $this->load();
    }

    /** Test/ops helper: forget this user's state. */
    public function reset(): void
    {
        if (!$this->persist) {
            unset(self::$memory[$this->key]);
            return;
        }
        $p = $this->path();
        if (is_file($p)) {
            @unlink($p);
        }
    }

    /**
     * @param array<string, mixed> $st
     * @return array<string, mixed>
     */
    private static function withBackoff(array $st, int $until, string $reason): array
    {
        $now = self::now();
        $cur = (int) ($st['backoffUntil'] ?? 0);
        $curReason = is_string($st['reason'] ?? null) ? $st['reason'] : null;
        $curHard = $cur > $now && $curReason !== null && $curReason !== self::REASON_LOW;
        if ($reason === self::REASON_LOW) {
            if (!$curHard) {
                $st['backoffUntil'] = $until;
                $st['reason'] = $reason;
            }
            return $st;
        }
        // Hard backoff (429 / exhausted): never shortened, always wins over "low".
        $st['backoffUntil'] = $curHard ? max($cur, $until) : $until;
        $st['reason'] = $reason;
        return $st;
    }

    private static function clampWait(int $wait): int
    {
        return max(1, min(self::MAX_BACKOFF_SEC, $wait));
    }

    /** @param array<string, list<string>> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $k => $values) {
            if (strcasecmp((string) $k, $name) === 0) {
                $v = is_array($values) ? ($values[0] ?? null) : $values;
                return $v !== null ? trim((string) $v) : null;
            }
        }
        return null;
    }

    /** @param array<string, list<string>> $headers */
    private static function intHeader(array $headers, string $name): ?int
    {
        $v = self::header($headers, $name);
        return ($v !== null && $v !== '' && is_numeric($v)) ? (int) $v : null;
    }

    /** X-RateLimit-Reset: seconds until reset (OSM), or a unix time if it looks like one. */
    public static function resetSeconds(?string $v): ?int
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $n = (int) $v;
        if ($n > 1000000000) {
            $n -= self::now();
        }
        return max(0, $n);
    }

    /** Retry-After: delta seconds or an HTTP date. */
    public static function retryAfterSeconds(?string $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return max(1, (int) $v);
        }
        $ts = strtotime($v);
        return $ts !== false ? max(1, $ts - self::now()) : null;
    }

    private function path(): string
    {
        return self::dir() . '/' . substr($this->key, 0, 40) . '.json';
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if (!$this->persist) {
            return self::$memory[$this->key] ?? [];
        }
        $p = $this->path();
        if (!is_file($p)) {
            return [];
        }
        $raw = @file_get_contents($p);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? $d : [];
    }

    /** @param array<string, mixed> $st */
    private function save(array $st): void
    {
        $st = [
            'limit' => isset($st['limit']) ? (int) $st['limit'] : null,
            'remaining' => isset($st['remaining']) ? (int) $st['remaining'] : null,
            'resetAt' => isset($st['resetAt']) ? (int) $st['resetAt'] : null,
            'backoffUntil' => (int) ($st['backoffUntil'] ?? 0),
            'reason' => isset($st['reason']) && is_string($st['reason']) ? $st['reason'] : null,
            'updatedAt' => (int) ($st['updatedAt'] ?? self::now()),
        ];
        if (!$this->persist) {
            self::$memory[$this->key] = $st;
            return;
        }
        $dir = self::dir();
        if (!is_dir($dir)) {
            $old = umask(0077);
            @mkdir($dir, 0700, true);
            umask($old);
        }
        $p = $this->path();
        $tmp = $p . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $old = umask(0077);
        $ok = @file_put_contents($tmp, json_encode($st), LOCK_EX);
        umask($old);
        if ($ok === false) {
            // Storage not writable: keep the backoff for this request at least.
            self::$memory[$this->key] = $st;
            $this->persist = false;
            @error_log('OSM API osm_rate_state_unwritable');
            return;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $p)) {
            @unlink($tmp);
        }
    }
}
