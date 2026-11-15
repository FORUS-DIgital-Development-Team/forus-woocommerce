<?php
/**
 * Plugin Name: FORUS Payment Gateway
 * Plugin URI: https://developer.forus.digital/plugins/woocommerce
 * Description: FORUS hosted checkout integration. This build is blocked pending merchant API verification; see merchant diagnostics.
 * Version: 1.1.0-dev
 * Author: FORUS
 * Author URI: https://forus.digital
 * License: GPL-2.0-or-later
 * Text Domain: forus-woocommerce
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;
define( 'FORUS_WC_VERSION', '1.1.0-dev' );
define( 'FORUS_WC_FILE', __FILE__ );
define( 'FORUS_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/includes/class-forus-policy.php';
require_once __DIR__ . '/includes/class-forus-store.php';
require_once __DIR__ . '/includes/class-forus-api.php';
require_once __DIR__ . '/includes/class-forus-service.php';

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	require_once __DIR__ . '/includes/class-wc-forus-gateway.php';
	add_filter( 'woocommerce_payment_gateways', static function ( $gateways ) {
		$gateways[] = 'WC_FORUS_Gateway';
		return $gateways;
	} );
	// Instantiate on callbacks/cron too: do not depend on a checkout page loading.
	add_action( 'woocommerce_api_forus_webhook', static function () {
		( new WC_FORUS_Gateway() )->handle_webhook();
	} );
	add_action( 'woocommerce_api_forus_checkout', static function () {
		( new WC_FORUS_Gateway() )->render_checkout_bridge();
	} );
	add_action( 'forus_reconcile_order', static function ( $order_id ) {
		( new WC_FORUS_Gateway() )->reconcile( (int) $order_id );
	} );
	add_action( 'admin_post_forus_connection_test', static function () {
		( new WC_FORUS_Gateway() )->connection_test();
	} );
} );

add_action( 'woocommerce_blocks_loaded', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once __DIR__ . '/includes/class-forus-blocks.php';
		add_action( 'woocommerce_blocks_payment_method_type_registration', static function ( $registry ) {
			$registry->register( new FORUS_Blocks() );
		} );
	}
} );

register_activation_hook( __FILE__, static function () {
	// Preserve all existing options and order records. There is no automatic enablement.
	add_option( 'forus_wc_schema_version', '1', '', false );
} );
register_deactivation_hook( __FILE__, static function () {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'forus_reconcile_order', null, 'forus' );
	}
	// Retain credentials, idempotency journals and event history for recovery.
} );
