<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Osm\OsmApi;
use App\Osm\OsmDebug;
use App\Osm\OsmLists;
use App\Store\SettingsStore;
use Throwable;
final class EquipmentController
{
    /** @param array<string, mixed> $res @return list<array<string, mixed>> */
    private static function quartermasterLists(array $res): array
    {
        // Node: listsResponse.data.data  (axios body → .data)
        $candidates = [];
        if (isset($res['data'])) $candidates[] = $res['data'];
        if (isset($res['data']['items'])) $candidates[] = $res['data']['items'];
        if (isset($res['items'])) $candidates[] = $res['items'];
        $candidates[] = OsmLists::items($res);
        foreach ($candidates as $c) {
            if (!is_array($c) || $c === []) continue;
            if (!array_is_list($c)) {
                $vals = array_values(array_filter($c, 'is_array'));
                // skip if looks like a single wrapper object without list rows
                if ($vals === []) continue;
                if (!isset($vals[0]['listid']) && !isset($vals[0]['list_id']) && !isset($vals[0]['id']) && !isset($vals[0]['name'])) {
                    continue;
                }
                $c = $vals;
            }
            $out = [];
            foreach ($c as $row) {
                if (is_array($row)) $out[] = $row;
            }
            if ($out !== []) return $out;
        }
        return [];
    }

    /** @param array<string, mixed> $res @return list<array<string, mixed>> */
    private static function quartermasterItems(array $res): array
    {
        // Node: itemsResponse.data.data.items
        if (isset($res['data']['items']) && is_array($res['data']['items'])) {
            $items = $res['data']['items'];
            if (!array_is_list($items)) $items = array_values(array_filter($items, 'is_array'));
            return array_values(array_filter($items, 'is_array'));
        }
        if (isset($res['items']) && is_array($res['items'])) {
            $items = $res['items'];
            if (!array_is_list($items)) $items = array_values(array_filter($items, 'is_array'));
            return array_values(array_filter($items, 'is_array'));
        }
        // sometimes data is already the items list
        if (isset($res['data']) && is_array($res['data']) && array_is_list($res['data'])) {
            $first = $res['data'][0] ?? null;
            if (is_array($first) && (isset($first['_1']) || isset($first['rowid']))) {
                return array_values(array_filter($res['data'], 'is_array'));
            }
        }
        return OsmLists::items($res);
    }

    public function index(): void
    {
        $token = Auth::requireLogin();
        $api = new OsmApi();
        $savedId = (string) SettingsStore::get('equipmentSectionId', '');
        $savedType = (string) SettingsStore::get('equipmentSectionType', 'adults');

        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable) {
            App::render('error.twig', Auth::baseContext([
                'title' => 'Equipment',
                'message' => 'Could not load sections from OSM.',
            ]));
            return;
        }

        $section = null;
        if ($savedId !== '') {
            foreach ($sections as $s) {
                if (is_array($s) && (string) ($s['section_id'] ?? '') === $savedId) {
                    $section = $s;
                    break;
                }
            }
            if ($section === null) {
                $section = [
                    'section_id' => $savedId,
                    'section_type' => $savedType !== '' ? $savedType : 'adults',
                    'section_name' => 'Configured section',
                ];
            }
        } else {
            foreach ($sections as $s) {
                if (is_array($s) && ($s['section_type'] ?? '') === 'adults' && !empty($s['section_id'])) {
                    $section = $s;
                    break;
                }
            }
            if ($section === null && $sections !== [] && is_array($sections[0])) {
                $section = $sections[0];
            }
        }

        if ($section === null) {
            App::render('error.twig', Auth::baseContext([
                'title' => 'Equipment',
                'message' => 'No equipment section configured. Pick one under Settings → Tool sections.',
            ]));
            return;
        }

        $sectionId = (string) $section['section_id'];
        $sectionType = (string) ($section['section_type'] ?? '');
        if ($sectionType === '') $sectionType = $savedType !== '' ? $savedType : 'adults';
        $sectionName = (string) ($section['section_name'] ?? $sectionId);
        $equipment = [];
        $listCount = 0;
        try {
            $listsRes = $api->get($token, '/ext/quartermaster/', [
                'action' => 'getListOfLists',
                'section' => $sectionType,
                'sectionid' => $sectionId,
            ]);
            OsmDebug::log('equipment_lists', [
                'sectionId' => $sectionId,
                'sectionType' => $sectionType,
                'top_keys' => array_keys($listsRes),
                'body' => $listsRes,
            ]);
            $lists = self::quartermasterLists($listsRes);
            $listCount = count($lists);
            foreach ($lists as $equipList) {
                $listId = $equipList['listid'] ?? $equipList['list_id'] ?? $equipList['id'] ?? null;
                if ($listId === null || $listId === '') continue;
                $listName = (string) ($equipList['name'] ?? ('List ' . $listId));
                $itemsRes = $api->get($token, '/ext/quartermaster/', [
                    'action' => 'getItemsInList',
                    'section' => $sectionType,
                    'sectionid' => $sectionId,
                    'listid' => $listId,
                ]);
                if ($listCount <= 3) {
                    OsmDebug::log('equipment_items_' . $listId, [
                        'listId' => $listId,
                        'top_keys' => array_keys($itemsRes),
                        'body' => $itemsRes,
                    ]);
                }
                foreach (self::quartermasterItems($itemsRes) as $item) {
                    if (!is_array($item)) continue;
                    $equipment[] = [
                        'listName' => $listName,
                        'itemName' => $item['_1'] ?? '',
                        'description' => $item['_2'] ?? '',
                        'location' => $item['_3'] ?? '',
                        'notes' => $item['_4'] ?? '',
                        'condition' => $item['_5'] ?? '',
                        'quantity' => $item['_6'] ?? '',
                        'broken' => $item['_7'] ?? '',
                    ];
                }
            }
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            OsmDebug::log('equipment_error', ['message' => $e->getMessage(), 'code' => $code, 'sectionId' => $sectionId, 'sectionType' => $sectionType]);
            $hint = $code > 0 ? " (OSM HTTP {$code})" : '';
            App::render('error.twig', Auth::baseContext([
                'title' => 'Equipment',
                'message' => "Could not load equipment for section {$sectionName} (id {$sectionId}, type {$sectionType}){$hint}. Check Settings → Tool sections.",
            ]));
            return;
        }

        App::render('equipment-list.twig', Auth::baseContext([
            'title' => 'Equipment',
            'equipment' => $equipment,
            'sectionName' => $sectionName,
            'sectionId' => $sectionId,
            'sectionType' => $sectionType,
            'listCount' => $listCount,
            'needsConfig' => $savedId === '',
        ]));
    }
}
