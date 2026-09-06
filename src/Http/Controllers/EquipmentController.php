<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Osm\OsmApi;
use App\Store\SettingsStore;
use Throwable;
final class EquipmentController
{
    public function index(): void
    {
        $token = Auth::requireLogin();
        $api = new OsmApi();
        $savedId = (string) SettingsStore::get('equipmentSectionId', '');
        $savedType = (string) SettingsStore::get('equipmentSectionType', 'adults');

        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable) {
            App::render('error.twig', Auth::baseContext(['title' => 'Equipment', 'message' => 'Could not load sections.']));
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
                $section = ['section_id' => $savedId, 'section_type' => $savedType, 'section_name' => 'Configured section'];
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
                'message' => 'No equipment section configured. Pick one under Settings.',
            ]));
            return;
        }

        $sectionId = $section['section_id'];
        $sectionType = $section['section_type'] ?? $savedType ?: 'adults';
        $equipment = [];
        try {
            $listsRes = $api->get($token, '/ext/quartermaster/', [
                'action' => 'getListOfLists',
                'section' => $sectionType,
                'sectionid' => $sectionId,
            ]);
            $lists = $listsRes['data'] ?? [];
            if (!is_array($lists)) $lists = [];
            if (!array_is_list($lists) && $lists !== []) {
                $lists = array_values(array_filter($lists, 'is_array'));
            }
            foreach ($lists as $equipList) {
                if (!is_array($equipList) || empty($equipList['listid'])) continue;
                $listId = $equipList['listid'];
                $listName = $equipList['name'] ?? ('List ' . $listId);
                $itemsRes = $api->get($token, '/ext/quartermaster/', [
                    'action' => 'getItemsInList',
                    'section' => $sectionType,
                    'sectionid' => $sectionId,
                    'listid' => $listId,
                ]);
                $items = $itemsRes['data']['items'] ?? $itemsRes['items'] ?? [];
                if (!is_array($items)) $items = [];
                if (!array_is_list($items)) {
                    $items = array_values(array_filter($items, 'is_array'));
                }
                foreach ($items as $item) {
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
        } catch (Throwable) {
            App::render('error.twig', Auth::baseContext(['title' => 'Equipment', 'message' => 'Could not load equipment lists for the configured section.']));
            return;
        }

        App::render('equipment-list.twig', Auth::baseContext([
            'title' => 'Equipment',
            'equipment' => $equipment,
            'sectionName' => (string) ($section['section_name'] ?? ''),
            'needsConfig' => $savedId === '',
        ]));
    }
}
