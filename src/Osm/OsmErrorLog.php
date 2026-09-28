<?php

declare(strict_types=1);

namespace App\Osm;

/**
 * Durable append-only OSM API error log (always on, outside webroot).
 * Redacts tokens and personal details (names, emails, phone numbers, dates of birth, notes) from the
 * free-text fields; keeps numeric scoutid/section_id/badge_id for diagnosis.
 * Rotate by capping entry count and approximate file size.
 */
final class OsmErrorLog
{
    private const MAX_ENTRIES = 200;
    private const MAX_BYTES = 524288; // 512 KiB soft cap before trim
    private const FILENAME = 'osm-errors.jsonl';

    /** Keys whose values are personal details and must never reach the log. */
    private const PII_KEYS = 'first_?name|last_?name|full_?name|name|email\\d*|phone\\d*|mobile|address\\d*|dob|date_of_birth|notes?|comment';

    /**
     * @param array{
     *   method?: string,
     *   endpoint?: string,
     *   action?: string|null,
     *   http_status?: int|null,
     *   osm_code?: string|null,
     *   osm_message?: string|null,
     *   kind?: string,
     *   section_id?: string|null,
     *   badge_id?: string|null,
     *   scoutid?: string|null,
     *   detail?: string|null
     * } $entry
     */
    public static function log(array $entry): void
    {
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $path = $dir . '/' . self::FILENAME;

        $row = [
            'at' => gmdate('c'),
            'method' => self::clip((string) ($entry['method'] ?? ''), 16),
            'endpoint' => self::clip(self::endpointOnly((string) ($entry['endpoint'] ?? '')), 160),
            'action' => self::clipNullable($entry['action'] ?? null, 80),
            'http_status' => isset($entry['http_status']) && is_numeric($entry['http_status'])
                ? (int) $entry['http_status']
                : null,
            'osm_code' => self::clipNullable($entry['osm_code'] ?? null, 80),
            'osm_message' => self::clipNullable(self::redactDetail($entry['osm_message'] ?? null), 240),
            'kind' => self::clip((string) ($entry['kind'] ?? 'http'), 40),
            'section_id' => self::idOnly($entry['section_id'] ?? null),
            'badge_id' => self::idOnly($entry['badge_id'] ?? null),
            'scoutid' => self::idOnly($entry['scoutid'] ?? null),
            'detail' => self::clipNullable(self::redactDetail($entry['detail'] ?? null), 200),
        ];

        $line = json_encode($row, JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }
        $ok = @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($ok === false) {
            @error_log('OsmErrorLog write failed: ' . $path);
            return;
        }
        @chmod($path, 0660);
        @chgrp('osmhelper', $path);
        self::rotateIfNeeded($path);
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 10): array
    {
        $path = dirname(__DIR__, 2) . '/storage/' . self::FILENAME;
        if (!is_readable($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return [];
        }
        $slice = array_slice($lines, -max(1, min(50, $limit)));
        $out = [];
        foreach ($slice as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }

    private static function rotateIfNeeded(string $path): void
    {
        $size = @filesize($path);
        $needTrim = is_int($size) && $size > self::MAX_BYTES;
        if (!$needTrim) {
            // Cheap line-count check only when file is large-ish
            if (!is_int($size) || $size < 32768) {
                return;
            }
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }
        if (count($lines) <= self::MAX_ENTRIES && !$needTrim) {
            return;
        }
        $keep = array_slice($lines, -self::MAX_ENTRIES);
        @file_put_contents($path, implode("\n", $keep) . "\n", LOCK_EX);
        @chmod($path, 0660);
    }

    private static function endpointOnly(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        // Strip query string from logged endpoint; action is separate.
        $q = strpos($path, '?');
        if ($q !== false) {
            $path = substr($path, 0, $q);
        }
        return '/' . ltrim($path, '/');
    }

    private static function clip(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max - 1) . '…';
    }

    private static function clipNullable(mixed $v, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        return self::clip($s, $max);
    }

    private static function redactDetail(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string) $v;
        $s = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $s) ?? $s;
        $s = preg_replace('/(access_token|refresh_token|client_secret|SESSION_SECRET)=([^&\s]+)/i', '$1=[redacted]', $s) ?? $s;
        // Personal fields in JSON-ish text ("firstname":"Ann", 'lastname' => 'Smith', notes=...).
        $s = preg_replace(
            '/(["\']?(?:' . self::PII_KEYS . ')["\']?\s*(?::|=>|=)\s*)("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^,&}\r\n]+)/i',
            '$1"[redacted]"',
            $s
        ) ?? $s;
        $s = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $s) ?? $s;
        $s = preg_replace('/(?<![\d\-])(?:\+44\s?|0)\d(?:[\s\-]?\d){8,9}(?!\d)/', '[phone]', $s) ?? $s;
        return $s;
    }

    /** IDs are kept only if they look like IDs (digits/short tokens), never free text. */
    private static function idOnly(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $s) === 1 ? $s : null;
    }
}
