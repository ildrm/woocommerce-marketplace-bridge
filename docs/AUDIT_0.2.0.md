# Technical audit of the 0.1.0-alpha package and 0.2.0-alpha repair

**Review date:** 2026-11-23. **Result:** Defects were fixed, but full requirements remain unimplemented; no production certification.

| Finding | Severity | 0.2.0-alpha disposition |
|---|---|---|
| Generic imported OpenAPI paths could execute unreviewed Digikala POST/PUT/PATCH/DELETE requests | **Critical** | **Fixed:** imports are now a read-only operation browser; all generic writes rejected before networking |
| Torob Shop `/products/` empty filters serialized to `[]` instead of `{}` | High | **Fixed:** empty filter becomes a JSON object |
| Torob currency conversion used floating point, risking precision loss or inconsistent rounding | High | **Fixed:** integer/decimal-string conversion, overflow checks and controlled half-up rounding |
| Torob webhook failures had no bounded retry mechanism | High | **Fixed:** capped exponential backoff for retryable errors; explicit terminal failure log |
| Torob webhook could continue after administrator disables it | Medium | **Fixed:** delivery rechecks enablement and token |
| Torob cursor endpoint could return non-null cursor after exactly 100 terminal products | Medium | **Fixed:** 101-record lookahead |
| Product-by-URL lookup trusted an arbitrary `variation_id` from query parameters | Medium | **Fixed:** verifies the variation belongs to the requested canonical product path |
| Torob product visibility exclusions could create incomplete feed pages | Medium | **Partially fixed:** filter catalog exclusions in SQL; complicated third-party product types still require live QA |
| Price-report answers lacked required report ID schema checks | Medium | **Fixed:** validated report IDs and status |
| Digikala OpenAPI importer did not recognize relative `/variants` paths | Medium | **Fixed:** resolves relative paths against the trusted API base |
| Digikala mappings were limited to one seller and no account isolation | High | **Fixed for manual workflows:** multiple seller credentials and account-scoped mapping; automated sync still absent |
| Admin result previews could persist raw secrets and phone numbers in transient responses | High | **Mitigated:** recursive redaction, size limits and transient expiry; avoid storing full API responses |
| Sensitive deletion actions had inadequate confirmation | Medium | **Fixed:** typed confirmations for account/mapping removal |
| Torob order attribution could not optionally include monetary line details, despite documented support | Medium | **Fixed with opt-in:** order totals, shipping and line items; optional phone separately controlled |
| Custom action tracking had no update/cancellation hook | Medium | **Partially fixed:** `mbwc_torob_update_action` allows status updates; integration test required |
| No automatic retention for logs/attribution | Medium | **Fixed:** bounded daily cleanup; reactivation safe |
| Uninstall lacked a clear handling policy for seller credentials | Medium | **Fixed:** remove tokens by default and optional purge historical data |
| Admin gave little visibility into imported OpenAPI inventory | Low | **Improved:** totals by operation risk and resource tag |

## Still missing or externally blocked

1. **Digikala official API access:** The authenticated current OpenAPI specification, request/response schemas, seller-specific scopes, and a test account were not available. Endpoint names from third-party SDKs are not proof of availability.
2. **Digikala write operations:** No autonomous stock/price publication, product creation, order import, returns, shipments, promotions, finance, advertising or active subscriptions. All require domain-specific adapters, confirmed schemas, verified permissions, transaction safety, and real integration tests.
3. **Digikala self-token onboarding/refresh:** Manual token entry currently; no end-to-end RSA authorization or token renewal workflow.
4. **Torob partner shop-generator flow and money transfers:** Requires formal partner authorization/permissions and additional dedicated reviewed workflows. Financial transfer intentionally disabled.
5. **Cross-channel inventory ledger and order idempotency:** Not implemented. No reliable two-way stock sync should be enabled before this work.
6. **Complete product mapping, category/attribute mapping and pricing rules:** Only manual variant mapping exists; no autonomous catalog reconciliation.
7. **Full React/TypeScript administrative UX and full Persian localization:** Current server-rendered administrator provides basic workflows and RTL styling; it is not the requested React/TypeScript application.
8. **True end-to-end QA:** No real WordPress/WooCommerce/HPOS deployment, no authorized live Torob/Digikala integration tests, and no load/concurrency tests were possible in this environment.
9. **Operational features:** Queue monitoring dashboard, durable event ledger, webhook replay, distributed rate limiting, error notifications, credential rotation, multisite migration validation, and performance benchmarks remain incomplete.
10. **Torob edge cases:** Exact status interpretation for third-party WooCommerce product types and full attribution payload semantics require staged API validation.

## Release decision

**NOT PRODUCTION-READY.** Use only in staging. The repaired package is meant for continued engineering, verified testing, and gradual activation after merchant approval. Do not claim the above missing areas have been completed.
