<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Providers\Torob;

use MarketplaceBridge\WooCommerce\Infrastructure\Secrets;
use MarketplaceBridge\WooCommerce\Infrastructure\Logger;

/** Bounded, idempotent-enough change notifications. Webhook acknowledgment != indexed product. */
final class Webhook {
    private const MAX_ATTEMPTS=5;
    public function register(): void {
        foreach (['woocommerce_update_product','woocommerce_update_product_variation','woocommerce_new_product','woocommerce_new_product_variation'] as $hook) {
            add_action($hook,[$this,'changed'],10,1);
        }
        add_action('mbwc_torob_notify',[$this,'deliver'],10,2);
    }
    public function changed(int $id): void {
        if (get_option('mbwc_torob_webhooks','0')!=='1' || !Secrets::exists('torob','webhook_token')) { return; }
        $product=wc_get_product($id);
        if (!$product) { return; }
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $child) { $this->schedule((int)$child,0,40); }
        } else { $this->schedule($id,0,40); }
    }
    private function schedule(int $id,int $attempt,int $delay,bool $force=false): void {
        $args=[$id,$attempt];
        if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_single_action')) {
            if ($force || !as_has_scheduled_action('mbwc_torob_notify',$args,'marketplacebridge')) {
                as_schedule_single_action(time()+$delay,'mbwc_torob_notify',$args,'marketplacebridge',!$force);
            }
            return;
        }
        if ($force || !wp_next_scheduled('mbwc_torob_notify',$args)) { wp_schedule_single_event(time()+$delay,'mbwc_torob_notify',$args); }
    }
    public function deliver(int $id,int $attempt=0): void {
        if (get_option('mbwc_torob_webhooks','0')!=='1' || !Secrets::exists('torob','webhook_token')) { return; }
        $product=wc_get_product($id);
        if (!$product || $product->get_status()!=='publish' || $product->is_type('variable')) { return; }
        // Respect documented 20 request/minute cap, leaving margin for other processes.
        $key='mbwc_torob_rate_'.gmdate('YmdHi');
        $used=(int)get_transient($key);
        if ($used>=18) { $this->schedule($id,$attempt,65,true); return; }
        set_transient($key,$used+1,75);
        $response=(new Client())->update_webhook([['page_url'=>Feed::url($product),'page_unique'=>Feed::unique($id)]]);
        if (is_wp_error($response)) {
            Logger::record('torob','warning','product_webhook','retryable',$response->get_error_code());
            $code=(int)(($response->get_error_data()['status']??0));
            $retryable=$code===0 || $code===408 || $code===429 || $code>=500;
            if ($retryable && $attempt+1<self::MAX_ATTEMPTS) {
                $delay=min(3600,(2**($attempt+1))*60 + random_int(0,30));
                $this->schedule($id,$attempt+1,$delay,true);
            } else { Logger::record('torob','error','product_webhook','terminal','Product '.$id.' failed after attempt '.($attempt+1)); }
            return;
        }
        Logger::record('torob','info','product_webhook','acknowledged','Product '.$id.' accepted for eventual indexing');
    }
}
