# shop.forus.digital installation

## Deployment continuation — 17 November 2026

**Deployed at https://shop.forus.digital/**. Read [the current deployment and acceptance record](DEPLOYMENT-2026-11-17.md) first. Store/admin access, TLS, backups, off-host restore and sandbox API connectivity are verified. An actual 3DS test exposed a stale provider redirect; new checkout is paused and the pending order is preserved. Provider acceptance and live/refund implementation remain incomplete.

The user selected **shop.forus.digital** for the demonstration store. Preserve both the existing `merchant.forus.digital` Manus assignment and the working Keycloak-protected ICBA portal at `https://merchant.icba.forus.coop/`. The former currently serves a Manus "Site Unavailable" page; that does not authorize replacing it. The ICBA service is separately deployed as `icba-merchant-production` in the production project.

Authenticated GCP CLI, GitHub CLI/connector and Cloudflare browser access are now verified. Use the actual upstream branch history. The installer defaults and production hostname guard now select `shop.forus.digital`. The cloud script defaults to `DNS_PROVIDER=cloudflare`, verifies authoritative nameservers, and prepares the isolated host without changing DNS. After installation it prints the new DNS-only A record to create through authenticated Cloudflare management. It never overwrites an existing record or migrates the DNS zone. `DNS_PROVIDER=google` retains the guarded Cloud DNS path for an actually authoritative Cloud DNS zone.

Deployed target: `forus-prod-503017`, `africa-south1-a`, isolated `forus-merchant-demo` VM/network. Do not rerun resource creation against this existing deployment. For a new approved installation, the provisioning script supports Cloudflare DNS preparation; inspect resources before resuming a partial run. Deployment verification and payment acceptance are recorded separately.

## Historical installer validation - 17 November 2026

