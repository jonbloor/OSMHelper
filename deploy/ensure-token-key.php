<?php

declare(strict_types=1);

/**
 * Make sure .env has OSMHELPER_TOKEN_KEY (32 random bytes, base64). Run with cwd = app dir.
 * Only fills a missing/empty value; never replaces an existing key (that would make saved tokens
 * unreadable). Never prints the key.
 */
$envPath = getcwd() . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, ".env not found in " . getcwd() . "\n");
    exit(1);
}
$t = (string) file_get_contents($envPath);
if (preg_match('/^OSMHELPER_TOKEN_KEY=\s*\S+/m', $t) === 1) {
    echo "OSMHELPER_TOKEN_KEY already set\n";
    exit(0);
}
$line = 'OSMHELPER_TOKEN_KEY=' . base64_encode(random_bytes(32));
if (preg_match('/^OSMHELPER_TOKEN_KEY=\s*$/m', $t) === 1) {
    $t = (string) preg_replace('/^OSMHELPER_TOKEN_KEY=\s*$/m', $line, $t, 1);
} else {
    $t = rtrim($t) . "\n" . $line . "\n";
}
file_put_contents($envPath, $t);
echo "OSMHELPER_TOKEN_KEY set\n";
