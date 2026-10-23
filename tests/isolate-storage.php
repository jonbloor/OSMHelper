<?php
declare(strict_types=1);

/**
 * Included by tests right after the autoloader: keeps OSM rate state (including an X-Blocked
 * app-wide stop that a test may trigger on purpose) and the OSM read cache in a throwaway temp
 * dir, never the repo's storage/. Removed again at exit.
 */

$__isoDir = sys_get_temp_dir() . '/osmh-iso-' . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($__isoDir, 0700, true);
App\Osm\OsmRateGuard::useDirectory($__isoDir . '/osm-rate');
App\Osm\OsmCache::useDirectory($__isoDir . '/osm-cache');
register_shutdown_function(static function () use ($__isoDir): void {
    if (!is_dir($__isoDir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($__isoDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        @chmod($f->getPathname(), 0700);
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($__isoDir);
});
