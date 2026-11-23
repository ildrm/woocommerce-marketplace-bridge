# Verification report — 0.2.0-alpha

- Runtime tested: PHP 8.4.24 CLI, Linux.
- Validation: `php -l` for each bundled PHP source and test file.
- Framework-free tests: `php tests/selftest.php`.
- Product feed fixture: `php tests/torob-feed-smoke.php`.
- Live Torob and Digikala: **NOT RUN**; no seller tokens or partner credentials.
- WordPress/WooCommerce/HPOS: **NOT RUN**; full WP application not available in the build environment.
- React/TypeScript bundle: **NOT PRESENT**; user interface is server-rendered PHP.
- QA security notes: The OpenAPI generic console is GET-only; manual write flows must be separately reviewed and explicitly authenticated/authorized.

These tests are evidence of local code-level behavior only. They do not establish production readiness.
