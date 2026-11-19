# Existing-wallet eZAR acceptance candidate

This component records the shop's intended receiving wallet and prepares the WOW
Open Pay client. Version 0.1.0 **cannot accept live payments or refunds**, even if a
WordPress gateway option is changed. It does not register a new wallet, change its
provider category, or claim merchant approval.

The merchant's email was matched to the existing production wallet identity in
FORUS Connect. A separate authenticated CloudGate account read confirmed its owner,
address and unfrozen state on 19 November 2026. CloudGate currently classifies that
wallet as Personal / Personal Level 1. The binding contains no API credentials and
is stored in a non-autoloaded WordPress option. Only WooCommerce administrators can
view it at **WooCommerce → eZAR merchant**. Private identifiers are omitted here.

## Provider contract

The current tenant API catalogue at <https://hub.cloudgate.dev/api> lists:

- `POST /{env}/openpay/payment?accountId=…`: receiving-account hosted invoice;
  body `description`, `amount`, `tokenId`, `rewardTokenId`,
  `rewardsEarnedPercentage`, `webhookUrl`.
- `GET /{env}/openpay/{payId}`: invoice data.
- `GET /{env}/openpay/payment/{payId}`: payment frame.

These differ from `POST /payments/accounts/{accountId}/create?paymentId=…`,
which **debits the payer's account**. The receiving integration never calls that
endpoint, send, withdrawal or refund endpoints. The older rail bearer-token
adapter is not reused. Requests use HMAC-SHA512 over millisecond timestamp, HTTP
method, exact environment-prefixed path including query, and exact transmitted
body. The signing secret stays server-side. Redirects are disabled.

The general CloudGate platform-wallet page is a separate onboarding flow and is
not used to create a replacement for the merchant's existing eZAR wallet.

## What is implemented

- Verified destination binding, administrative readiness page and distinct locked
  gateway ID `forus_ezar_openpay`.
- Explicitly sandbox-only signed invoice creation and invoice reads, with a fixed
  receiving account/token, exact decimal validation and shop-owned references.
- Permanent atomic reservation per WooCommerce order before provider I/O. The
  reservation binds sandbox environment, receiving account, token, currency and
  amount. Concurrent requests, timeouts, process restarts and failed result writes
  cannot issue a second invoice. Only the dedicated gateway's unpaid orders on
  the exact staging shop are eligible. This internal service has no public route.
- Evidence checks for invoice, destination, token, description and amount. They
  never mark an order paid: final provider status/settlement semantics are not yet
  verified. No unverified callback endpoint is exposed.
- No private provider keys deployed to WordPress; existing simulated eZAR and WOW
  checkouts and historical unpaid orders are preserved.

## Remaining release gates

1. Verify the existing wallet's merchant/Open Pay eligibility with the provider;
   personal wallet classification alone is not evidence of merchant approval.
2. Issue a dedicated restricted shop sandbox credential. The existing WOW GCP
   secrets are deliberately isolated by an IAM deny policy and must not be reused
   by another application. All temporary task-owned allow grants were removed
   and their removal verified; the deny policy was preserved. A shop-specific
   sandbox key is prepared in CloudGate, pending the required credential-creation
   confirmation. It permits account/token reads and receiving Open Pay invoice
   create/read/frame only, with request limits; it cannot debit or withdraw.
3. Verify sandbox invoice response, hosted URL, invoice idempotency/reconciliation,
   exact status and settlement evidence, callback authentication, expiry, failures,
   wrong amounts, duplicate callbacks and missed callbacks.
4. Implement and pass provider refund acceptance tests. No refund endpoint is
   assumed equivalent to reversing a received Open Pay invoice.
5. Complete the WooCommerce order integration and replace the hard live lock only
   after the end-to-end acceptance suite passes. A verified wallet binding or local
   test success is not a completed provider payment test.

## Validation and staging

`php test-openpay.php` runs network-free adversarial contract-safety checks.
`php test-invoice-attempt.php` exercises overlapping requests, lost responses,
failed writes, changed destinations/amounts and rejection of demo orders.
`wp eval-file verify-invoice-attempt.php` checks a disposable unpaid HPOS order
against two real database connections, including a conflicting insert, using
synthetic transport only. Its `finally` block removes only that test's order and
reservation. It never resets a real invoice attempt or calls the provider.
`install-staged.sh /absolute/binding/directory` checks the exact shop/staging target,
backs up the site, installs the two runtime files, records the supplied verified
`merchant.json`, and runs `verify-staged.php`. The latter checks that the new
gateway and refunds remain locked, the FORUS gateway remains disabled, only the
two original simulated gateways are publicly available, all six products remain,
and historical order 16 is still unpaid.

Merchant binding format (no secrets):

```json
{
  "merchant_name": "FORUS Store",
  "owner_email": "<verified Connect email>",
  "account_id": "<verified existing production account UUID>",
  "wallet_address": "<verified receiving address>",
  "wallet_verified_at": "<UTC timestamp>",
  "provider_wallet_category": "<provider-read classification>"
}
```
