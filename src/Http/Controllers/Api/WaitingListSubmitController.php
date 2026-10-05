<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;
use App\Waiting\WaitingListIntake;
use App\Waiting\WaitingListIntakeException;
use Throwable;
/**
 * Authenticated WordPress → OSM Helper waiting-list intake.
 * Auth: Bearer site key or X-Osmhelper-Site-Key. No session / CSRF.
 */
final class WaitingListSubmitController
{
    public function submit(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::json(405, ['ok' => false, 'error' => 'Method not allowed.']);
            return;
        }

        $siteKey = self::siteKeyFromRequest();
        if ($siteKey === '') {
            self::json(401, ['ok' => false, 'error' => 'Missing site key.']);
            return;
        }

        $payload = self::jsonBody();
        if ($payload === null) {
            self::json(400, ['ok' => false, 'error' => 'Expected a JSON body.']);
            return;
        }

        try {
            $result = WaitingListIntake::submitWithSiteKey($siteKey, $payload);
            self::json(200, self::successBody($result));
        } catch (WaitingListIntakeException $e) {
            // Do not echo child data or tokens.
            error_log('OSMHelper waiting-list intake: HTTP ' . $e->status() . ' — ' . $e->getMessage());
            self::json($e->status(), ['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('OSMHelper waiting-list intake failed: ' . $e->getMessage());
            self::json(502, ['ok' => false, 'error' => 'Could not add the child to the waiting list.']);
        }
    }

    /**
     * Success JSON. The member exists in OSM; `partial` is true when an optional step (the parent
     * note) did not save, with plain-English `warnings` for the WordPress admin log. No PII.
     *
     * @param array{scoutid:int, note_status?:string, warnings?:list<string>} $result
     * @return array<string, mixed>
     */
    public static function successBody(array $result): array
    {
        $noteStatus = (string) ($result['note_status'] ?? 'none');
        $warnings = array_values(array_filter(
            is_array($result['warnings'] ?? null) ? $result['warnings'] : [],
            'is_string'
        ));
        return [
            'ok' => true,
            'scoutid' => (int) $result['scoutid'],
            'partial' => in_array($noteStatus, ['skipped', 'failed'], true),
            'note_status' => $noteStatus,
            'warnings' => $warnings,
        ];
    }

    private static function siteKeyFromRequest(): string
    {
        $header = $_SERVER['HTTP_X_OSMHELPER_SITE_KEY'] ?? '';
        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (is_string($auth) && preg_match('/^Bearer\s+(\S+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /** @return array<string, mixed>|null */
    private static function jsonBody(): ?array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }
}
