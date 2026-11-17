# FORUS demonstration deployment — 17 November 2026

Follow-up GCP recovery audit: all 486 enabled October secret-backup versions, 18 non-Keycloak SQL export objects, 268 build tar archives and 70 Rail/Admin application images were inspected without recovering the Ecentric client key. A read-only check of the current production Admin database found no Ecentric tenant-rail, integration or network-rail records. The temporary audit job was removed. The 14 deployed safety/gate checks passed again; order 16 stays pending/unpaid, and all public/live/refund execution gates remain off. The Ecentric profile is still unchanged. The user explicitly chose to keep the prepared provider email unsent. See the Rail Connector's `docs/ecentric-gcp-recovery-audit-2026-11-17.md` for bounded search coverage and resumption requirements.

## Follow-up deployment: callbacks and refund recovery

The current candidate is deployed with all execution gates still off. This section supersedes the initial revision/package and unimplemented-refund statements below.

- Plugin source `2a86971dcc64710c12a8eff9288681bbebb1317b`; exact GitHub Actions ZIP SHA256 `5a15012ebdc1793c93b93882c86a832b377786cf2388ab18fc44e34eebad3112`, verified again on the host before installation. Plugin remains 1.1.0-dev. `/opt/forus-merchant/deployed-plugin-source.txt` records this SHA; the site's provisioned source record remains unchanged.
- Backend source `265180b4eb48b77d72460ed987c26542013b450c`; image digest `sha256:8289008b847ff085131286ad9070838e996b6e94918a743f73705acabc10d9be`; current revision `forus-merchant-sandbox-00005-qgb`. Cloud Build `cc6f4391-e0cf-4b2f-940c-3cabd1007d45` succeeded.
- Additive migration 0012 completed in execution `forus-merchant-migrate-staging-vkcpv`, with 13 migration hashes verified. Cloud SQL backup `1794901733731` completed successfully before migration. Host backups `20261117T075606Z` and `20261117T075634Z` bracket the plugin upgrade.
- Signed cancellation/timeout failures can have a blank TransactionID; these remain failures and are stored with NULL transaction IDs. Success still requires an ID and exact signed binding.
- New sandbox-only mTLS SOAP/RTTQ transport verifies certificate identity, validity and matching private key. It uses the existing registered PFX or PEM and rejects production destinations. No replacement provider certificate was generated.
- Durable refund reservations enforce idempotency and cumulative limits. Uncertain refunds never repeat the provider POST. WooCommerce can recover by caller key and record a confirmed asynchronous refund locally without sending another refund.
- Backend full typecheck/test CI `44041347324` and focused merchant CI `44041347473` passed. The focused suite has 41 tests. Plugin CI `44041370399` passed all eight jobs: 103 engine assertions and 25 merchant-adapter assertions on PHP 8.1–8.5, real WooCommerce HPOS on/off accounting/recovery and package verification. Site installer CI `44041370382` passed.
- Deployed checks passed: duplicate create reuse, changed amount 409, auth enforcement, forged callback and duplicate fields rejected, uncertain order remains unpaid, unauthenticated refund POST 401, authenticated disabled refund POST 503, lookup 404 proving no refund reservation was created. Backend health/readiness and OIDC delivery remain healthy.
- Final configuration readback: environment=test, `MERCHANT_CHECKOUT_PROVIDER_API_ENABLED=false`, `MERCHANT_CHECKOUT_REFUNDS_ENABLED=false`, capabilities live=false/refunds=false. Store enabled=no; `FORUS_WC_ENABLE_TEST_REFUNDS` is unset/false. No live payment or provider refund occurred.

The user confirmed that all services have been ported; old Manus/Vercel host notes are historical, not current hosting instructions. Current dev/staging/prod service configurations, 243 retained revisions across the principal Rail/Admin services, and the October GCP backup's secret/runtime inventories contain no Ecentric client-certificate binding. The migration archive buckets and their October inventories are empty. The existing certificate's private key has not been located in the migrated GCP estate; this remains an unresolved migration/configuration dependency, not a request to return to the old hosting platform.

Ecentric's sandbox profile still uses the stale WOW callback URL. Real provider success/decline/cancel, missed-callback recovery, partial/full refunds and settlement reconciliation therefore remain outstanding. Keep the retained order 16 pending; do not repeat its uncertain charge. The callback profile and existing certificate must be restored/verified before running that acceptance matrix.

Public store: https://shop.forus.digital/

Administrator: https://shop.forus.digital/wp-admin/ — username `forus-admin`. The generated password remains root-only on the host at `secrets/admin_password`; retrieve through authenticated IAP/OS Login. Do not put it in chat or repository files.

The requested persistent storefront is deployed. **Payment acceptance is incomplete and new checkouts are paused.** Live payments and automatic refunds remain disabled. Both `merchant.forus.digital` and https://merchant.icba.forus.coop/ were preserved; the latter is linked from the store homepage.

## Hosting and recovery

