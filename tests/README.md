# Regression tests

Run `php tests/security.php`, `php tests/gateway.php` and `php tests/gateway.php --release-gate` on PHP 8.1 or newer.

The gateway tests execute production methods using isolated WooCommerce doubles. They do not establish WordPress database, HPOS, checkout UI, provider sandbox or refund compatibility. See the root README release checklist.
