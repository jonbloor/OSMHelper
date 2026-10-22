<?php

declare(strict_types=1);

use App\Osm\OsmErrorLog;

/**
 * One-off (run by deploy.sh, safe to re-run): remove fields older versions wrote to
 * storage/osm-errors.jsonl (OSM message text, scoutid) and entries older than 30 days.
 * Rewrites the file in place under its lock, so ownership and permissions stay as they are.
 * Prints counts only.
 *
 *   cd /home/osmhelper/app && (umask 007; php bin/scrub-error-log.php)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

// Tests point this at a temp dir; on the server it is unset (app/storage/).
$dir = getenv('OSMHELPER_ERRORLOG_DIR');
if (is_string($dir) && $dir !== '') {
    OsmErrorLog::setDirForTests($dir);
}

$changed = OsmErrorLog::scrubLegacyFields();
$pruned = OsmErrorLog::pruneOld();
echo 'osm-errors.jsonl entries scrubbed: ' . $changed . ', older than 30 days removed: ' . $pruned . "\n";
exit(0);
