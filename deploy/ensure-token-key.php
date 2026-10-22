<?php

declare(strict_types=1);

/**
 * Make sure .env has a usable OSMHELPER_TOKEN_KEY (32 random bytes, base64). Run with cwd = app dir.
 *
 * - No definition line at all: appends a new random key (written via temp file + rename, keeping the
 *   file's owner, group and mode) and prints "OSMHELPER_TOKEN_KEY set".
 * - Defined with a valid value: changes nothing, prints "OSMHELPER_TOKEN_KEY already set".
 * - Defined (any form: export, leading spaces, "KEY = x", duplicates) but the effective value is empty
 *   or not a 32-byte base64 key: changes nothing and exits 1. Never appends a second definition,
 *   because whichever line wins could leave saved tokens unreadable. Fix .env by hand.
 *
 * The effective value is what the app sees: Dotenv::parse($text)['OSMHELPER_TOKEN_KEY'], trimmed
 * (the last definition wins, as Dotenv does). Never prints the key or any .env text.
 */

const OSMHELPER_TOKEN_KEY_NAME = 'OSMHELPER_TOKEN_KEY';

/** True when any line defines the key (incl. "export ", leading blanks, blanks around "="). */
function osmhelper_token_key_defined(string $env): bool
{
    return preg_match('/^[ \t]*(?:export[ \t]+)?OSMHELPER_TOKEN_KEY[ \t]*=/m', $env) === 1;
}

/** A real key: base64 of exactly 32 bytes. */
function osmhelper_token_key_valid(string $value): bool
{
    $raw = base64_decode(trim($value), true);
    return is_string($raw) && strlen($raw) === 32;
}

/**
 * 'set' (valid effective value), 'missing' (no definition), 'invalid' (defined but empty/invalid),
 * or 'unparseable' (Dotenv can't parse the file).
 */
function osmhelper_token_key_status(string $env): string
{
    try {
        $parsed = \Dotenv\Dotenv::parse($env);
    } catch (\Throwable $e) {
        return 'unparseable'; // never echo the exception: its message can quote .env text
    }
    $defined = osmhelper_token_key_defined($env) || array_key_exists(OSMHELPER_TOKEN_KEY_NAME, $parsed);
    if (!$defined) {
        return 'missing';
    }
    $value = trim((string) ($parsed[OSMHELPER_TOKEN_KEY_NAME] ?? ''));
    return osmhelper_token_key_valid($value) ? 'set' : 'invalid';
}

/** .env text with one key line appended (only used when no definition exists). */
function osmhelper_with_token_key(string $env, string $key): string
{
    $eol = str_contains($env, "\r\n") ? "\r\n" : "\n";
    $env = rtrim($env, "\r\n");
    return ($env === '' ? '' : $env . $eol) . OSMHELPER_TOKEN_KEY_NAME . '=' . $key . $eol;
}

/** Write via a temp file in the same dir + rename, keeping owner, group and mode. */
function osmhelper_write_env_atomic(string $path, string $text): void
{
    clearstatcache(true, $path);
    $st = @stat($path);
    $dir = dirname($path);
    $tmp = $dir . '/.env.tmp-' . bin2hex(random_bytes(6));
    $old = umask(077);
    try {
        if (file_put_contents($tmp, $text, LOCK_EX) !== strlen($text)) {
            throw new \RuntimeException('could not write temp file');
        }
        if ($st !== false) {
            $mode = $st['mode'] & 07777;
            if (!chmod($tmp, $mode)) {
                throw new \RuntimeException('could not set mode');
            }
            if (fileowner($tmp) !== $st['uid'] && !@chown($tmp, $st['uid'])) {
                throw new \RuntimeException('could not keep owner');
            }
            if (filegroup($tmp) !== $st['gid'] && !@chgrp($tmp, $st['gid'])) {
                throw new \RuntimeException('could not keep group');
            }
        }
        if (!rename($tmp, $path)) {
            throw new \RuntimeException('could not replace .env');
        }
    } catch (\Throwable $e) {
        @unlink($tmp);
        throw $e;
    } finally {
        umask($old);
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return; // included by tests: functions only
}

// The app's vendor/ (cwd when run from the app dir, or next to deploy/ when shipped with the app).
foreach ([getcwd() . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $autoload) {
    if (!class_exists(\Dotenv\Dotenv::class) && is_file($autoload)) {
        require $autoload;
    }
}
if (!class_exists(\Dotenv\Dotenv::class)) {
    fwrite(STDERR, "ensure-token-key: vendor/autoload.php (vlucas/phpdotenv) not found in " . getcwd() . "\n");
    exit(1);
}
$envPath = getcwd() . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, ".env not found in " . getcwd() . "\n");
    exit(1);
}
$t = (string) file_get_contents($envPath);
switch (osmhelper_token_key_status($t)) {
    case 'set':
        echo "OSMHELPER_TOKEN_KEY already set\n";
        exit(0);
    case 'invalid':
        fwrite(STDERR, "OSMHELPER_TOKEN_KEY is defined in .env but its value is empty or not a 32-byte base64 key.\n"
            . "Nothing was changed. Fix .env by hand (keep the original key if tokens were saved with it; see docs/DEPLOY-RUNBOOK.md).\n");
        exit(1);
    case 'unparseable':
        fwrite(STDERR, ".env could not be parsed by Dotenv; nothing was changed. Fix .env by hand.\n");
        exit(1);
}
try {
    osmhelper_write_env_atomic($envPath, osmhelper_with_token_key($t, base64_encode(random_bytes(32))));
} catch (\Throwable $e) {
    fwrite(STDERR, "ensure-token-key: " . $e->getMessage() . "; .env unchanged\n");
    exit(1);
}
echo "OSMHELPER_TOKEN_KEY set\n";
