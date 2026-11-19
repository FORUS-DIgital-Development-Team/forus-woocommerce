## Sandbox refund candidate — 17 November 2026

The persistent demo is deployed at https://shop.forus.digital, with its gateway paused after Ecentric returned the browser to an old WOW URL. Live payment support remains unconditionally disabled. The current candidate adds a real sandbox refund transport behind the separate `FORUS_WC_ENABLE_TEST_REFUNDS` opt-in and matching authenticated backend capability. Neither refund enablement nor provider acceptance is claimed.

The backend now implements durable refund reservations, cumulative limits, immutable caller keys, exact SOAP/RTTQ binding and mandatory mTLS using the existing registered certificate. WooCommerce persists the refund lookup key before POST, then uses GET after an uncertain result. A confirmed asynchronous refund is recorded locally with `refund_payment=false`, under the order lock, without repeating the provider request. If intervening manual accounting makes attribution ambiguous, recovery stops for review. Synchronous confirmed refunds remain accounted by WooCommerce's normal caller. A crash after local creation but before its final checkpoint is handled conservatively and can require manual review.

The registered PFX/private key has not been recovered from the old Rail Connector cloud computer, and Ecentric's sandbox response profile still targets the old WOW site. These prevent genuine end-to-end payment/refund and settlement acceptance. Tests with synthetic evidence, mocked HTTP, a local mTLS server and disposable WooCommerce orders do not satisfy that gate. The new code stays disabled on the public shop until those tests pass.

The historical sections below describe earlier revisions; their claims that refunds, status queries or lookup-by-key are unimplemented are superseded by this section. Current deployment and CI evidence is recorded in `deploy/merchant-site/DEPLOYMENT-2026-11-17.md`.

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

# Release readiness - 15 November 2026

**Classification: BLOCKED. Not production-ready.**

GitHub repository access is restored. Work continues on existing draft PR #3; there is no production merge, release or deployment.

## Consolidated implementation

The local modular engine replaces the hypothetical legacy API harness on the remote branch. It adds persistent per-order journals, database locks, status-driven payment verification, refund evidence tracking, bounded recovery with merchant alerts, masked credentials, strict hosted form destinations, merchant diagnostics and HPOS/Blocks integration.

Merchant webhook parsing matches PR #29 raw-body HMAC, signed ISO createdAt and string order IDs. The public capabilities response is no longer represented as merchant authentication. No financial API or credential-bearing connection request is made by the disabled adapter.

## Acceptance gates

| Gate | State |
|---|---|
| GitHub access | Resolved; all FORUS repositories linked |
| Earlier remote PHP suite | PASS on e7fda291, run 43462103102; not evidence for later changes |
| Consolidated PHP 8.1-8.5 suite | PASS: 102 assertions on each version at ec7bf50; run 43693603597 |
| Real WooCommerce and HPOS on/off | PASS: lifecycle, single stock reduction, cumulative refunds and database contention with test adapter |
| JavaScript / source checks | Run locally before each upload |
| Backend merchant API | BLOCKED: PR #29 unmerged and critical guarantees incomplete |
| Production adapter | Disabled; no live checkout or refunds |
| Refund accounting recovery | Tracking exists; automatic async bookkeeping/lookup-by-key incomplete |
| Browser Classic/Blocks journeys | Outstanding |
| Real provider sandbox E2E | Outstanding; verified test environment/credentials needed |
| Legacy pending-order migration | No destructive migration; verified mapping/reconciliation still required |
| ZIP | PASS: all 18 packaged files validated; development artifact generated by CI |
| Critical/high security acceptance | Outstanding for complete plugin/backend/provider path |

## Required completion sequence

1. Resolve backend evidence-binding, status, idempotency, refund and outbox gaps in API-AUDIT.md.
2. Implement the plugin adapter against the approved versioned contract and prove timeout recovery.
3. Complete asynchronous local refund accounting, legacy in-flight migration and reactivation recovery.
4. Pass PHP, real database concurrency, Classic/Blocks browser, upgrade/rollback and minimum-version checks.
5. Run the real sandbox payment/refund matrix and reconcile provider records.
6. Close critical/high findings, inspect the exact ZIP and obtain approval before merge/release/deployment.

Do not install this build on a live merchant store: FORUS payment availability remains false.

