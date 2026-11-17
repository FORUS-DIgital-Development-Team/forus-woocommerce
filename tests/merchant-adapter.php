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

define( 'FORUS_WC_ENABLE_TEST_REFUNDS', true );
$capabilities = json_decode( $GLOBALS['http_result']['body'], true );
$capabilities['refunds'] = true;
$data['environment'] = 'test'; $data['status'] = 'completed';
$GLOBALS['refund_posts'] = 0;
$GLOBALS['http_result'] = static function ( $url, $args ) use ( $capabilities, $data ) {
	if ( false !== strpos( $url, 'merchant-capabilities' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $capabilities ) );
	}
	if ( isset( $args['body'] ) ) {
		++$GLOBALS['refund_posts'];
		$GLOBALS['refund_wire'] = json_decode( $args['body'], true );
		return new WP_Error( 'timeout', 'Lost refund response' );
	}
	$evidence = $data;
	$evidence['refund'] = $GLOBALS['refund_wire']['requestKey'];
	$evidence['amount'] = $GLOBALS['refund_wire']['amountMinor'];
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $evidence ) );
};
check( is_wp_error( $service->refund( 42, '1.00', 'Recoverable fixture' ) ), 'Lost refund response does not claim completion' );
$operation = $order->get_meta( '_forus_refund_operation' );
check( $operation['key'] === $operation['refund'] && $operation['key'] === $GLOBALS['refund_wire']['requestKey'], 'Caller lookup key persisted before POST' );
check( is_wp_error( $service->refund( 42, '1.00', 'Recoverable fixture' ) ) && 1 === $GLOBALS['refund_posts'], 'Unknown refund cannot issue a second POST' );
check( true === $service->reconcile_refund( 42 ) && '1' === $order->refunded, 'GET recovery records confirmed refund locally' );
check( true === $service->reconcile_refund( 42 ) && 1 === $GLOBALS['local_refund_calls'] && 1 === $GLOBALS['refund_posts'], 'Repeated recovery cannot duplicate provider or local refund' );
check( 'accounted' === $order->get_meta( '_forus_refund_operation' )['local_handling'], 'Recovered accounting checkpoint persisted' );
$response = $data; $response['refund'] = $operation['refund']; $response['amount'] = 100;
$response['merchant_id'] = 'wrong';
$GLOBALS['http_result'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $response ) );
check( is_wp_error( $api->refund_status( $session, $operation['refund'] ) ), 'Cross-merchant refund evidence rejected' );
$response['merchant_id'] = $config['merchant']; $response['refund'] = '99999999-9999-4999-8999-999999999999';
$GLOBALS['http_result']['body'] = json_encode( $response );
check( is_wp_error( $api->refund_status( $session, $operation['refund'] ) ), 'Wrong refund key rejected' );
echo 'All ' . $GLOBALS['checks'] . ' merchant adapter assertions passed.' . PHP_EOL;
