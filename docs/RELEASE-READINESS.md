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
| Consolidated PHP 8.1-8.5 suite | CI results pending; see TESTING.md |
| Real WooCommerce and HPOS on/off | Lifecycle tests authored; CI results pending |
| JavaScript / source checks | Run locally before each upload |
| Backend merchant API | BLOCKED: PR #29 unmerged and critical guarantees incomplete |
| Production adapter | Disabled; no live checkout or refunds |
| Refund accounting recovery | Tracking exists; automatic async bookkeeping/lookup-by-key incomplete |
| Browser Classic/Blocks journeys | Outstanding |
| Real provider sandbox E2E | Outstanding; verified test environment/credentials needed |
| Legacy pending-order migration | No destructive migration; verified mapping/reconciliation still required |
| ZIP | Development packaging only; generated only after CI gates pass |
| Critical/high security acceptance | Outstanding for complete plugin/backend/provider path |

## Required completion sequence

1. Resolve backend evidence-binding, status, idempotency, refund and outbox gaps in API-AUDIT.md.
2. Implement the plugin adapter against the approved versioned contract and prove timeout recovery.
3. Complete asynchronous local refund accounting, legacy in-flight migration and reactivation recovery.
4. Pass PHP, real database concurrency, Classic/Blocks browser, upgrade/rollback and minimum-version checks.
5. Run the real sandbox payment/refund matrix and reconcile provider records.
6. Close critical/high findings, inspect the exact ZIP and obtain approval before merge/release/deployment.

Do not install this build on a live merchant store: FORUS payment availability remains false.
