<?php
/** Real order storage with the actual versioned HTTP adapter; network responses are mocked. */
if ( '1' !== getenv( 'FORUS_WC_TEST_ENV' ) ) { throw new RuntimeException( 'Disposable test environment required.' ); }
define( 'FORUS_WC_ENABLE_TEST_CHECKOUT', true );
add_filter( 'pre_wp_mail', '__return_true' );
$wire_config = array( 'environment' => 'sandbox', 'merchant' => 'ci-shop', 'base' => 'https://api.example.test/v1',
	'api_hosts' => array( 'api.example.test' ), 'checkout_hosts' => array( 'checkout.example.test' ),
	'key' => 'forus_test_sk_' . str_repeat( 'a', 48 ), 'secret' => str_repeat( 's', 32 ), 'currencies' => array( 'ZAR' => 2 ) );
$wire_product = new WC_Product_Simple();
$wire_product->set_name( 'Versioned adapter fixture' ); $wire_product->set_regular_price( '12.34' );
$wire_product->set_manage_stock( true ); $wire_product->set_stock_quantity( 5 ); $wire_product->save();
$wire_order = wc_create_order(); $wire_order->set_currency( 'ZAR' ); $wire_order->set_payment_method( 'forus' );
$wire_order->add_product( $wire_product, 1 ); $wire_order->calculate_totals(); $wire_order->save();
$wire_id = $wire_order->get_id(); $wire_session = wp_generate_uuid4(); $wire_state = 'pending'; $wire_timeout = true; $wire_requests = array();
$http_filter = function ( $preempt, $args, $url ) use ( &$wire_requests, &$wire_state, &$wire_timeout, $wire_id, $wire_session ) {
	if ( 0 !== strpos( $url, 'https://api.example.test/v1/' ) ) { return new WP_Error( 'ci_network', 'External requests disabled' ); }
	if ( isset( $args['body'] ) ) { $wire_requests[] = $args['body']; }
	if ( $wire_timeout ) { return new WP_Error( 'timeout', 'Simulated lost creation response' ); }
	$body = array( 'contract' => 'forus-merchant-checkout-v1', 'id' => $wire_session, 'merchant_id' => 'ci-shop',
		'reference' => 'wc-' . $wire_id . '-' . substr( hash( 'sha256', home_url( '/' ) ), 0, 16 ),
		'environment' => 'test', 'amount' => 1234, 'currency' => 'ZAR', 'status' => $wire_state, 'metadata' => array( 'order_id' => $wire_id ),
		'checkout' => array( 'method' => 'POST', 'url' => 'https://checkout.example.test/hpp', 'fields' => array(
			'Amount' => '1234', 'Currency' => 'ZAR', 'TransactionType' => 'Payment', 'Checksum' => str_repeat( 'A', 64 ) ) ) );
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $body ) );
};
add_filter( 'pre_http_request', $http_filter, 10, 3 );
try {
	$wire_api = new FORUS_API( $wire_config ); $wire_service = new FORUS_Service( $wire_api, new FORUS_Store(), $wire_config );
	forus_integration_assert( is_wp_error( $wire_service->create( $wire_id ) ), 'Versioned adapter preserves lost-response attempt' );
	$wire_timeout = false;
	forus_integration_assert( ! is_wp_error( $wire_service->reconcile( $wire_id ) ), 'Recovery resumes same backend session' );
	forus_integration_assert( 2 === count( $wire_requests ) && $wire_requests[0] === $wire_requests[1], 'Real storage preserves identical wire retry' );
	$wire_state = 'completed';
	$wire_event = wp_json_encode( array( 'id' => 'evt-versioned-' . $wire_id, 'created_at' => time(), 'type' => 'payment.completed',
		'data' => array( 'id' => $wire_session, 'metadata' => array( 'order_id' => $wire_id ) ) ) );
	$wire_signature = hash_hmac( 'sha256', $wire_event, $wire_config['secret'] );
	forus_integration_assert( true === $wire_service->webhook( $wire_event, $wire_signature ), 'Versioned webhook verifies current authenticated status' );
	forus_integration_assert( wc_get_order( $wire_id )->is_paid(), 'Versioned adapter persists paid state' );
	forus_integration_assert( true === $wire_service->webhook( $wire_event, $wire_signature ), 'Versioned webhook duplicate acknowledged' );
	forus_integration_assert( 4 === wc_get_product( $wire_product->get_id() )->get_stock_quantity(), 'Versioned adapter reduces stock once' );
	forus_integration_assert( is_wp_error( $wire_service->refund( $wire_id, '1.00', '' ) ), 'Unsupported real-adapter refund disabled' );
} finally {
	remove_filter( 'pre_http_request', $http_filter, 10 );
	as_unschedule_all_actions( 'forus_reconcile_order', array( $wire_id ), 'forus' );
	$wire_order->delete( true ); $wire_product->delete( true );
}
echo 'Versioned HTTP adapter passed on real WooCommerce storage with mocked backend responses.' . PHP_EOL;
