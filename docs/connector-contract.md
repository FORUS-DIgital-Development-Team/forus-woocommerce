# FORUS Rail Connector Contract — WooCommerce 1.1

The WooCommerce gateway now uses the scoped merchant surface of the Rail Connector. It does not call the older generic payment route or assume that a hosted payment page is a single redirect URL.

| Function | Request | Successful response | Plugin behavior |
|---|---|---|---|
| Create payment session | `POST /v1/payments/merchant-sessions` | `{ id, status, checkout: { method: "POST", url, fields } }` | Stores the session and renders a same-origin bridge page that safely form-posts the provider fields. |
| Receive payment event | HTTPS POST to the configured WooCommerce callback | `X-FORUS-SIGNATURE` HMAC-SHA256 over the raw event body | Verifies signature, binds the event to its stored session and order, and ignores a repeated event ID. |
| Refund | `POST /v1/payments/merchant-sessions/{id}/refunds` | `{ id, paymentSessionId, status, amountMinor }` | Uses the stored payment-session ID rather than a provider transaction ID. |

The connector only creates a merchant session when its webhook encryption key and destination-host allowlist are configured. These controls prevent plaintext webhook secrets and arbitrary callback destinations.
