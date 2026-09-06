<?php
declare(strict_types=1);
namespace App\Store;
/**
 * Durable JSON settings under storage/settings.json (outside webroot).
 * Survives logout — session alone is not enough for capacity/section picks.
 */
final class SettingsStore
{
    private static function path(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir . '/settings.json';
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $path = self::path();
        if (!is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $patch */
    public static function merge(array $patch): void
    {
        $data = array_merge(self::all(), $patch);
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
        @chmod($path, 0660);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }
}
