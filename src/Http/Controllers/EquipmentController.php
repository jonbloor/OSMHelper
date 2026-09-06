<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Osm\OsmApi;
use Throwable;
final class EquipmentController
{
    public function index(): void
    {
        $token = Auth::requireLogin();
        $api = new OsmApi();
        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable $e) {
            App::render('error.twig', Auth::baseContext(['title' => 'Equipment', 'message' => 'Could not load sections.']));
            return;
        }
        $section = null;
        foreach ($sections as $s) {
            if (is_array($s) && ($s['section_type'] ?? '') === 'adults' && !empty($s['section_id'])) {
                $section = $s;
                break;
            }
        }
        if ($section === null && $sections !== [] && is_array($sections[0])) {
            $section = $sections[0];
        }
        if ($section === null) {
            App::render('error.twig', Auth::baseContext(['title' => 'Equipment', 'message' => 'No suitable section found for equipment.']));
            return;
        }
        $sectionId = $section['section_id'];
        $sectionType = $section['section_type'] ?? 'adults';
        $equipment = [];
        try {
            $listsRes = $api->get($token, '/ext/quartermaster/', [
                'action' => 'getListOfLists',
                'section' => $sectionType,
                'sectionid' => $sectionId,
            ]);
            $lists = $listsRes['data'] ?? [];
            if (!is_array($lists)) $lists = [];
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
                $items = $itemsRes['data']['items'] ?? [];
                if (!is_array($items)) $items = [];
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
        } catch (Throwable $e) {
            App::render('error.twig', Auth::baseContext(['title' => 'Equipment', 'message' => 'Could not load equipment lists.']));
            return;
        }
        App::render('equipment-list.twig', Auth::baseContext([
            'title' => 'Equipment',
            'equipment' => $equipment,
        ]));
    }
}
