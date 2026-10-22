<?php

declare(strict_types=1);

namespace App\Osm;

use App\Support\LogSafe;

/**
 * Durable append-only OSM API error log (always on, outside webroot).
 * OSM's own message text is never stored: each entry keeps the HTTP status, the error kind, a short
 * code-like osm_code, the endpoint path (no query string) and section_id/badge_id only. The one
 * app-authored `detail` field still goes through LogSafe::redact(). Member IDs (scoutid) are never
 * written. Entries older than MAX_AGE_SEC are pruned, and the file is capped by entry count and size.
 * Pruning and trimming rewrite the file in place under an exclusive flock (the same lock appends use),
 * so concurrent requests can't lose or duplicate lines.
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
     *   osm_message?: string|null,   (accepted for older callers but never written)
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
            // Only code-like values (e.g. "invalid-action", "123"): never free text from OSM.
            'osm_code' => self::idOnly($entry['osm_code'] ?? null),
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
                unset($decoded['osm_message'], $decoded['scoutid']); // older entries
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
        $cutoff = $now - self::MAX_AGE_SEC;
        return self::rewriteLocked($path, static function (array $lines) use ($cutoff): array {
            // Unreadable lines have no trustworthy date: drop them too.
            return array_values(array_filter($lines, static function ($line) use ($cutoff): bool {
                $t = self::entryTime((string) $line);
                return $t !== null && $t >= $cutoff;
            }));
        });
    }

    /** Old Top awards details carried "error=<OSM text>": keep code-like values only. */
    private static function scrubDetail(string $detail): string
    {
        return (string) preg_replace_callback(
            '/\b(ok|status|error)=([^,]*)/',
            static fn (array $m): string => preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $m[2]) === 1 ? $m[0] : $m[1] . ':text',
            $detail
        );
    }

    /**
     * Remove fields older versions wrote (OSM message text, scoutid, OSM text in details) from existing entries.
     * Run once by deploy.sh (bin/scrub-error-log.php); safe to re-run.
     *
     * @return int number of entries changed
     */
    public static function scrubLegacyFields(): int
    {
        $path = self::path();
        if (!is_file($path)) {
            return 0;
        }
        $changed = 0;
        self::rewriteLocked($path, static function (array $lines) use (&$changed): array {
            $out = [];
            foreach ($lines as $line) {
                $d = json_decode((string) $line, true);
                if (!is_array($d)) {
                    $changed++;
                    continue; // unreadable: drop
                }
                $detail = is_string($d['detail'] ?? null) ? $d['detail'] : null;
                $cleanDetail = $detail === null ? null : self::scrubDetail($detail);
                $osmCode = $d['osm_code'] ?? null;
                $cleanCode = is_scalar($osmCode) ? self::idOnly($osmCode) : null;
                if (array_key_exists('osm_message', $d) || array_key_exists('scoutid', $d)
                    || $cleanDetail !== $detail || ($osmCode !== null && $cleanCode !== $osmCode)) {
                    unset($d['osm_message'], $d['scoutid']);
                    if ($detail !== null) {
                        $d['detail'] = $cleanDetail;
                    }
                    if (array_key_exists('osm_code', $d)) {
                        $d['osm_code'] = $cleanCode;
                    }
                    $changed++;
                    $enc = json_encode($d, JSON_UNESCAPED_SLASHES);
                    if (!is_string($enc)) {
                        continue;
                    }
                    $line = $enc;
                }
                $out[] = (string) $line;
            }
            return $out;
        });
        return $changed;
    }

    /**
     * Read-modify-write the whole file under one exclusive flock (appends take the same lock), in place,
     * so no line appended meanwhile is lost. An empty result truncates the file to 0 bytes.
     *
     * @param callable(list<string>): list<string> $filter
     * @return int number of lines removed
     */
    private static function rewriteLocked(string $path, callable $filter): int
    {
        $fh = @fopen($path, 'c+b');
        if ($fh === false) {
            return 0;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return 0;
            }
            $data = stream_get_contents($fh);
            if (!is_string($data)) {
                return 0;
            }
            $lines = array_values(array_filter(explode("\n", $data), static fn ($l) => trim($l) !== ''));
            $keep = $filter($lines);
            $removed = count($lines) - count($keep);
            if ($keep === $lines) {
                return 0;
            }
            rewind($fh);
            ftruncate($fh, 0);
            if ($keep !== []) {
                fwrite($fh, implode("\n", $keep) . "\n");
            }
            fflush($fh);
            return $removed;
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
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
        clearstatcache(true, $path);
        $size = @filesize($path);
        // Cheap: only look at the line count once the file is large-ish.
        if (!is_int($size) || $size < 32768) {
            return;
        }
        $needTrim = $size > self::MAX_BYTES;
        self::rewriteLocked($path, static function (array $lines) use ($needTrim): array {
            if (count($lines) <= self::MAX_ENTRIES && !$needTrim) {
                return $lines;
            }
            return array_slice($lines, -self::MAX_ENTRIES);
        });
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
