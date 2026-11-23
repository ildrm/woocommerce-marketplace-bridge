<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Infrastructure;

final class Database {
    public const VERSION = '1';
    public static function table(string $name): string { global $wpdb; return $wpdb->prefix . 'mbwc_' . $name; }
    public static function migrate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $maps = self::table('mappings');
        $logs = self::table('logs');
        dbDelta("CREATE TABLE $maps (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(40) NOT NULL,
            account varchar(100) NOT NULL DEFAULT 'default',
            wc_product_id bigint(20) unsigned NOT NULL,
            external_variant_id varchar(100) NOT NULL,
            external_product_id varchar(100) NOT NULL DEFAULT '',
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_wc (provider,account,wc_product_id),
            UNIQUE KEY provider_external (provider,account,external_variant_id),
            KEY wc_product_id (wc_product_id)
        ) $charset;");
        $attributions = self::table('attribution');
        dbDelta("CREATE TABLE $attributions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            click_id varchar(128) NOT NULL,
            purchase_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            KEY purchase_at (purchase_at)
        ) $charset;");
        $actions = self::table('actions');
        dbDelta("CREATE TABLE $actions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            click_id varchar(128) NOT NULL,
            action_type varchar(80) NOT NULL,
            status varchar(20) NOT NULL,
            happened_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY happened_at (happened_at)
        ) $charset;");
        dbDelta("CREATE TABLE $logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(40) NOT NULL,
            level varchar(20) NOT NULL,
            action varchar(100) NOT NULL,
            status varchar(30) NOT NULL,
            detail text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY provider_date (provider,created_at),
            KEY status (status)
        ) $charset;");
        update_option('mbwc_schema_version', self::VERSION, false);
    }
}
