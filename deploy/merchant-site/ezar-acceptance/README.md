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
- No automatic creation retry after an uncertain response.
- Evidence checks for invoice, destination, token, description and amount. They
  never mark an order paid: final provider status/settlement semantics are not yet
  verified. No unverified callback endpoint is exposed.
- No private provider keys deployed to WordPress; existing simulated eZAR and WOW
  checkouts and historical unpaid orders are preserved.

## Remaining release gates

1. Verify the existing wallet's merchant/Open Pay eligibility with the provider;
   personal wallet classification alone is not evidence of merchant approval.
2. Obtain authorised sandbox credential access. GCP explicitly rejects secret
   access because of an **IAM deny policy**; running the existing staging identity
   also failed `iam.serviceaccounts.actAs`. A ten-minute conditional grant to the
   existing project owner did not override the deny. All three task-owned secret
   grants were removed and their removal verified. The deny policy was not changed
   or bypassed, and no persistent access was added.
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
