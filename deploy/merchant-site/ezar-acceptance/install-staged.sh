#!/usr/bin/env bash
set -Eeuo pipefail
[[ $EUID == 0 ]] || exit 1
release_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
binding_dir=${1:?Verified binding directory required}
cd /opt/forus-merchant/deploy/merchant-site
dc() { docker compose --env-file .env --env-file images.env "$@"; }
dc run --rm -T --entrypoint sh -v "$release_dir:/ezar-release:ro" cli -ec 'for f in /ezar-release/*.php; do php -l "$f"; done; php /ezar-release/test-openpay.php'
dc run --rm -T cli eval 'if(get_option("siteurl")!=="https://shop.forus.digital"||wp_get_environment_type()!=="staging"||get_option("woocommerce_forus_settings")["enabled"]!=="no"){WP_CLI::error("Unexpected target");}'
bash backup.sh
target=state/wordpress/wp-content/plugins/forus-ezar-acceptance
mkdir -p "$target"
cp "$release_dir/forus-ezar-acceptance.php" "$release_dir/openpay-client.php" "$target/"
chown -R 33:33 "$target"
dc run --rm -T cli plugin activate forus-ezar-acceptance
dc run --rm -T -v "$release_dir:/ezar-release:ro" -v "$binding_dir:/ezar-binding:ro" cli eval-file /ezar-release/stage-merchant.php
dc run --rm -T -v "$release_dir:/ezar-release:ro" cli eval-file /ezar-release/verify-staged.php
