# FORUS WooCommerce 1.1.0 development candidate

**INCOMPLETE. STAGING ONLY. DO NOT INSTALL ON A LIVE STORE.**

This branch is a security containment and verification foundation, not a production release. Live checkout and live callbacks are explicitly disabled. Automatic refunds are unavailable. No payment method or country coverage is certified. The legacy adapter remains as a gated test harness; it must be replaced with the agreed merchant-session contract before release.

## Audit evidence (13 November 2026)

Plugin base: master d6501155fd02102c4ca97eb61e2989026a82dc8d. Reviewed the three base files, release v1.0.0, three branches and open PR #2. No open issues were returned in the initial audit. PR #2 uses the unmerged Rail Connector PR #29 (b7b15193b704c7744b6d1ba772e6539629125ff4). Its previous smoke test only checks source strings.

Rail Connector main was downloaded and its server mounts, payment handlers, authentication, scopes, capabilities and database idempotency index inspected. PR #29 was subsequently read in the signed-in browser. It is still open and was 115 commits behind main when inspected; do not merge its historical authentication changes without reconciling current main.

| Interface | Source-verified contract | Release consequence |
|---|---|---|
| Generic create | POST /v1/payments (also /api/payments); tenantId, customerId, orderNumber, amount in major units, returnUrl; returns transactionId, status, redirectPayload | Incompatible with v1.0 plugin's minor-unit payload and id/checkout_url expectations |
| Generic status | GET /v1/payments/{transactionId}; status, amount in major units, currency, orderNumber | Handler does not supply merchant identity evidence; do not treat as sufficient merchant reconciliation |
| Generic refund | POST /v1/payments/{transactionId}/refunds, amount in major units | Singular /refund in v1.0 is incorrect; safe repeated/partial refund behavior needs backend work |
| Merchant create in PR #29 | POST /v1/payments/merchant-sessions; merchantReference, amountMinor, ZAR, returnUrl, cancelUrl, webhookUrl, webhookSecret, string-valued metadata | Unmerged. Hosted form POST response: id, status, checkout {method, url, fields} |
| Merchant refund in PR #29 | POST /v1/payments/merchant-sessions/{id}/refunds; amountMinor, reason; id, paymentSessionId, status, amountMinor | Unmerged; requires durable idempotency and cumulative refund checks |
| Merchant event in PR #29 | X-FORUS-SIGNATURE = lowercase hex HMAC-SHA256(raw JSON); id, type, createdAt ISO timestamp, data {id,status,amountMinor,currency,metadata} | Missing merchant/reference evidence required by this candidate; not wire-compatible with its defensive legacy event schema |
| Capabilities | GET /v1/capabilities is public deployment configuration | Does not authenticate a merchant and cannot be called a merchant connection test |

Organisation API keys are scoped to /v1. Do not work around that with platform admin keys in WordPress. No Keycloak administrative credentials belong in this plugin. A source-implemented route is not proof of deployment or provider readiness.

## Implemented locally

- Required HMAC authentication, bounded payload, exact evidence checks and current-order amount/currency validation.
- Durable single-attempt claim, per-order callback lock, per-event deduplication and terminal-state safeguards.
- Exact HTTPS redirect allowlist, no credential-carrying redirects, bounded HTTP responses and safe customer errors.
- Explicit live execution gates; default disabled; administrator warning; refund denial.
- Pure helper and behavioral gateway regression tests, including duplicate/late events, changed amounts, unavailable services and default release gates.
- Checkout Blocks adapter sharing server availability gates; only display data is exposed to JavaScript. Browser checkout has NOT been validated.
- PHP 8.1-8.5 CI matrix, recursive lint and development artifact packaging. No automatic release publication.
- Existing WooCommerce option names retained and order data accessed through WooCommerce CRUD.

The single-attempt claim is a conservative safety brake, not backend idempotency. A crash may leave a durable claim or callback lock. Do not delete it before reconciling the backend. Test doubles do not demonstrate database concurrency, stock/email side effects, HPOS or browser checkout correctness.

The sandbox-only constant FORUS_WC_VERIFIED_LEGACY_CONTRACT exists for isolated testing. It does not certify compatibility and cannot enable live execution. The gated test event uses id/type/created_at (integer epoch seconds within 300 seconds), data {id,reference,merchant_id,amount (integer minor units),currency,status,metadata:{order_id (integer)}}. This is a proposed defensive schema, not the actual PR #29 event. Do not point real merchant callbacks at it.

## Required backend changes

