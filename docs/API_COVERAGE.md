# API coverage — audited 2026-11-23

## Torob

Official source: https://github.com/torob/Torob-Sync

| API | Current state |
|---|---|
| Product API v3 | Implemented with authenticated endpoint; fixture-tested, not live validated |
| Ed25519 JWT with version and `aud` checks | Implemented; negative-case tests |
| Product Webhook | Implemented with bounded retries; not live validated |
| Shop information | Manual GET, not live validated |
| Shop product search/status | Manual POST filters, improved JSON-object encoding |
| Product activate/deactivate | Manual, confirmation required; not live validated |
| Clicks | Manual GET with date validation; not live validated |
| Price reports | Manual GET/POST; report answer payload improved |
| Charge transfer preview | Manual GET, not live validated |
| Charge transfer execution | Disabled; not implemented |
| Order tracking | JWT-authenticated, disabled by default; optional line/phone sharing |
| Action tracking | JWT-authenticated, disabled by default; developer event hook |
| Shop generator connection | Not implemented, partner authorization required |

Torob's tracking endpoints use a timestamp-only cursor. The plugin keeps same-second groups together at page boundaries when they fit in the requested page size. A group that exceeds the requested limit needs a provider-agreed tie-breaker or a finer event timestamp.

## Digikala

Official developer entry: https://seller.digikala.com/open-api/v1/doc/
Public third-party reference: https://github.com/Goldis-Team/iranian-marketplaces-sdk

| API family | Current state |
|---|---|
| Seller authentication | Manual stored access tokens only, one per configured seller account |
| Built-in GET resources | Read-only fixed candidate endpoints, subject to live verification |
| Imported OpenAPI GET endpoints | Available after admin imports authorized JSON; **not necessarily validated** |
| Imported OpenAPI POST/PUT/PATCH/DELETE endpoints | Catalogued but execution explicitly blocked |
| Variant mapping | Manual, account-isolated |
| Inventory, pricing write | Not implemented |
| Order import and fulfillment | Not implemented |
| Product creation and content | Not implemented |
| Shipping and warehouses (writes) | Not implemented |
| Promotions, finance, ads (writes) | Not implemented |
| Webhook subscriptions | Not implemented |
| Refresh/self-token onboarding | Not implemented |

**A third-party listing of 274 operations in 37 groups does not mean 274 integrated operations exist in this plugin.** The exact authorized API surface can only be determined from the official schema and seller permissions.
