<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Stops an automatic sign-in loop. /auth normally sends the browser straight on to OSM, but on a
 * phone Android can hand the OSM link to an installed OSM app (a different browser with its own
 * cookies), so the sign-in never finishes here and every page sends the leader to OSM again.
 * After a second /auth visit within a few minutes with no successful sign-in, /auth shows a
 * "Continue to OSM" button instead of redirecting. The counter lives in the session and is wiped
 * by App::beginAuthenticatedSession() on a successful sign-in.
 *
 * A second check catches a loop where sign-in succeeds but the session is then lost (each pass
 * costs two OSM calls): recent successful sign-ins are kept in their own short-lived signed cookie,
 * which a successful sign-in adds to rather than clears. Several in a few minutes and /auth shows the
 * button too. Sign-out clears it.
 */
final class SignInGuard
{
    public const SESSION_KEY = 'signInAttempts';
    /** Look-back window for /auth visits. */
    public const WINDOW_SECONDS = 300;
    /** This many /auth visits in the window (including the current one) shows the button instead. */
    public const MAX_AUTOMATIC = 2;
    /** Cookie listing recent successful sign-ins (timestamps plus an HMAC); not the PHP session. */
    public const SUCCESS_COOKIE = 'osmhelper_signins';
    /** This many successful sign-ins in the window means the session isn't sticking: show the button. */
    public const MAX_RECENT_SUCCESSES = 3;

    /**
     * Record one /auth visit and return how many there have been in the window, this one included.
     * @param array<string, mixed> $session
     */
    public static function recordAttempt(array &$session, ?int $now = null): int
    {
        $now ??= time();
        $kept = [];
        $raw = $session[self::SESSION_KEY] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $t) {
                if (is_int($t) && $t <= $now && $now - $t < self::WINDOW_SECONDS) {
                    $kept[] = $t;
                }
            }
        }
        $kept[] = $now;
        // Only the recent few matter; keep the session small.
        $kept = array_slice($kept, -10);
        $session[self::SESSION_KEY] = $kept;

        return count($kept);
    }

    /**
     * Whether /auth should show the "Continue to OSM" page instead of redirecting.
     * $go: the leader pressed "Continue to OSM" (always allowed through).
     * $retry: they came from a "Try again here" link (always shown the page first).
     * $recentSuccesses: successful sign-ins in the window (see successesInWindow()).
     */
    public static function shouldPause(int $attemptsInWindow, bool $retry, bool $go, int $recentSuccesses = 0): bool
    {
        if ($go) {
            return false;
        }

        return $retry
            || $attemptsInWindow >= self::MAX_AUTOMATIC
            || $recentSuccesses >= self::MAX_RECENT_SUCCESSES;
    }

    /**
     * Successful sign-in times from the cookie value that are still in the window. A cookie that is
     * missing, malformed or not signed with $secret counts as none.
     * @return list<int>
     */
    public static function successesInWindow(string $cookieRaw, string $secret, ?int $now = null): array
    {
        $now ??= time();
        $dot = strrpos($cookieRaw, '.');
        if ($dot === false || $dot === 0) {
            return [];
        }
        $list = substr($cookieRaw, 0, $dot);
        $sig = substr($cookieRaw, $dot + 1);
        if (!hash_equals(self::signSuccesses($list, $secret), $sig)) {
            return [];
        }
        $kept = [];
        foreach (explode(',', $list) as $part) {
            if (!ctype_digit($part)) {
                continue;
            }
            $t = (int) $part;
            if ($t <= $now && $now - $t < self::WINDOW_SECONDS) {
                $kept[] = $t;
            }
        }

        return array_slice($kept, -10);
    }

    /** New cookie value with one more successful sign-in at $now (older ones out of the window dropped). */
    public static function addSuccess(string $cookieRaw, string $secret, ?int $now = null): string
    {
        $now ??= time();
        $kept = self::successesInWindow($cookieRaw, $secret, $now);
        $kept[] = $now;
        $list = implode(',', array_slice($kept, -10));

        return $list . '.' . self::signSuccesses($list, $secret);
    }

    /** Successful sign-ins in the window for this request's browser. */
    public static function recentSuccesses(): int
    {
        $raw = isset($_COOKIE[self::SUCCESS_COOKIE]) ? (string) $_COOKIE[self::SUCCESS_COOKIE] : '';

        return count(self::successesInWindow($raw, self::secret()));
    }

    /** Call when a sign-in succeeds (the token exchange worked). */
    public static function noteSuccess(): void
    {
        $raw = isset($_COOKIE[self::SUCCESS_COOKIE]) ? (string) $_COOKIE[self::SUCCESS_COOKIE] : '';
        $value = self::addSuccess($raw, self::secret());
        $_COOKIE[self::SUCCESS_COOKIE] = $value;
        self::setSuccessCookie($value, time() + self::WINDOW_SECONDS);
    }

    /** Sign-out: forget recent sign-ins so signing straight back in isn't paused. */
    public static function forgetSuccesses(): void
    {
        unset($_COOKIE[self::SUCCESS_COOKIE]);
        self::setSuccessCookie('', time() - 42000);
    }

    private static function signSuccesses(string $list, string $secret): string
    {
        // Own prefix so this HMAC can never be mistaken for the OAuth state cookie's.
        return hash_hmac('sha256', 'signin-successes:' . $list, $secret);
    }

    private static function secret(): string
    {
        return \App\Config::get('SESSION_SECRET', 'dev-insecure-change-me') ?? 'dev-insecure-change-me';
    }

    private static function setSuccessCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::SUCCESS_COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => OAuthState::cookieShouldBeSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** @param array<string, mixed> $session */
    public static function clear(array &$session): void
    {
        unset($session[self::SESSION_KEY]);
    }
}
