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

    public static function userMessage(string $reason): string
    {
        $base = 'Invalid OAuth state. Please try connecting again.';
        $hint = ' If this keeps happening, allow cookies for osmhelper.co.uk and retry.';

        return match ($reason) {
            'state_param_missing' => $base . ' (missing state from OSM.)' . $hint,
            'empty_session' => $base . ' (login session was empty on return — often blocked or dropped cookies.)' . $hint,
            'state_missing' => $base . ' (no saved login state on return.)' . $hint,
            'state_mismatch' => $base . ' (saved login state did not match.)' . $hint,
            default => $base . $hint,
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
        $keys = array_values(array_filter($keys, static fn ($k) => $k !== '_init' && $k !== '_csrf'));

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
