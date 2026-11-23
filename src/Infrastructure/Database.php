<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Infrastructure;

final class Database {
    public const VERSION = '3';
    public static function table(string $name): string { global $wpdb; return $wpdb->prefix . 'mbwc_' . $name; }
    public static function locked(string $scope, callable $work, int $timeout = 2): mixed {
        global $wpdb;
        $lock = 'mbwc_'.substr(hash('sha256', DB_NAME.'.'.$wpdb->prefix.'.'.$scope), 0, 48);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $lock, $timeout)) !== 1) {
            return new \WP_Error('mbwc_busy', 'Another operation is in progress. Try again.');
        }
        try {
            return $work();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
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
        $queue = self::table('torob_queue');
        dbDelta("CREATE TABLE $queue (
            product_id bigint(20) unsigned NOT NULL,
            revision bigint(20) unsigned NOT NULL DEFAULT 1,
            due_at bigint(20) unsigned NOT NULL,
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            previous_url text NOT NULL,
            last_error varchar(40) NOT NULL DEFAULT '',
            PRIMARY KEY  (product_id),
            KEY due_at (due_at,product_id)
        ) $charset;");
        foreach ([$maps, $logs, $attributions, $actions, $queue] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                return;
            }
        }
        if (!$wpdb->get_var("SHOW COLUMNS FROM $queue LIKE 'last_error'")) { return; }
        update_option('mbwc_schema_version', self::VERSION, false);
    }
}
