#!/usr/bin/env bash
set -Eeuo pipefail
[[ $EUID == 0 ]] || exit 1
release_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
site=/opt/forus-merchant/deploy/merchant-site
cd "$site"
dc() { docker compose --env-file .env --env-file images.env "$@"; }
dc run --rm -T --entrypoint sh -v "$release_dir:/merch-release:ro" cli -ec 'for file in /merch-release/*.php /merch-release/forus-store/*.php /merch-release/forus-demo-wallets/*.php; do php -l "$file"; done'
dc run --rm -T cli eval 'if(get_option("siteurl")!=="https://shop.forus.digital"||wp_get_environment_type()!=="staging"||get_option("woocommerce_forus_settings")["enabled"]!=="no"){WP_CLI::error("Unexpected host or live gateway state");}'
bash backup.sh
mkdir -p state/wordpress/wp-content/themes/forus-store state/wordpress/wp-content/plugins/forus-demo-wallets
cp -a "$release_dir/forus-store/." state/wordpress/wp-content/themes/forus-store/
cp -a "$release_dir/forus-demo-wallets/." state/wordpress/wp-content/plugins/forus-demo-wallets/
chown -R 33:33 state/wordpress/wp-content/themes/forus-store state/wordpress/wp-content/plugins/forus-demo-wallets
dc run --rm -T cli plugin activate forus-demo-wallets
dc run --rm -T -v "$release_dir:/merch-release:ro" cli eval-file /merch-release/seed-merch.php
dc run --rm -T cli cache flush
echo 'FORUS merchandise demo installed. Real FORUS gateway remains disabled.'
