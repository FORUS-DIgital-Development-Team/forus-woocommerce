# Verification and testing

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
