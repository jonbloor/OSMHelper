<?php
declare(strict_types=1);
namespace App\Osm;

use App\Store\WordpressSiteKeyStore;
use Throwable;

/**
 * Keeps OSM access tokens fresh.
 *
 * OSM access tokens last about an hour, and every refresh returns a NEW refresh token and retires
 * the old one. Two things hold a copy of a leader's tokens:
 *  - the signed-in browser session ($_SESSION accessToken / refreshToken / accessTokenExpiresAt), and
 *  - the WordPress joining form row (wordpress_site_keys), copied from the session on Save.
 * If one side refreshes, the other side's refresh token stops working. So:
 *  - a session refresh also updates the form row when the row still has the same refresh token, and
 *  - when the session can't refresh (the form already rotated it), it adopts the form row's newer
 *    tokens (same OSM user) instead of failing.
 * Refreshes for one OSM user are serialised with a file lock.
 */
final class OsmTokens
{
    /** Seconds before expiry at which a token is treated as expired. */
    public const SKEW = 60;

    /** @var null|callable(string): (array{access: string, refresh: ?string, expires: ?int}|null) test hook */
    public static $refresher = null;

    /**
     * A usable access token for the current session, refreshing it if needed. Null = sign in again.
     */
    public static function sessionToken(): ?string
    {
        $token = $_SESSION['accessToken'] ?? '';
        if (!is_string($token) || $token === '') {
            return null;
        }
        if (!self::sessionNeedsRenewal($token)) {
            return $token;
        }
        $renewed = self::renewSession();
        if ($renewed === null) {
            self::clearSessionTokens();
        }
        return $renewed;
    }

