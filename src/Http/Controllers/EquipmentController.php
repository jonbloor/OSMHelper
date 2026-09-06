<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Osm\OsmApi;
use App\Osm\OsmLists;
use App\Store\SettingsStore;
use Throwable;
final class EquipmentController
{
    /** @param array<string, mixed> $res @return list<array<string, mixed>> */
    private static function quartermasterLists(array $res): array
    {
        // Node: listsResponse.data.data (array of lists)
        $data = $res['data'] ?? null;
        if (is_array($data)) {
            if (array_is_list($data)) {
                return array_values(array_filter($data, 'is_array'));
            }
            if (isset($data['items']) && is_array($data['items'])) {
                $items = $data['items'];
                if (!array_is_list($items)) {
                    $items = array_values(array_filter($items, 'is_array'));
                }
                return array_values(array_filter($items, 'is_array'));
            }
            $vals = array_values(array_filter($data, 'is_array'));
            if ($vals !== [] && isset($vals[0]['listid'])) {
                return $vals;
            }
        }
        return OsmLists::items($res);
    }

    /** @param array<string, mixed> $res @return list<array<string, mixed>> */
    private static function quartermasterItems(array $res): array
    {
        // Node: itemsResponse.data.data.items
        if (isset($res['data']['items']) && is_array($res['data']['items'])) {
            $items = $res['data']['items'];
            if (!array_is_list($items)) {
                $items = array_values(array_filter($items, 'is_array'));
            }
            return array_values(array_filter($items, 'is_array'));
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
        } catch (Throwable $e) {
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
        if ($sectionType === '') {
            $sectionType = $savedType !== '' ? $savedType : 'adults';
        }
        $sectionName = (string) ($section['section_name'] ?? $sectionId);
        $equipment = [];
        $listCount = 0;
        try {
            $listsRes = $api->get($token, '/ext/quartermaster/', [
                'action' => 'getListOfLists',
                'section' => $sectionType,
                'sectionid' => $sectionId,
            ]);
            $lists = self::quartermasterLists($listsRes);
            $listCount = count($lists);
            foreach ($lists as $equipList) {
                if (!is_array($equipList)) continue;
                $listId = $equipList['listid'] ?? $equipList['list_id'] ?? null;
                if ($listId === null || $listId === '') continue;
                $listName = (string) ($equipList['name'] ?? ('List ' . $listId));
                $itemsRes = $api->get($token, '/ext/quartermaster/', [
                    'action' => 'getItemsInList',
                    'section' => $sectionType,
                    'sectionid' => $sectionId,
                    'listid' => $listId,
                ]);
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
            $hint = $code > 0 ? " (OSM HTTP {$code})" : '';
            App::render('error.twig', Auth::baseContext([
                'title' => 'Equipment',
                'message' => "Could not load equipment for section {$sectionName} (id {$sectionId}, type {$sectionType}){$hint}. Check Settings → Tool sections, or try another section.",
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
