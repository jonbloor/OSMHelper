<?php

declare(strict_types=1);

use App\Store\TokenCrypto;
use App\Store\WordpressSiteKeyStore;

/**
 * One-off: encrypt the OSM tokens already saved for the WordPress joining form
 * (wordpress_site_keys.access_token / refresh_token) with OSMHELPER_TOKEN_KEY.
 * Safe to re-run: rows that are already encrypted are left alone. Prints counts only, never tokens.
 *
 * Run on the server from the app dir (not web-reachable: bin/ is outside the docroot):
 *   cd /home/osmhelper/app && (umask 007; php bin/encrypt-site-tokens.php)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

if (!TokenCrypto::available()) {
    fwrite(STDERR, "OSMHELPER_TOKEN_KEY is missing or invalid (needs 32 random bytes, base64). Nothing changed.\n");
    exit(1);
}

try {
    $r = WordpressSiteKeyStore::encryptExistingRows();
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . get_class($e) . "\n");
    exit(1);
}
echo 'wordpress_site_keys rows: ' . $r['rows'] . ', encrypted now: ' . $r['encrypted'] . ', already encrypted: ' . $r['already'] . "\n";
exit(0);
