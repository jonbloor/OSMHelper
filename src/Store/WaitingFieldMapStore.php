<?php
declare(strict_types=1);
namespace App\Store;
/**
 * Waiting-list rank/notes (and optional willing-to-help) custom-field mapping, shared per OSM group + waiting-list section.
 * Stores OSM column ids / varnames / labels only (no member data).
 */
final class WaitingFieldMapStore
{
    /** @return array<string, string>|null */
    public static function get(string $groupId, string $sectionId): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM waiting_field_maps WHERE group_id = ? AND section_id = ?');
        $st->execute([$groupId, $sectionId]);
        $row = $st->fetch();
        if (!is_array($row)) {
            return null;
        }
        return array_map(static fn ($v) => $v === null ? '' : (string) $v, $row);
    }

    /**
     * @param array{section_name?:string,rank_column_id:string,rank_varname?:string,rank_label?:string,
     *   notes_column_id:string,notes_varname?:string,notes_label?:string,field_group_id?:string,
     *   willing_column_id?:string,willing_varname?:string,willing_label?:string} $map  (willing_* optional; '' = not mapped)
     */
    public static function save(string $groupId, string $sectionId, array $map, string $updatedBy): void
    {
        $sql = 'INSERT INTO waiting_field_maps
            (group_id, section_id, section_name, rank_column_id, rank_varname, rank_label,
             notes_column_id, notes_varname, notes_label, willing_column_id, willing_varname, willing_label,
             field_group_id, updated_by, updated_at)
            VALUES (:g, :s, :sn, :rc, :rv, :rl, :nc, :nv, :nl, :wc, :wv, :wl, :fg, :ub, :ua)
            ON CONFLICT(group_id, section_id) DO UPDATE SET
              section_name = excluded.section_name,
              rank_column_id = excluded.rank_column_id, rank_varname = excluded.rank_varname, rank_label = excluded.rank_label,
              notes_column_id = excluded.notes_column_id, notes_varname = excluded.notes_varname, notes_label = excluded.notes_label,
              willing_column_id = excluded.willing_column_id, willing_varname = excluded.willing_varname, willing_label = excluded.willing_label,
              field_group_id = excluded.field_group_id, updated_by = excluded.updated_by, updated_at = excluded.updated_at';
        $st = Db::pdo()->prepare($sql);
        $st->execute([
            ':g' => $groupId,
            ':s' => $sectionId,
            ':sn' => (string) ($map['section_name'] ?? ''),
            ':rc' => (string) $map['rank_column_id'],
            ':rv' => (string) ($map['rank_varname'] ?? ''),
            ':rl' => (string) ($map['rank_label'] ?? ''),
            ':nc' => (string) $map['notes_column_id'],
            ':nv' => (string) ($map['notes_varname'] ?? ''),
            ':nl' => (string) ($map['notes_label'] ?? ''),
            ':wc' => (string) ($map['willing_column_id'] ?? ''),
            ':wv' => (string) ($map['willing_varname'] ?? ''),
            ':wl' => (string) ($map['willing_label'] ?? ''),
            ':fg' => (string) (($map['field_group_id'] ?? '') !== '' ? $map['field_group_id'] : '5'),
            ':ub' => $updatedBy,
            ':ua' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }
}
