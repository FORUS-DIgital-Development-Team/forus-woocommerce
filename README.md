## Merchant checkout integration — 15 November 2026

This revision connects the consolidated plugin architecture to **Rail Connector PR #90** and its `forus-merchant-checkout-v1` contract. It replaces the previous fully blocked HTTP adapter for explicitly opted-in sandbox installations only. Existing lifecycle/refund safeguards and tests are retained.

- Set `FORUS_WC_ENABLE_TEST_CHECKOUT` to true in a staging wp-config.php only after deploying the matching backend and configuring the store. Save the verified HTTPS API base including /v1, a forus_test_sk_* key with payment read/write scopes, registered tenant, 32+ character webhook secret and approved provider checkout hosts. ZAR is the only supported currency.
- Live keys, live mode and automatic provider refunds remain disabled. No live deployment or provider acceptance is claimed.
- The merchant capabilities endpoint now verifies the test merchant configuration. Payment creation uses stable wire snapshots and database-backed backend idempotency. Lost creation responses can be recovered using the same reference/request.
- New merchant events carry signed integer created_at timestamps. Webhooks remain wake-up signals; an authenticated, tenant-scoped status read independently verifies order evidence before completion.
- The status endpoint reports durable verified callback state, not independent provider settlement reconciliation.
- Tests now include the actual HTTP adapter with mocked backend responses in both the PHP matrix and real WooCommerce HPOS on/off lifecycle jobs. Check this revision's Actions results; earlier results below apply only to their named commits.
- Remaining deployment blockers: target WordPress URL/access; provisioned backend sandbox credentials, merchant registration, public callback routing and delivery scheduler; actual provider sandbox/browser acceptance; safe provider refunds and live release enablement.

The historical blocked-adapter/PR #29 descriptions below apply to earlier revisions and are superseded by this section. The matching backend runbook is `docs/merchant-checkout-acceptance.md` in Rail Connector PR #90.

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
