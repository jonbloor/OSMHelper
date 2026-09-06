<?php

declare(strict_types=1);

/**
 * CloudPanel docroot entry: /home/osmhelper/htdocs/osmhelper.co.uk/index.php
 * App code lives outside the public htdocs tree.
 *
 * Stubs under auth/, members/, … set OSMHELPER_PATH explicitly. For bare paths
 * like /callback (OSM redirect_uri, no trailing slash), nginx must hit THIS
 * file — not a directory redirect to http://…/callback/ which drops the Secure
 * session cookie and breaks OAuth state (fail-closed).
 */
if (empty($_SERVER['OSMHELPER_PATH'])) {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $_SERVER['OSMHELPER_PATH'] = is_string($path) && $path !== '' ? $path : '/';
}

require '/home/osmhelper/app/public/index.php';
