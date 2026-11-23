<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Infrastructure;

/** Encrypted settings; secrets are never returned to browser responses. */
final class Secrets {
    private static function key(): string {
        if (defined('MBWC_ENCRYPTION_KEY') && is_string(MBWC_ENCRYPTION_KEY) && MBWC_ENCRYPTION_KEY !== '') {
            return hash('sha256', MBWC_ENCRYPTION_KEY, true);
        }
        return hash('sha256', wp_salt('auth') . wp_salt('secure_auth') . 'mbwc-keys-v1', true);
    }
    public static function set(string $provider, string $field, string $value): bool {
        if (!in_array($provider, ['torob','digikala'], true) || !preg_match('/^[a-z][a-z0-9_]{0,90}$/D', $field)) { return false; }
        if ($value === '') { delete_option('mbwc_secret_' . $provider . '_' . $field); return true; }
        if (!function_exists('openssl_encrypt')) { return false; }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) { return false; }
        return update_option('mbwc_secret_' . $provider . '_' . $field, base64_encode($iv . $tag . $ciphertext), false);
    }
    public static function get(string $provider, string $field): string {
        $encoded = get_option('mbwc_secret_' . $provider . '_' . $field, '');
        if (!is_string($encoded) || $encoded === '' || !function_exists('openssl_decrypt')) { return ''; }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) { return ''; }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return is_string($plain) ? $plain : '';
    }
    public static function exists(string $provider, string $field): bool { return self::get($provider, $field) !== ''; }
}