Commit `cf92a7339cebd3ae79e6d1980719bd2eb382f453` passed the complete container installation/restart/backup-restore job in [run 44018352178](https://github.com/FORUS-DIgital-Development-Team/forus-woocommerce/actions/runs/44018352178). The eight plugin validation/packaging jobs also passed in [run 44018352170](https://github.com/FORUS-DIgital-Development-Team/forus-woocommerce/actions/runs/44018352170). This proves the installation package in a disposable runner, not an external deployment or live payments.

Public DNS from that runner revealed an existing hostname assignment:

- Authoritative nameservers: `april.ns.cloudflare.com` and `quentin.ns.cloudflare.com`.
- `merchant.forus.digital` resolves through `forusconfig-7mhqfp4d.manus.space`.
- The organization's `forus-merchant-config-platform` repository is a React/Express merchant configuration portal, not a WordPress installation. Its HTML title is FORUS Merchant Configuration Platform. Repository source alone does not verify the current live application version.

**Preserve this existing DNS assignment and application.** Earlier claims that the hostname was unused were not supported by a successful DNS check. No DNS record or existing portal has been changed by this work. Decide how the WooCommerce demonstration store should coexist with the portal before any domain cutover. The GCP/Cloud DNS provisioning script deliberately refuses this current configuration before creating resources: Cloudflare is authoritative and the hostname already exists. It is a guarded preparation script, not a validated deployment path for this domain. Authenticated Cloudflare access and an agreed coexistence/cutover plan are still required.

This installs a persistent **demonstration store**, separate from payment release approval. It does not create a live payment capability. The matching plugin source is included in the package, while all checkout gateways are disabled until the paired backend sandbox is provisioned. This avoids accepting real orders with incomplete payment processing.

## What is prepared

- WordPress, WooCommerce, the FORUS candidate, HPOS, ZAR, a demonstration product, shop/cart/checkout pages and a clear test-store notice.
- Dedicated persistent MariaDB and WordPress storage. Only the reverse proxy publishes ports. Database access is confined to its internal container network.
- Caddy issues HTTPS after the hostname resolves to the host. The public listener starts only after WordPress has an administrator and the initial configuration is complete.
- Generated database/admin secrets stay on the host. Re-running the installer preserves passwords, the installed FORUS version and demonstration data. Container digests are recorded and reused on that host.
- Database/file backup script. GCP setup adds daily off-host disk snapshots retained for 14 days and a daily application backup. Application backup retention needs a policy after the first restore is verified; monitor disk space.
- CI checks a real installation, public pages, protected paths, gateway isolation, HPOS, repeat installation, restart persistence and database backup restore. The local CI HTTP endpoint does not certify public DNS or TLS.

## Administration and new installations

Use authenticated GCP CLI with IAP/OS Login for the existing host and Cloudflare for authoritative DNS. These access paths worked in this deployment session. No credentials should be copied into this repository or chat.

The GCP script creates a dedicated `forus-merchant-demo` VPC/subnet, static IP, Ubuntu 24.04 e2-medium VM with a retained 50 GB disk, scoped web/IAP firewall rules and snapshot schedule. These are billable resources. The VM has no GCP service account. Administration uses OS Login and IAP; the signed-in operator must already have the necessary compute, OS Login, IAP and DNS permissions. The script does not grant IAM privileges.

Hosting and DNS project IDs must be supplied explicitly. Existing FORUS source identifies `forus-prod-503017` and `forus-staging-503017`; actual DNS is Cloudflare, as recorded above. The script checks DNS authority and refuses to overwrite any existing record or named infrastructure. For this domain, stop and use Cloudflare's authenticated management path rather than creating an ineffective Cloud DNS zone.

For a new deployment only, set `PROJECT_ID` and `ADMIN_EMAIL` (`DNS_PROJECT_ID` is required only for authoritative Google Cloud DNS), then run:

```bash
bash deploy/merchant-site/provision-gcp.sh
```

It checks access/DNS, packages the plugin, creates the isolated host and installs the store. With Cloudflare it prints the exact DNS record for authenticated creation, then checks public HTTPS. It never merges or deploys Rail Connector. A partial provisioning failure leaves resources intact and identifies the failing step. Do not repeat creation blindly; inspect and resume using the printed resource names.

For an existing dedicated Ubuntu host with Docker, transfer only the compose/configuration/scripts and validated `dist/` package, then run `sudo env ADMIN_EMAIL=your-verified-address bash deploy/merchant-site/install.sh`. Configure DNS and run `smoke.sh` after installation. `host-setup.sh` is specifically for the `/opt/forus-merchant` path used by the GCP script.

Administrator username is `forus-admin`; retrieve the generated password through authenticated host access from `/opt/forus-merchant/deploy/merchant-site/secrets/admin_password`. It is never logged or uploaded as a test artifact. Configure the final administrator access and operational notifications after first login; outbound mail delivery is not configured by this package.

## Acceptance status

1. DNS, trusted HTTPS, administrator login, restart persistence and off-host restore verified. Alert routing and outbound email remain unconfigured.
2. Matching isolated PR #90 backend, registered test merchant, scoped key, provider endpoint/secret, fixed callback and OIDC scheduler deployed.
3. Actual Blocks/HPOS checkout reached provider 3DS, then encountered the provider's stale WOW success redirect. New checkout is paused. Correct the dedicated provider response configuration before completing success/decline/cancel and missed-callback acceptance.
4. Complete live provider and refund implementation/acceptance before enabling real payments. User deployment approval is already given; provider configuration, certificate availability and functional acceptance remain the dependencies.

## Recovery

`backup.sh` creates root-only SQL and file archives, including configuration/secrets. Never upload these archives as CI artifacts. The database dump is transaction-consistent; quiesce the application for a coordinated database/uploads backup of an active store. Disk snapshots are an additional recovery layer, not proof of application-consistent restore.

For rollback, disable the FORUS gateway first and preserve all order/session evidence. Restore to a separate host/database and validate it before changing DNS. No script drops live databases, removes volumes, replaces existing infrastructure or deletes payment records. The VM uses deletion protection and its boot disk is retained.

Provider references: [official WordPress container](https://hub.docker.com/_/wordpress), [Docker installation](https://docs.docker.com/engine/install/ubuntu/), [GCP snapshot schedules](https://docs.cloud.google.com/compute/docs/disks/scheduled-snapshots).
