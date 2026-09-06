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
    /**
     * Node: listsResponse.data.data  (axios body → .data is the lists array)
     * @param array<string, mixed> $res
     * @return list<array<string, mixed>>
     */
    private static function quartermasterLists(array $res): array
    {
        $candidates = [
            $res['data'] ?? null,                 // Node primary
            $res['data']['data'] ?? null,         // double-wrapped
            $res['data']['items'] ?? null,
            $res['items'] ?? null,
            $res['lists'] ?? null,
            $res['data']['lists'] ?? null,
        ];
        foreach ($candidates as $c) {
            $rows = self::asRowList($c, ['listid', 'list_id', 'id', 'name']);
            if ($rows !== []) {
                return $rows;
            }
        }
        return OsmLists::items($res);
    }

    /**
     * Jon Chromium capture: { status, data: { list, rows, columns } }
     * rows keyed by id; column keys are "1","2",… (Name, Description, … Quantity="6").
     * Also accept legacy Node _1/_6 and data.items shapes.
     * @param array<string, mixed> $res
     * @return list<array<string, mixed>>
     */
    private static function quartermasterItems(array $res): array
    {
        $data = is_array($res['data'] ?? null) ? $res['data'] : null;
        if (is_array($data) && isset($data['rows']) && is_array($data['rows'])) {
            $mapped = [];
            foreach ($data['rows'] as $rowId => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $mapped[] = self::normaliseItemRow($row, (string) $rowId);
            }
            if ($mapped !== []) {
                return $mapped;
            }
        }
        $candidates = [
            $data['items'] ?? null,
            $res['data']['data']['items'] ?? null,
            $res['items'] ?? null,
            $data,
        ];
        foreach ($candidates as $c) {
            $rows = self::asRowList($c, ['_1', '1', 'rowid', 'name', 'item']);
            if ($rows !== []) {
                $out = [];
                foreach ($rows as $i => $row) {
                    $out[] = self::normaliseItemRow($row, (string) ($row['rowid'] ?? $row['id'] ?? $i));
                }
                return $out;
            }
        }
        return OsmLists::items($res);
    }

    /**
     * Map OSM column ids ("1"…"9") and legacy _1…_9 onto stable keys.
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normaliseItemRow(array $row, string $rowId): array
    {
        $pick = static function (array $row, string $n) {
            if (array_key_exists('_' . $n, $row)) {
                return $row['_' . $n];
            }
            if (array_key_exists($n, $row)) {
                return $row[$n];
            }
            return '';
        };
        return [
            'rowid' => $row['rowid'] ?? ($row['id'] ?? $rowId),
            '_1' => $pick($row, '1') !== '' ? $pick($row, '1') : ($row['name'] ?? ''),
            '_2' => $pick($row, '2'),
            '_3' => $pick($row, '3'),
            '_4' => $pick($row, '4'),
            '_5' => $pick($row, '5'),
            '_6' => $pick($row, '6'),
            '_7' => $pick($row, '7'),
            '_8' => $pick($row, '8'),
            '_9' => $pick($row, '9'),
            'name' => $row['name'] ?? null,
        ];
    }

    /**
     * @param mixed $c
     * @param list<string> $hintKeys
     * @return list<array<string, mixed>>
     */
    private static function asRowList(mixed $c, array $hintKeys): array
    {
        if (!is_array($c) || $c === []) {
            return [];
        }
        if (!array_is_list($c)) {
            // id-keyed object of rows, or a wrapper
            if (isset($c['items']) && is_array($c['items'])) {
                return self::asRowList($c['items'], $hintKeys);
            }
            if (isset($c['data']) && is_array($c['data'])) {
                return self::asRowList($c['data'], $hintKeys);
            }
            $vals = array_values(array_filter($c, 'is_array'));
            if ($vals === []) {
                return [];
            }
            $c = $vals;
        }
        $out = [];
        foreach ($c as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hasHint = false;
            foreach ($hintKeys as $k) {
                if (array_key_exists($k, $row)) {
                    $hasHint = true;
                    break;
                }
            }
            // accept rows with any hint, or any non-empty assoc row if list looks uniform
            if ($hasHint || $row !== []) {
                $out[] = $row;
            }
        }
        // If nothing had hints, only keep if first row looks like a list/item record
        if ($out !== []) {
            $first = $out[0];
            $ok = false;
            foreach ($hintKeys as $k) {
                if (array_key_exists($k, $first)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return [];
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function typeVariants(string ...$types): array
    {
        $out = [];
        foreach ($types as $t) {
            $t = trim($t);
            if ($t === '') {
                continue;
            }
            if (!in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        foreach (['adults', 'group'] as $fallback) {
            if (!in_array($fallback, $out, true)) {
                $out[] = $fallback;
            }
        }
        return $out;
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
        $liveType = $savedType;
        $liveName = '';
        if ($savedId !== '') {
            foreach ($sections as $s) {
                if (is_array($s) && (string) ($s['section_id'] ?? '') === $savedId) {
                    $section = $s;
                    $liveType = (string) ($s['section_type'] ?? $savedType);
                    $liveName = (string) ($s['section_name'] ?? '');
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
        if ($liveType === '') {
            $liveType = $sectionType;
        }
        $sectionName = $liveName !== '' ? $liveName : (string) ($section['section_name'] ?? $sectionId);
        $equipment = [];
        $listCount = 0;
        $debugShape = null;
        $usedType = $sectionType;

        try {
            $lists = [];
            $listsRes = [];
            $lastErr = null;
            foreach (self::typeVariants($liveType, $sectionType, $savedType) as $tryType) {
                try {
                    $listsRes = $api->get($token, '/ext/quartermaster/', [
                        'action' => 'getListOfLists',
                        'section' => $tryType,
                        'sectionid' => $sectionId,
                    ]);
                    OsmDebug::log('equipment_lists_' . $sectionId . '_' . $tryType, [
                        'sectionId' => $sectionId,
                        'sectionType' => $tryType,
                        'top_keys' => array_keys($listsRes),
                        'body' => $listsRes,
                    ]);
                    $lists = self::quartermasterLists($listsRes);
                    if ($lists !== []) {
                        $usedType = $tryType;
                        break;
                    }
                    $debugShape = [
                        'top_keys' => array_keys($listsRes),
                        'data_type' => isset($listsRes['data']) ? gettype($listsRes['data']) : 'missing',
                    ];
                } catch (Throwable $e) {
                    $lastErr = $e;
                    OsmDebug::log('equipment_lists_error_' . $sectionId . '_' . $tryType, [
                        'message' => $e->getMessage(),
                        'code' => (int) $e->getCode(),
                    ]);
                }
            }

            // Fallback: Node-style first adults section if configured id yielded nothing
            if ($lists === [] && $savedId !== '') {
                foreach ($sections as $s) {
                    if (!is_array($s) || ($s['section_type'] ?? '') !== 'adults' || empty($s['section_id'])) {
                        continue;
                    }
                    $fbId = (string) $s['section_id'];
                    if ($fbId === $sectionId) {
                        continue;
                    }
                    $fbType = (string) ($s['section_type'] ?? 'adults');
                    try {
                        $listsRes = $api->get($token, '/ext/quartermaster/', [
                            'action' => 'getListOfLists',
                            'section' => $fbType,
                            'sectionid' => $fbId,
                        ]);
                        OsmDebug::log('equipment_lists_fallback_' . $fbId, [
                            'sectionId' => $fbId,
                            'sectionType' => $fbType,
                            'top_keys' => array_keys($listsRes),
                            'body' => $listsRes,
                        ]);
                        $fbLists = self::quartermasterLists($listsRes);
                        if ($fbLists !== []) {
                            $lists = $fbLists;
                            $sectionId = $fbId;
                            $usedType = $fbType;
                            $sectionName = (string) ($s['section_name'] ?? $fbId);
                            $sectionType = $fbType;
                            break;
                        }
                    } catch (Throwable) {
                        continue;
                    }
                }
            }

            if ($lists === [] && $lastErr !== null && $listsRes === []) {
                throw $lastErr;
            }

            $listCount = count($lists);
            $sectionType = $usedType;
            $itemErrors = [];
            // List metadata uses id/name; getList query uses listid (Jon Chromium capture).
            foreach ($lists as $equipList) {
                $listId = $equipList['listid'] ?? $equipList['list_id'] ?? $equipList['id'] ?? null;
                if ($listId === null || $listId === '') {
                    continue;
                }
                $listName = (string) ($equipList['name'] ?? ('List ' . $listId));
                try {
                    // NOT getItemsInList — OSM returns invalid-action / HTTP 403 for that.
                    // Working: GET .../ext/quartermaster/?action=getList&listid=&section=&sectionid=
                    $itemsRes = $api->get($token, '/ext/quartermaster/', [
                        'action' => 'getList',
                        'listid' => $listId,
                        'section' => $sectionType,
                        'sectionid' => $sectionId,
                    ]);
                    if ($listCount <= 3) {
                        OsmDebug::log('equipment_items_' . $listId, [
                            'listId' => $listId,
                            'top_keys' => array_keys($itemsRes),
                            'data_keys' => is_array($itemsRes['data'] ?? null) ? array_keys($itemsRes['data']) : null,
                            'body' => $itemsRes,
                        ]);
                    }
                    $rows = self::quartermasterItems($itemsRes);
                    if ($rows === []) {
                        // still show the list shell so user sees QM is reachable
                        $equipment[] = [
                            'listName' => $listName,
                            'itemName' => '(no items in list)',
                            'quantity' => '',
                            'description' => '',
                            'condition' => '',
                            'broken' => '',
                            'location' => '',
                            'purchaseDate' => '',
                            'notes' => '',
                            'renewalPrice' => '',
                            'brokenCount' => 0,
                        ];
                    }
                    foreach ($rows as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $brokenRaw = $item['_7'] ?? '';
                        $brokenNum = is_numeric($brokenRaw) ? (float) $brokenRaw : 0.0;
                        $equipment[] = [
                            'listName' => $listName,
                            'itemName' => $item['_1'] ?? ($item['name'] ?? ''),
                            'quantity' => $item['_6'] ?? '',
                            'description' => $item['_2'] ?? '',
                            'condition' => $item['_5'] ?? '',
                            'broken' => $brokenRaw,
                            'location' => $item['_3'] ?? '',
                            'purchaseDate' => $item['_8'] ?? '',
                            'notes' => $item['_4'] ?? '',
                            'renewalPrice' => $item['_9'] ?? '',
                            'brokenCount' => $brokenNum,
                        ];
                    }
                } catch (Throwable $ie) {
                    $itemErrors[] = $listName . ' (HTTP ' . (int) $ie->getCode() . ')';
                    OsmDebug::log('equipment_items_error_' . $listId, [
                        'listId' => $listId,
                        'message' => $ie->getMessage(),
                        'code' => (int) $ie->getCode(),
                    ]);
                    $equipment[] = [
                        'listName' => $listName,
                        'itemName' => '(could not load items)',
                        'quantity' => '',
                        'description' => $ie->getMessage(),
                        'condition' => '',
                        'broken' => '',
                        'location' => '',
                        'purchaseDate' => '',
                        'notes' => '',
                        'renewalPrice' => '',
                        'brokenCount' => 0,
                    ];
                }
            }
            if ($itemErrors !== [] && $equipment !== []) {
                $debugShape = ['top_keys' => ['item_errors'], 'data_type' => implode('; ', $itemErrors)];
            }
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            OsmDebug::log('equipment_error', [
                'message' => $e->getMessage(),
                'code' => $code,
                'sectionId' => $sectionId,
                'sectionType' => $sectionType,
            ]);
            $hint = $code > 0 ? " (OSM HTTP {$code})" : '';
            App::render('error.twig', Auth::baseContext([
                'title' => 'Equipment',
                'message' => "Could not load equipment for section {$sectionName} (id {$sectionId}, type {$sectionType}){$hint}. Check Settings → Tool sections.",
            ]));
            return;
        }

        $parseHint = null;
        if ($listCount === 0 && is_array($debugShape)) {
            $parseHint = 'OSM returned no quartermaster lists for this section (keys: '
                . implode(', ', $debugShape['top_keys'] ?? [])
                . '; data=' . ($debugShape['data_type'] ?? '?')
                . '). Confirm the Equipment section in Settings, or that quartermaster is enabled in OSM.';
        } elseif (is_array($debugShape) && ($debugShape['top_keys'][0] ?? '') === 'item_errors') {
            $parseHint = 'Found ' . $listCount . ' quartermaster list(s) but some item fetches failed: '
                . ($debugShape['data_type'] ?? '')
                . '. Lists still shown below.';
        }

        App::render('equipment-list.twig', Auth::baseContext([
            'title' => 'Equipment',
            'equipment' => $equipment,
            'sectionName' => $sectionName,
            'sectionId' => $sectionId,
            'sectionType' => $sectionType,
            'listCount' => $listCount,
            'needsConfig' => $savedId === '',
            'parseHint' => $parseHint,
        ]));
    }
}
