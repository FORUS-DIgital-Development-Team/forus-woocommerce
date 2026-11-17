#!/usr/bin/env bash
set -Eeuo pipefail
base=${1:-https://merchant.forus.digital}
for path in / /wp-login.php /shop/ /cart/ /checkout/; do
  code=$(curl --silent --show-error --location --retry 10 --retry-all-errors --retry-delay 2 --max-time 30 --output /dev/null --write-out '%{http_code}' "$base$path")
  [[ $code == 200 ]] || { echo "FAIL $path HTTP $code"; exit 1; }
  echo "PASS $path"
done
body=$(curl --fail --silent --show-error --max-time 30 "$base/")
grep -q 'Explore FORUS checkout' <<< "$body"
for path in /wp-config.php /.env /.git/config /xmlrpc.php; do
  code=$(curl --silent --show-error --max-time 30 --output /dev/null --write-out '%{http_code}' "$base$path")
  [[ $code == 404 ]] || { echo "FAIL protected path $path HTTP $code"; exit 1; }
done
echo 'PASS site pages and protected paths. This does not prove provider checkout.'
