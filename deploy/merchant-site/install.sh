#!/usr/bin/env bash
set -Eeuo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
umask 077
[[ $EUID == 0 ]] || { echo 'Run as root on the dedicated Linux host.' >&2; exit 1; }
: "${ADMIN_EMAIL:?Set ADMIN_EMAIL to the store administrator email}"
SITE_URL=${SITE_URL:-https://shop.forus.digital}
SITE_ADDRESS=${SITE_ADDRESS:-shop.forus.digital}
if [[ ${FORUS_LOCAL_TEST:-0} != 1 ]]; then
  [[ $SITE_URL == https://shop.forus.digital && $SITE_ADDRESS == shop.forus.digital ]] || exit 1
fi
[[ $ADMIN_EMAIL =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]] || { echo 'Invalid administrator email.' >&2; exit 1; }
command -v docker >/dev/null
docker compose version >/dev/null
test -s ../../dist/forus-woocommerce-v1.1.0.zip
test -s ../../dist/forus-woocommerce-v1.1.0.zip.sha256
(cd ../../dist && sha256sum -c forus-woocommerce-v1.1.0.zip.sha256)
# Never silently repoint an installed site or replace its saved administrator identity.
if [[ -f .env ]]; then
  grep -Fxq "SITE_URL=$SITE_URL" .env || { echo 'Existing site URL differs.' >&2; exit 1; }
  grep -Fxq "ADMIN_EMAIL=$ADMIN_EMAIL" .env || { echo 'Existing administrator differs.' >&2; exit 1; }
else
  printf 'ADMIN_EMAIL=%s\nSITE_URL=%s\nSITE_ADDRESS=%s\nBIND_ADDRESS=%s\nHTTP_PORT=%s\nHTTPS_PORT=%s\n' \
    "$ADMIN_EMAIL" "$SITE_URL" "$SITE_ADDRESS" "${BIND_ADDRESS:-0.0.0.0}" "${HTTP_PORT:-80}" "${HTTPS_PORT:-443}" > .env
fi
mkdir -p secrets state/wordpress
for name in db_password db_root_password admin_password; do
  if [[ ! -s secrets/$name ]]; then openssl rand -hex 32 > "secrets/$name"; fi
done
# Docker mounts these individual files; the enclosing host directory remains root-only.
chmod 444 secrets/db_password secrets/db_root_password
chmod 600 secrets/admin_password
chmod 700 secrets
chmod 755 configure.php
if [[ ! -f images.env ]]; then
  : > images.env.new
  for entry in 'WP_IMAGE wordpress:php8.3-apache' 'CLI_IMAGE wordpress:cli-php8.3' 'DB_IMAGE mariadb:11.4' 'CADDY_IMAGE caddy:2'; do
    read -r key tag <<< "$entry"
    docker pull "$tag"
    digest=$(docker image inspect "$tag" --format '{{index .RepoDigests 0}}')
    [[ $digest == *@sha256:* ]] || exit 1
    printf '%s=%s\n' "$key" "$digest" >> images.env.new
  done
  mv images.env.new images.env
fi
dc() { docker compose --env-file .env --env-file images.env "$@"; }
wp() { dc run --rm --no-deps cli "$@"; }
# Start with no public listener. Installation and package activation finish before Caddy starts.
dc up -d --wait --wait-timeout 240 db wordpress
for attempt in $(seq 1 60); do
  if dc exec -T wordpress test -s /var/www/html/wp-config.php; then break; fi
  sleep 2
done
dc exec -T wordpress test -s /var/www/html/wp-config.php
if ! wp core is-installed; then
  dc run --rm --no-deps --user 0:0 --entrypoint sh cli -c \
    'wp core install --allow-root --url="$SITE_URL" --title="FORUS Merchant Demo" --admin_user=forus-admin --admin_email="$ADMIN_EMAIL" --skip-email --admin_password="$(cat /run/secrets/admin_password)"'
  chown -R 33:33 state/wordpress
fi
if ! wp plugin is-installed woocommerce; then
  wp plugin install woocommerce --activate
else
  wp plugin activate woocommerce
fi
if ! wp plugin is-installed forus-woocommerce; then
  wp plugin install /package/forus-woocommerce-v1.1.0.zip --activate
else
  echo 'Existing FORUS package retained; upgrades require backup and explicit version selection.'
  wp plugin activate forus-woocommerce
fi
wp eval-file /configure.php
wp core version > installed-versions.txt
wp plugin list --format=json >> installed-versions.txt
cp images.env installed-images.env
dc up -d caddy
echo "Site installed at $SITE_URL; DNS and HTTPS must pass the separate smoke check."
echo 'Administrator: forus-admin. Password is in root-only secrets/admin_password on this host.'
echo 'Payments remain disabled until the backend sandbox is provisioned and accepted.'
