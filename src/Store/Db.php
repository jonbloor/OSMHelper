<?php
declare(strict_types=1);
namespace App\Store;
use App\Config;
use PDO;
/**
 * Lazy SQLite connection at storage/osmhelper.sqlite (outside webroot, git-ignored).
 * Holds configuration only (e.g. waiting-list field mappings) — never scout names,
 * ranks or notes. Migrations are idempotent (CREATE TABLE IF NOT EXISTS, ADD COLUMN only when missing).
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function path(): string
    {
        $override = Config::get('OSMHELPER_DB_PATH');
        if ($override !== null && $override !== '') {
            return $override;
        }
        return dirname(__DIR__, 2) . '/storage/osmhelper.sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $isNew = !is_file($path);
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 3000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        self::migrate($pdo);
        if ($isNew) {
            @chmod($path, 0660);
        }
        self::$pdo = $pdo;
        return $pdo;
    }

    /** Test helper: drop the cached connection (e.g. after changing OSMHELPER_DB_PATH). */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS waiting_field_maps (
                group_id        TEXT NOT NULL,
                section_id      TEXT NOT NULL,
                section_name    TEXT,
                rank_column_id  TEXT NOT NULL,
                rank_varname    TEXT,
                rank_label      TEXT,
                notes_column_id TEXT NOT NULL,
                notes_varname   TEXT,
                notes_label     TEXT,
                field_group_id  TEXT NOT NULL DEFAULT \'5\',
                updated_by      TEXT,
                updated_at      TEXT NOT NULL,
                PRIMARY KEY (group_id, section_id)
            )'
        );
        // Added later: optional "willing to help" field. SQLite has no ADD COLUMN IF NOT EXISTS,
        // so check the table first; existing rows get NULL (= not mapped).
        $have = [];
        foreach ($pdo->query('PRAGMA table_info(waiting_field_maps)')->fetchAll() as $c) {
            $have[(string) $c['name']] = true;
        }
        foreach (['willing_column_id', 'willing_varname', 'willing_label'] as $col) {
            if (!isset($have[$col])) {
                $pdo->exec('ALTER TABLE waiting_field_maps ADD COLUMN ' . $col . ' TEXT');
            }
        }
    }
}
