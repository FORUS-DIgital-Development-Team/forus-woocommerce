# Upgrade and rollback

## Current development package

Do not upgrade a production installation to this build. The code gate makes FORUS unavailable and valid callbacks cannot be settled while the backend is unverified. Existing production systems were not changed in this task.

Use a restored staging copy or disposable store for review. The plugin retains the forus gateway ID, woocommerce_forus_settings option, existing credentials, environment, labels and order metadata. It does not automatically migrate an in-flight legacy payment into a merchant-session payment: there is no verified mapping.

## Eventual production upgrade

1. Pass all gates in RELEASE-READINESS.md and obtain approval.
2. Back up the database, plugin directory and secret configuration.
3. Inventory all unpaid/ambiguous legacy payments and pending refunds. Reconcile them under the old verified protocol.
4. Verify account, API environment, signing protocol and HPP allowlist.
5. Disable new FORUS checkouts for the controlled rollout window without discarding incoming settlement evidence.
6. Install the approved artifact, verify its checksum and enable only the verified merchant configuration.
7. Validate Classic and Blocks availability, a separately authorised production smoke test, callbacks and scheduled recovery.
8. Monitor orders, provider settlement, refunds and duplicate-event handling.

New installs default disabled. Existing enabled preferences remain saved, but cannot bypass the development release gate. No automatic credential rotation or live configuration change is performed.

## Rollback

Retain backups and the prior artifact. Reconcile sessions/refunds created under the new protocol before swapping versions; an old plugin may not understand new callback/session IDs. Do not delete metadata, restore an old database over settled transactions or reset journals to force a retry.

Deactivation preserves settings and journals and unschedules plugin recovery actions. After reactivation, pending recovery must be explicitly resumed through a verified implementation; this build provides no automatic upgrade migration for pending legacy orders.

Version 1.0.0 contains the known optional-webhook-authentication vulnerability. Returning to it is not a security remediation. Use a reviewed containment/reconciliation plan and keep another approved payment method available.
