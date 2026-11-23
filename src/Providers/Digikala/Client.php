<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Providers\Digikala;

use MarketplaceBridge\WooCommerce\Infrastructure\Http;
use MarketplaceBridge\WooCommerce\Infrastructure\Accounts;

/** Read operations intentionally limited to observed OpenAPI routes; writes require validated contracts. */
final class Client {
    public const READ_OPERATIONS = [
        'health'=>['/open-api/v1/', []],
        'scopes'=>['/open-api/v1/auth/scopes', []],
        'categories'=>['/open-api/v1/categories/tree', []],
        'seller_products'=>['/open-api/v1/products/seller', ['page','page_size','search[search_term]']],
        'variants'=>['/open-api/v1/variants', ['page','page_size','search[search_term]']],
        'variant'=>['/open-api/v1/variants/{variant_id}', []],
        'variant_seller_stock'=>['/open-api/v1/variants/{variant_id}/seller-stock', []],
        'orders'=>['/open-api/v1/orders', ['page','page_size']],
        'order_statistics'=>['/open-api/v1/orders/statistics', []],
        'order_history'=>['/open-api/v1/orders/history', ['page','page_size']],
        'inventories'=>['/open-api/v1/inventories', ['page','page_size']],
        'packages'=>['/open-api/v1/packages', ['page','page_size']],
        'packages_warehouses'=>['/open-api/v1/packages/warehouses', []],
    ];
    public function read(string $operation, array $parameters=[], string $account="default"): array|\WP_Error {
        if (!isset(self::READ_OPERATIONS[$operation])) { return new \WP_Error('mbwc_operation','Unverified API operation'); }
        [$path, $queryKeys] = self::READ_OPERATIONS[$operation];
        if (str_contains($path, '{variant_id}')) {
            $id = (string)($parameters['variant_id'] ?? '');
            if (!ctype_digit($id) || $id === '0') { return new \WP_Error('mbwc_id','Invalid variant ID'); }
            $path = str_replace('{variant_id}', $id, $path);
        }
        $params = array_intersect_key($parameters, array_flip($queryKeys));
        if (!Accounts::exists('digikala',$account)) { return new \WP_Error('mbwc_account','Unknown seller account'); }
        $token = Accounts::token('digikala',$account);
        if (!$token) { return new \WP_Error('mbwc_auth','Configure a Digikala seller access token'); }
        return Http::request('digikala', 'GET', $path, $token, $params, null, Accounts::sellerCode($account));
    }
}