    /** Called by OsmApi when OSM says the token isn't logged in, so the next page renews it. */
    public static function markRejected(string $accessToken): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !isset($_SESSION)) {
            return;
        }
        if (($_SESSION['accessToken'] ?? null) === $accessToken) {
            $_SESSION['osmTokenRejected'] = hash('sha256', $accessToken);
        }
    }

    public static function isNotLoggedInError(int $status, ?string $osmCode, ?string $osmMessage): bool
    {
        if ($status !== 401 && $status !== 403) {
            return false;
        }
        if ($osmCode === 'access-error-2') {
            return true;
        }
        $m = strtolower((string) $osmMessage);
        return str_contains($m, 'must be logged in') || str_contains($m, 'unauthenticated');
    }

    private static function sessionNeedsRenewal(string $token): bool
    {
        if (isset($_SESSION['osmTokenRejected']) && $_SESSION['osmTokenRejected'] === hash('sha256', $token)) {
            return true;
        }
        $exp = $_SESSION['accessTokenExpiresAt'] ?? null;
        return is_numeric($exp) && (int) $exp > 0 && (int) $exp <= time() + self::SKEW;
    }

    /**
     * Order: (1) the form row already holds a fresh token for this user → use it (no OSM call);
     * (2) refresh with the session's refresh token (and update the row if it shares that token);
     * (3) refresh with the form row's refresh token if different. Otherwise null.
     */
    public static function renewSession(): ?string
    {
        $oldAccess = (string) ($_SESSION['accessToken'] ?? '');
        $oldRefresh = isset($_SESSION['refreshToken']) && is_string($_SESSION['refreshToken']) ? $_SESSION['refreshToken'] : '';
        $userId = (string) ($_SESSION['osmUserId'] ?? '');

        return self::withUserLock($userId, static function () use ($oldAccess, $oldRefresh, $userId): ?string {
            $row = $userId !== '' ? self::safeRow($userId) : null;

            // (1) The form refreshed recently (or another tab did): reuse its fresh token.
            if (is_array($row)) {
                $rowAccess = (string) ($row['access_token'] ?? '');
                $rowExp = isset($row['token_expires_at']) && is_numeric($row['token_expires_at']) ? (int) $row['token_expires_at'] : 0;
                if ($rowAccess !== '' && $rowAccess !== $oldAccess && $rowExp > time() + self::SKEW) {
                    self::setSession($rowAccess, self::nullIfEmpty($row['refresh_token'] ?? null), $rowExp);
                    return $rowAccess;
                }
            }

            // (2) Refresh with the session's own refresh token.
            if ($oldRefresh !== '') {
                $new = self::refresh($oldRefresh);
                if ($new !== null) {
                    $refresh = $new['refresh'] ?? $oldRefresh;
                    self::setSession($new['access'], $refresh, $new['expires']);
                    if (is_array($row) && (string) ($row['refresh_token'] ?? '') === $oldRefresh) {
                        self::saveRow((int) $row['id'], $new['access'], $refresh, $new['expires']);
                    }
                    return $new['access'];
                }
            }

            // (3) The form rotated the shared refresh token: continue from the form's chain.
            if (is_array($row)) {
                $rowRefresh = (string) ($row['refresh_token'] ?? '');
                if ($rowRefresh !== '' && $rowRefresh !== $oldRefresh) {
                    $new = self::refresh($rowRefresh);
                    if ($new !== null) {
                        $refresh = $new['refresh'] ?? $rowRefresh;
                        self::saveRow((int) $row['id'], $new['access'], $refresh, $new['expires']);
                        self::setSession($new['access'], $refresh, $new['expires']);
                        return $new['access'];
                    }
                }
            }
            return null;
        });
    }

    /**
     * Refresh the form row's token (WordPress intake). Re-reads the row under the lock, so a refresh
     * done meanwhile by the leader's session is reused instead of spending the retired refresh token.
     *
     * @param array<string, mixed> $row
     * @return array{access: string, refresh: ?string, expires: ?int, row: array<string, mixed>}|null
     */
    public static function renewRow(array $row): ?array
    {
        $userId = (string) ($row['osm_user_id'] ?? '');
        return self::withUserLock($userId, static function () use ($row): ?array {
            $current = WordpressSiteKeyStore::findBySiteKey((string) ($row['site_key'] ?? '')) ?? $row;
            $access = (string) ($current['access_token'] ?? '');
            $exp = isset($current['token_expires_at']) && is_numeric($current['token_expires_at']) ? (int) $current['token_expires_at'] : 0;
            if ($access !== '' && ($exp === 0 || $exp > time() + self::SKEW)) {
                return ['access' => $access, 'refresh' => self::nullIfEmpty($current['refresh_token'] ?? null), 'expires' => $exp ?: null, 'row' => $current];
            }
            $refresh = (string) ($current['refresh_token'] ?? '');
            if ($refresh === '') {
                return null;
            }
            $new = self::refresh($refresh);
            if ($new === null) {
                return null;
            }
            $newRefresh = $new['refresh'] ?? $refresh;
            self::saveRow((int) $current['id'], $new['access'], $newRefresh, $new['expires']);
            return ['access' => $new['access'], 'refresh' => $newRefresh, 'expires' => $new['expires'], 'row' => $current];
        });
    }

    /** @return array{access: string, refresh: ?string, expires: ?int}|null */
    private static function refresh(string $refreshToken): ?array
    {
        if (self::$refresher !== null) {
            return (self::$refresher)($refreshToken);
        }
        try {
            $token = (new OsmOAuth())->getProvider()->getAccessToken('refresh_token', ['refresh_token' => $refreshToken]);
        } catch (Throwable $e) {
            // No token values in the log.
            error_log('OSMHelper token refresh failed: ' . get_class($e));
            return null;
        }
        $access = $token->getToken();
        if (!is_string($access) || $access === '') {
            return null;
        }
        $refresh = $token->getRefreshToken();
        $expires = $token->getExpires();
        return [
            'access' => $access,
            'refresh' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            'expires' => is_int($expires) && $expires > 0 ? $expires : null,
        ];
    }

    private static function setSession(string $access, ?string $refresh, ?int $expires): void
    {
        $_SESSION['accessToken'] = $access;
        if ($refresh !== null && $refresh !== '') {
            $_SESSION['refreshToken'] = $refresh;
        }
        if ($expires !== null && $expires > 0) {
            $_SESSION['accessTokenExpiresAt'] = $expires;
        } else {
            unset($_SESSION['accessTokenExpiresAt']);
        }
        unset($_SESSION['osmTokenRejected']);
    }

    private static function clearSessionTokens(): void
    {
        unset($_SESSION['accessToken'], $_SESSION['refreshToken'], $_SESSION['accessTokenExpiresAt'], $_SESSION['osmTokenRejected']);
    }

    /** @return array<string, mixed>|null */
    private static function safeRow(string $userId): ?array
    {
        try {
            return WordpressSiteKeyStore::findByOsmUserId($userId);
        } catch (Throwable) {
            return null;
        }
    }

    private static function saveRow(int $id, string $access, ?string $refresh, ?int $expires): void
    {
        try {
            WordpressSiteKeyStore::updateTokens($id, $access, $refresh, $expires);
        } catch (Throwable $e) {
            error_log('OSMHelper could not save refreshed form token: ' . get_class($e));
        }
    }

    private static function nullIfEmpty(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private static function withUserLock(string $userId, callable $fn): mixed
    {
        $dir = dirname(__DIR__, 2) . '/storage';
        $fh = false;
        if (is_dir($dir) && is_writable($dir)) {
            $fh = @fopen($dir . '/token-' . substr(hash('sha256', $userId !== '' ? $userId : 'anon'), 0, 16) . '.lock', 'c');
        }
        if ($fh !== false) {
            @flock($fh, LOCK_EX);
        }
        try {
            return $fn();
        } finally {
            if ($fh !== false) {
                @flock($fh, LOCK_UN);
                @fclose($fh);
            }
        }
    }
}
