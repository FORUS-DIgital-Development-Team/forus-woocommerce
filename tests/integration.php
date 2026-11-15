<?php
/** Disposable WordPress install only: FORUS_WC_TEST_ENV=1 wp eval-file tests/integration.php */
if ( '1' !== getenv( 'FORUS_WC_TEST_ENV' ) || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'Disposable WooCommerce test environment required.' );
}
function forus_integration_assert( bool $condition, string $label ): void {
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	echo 'PASS ' . $label . PHP_EOL;
}
$expected_hpos = getenv( 'FORUS_EXPECT_HPOS' );
forus_integration_assert( in_array( $expected_hpos, array( 'yes', 'no' ), true ), 'Explicit storage-mode expectation supplied' );
forus_integration_assert( ( 'yes' === $expected_hpos ) === \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'Requested order store is actually active' );
$settings = array( 'enabled' => 'yes', 'environment' => 'sandbox', 'api_key' => 'fixture-only', 'webhook_secret' => str_repeat( 'x', 32 ), 'title' => 'Existing merchant label', 'custom_legacy' => 'preserve-me' );
update_option( 'woocommerce_forus_settings', $settings );
$gateway = new WC_FORUS_Gateway();
forus_integration_assert( 'Existing merchant label' === $gateway->title, 'Existing title retained' );
forus_integration_assert( $settings === get_option( 'woocommerce_forus_settings' ), 'Gateway loading leaves saved settings intact' );
forus_integration_assert( false === $gateway->is_available(), 'Classic checkout safely gated' );
forus_integration_assert( ! str_contains( $gateway->generate_password_html( 'api_key', $gateway->form_fields['api_key'] ), 'fixture-only' ), 'Secret absent from password HTML' );
forus_integration_assert( 'fixture-only' === $gateway->validate_password_field( 'api_key', '' ), 'Blank password retains credential' );
forus_integration_assert( ! $gateway->supports( 'refunds' ), 'Unverified refunds not advertised' );
$order = wc_create_order();
try {
	$order->set_payment_method( 'forus' );
	$order->set_currency( 'ZAR' );
	$order->set_total( '100.00' );
	$order->set_status( 'pending' );
	$order->save();
	$store = new FORUS_Store();
	$result = $store->locked( $order->get_id(), static function ( $fresh ) use ( $store ) {
		$store->record_event( $fresh, 'integration-event' );
		return true;
	} );
	forus_integration_assert( true === $result, 'Database lock and Woo order CRUD' );
	forus_integration_assert( $store->event_seen( wc_get_order( $order->get_id() ), 'integration-event' ), 'Dedup survives reload' );
	forus_integration_assert( 'failure' === $gateway->process_payment( $order->get_id() )['result'], 'Unverified checkout fails safely' );
	forus_integration_assert( is_wp_error( $gateway->process_refund( $order->get_id(), '25.00', 'test' ) ), 'Unverified refund fails safely' );
	forus_integration_assert( ! wc_get_order( $order->get_id() )->is_paid(), 'Blocked operations cannot mark paid' );
} finally {
	$order->delete( true );
}
if ( ! class_exists( 'FORUS_Blocks' ) ) { require FORUS_WC_PLUGIN_DIR . 'includes/class-forus-blocks.php'; }
$blocks = new FORUS_Blocks();
$blocks->initialize();
$data = $blocks->get_payment_method_data();
forus_integration_assert( array( 'title', 'description', 'available', 'supports' ) === array_keys( $data ), 'Blocks public field allowlist' );
forus_integration_assert( false === $data['available'], 'Blocks follows server gate' );
echo 'Integration checks passed; NOT a sandbox payment test.' . PHP_EOL;
