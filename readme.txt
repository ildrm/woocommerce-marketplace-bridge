=== MarketplaceBridge for WooCommerce ===
Contributors: marketplacebridge
Tags: woocommerce, torob, digikala, marketplace, seller-api
Requires at least: 6.4
Requires PHP: 8.2
Stable tag: 0.2.0-alpha
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Provider-based WooCommerce integration for Torob and Digikala. ALPHA: use on staging only; not complete or production certified.

== Description ==

Torob Product API v3 with Ed25519 JWT, selected Torob Shop API operations, opt-in attribution, bounded product notifications, manually configured multiple Digikala seller accounts, manual variant mapping and a safe read-only OpenAPI browser.

Critical features NOT completed: Digikala stock/price writes, marketplace order import, self-token onboarding/refresh, full shipping/promotion/finance workflows and full localization.

== Installation ==

Install WooCommerce and upload the ZIP. Open MarketplaceBridge > Connections to set tokens and the source currency unit. Verify on staging before enabling Torob. See README.md for details.

== Changelog ==

= 0.2.0-alpha =
Fixed unsafe Digikala generic writes, money conversion, Torob webhook retry and feed cursor behavior; added multiple Digikala seller accounts and better test coverage.
