# Implementation status — 0.2.0-alpha

| Area | State | Notes |
|---|---|---|
| WordPress bootstrap | Verified locally | Activation and schema upgrade tested in WordPress and WooCommerce |
| Provider registry | Implemented | Extension hook available |
| Torob Product API v3 | Improved; fixture-tested | Page/identity endpoints, JWT validation; live unverified |
| Torob Product Webhook | Verified with mocked Torob responses | Batched delivery, capped retry, visible failures, manual retry and concurrent edits |
| Torob Shop API | Partially implemented | Manual shop/products/clicks/report operations, write confirmations; no transfer |
| Torob orders/actions tracking | Improved, opt-in | Optional details + update hook; no live test |
| Torob partner OAuth/connection | Not implemented | Needs partner agreement |
| Digikala seller access | Improved | Manual token + multiple seller accounts; no refresh |
| Digikala read operations | Partially implemented | Fixed built-in GET list, user-imported OpenAPI GET catalog |
| Digikala writes | Explicitly blocked | Requires verified typed workflows |
| Digikala inventory/order automation | Not implemented | Inventory/order ledger and schemas missing |
| Mappings | Verified locally | WooCommerce search, account-scoped pagination, guarded editing and optional variant access check |
| Secrets | Implemented; smoke-tested | AES-256-GCM; no key-rotation workflow |
| Response display | Improved | Redaction and transient size bounds |
| Torob money | Improved; tested | Exact integer conversion, rejects fractional unit values |
| Retry engine | Partial | Only Torob webhook retry, not a full job system |
| Retention/uninstall | Added | Daily cleanup and secret removal |
| Admin UI | Verified locally | Server-rendered English and Persian/RTL, typed forms, feed preview, delivery queue and searchable API catalog |
| PHP lint | Passed | All packaged PHP files |
| Framework-free tests | Passed | API contract and product feed fixtures |
| WordPress/WooCommerce integration | Passed locally | Temporary WordPress and WooCommerce installation with MariaDB and mocked provider HTTP |
| Production readiness | **NO** | Not for use with live stock or financial writes |

For the complete audit and unresolved requirements, see `AUDIT_0.2.0.md`.
