# Changelog

## 0.2.0-alpha — 2026-11-23

- Critical safety repair: blocked imported Digikala Swagger write execution.
- Added account-scoped Digikala seller connections, tokens and product mapping.
- Fixed Torob Shop product search empty JSON payload (`{}` rather than `[]`).
- Added exact integer Rial/Toman conversion with validation and overflow checks.
- Added Torob webhook bounded retry, backoff and pause behavior.
- Corrected Torob feed cursor final-page detection and product-by-URL variation validation.
- Added optional order attribution details and customer phone opt-in.
- Improved Torob Shop report request validation.
- Added OpenAPI relative path normalization and resource group inventory view.
- Added recursive API-preview redaction and shorter transient persistence.
- Added typed confirmation for seller account and mapping deletion.
- Added retention maintenance and secure uninstall policy.
- Expanded smoke and product-feed fixture tests; documented remaining blockers.

## 0.1.0-alpha — 2026-11-23

- Initial prototype provider plugin.
