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
 */
final class SignInGuard
{
    public const SESSION_KEY = 'signInAttempts';
    /** Look-back window for /auth visits. */
    public const WINDOW_SECONDS = 300;
    /** This many /auth visits in the window (including the current one) shows the button instead. */
    public const MAX_AUTOMATIC = 2;

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
     */
    public static function shouldPause(int $attemptsInWindow, bool $retry, bool $go): bool
    {
        if ($go) {
            return false;
        }

        return $retry || $attemptsInWindow >= self::MAX_AUTOMATIC;
    }

    /** @param array<string, mixed> $session */
    public static function clear(array &$session): void
    {
        unset($session[self::SESSION_KEY]);
    }
}
