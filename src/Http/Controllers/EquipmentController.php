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
    private static function cellToString(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        if (is_array($v)) {
            // OSM sometimes nests {value:…} or multi-line arrays
            if (array_key_exists('value', $v)) {
                return self::cellToString($v['value']);
            }
            if (array_is_list($v)) {
                $parts = [];
                foreach ($v as $p) {
                    $s = self::cellToString($p);
                    if ($s !== '') {
                        $parts[] = $s;
                    }
                }
                return implode("\n", $parts);
            }
            // assoc leftover — prefer common keys
            foreach (['text', 'label', 'name', 'raw'] as $k) {
                if (array_key_exists($k, $v)) {
                    return self::cellToString($v[$k]);
                }
            }
            $json = json_encode($v, JSON_UNESCAPED_UNICODE);
            return is_string($json) ? $json : '';
        }
        return '';
    }

    private static function normaliseItemRow(array $row, string $rowId): array
    {
        $pick = static function (array $row, string $n): string {
            if (array_key_exists('_' . $n, $row)) {
                return self::cellToString($row['_' . $n]);
            }
            if (array_key_exists($n, $row)) {
                return self::cellToString($row[$n]);
            }
            return '';
        };
        $name = $pick($row, '1');
        if ($name === '' && isset($row['name'])) {
            $name = self::cellToString($row['name']);
        }
        return [
            'rowid' => self::cellToString($row['rowid'] ?? ($row['id'] ?? $rowId)),
            '_1' => $name,
            '_2' => $pick($row, '2'),
            '_3' => $pick($row, '3'),
            '_4' => $pick($row, '4'),
            '_5' => $pick($row, '5'),
            '_6' => $pick($row, '6'),
            '_7' => $pick($row, '7'),
            '_8' => $pick($row, '8'),
            '_9' => $pick($row, '9'),
            'name' => $name,
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
                            'listId' => (string) $listId,
                            'rowid' => '',
                            'selectable' => false,
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
                        $rowid = (string) ($item['rowid'] ?? '');
                        $equipment[] = [
                            'listName' => $listName,
                            'listId' => (string) $listId,
                            'rowid' => $rowid,
                            'selectable' => $rowid !== '',
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
                        'listId' => (string) $listId,
                        'rowid' => '',
                        'selectable' => false,
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

        $locations = self::savedLocations();
        $flash = $_SESSION['equipmentFlash'] ?? null;
        unset($_SESSION['equipmentFlash']);

        App::render('equipment-list.twig', Auth::baseContext([
            'title' => 'Equipment',
            'equipment' => $equipment,
            'sectionName' => $sectionName,
            'sectionId' => $sectionId,
            'sectionType' => $sectionType,
            'listCount' => $listCount,
            'needsConfig' => $savedId === '',
            'parseHint' => $parseHint,
            'equipmentLocations' => $locations,
            'flash' => is_array($flash) ? $flash : null,
        ]));
    }

    /**
     * Review or commit a bulk location move.
     * OSM quartermaster WRITE action is not confirmed in Node (stubs) or community docs —
     * commit runs as dry-run/stub with an honest error rather than inventing POSTs.
     */
    public function move(): void
    {
        $token = Auth::requireLogin();
        $step = (string) ($_POST['step'] ?? 'review');
        $location = trim((string) ($_POST['location'] ?? ''));
        $rawItems = $_POST['items'] ?? [];
        if (!is_array($rawItems)) {
            $rawItems = [];
        }

        $locations = self::savedLocations();
        if ($location === '' || !in_array($location, $locations, true)) {
            $_SESSION['equipmentFlash'] = [
                'type' => 'error',
                'message' => 'Pick a location from Settings → Equipment locations before moving kit.',
            ];
            header('Location: /equipment/');
            exit;
        }

        $parsed = [];
        foreach ($rawItems as $raw) {
            if (!is_string($raw) && !is_numeric($raw)) {
                continue;
            }
            $parts = explode(':', (string) $raw, 3);
            if (count($parts) < 2) {
                continue;
            }
            $listId = trim($parts[0]);
            $rowid = trim($parts[1]);
            $itemName = isset($parts[2]) ? trim($parts[2]) : '';
            if ($listId === '' || $rowid === '') {
                continue;
            }
            $parsed[] = [
                'listId' => $listId,
                'rowid' => $rowid,
                'itemName' => $itemName !== '' ? $itemName : ('Item ' . $rowid),
                'key' => $listId . ':' . $rowid,
            ];
        }
        // de-dupe
        $seen = [];
        $items = [];
        foreach ($parsed as $p) {
            if (isset($seen[$p['key']])) {
                continue;
            }
            $seen[$p['key']] = true;
            $items[] = $p;
        }

        if ($items === []) {
            $_SESSION['equipmentFlash'] = [
                'type' => 'error',
                'message' => 'Select at least one equipment item to move.',
            ];
            header('Location: /equipment/');
            exit;
        }

        $savedId = (string) SettingsStore::get('equipmentSectionId', '');
        $savedType = (string) SettingsStore::get('equipmentSectionType', 'adults');
        $sectionId = $savedId;
        $sectionType = $savedType !== '' ? $savedType : 'adults';
        $sectionName = '';
        try {
            $api = new OsmApi();
            $sections = $api->getDynamicSections($token);
            foreach ($sections as $s) {
                if (is_array($s) && (string) ($s['section_id'] ?? '') === $savedId) {
                    $sectionType = (string) ($s['section_type'] ?? $sectionType);
                    $sectionName = (string) ($s['section_name'] ?? '');
                    break;
                }
            }
            if ($sectionId === '') {
                foreach ($sections as $s) {
                    if (is_array($s) && ($s['section_type'] ?? '') === 'adults' && !empty($s['section_id'])) {
                        $sectionId = (string) $s['section_id'];
                        $sectionType = (string) ($s['section_type'] ?? 'adults');
                        $sectionName = (string) ($s['section_name'] ?? '');
                        break;
                    }
                }
            }
        } catch (Throwable) {
            // keep saved ids
        }

        if ($step !== 'commit') {
            App::render('equipment-move-confirm.twig', Auth::baseContext([
                'title' => 'Confirm move',
                'location' => $location,
                'items' => $items,
                'sectionId' => $sectionId,
                'sectionType' => $sectionType,
                'sectionName' => $sectionName,
                'itemCount' => count($items),
            ]));
            return;
        }

        // Commit = dry-run stub (no invented OSM POSTs)
        $results = [];
        $delayUs = 150000; // 150ms between would-be writes (rate-limit friendly when real)
        foreach ($items as $i => $item) {
            if ($i > 0) {
                usleep($delayUs);
            }
            $results[] = [
                'listId' => $item['listId'],
                'rowid' => $item['rowid'],
                'itemName' => $item['itemName'],
                'ok' => false,
                'dryRun' => true,
                'message' => 'Dry-run only — quartermaster location write action not confirmed (Node POSTs were stubs; no community docs). Nothing written to OSM.',
            ];
            OsmDebug::log('equipment_move_dryrun_' . $item['listId'] . '_' . $item['rowid'], [
                'listId' => $item['listId'],
                'rowid' => $item['rowid'],
                'location' => $location,
                'sectionId' => $sectionId,
                'sectionType' => $sectionType,
                'mode' => 'dry-run-stub',
            ]);
        }

        App::render('equipment-move-result.twig', Auth::baseContext([
            'title' => 'Move result',
            'location' => $location,
            'results' => $results,
            'sectionName' => $sectionName,
            'wrote' => false,
            'dryRun' => true,
            'honestError' => 'OSM quartermaster write API for updating item location (column _3) is not documented in the Node reference (add/edit POSTs redirect only) or community OpenAPI. OSMHelper did not invent a POST. UI and dry-run are ready for a real write once the action is captured.',
        ]));
    }

    /** @return list<string> */
    private static function savedLocations(): array
    {
        $raw = SettingsStore::get('equipmentLocations', []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }
            $label = trim((string) $item);
            if ($label !== '') {
                $out[] = $label;
            }
        }
        return $out;
    }
}
