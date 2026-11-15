<?php
require __DIR__ . '/bootstrap.php';
define( 'FORUS_WC_ENABLE_TEST_CHECKOUT', true );
function get_option( $key, $default = false ) { return $GLOBALS['wire_options'][$key] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = false ) {
	if ( isset( $GLOBALS['wire_options'][$key] ) ) { return false; }
	$GLOBALS['wire_options'][$key] = $value; return true;
}
function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
function wp_safe_remote_get( $url, $args ) { return wp_safe_remote_request( $url, $args ); }
function wp_safe_remote_post( $url, $args ) { return wp_safe_remote_request( $url, $args ); }
class MerchantTestOrder extends TestOrder {
	public function get_checkout_order_received_url() { return 'https://shop.example.test/thanks'; }
	public function get_cancel_order_url_raw() { return 'https://shop.example.test/cancel'; }
}
$config = test_config(); $config['key'] = 'forus_test_sk_' . str_repeat( 'a', 48 );
$api = new FORUS_API( $config );
check( $api->ready(), 'Explicit opt-in allows configured sandbox adapter' );
$live = $config; $live['environment'] = 'live';
check( ! ( new FORUS_API( $live ) )->ready(), 'Opt-in never enables live adapter' );
$public = $config; $public['key'] = 'forus_test_pk_' . str_repeat( 'a', 48 );
check( ! ( new FORUS_API( $public ) )->ready(), 'Publishable key cannot execute merchant requests' );
$order = new MerchantTestOrder(); $GLOBALS['orders'] = array( 42 => $order ); $GLOBALS['scheduled'] = array();
$service = new FORUS_Service( $api, new TestStore(), $config );
$GLOBALS['http_result'] = new WP_Error( 'timeout', 'No response' ); $GLOBALS['http_calls'] = array();
check( is_wp_error( $service->create( 42 ) ), 'Timeout preserves unknown journal' );
$wire = json_decode( $GLOBALS['http_calls'][0][1]['body'], true );
check( '42' === $wire['metadata']['order_id'] && 10000 === $wire['amountMinor'], 'Wire request uses versioned minor units and metadata' );
check( 1 === count( $GLOBALS['scheduled'] ), 'Unknown idempotent request schedules recovery' );
$session = '11111111-1111-4111-8111-111111111111';
$data = array( 'contract' => 'forus-merchant-checkout-v1', 'id' => $session, 'reference' => $wire['merchantReference'],
	'merchant_id' => $config['merchant'], 'environment' => 'test', 'currency' => 'ZAR', 'amount' => 10000,
	'metadata' => array( 'order_id' => 42 ), 'status' => 'pending',
	'checkout' => array( 'method' => 'POST', 'url' => 'https://checkout.example.test/hpp', 'fields' => array(
		'Amount' => '10000', 'Currency' => 'ZAR', 'TransactionType' => 'Payment', 'Checksum' => str_repeat( 'A', 64 ) ) ) );
$GLOBALS['http_result'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $data ) );
check( ! is_wp_error( $service->reconcile( 42 ) ), 'Unknown session recovered by idempotent create' );
check( $GLOBALS['http_calls'][0][1]['body'] === $GLOBALS['http_calls'][1][1]['body'], 'Retry preserves exact wire body' );
check( $session === $order->get_meta( '_forus_payment_session_id' ), 'Recovered session persisted' );
$data['status'] = 'completed'; $GLOBALS['http_result']['body'] = json_encode( $data );
$event = array( 'id' => 'numeric-timestamp-event', 'created_at' => time(), 'type' => 'payment.completed',
	'data' => array( 'id' => $session, 'metadata' => array( 'order_id' => 42 ) ) );
check( true === deliver( $service, json_encode( $event ) ) && 1 === $order->paid_calls, 'Versioned event wakes authenticated status verification' );
check( true === deliver( $service, json_encode( $event ) ) && 1 === $order->paid_calls, 'Versioned event deduplicates' );
$before = count( $GLOBALS['http_calls'] );
check( is_wp_error( $service->refund( 42, '1.00', '' ) ) && $before === count( $GLOBALS['http_calls'] ), 'Unsupported refund sends no HTTP' );
check( '' === $order->get_meta( '_forus_refund_operation' ), 'Unsupported refund creates no ambiguous journal' );
$data['merchant_id'] = 'other'; $GLOBALS['http_result']['body'] = json_encode( $data );
check( is_wp_error( $api->status( $session ) ), 'Wrong merchant in authenticated response rejected' );
$data['merchant_id'] = $config['merchant']; $data['environment'] = 'live'; $GLOBALS['http_result']['body'] = json_encode( $data );
check( is_wp_error( $api->status( $session ) ), 'Wrong environment rejected' );
$GLOBALS['http_result'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array(
	'contract' => 'forus-merchant-checkout-v1', 'merchant_id' => $config['merchant'], 'environment' => 'test',
	'currency' => 'ZAR', 'status' => true, 'refunds' => false, 'live' => false ) ) );
check( ! is_wp_error( $api->capabilities() ), 'Authenticated merchant connection check' );
echo 'All ' . $GLOBALS['checks'] . ' merchant adapter assertions passed.' . PHP_EOL;
