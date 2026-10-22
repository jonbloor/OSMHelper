<?php

declare(strict_types=1);

namespace App\Osm;

use App\Support\LogSafe;

/**
 * Durable append-only OSM API error log (always on, outside webroot).
 * Redacts tokens and personal details (names, emails, phone numbers, dates of birth, notes) from the
 * free-text fields via LogSafe::redact(); keeps only section_id/badge_id for diagnosis. Member IDs
 * (scoutid) are never written. Entries older than MAX_AGE_SEC are pruned, and the file is capped by
 * entry count and approximate size.
 */
final class OsmErrorLog
{
    private const MAX_ENTRIES = 200;
    private const MAX_BYTES = 524288; // 512 KiB soft cap before trim
    private const FILENAME = 'osm-errors.jsonl';
    /** Entries are kept for at most 30 days. */
    public const MAX_AGE_SEC = 30 * 86400;

    /** Test helper: write to another directory instead of storage/. */
    private static ?string $dirOverride = null;

    public static function setDirForTests(?string $dir): void
    {
        self::$dirOverride = $dir;
    }

    public static function path(): string
    {
        return (self::$dirOverride ?? dirname(__DIR__, 2) . '/storage') . '/' . self::FILENAME;
    }

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
     * } $entry  (scoutid is accepted for older callers but never written)
     */
    public static function log(array $entry): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $row = [
            'at' => gmdate('c'),
            'method' => self::clip((string) ($entry['method'] ?? ''), 16),
            'endpoint' => self::clip(self::endpointOnly((string) ($entry['endpoint'] ?? '')), 160),
            'action' => self::clipNullable($entry['action'] ?? null, 80),
            'http_status' => isset($entry['http_status']) && is_numeric($entry['http_status'])
                ? (int) $entry['http_status']
                : null,
            'osm_code' => self::clipNullable($entry['osm_code'] ?? null, 80),
            'osm_message' => self::clipNullable(LogSafe::redact(self::str($entry['osm_message'] ?? null)), 240),
            'kind' => self::clip((string) ($entry['kind'] ?? 'http'), 40),
            'section_id' => self::idOnly($entry['section_id'] ?? null),
            'badge_id' => self::idOnly($entry['badge_id'] ?? null),
            'detail' => self::clipNullable(LogSafe::redact(self::str($entry['detail'] ?? null)), 200),
        ];

        $line = json_encode($row, JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }
        $ok = @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($ok === false) {
            @error_log('OsmErrorLog write failed (storage not writable)');
            return;
        }
        @chmod($path, 0660);
        @chgrp('osmhelper', $path);
        self::rotateIfNeeded($path);
        self::pruneOld();
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 10): array
    {
        $path = self::path();
        if (!is_readable($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return [];
        }
        $slice = array_slice($lines, -max(1, min(self::MAX_ENTRIES, $limit)));
        $out = [];
        foreach ($slice as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }

    /**
     * Drop entries older than MAX_AGE_SEC. Cheap when nothing is due: reads only the first line
     * (entries are appended in time order). Called on every request from App::run() and after each write.
     *
     * @return int number of entries removed
     */
    public static function pruneOld(?int $now = null): int
    {
        $now ??= time();
        $path = self::path();
        if (!is_file($path)) {
            return 0;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return 0;
        }
        $first = fgets($fh);
        fclose($fh);
        if (!is_string($first) || $first === '') {
            return 0;
        }
        $at = self::entryTime($first);
        if ($at !== null && $at >= $now - self::MAX_AGE_SEC) {
            return 0;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return 0;
        }
        $keep = [];
        foreach ($lines as $line) {
            $t = self::entryTime((string) $line);
            // Unreadable lines have no trustworthy date: drop them too.
            if ($t !== null && $t >= $now - self::MAX_AGE_SEC) {
                $keep[] = $line;
            }
        }
        $removed = count($lines) - count($keep);
        if ($removed <= 0) {
            return 0;
        }
        if ($keep === []) {
            @unlink($path);
        } else {
            @file_put_contents($path, implode("\n", $keep) . "\n", LOCK_EX);
            @chmod($path, 0660);
        }
        return $removed;
    }

    private static function entryTime(string $line): ?int
    {
        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded) || !is_string($decoded['at'] ?? null)) {
            return null;
        }
        $t = strtotime($decoded['at']);
        return $t === false ? null : $t;
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

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
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
