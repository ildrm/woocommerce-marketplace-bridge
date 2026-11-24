<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Application;

use MarketplaceBridge\WooCommerce\Infrastructure\Database;
use MarketplaceBridge\WooCommerce\Infrastructure\Accounts;
use WC_Product;
use WP_Error;

final class Sync {
    public function register(): void {
        do_action('mbwc_sync_engine_registered', $this);
    }

    public static function can_map(WC_Product $product): bool {
        return $product->get_status('edit') !== 'trash'
            && ($product->is_type('simple') || $product->is_type('variation'));
    }

    public static function add_mapping(
        int $wcId,
        string $externalId,
        string $externalProduct = '',
        string $account = 'default',
        ?int $editId = null,
        ?string $revision = null
    ): bool|WP_Error {
        if ($wcId <= 0 || !preg_match('/^[1-9][0-9]{0,31}$/D', $externalId)
            || ($externalProduct !== '' && !preg_match('/^(?:DKP-)?[1-9][0-9]{0,31}$/iD', $externalProduct))) {
            return new WP_Error('mbwc_validation', 'Enter a positive Digikala variant ID and, optionally, a valid DKP.');
        }
        $product = wc_get_product($wcId);
        if (!$product || !self::can_map($product)) {
            return new WP_Error('mbwc_product', 'Select a simple WooCommerce product or an individual variation.');
        }

        return Database::locked(Accounts::LOCK, static function () use ($wcId, $externalId, $externalProduct, $account, $editId, $revision): bool|WP_Error {
            global $wpdb;
            if (!Accounts::exists('digikala', $account)) { return new WP_Error('mbwc_account', 'Unknown Digikala account.'); }
            $table = Database::table('mappings');
            // Previous builds allowed left-padded variant IDs. They still identify the same variant.
            $duplicates = $wpdb->get_col($wpdb->prepare("SELECT wc_product_id FROM $table WHERE provider='digikala'
                AND account=%s AND (external_variant_id=%s OR external_variant_id REGEXP %s)",
                $account, $externalId, '^0+'.$externalId.'$'));
            if ($wpdb->last_error) { return new WP_Error('mbwc_mapping_db', 'Could not read product mappings.'); }
            foreach ($duplicates as $owner) {
                if ((int)$owner !== $wcId) { return new WP_Error('mbwc_duplicate', 'This variant is already mapped to another WooCommerce product.'); }
            }
            $row = $wpdb->get_row($wpdb->prepare("SELECT id, external_variant_id, external_product_id, updated_at FROM $table
                WHERE provider='digikala' AND account=%s AND wc_product_id=%d", $account, $wcId), ARRAY_A);
            $existing = (int)($row['id'] ?? 0);
            if ($wpdb->last_error) { return new WP_Error('mbwc_mapping_db', 'Could not read product mappings.'); }
            if (($editId !== null && $existing !== $editId) || ($revision !== null && (!$row || !hash_equals(self::revision($row), $revision)))) {
                return new WP_Error('mbwc_mapping_conflict', 'The mapping already exists or changed. Open it from the list to edit it.');
            }
            $data = [
                'provider'=>'digikala', 'account'=>$account, 'wc_product_id'=>$wcId,
                'external_variant_id'=>$externalId, 'external_product_id'=>strtoupper($externalProduct),
                'updated_at'=>gmdate('Y-m-d H:i:s'),
            ];
            return $existing ? $wpdb->update($table, $data, ['id'=>$existing]) !== false : $wpdb->insert($table, $data) !== false;
        });
    }

    public static function revision(array $row): string {
        return hash('sha256', implode('|', [(string)$row['id'], $row['external_variant_id'], $row['external_product_id'], $row['updated_at']]));
    }

    public static function mappings(int $limit = 100): array {
        global $wpdb;
        $table = Database::table('mappings');
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d",
            min(500, max(1, $limit))), ARRAY_A) ?: [];
    }

    public static function list_mappings(int $page, string $account = '', int $limit = 25): array|WP_Error {
        global $wpdb;
        $limit = min(500, max(1, $limit));
        $table = Database::table('mappings');
        $where = "WHERE provider='digikala'";
        if ($account !== '') { $where .= $wpdb->prepare(' AND account=%s', $account); }
        $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table $where");
        if ($wpdb->last_error) { return new WP_Error('mbwc_mapping_db', 'Could not read product mappings.'); }
        $pages = max(1, (int)ceil($count / $limit));
        $page = min(max(1, $page), $pages);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table $where ORDER BY id DESC LIMIT %d OFFSET %d",
            $limit, ($page - 1) * $limit), ARRAY_A);
        if ($wpdb->last_error) { return new WP_Error('mbwc_mapping_db', 'Could not read product mappings.'); }
        return ['rows'=>$rows ?: [], 'count'=>$count, 'pages'=>$pages, 'page'=>$page];
    }

    public static function mapping(int $id): ?array {
        global $wpdb;
        $table = Database::table('mappings');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE provider='digikala' AND id=%d", $id), ARRAY_A);
    }

    public static function hasAccountMappings(string $account): bool {
        return Accounts::hasMappings($account);
    }

    public static function remove_mapping(int $id, ?string $revision = null): bool {
        if ($id <= 0) { return false; }
        return Database::locked(Accounts::LOCK, static function () use ($id, $revision): bool {
            global $wpdb;
            if ($revision !== null) {
                $row = self::mapping($id);
                if (!$row || !hash_equals(self::revision($row), $revision)) { return false; }
            }
            return $wpdb->delete(Database::table('mappings'), ['id'=>$id, 'provider'=>'digikala'], ['%d', '%s']) === 1;
        }) === true;
    }
}
