<?php

declare(strict_types=1);

/**
 * Make sure .env has OSMHELPER_TOKEN_KEY (32 random bytes, base64). Run with cwd = app dir.
 * Only fills a missing/empty value; never replaces an existing key (that would make saved tokens
 * unreadable). Never prints the key.
 */

/**
 * True when the .env text already has a non-empty OSMHELPER_TOKEN_KEY (not "" or '').
 * Dotenv (immutable) keeps the first definition, so the first line decides.
 */
function osmhelper_token_key_present(string $env): bool
{
    // [^\S\n]* = spaces/tabs only, so an empty line can't borrow the next line's value.
    if (preg_match('/^OSMHELPER_TOKEN_KEY=[^\S\n]*([^\n]*)$/m', $env, $m) !== 1) {
        return false;
    }
    $v = trim($m[1]);
    return $v !== '' && $v !== '""' && $v !== "''";
}

/** .env text with the key filled in (replacing an empty/quoted-empty line, or appended). */
function osmhelper_with_token_key(string $env, string $key): string
{
    $line = 'OSMHELPER_TOKEN_KEY=' . $key;
    $emptyLine = '/^OSMHELPER_TOKEN_KEY=[^\S\n]*(?:""|\'\')?[^\S\n]*\r?$/m';
    if (preg_match($emptyLine, $env) === 1) {
        // Drop every empty duplicate, then add one real line.
        $env = (string) preg_replace($emptyLine, '', $env);
        $env = (string) preg_replace("/\n{3,}/", "\n\n", $env);
    }
    return rtrim($env, "\r\n") . "\n" . $line . "\n";
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return; // included by tests: functions only
}

$envPath = getcwd() . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, ".env not found in " . getcwd() . "\n");
    exit(1);
}
$t = (string) file_get_contents($envPath);
if (osmhelper_token_key_present($t)) {
    echo "OSMHELPER_TOKEN_KEY already set\n";
    exit(0);
}
file_put_contents($envPath, osmhelper_with_token_key($t, base64_encode(random_bytes(32))));
echo "OSMHELPER_TOKEN_KEY set\n";
