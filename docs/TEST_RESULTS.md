# Verification report — 0.2.0-alpha

- Runtime tested: PHP 8.5.8 CLI, macOS.
- Validation: `php -l` for each bundled PHP source and test file.
- Framework-free tests: `php tests/selftest.php`.
- Product feed fixture: `php tests/torob-feed-smoke.php`.
- Integration environment: WordPress 7.1.5, WooCommerce 11.3.1 and a temporary local MariaDB instance.
- Verified activation, additive queue schema migration, account forms, WooCommerce product search, mapping conflicts, queue failures and retries, revision handling, API response redaction and Persian/RTL admin markup.
- Checked the Persian connection screen at desktop width and the mapping screen at a 390 px browser viewport. The page fit the viewport and wide tables scrolled within their container.
- With WooCommerce HPOS enabled, verified that pending orders do not enter the feed, older pending rows do not consume the polling limit, and cancellation and deletion are reflected in attribution.
- Provider HTTP was mocked. Live indexing, seller permissions and live API responses require separate verification.

These tests are evidence of local code-level behavior only. They do not establish production readiness.
