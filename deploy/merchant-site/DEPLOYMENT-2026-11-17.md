# FORUS demonstration deployment — 17 November 2026

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
