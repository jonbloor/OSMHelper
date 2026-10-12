<?php

declare(strict_types=1);

/**
 * Offline tests for the member-list section picker. No network / no OSM.
 * Run: php tests/login-sections-test.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Osm\LoginSections;
use App\Osm\OsmApi;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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

$raw = [
    [
        'section_id' => '10',
        'section_name' => 'Colony',
        'section_type' => 'beavers',
        'group_name' => '4th Ashby',
        'terms' => [
            ['term_id' => 1, 'startdate' => '2020-01-01', 'enddate' => '2020-06-01'],
            ['term_id' => 99, 'startdate' => '2026-09-01', 'enddate' => '2027-01-31'],
        ],
    ],
    [
        'section_id' => '11',
        'section_name' => 'Pack',
        'section_type' => 'cubs',
        'group_name' => '4th Ashby',
        'current_term_id' => 5,
    ],
    'not-a-section',
];
$normalised = OsmApi::normalizeSections($raw);
check(count($normalised) === 2, 'drops non-arrays');
check((string) $normalised[0]['current_term_id'] === '99', 'picks the term covering today');
check((string) $normalised[1]['current_term_id'] === '5', 'keeps an existing current term');

$nested = OsmApi::sectionsFromResource([
    'data' => [
        'sections' => [
            ['section_id' => '3', 'section_name' => 'Troop', 'section_type' => 'scouts', 'group_name' => '4th Ashby'],
        ],
    ],
]);
check(count($nested) === 1 && $nested[0]['section_name'] === 'Troop', 'reads sections nested under data');

$choices = LoginSections::memberListChoices([
    ['section_id' => '2', 'section_name' => 'Troop', 'section_type' => 'scouts', 'group_name' => 'B Group', 'current_term_id' => 8],
    ['section_id' => '1', 'section_name' => 'Colony', 'section_type' => 'beavers', 'group_name' => 'A Group', 'current_term_id' => 7],
    ['section_id' => '9', 'section_name' => 'Wait', 'section_type' => 'waiting', 'group_name' => 'A Group'],
    ['section_id' => '8', 'section_name' => 'Mystery', 'section_type' => 'unknown', 'group_name' => 'A Group'],
    ['section_id' => '4', 'section_name' => 'Leaders', 'section_type' => 'adults', 'group_name' => 'A Group', 'current_term_id' => 3],
]);
check(array_column($choices, 'id') === ['1', '4', '2'], 'skips waiting and unknown, orders by group then type');
check($choices[0]['typeLabel'] === 'Beavers', 'uses the friendly section type');

$groups = LoginSections::groupChoices($choices);
check(count($groups) === 2 && $groups[0]['name'] === 'A Group' && count($groups[0]['sections']) === 2, 'groups by group name');

$picked = LoginSections::pickedSections($choices, ['2', '999', '2', '']);
check(count($picked) === 1 && $picked[0]['section_id'] === '2' && $picked[0]['section_type'] === 'scouts', 'ignores ids that were not on the login list');
check($picked[0]['current_term_id'] === 8, 'keeps the term id for the member fetch');
check(LoginSections::pickedSections($choices, []) === [], 'empty selection picks nothing');

$dash = LoginSections::dashboardChoices([
    ['section_id' => '2', 'section_name' => 'Troop', 'section_type' => 'scouts', 'group_name' => 'B Group'],
    ['section_id' => '1', 'section_name' => 'Colony', 'section_type' => 'beavers', 'group_name' => 'A Group'],
    ['section_id' => '9', 'section_name' => 'Wait', 'section_type' => 'waiting', 'group_name' => 'A Group'],
    ['section_id' => '5', 'section_name' => 'Unit', 'section_type' => 'explorers', 'group_name' => 'A Group'],
    ['section_id' => '4', 'section_name' => 'Leaders', 'section_type' => 'adults', 'group_name' => 'A Group'],
    ['section_id' => '6', 'section_name' => 'Dreys', 'section_type' => 'earlyyears', 'group_name' => 'A Group'],
    ['section_id' => '8', 'section_name' => 'Mystery', 'section_type' => 'unknown', 'group_name' => 'A Group'],
]);
check(array_column($dash, 'id') === ['6', '1', '2'], 'group numbers skips waiting, explorers, adults and unknown');

$_SESSION = [];
check(LoginSections::fromSession() === null, 'missing session key means the list was not stored yet');
$stored = LoginSections::remember($normalised);
check(LoginSections::fromSession() === $stored, 'remember round-trips through the session');
check(($_SESSION['_sections_count'] ?? null) === 2, 'section count matches the stored list');
$_SESSION[LoginSections::SESSION_KEY] = [];
check(LoginSections::fromSession() === [], 'an empty stored list is kept and not treated as missing');

echo "$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
