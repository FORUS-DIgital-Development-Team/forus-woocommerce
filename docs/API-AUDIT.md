# API and backend audit - 15 November 2026

## Source evidence

GitHub access was repaired: the FORUS installation now includes all repositories. The plugin target branch is feature/woocommerce-v1.1-hardening, PR #3, previously e7fda2919407ecfb729e3a5d928304daa97058a0. The remote main plugin remains d6501155fd02102c4ca97eb61e2989026a82dc8d.

Rail Connector main tree/ref returned fa983b6e3f84fcb0aa85aa775bb5e43d8541f9c3. Relevant inspected blobs:
- apps/connector-api/src/routes/payments.routes.ts: 9d0c3d77c174979208420fce6eb87badb21f79d7
- packages/ecentric/src/hpp.ts: 12e4f8f86b894096716c58eb46c673a1012fe764
- packages/db/src/ecentric-schema.ts: 9ca5be45bf07369703c806cee58d116ae80cf70c
- apps/connector-api/src/plugins/auth.plugin.ts: c01178372e1c45dc4e40a96657d0f3ef8214b0b0

Merchant checkout PR #29 remains open at b7b15193b704c7744b6d1ba772e6539629125ff4, with merge conflicts against current main. The merchant route is absent from the inspected current main tree. Source availability does not prove deployment.

## Verified contracts and gaps

| Surface | Observed source | Decision |
|---|---|---|
| Generic create | POST /v1/payments, major-unit amount, tenantId/customerId/orderNumber/returnUrl; transactionId/status/redirectPayload | Not compatible with v1.0 plugin payload or merchant HPP contract |
| Generic status | GET /v1/payments/{transactionId}; amount/currency/orderNumber/status | No merchant identity in response; route query is by transaction ID only; not sufficient merchant payment evidence |
| Generic refund | POST /v1/payments/{transactionId}/refunds; major-unit amount | No demonstrated cumulative/idempotent refund guarantee |
| Merchant create in PR #29 | POST /v1/payments/merchant-sessions; merchantReference/amountMinor/ZAR/returnUrl/cancelUrl/webhookUrl/webhookSecret/string metadata; returns id/status and POST checkout form | Real proposed source, unmerged; not enabled |
| Merchant idempotency in PR #29 | Selects tenant:merchant:reference; unique DB index; rebuilds form from incoming payload | Does not compare immutable payload on reuse; race/conflict handling and duplicate provider submission must be proven |
| Merchant refund in PR #29 | Scoped POST /merchant-sessions/{id}/refunds; provider call precedes refund insert | Missing durable pre-call claim, cumulative bound and recovery by key; not enabled |
| Merchant status/refund status | No dedicated route in PR #29 | Critical dependency; no guessed GET requests |
| Merchant webhook | Raw-body lowercase hex HMAC-SHA256; createdAt ISO UTC, string order_id metadata, stable tx/status ID | Parser now matches signed timestamp/string routing. Callback only triggers authenticated status enquiry; never pays an order directly |
| Public capabilities | GET /v1/capabilities returns deployment capabilities | Not merchant authentication; removed the misleading credential-bearing connection test |
| Methods/countries/embedded | No operational merchant evidence | Not advertised |

## Backend security findings to resolve before adapter enablement

1. PR #29 provider callback validates checksum but does not bind its reference/amount to the selected session before updating state. Invalid callbacks become DECLINED and can overwrite existing payment state. Add evidence binding and atomic terminal-state guards.
2. Merchant create must reject same-key/different-payload requests and return a single immutable session under concurrent creation; prove the provider cannot charge repeatedly on a replayed hosted form.
3. Add tenant-and-environment-bound authenticated session and refund enquiry, including immutable merchant/reference/amount/currency evidence and recovery using the original operation key.
4. Reserve refund value and operation key durably before the provider call. Enforce cumulative refunds under concurrency; distinguish rejected, pending and ambiguous results. Never retry an ambiguous provider call without provider-side idempotency or authoritative recovery.
5. Replace fire-and-forget webhook delivery with durable outbox retries, explicit no-redirect delivery, stable events and documented signed timestamp/expiry behaviour.
6. Preserve current main authentication changes when rebasing PR #29. Do not import its unrelated historical KYC route edits.
7. Supply sandbox deployment revision, test merchant credentials, currency exponents, approved HPP destinations and provider settlement/refund evidence.

## Plugin boundaries

FORUS_Adapter is an internal normalized interface, not an assertion about a deployed API. Production implementation remains disabled. The plugin's fresh webhook window is 300 seconds around the signed createdAt value. Late deliveries cannot authorize payment; authenticated status polling/manual reconciliation must recover them. The backend retry protocol still requires final agreement.

FORUS Identity is not needed for ordinary WooCommerce checkout; WooCommerce owns order/customer authorization. Platform administrator keys must never be placed in WordPress. Register onboarding and Control Tower exports are additional opportunities, not unverified integrations shipped in this release.
