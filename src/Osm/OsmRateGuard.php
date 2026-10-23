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
 *  - On 429: record backoff-until = the longer of Retry-After and Reset (whichever OSM sent),
 *    else DEFAULT_429_BACKOFF_SEC; at least MIN_HARD_BACKOFF_SEC, at most MAX_BACKOFF_SEC.
 *    Never retried here; callers get OsmBusyException.
 *  - X-Blocked (OSM blocked the whole application): an app-wide "blocked" file is written and
 *    every later call, for every user, essential or not (saves, sign-in, the WordPress form), is
 *    refused until an administrator deletes it (bin/osm-clear-block.php; DEPLOY-RUNBOOK.md).
 *  - Fail closed: if the state can't be saved (storage/osm-rate unwritable), every call is
 *    refused and the reason code osm_rate_state_unwritable is logged.
 *  - Load/modify/save of a user's state runs under an exclusive flock, so concurrent PHP-FPM
 *    requests can't drop each other's 429.
 *
 * State is shared across requests and sessions for the same OSM user via a small JSON file in
 * storage/osm-rate/ (dir 0700, file 0600). The file holds only numbers and a reason code:
 * never tokens, names or member data. The file name is a hash of the OSM user id.
 * Before sign-in (no user id yet) state is kept in memory for this PHP request only (the
 * app-wide block and the fail-closed check still apply).
 * Reads X-RateLimit-* and, when absent, the IETF RateLimit-* / RateLimit headers.
 */
final class OsmRateGuard
{
    public const LOW_MIN_REMAINING = 20;
    public const LOW_FRACTION = 0.10;
    /** Used when OSM sends a 429 with no Retry-After and no Reset. */
    public const DEFAULT_429_BACKOFF_SEC = 900;
    /** A 429 / exhausted allowance always pauses at least this long, whatever the headers say. */
    public const MIN_HARD_BACKOFF_SEC = 60;
    /** Used for a low-remaining backoff when OSM sends no Reset (or Reset 0). */
    public const DEFAULT_LOW_BACKOFF_SEC = 300;
    /** A low-remaining backoff lasts at least this long (Reset 0/1 must not mean a 1-second pause). */
    public const MIN_LOW_BACKOFF_SEC = 60;
    /** How long a fail-closed refusal (state unwritable) tells the user to wait before trying again. */
    public const UNWRITABLE_WAIT_SEC = 300;
    /** Never honour a header asking for longer than this (bad header guard). */
    public const MAX_BACKOFF_SEC = 86400 + 3600;

    public const REASON_429 = 'osm_backoff_429';
    public const REASON_EXHAUSTED = 'osm_backoff_exhausted';
    public const REASON_LOW = 'osm_rate_low';
    public const REASON_BLOCKED = 'osm_blocked';
    public const REASON_UNWRITABLE = 'osm_rate_state_unwritable';
    /** App-wide block file name inside dir(). */
    public const APP_BLOCK_FILE = 'app-blocked.json';

