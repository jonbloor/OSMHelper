<?php

declare(strict_types=1);

use App\Osm\OsmRateGuard;

/**
 * Admin only, by hand: show or clear OSMHelper's app-wide "OSM blocked us" state.
 *
 * When OSM sends X-Blocked, OSMHelper writes storage/osm-rate/app-blocked.json and refuses every
 * OSM call for every user (saves, sign-in and the WordPress joining form included) until this is
 * run with --clear. Carrying on after X-Blocked can make OSM's block permanent, so only clear it
 * once OSM has confirmed the block is lifted and the cause is fixed. See docs/DEPLOY-RUNBOOK.md.
 * Makes no OSM calls. Prints no member data.
 *
 *   cd /home/osmhelper/app && php bin/osm-clear-block.php            # show status
 *   cd /home/osmhelper/app && php bin/osm-clear-block.php --clear    # lift the block
 *   cd /home/osmhelper/app && php bin/osm-clear-block.php --user=12345            # show one user's backoff
 *   cd /home/osmhelper/app && php bin/osm-clear-block.php --user=12345 --clear    # clear one user's backoff
 * Run as the PHP-FPM user (osmhelper): the files are 0600 in a 0700 dir.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

// Tests point this at a temp dir; on the server it is unset (app/storage/osm-rate).
$dir = getenv('OSMHELPER_OSM_RATE_DIR');
if (is_string($dir) && $dir !== '') {
    OsmRateGuard::useDirectory($dir);
}

$userArg = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--user=')) {
        $userArg = substr($a, 7);
    }
}
if ($userArg !== null) {
    if (!preg_match('/^\d{1,12}$/', $userArg)) {
        fwrite(STDERR, "--user must be an OSM user number.\n");
        exit(2);
    }
    $g = OsmRateGuard::forUser($userArg);
    $left = $g->secondsLeft();
    $why = $g->reason() ?? 'none';
    if (in_array('--clear', $argv, true)) {
        $g->reset();
        echo "Cleared OSM backoff for that user" . ($left > 0 ? " ({$left}s were left, reason {$why})" : '') . ".\n";
        echo "Only do this if you are sure OSM's limit has reset: their next requests go straight to OSM.\n";
    } else {
        $st = $g->state();
        echo $left > 0
            ? "Backoff: {$left}s left, reason " . (string) ($st['reason'] ?? 'unknown') . ".\n"
            : "No backoff for that user.\n";
    }
    exit(0);
}

$blocked = OsmRateGuard::appBlocked();
if (in_array('--clear', $argv, true)) {
    if ($blocked === null) {
        echo "Not blocked: nothing to clear.\n";
        exit(0);
    }
    if (!OsmRateGuard::clearAppBlock()) {
        fwrite(STDERR, "Could not delete " . OsmRateGuard::dir() . '/' . OsmRateGuard::APP_BLOCK_FILE . " (check ownership; run as the PHP-FPM user).\n");
        exit(1);
    }
    echo "Cleared the OSM block. OSMHelper will contact OSM again on the next request.\n";
    exit(0);
}
if ($blocked === null) {
    echo "Not blocked.\n";
} else {
    $at = $blocked['blockedAt'] > 0 ? gmdate('Y-m-d H:i:s', $blocked['blockedAt']) . ' UTC' : 'unknown time';
    echo "BLOCKED since {$at}. Every OSM call is refused. Run with --clear only once OSM has lifted the block.\n";
}
exit(0);
