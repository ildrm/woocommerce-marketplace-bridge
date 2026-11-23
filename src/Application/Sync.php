<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Application;

use MarketplaceBridge\WooCommerce\Infrastructure\Database;
use MarketplaceBridge\WooCommerce\Infrastructure\Accounts;

/** Safe mapping management. Deliberately does not write unverified seller-variant payloads. */
final class Sync {
    public function register(): void {
        // Future providers can hook into this core without changing existing provider modules.
        do_action('mbwc_sync_engine_registered', $this);
    }
    public static function add_mapping(int $wcId, string $externalId, string $externalProduct='', string $account='default'): bool|\WP_Error {
        global $wpdb;
        if (!Accounts::exists('digikala',$account)) { return new \WP_Error('mbwc_account','Unknown Digikala account'); }
        if ($wcId<=0 || !$externalId || !preg_match('/^[0-9]{1,32}$/D', $externalId)) {
            return new \WP_Error('mbwc_validation','Invalid product or Digikala variant ID');
        }
        $product = wc_get_product($wcId);
        if (!$product || $product->is_type('variable')) { return new \WP_Error('mbwc_product','Map a simple product or individual variation'); }
        $table=Database::table('mappings');
        $current = $wpdb->get_var($wpdb->prepare("SELECT wc_product_id FROM $table WHERE provider='digikala' AND account=%s AND external_variant_id=%s",$account,$externalId));
        if ($current && (int)$current!==$wcId) { return new \WP_Error('mbwc_duplicate','Variant already maps to a different WooCommerce product'); }
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE provider='digikala' AND account=%s AND wc_product_id=%d",$account,$wcId));
        $data=['provider'=>'digikala','account'=>$account,'wc_product_id'=>$wcId,'external_variant_id'=>$externalId,
               'external_product_id'=>sanitize_text_field($externalProduct),'updated_at'=>gmdate('Y-m-d H:i:s')];
        if ($existing) { return $wpdb->update($table,$data,['id'=>(int)$existing])!==false; }
        return $wpdb->insert($table,$data)!==false;
    }
    public static function mappings(int $limit=100): array {
        global $wpdb;
        $table=Database::table('mappings');
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d",min(500,max(1,$limit))),ARRAY_A)?:[];
    }
    public static function hasAccountMappings(string $account): bool {
        global $wpdb;
        $table=Database::table('mappings');
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE provider='digikala' AND account=%s",$account))>0;
    }
    public static function remove_mapping(int $id): bool {
        global $wpdb;
        return $wpdb->delete(Database::table('mappings'),['id'=>$id],['%d'])!==false;
    }
}