    private static ?string $dirOverride = null;
    /** @var callable():int|null */
    private static $clock = null;
    /** @var array<string, array<string, mixed>> in-memory state for anonymous (pre-sign-in) use */
    private static array $memory = [];
    /** True when the app-wide block couldn't be written: held in memory for this request. */
    private static bool $memoryBlocked = false;
    /** @var array<string, bool> per directory: storage writable (checked once per request) */
    private static array $writable = [];

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
        self::$memoryBlocked = false;
        self::$writable = [];
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
     * Throws OsmBusyException (or OsmBlockedException) if this call must not go to OSM.
     * Makes no HTTP call. Order: app-wide block, then fail-closed storage check, then backoff.
     */
    public function assertCanCall(bool $essential): void
    {
        self::assertAppMayCall();
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
     * App-wide checks that apply to every OSM call, including ones that don't go through OsmApi
     * (the OAuth sign-in and token refresh): the X-Blocked block, then fail closed when backoff
     * state can't be saved. Makes no HTTP call.
     */
    public static function assertAppMayCall(): void
    {
        if (self::appBlocked() !== null) {
            throw new OsmBlockedException();
        }
        if (!self::storageWritable()) {
            throw new OsmBusyException(self::UNWRITABLE_WAIT_SEC, self::REASON_UNWRITABLE);
        }
    }

    /**
     * App-wide block (X-Blocked) state, or null when not blocked.
     * @return array{blockedAt:int, reason:string}|null
     */
    public static function appBlocked(): ?array
    {
        if (self::$memoryBlocked) {
            return ['blockedAt' => self::now(), 'reason' => self::REASON_BLOCKED];
        }
        $p = self::dir() . '/' . self::APP_BLOCK_FILE;
        if (!is_file($p)) {
            return null;
        }
        $raw = @file_get_contents($p);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        // A file we can't read or parse still counts as blocked: fail closed.
        return [
            'blockedAt' => is_array($d) ? (int) ($d['blockedAt'] ?? 0) : 0,
            'reason' => self::REASON_BLOCKED,
        ];
    }

    /** Record that OSM blocked this application. Cleared only by clearAppBlock() (an admin, by hand). */
    public static function blockApp(): void
    {
        self::$memoryBlocked = true; // this request stops even if the file can't be written
        $dir = self::dir();
        self::ensureDir($dir);
        $p = $dir . '/' . self::APP_BLOCK_FILE;
        if (is_file($p)) {
            return; // keep the first blockedAt
        }
        $tmp = $p . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $old = umask(0077);
        $ok = @file_put_contents($tmp, json_encode(['blockedAt' => self::now(), 'reason' => self::REASON_BLOCKED]), LOCK_EX);
        umask($old);
        if ($ok === false || !@rename($tmp, $p)) {
            @unlink($tmp);
            self::$writable[$dir] = false;
            @error_log('OSM API ' . self::REASON_UNWRITABLE);
            return;
        }
        @chmod($p, 0600);
    }

    /** Admin only (bin/osm-clear-block.php): lift the app-wide block. Returns true if one was cleared. */
    public static function clearAppBlock(): bool
    {
        self::$memoryBlocked = false;
        $p = self::dir() . '/' . self::APP_BLOCK_FILE;
        if (!is_file($p)) {
            return false;
        }
        return @unlink($p);
    }

    /**
     * Can backoff state be saved? Creates storage/osm-rate (0700) if needed. Checked once per
     * request per directory; a failed save later in the request also flips it to false.
     */
    public static function storageWritable(): bool
    {
        $dir = self::dir();
        if (!isset(self::$writable[$dir])) {
            self::ensureDir($dir);
            self::$writable[$dir] = is_dir($dir) && is_writable($dir);
            if (!self::$writable[$dir]) {
                @error_log('OSM API ' . self::REASON_UNWRITABLE);
            }
        }
        return self::$writable[$dir];
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            $old = umask(0077);
            @mkdir($dir, 0700, true);
            umask($old);
        }
    }

