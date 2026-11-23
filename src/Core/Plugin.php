<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Core;

use MarketplaceBridge\WooCommerce\Infrastructure\Database;
use MarketplaceBridge\WooCommerce\Infrastructure\Maintenance;
use MarketplaceBridge\WooCommerce\Providers\Torob\Provider as TorobProvider;
use MarketplaceBridge\WooCommerce\Providers\Torob\Feed;
use MarketplaceBridge\WooCommerce\Providers\Torob\Webhook;
use MarketplaceBridge\WooCommerce\Providers\Torob\Attribution;
use MarketplaceBridge\WooCommerce\Providers\Digikala\Provider as DigikalaProvider;
use MarketplaceBridge\WooCommerce\Application\Sync;
use MarketplaceBridge\WooCommerce\Admin\Dashboard;

final class Plugin {
    private static ?self $instance = null;
    private Registry $registry;
    public static function instance(): self { return self::$instance ??= new self(); }
    public function registry(): Registry { return $this->registry; }
    public function boot(): void {
        $this->registry = new Registry();
        $this->registry->register(new TorobProvider());
        $this->registry->register(new DigikalaProvider());
        /** External providers must implement ProviderInterface. */
        do_action('mbwc_register_providers', $this->registry);
        if (get_option('mbwc_schema_version') !== Database::VERSION && !get_transient('mbwc_migration_pending')) {
            set_transient('mbwc_migration_pending', 1, MINUTE_IN_SECONDS);
            Database::migrate();
        }
        (new Feed())->register();
        (new Webhook())->register();
        (new Attribution())->register();
        (new Sync())->register();
        (new Maintenance())->register();
        if (is_admin()) {
            (new Dashboard($this->registry))->register();
            add_action('admin_notices',static function (): void {
                if (!current_user_can('manage_options')) { return; }
                foreach ((array)get_option('active_plugins',[]) as $file) {
                    if (is_string($file) && str_starts_with($file,'products-extractor-for-woocommerce/')) {
                        echo '<div class="notice notice-warning"><p>'.esc_html__('MarketplaceBridge detected the official Torob product extractor. Disable one overlapping Torob product feed to avoid conflicting endpoints and duplicate notifications.','marketplacebridge-for-woocommerce').'</p></div>';
                        break;
                    }
                }
            });
        }
        add_action('init', [Feed::class, 'add_rewrite_rule']);
    }
}
