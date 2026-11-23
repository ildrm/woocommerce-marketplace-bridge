<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Core;

final class Registry {
    /** @var array<string,ProviderInterface> */
    private array $providers = [];
    public function register(ProviderInterface $provider): void {
        $id = $provider->id();
        if (!preg_match('/^[a-z][a-z0-9_-]{1,30}$/', $id)) { throw new \InvalidArgumentException('Invalid provider id'); }
        if (isset($this->providers[$id])) { throw new \LogicException('Provider already registered'); }
        $this->providers[$id] = $provider;
    }
    public function get(string $id): ?ProviderInterface { return $this->providers[$id] ?? null; }
    /** @return array<string,ProviderInterface> */
    public function all(): array { return $this->providers; }
}
