<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Providers\Torob;
use MarketplaceBridge\WooCommerce\Core\ProviderInterface;
final class Provider implements ProviderInterface {
    public function id(): string { return 'torob'; }
    public function label(): string { return 'Torob / ترب'; }
    public function capabilities(): array {
        return [
            'Product API v3 (JWT)' => 'implemented',
            'Outbound product update webhook' => 'implemented: bounded retry',
            'Shop information / product statuses' => 'implemented',
            'Product activate/deactivate' => 'implemented',
            'Click analytics / price reports' => 'implemented',
            'Answer price reports' => 'implemented',
            'Order attribution (opt-in)' => 'implemented'
            , 'Action tracking (custom event hook)' => 'implemented',
            'Shop-generator connection' => 'partner approval required',
            'Charge transfer' => 'disabled: sensitive operation',
        ];
    }
    public function settings_fields(): array { return ['shop_token'=>'Shop API token', 'webhook_token'=>'Webhook bearer token']; }
}
