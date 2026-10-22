<?php
declare(strict_types=1);
namespace App\Store;
use PDO;
/**
 * Per-leader WordPress site keys for waiting-list intake.
 * Stores configuration and OSM tokens only — never child/parent form payloads.
 * access_token/refresh_token are encrypted at rest (TokenCrypto); rows returned by the find*
 * methods carry the decrypted values. Legacy plaintext rows are read as-is and re-saved encrypted.
 */
final class WordpressSiteKeyStore
{
    public static function ensureSchema(): void
    {
        $pdo = Db::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS wordpress_site_keys (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                osm_user_id         TEXT NOT NULL,
                site_key            TEXT NOT NULL UNIQUE,
                section_id          TEXT NOT NULL,
                section_name        TEXT,
                access_token        TEXT NOT NULL,
                refresh_token       TEXT,
                token_expires_at    INTEGER,
                blocked_at          INTEGER,
                blocked_header      TEXT,
                created_at          TEXT NOT NULL,
                updated_at          TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS wordpress_site_keys_user
             ON wordpress_site_keys (osm_user_id)'
        );
    }

    /** @return array<string, mixed>|null */
    public static function findByOsmUserId(string $osmUserId): ?array
    {
        self::ensureSchema();
        $stmt = Db::pdo()->prepare('SELECT * FROM wordpress_site_keys WHERE osm_user_id = ? LIMIT 1');
        $stmt->execute([$osmUserId]);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string, mixed>|null */
    public static function findBySiteKey(string $siteKey): ?array
    {
        self::ensureSchema();
        if ($siteKey === '') {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM wordpress_site_keys WHERE site_key = ? LIMIT 1');
        $stmt->execute([$siteKey]);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Create or update the single site-key row for this OSM user.
     * Regenerates the site key when $regenerateKey is true or none exists yet.
     *
     * @return array{row: array<string, mixed>, siteKey: string, regenerated: bool}
     */
    public static function upsertForUser(
        string $osmUserId,
        string $sectionId,
        string $sectionName,
        string $accessToken,
        ?string $refreshToken,
        ?int $tokenExpiresAt,
        bool $regenerateKey = false
    ): array {
        self::ensureSchema();
        $existing = self::findByOsmUserId($osmUserId);
        $now = gmdate('c');
        $regenerated = false;
        if ($existing === null || $regenerateKey || (string) ($existing['site_key'] ?? '') === '') {
            $siteKey = self::generateSiteKey();
            $regenerated = true;
        } else {
            $siteKey = (string) $existing['site_key'];
        }

        if ($existing === null) {
            $stmt = Db::pdo()->prepare(
                'INSERT INTO wordpress_site_keys (
                    osm_user_id, site_key, section_id, section_name,
                    access_token, refresh_token, token_expires_at,
                    blocked_at, blocked_header, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)'
            );
            $stmt->execute([
                $osmUserId,
                $siteKey,
                $sectionId,
                $sectionName !== '' ? $sectionName : null,
                TokenCrypto::encrypt($accessToken),
                TokenCrypto::encrypt($refreshToken),
                $tokenExpiresAt,
                $now,
                $now,
            ]);
        } else {
            $stmt = Db::pdo()->prepare(
                'UPDATE wordpress_site_keys SET
                    site_key = ?,
                    section_id = ?,
                    section_name = ?,
                    access_token = ?,
                    refresh_token = ?,
                    token_expires_at = ?,
                    updated_at = ?
                 WHERE osm_user_id = ?'
            );
            $stmt->execute([
                $siteKey,
                $sectionId,
                $sectionName !== '' ? $sectionName : null,
                TokenCrypto::encrypt($accessToken),
                TokenCrypto::encrypt($refreshToken),
                $tokenExpiresAt,
                $now,
                $osmUserId,
            ]);
        }

        $row = self::findByOsmUserId($osmUserId);
        if ($row === null) {
            throw new \RuntimeException('Failed to save WordPress site key.');
        }
        return ['row' => $row, 'siteKey' => $siteKey, 'regenerated' => $regenerated];
    }

    /** Refresh OSM tokens on an existing row (no key/section change). */
    public static function updateTokens(int $id, string $accessToken, ?string $refreshToken, ?int $tokenExpiresAt): void
    {
        self::ensureSchema();
        $stmt = Db::pdo()->prepare(
            'UPDATE wordpress_site_keys SET
                access_token = ?,
                refresh_token = ?,
                token_expires_at = ?,
                updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            TokenCrypto::encrypt($accessToken),
            TokenCrypto::encrypt($refreshToken),
            $tokenExpiresAt,
            gmdate('c'),
            $id,
        ]);
    }

    public static function markBlocked(int $id, ?string $headerValue): void
    {
        self::ensureSchema();
        $stmt = Db::pdo()->prepare(
            'UPDATE wordpress_site_keys SET blocked_at = ?, blocked_header = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([time(), $headerValue, gmdate('c'), $id]);
    }

    public static function clearBlocked(int $id): void
    {
        self::ensureSchema();
        $stmt = Db::pdo()->prepare(
            'UPDATE wordpress_site_keys SET blocked_at = NULL, blocked_header = NULL, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([gmdate('c'), $id]);
    }

    public static function deleteForUser(string $osmUserId): void
    {
        self::ensureSchema();
        $stmt = Db::pdo()->prepare('DELETE FROM wordpress_site_keys WHERE osm_user_id = ?');
        $stmt->execute([$osmUserId]);
    }

    /**
     * Encrypt any plaintext tokens left from before encryption (one-off migration; safe to re-run).
     *
     * @return array{rows:int, encrypted:int, already:int}
     */
    public static function encryptExistingRows(): array
    {
        self::ensureSchema();
        if (!TokenCrypto::available()) {
            throw new \RuntimeException('OSMHELPER_TOKEN_KEY is missing or invalid; nothing was changed.');
        }
        $rows = Db::pdo()->query('SELECT id, access_token, refresh_token FROM wordpress_site_keys')->fetchAll();
        $out = ['rows' => 0, 'encrypted' => 0, 'already' => 0];
        foreach ($rows as $row) {
            $out['rows']++;
            if (self::needsEncrypting($row)) {
                self::reencrypt($row);
                $out['encrypted']++;
            } else {
                $out['already']++;
            }
        }
        return $out;
    }

    /**
     * Decrypt the token columns for callers; upgrade a legacy plaintext row in place when a key is set.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        if (self::needsEncrypting($row) && TokenCrypto::available()) {
            try {
                self::reencrypt($row);
            } catch (\Throwable $e) {
                error_log('OSMHelper token encryption: upgrade_failed ' . get_class($e));
            }
        }
        $access = $row['access_token'] ?? null;
        $refresh = $row['refresh_token'] ?? null;
        $row['access_token'] = (string) (TokenCrypto::decrypt(is_string($access) ? $access : null) ?? '');
        $row['refresh_token'] = TokenCrypto::decrypt(is_string($refresh) ? $refresh : null);
        return $row;
    }

    /** @param array<string, mixed> $row */
    private static function needsEncrypting(array $row): bool
    {
        foreach (['access_token', 'refresh_token'] as $col) {
            $v = $row[$col] ?? null;
            if (is_string($v) && $v !== '' && !TokenCrypto::isEncrypted($v)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $row */
    private static function reencrypt(array $row): void
    {
        $access = $row['access_token'] ?? null;
        $refresh = $row['refresh_token'] ?? null;
        // Only touch the token columns; updated_at is left alone (this is not a user change).
        $stmt = Db::pdo()->prepare(
            'UPDATE wordpress_site_keys SET access_token = ?, refresh_token = ? WHERE id = ? AND access_token = ?'
        );
        $stmt->execute([
            TokenCrypto::encrypt(is_string($access) ? $access : ''),
            TokenCrypto::encrypt(is_string($refresh) ? $refresh : null),
            (int) $row['id'],
            (string) $access,
        ]);
    }

    public static function generateSiteKey(): string
    {
        return 'osmwp_' . bin2hex(random_bytes(24));
    }
}
