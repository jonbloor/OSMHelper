<?php
declare(strict_types=1);
namespace App\Http;
use App\App;
use App\Osm\OsmApi;
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

    /** @return array{rateLimit: ?array, rateResetText: ?string} */
    public static function rateLimitContext(): array
    {
        $rate = OsmApi::sessionSnapshot();
        $text = null;
        if (is_array($rate)) {
            $secs = $rate['secondsUntilReset'] ?? null;
            if ($secs === null) {
                $text = 'Unknown';
            } elseif ((int) $secs <= 0) {
                $text = 'now';
            } else {
                $mins = (int) ceil(((int) $secs) / 60);
                $text = $mins . ' min';
            }
        }
        return ['rateLimit' => $rate, 'rateResetText' => $text];
    }
}
