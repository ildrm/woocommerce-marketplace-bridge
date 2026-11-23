<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Core;

interface ProviderInterface {
    public function id(): string;
    public function label(): string;
    /** @return array<string,string> Capability name => status. */
    public function capabilities(): array;
    /** @return array<string,string> Configuration field => label. */
    public function settings_fields(): array;
}
