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

## Sandbox provider evidence (19 November 2026)

The dedicated shop key was approved, created and restricted to sandbox account,
token and receiving Open Pay endpoints, with request limits. Production access,
payer debits, sends, withdrawals and refunds are excluded. It is encrypted outside
Git, with a verified dedicated Secret Manager version in the staging GCP project
replicated in `africa-south1`. No secret IAM grants or runtime changes were made;
it is not deployed to WordPress. The key has endpoint restrictions; the
server must still bind the intended receiving account.

Authenticated account and token reads passed with this key. The existing FORUS
organizational sandbox wallet was located in the provider's KYB correspondence and
matched by account, owner profile and address. That wallet is a test fixture only;
the shop's intended production destination remains the merchant's original wallet.
Neither that match nor the provider's personal-wallet category proves merchant
eligibility or completed KYB.

One standalone, unpaid sandbox invoice request was submitted at 12:43:15 UTC with
reference `FORUS-shop-acceptance-20261119-01`. CloudGate session `27879054` records
upstream HTTP **302 Found**, `IsError: true`, and `Result: null`. No invoice ID or
hosted URL was returned. A later read of an all-zero nonexistent invoice ID also
returned 302, no `Location` header, and the non-JSON body `Found Found - ` despite
an `application/json` content type. This is not a verified hosted checkout.

The creation was not repeated. Its durable journal is retained for provider
reconciliation because an error response does not prove no invoice was created.
No payment, callback or provider refund has passed. Regression tests reproduce
the 302 response and verify that another worker cannot submit a second invoice.
The exact incident and required provider changes are prepared in an unsent local
brief; provider workflow credentials and shared WOW secrets were not changed.

## Remaining release gates

1. Verify the existing wallet's merchant/Open Pay eligibility with the provider;
   personal wallet classification alone is not evidence of merchant approval.
2. Resolve the provider's sandbox Open Pay 302 failure and reconcile the single
   existing invoice attempt before another creation. The dedicated credential's
   account/token reads pass; a broader credential is not justified by this error.
   Existing WOW application secrets remain isolated and must not be reused.
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
backs up the site, installs the runtime files, records the supplied verified
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
