<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Infrastructure;

use WP_Error;

/** Independent seller connections with support for existing default credentials. */
final class Accounts {
    public const LOCK = 'digikala_accounts';

    public static function validId(string $id): bool {
        return (bool)preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/D', $id);
    }

    public static function all(string $provider): array {
        if (!in_array($provider, ['digikala', 'torob'], true)) { return []; }
        $value = get_option('mbwc_accounts_'.$provider, []);
        $accounts = is_array($value) ? $value : [];
        $default = $accounts['default'] ?? ['label'=>'Default', 'seller_code'=>''];
        if ($provider === 'digikala') {
            $default['seller_code'] = (string)get_option('mbwc_digikala_seller_code', $default['seller_code']);
        }
        return ['default'=>$default] + $accounts;
    }

    public static function exists(string $provider, string $id): bool {
        return isset(self::all($provider)[$id]);
    }

    public static function save(string $provider, string $id, string $label, string $sellerCode, string $token, bool $clear = false,
        bool $create = false): bool|WP_Error {
        if ($provider !== 'digikala' || !self::validId($id) || $label === '' || strlen($label) > 360
            || !preg_match('/^[A-Za-z0-9_-]{0,60}$/D', $sellerCode) || ($clear && $token !== '') || !Secrets::valid_token($token)) {
            return new WP_Error('mbwc_account', 'Invalid Digikala account configuration.');
        }
        return Database::locked(self::LOCK, static function () use ($id, $label, $sellerCode, $token, $clear, $create): bool|WP_Error {
            $accounts = self::all('digikala');
            $known = isset($accounts[$id]);
            if ($create && $known) { return new WP_Error('mbwc_account_exists', 'This account ID already exists. Open it to edit.'); }
            foreach (array_keys($accounts) as $existingId) {
                if ($id !== $existingId && strcasecmp($id, $existingId) === 0) {
                    return new WP_Error('mbwc_account', 'Account IDs must be unique regardless of letter case.');
                }
            }
            if (!$known && count($accounts) >= 21) {
                return new WP_Error('mbwc_accounts', 'Maximum 20 additional accounts allowed.');
            }
            $previousCode = $accounts[$id]['seller_code'] ?? '';
            if ($previousCode !== '' && $previousCode !== $sellerCode && self::hasMappings($id)) {
                return new WP_Error('mbwc_account_mappings', 'Use a separate account for a different seller while product mappings exist.');
            }

            $field = self::token_field($id);
            $option = Secrets::option_name('digikala', $field);
            $savedSecret = get_option($option, false);
            $changeSecret = !$known || $clear || $token !== '';
            if ($previousCode !== '' && $previousCode !== $sellerCode && $savedSecret !== false && !$changeSecret) {
                return new WP_Error('mbwc_account_token', 'Replace or remove the token when changing the seller code.');
            }
            if ($changeSecret && !Secrets::set('digikala', $field, $token)) {
                return new WP_Error('mbwc_secret', 'Could not save the seller token.');
            }
            $next = $accounts;
            $next[$id] = ['label'=>$label, 'seller_code'=>$sellerCode];
            if ($next !== $accounts && !self::persist('mbwc_accounts_digikala', $next)) {
                if ($changeSecret) { self::restore($option, $savedSecret); }
                return false;
            }
            if ($id === 'default' && !self::persist('mbwc_digikala_seller_code', $sellerCode)) {
                if ($next !== $accounts) { self::persist('mbwc_accounts_digikala', $accounts); }
                if ($changeSecret) { self::restore($option, $savedSecret); }
                return false;
            }
            return true;
        });
    }

    public static function remove(string $provider, string $id): bool {
        if ($provider !== 'digikala' || $id === 'default' || !self::validId($id)) { return false; }
        return Database::locked(self::LOCK, static function () use ($id): bool {
            $accounts = self::all('digikala');
            if (!isset($accounts[$id]) || self::hasMappings($id)) { return false; }
            $field = self::token_field($id);
            $option = Secrets::option_name('digikala', $field);
            $savedSecret = get_option($option, false);
            if (!Secrets::set('digikala', $field, '')) { return false; }
            unset($accounts[$id]);
            if (self::persist('mbwc_accounts_digikala', $accounts)) { return true; }
            self::restore($option, $savedSecret);
            return false;
        }) === true;
    }

    public static function hasMappings(string $id): bool {
        global $wpdb;
        $table = Database::table('mappings');
        $found = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE provider='digikala' AND account=%s LIMIT 1", $id));
        return (bool)$found || !empty($wpdb->last_error);
    }

    public static function token(string $provider, string $id = 'default'): string {
        if (!self::exists($provider, $id)) { return ''; }
        return Secrets::get($provider, $provider === 'digikala' ? self::token_field($id) : 'shop_token');
    }

    public static function sellerCode(string $id = 'default'): string {
        return (string)(self::all('digikala')[$id]['seller_code'] ?? '');
    }

    public static function credentials(string $id): array|WP_Error {
        return Database::locked(self::LOCK, static function () use ($id): array|WP_Error {
            $account = self::all('digikala')[$id] ?? null;
            if (!$account) { return new WP_Error('mbwc_account', 'Unknown Digikala account.'); }
            $token = Secrets::get('digikala', self::token_field($id));
            if ($token === '') { return new WP_Error('mbwc_auth', 'Configure this seller account token first.'); }
            return ['token'=>$token, 'seller_code'=>$account['seller_code']];
        });
    }

    private static function token_field(string $id): string {
        return $id === 'default' ? 'access_token' : 'access_token_'.$id;
    }

    private static function persist(string $key, mixed $value): bool {
        return update_option($key, $value, false) || get_option($key) === $value;
    }

    private static function restore(string $key, mixed $value): void {
        if ($value === false) { delete_option($key); }
        else { self::persist($key, $value); }
    }
}
