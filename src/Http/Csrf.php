<?php
declare(strict_types=1);

namespace App\Http;

use App\App;

/** Per-session CSRF token for state-changing POSTs. */
final class Csrf
{
    public static function token(): string
    {
        $existing = $_SESSION['_csrf'] ?? null;
        if (!is_string($existing) || $existing === '') {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_csrf'];
    }

    public static function validate(?string $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? '';
        return is_string($expected)
            && $expected !== ''
            && is_string($token)
            && $token !== ''
            && hash_equals($expected, $token);
    }

    public static function requireValid(): void
    {
        $raw = $_POST['_csrf'] ?? '';
        $token = is_string($raw) ? $raw : '';
        if (!self::validate($token)) {
            http_response_code(403);
            App::render('error.twig', Auth::baseContext([
                'title' => 'Forbidden',
                'message' => 'Invalid or missing security token. Go back, refresh the page, and try again.',
            ]));
            exit;
        }
    }
}
