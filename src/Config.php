<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public const OSM_API_BASE = 'https://www.onlinescoutmanager.co.uk';

    /**
     * Space-separated OSM OAuth scopes (deduped).
     * Badge: request section:badge:write only — OSM's consent UI lists "Badges" once per
     * badge:* scope, so read+write showed twice. Same pattern as quartermaster:write
     * (no separate :read); write covers Top awards reads, Nights away records, and progress writes.
     * Re-login after changing scopes.
     * Member: section:member:write (replaces :read) so Waiting list rank/notes can be saved
     * to OSM custom fields; sessions from before this change stay read-only until re-login.
     */
    public const OAUTH_SCOPES = 'section:member:write section:quartermaster:write section:finance:read section:badge:write';

    /** @var list<string> */
    public const SECTION_TYPE_ORDER = [
        'Waiting List', 'Squirrels', 'Beavers', 'Cubs', 'Scouts', 'Explorers', 'Adults / Leaders',
    ];

    /** @var array<string, string> */
    public const FRIENDLY_SECTION_TYPES = [
        'earlyyears' => 'Squirrels',
        'beavers' => 'Beavers',
        'cubs' => 'Cubs',
        'scouts' => 'Scouts',
        'explorers' => 'Explorers',
        'adults' => 'Adults/Leaders',
        'waiting' => 'Waiting List',
        'unknown' => 'Other',
    ];

    /** @var array<string, int> */
    public const DEFAULT_CAPACITIES = [
        'earlyyears' => 18,
        'beavers' => 24,
        'cubs' => 30,
        'scouts' => 36,
    ];

    /** @var array<string, float> */
    public const DEFAULT_CUTOFFS = [
        'squirrels' => 4.0,
        'beavers' => 5.75,
        'cubs' => 7.5,
        'scouts' => 10.0,
        'explorers' => 13.5,
    ];

    /** @var list<string> */
    public const REQUIRED_ENV = [
        'SESSION_SECRET',
        'CLIENT_ID',
        'CLIENT_SECRET',
        'REDIRECT_URI',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public static function osmApiBase(): string
    {
        return self::get('OSM_API_BASE', self::OSM_API_BASE) ?? self::OSM_API_BASE;
    }

    /**
     * @return list<string>
     */
    public static function missingRequired(): array
    {
        $missing = [];
        foreach (self::REQUIRED_ENV as $key) {
            $v = self::get($key);
            if ($v === null || trim($v) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public static function isConfigured(): bool
    {
        return self::missingRequired() === [];
    }

    public static function debug(): bool
    {
        return filter_var(self::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Debug files that can contain OSM data (storage/osm-debug.json, storage/top-awards-dryrun.json).
     * Separate explicit flag, off by default; APP_DEBUG alone does not turn them on.
     */
    /**
     * OSM user IDs allowed to see the OSM API error log for every group on the signed-in home page.
     * Comma-separated ADMIN_OSM_USER_IDS; unset/empty means nobody sees other groups' errors.
     *
     * @return list<string>
     */
    public static function adminOsmUserIds(): array
    {
        $raw = (string) (self::get('ADMIN_OSM_USER_IDS', '') ?? '');
        $ids = array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== '');
        return array_values(array_unique($ids));
    }

    public static function isAdmin(?string $osmUserId): bool
    {
        $id = trim((string) $osmUserId);
        return $id !== '' && in_array($id, self::adminOsmUserIds(), true);
    }

    public static function debugFiles(): bool
    {
        return filter_var(self::get('OSM_DEBUG_FILES', 'false'), FILTER_VALIDATE_BOOLEAN);
    }
}
