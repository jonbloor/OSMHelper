<?php
declare(strict_types=1);
/**
 * Offline tests for the Young Leaders page. Fake fixtures only: every OSM reply comes from a
 * Guzzle handler in this file, never the network.
 * Run: php tests/young-leaders-test.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/isolate-storage.php';

use App\Osm\OsmCache;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$failed = 0;
$passed = 0;
function check(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) { $passed++; echo "OK  $msg\n"; } else { $failed++; echo "FAIL $msg\n"; }
}

$now = 1_800_000_000;
OsmCache::useClock(static function () use (&$now): int { return $now; });

// --- OsmCache: per-kind TTL ---------------------------------------------------------------
check(OsmCache::ttlFor('ylmember') === 86400 && OsmCache::ttlFor('ylsection') === 86400, 'Young Leaders kinds last 24 hours');
check(OsmCache::ttlFor('waiting') === OsmCache::TTL_SECONDS, 'other kinds keep the 5-minute TTL');
OsmCache::put('ylmember', 'u1', '10:1', ['others' => ['A: B']]);
OsmCache::put('waiting', 'u1', '10', ['rows' => []]);
$now += 3600;
OsmCache::sweep();
check(OsmCache::get('ylmember', 'u1', '10:1') !== null, 'sweep keeps a 1-hour-old Young Leaders entry');
check(OsmCache::get('waiting', 'u1', '10') === null, 'sweep still drops 5-minute kinds');
check(OsmCache::get('ylmember', 'u2', '10:1') === null, 'entry is owner-only');
OsmCache::forgetUser('u1');
check(OsmCache::get('ylmember', 'u1', '10:1') !== null, 'sign-out keeps the 24-hour Young Leaders entry');
OsmCache::forgetUser('u1', true);
check(OsmCache::get('ylmember', 'u1', '10:1') === null, 'forgetUser(everything) removes it');
OsmCache::put('ylmember', 'u1', '10:1', ['others' => []]);
$now += 86400;
OsmCache::sweep();
check(glob(OsmCache::dir() . '/ylmember-*') === [], 'sweep deletes Young Leaders files after 24 hours');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
