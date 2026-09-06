<?php
declare(strict_types=1);
namespace App\Osm;
/** Write truncated OSM response shapes to storage (no tokens). */
final class OsmDebug
{
    public static function log(string $label, array $payload): void
    {
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $path = $dir . '/osm-debug.json';
        $existing = [];
        if (is_readable($path)) {
            $raw = json_decode((string) file_get_contents($path), true);
            if (is_array($raw)) $existing = $raw;
        }
        $existing[$label] = [
            'at' => gmdate('c'),
            'payload' => self::summarise($payload),
        ];
        @file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
        @chmod($path, 0660);
    }

    /** @param mixed $v */
    private static function summarise(mixed $v, int $depth = 0): mixed
    {
        if ($depth > 4) return '...';
        if (is_array($v)) {
            if ($v === []) return [];
            if (array_is_list($v)) {
                $out = ['__list_len' => count($v)];
                if (isset($v[0])) $out['__first'] = self::summarise($v[0], $depth + 1);
                if (count($v) > 1) $out['__second_keys'] = is_array($v[1]) ? array_slice(array_keys($v[1]), 0, 20) : gettype($v[1]);
                return $out;
            }
            $out = [];
            $i = 0;
            foreach ($v as $k => $val) {
                if ($i++ > 40) { $out['__more'] = true; break; }
                $out[(string) $k] = self::summarise($val, $depth + 1);
            }
            return $out;
        }
        if (is_string($v)) return strlen($v) > 120 ? substr($v, 0, 120) . '…' : $v;
        if (is_bool($v) || is_int($v) || is_float($v) || $v === null) return $v;
        return gettype($v);
    }
}
