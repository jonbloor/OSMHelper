<?php
declare(strict_types=1);
namespace App\Http;
use App\App;
use App\Osm\OsmApi;
use Throwable;
final class Auth
{
    public static function requireLogin(): string
    {
        $token = $_SESSION['accessToken'] ?? '';
        if (!is_string($token) || $token === '') {
            header('Location: /auth/');
            exit;
        }
        return $token;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    public static function baseContext(array $extra = []): array
    {
        $ctx = [
            'authed' => App::isAuthenticated(),
            'fullName' => (string) ($_SESSION['fullName'] ?? ''),
            'email' => (string) ($_SESSION['email'] ?? ''),
            'groupName' => (string) ($_SESSION['groupName'] ?? 'OSM Helper'),
        ];
        if ($ctx['authed']) {
            $ctx = array_merge($ctx, self::rateLimitContext());
        }
        return array_merge($ctx, $extra);
    }

    /**
     * Always returns a rateLimit array when called (Unknowns if OSM headers missing).
     * @return array{rateLimit: array<string, mixed>, rateResetText: string}
     */
    public static function rateLimitContext(): array
    {
        return self::ensureRateLimit();
    }

    /** @return array{rateLimit: array<string, mixed>, rateResetText: string} */
    public static function ensureRateLimit(): array
    {
        $token = (string) ($_SESSION['accessToken'] ?? '');
        $rate = OsmApi::sessionSnapshot();
        if ($rate === null && $token !== '') {
            try {
                (new OsmApi())->get($token, '/oauth/resource');
            } catch (Throwable) {
            }
            $rate = OsmApi::sessionSnapshot();
        }
        if ($rate === null) {
            $rate = [
                'limit' => null,
                'remaining' => null,
                'resetInSec' => null,
                'secondsUntilReset' => null,
            ];
        }
        $secs = $rate['secondsUntilReset'] ?? null;
        if ($secs === null) {
            $text = 'Unknown';
        } elseif ((int) $secs <= 0) {
            $text = 'now';
        } else {
            $mins = (int) ceil(((int) $secs) / 60);
            $text = $mins . ' minute' . ($mins === 1 ? '' : 's');
        }
        return ['rateLimit' => $rate, 'rateResetText' => $text];
    }
}
