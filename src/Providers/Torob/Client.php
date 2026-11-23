<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Providers\Torob;

use MarketplaceBridge\WooCommerce\Infrastructure\Http;
use MarketplaceBridge\WooCommerce\Infrastructure\Secrets;

/** Explicitly allowlisted operations from Torob's official Shop API documentation. */
final class Client {
    public const OPERATIONS = [
        'shop' => ['GET', '/shop/'],
        'products' => ['POST', '/products/'],
        'activate' => ['POST', '/products/activate/'],
        'deactivate' => ['POST', '/products/deactivate/'],
        'clicks' => ['GET', '/clicks/'],
        'price_reports' => ['GET', '/price-reports/'],
        'answer_report' => ['POST', '/price-reports/answer/'],
        'transfer_preview' => ['GET','/charge-transfer/'],
    ];
    public function run(string $operation, array $parameters = [], ?array $body = null): array|\WP_Error {
        if (!isset(self::OPERATIONS[$operation])) { return new \WP_Error('mbwc_operation','Operation not available'); }
        [$method, $path] = self::OPERATIONS[$operation];
        $token = Secrets::get('torob','shop_token');
        if (!$token) { return new \WP_Error('mbwc_auth', 'Configure a Torob Shop API token first'); }
        if (in_array($operation,['activate','deactivate','answer_report'],true) && $body === null) {
            return new \WP_Error('mbwc_body','Body is required for changes');
        }
        if ($operation === 'products' && $body === null) { $body = []; }
        if ($operation === 'products') {
            $allowedBody = ['product_ids','status','updated_since'];
            $unknown = array_diff(array_keys($body),$allowedBody);
            if ($unknown) { return new \WP_Error('mbwc_body','Unexpected products filter'); }
            if (isset($body['product_ids'])) {
                if (!is_array($body['product_ids']) || !$body['product_ids'] || count($body['product_ids'])>500) { return new \WP_Error('mbwc_body','product_ids must contain 1..500 IDs'); }
                foreach ($body['product_ids'] as $id) { if (!is_string($id) || $id==='') { return new \WP_Error('mbwc_body','Invalid product ID'); } }
            }
        }
        if ($operation === 'answer_report') {
            if (!in_array($body['status']??'', ['claimed_to_be_corrected','claimed_to_be_invalid'],true)) {
                return new \WP_Error('mbwc_body','Invalid price report resolution');
            }
            if (empty($body['report_ids']) || !is_array($body['report_ids']) || count($body['report_ids'])>1000) {
                return new \WP_Error('mbwc_body','report_ids must contain 1..1000 IDs');
            }
            foreach ($body['report_ids'] as $id) { if (!is_int($id) || $id<1) { return new \WP_Error('mbwc_body','Invalid report_id'); } }
            if (isset($body['description']) && (!is_string($body['description']) || strlen($body['description'])>600)) {
                return new \WP_Error('mbwc_body','Report description is too long');
            }
        }
        if (in_array($operation,['activate','deactivate'],true)) {
            if (!isset($body['product_ids']) || !is_array($body['product_ids']) || count($body['product_ids'])<1 || count($body['product_ids'])>5000) {
                return new \WP_Error('mbwc_body','Product IDs must contain 1–5000 identifiers');
            }
            foreach ($body['product_ids'] as $id) {
                if (!is_string($id) || $id === '' || strlen($id) > 200) { return new \WP_Error('mbwc_body', 'Invalid product ID'); }
            }
            $body = ['product_ids'=>array_values($body['product_ids'])];
        }
        $allowed = match($operation) {
            'products'=>['page_size','cursor','domain'], 'clicks'=>['date','click_type','is_guaranteed','page','page_size','domain'],
            'price_reports'=>['active_reports','page','page_size','domain'], 'transfer_preview'=>['destination'],
            default=>['domain']
        };
        $parameters = array_intersect_key($parameters, array_flip($allowed));
        if ($operation === 'clicks') {
            $date = $parameters['date'] ?? '';
            if (!is_string($date) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts)
                || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
                return new \WP_Error('mbwc_body', 'A valid date in Tehran (YYYY-MM-DD) is required.');
            }
        }
        if ($operation === 'transfer_preview' && empty($parameters['destination'])) { return new \WP_Error('mbwc_body','Destination domain is required'); }
        if ($method === 'GET') { $body = null; }
        return Http::request('torob', $method, '/shop-api/v1'.$path, $token, $parameters, $operation==='products' && !$body ? (object)[] : $body);
    }
    /** Change notification API; an acknowledgement is not proof of indexing. */
    public function update_webhook(array $items): array|\WP_Error {
        $token = Secrets::get('torob','webhook_token');
        if (!$token) { return new \WP_Error('mbwc_auth','Configure a Torob webhook token first'); }
        if (count($items)<1 || count($items)>100) { return new \WP_Error('mbwc_batch','Webhook batch must contain 1–100 products'); }
        foreach ($items as $item) {
            if (!isset($item['page_url'],$item['page_unique']) || !is_string($item['page_url']) || !is_string($item['page_unique'])) {
                return new \WP_Error('mbwc_batch','Invalid webhook product identity');
            }
        }
        $response = Http::request('torob', 'POST', '/update/webhook/v1/', $token, [], ['items'=>array_values($items)]);
        if (!is_wp_error($response) && ($response['message'] ?? null) !== 'success') {
            return new \WP_Error('mbwc_webhook_ack', 'Unexpected Torob webhook response');
        }
        return $response;
    }
}
