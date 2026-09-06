<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public const OSM_API_BASE = 'https://www.onlinescoutmanager.co.uk';

    public const OAUTH_SCOPES = 'section:member:read section:quartermaster:write section:finance:read';

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
}
