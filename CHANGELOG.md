# Changelog

## 1.1.0 — unreleased, BLOCKED — 15 November 2026

### Security and integrity

- Consolidate remote and local implementations; remove the hypothetical legacy API harness.
- Parse the source-verified ISO signed timestamp and string metadata; reject stale/future events.

- Preserve authenticated failed refund states; reject unknown states and malformed refund identifiers without authorising a retry.
- Flag exhausted payment verification retries for merchant review and add one persistent order note.
- Require a sufficiently strong webhook secret in all environments; reject malformed signatures, bodies and identifiers.
- Treat valid webhook events as status-check triggers, never direct authority to fulfil an order.
- Add canonical evidence checks for payment/session, merchant, reference, amount, currency and environment.
- Add connection-owned database locking, persistent multi-event deduplication and monotonic order transitions.
- Persist operation UUIDs and an ambiguity journal before financial operations. Refuse ambiguous retries instead of risking duplicates.
- Validate exact HTTPS checkout hosts; protect bridge pages with order keys, expiry, no-cache, no-referrer and CSP.
- Mask stored credentials in settings HTML; support server-side credential constants; redact remote errors.

### Integration and merchant tooling

- Separate policy, storage, adapter, payment service and WooCommerce UI.
- Add Checkout Blocks registration and HPOS compatibility declarations using WooCommerce CRUD.
- Preserve legacy settings and gateway identity; add explicit API URL and merchant configuration.
- Remove the misleading public-capabilities merchant verification; no credential-bearing test request is sent.
- Add payment/refund recovery logic against an internal evidence interface; no backend status/refund route is guessed.
- Add merchant diagnostics, bounded scheduled payment recovery, partial/full refund validation and refund-state tracking.

### Testing and packaging

- Add executable PHP regression suite, Blocks JavaScript tests and disposable WooCommerce integration checks.
- Add PHP 8.1–8.5 and HPOS on/off CI jobs; artifact creation depends on test jobs.
- Replace automatic tag publication with development artifact packaging only.
- Remove unverified method/country/embedded-checkout claims from current documentation.

### Unresolved release blockers

- Payment adapter intentionally disabled pending source-backed backend contract and sandbox validation.
- Consolidated PHP and real WooCommerce lifecycle suites are executed through CI; see TESTING.md for evidence.
- GitHub access restored; consolidated work targets existing draft PR #3.
- Not production-ready; not a release candidate.

## 1.0.0 — existing release

Gateway registration, API-key settings, environment selection, hosted redirect, optional raw-body HMAC webhook checks, generic refund request and release packaging. Historical release notes overstate embedded checkout and method/country availability.
