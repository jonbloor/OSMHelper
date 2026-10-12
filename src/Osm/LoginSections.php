<?php

declare(strict_types=1);

namespace App\Osm;

use App\Config;

/**
 * Sections OSM returned for this login (/oauth/resource), kept on the session
 * so a tool can offer them before it calls OSM again.
 */
final class LoginSections
{
    public const SESSION_KEY = 'osmSections';

    /** @var array<string, int> */
    private const TYPE_ORDER = [
        'earlyyears' => 0,
        'beavers' => 1,
        'cubs' => 2,
        'scouts' => 3,
        'explorers' => 4,
        'network' => 5,
        'adults' => 6,
    ];

    /**
     * @param list<array<string, mixed>> $sections normalised resource sections
     * @return list<array{section_id:string,section_name:string,section_type:string,group_name:string,current_term_id:mixed}>
     */
    public static function remember(array $sections): array
    {
        $slim = self::slim($sections);
        $_SESSION[self::SESSION_KEY] = $slim;
        $_SESSION['_sections_count'] = count($slim);
        return $slim;
    }

    /**
     * null when this session has not stored sections yet (signed in before they were kept).
     *
     * @return list<array{section_id:string,section_name:string,section_type:string,group_name:string,current_term_id:mixed}>|null
     */
    public static function fromSession(): ?array
    {
        if (!array_key_exists(self::SESSION_KEY, $_SESSION) || !is_array($_SESSION[self::SESSION_KEY])) {
            return null;
        }
        return self::slim($_SESSION[self::SESSION_KEY]);
    }

    /**
     * The login list, or one /oauth/resource read when an older session does not have it yet.
     *
     * @return list<array{section_id:string,section_name:string,section_type:string,group_name:string,current_term_id:mixed}>
     */
    public static function ensure(string $token): array
    {
        $existing = self::fromSession();
        if ($existing !== null) {
            return $existing;
        }
        return self::remember((new OsmApi())->getDynamicSections($token));
    }

    /**
     * Sections the member list can query. Waiting lists and unknown types are skipped,
     * matching the previous "load every section" behaviour.
     *
     * @param list<array<string, mixed>> $sections
     * @return list<array{id:string,name:string,type:string,typeLabel:string,groupName:string,termId:mixed}>
     */
    public static function memberListChoices(array $sections): array
    {
        $out = [];
        foreach ($sections as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $type = strtolower((string) ($sec['section_type'] ?? 'unknown'));
            if ($type === '' || $type === 'waiting' || $type === 'unknown') {
                continue;
            }
            $id = (string) ($sec['section_id'] ?? $sec['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $name = trim((string) ($sec['section_name'] ?? $sec['name'] ?? ''));
            if ($name === '') {
                $name = $id;
            }
            $out[] = [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'typeLabel' => Config::FRIENDLY_SECTION_TYPES[$type] ?? $type,
                'groupName' => trim((string) ($sec['group_name'] ?? '')),
                'termId' => $sec['current_term_id'] ?? -1,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            $group = strnatcasecmp($a['groupName'], $b['groupName']);
            if ($group !== 0) {
                return $group;
            }
            $typeOrder = (self::TYPE_ORDER[$a['type']] ?? 50) <=> (self::TYPE_ORDER[$b['type']] ?? 50);
            if ($typeOrder !== 0) {
                return $typeOrder;
            }
            return strnatcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * @param list<array{id:string,name:string,type:string,typeLabel:string,groupName:string,termId:mixed}> $choices
     * @return list<array{name:string,sections:list<array{id:string,name:string,type:string,typeLabel:string,groupName:string,termId:mixed}>}>
     */
    public static function groupChoices(array $choices): array
    {
        $groups = [];
        foreach ($choices as $choice) {
            $name = $choice['groupName'] !== '' ? $choice['groupName'] : 'Sections';
            if (!isset($groups[$name])) {
                $groups[$name] = ['name' => $name, 'sections' => []];
            }
            $groups[$name]['sections'][] = $choice;
        }
        return array_values($groups);
    }

    /**
     * Slim rows for the member fetch. Ids that were not on the login list are ignored.
     *
     * @param list<array{id:string,name:string,type:string,typeLabel:string,groupName:string,termId:mixed}> $choices
     * @param list<mixed> $ids
     * @return list<array{section_id:string,section_name:string,section_type:string,group_name:string,current_term_id:mixed}>
     */
    public static function pickedSections(array $choices, array $ids): array
    {
        $want = [];
        foreach ($ids as $id) {
            if (!is_scalar($id)) {
                continue;
            }
            $id = trim((string) $id);
            if ($id !== '') {
                $want[$id] = true;
            }
        }
        $out = [];
        foreach ($choices as $choice) {
            if (!isset($want[$choice['id']])) {
                continue;
            }
            $out[] = [
                'section_id' => $choice['id'],
                'section_name' => $choice['name'],
                'section_type' => $choice['type'],
                'group_name' => $choice['groupName'],
                'current_term_id' => $choice['termId'],
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return list<array{section_id:string,section_name:string,section_type:string,group_name:string,current_term_id:mixed}>
     */
    private static function slim(array $sections): array
    {
        $out = [];
        $seen = [];
        foreach ($sections as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $id = (string) ($sec['section_id'] ?? $sec['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'section_id' => $id,
                'section_name' => (string) ($sec['section_name'] ?? $sec['name'] ?? ''),
                'section_type' => (string) ($sec['section_type'] ?? 'unknown'),
                'group_name' => (string) ($sec['group_name'] ?? ''),
                'current_term_id' => $sec['current_term_id'] ?? -1,
            ];
        }
        return $out;
    }
}
