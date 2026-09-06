<?php
declare(strict_types=1);
namespace App\Http;
use App\App;
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

    public static function baseContext(array $extra = []): array
    {
        return array_merge([
            'authed' => App::isAuthenticated(),
            'fullName' => (string) ($_SESSION['fullName'] ?? ''),
            'email' => (string) ($_SESSION['email'] ?? ''),
            'groupName' => (string) ($_SESSION['groupName'] ?? 'OSM Helper'),
        ], $extra);
    }
}
