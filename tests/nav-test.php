<?php

declare(strict_types=1);

/**
 * Offline tests for the main menu: structure, labels and the current-page marker.
 * No network / no OSM. Run: php tests/nav-test.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\Nav;

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

/** @return list<string> labels of the links marked current */
function currentLabels(array $entries): array
{
    $out = [];
    foreach ($entries as $e) {
        if ($e['type'] === 'group') {
            foreach ($e['items'] as $i) {
                if (!empty($i['current'])) {
                    $out[] = $i['label'];
                }
            }
        } elseif (!empty($e['current'])) {
            $out[] = $e['label'];
        }
    }
    return $out;
}

function groupsWithCurrent(array $entries): array
{
    $out = [];
    foreach ($entries as $e) {
        if ($e['type'] === 'group' && !empty($e['hasCurrent'])) {
            $out[] = $e['label'];
        }
    }
    return $out;
}

// Structure and labels (Jon's choices, 24 Oct 2026).
$nav = Nav::signedIn();
$top = array_map(static fn ($e) => $e['label'], $nav);
check($top === ['Home', 'People', 'Joining', 'Badges', 'Equipment', 'Finance', 'Settings', 'Help', 'Sign out'], 'top level: ' . implode(' · ', $top));
$byLabel = [];
foreach ($nav as $e) {
    if ($e['type'] === 'group') {
        $byLabel[$e['label']] = array_map(static fn ($i) => $i['label'], $e['items']);
    }
}
check($byLabel['People'] === ['Group numbers', 'Member list', 'Find a member', 'Young Leaders', 'Member checks'], 'People items');
check($byLabel['Joining'] === ['Waiting list', 'Joining form'], 'Joining items');
check($byLabel['Badges'] === ['Events', 'Nights away', 'Top awards'], 'Badges items (label stays Badges)');
check($byLabel['Equipment'] === ['Equipment list', 'Add an item', 'Locations'], 'Equipment items');
check($byLabel['Finance'] === ['Bank accounts', 'All transfers'], 'Finance items');
check(!in_array('/roadmap/', Nav::hrefs($nav), true), 'Roadmap is not in the signed-in menu');
check(!in_array('/roadmap/', Nav::hrefs(Nav::public()), true), 'Roadmap is not in the public menu');

// Every pre-existing menu route is still in the menu (no route lost), plus Young Leaders.
$before = ['/dashboard/', '/membership-dashboard/', '/members/', '/find-member/', '/events/', '/member-checks/',
    '/young-leaders/', '/waiting-list/', '/wordpress-form/', '/top-awards/', '/nights-away/', '/equipment/',
    '/bank-transfers/', '/bank-transfers/all/', '/settings/', '/help/', '/logout/'];
$hrefs = Nav::hrefs($nav);
foreach ($before as $h) {
    check(in_array($h, $hrefs, true), "still in the menu: $h");
}
$routes = (string) file_get_contents(dirname(__DIR__) . '/src/App.php');
foreach ($hrefs as $h) {
    $p = rtrim((string) parse_url($h, PHP_URL_PATH), '/');
    $p = $p === '' ? '/' : $p;
    check(str_contains($routes, "get('" . $p . "'"), "menu link has a GET route: $h");
}

// Current page: exactly one link, whole path segments, longest match wins.
$cases = [
    '/membership-dashboard/' => ['Group numbers'],
    '/members/' => ['Member list'],
    '/members' => ['Member list'],
    '/bank-transfers/all/' => ['All transfers'],
    '/bank-transfers/' => ['Bank accounts'],
    '/bank-transfers/?account=5' => ['Bank accounts'],
    '/waiting-list/rank/?section=1' => ['Waiting list'],
    '/events/csv/' => ['Events'],
    '/equipment/' => ['Equipment list'],
    '/settings/' => ['Settings'],
    '/dashboard/' => ['Home'],
    '/young-leaders/' => ['Young Leaders'],
    '/roadmap/' => [],
    '/membersx/' => [],
    '/' => [],
];
foreach ($cases as $uri => $want) {
    $got = currentLabels(Nav::forRequest(true, $uri));
    check($got === $want, "current on $uri: [" . implode(', ', $got) . ']');
}
check(groupsWithCurrent(Nav::forRequest(true, '/membership-dashboard/')) === ['People'], 'only People group marked on /membership-dashboard/');
check(groupsWithCurrent(Nav::forRequest(true, '/bank-transfers/all/')) === ['Finance'], 'only Finance group marked on /bank-transfers/all/');
check(groupsWithCurrent(Nav::forRequest(true, '/settings/')) === [], 'Locations (#fragment) does not mark Equipment on /settings/');
check(currentLabels(Nav::forRequest(false, '/')) === ['Home'], 'public Home current on /');
check(currentLabels(Nav::forRequest(false, '/help/')) === ['Help'], 'public Help current on /help/');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
