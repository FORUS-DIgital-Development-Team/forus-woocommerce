#!/usr/bin/env bash
set -Eeuo pipefail
[[ $EUID == 0 ]] || exit 1
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
: "${ADMIN_EMAIL:?Set ADMIN_EMAIL}"
if ! command -v docker >/dev/null; then
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl
  install -m 0755 -d /etc/apt/keyrings
  curl --fail --silent --show-error https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  . /etc/os-release
  [[ $ID == ubuntu ]] || { echo 'This bootstrap requires Ubuntu.' >&2; exit 1; }
  printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu %s stable\n' \
    "$(dpkg --print-architecture)" "$VERSION_CODENAME" > /etc/apt/sources.list.d/docker.list
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
systemctl enable --now docker
bash install.sh
# A daily transaction-consistent database export accompanies the off-host disk snapshots.
printf '17 1 * * * root /bin/bash /opt/forus-merchant/deploy/merchant-site/backup.sh >> /var/log/forus-merchant-backup.log 2>&1\n' > /etc/cron.d/forus-merchant-backup
chmod 644 /etc/cron.d/forus-merchant-backup
printf '* * * * * root /bin/bash /opt/forus-merchant/deploy/merchant-site/run-cron.sh >> /var/log/forus-merchant-cron.log 2>&1\n' > /etc/cron.d/forus-merchant-cron
chmod 644 /etc/cron.d/forus-merchant-cron
bash backup.sh