    /**
     * Record allowance headers from a response. Returns the backoff reason if this response
     * started (or extended) a backoff, otherwise null.
     *
     * @param array<string, list<string>> $headers
     */
    public function recordResponse(int $status, array $headers): ?string
    {
        if (self::header($headers, 'X-Blocked') !== null) {
            self::blockApp();
            return self::REASON_BLOCKED;
        }
        $limit = self::intHeader($headers, 'X-RateLimit-Limit') ?? self::intHeader($headers, 'RateLimit-Limit') ?? self::ietf($headers, 'limit');
        $remaining = self::intHeader($headers, 'X-RateLimit-Remaining') ?? self::intHeader($headers, 'RateLimit-Remaining') ?? self::ietf($headers, 'remaining');
        $resetRaw = self::header($headers, 'X-RateLimit-Reset') ?? self::header($headers, 'RateLimit-Reset');
        $resetSec = self::resetSeconds($resetRaw);
        if ($resetSec === null) {
            $ietfReset = self::ietf($headers, 'reset');
            $resetSec = $ietfReset !== null ? max(0, $ietfReset) : null;
        }
        $retryAfter = self::retryAfterSeconds(self::header($headers, 'Retry-After'));

        return $this->locked(function () use ($status, $limit, $remaining, $resetSec, $retryAfter): ?string {
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
            if ($status === 429 || ($remaining !== null && $remaining <= 0)) {
                // Use the longer of Retry-After and Reset when OSM sent both: a short Retry-After
                // inside an hourly window that is still empty would only earn another 429.
                $candidates = [];
                if ($retryAfter !== null) {
                    $candidates[] = $retryAfter;
                }
                if (($resetSec ?? 0) > 0) {
                    $candidates[] = (int) $resetSec;
                }
                $wait = $candidates !== [] ? max($candidates) : self::DEFAULT_429_BACKOFF_SEC;
                $started = $status === 429 ? self::REASON_429 : self::REASON_EXHAUSTED;
                $st = self::withBackoff($st, $now + self::clampWait(max(self::MIN_HARD_BACKOFF_SEC, $wait)), $started);
            } elseif ($remaining !== null && self::isLow($remaining, $limit ?? (isset($st['limit']) ? (int) $st['limit'] : null))) {
                $wait = ($resetSec ?? 0) > 0 ? max(self::MIN_LOW_BACKOFF_SEC, (int) $resetSec) : self::DEFAULT_LOW_BACKOFF_SEC;
                $started = self::REASON_LOW;
                $st = self::withBackoff($st, $now + self::clampWait($wait), $started);
            } elseif ($remaining !== null && ($st['reason'] ?? null) === self::REASON_LOW) {
                // The window reset (an essential call saw a healthy allowance): lift the low backoff.
                $st['backoffUntil'] = 0;
                $st['reason'] = null;
            }
            $this->save($st);
            return $started;
        });
    }

    /**
     * Run $fn while holding this user's exclusive lock (persisted users only), so concurrent
     * requests' load/modify/save can't overwrite each other. If the lock can't be taken, the
     * state is not writable: fail closed for the rest of this request.
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function locked(callable $fn): mixed
    {
        if (!$this->persist) {
            return $fn();
        }
        $dir = self::dir();
        self::ensureDir($dir);
        $old = umask(0077);
        $fh = @fopen($this->path() . '.lock', 'c');
        umask($old);
        if ($fh === false || !@flock($fh, LOCK_EX)) {
            if ($fh !== false) {
                fclose($fh);
            }
            self::$writable[$dir] = false;
            @error_log('OSM API ' . self::REASON_UNWRITABLE);
            return $fn(); // save() keeps the state in memory for this request
        }
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * One value from the IETF RateLimit header (draft forms "limit=100, remaining=5, reset=30"
     * or "\"default\";r=5;t=30"). Key: limit | remaining | reset.
     * @param array<string, list<string>> $headers
     */
    private static function ietf(array $headers, string $key): ?int
    {
        $v = self::header($headers, 'RateLimit');
        if ($v === null || $v === '') {
            return null;
        }
        $aliases = ['limit' => ['limit', 'l', 'q'], 'remaining' => ['remaining', 'r'], 'reset' => ['reset', 't']][$key] ?? [$key];
        foreach (preg_split('/[;,]/', $v) ?: [] as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2 && in_array(strtolower(trim($kv[0])), $aliases, true) && is_numeric(trim($kv[1]))) {
                return (int) trim($kv[1]);
            }
        }
        return null;
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
        self::ensureDir($dir);
        $p = $this->path();
        $tmp = $p . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $old = umask(0077);
        $ok = @file_put_contents($tmp, json_encode($st), LOCK_EX);
        umask($old);
        if ($ok === false) {
            // Storage not writable: keep the state for this request, and fail closed from now on
            // (assertCanCall refuses every call while storage is unwritable).
            self::$memory[$this->key] = $st;
            $this->persist = false;
            self::$writable[$dir] = false;
            @error_log('OSM API ' . self::REASON_UNWRITABLE);
            return;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $p)) {
            @unlink($tmp);
            self::$memory[$this->key] = $st;
            $this->persist = false;
            self::$writable[$dir] = false;
            @error_log('OSM API ' . self::REASON_UNWRITABLE);
        }
    }
}
