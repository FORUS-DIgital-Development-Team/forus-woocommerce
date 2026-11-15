# Consolidate payment integrity and verify real WooCommerce lifecycle

PR #3 combines the remote security foundation with the local modular implementation. Signed callbacks now match the inspected merchant proposal and can only trigger authenticated status checks. The public capabilities endpoint cannot masquerade as a merchant connection test.

Includes per-order database serialization, persistent create/refund ambiguity journals, durable event deduplication, merchant review diagnostics, strict hosted form destinations, Blocks registration and WooCommerce CRUD/HPOS integration. The prior gated legacy API harness is removed; all financial operations remain unavailable until the backend contract and sandbox gates pass.

Validation: PHP 8.1-8.5 regressions, focused coding baseline, real WooCommerce lifecycle and database contention with HPOS on/off, JavaScript behavior tests and exact development ZIP content validation. Current execution results are recorded in TESTING.md and GitHub Actions.

Dependencies: Rail Connector PR #29 needs reconciliation with current main, callback evidence binding, merchant enquiry, idempotent/cumulative refunds, ambiguous-operation recovery and durable delivery. Adapter implementation, actual sandbox/browser tests and in-flight migration remain blockers.

Do not merge or deploy as production-ready. Use a disposable store. Existing settings/journals are retained; pending legacy payments must be reconciled before any eventual upgrade. See UPGRADE.md.
