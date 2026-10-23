<?php

declare(strict_types=1);

/**
 * Offline tests for adding a quartermaster item. No network / no OSM.
 * Run: php tests/equipment-add-test.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/isolate-storage.php';

use App\Http\Controllers\EquipmentController;

$failed = 0;
$passed = 0;
function check(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "OK  $msg\n";
    } else {
        $failed++;
        echo "FAIL $msg\n";
    }
}

$blank = EquipmentController::normaliseNewItem(['name' => '   ']);
check($blank['ok'] === false && $blank['errors'][0] === 'Enter a name for the item.', 'rejects a blank name');

$badQty = EquipmentController::normaliseNewItem(['name' => 'Rope', 'quantity' => '-1']);
check($badQty['ok'] === false && in_array('Quantity needs to be a number, such as 3.', $badQty['errors'], true), 'rejects a negative quantity');

$badDate = EquipmentController::normaliseNewItem(['name' => 'Rope', 'purchaseDate' => '2026-02-31']);
check($badDate['ok'] === false && in_array('Purchase date needs to be a real date.', $badDate['errors'], true), 'rejects 31 February');

$badPrice = EquipmentController::normaliseNewItem(['name' => 'Rope', 'renewalPrice' => '£149.99']);
check($badPrice['ok'] === false && in_array('Renewal price needs to be a number, such as 149.99.', $badPrice['errors'], true), 'rejects a price with a currency sign');

$posted = [
    'name' => '  Vango Tent  ',
    'quantity' => '3',
    'description' => 'This is the description',
    'condition' => 'Excellent',
    'broken' => '',
    'location' => 'Store Room, Shelf 12',
    'purchaseDate' => '2026-10-10',
    'notes' => 'This is a note',
    'renewalPrice' => '149.99',
];
$ok = EquipmentController::normaliseNewItem($posted);
check($ok['ok'] === true && $ok['errors'] === [], 'accepts the captured tent fields');
check($ok['item']['name'] === 'Vango Tent', 'trims the name');
check($ok['item']['broken'] === '0', 'blank broken becomes 0');

$params = EquipmentController::addItemFormParams('55', 'adults', '9', $ok['item']);
check(!array_key_exists('itemrowidentifier', $params), 'create does not send an existing row id');
check($params['listid'] === '55' && $params['section'] === 'adults' && $params['sectionid'] === '9', 'sends list and section with the row');
check($params['row'] === [
    '1' => 'Vango Tent',
    '2' => 'This is the description',
    '3' => 'Store Room, Shelf 12',
    '4' => 'This is a note',
    '5' => 'Excellent',
    '6' => '3',
    '7' => '0',
    '8' => '2026-10-10',
    '9' => '149.99',
], 'row columns match the OSM quartermaster map');
$query = http_build_query($params);
check(str_contains($query, 'row%5B1%5D=Vango+Tent') || str_contains($query, 'row[1]=Vango+Tent'), 'encodes the name as form field row[1]');
check(!str_contains($query, 'itemrowidentifier'), 'the encoded body has no row id');

$captured = [
    'status' => true,
    'error' => null,
    'data' => [
        'itemrowidentifier' => '1791812716_6acce46c976c5',
        'row' => [
            '1' => 'Vango Tent',
            '6' => '3',
            '2' => 'This is the description',
            '5' => 'Excellent',
            '7' => '0',
            '3' => 'Store Room, Shelf 12',
            '8' => '2026-10-10',
            '9' => '149.99',
            '4' => 'This is a note',
        ],
    ],
    'meta' => [],
];
$read = EquipmentController::readAddItemResponse($captured);
check($read['ok'] === true && $read['rowId'] === '1791812716_6acce46c976c5', 'reads the captured save response');
check($read['row']['1'] === 'Vango Tent' && $read['row']['9'] === '149.99', 'reads the captured row columns');

$failedSave = EquipmentController::readAddItemResponse([
    'status' => false,
    'error' => ['message' => 'Name is required', 'code' => 'invalid'],
]);
check($failedSave['ok'] === false && $failedSave['error'] === 'Name is required [invalid]', 'reports an OSM error and does not treat it as added');

$noId = EquipmentController::readAddItemResponse(['status' => true, 'data' => ['row' => ['1' => 'Tent']]]);
check($noId['ok'] === false && $noId['error'] === 'OSM did not return a new row id.', 'a response without a row id is not a success');

$lists = [
    ['id' => '55', 'name' => 'Tents'],
    ['id' => '56', 'name' => 'Poles'],
];
$chosen = EquipmentController::chosenList('55', $lists);
check($chosen !== null && $chosen['name'] === 'Tents', 'accepts a list from this section');
check(EquipmentController::chosenList('999', $lists) === null, 'rejects a list that was not loaded');
check(EquipmentController::chosenList('../55', $lists) === null, 'rejects a list id with unexpected characters');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
