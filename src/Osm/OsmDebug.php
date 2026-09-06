<?php
declare(strict_types=1);
namespace App\Osm;

use App\Config;

/**
 * Write truncated OSM response shapes to storage (outside webroot).
 * Gated by APP_DEBUG; redacts likely PII / money fields; keeps file small.
 */
final class OsmDebug
{
    private const MAX_ENTRIES = 40;

    /** @var list<string> */
    private const REDACT_KEYS = [
        'name', 'fullname', 'lastname', 'full_name', 'fullname', 'itemName', 'itemname',
        'description', 'notes', 'reference', 'email', 'address', 'phone', 'dob',
        'amount', 'current_balance', 'opening_balance', 'value', 'data',
        'scoutid', 'member_id', 'access_token', 'refresh_token', 'token',
    ];

    public static function log(string $label, array $payload): void
    {
        if (!Config::debug()) {
            return;
        }
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $path = $dir . '/osm-debug.json';
        $existing = [];
        if (is_readable($path)) {
            $raw = json_decode((string) file_get_contents($path), true);
            if (is_array($raw)) {
                $existing = $raw;
            }
        }
        $existing[$label] = [
            'at' => gmdate('c'),
            'payload' => self::redact(self::summarise($payload)),
        ];
        // Rotate: keep newest MAX_ENTRIES by rewriting as ordered list of last keys
        if (count($existing) > self::MAX_ENTRIES) {
            $existing = array_slice($existing, -self::MAX_ENTRIES, null, true);
        }
        $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $ok = @file_put_contents($path, $json, LOCK_EX);
        if ($ok === false) {
            @error_log('OsmDebug write failed: ' . $path);
        } else {
            @chmod($path, 0660);
            @chgrp('osmhelper', $path);
        }
    }

    /** @param mixed $v */
    private static function summarise(mixed $v, int $depth = 0): mixed
    {
        if ($depth > 4) {
            return '...';
        }
        if (is_array($v)) {
            if ($v === []) {
                return [];
            }
            if (array_is_list($v)) {
                $out = ['__list_len' => count($v)];
                if (isset($v[0])) {
                    $out['__first'] = self::summarise($v[0], $depth + 1);
                }
                if (count($v) > 1) {
                    $out['__second_keys'] = is_array($v[1]) ? array_slice(array_keys($v[1]), 0, 20) : gettype($v[1]);
                }
                return $out;
            }
            $out = [];
            $i = 0;
            foreach ($v as $k => $val) {
                if ($i++ > 40) {
                    $out['__more'] = true;
                    break;
                }
                $out[(string) $k] = self::summarise($val, $depth + 1);
            }
            return $out;
        }
        if (is_string($v)) {
            return strlen($v) > 80 ? substr($v, 0, 80) . '…' : $v;
        }
        if (is_bool($v) || is_int($v) || is_float($v) || $v === null) {
            return $v;
        }
        return gettype($v);
    }

    /** @param mixed $v @return mixed */
    private static function redact(mixed $v, string $key = ''): mixed
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $val) {
                $ks = strtolower((string) $k);
                $sensitive = false;
                foreach (self::REDACT_KEYS as $rk) {
                    if ($ks === $rk || str_contains($ks, $rk)) {
                        $sensitive = true;
                        break;
                    }
                }
                if ($sensitive) {
                    $out[(string) $k] = '[redacted]';
                } else {
                    $out[(string) $k] = self::redact($val, (string) $k);
                }
            }
            return $out;
        }
        return $v;
    }
}
