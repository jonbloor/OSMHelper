<?php

declare(strict_types=1);

namespace App\Store;

use App\Config;

/**
 * Encrypts OSM tokens at rest (libsodium secretbox, key from OSMHELPER_TOKEN_KEY).
 *
 * Stored format: "sb1:" . base64(nonce . ciphertext). Anything without that prefix is a legacy
 * plaintext value: it is still read as-is, and WordpressSiteKeyStore re-saves it encrypted.
 *
 * Fail safe: if the key is missing/invalid (or sodium is unavailable) the site keeps working —
 * encrypt() returns the value unchanged and logs a reason code; decrypt() of an encrypted value then
 * returns null (the form shows "sign in and save the form again"). Values and keys are never logged.
 */
final class TokenCrypto
{
    public const PREFIX = 'sb1:';

    /** Reasons already logged this request (log each once). */
    private static array $logged = [];

    /** Test helper: override the configured key (base64 string, '' = no key, null = use config). */
    private static ?string $keyOverride = null;

    public static function setKeyForTests(?string $base64Key): void
    {
        self::$keyOverride = $base64Key;
        self::$logged = [];
    }

    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /** True when a usable key is configured (so values can be encrypted and decrypted). */
    public static function available(): bool
    {
        return self::key() !== null;
    }

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '' || self::isEncrypted($plain)) {
            return $plain;
        }
        $key = self::key();
        if ($key === null) {
            return $plain;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plain, $nonce, $key);
        return self::PREFIX . base64_encode($nonce . $box);
    }

    /** Plaintext passes through unchanged; encrypted values are opened, or null if that is impossible. */
    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || !self::isEncrypted($stored)) {
            return $stored;
        }
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            self::logOnce('token_decrypt_malformed');
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        if ($plain === false) {
            self::logOnce('token_decrypt_failed');
            return null;
        }
        return $plain;
    }

    private static function key(): ?string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            self::logOnce('token_sodium_missing');
            return null;
        }
        $b64 = self::$keyOverride ?? Config::tokenKey();
        if ($b64 === null || $b64 === '') {
            self::logOnce('token_key_missing');
            return null;
        }
        $key = base64_decode($b64, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            self::logOnce('token_key_invalid');
            return null;
        }
        return $key;
    }

    private static function logOnce(string $reason): void
    {
        if (isset(self::$logged[$reason])) {
            return;
        }
        self::$logged[$reason] = true;
        error_log('OSMHelper token encryption: ' . $reason);
    }
}