- Project `forus-prod-503017`, zone `africa-south1-a`, VM/network/subnet/static IP `forus-merchant-demo`; public IP `34.35.159.94`.
- New Cloudflare DNS-only A record `shop`; existing merchant records unchanged. Trusted Caddy TLS verified externally.
- Ubuntu 24.04, e2-medium, retained 50 GB persistent disk, deletion protection and secure boot. No VM service account; SSH through IAP with OS Login. Only web ports 80/443 and source-restricted IAP SSH opened on the dedicated network.
- Deployment directory `/opt/forus-merchant/deploy/merchant-site`. WordPress 7.1.4, WooCommerce 11.3.0, HPOS and ZAR. Runtime container digests pinned in `images.env` and `installed-images.env`.
- Plugin source is PR #3 `c146a501dbb154b7953088d2e36b4e5e85655314`, packaged from the PR #4 tree. Installed ZIP SHA256 `2904a01245366b81186239eace1d1e7e9fe6778d5a1d26f0efb4e5a3b01197a7`. Version remains 1.1.0-dev.
- Daily root-only database/file backup at 01:17 UTC. Backup includes Compose override and mounted sandbox credentials. New system cron executes due WordPress events every minute with flock and a 240-second timeout; Action Scheduler execution observed.
- Daily off-host snapshot policy `forus-shop-daily`, explicit storage location africa-south1, 02:00 UTC, 14-day retention, preserve snapshots on source-disk deletion. Explicit location avoids a default snapshot API error selecting invalid `africa`.
- Initial snapshot `forus-shop-verified-20261117` was restored to a separate disk, mounted read-only, and its SQL export restored into a separate disposable database. URL/product/admin checks passed. Temporary disk detached/deleted afterward.
- Current paused-sandbox backup `20261117T060825Z` also restored into an isolated database successfully. Snapshot `forus-shop-sandbox-20261117` retains this configuration off-host.
- Secret transfer copies removed locally and on the VM after comparison with installed files; temporary portal job secret access revoked. Secrets remain in their approved Secret Manager resources and root-only host storage.

## Backend

API: `https://forus-merchant-sandbox-6kg73n2nhq-bq.a.run.app/v1`

Cloud Run service `forus-merchant-sandbox`, project `forus-staging-503017`, africa-south1. Revision `forus-merchant-sandbox-00003-fh4`, source `a27555a43abbd9d894b82158ce1f8c117c8c176f`. The shared staging Rail Connector service was not replaced. Additive migration 0011 applied successfully using the standard migration job.

Portal sandbox org 27, tenant `forus_woocommerce_demo`, exact host `shop.forus.digital`. Scoped payment key and webhook secret are mounted read-only; secret values are not stored in WordPress options. Backend advertises test/ZAR, live=false, refunds=false. OIDC-only Cloud Scheduler job `forus-shop-webhook-delivery` runs each minute in europe-west1; successful HTTP 200 executions verified.

## Acceptance evidence

- Public home/login/shop/cart/checkout HTTP 200; protected configuration, .git, .env and xmlrpc paths rejected. Real administrator authentication over HTTPS passed without logging credentials.
- Browser shopping/cart and Blocks checkout reached the live sandbox HPP with R1.00 and the intended unique reference. No real card or money was used.
- Order 16; backend session `b3db5ca1-219b-49f7-80c8-7f80f22c1129`; provider reference `1D38120CCB9A4FC1AA28006889572230`.
- Official provider 3DS success test card reached the simulator; Authenticate was selected. Ecentric then redirected to `https://www.ecttcwow.co.za/payment-succes`, which does not resolve, instead of the submitted FORUS callback. Provider branding was “WOW Mobile SB”. No authentic callback reached the backend, so **no successful payment is claimed**.
- Repeated identical API requests reuse the same session. Changed-amount request returns 409. Missing merchant/scheduler authentication returns 401. Forged callback returns 401; repeated form fields return 400. Authenticated reconciliation keeps the original backend session and WooCommerce order pending/unpaid.
- WordPress restart preserved settings, product and pending order. New checkout is disabled; website clearly describes paused sandbox validation.
- Backend 25 focused tests and TypeScript checks passed. CI `44028750542` and `44028750707` passed. Installer/8-job plugin CI at b72782f passed in `44027393499` / `44027393510`; later source checks are tracked on PR #4.

## Remaining work and exact external dependency

Ecentric must confirm a dedicated WooCommerce sandbox account or supported isolated routing before changing a potentially shared WOW profile. Both success and failure POSTs should reach:

`https://forus-merchant-sandbox-6kg73n2nhq-bq.a.run.app/webhooks/merchant-checkout`

The fixed callback endpoint is deployed and rejects invalid signatures. Confirm provider cancellation behavior, server notifications and duplicate-reference semantics. Then repeat real success, decline, cancel, dropped-webhook and settlement reconciliation tests. Current missing-callback checks prove preservation of uncertain status, not recovery of an unknown provider result.

The official SOAP WSDL exposes incompatibilities in the legacy refund adapter. A current approved mTLS client certificate/private key, provider query access and production credentials/activation have not been verified. Refund journaling, provider contract implementation, cumulative limits and uncertain-result recovery still require implementation and real sandbox acceptance. Do not remove the live/refund restrictions. Details are recorded in Rail Connector PR #90's `docs/merchant-checkout-acceptance.md`.

Outbound store email is not configured or verified. No notification-delivery claim is made. Local application-backup retention and monitoring/alert routing still need operational configuration; disk snapshots retain 14 days. No provider email was sent. PRs remain draft candidates, not a completed live release.
