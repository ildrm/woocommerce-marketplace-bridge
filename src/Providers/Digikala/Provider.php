<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Providers\Digikala;
use MarketplaceBridge\WooCommerce\Core\ProviderInterface;
final class Provider implements ProviderInterface {
    public function id(): string { return 'digikala'; }
    public function label(): string { return 'Digikala / دیجی‌کالا'; }
    public function capabilities(): array {
        return [
            'Authenticated read-only seller API console' => 'implemented',
            'Variants / orders / categories / profile' => 'implemented: read-only',
            'Account-scoped DKPC-to-WooCommerce mapping' => 'implemented',
            'Multiple separate seller connections' => 'implemented: manual credentials',
            'Token validation' => 'requires seller token',
            'Live stock/price write' => 'disabled: unverified request schema',
            'Order importing and stock reconciliation' => 'not implemented',
            'Shipping / finance / marketing write operations' => 'not implemented',
            'Full Swagger operation coverage' => 'requires authorized OpenAPI specification',
        ];
    }
    public function settings_fields(): array { return ['access_token'=>'Seller Open API bearer token', 'seller_code'=>'Seller code (optional)']; }
}
