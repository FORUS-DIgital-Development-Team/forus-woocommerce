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

# Merchant onboarding and configuration

This is a BLOCKED development package. Use only a disposable staging store. Installing it on a live store will remove FORUS as an available payment choice.

## Install

1. Back up WordPress files, WooCommerce database and configuration.
2. Confirm PHP 8.1+ and WooCommerce 8.0+ target requirements.
3. Upload forus-woocommerce-v1.1.0.zip in Plugins → Add New → Upload Plugin.
4. Activate, then open WooCommerce → Settings → Payments → FORUS.
5. Review the release-status banner before configuring credentials.

## Settings

| Setting | Meaning |
|---|---|
| Enabled | Preserved merchant preference; cannot override the code release gate |
| Title/description | Existing merchant checkout wording is preserved |
| Environment | Sandbox or Live; existing value retained |
| API base URL | Exact FORUS-supplied HTTPS merchant environment URL including /v1; never assume a default |
| Merchant / tenant ID | Identity to bind to API evidence; must match the provisioned account |
| API key | Password input never renders the saved value; blank retains the existing credential |
| Webhook secret | Required, at least 32 characters; blank retains existing value |
| Approved checkout hosts | Comma-separated exact provider hostnames; no wildcards, IPs, suffix matching or arbitrary ports |

FORUS_WC_API_KEY and FORUS_WC_WEBHOOK_SECRET in server-side wp-config.php take precedence over stored options. Use secret provisioning appropriate to your hosting platform. No key is sent to browser JavaScript, diagnostic output or raw logs. Database-stored settings remain protected by WordPress access controls, not field-level encryption; protect database access and backups.

Changing credentials, API URL, secret or merchant identity while orders are pending intentionally prevents reconciliation with mismatched configuration. Coordinate credential migration with an explicit pending-order recovery plan.

## Connection test

The connection control reports that authenticated merchant verification is unavailable. Rail Connector's public capabilities endpoint cannot authenticate an account. No API key is sent and no successful connection is claimed until a dedicated authenticated endpoint is implemented.

## Webhooks

The settings page displays the store callback: https://your-store.example/?wc-api=forus_webhook. Require HTTPS and keep it reachable for provider POST requests. Do not redirect this path, cache its response or inject challenge pages.

Current development behaviour: malformed/unsigned requests return 400/401; a structurally valid, signed payment event returns 503 while the backend contract gate remains closed. This build must not replace an active callback endpoint. See SECURITY-AND-OPERATIONS.md.

## Diagnostics and availability

Order administration displays session reference, last verified state/time, manual-review flag and refund journal state. It does not display raw payloads or credentials.

Payment-method and country coverage must come from the approved merchant contract and provisioned account. No specific method/country is promised by this build. Embedded checkout is unsupported.
