<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * At-rest encryption for the raw HLS AES-128 key stored in post meta.
 * Keyed off a hash of the site's AUTH_KEY, so the DB alone (a leaked
 * export, a compromised read-replica) doesn't hand out playable keys —
 * an attacker also needs the wp-config.php secret.
 */
final class SPLY_Crypto
{
    const CIPHER = 'aes-256-cbc';

    private static function derive_key(): string
    {
        $secret = defined('AUTH_KEY') ? AUTH_KEY : '';

        // A fresh WordPress install that was never given real secrets
        // still defines AUTH_KEY as the literal placeholder string. Fall
        // back to a per-site value derived from the site URL so encryption
        // still works (rather than fatal-erroring), while a site admin who
        // installs real salts automatically gets a stronger derived key.
        if ($secret === '' || strpos($secret, 'put your unique phrase here') !== false) {
            $secret = 'sply-fallback-' . site_url();
        }

        return hash('sha256', $secret, true);
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::derive_key();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::CIPHER));
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $ciphertext);
    }

    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($raw, 0, $ivLength);
        $ciphertext = substr($raw, $ivLength);

        $key = self::derive_key();
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        return $plaintext === false ? null : $plaintext;
    }
}
