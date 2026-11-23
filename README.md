# MarketplaceBridge for WooCommerce

**Version: 0.2.0-alpha — audited and repaired development build; NOT production certified.**

Independent, extendable Torob + Digikala WooCommerce integration with a provider registry, administrator controls, Torob Product API v3, Torob Shop API, signed Torob attribution tracking, product-update webhook delivery with bounded retries, encrypted seller credentials, multiple Digikala seller connections, and a **read-only** Digikala OpenAPI operation browser.

This version fixes specific defects in the prior 0.1.0-alpha package. It **does not** implement every operation in the providers' APIs and **does not** provide production-safe automatic Digikala stock/price writes, order import, financial workflows or full React/TypeScript UI. See [audit and remaining work](docs/AUDIT_0.2.0.md).

## Install / upgrade

1. Back up the website and database. Use a staging WooCommerce site first.
2. Upload the plugin ZIP from **WordPress → Plugins → Add New → Upload Plugin**. Replace the previous version when prompted.
3. WooCommerce and PHP 8.2+ are required. PHP Sodium is needed for Torob JWT signature verification. OpenSSL is needed for encryption at rest.
4. In **Marketplace Bridge → Connections**, set the source WooCommerce price unit (**IRR** or **IRT**) and configure the Torob tokens. Use **Torob → Product feed** to preview the outgoing price and availability of a product or variation before enabling the feed.
5. In **Connections → Digikala seller accounts**, edit the default connection or add a separate account ID, seller code and token for each seller. Validate each connection from **Digikala**. The default connection keeps the existing token.
6. In **Mappings**, search WooCommerce by product name, SKU or ID, select a simple product or variation, and enter the Digikala variant ID. You can verify access to the variant before saving. Existing mappings can be filtered by seller account and edited from the list.
7. Give Torob the endpoint listed under **Torob → Product feed** after checking the price and availability of a sample catalog.
8. Ask Torob to enable the direct integration if this shop qualifies. Their official WooCommerce plugin remains the simplest option for many merchants.
9. To inspect additional read operations, export the official Swagger/OpenAPI **JSON** from the seller portal and import it through **API catalog**. The catalog can run GET requests.
10. To enable product change notifications, enable the Torob product endpoint and configure the webhook token. WP-Cron schedules batches of up to 100 products every minute, with up to five delivery attempts for temporary errors. The **Delivery queue** shows pending and failed items and lets administrators retry a failed item. A Torob acknowledgment confirms queue receipt. Indexing is asynchronous.

## Supported Torob endpoints

- `POST https://YOUR-STORE/torob_api/v3/products` — Product API v3, verified Ed25519 JWT only.
- `GET https://YOUR-STORE/torob/v1/orders` — optional, Torob-origin orders only; disabled by default.
- `GET https://YOUR-STORE/torob/v1/actions` — optional, Torob-origin actions only; disabled by default.
- WordPress REST fallbacks: `/wp-json/marketplacebridge/v1/torob/products`, `/torob/orders` and `/torob/actions`.

Torob Shop API operations and notifications are available as manually invoked admin actions, except irreversible charge transfer. Exact limitations are described in the API coverage document.

## Supported Digikala integration

- Seller access-token storage, separately encrypted for each configured seller account.
- Manual account-scoped SKU/product/variant mapping.
- Read-only API operations for listed seller resources, subject to actual account scopes and the live API specification.
- Import of seller OpenAPI 3 or Swagger 2 JSON, including relative endpoint paths.
- Permission-restricted GET-only operation browser. **No arbitrary imported write is executed.**
- Sanitized API response previews with best-effort redaction of personal and secret data; do not treat this as a general-purpose personal-data archive.

**Not supported in this build:** autonomous Digikala price and inventory updates, importing seller orders, token onboarding/refresh, live webhook subscriptions and all mutable financial/marketing/shipping workflows.

## Security and privacy

- Store tokens encrypted using AES-256-GCM. Define a stable, high-entropy `MBWC_ENCRYPTION_KEY` in `wp-config.php` for best operational reliability. Changing keys or WordPress salts without migration renders earlier credentials unreadable.
- Administrator actions require `manage_options` plus WordPress nonces. Unreviewed Digikala writes are blocked.
- Network requests use fixed HTTPS provider origins, TLS verification and bounded response bodies.
- Torob order/action attribution is **off** by default and uses a signed HttpOnly cookie with a seven-day attribution window. Phone numbers and line-item details are **separately opt-in** and require an appropriate merchant privacy basis.
- A daily cleanup job removes old logs (default 30 days) and old attribution/action records (90 days), in bounded batches.
- When the plugin is uninstalled, saved secrets are removed. Historical mappings/logs are retained unless `define('MBWC_PURGE_ON_UNINSTALL', true);` is set **before** uninstall. Be careful: purging cannot be undone.
- Avoid running two overlapping Torob feed plugins. A warning is shown if the known official Torob extractor is active.

## Verify the package

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/selftest.php
php tests/torob-feed-smoke.php
```

These tests use mocked responses and WooCommerce fixtures and **do not** prove WordPress installation, live seller API correctness, stock concurrency or HPOS compatibility. Those require staging and authorized account credentials.

## Authoritative references

- Torob: https://github.com/torob/Torob-Sync
- Digikala seller API: https://seller.digikala.com/open-api/v1/doc/
- Digikala third-party inventory (not an official permission grant): https://github.com/Goldis-Team/iranian-marketplaces-sdk/blob/main/docs/reference/digikala-endpoints.md
- WooCommerce HPOS: https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/

## License

GPL-2.0-or-later. Independent third-party integration; not endorsed by Torob or Digikala.
