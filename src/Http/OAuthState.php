<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;

/**
 * Bind OAuth CSRF state to the browser without relying solely on the PHP session
 * cookie surviving a cross-site 302 to OSM and back.
 */
final class OAuthState
{
    public const COOKIE = 'osmhelper_oauth';

    /** Session keys every request sets (App::bootSession / enforceSessionMaxAge, Csrf): not a sign-in in progress. */
    private const HOUSEKEEPING_KEYS = ['_init', '_csrf', '_startedAt', '_lastSeen'];

    public static function persist(string $state): void
    {
        $_SESSION['oauth2state'] = $state;
        $payload = $state . '.' . self::sign($state);
        self::setCookie($payload, time() + 900);
    }

    /**
     * @return 'ok'|'state_param_missing'|'empty_session'|'state_missing'|'state_mismatch'
     */
    public static function consume(string $stateFromQuery): string
    {
        $sessionState = $_SESSION['oauth2state'] ?? null;
        unset($_SESSION['oauth2state']);
        $cookieRaw = isset($_COOKIE[self::COOKIE]) ? (string) $_COOKIE[self::COOKIE] : '';
        self::setCookie('', time() - 42000);
        unset($_COOKIE[self::COOKIE]);

        if ($stateFromQuery === '') {
            return 'state_param_missing';
        }

        $cookieOk = self::cookieMatches($cookieRaw, $stateFromQuery);
        $sessionOk = is_string($sessionState)
            && $sessionState !== ''
            && hash_equals($sessionState, $stateFromQuery);

        if ($cookieOk || $sessionOk) {
            return 'ok';
        }

        $hasSessionCookie = isset($_COOKIE[session_name()]) && (string) $_COOKIE[session_name()] !== '';
        $sessionEmpty = self::sessionLooksEmpty($sessionState);

        if (!$hasSessionCookie && $cookieRaw === '' && $sessionEmpty) {
            return 'empty_session';
        }
        if (($sessionState === null || $sessionState === '') && $cookieRaw === '') {
            return 'state_missing';
        }

        return 'state_mismatch';
    }

    /** Short plain-English reason for the retry page's "Details" line, or null. The reason code itself only goes to the log. */
    public static function detail(string $reason): ?string
    {
        return match ($reason) {
            'state_param_missing' => 'OSM sent your browser back without the sign-in details we need.',
            'empty_session' => 'This browser had no sign-in in progress. It may have started in another browser or app, or cookies may be blocked.',
            'state_missing' => 'Your browser came back from OSM without the sign-in details we saved.',
            'state_mismatch' => 'Your browser came back from OSM with a different sign-in from the one we were expecting (for example from another tab, or the button was pressed twice).',
            default => null,
        };
    }

    private static function sign(string $state): string
    {
        $secret = Config::get('SESSION_SECRET', 'dev-insecure-change-me') ?? 'dev-insecure-change-me';

        return hash_hmac('sha256', $state, $secret);
    }

    private static function cookieMatches(string $cookieRaw, string $stateFromQuery): bool
    {
        if ($cookieRaw === '' || !str_contains($cookieRaw, '.')) {
            return false;
        }
        [$cState, $cSig] = explode('.', $cookieRaw, 2);
        if ($cState === '' || $cSig === '') {
            return false;
        }
        $expect = self::sign($cState);

        return hash_equals($expect, $cSig) && hash_equals($cState, $stateFromQuery);
    }

    private static function sessionLooksEmpty(mixed $sessionState): bool
    {
        if (is_string($sessionState) && $sessionState !== '') {
            return false;
        }
        $keys = array_keys($_SESSION);
        $keys = array_values(array_filter($keys, static fn ($k) => !in_array($k, self::HOUSEKEEPING_KEYS, true)));

        return $keys === [];
    }

    private static function setCookie(string $value, int $expires): void
    {
        $https = true;
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '' && !str_contains($host, 'osmhelper.co.uk')) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        }
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
