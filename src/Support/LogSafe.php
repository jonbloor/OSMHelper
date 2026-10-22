<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Helpers for anything written to a log (PHP error_log or storage/osm-errors.jsonl).
 * Logs must never carry personal details, member IDs, OSM reply text or tokens:
 * use reason() to log an error type/code only, or redact() for free text that has to be kept.
 */
final class LogSafe
{
    /** Keys whose values are personal details and must never reach a log. */
    public const PII_KEYS = 'first_?name|last_?name|full_?name|name|email\\d*|phone\\d*|mobile|address\\d*|postcode|dob|date_of_birth|notes?|comment|birthday|date.?of.?birth|emergency\\w*|custom_?data\\w*|scout_?id|member_?id';

    /**
     * Short, fixed reason for an exception: class name, numeric code and a reason code derived from
     * OSMHelper's own message prefixes. Never includes the message text itself.
     * Example: "RuntimeException#429 osm_http_429".
     */
    public static function reason(Throwable $e): string
    {
        $class = (new \ReflectionClass($e))->getShortName();
        return $class . '#' . (int) $e->getCode() . ' ' . self::reasonCode($e->getMessage());
    }

    /** Map an error message to a fixed code without keeping any of its text. */
    public static function reasonCode(string $message): string
    {
        if (preg_match('/^(?:OSM_INVALID_ACTION: )?OSM HTTP (\d{3})\b/', $message, $m) === 1) {
            return (str_starts_with($message, 'OSM_INVALID_ACTION') ? 'osm_invalid_action_' : 'osm_http_') . $m[1];
        }
        $prefixes = [
            'OSM_BLOCKED' => 'osm_blocked',
            'OSM_INVALID_ACTION' => 'osm_invalid_action',
            'OSM transport error' => 'osm_transport',
            'OSM marked this endpoint removed' => 'osm_deprecated',
            'SQLSTATE' => 'database',
        ];
        foreach ($prefixes as $prefix => $code) {
            if (str_starts_with($message, $prefix)) {
                return $code;
            }
        }
        if (stripos($message, 'X-Deprecated') !== false) {
            return 'osm_deprecated';
        }
        if (stripos($message, 'invalid_grant') !== false) {
            return 'oauth_invalid_grant';
        }
        if (stripos($message, 'state') !== false && stripos($message, 'oauth') !== false) {
            return 'oauth_state';
        }
        return 'error';
    }

    /** Path only: drop any query string (it can carry IDs or search terms). */
    public static function pathOnly(string $url): string
    {
        $url = trim($url);
        $cut = strcspn($url, '?#');
        return substr($url, 0, $cut);
    }

    /**
     * Remove tokens and personal details from free text before it is logged.
     * Keeps the shape of the message (so it can still be diagnosed) but not the values.
     */
    public static function redact(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer [redacted]', $s) ?? $s;
        $s = preg_replace('/(access_token|refresh_token|client_secret|code|SESSION_SECRET|OSMHELPER_TOKEN_KEY)=([^&\s"\']+)/i', '$1=[redacted]', $s) ?? $s;
        $s = preg_replace('/(["\']?(?:access_token|refresh_token|client_secret|token)["\']?\s*(?::|=>)\s*)("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\')/i', '$1"[redacted]"', $s) ?? $s;
        // Personal fields in JSON-ish text ("firstname":"Ann", 'lastname' => 'Smith', notes=...).
        $s = preg_replace(
            '/(["\']?(?:' . self::PII_KEYS . ')["\']?\s*(?::|=>|=)\s*)("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^,&}\r\n]+)/i',
            '$1"[redacted]"',
            $s
        ) ?? $s;
        // Query strings in URLs or paths can carry IDs or search terms: keep the path only.
        $s = preg_replace('#((?:https?://[^\s?\#"\']+)|(?<![\w])/[A-Za-z0-9._\-/]*)\?[^\s\#"\']*#i', '$1?[query removed]', $s) ?? $s;
        $s = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $s) ?? $s;
        $s = preg_replace('/(?<![\d\-])(?:\+44\s?|0)\d(?:[\s\-]?\d){8,9}(?!\d)/', '[phone]', $s) ?? $s;
        $s = preg_replace('/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/i', '[postcode]', $s) ?? $s;
        // Long opaque strings (JWTs, OAuth tokens, keys).
        $s = preg_replace('/\b[A-Za-z0-9_\-]{12,}\.[A-Za-z0-9_\-]{12,}\.[A-Za-z0-9_\-]{8,}\b/', '[token]', $s) ?? $s;
        $s = preg_replace('/\b[A-Za-z0-9+_\-]{40,}={0,2}/', '[token]', $s) ?? $s;
        return $s;
    }
}
