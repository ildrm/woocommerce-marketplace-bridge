<?php
/** Securely remove credentials. Purge data only with explicit administrator configuration. */
declare(strict_types=1);
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
$accounts=get_option('mbwc_accounts_digikala',[]);
if (is_array($accounts)) {
    foreach (array_keys($accounts) as $id) {
        if (is_string($id) && preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/D',$id)) {
            delete_option('mbwc_secret_digikala_access_token_'.$id);
        }
    }
}
foreach (['mbwc_secret_torob_shop_token','mbwc_secret_torob_webhook_token','mbwc_secret_digikala_access_token'] as $key) { delete_option($key); }
delete_option('mbwc_accounts_digikala');
delete_option('mbwc_digikala_spec');
wp_clear_scheduled_hook('mbwc_daily_cleanup');
wp_clear_scheduled_hook('mbwc_torob_flush_queue');
wp_unschedule_hook('mbwc_torob_notify');
if (function_exists('as_unschedule_all_actions')) { as_unschedule_all_actions('mbwc_torob_notify',[], 'marketplacebridge'); }
global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS `'.esc_sql($wpdb->prefix.'mbwc_torob_queue').'`');
delete_option('mbwc_schema_version');
if (defined('MBWC_PURGE_ON_UNINSTALL') && MBWC_PURGE_ON_UNINSTALL === true) {
    foreach (['mappings','logs','attribution','actions'] as $name) {
        $table=$wpdb->prefix.'mbwc_'.$name;
        $wpdb->query('DROP TABLE IF EXISTS `'.esc_sql($table).'`');
    }
    foreach (['mbwc_torob_enabled','mbwc_torob_webhooks','mbwc_torob_attribution','mbwc_torob_actions','mbwc_torob_order_details','mbwc_torob_share_phone','mbwc_currency_unit','mbwc_digikala_seller_code','mbwc_log_retention_days'] as $key) { delete_option($key); }
}
