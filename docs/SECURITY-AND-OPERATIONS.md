# Security and operational behaviour

## Current safety boundary

FORUS_API.ready() is hard-coded false. There is no merchant setting/filter to bypass it. Create, payment status, refund and refund status methods return an explicit error without HTTP. Classic and Blocks availability remain false. No customer return URL or webhook can mark an order paid in this package.

This is safe non-execution, not a claim that a production integration has been completed or certified.

## Implemented independent controls

- Amounts use bounded decimal-string conversion, with supported exponents supplied by the future verified adapter; no float rounding of payment totals.
- Canonical evidence checks session, merchant, reference, amount, currency and environment against a saved snapshot, also checking current order/configuration.
- Production status mapping is not implemented until backend verification. Test-adapter evidence is synthetic.
- Raw-body HMAC validation is constant-time, requires a minimum-strength secret, rejects malformed signatures and limits request size to 64 KiB.
- The inspected merchant proposal signs ISO UTC createdAt within the raw body. Events outside a 300-second window, invalid dates and ambiguous order identifiers are rejected. A valid event only triggers an authenticated current-status read; unsigned headers are never trusted.
- Event hashes are stored persistently on the WooCommerce order. All recorded events remain deduplicated; they are not reduced to the last event. At 4,096 events, new events fail closed for review instead of deleting replay history.
- Connection-owned MySQL named locks serialize plugin operations per site/order. No expiring lease can enable overlapping workers. Database topology, reconnection behaviour and multi-worker tests remain release gates.
- Before financial submission, a UUID and unknown-state journal are persisted. Ambiguous timeout/crash retries are refused. This prevents re-submission locally; it is not verified backend exactly-once processing.
- Paid/refunded orders are never downgraded by a failure event. A late payment on a cancelled or failed order sets a manual-review flag rather than authorising fulfilment.
- Checkout destination requires an exact approved HTTPS hostname; no userinfo, fragment, IP, unexpected port or backslash. Bridge access requires matching order key, valid state and unexpired session. The page uses a user-initiated POST form, no third-party scripts, no-cache, no-referrer and restrictive CSP.
- Gateway errors shown to customers are generic. Remote response bodies and transport errors are not exposed.

## Reconciliation

The service can apply authenticated canonical status and schedule bounded exponential payment checks using Action Scheduler. The shipped adapter cannot perform the read until the real endpoint is verified. Deactivation unschedules pending recovery work; records remain.

A network timeout never means payment failed. Do not delete an operation journal to make retry work. Resolve the operation by its merchant reference/idempotency key in the verified provider system; implement a reviewed recovery path before resubmission.

Do not interpret the browser return/cancel link as payment evidence. WooCommerce may locally cancel an abandoned order without cancelling a provider payment.

## Refunds

The engine validates full/partial amounts and bounds against remaining refundable value. It only returns success for a matching canonical completed refund. Accepted/pending responses do not produce a WooCommerce financial refund. Ambiguous requests block additional refunds.

Read-only refund tracking is implemented as a service method, but there is no verified backend binding or automatic asynchronous accounting repair. The operator must reconcile confirmed remote refunds with local WooCommerce records before another refund; never submit another remote refund to repair local bookkeeping. Adapter binding, scheduled refund tracking and audited local refund creation remain required work.

## Troubleshooting

| Symptom | Action |
|---|---|
| FORUS absent at checkout | Expected in this blocked build; do not bypass the gate |
| HTTP 401 callback | Check required secret, raw body and exact signature protocol; do not disable authentication |
| HTTP 503 callback | Contract/status/configuration or locking unavailable; retain provider retries |
| Changed-order/credential diagnostic | Reconcile original session under its original verified identity before migration |
| Unknown create/refund | Investigate provider record; do not resend |
| Named locks unsupported | Fail closed; validate hosting/database support |
| Secret input appears empty | Intentional masking; blank save retains existing credential |
| Merchant connection check unavailable | Public capabilities cannot authenticate an account; wait for a verified merchant endpoint |
| Late payment on cancelled order | Review stock and fulfilment, then resolve through a documented merchant process |

No refund or payment should be tested with live funds without explicit approval. A security review must cover the eventual backend adapter as well as this plugin.
