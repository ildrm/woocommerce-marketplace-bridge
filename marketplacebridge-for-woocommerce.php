<?php
/**
 * Plugin Name: MarketplaceBridge for WooCommerce
 * Description: Provider-based WooCommerce integration for Torob product feed and Digikala seller APIs.
 * Version: 0.2.0-alpha
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * WC requires at least: 8.2
 * Text Domain: marketplacebridge-for-woocommerce
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

define('MBWC_VERSION', '0.2.0-alpha');
define('MBWC_FILE', __FILE__);
define('MBWC_PATH', plugin_dir_path(__FILE__));
define('MBWC_URL', plugin_dir_url(__FILE__));

add_action('init', static function (): void {
    load_plugin_textdomain('marketplacebridge-for-woocommerce', false, dirname(plugin_basename(__FILE__)).'/languages');
}, 0);

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'MarketplaceBridge\\WooCommerce\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
    $relative = substr($class, strlen($prefix));
    $file = MBWC_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) { require_once $file; }
});

register_activation_hook(__FILE__, static function (): void {
    if (!class_exists('WooCommerce')) { return; }
    \MarketplaceBridge\WooCommerce\Infrastructure\Database::migrate();
    \MarketplaceBridge\WooCommerce\Providers\Torob\Feed::add_rewrite_rule();
    add_rewrite_rule('^torob/v1/orders/?$', 'index.php?mbwc_torob_orders=1', 'top');
    add_rewrite_rule('^torob/v1/actions/?$', 'index.php?mbwc_torob_actions=1', 'top');
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('mbwc_daily_cleanup');
    wp_clear_scheduled_hook(\MarketplaceBridge\WooCommerce\Providers\Torob\Webhook::HOOK);
    delete_option('rewrite_rules');
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>' . esc_html__('MarketplaceBridge requires WooCommerce.', 'marketplacebridge-for-woocommerce') . '</p></div>';
            }
        });
        return;
    }
    \MarketplaceBridge\WooCommerce\Core\Plugin::instance()->boot();
}, 20);