1. Agree and version a merchant contract; preserve existing integrations while adding the new surface.
2. Bind merchant and tenant identity to the authenticated org key on every create/read/refund operation. Return identity, immutable reference, environment, amount/currency and state in authenticated evidence.
3. Atomically reserve merchant-scoped idempotency keys, reject changed payloads, return the same session after timeouts and prevent replayed hosted forms from creating duplicate charges.
4. Add a merchant-scoped session status/lookup endpoint and a refund lookup endpoint. Proposed paths: GET /v1/payments/merchant-sessions/{id}, GET /v1/payments/merchant-sessions/{id}/refunds/{refundId}. These are requirements, not existing routes.
5. Make full/partial refunds idempotent with an atomic cumulative refundable balance and recovery for uncertain provider responses.
6. Bind authenticated provider callback evidence to the stored session's merchant, reference, amount/currency and transaction identifier before changing state. Reject invalid callbacks without changing financial state. Enforce monotonic state changes.
7. Persist outbound events in a durable outbox; retry failed deliveries without following redirects. Define stable event IDs, signed timestamps, delivery freshness and event ordering. Include complete merchant evidence.
8. Publish authenticated merchant capabilities and approved checkout hosts. Confirm sandbox and live URL/key separation with deployed evidence.

## Ecosystem boundaries

WordPress owns order state, customer UI and verified event consumption. Rail Connector owns merchant authentication, orchestration, provider credentials, payment/refund state and durable event delivery. Providers own payment authorisation and settlement evidence. Optional Register onboarding must return a merchant identity through an approved server integration; no verified onboarding endpoint was found. Control Tower telemetry is emitted by the backend payment service; avoid duplicate client emission. Additional onboarding/reporting events remain design dependencies, not implemented integrations.

## Testing

Run:

    php tests/security.php
    php tests/gateway.php
    php tests/gateway.php --release-gate
    node --check includes/blocks.js

The workflow lints all PHP files and runs all PHP regressions on 8.1-8.5 before packaging. The artifact is labelled 1.1.0-dev and must not be published as production-ready. At this checkpoint only the JavaScript syntax check has executed successfully. Local PHP is absent, Docker is unavailable, and official PHP downloads were blocked by the browser. PHP execution and CI results must be recorded separately; no pass is claimed here.

## Installation, migration and rollback

Use a disposable staging copy. Back up the database, plugin directory and woocommerce_forus_settings. Disable FORUS checkout during the transition and reconcile in-flight payments before swapping the plugin. Existing keys and settings are retained; this candidate does not delete configuration or order data. New merchant identity and allowed-host settings default empty.

Pending legacy orders lack the evidence required by this candidate. Reconcile them individually using provider records. Do not mark them paid from a browser return parameter or unsigned callback.

For rollback, disable FORUS checkout and block its callback route before restoring the previous plugin/database snapshot. v1.0.0's unsigned callback must not become publicly reachable again. Other gateways may remain enabled. Upgrade and rollback have NOT been executed in a WordPress installation.

## Troubleshooting

- No FORUS method at checkout: expected while the release gate is closed; do not bypass it in a live store.
- Callback 401: absent/invalid signature. 400: malformed payload, invalid schema or timestamp. 409: evidence/order mismatch or disallowed transition. 503: release gate, concurrent handler or processing failure.
- Failed creation: reconcile before another payment attempt; a durable attempt claim intentionally prevents blind retry.
- Refund unavailable: automatic refunds are disabled until backend guarantees are verified. Record manual refunds only after provider confirmation.
- Logs: this candidate writes no raw payment payloads or credentials. Configurable diagnostic retention and connection testing remain unfinished.

## Release acceptance checklist

- [ ] Complete merchant API changes and plugin contract migration.
- [ ] Verify creation/refund idempotency under concurrency and network uncertainty.
- [ ] Complete reconciliation, callback recovery, connection test and diagnostics.
- [ ] Pass WordPress coding standards and PHP compatibility matrix.
- [ ] Pass classic checkout and Checkout Blocks in supported browsers.
- [ ] Test legacy and HPOS order stores before declaring HPOS compatibility.
- [ ] Run successful/failed/cancelled/timeout/delayed payments and full/partial refunds in the real sandbox.
- [ ] Run forged/replayed/malformed/out-of-order event tests end to end.
- [ ] Verify configuration preservation, outstanding-order migration and safe rollback.
- [ ] Record source revisions, test results and package hash; obtain release review.

No merge or production deployment is authorised by this candidate. GPL-2.0-or-later.
