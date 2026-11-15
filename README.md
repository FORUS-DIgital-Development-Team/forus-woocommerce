# FORUS WooCommerce Payment Gateway 1.1.0 development candidate

**BLOCKED development build. Do not install on a live merchant store.**

This branch consolidates the modular payment-integrity work with PR #3. The plugin includes order locking, persistent operation/event journals, authenticated status-driven reconciliation, refund tracking, merchant diagnostics, Classic gateway registration and Checkout Blocks integration. Financial execution is disabled until the missing Rail Connector merchant API guarantees and real sandbox acceptance are complete.

No payment, refund, cancellation, status enquiry or credential-bearing connection request is sent by the shipped adapter. The public Rail capabilities route cannot authenticate a merchant; the connection control explicitly reports that limitation.

## Requirements

WordPress 6.0+, WooCommerce 8.0+ and PHP 8.1+ are target minimums. CI exercises PHP 8.1-8.5 and real WooCommerce with HPOS enabled and disabled. Actual sandbox and browser checkout certification remain separate gates. The code uses WooCommerce order APIs and requires MySQL/MariaDB named locks for financial serialization.

Hosted checkout only. No payment method, country, embedded-checkout or provider acceptance coverage is certified. Existing gateway ID, saved settings and order journals are retained.

## Review and development

Use a disposable store. Upload the development ZIP under Plugins > Add New > Upload Plugin. FORUS remains unavailable at checkout. See [release readiness](docs/RELEASE-READINESS.md) before installation.

- [Merchant guide](docs/MERCHANT-GUIDE.md)
- [API and backend audit](docs/API-AUDIT.md)
- [Security and reconciliation](docs/SECURITY-AND-OPERATIONS.md)
- [Testing and evidence](docs/TESTING.md)
- [Upgrade and rollback](docs/UPGRADE.md)
- [Changelog](CHANGELOG.md)

Run `php tests/run.php`, `node --test tests/blocks.test.mjs` and `node scripts/check-source.mjs`. The disposable integration entrypoint is `tests/lifecycle.php`; it exercises real WooCommerce storage, stock and refunds with an in-process test adapter, not a live provider. Package with `pwsh scripts/build.ps1`.

[Draft PR #3](https://github.com/FORUS-DIgital-Development-Team/forus-woocommerce/pull/3) retains the earlier GitHub commits. The old hypothetical legacy adapter and tests are superseded by the modular implementation and regression suite. They remain available in Git history at e7fda2919407ecfb729e3a5d928304daa97058a0.

GPL-2.0-or-later. No production release or deployment has been performed.
