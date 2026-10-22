<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Http\Csrf;
use App\App;
use App\Http\Auth;
use App\Osm\OsmApi;
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
        $ctx = $this->resolveEquipmentLists($token);
        if ($ctx['error'] !== null) {
            App::render('error.twig', Auth::baseContext([
                'title' => 'Equipment',
                'message' => $ctx['error'],
            ]));
            return;
        }

        $savedId = $ctx['savedId'];
        $sectionId = $ctx['sectionId'];
        $sectionType = $ctx['sectionType'];
        $sectionName = $ctx['sectionName'];
        $lists = $ctx['lists'];
        $listCount = $ctx['listCount'];
        $debugShape = $ctx['debugShape'];
        $equipment = [];

        try {
            $api = new OsmApi();
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
        $equipmentLists = self::listChoices($lists);

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
            'equipmentLists' => $equipmentLists,
            'flash' => is_array($flash) ? $flash : null,
        ]));
    }

    /**
     * Review or commit one new quartermaster row.
     *
     * Jon capture (2026-10-12): POST /ext/quartermaster/?action=saveItemRowDetails
     * returns data.itemrowidentifier and data.row keyed by column number
     * (1 name, 2 description, 3 location, 4 notes, 5 condition, 6 quantity,
     * 7 broken, 8 purchase date, 9 renewal price). The request body was not
     * in that capture. This posts form-urlencoded listid, section, sectionid
     * and row[1]..row[9], the same encoding as the location update. It does
     * not send itemrowidentifier: OSM returns a new one. JSON content type
     * breaks quartermaster writes, so this stays form_params.
     */
    public function add(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
        $step = (string) ($_POST['step'] ?? 'review');
        $normalised = self::normaliseNewItem($_POST);
        if (!$normalised['ok']) {
            $_SESSION['equipmentFlash'] = [
                'type' => 'error',
                'message' => implode(' ', $normalised['errors']),
            ];
            header('Location: /equipment/');
            exit;
        }

        $ctx = $this->resolveEquipmentLists($token);
        if ($ctx['error'] !== null) {
            $_SESSION['equipmentFlash'] = [
                'type' => 'error',
                'message' => $ctx['error'],
            ];
            header('Location: /equipment/');
            exit;
        }

        $list = self::chosenList(trim((string) ($_POST['listId'] ?? '')), $ctx['listChoices']);
        if ($list === null) {
            $_SESSION['equipmentFlash'] = [
                'type' => 'error',
                'message' => 'Choose a list from this equipment section.',
            ];
            header('Location: /equipment/');
            exit;
        }

        $item = $normalised['item'];
        if ($step !== 'commit') {
            App::render('equipment-add-confirm.twig', Auth::baseContext([
                'title' => 'Confirm new item',
                'item' => $item,
                'listId' => $list['id'],
                'listName' => $list['name'],
                'sectionName' => $ctx['sectionName'],
            ]));
            return;
        }

        $ok = false;
        $rowId = '';
        $error = '';
        $echo = [];
        try {
            $api = new OsmApi();
            $res = $api->post($token, '/ext/quartermaster/?action=saveItemRowDetails', self::addItemFormParams(
                $list['id'],
                $ctx['sectionType'],
                $ctx['sectionId'],
                $item
            ));
            $read = self::readAddItemResponse(is_array($res) ? $res : []);
            $ok = $read['ok'];
            $rowId = $read['rowId'];
            $error = $read['error'];
            $echo = $read['row'];
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        App::render('equipment-add-result.twig', Auth::baseContext([
            'title' => $ok ? 'Item added' : 'Item was not added',
            'ok' => $ok,
            'item' => $item,
            'echo' => $echo,
            'rowId' => $rowId,
            'error' => $error,
            'listName' => $list['name'],
            'sectionName' => $ctx['sectionName'],
        ]));
    }

    /**
     * Review or commit a bulk location move.
     * Jon Network capture (2026-09-06): POST /ext/quartermaster/?action=updateListItemColumnValue
     * with listid + itemrowidentifier + columnid=3 (Location) + value=<location name>
     * (response stores the string under data; request field is value — Jon smoke 2026-09-06).
     * Confirm UI still runs before commit; 150ms spacing between writes.
     */
    public function move(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
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

        // Commit = real OSM Location column write (columnid 3), one item at a time.
        $results = [];
        $delayUs = 150000; // 150ms between writes (rate-limit friendly)
        $anyOk = false;
        $api = new OsmApi();
        foreach ($items as $i => $item) {
            if ($i > 0) {
                usleep($delayUs);
            }
            $ok = false;
            $message = '';
            try {
                // Captured URL puts action in query; body carries list/row/column/value (+ section like getList).
                $res = $api->post($token, '/ext/quartermaster/?action=updateListItemColumnValue', [
                    'listid' => $item['listId'],
                    'itemrowidentifier' => $item['rowid'],
                    'columnid' => 3,
                    'value' => $location, // request field; response may echo under data
                    'section' => $sectionType,
                    'sectionid' => $sectionId,
                ]);
                $statusTrue = ($res['status'] ?? null) === true
                    || ($res['status'] ?? null) === 'true'
                    || ($res['status'] ?? null) === 1;
                $data = is_array($res['data'] ?? null) ? $res['data'] : [];
                $echoData = '';
                if (isset($data['data']) && (is_string($data['data']) || is_numeric($data['data']))) {
                    $echoData = (string) $data['data'];
                } elseif (isset($data['value']) && (is_string($data['value']) || is_numeric($data['value']))) {
                    $echoData = (string) $data['value'];
                }
                if ($statusTrue) {
                    $ok = true;
                    $anyOk = true;
                    $message = $echoData !== '' ? ('OSM: ' . $echoData) : 'Updated';
                } else {
                    $message = 'OSM did not return status true'
                        . (isset($res['error']) ? (': ' . (is_string($res['error']) ? $res['error'] : json_encode($res['error']))) : '');
                }
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
            $results[] = [
                'listId' => $item['listId'],
                'rowid' => $item['rowid'],
                'itemName' => $item['itemName'],
                'ok' => $ok,
                'dryRun' => false,
                'message' => $message,
            ];
        }

        App::render('equipment-move-result.twig', Auth::baseContext([
            'title' => 'Move result',
            'location' => $location,
            'results' => $results,
            'sectionName' => $sectionName,
            'wrote' => $anyOk,
            'dryRun' => false,
            'honestError' => '',
        ]));
    }

    /**
     * Section and quartermaster lists for the Equipment page and for adding an item.
     * Same choice as the old list loader: Settings equipment section, then type
     * variants, then the first other adults section if the configured id has no lists.
     *
     * @return array{
     *   error: ?string,
     *   savedId: string,
     *   sectionId: string,
     *   sectionType: string,
     *   sectionName: string,
     *   lists: list<array<string, mixed>>,
     *   listChoices: list<array{id: string, name: string}>,
     *   listCount: int,
     *   debugShape: ?array<string, mixed>
     * }
     */
    private function resolveEquipmentLists(string $token): array
    {
        $empty = [
            'error' => null,
            'savedId' => '',
            'sectionId' => '',
            'sectionType' => '',
            'sectionName' => '',
            'lists' => [],
            'listChoices' => [],
            'listCount' => 0,
            'debugShape' => null,
        ];
        $savedId = (string) SettingsStore::get('equipmentSectionId', '');
        $savedType = (string) SettingsStore::get('equipmentSectionType', 'adults');
        $api = new OsmApi();
        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable) {
            $empty['error'] = 'Could not load sections from OSM.';
            return $empty;
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
            $empty['error'] = 'No equipment section configured. Pick one under Settings → Tool sections.';
            return $empty;
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
        $lists = [];
        $listsRes = [];
        $lastErr = null;
        $debugShape = null;
        $usedType = $sectionType;

        foreach (self::typeVariants($liveType, $sectionType, $savedType) as $tryType) {
            try {
                $listsRes = $api->get($token, '/ext/quartermaster/', [
                    'action' => 'getListOfLists',
                    'section' => $tryType,
                    'sectionid' => $sectionId,
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
            }
        }

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
            $code = (int) $lastErr->getCode();
            $hint = $code > 0 ? " (OSM HTTP {$code})" : '';
            $empty['error'] = "Could not load equipment for section {$sectionName} (id {$sectionId}, type {$sectionType}){$hint}. Check Settings → Tool sections.";
            return $empty;
        }

        $empty['savedId'] = $savedId;
        $empty['sectionId'] = $sectionId;
        $empty['sectionType'] = $usedType;
        $empty['sectionName'] = $sectionName;
        $empty['lists'] = $lists;
        $empty['listChoices'] = self::listChoices($lists);
        $empty['listCount'] = count($lists);
        $empty['debugShape'] = $debugShape;
        return $empty;
    }

    /**
     * @param list<array<string, mixed>> $lists
     * @return list<array{id: string, name: string}>
     */
    private static function listChoices(array $lists): array
    {
        $out = [];
        $seen = [];
        foreach ($lists as $equipList) {
            if (!is_array($equipList)) {
                continue;
            }
            $listId = $equipList['listid'] ?? $equipList['list_id'] ?? $equipList['id'] ?? null;
            if ($listId === null || $listId === '') {
                continue;
            }
            $id = trim((string) $listId);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $name = trim((string) ($equipList['name'] ?? ''));
            $out[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : ('List ' . $id),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{
     *   ok: bool,
     *   errors: list<string>,
     *   item: array{
     *     name: string,
     *     quantity: string,
     *     description: string,
     *     condition: string,
     *     broken: string,
     *     location: string,
     *     purchaseDate: string,
     *     notes: string,
     *     renewalPrice: string
     *   }
     * }
     */
    public static function normaliseNewItem(array $post): array
    {
        $name = self::postedString($post, 'name');
        $quantity = self::postedString($post, 'quantity');
        $description = self::postedString($post, 'description');
        $condition = self::postedString($post, 'condition');
        $broken = self::postedString($post, 'broken');
        $location = self::postedString($post, 'location');
        $purchaseDate = self::postedString($post, 'purchaseDate');
        $notes = self::postedString($post, 'notes');
        $renewalPrice = self::postedString($post, 'renewalPrice');
        $errors = [];

        if ($name === '') {
            $errors[] = 'Enter a name for the item.';
        } elseif (str_contains($name, "\n") || self::hasDisallowedControls($name)) {
            $errors[] = 'Remove unusual characters from the name.';
        } elseif (mb_strlen($name) > 200) {
            $errors[] = 'Name is too long (limit 200 characters).';
        }

        if ($quantity !== '' && preg_match('/^\d{1,6}(\.\d{1,2})?$/', $quantity) !== 1) {
            $errors[] = 'Quantity needs to be a number, such as 3.';
        }
        if ($broken === '') {
            $broken = '0';
        } elseif (preg_match('/^\d{1,6}$/', $broken) !== 1) {
            $errors[] = 'Broken needs to be a whole number, or leave it blank for 0.';
        }
        if ($purchaseDate !== '' && !self::isIsoDate($purchaseDate)) {
            $errors[] = 'Purchase date needs to be a real date.';
        }
        if ($renewalPrice !== '' && preg_match('/^\d{1,7}(\.\d{1,2})?$/', $renewalPrice) !== 1) {
            $errors[] = 'Renewal price needs to be a number, such as 149.99.';
        }

        self::limitText($errors, 'Description', $description, 500);
        self::limitText($errors, 'Condition', $condition, 500);
        self::limitText($errors, 'Location', $location, 200);
        self::limitText($errors, 'Notes', $notes, 500);

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'item' => [
                'name' => $name,
                'quantity' => $quantity,
                'description' => $description,
                'condition' => $condition,
                'broken' => $broken,
                'location' => $location,
                'purchaseDate' => $purchaseDate,
                'notes' => $notes,
                'renewalPrice' => $renewalPrice,
            ],
        ];
    }

    /**
     * @param list<array{id: string, name: string}> $lists
     * @return ?array{id: string, name: string}
     */
    public static function chosenList(string $listId, array $lists): ?array
    {
        $listId = trim($listId);
        if ($listId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $listId) !== 1) {
            return null;
        }
        foreach ($lists as $list) {
            if (!is_array($list)) {
                continue;
            }
            if ((string) ($list['id'] ?? '') !== $listId) {
                continue;
            }
            $name = trim((string) ($list['name'] ?? ''));
            return [
                'id' => $listId,
                'name' => $name !== '' ? $name : ('List ' . $listId),
            ];
        }
        return null;
    }

    /**
     * @param array{
     *   name: string,
     *   quantity: string,
     *   description: string,
     *   condition: string,
     *   broken: string,
     *   location: string,
     *   purchaseDate: string,
     *   notes: string,
     *   renewalPrice: string
     * } $item
     * @return array{listid: string, section: string, sectionid: string, row: array<string, string>}
     */
    public static function addItemFormParams(string $listId, string $sectionType, string $sectionId, array $item): array
    {
        return [
            'listid' => $listId,
            'section' => $sectionType,
            'sectionid' => $sectionId,
            'row' => [
                '1' => $item['name'],
                '2' => $item['description'],
                '3' => $item['location'],
                '4' => $item['notes'],
                '5' => $item['condition'],
                '6' => $item['quantity'],
                '7' => $item['broken'],
                '8' => $item['purchaseDate'],
                '9' => $item['renewalPrice'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $res
     * @return array{ok: bool, rowId: string, error: string, row: array<string, string>}
     */
    public static function readAddItemResponse(array $res): array
    {
        $status = $res['status'] ?? null;
        $okStatus = $status === true || $status === 'true' || $status === 1 || $status === '1';
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $rowId = trim(self::cellToString($data['itemrowidentifier'] ?? ''));
        $rawRow = is_array($data['row'] ?? null) ? $data['row'] : [];
        $row = [];
        foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $col) {
            if (array_key_exists($col, $rawRow)) {
                $row[$col] = self::cellToString($rawRow[$col]);
            }
        }
        $error = '';
        if (!$okStatus) {
            $error = self::osmErrorText($res['error'] ?? null);
            if ($error === '') {
                $error = 'OSM did not confirm the new item.';
            }
        } elseif ($rowId === '') {
            $error = 'OSM did not return a new row id.';
        }
        return [
            'ok' => $okStatus && $rowId !== '',
            'rowId' => $rowId,
            'error' => $error,
            'row' => $row,
        ];
    }

    /** @param array<string, mixed> $post */
    private static function postedString(array $post, string $key): string
    {
        $value = $post[$key] ?? '';
        if (is_array($value) || is_object($value) || $value === null) {
            return '';
        }
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }
        return str_replace(["\r\n", "\r"], "\n", trim((string) $value));
    }

    private static function hasDisallowedControls(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1;
    }

    /** @param list<string> $errors */
    private static function limitText(array &$errors, string $label, string $value, int $max): void
    {
        if ($value === '') {
            return;
        }
        if (self::hasDisallowedControls($value)) {
            $errors[] = 'Remove unusual characters from the ' . strtolower($label) . '.';
            return;
        }
        if (mb_strlen($value) > $max) {
            $errors[] = $label . ' is too long (limit ' . $max . ' characters).';
        }
    }

    private static function isIsoDate(string $value): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$dt instanceof \DateTimeImmutable) {
            return false;
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return false;
        }
        return $dt->format('Y-m-d') === $value;
    }

    private static function osmErrorText(mixed $error): string
    {
        if (is_string($error)) {
            return trim($error);
        }
        if (!is_array($error)) {
            return '';
        }
        $message = $error['message'] ?? '';
        if (is_string($message) && trim($message) !== '') {
            $text = trim($message);
            $code = $error['code'] ?? '';
            if ((is_string($code) || is_int($code)) && (string) $code !== '') {
                $text .= ' [' . $code . ']';
            }
            return $text;
        }
        $json = json_encode($error, JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '';
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
