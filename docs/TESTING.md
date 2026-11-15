# Verification and testing

## Verified consolidated build - 15 November 2026

Commit ec7bf50a0cc8a36aa04faf9bb500142db0693f84 passed all eight jobs in [GitHub Actions run 43693603597](https://github.com/FORUS-DIgital-Development-Team/forus-woocommerce/actions/runs/43693603597): five PHP jobs, two real WooCommerce jobs and packaging.

- 102 PHP assertions passed on each of PHP 8.1, 8.2, 8.3, 8.4 and 8.5, plus syntax and the focused PHPCS baseline.
- Both JavaScript behavior tests and source checks passed.
- Real WooCommerce lifecycle checks passed with HPOS enabled and disabled. Each run asserted the selected storage mode; tests covered settings preservation, secret masking, payment state, one stock reduction, cumulative refunds and two-connection database contention.
- All 18 packaged files passed structure and byte-content validation. The tested plugin ZIP SHA-256 is c85a3e8f3e861dc808f0de5ce87a8627e29751e2d2326042b85e1d4c190ea7ae. This hash identifies the ec7bf50 artifact, not a later documentation rebuild.
- Download the [tested development artifact](https://github.com/FORUS-DIgital-Development-Team/forus-woocommerce/actions/runs/43693603597/artifacts/14153226756). It expires on 15 December 2026.

These results remove the former PHP/HPOS execution blocker. They do not establish real provider sandbox, browser checkout or production readiness.

## Evidence before consolidation

Remote commit e7fda2919407ecfb729e3a5d928304daa97058a0 passed PHP 8.1-8.5 lint and its earlier helper/gateway regression tests in GitHub Actions run 43462103102. Those tests used WooCommerce doubles and do not certify subsequent changes.

## Consolidated tests

- `php tests/run.php`: exact amounts, destination validation, HMAC and timestamp rejection, string metadata, persistent replay protection, wrong evidence, changed orders, duplicate requests, ambiguity journals, terminal transitions, recovery scheduling, full/partial/failed/pending refunds and the permanent production gate.
- `node --test tests/blocks.test.mjs`: registration, availability and text-only rendering.
- `node scripts/check-source.mjs`: direct guards, unsafe APIs, order-access patterns and publication gate. Not a security certification.
- `FORUS_WC_TEST_ENV=1 FORUS_EXPECT_HPOS=yes wp eval-file tests/lifecycle.php` on a disposable store: real WooCommerce settings, actual selected order storage, database locks across connections, payment completion, stock reduction once, repeated partial refunds and no over-refund. Repeat with HPOS=no.
- PHP 8.1-8.5 and real WordPress/WooCommerce jobs are required before development ZIP packaging. Focused PHPCS baseline runs; full WPCS remains a release gate.

The integration test adapter is entirely in-process. These checks execute no provider request, create no real-money transaction and do not replace sandbox E2E. Local PHP is absent; CI is the execution environment for PHP/WooCommerce checks. The workflow records actual WordPress/WooCommerce versions.

## Required provider sandbox evidence

Record backend/plugin revisions, runtime versions, sandbox account (no secrets), payment/session/refund references and redacted results.

Run Classic and Blocks with HPOS on/off: successful, failed, cancelled and delayed payment; abandoned browser; concurrent and repeated checkout; timeout before/after provider acceptance; mismatched/forged/replayed/old events; missing-webhook recovery; full and repeated partial refunds; cumulative over-refund attempts; async success/failure; provider response loss; local persistence failure after remote success; legacy pending orders and rollback; hosted form expiry/destination tampering; configuration migration and secret leakage.

No live-money test is authorized by this checklist.

