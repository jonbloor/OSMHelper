<?php
declare(strict_types=1);
namespace App\Osm;
final class OsmLists
{
    /**
     * OSM sometimes returns items as a list, sometimes as an id-keyed object,
     * sometimes nested under data / data.items.
     * @param array<string, mixed> $res
     * @return list<array<string, mixed>>
     */
    public static function items(array $res): array
    {
        $candidates = [
            $res['items'] ?? null,
            $res['data']['items'] ?? null,
            $res['data'] ?? null,
            $res['members'] ?? null,
            $res,
        ];
        foreach ($candidates as $c) {
            if (!is_array($c) || $c === []) {
                continue;
            }
            // associative object of rows?
            $isList = array_is_list($c);
            if (!$isList) {
                $vals = array_values($c);
                if ($vals !== [] && is_array($vals[0])) {
                    return array_values(array_filter($vals, 'is_array'));
                }
                continue;
            }
            if (isset($c[0]) && is_array($c[0])) {
                return $c;
            }
        }
        return [];
    }
}
