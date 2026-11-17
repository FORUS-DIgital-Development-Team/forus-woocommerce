#!/usr/bin/env bash
# Run from an authenticated Cloud Shell or Linux workstation. Never copies local credentials.
set -Eeuo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
: "${PROJECT_ID:?Set the approved GCP hosting project}"
: "${DNS_PROJECT_ID:?Set the GCP project containing the authoritative forus.digital DNS zone}"
: "${ADMIN_EMAIL:?Set the store administrator email}"
[[ $PROJECT_ID =~ ^[a-z][a-z0-9-]{4,28}[a-z0-9]$ && $DNS_PROJECT_ID =~ ^[a-z][a-z0-9-]{4,28}[a-z0-9]$ ]] || exit 1
[[ $ADMIN_EMAIL =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]] || exit 1
region=africa-south1
zone=africa-south1-a
name=forus-merchant-demo
domain=merchant.forus.digital
command -v gcloud >/dev/null
command -v python3 >/dev/null
account=$(gcloud auth list --filter=status:ACTIVE --format='value(account)')
[[ -n $account ]] || { echo 'Sign in to Google Cloud before provisioning.' >&2; exit 1; }
gcloud projects describe "$PROJECT_ID" --format='value(projectId)' >/dev/null
zones=$(gcloud dns managed-zones list --project="$DNS_PROJECT_ID" --filter='dnsName=forus.digital. AND visibility=public' --format='value(name)')
[[ -n $zones && $(wc -w <<< "$zones") == 1 ]] || { echo 'Exactly one authoritative public forus.digital Cloud DNS zone is required; no changes made.' >&2; exit 1; }
dns_zone=$zones
# Verify Cloud DNS is actually authoritative before provisioning or changing records.
gcloud dns managed-zones describe "$dns_zone" --project="$DNS_PROJECT_ID" --format=json > /tmp/forus-merchant-zone.json
python3 - <<'PY'
import json, subprocess
zone = json.load(open('/tmp/forus-merchant-zone.json'))
actual = set(subprocess.check_output(['dig', '+short', 'NS', 'forus.digital'], text=True).lower().split())
expected = set(x.lower() for x in zone['nameServers'])
if actual != expected:
    raise SystemExit('Cloud DNS is not the authoritative DNS provider; no changes made.')
PY
records=$(gcloud dns record-sets list --project="$DNS_PROJECT_ID" --zone="$dns_zone" --name="$domain." --format='value(type)')
[[ -z $records ]] || { echo 'The hostname already has DNS records; inspect the existing site before proceeding.' >&2; exit 1; }
# Create-only: never overwrite an existing VM, network, disk, address or policy.
for resource in instances networks addresses; do
  existing=$(gcloud compute "$resource" list --project="$PROJECT_ID" --filter="name=$name" --format='value(name)')
  [[ -z $existing ]] || { echo "Existing $resource named $name; resume deliberately, do not replace it." >&2; exit 1; }
done
revision=$(git rev-parse HEAD)
[[ $revision =~ ^[a-f0-9]{40}$ ]] || exit 1
[[ -z $(git status --porcelain --untracked-files=no) ]] || { echo 'Commit deployment sources before provisioning.' >&2; exit 1; }
python3 deploy/merchant-site/package.py
bundle="/tmp/forus-merchant-$revision.tgz"
tar -czf "$bundle" deploy/merchant-site/compose.yml deploy/merchant-site/Caddyfile \
  deploy/merchant-site/configure.php deploy/merchant-site/install.sh deploy/merchant-site/host-setup.sh \
  deploy/merchant-site/backup.sh deploy/merchant-site/smoke.sh dist/forus-woocommerce-v1.1.0.zip \
  dist/forus-woocommerce-v1.1.0.zip.sha256
digest=$(sha256sum "$bundle" | cut -d' ' -f1)
echo "Provisioning dedicated test site $domain in $PROJECT_ID ($zone), source $revision."
gcloud compute networks create "$name" --project="$PROJECT_ID" --subnet-mode=custom
gcloud compute networks subnets create "$name" --project="$PROJECT_ID" --network="$name" --region="$region" --range=10.92.0.0/24
gcloud compute firewall-rules create "$name-web" --project="$PROJECT_ID" --network="$name" --allow=tcp:80,tcp:443 --source-ranges=0.0.0.0/0 --target-tags="$name"
gcloud compute firewall-rules create "$name-iap" --project="$PROJECT_ID" --network="$name" --allow=tcp:22 --source-ranges=35.235.240.0/20 --target-tags="$name"
gcloud compute addresses create "$name" --project="$PROJECT_ID" --region="$region"
ip=$(gcloud compute addresses describe "$name" --project="$PROJECT_ID" --region="$region" --format='value(address)')
gcloud compute resource-policies create snapshot-schedule "$name-daily" --project="$PROJECT_ID" --region="$region" --daily-schedule --start-time=02:00 --max-retention-days=14 --on-source-disk-delete=keep-auto-snapshots
gcloud compute instances create "$name" --project="$PROJECT_ID" --zone="$zone" --machine-type=e2-medium \
  --network-interface="subnet=$name,address=$ip" --tags="$name" \
  --image-family=ubuntu-2404-lts-amd64 --image-project=ubuntu-os-cloud \
  --boot-disk-size=50GB --boot-disk-type=pd-balanced --no-boot-disk-auto-delete \
  --no-service-account --no-scopes --shielded-secure-boot \
  --metadata=enable-oslogin=TRUE,block-project-ssh-keys=TRUE \
  --labels=app=forus-merchant,environment=test --deletion-protection
gcloud compute disks add-resource-policies "$name" --project="$PROJECT_ID" --zone="$zone" --resource-policies="$name-daily"
for attempt in $(seq 1 30); do
  if gcloud compute ssh "$name" --project="$PROJECT_ID" --zone="$zone" --tunnel-through-iap --quiet --command=true; then break; fi
  sleep 5
done
gcloud compute scp "$bundle" "$name:/tmp/" --project="$PROJECT_ID" --zone="$zone" --tunnel-through-iap --quiet
gcloud compute ssh "$name" --project="$PROJECT_ID" --zone="$zone" --tunnel-through-iap --quiet \
  --command="echo '$digest  $bundle' | sha256sum -c - && sudo install -d -m0755 /opt/forus-merchant && sudo tar -xzf '$bundle' -C /opt/forus-merchant && sudo env ADMIN_EMAIL='$ADMIN_EMAIL' bash /opt/forus-merchant/deploy/merchant-site/host-setup.sh"
# Add only a new record. An intervening DNS change fails instead of being overwritten.
gcloud dns record-sets create "$domain." --project="$DNS_PROJECT_ID" --zone="$dns_zone" --type=A --ttl=300 --rrdatas="$ip"
for attempt in $(seq 1 30); do
  if bash deploy/merchant-site/smoke.sh "https://$domain"; then
    echo "HTTPS site checks passed: https://$domain (sandbox payments still require backend configuration)."
    exit 0
  fi
  sleep 10
done
echo 'Resources were created, but HTTPS checks did not pass. Preserve them and investigate DNS/Caddy; do not rerun creation.' >&2
exit 1
