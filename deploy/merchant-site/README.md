# merchant.forus.digital installation

This installs a persistent **demonstration store**, separate from payment release approval. It does not create a live payment capability. The matching plugin source is included in the package, while all checkout gateways are disabled until the paired backend sandbox is provisioned. This avoids accepting real orders with incomplete payment processing.

## What is prepared

- WordPress, WooCommerce, the FORUS candidate, HPOS, ZAR, a demonstration product, shop/cart/checkout pages and a clear test-store notice.
- Dedicated persistent MariaDB and WordPress storage. Only the reverse proxy publishes ports. Database access is confined to its internal container network.
- Caddy issues HTTPS after the hostname resolves to the host. The public listener starts only after WordPress has an administrator and the initial configuration is complete.
- Generated database/admin secrets stay on the host. Re-running the installer preserves passwords, the installed FORUS version and demonstration data. Container digests are recorded and reused on that host.
- Database/file backup script. GCP setup adds daily off-host disk snapshots retained for 14 days and a daily application backup. Application backup retention needs a policy after the first restore is verified; monitor disk space.
- CI checks a real installation, public pages, protected paths, gateway isolation, HPOS, repeat installation, restart persistence and database backup restore. The local CI HTTP endpoint does not certify public DNS or TLS.

## Deployment access needed

Use an authenticated Google Cloud Shell/administrator terminal with access to the approved hosting project and authoritative DNS project. The current ChatGPT session cannot use the local GCP credential store, has no browser-control tool, and the WordPress connection is absent. No credentials should be copied into this repository or chat.

The GCP script creates a dedicated `forus-merchant-demo` VPC/subnet, static IP, Ubuntu 24.04 e2-medium VM with a retained 50 GB disk, scoped web/IAP firewall rules and snapshot schedule. These are billable resources. The VM has no GCP service account. Administration uses OS Login and IAP; the signed-in operator must already have the necessary compute, OS Login, IAP and DNS permissions. The script does not grant IAM privileges.

Hosting and DNS project IDs must be supplied explicitly. Existing FORUS source identifies `forus-prod-503017` and `forus-staging-503017`, but the authoritative DNS zone location has not been verified. The script checks DNS authority and refuses to overwrite any existing record or named infrastructure. If DNS is hosted elsewhere, stop and use that provider's authenticated management path rather than creating an ineffective Cloud DNS zone.

From this committed branch in Cloud Shell, set `PROJECT_ID`, `DNS_PROJECT_ID` and `ADMIN_EMAIL`, then run:

```bash
bash deploy/merchant-site/provision-gcp.sh
```

It checks access/DNS, packages the plugin, creates the isolated host, installs the store, creates the new DNS A record and verifies public HTTPS. It never merges or deploys Rail Connector. A partial provisioning failure leaves resources intact and identifies the failing step. Do not repeat creation blindly; inspect and resume using the printed resource names.

For an existing dedicated Ubuntu host with Docker, transfer only the compose/configuration/scripts and validated `dist/` package, then run `sudo env ADMIN_EMAIL=your-verified-address bash deploy/merchant-site/install.sh`. Configure DNS and run `smoke.sh` after installation. `host-setup.sh` is specifically for the `/opt/forus-merchant` path used by the GCP script.

Administrator username is `forus-admin`; retrieve the generated password through authenticated host access from `/opt/forus-merchant/deploy/merchant-site/secrets/admin_password`. It is never logged or uploaded as a test artifact. Configure the final administrator access and operational notifications after first login; outbound mail delivery is not configured by this package.

## Acceptance still required on the real host

1. Confirm DNS, trusted HTTPS, admin access, resource monitoring and the actual off-host snapshot/restore path.
2. Deploy and provision Rail Connector PR #90 in sandbox using its `docs/merchant-checkout-acceptance.md`; register this hostname, test merchant key, HPP endpoint, signing secret, callback routing and outbox scheduler.
3. Enable the plugin's sandbox opt-in only with those verified settings. Test real provider success/decline/cancel and browser Classic/Blocks flows, missed callbacks and duplicate submissions.
4. Complete live provider and refund implementation/acceptance before enabling any live payments. User approval to deploy has been received; missing access and functional acceptance remain the blockers.

## Recovery

`backup.sh` creates root-only SQL and file archives, including configuration/secrets. Never upload these archives as CI artifacts. The database dump is transaction-consistent; quiesce the application for a coordinated database/uploads backup of an active store. Disk snapshots are an additional recovery layer, not proof of application-consistent restore.

For rollback, disable the FORUS gateway first and preserve all order/session evidence. Restore to a separate host/database and validate it before changing DNS. No script drops live databases, removes volumes, replaces existing infrastructure or deletes payment records. The VM uses deletion protection and its boot disk is retained.

Provider references: [official WordPress container](https://hub.docker.com/_/wordpress), [Docker installation](https://docs.docker.com/engine/install/ubuntu/), [GCP snapshot schedules](https://docs.cloud.google.com/compute/docs/disks/scheduled-snapshots).
