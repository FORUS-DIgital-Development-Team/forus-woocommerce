<?php
/** Real WooCommerce order lifecycle with an in-process adapter; never provider E2E. */
if ( '1' !== getenv( 'FORUS_WC_TEST_ENV' ) || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'Disposable WooCommerce test environment required.' );
}
require __DIR__ . '/integration.php';

class FORUS_Integration_Adapter implements FORUS_Adapter {
	public array $snapshot = array();
	public string $payment_state = 'pending';
	public int $creates = 0;
	public int $refunds = 0;
	public bool $lose_refund_response = false;
	public array $last_refund = array();
	public function ready(): bool { return true; }
	public function create( array $request, string $key ) {
		++$this->creates;
		$this->snapshot = $request + array( 'session' => 'integration-' . $key );
		return array( 'session' => $this->snapshot['session'], 'checkout' => array( 'method' => 'POST', 'url' => 'https://checkout.example.test/pay', 'fields' => array( 'token' => 'test-only' ) ) );
	}
	public function status( string $session ) { return $this->snapshot + array( 'status' => $this->payment_state ); }
	public function refund( string $session, int $amount, string $reason, string $key ) {
		++$this->refunds;
		$this->last_refund = array_replace( $this->snapshot, array( 'amount' => $amount, 'refund' => $key, 'status' => 'completed' ) );
		return $this->lose_refund_response ? new WP_Error( 'timeout', 'Simulated lost response' ) : $this->last_refund;
	}
	public function refund_status( string $session, string $refund ) { return $this->last_refund; }
}
$config = array( 'environment' => 'sandbox', 'merchant' => 'test-merchant', 'base' => 'https://api.example.test/v1', 'api_hosts' => array( 'api.example.test' ), 'checkout_hosts' => array( 'checkout.example.test' ), 'key' => 'fixture-only', 'secret' => str_repeat( 's', 32 ), 'currencies' => array( 'ZAR' => 2 ) );
$product = new WC_Product_Simple();
$product->set_name( 'FORUS integration fixture' );
$product->set_regular_price( '100.00' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 2 );
$product->save();
$order = wc_create_order();
try {
	$order->set_currency( 'ZAR' );
	$order->set_payment_method( 'forus' );
	$order->add_product( $product, 1 );
	$order->calculate_totals();
	$order->save();
	$adapter = new FORUS_Integration_Adapter();
	$store = new FORUS_Store();
	$service = new FORUS_Service( $adapter, $store, $config );
	$id = $order->get_id();
	forus_integration_assert( ! is_wp_error( $service->create( $id ) ), 'Create journals using real order storage' );
	forus_integration_assert( ! is_wp_error( $service->create( $id ) ) && 1 === $adapter->creates, 'Repeated checkout reuses one adapter session' );
	$event = array( 'id' => 'evt-real-order', 'type' => 'payment.completed', 'createdAt' => gmdate( 'Y-m-d\TH:i:s' ) . '.000Z', 'data' => array( 'id' => $adapter->snapshot['session'], 'metadata' => array( 'order_id' => (string) $id ) ) );
	$body = wp_json_encode( $event );
	$signature = hash_hmac( 'sha256', $body, $config['secret'] );
	$adapter->payment_state = 'completed';
	forus_integration_assert( is_wp_error( $service->webhook( $body, str_repeat( '0', 64 ) ) ), 'Forged webhook rejected with real order storage' );
	forus_integration_assert( ! wc_get_order( $id )->is_paid(), 'Forgery cannot pay real order' );
	forus_integration_assert( true === $service->webhook( $body, $signature ), 'Authenticated status pays real Woo order' );
	forus_integration_assert( wc_get_order( $id )->is_paid(), 'Paid state persists across order reload' );
	forus_integration_assert( 1 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'WooCommerce stock reduced once' );
	forus_integration_assert( true === $service->webhook( $body, $signature ), 'Duplicate event acknowledged' );
	forus_integration_assert( 1 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'Duplicate event cannot reduce stock again' );
	$adapter->payment_state = 'failed';
	forus_integration_assert( true === $service->reconcile( $id ) && wc_get_order( $id )->is_paid(), 'Out-of-order failure cannot downgrade real paid order' );
	forus_integration_assert( true === $service->refund( $id, '25.00', 'fixture' ), 'Partial refund confirmed by test adapter' );
	forus_integration_assert( is_wp_error( $service->refund( $id, '25.00', 'fixture' ) ) && 1 === $adapter->refunds, 'Refund retry blocked before local accounting' );
	$first = wc_create_refund( array( 'order_id' => $id, 'amount' => 25, 'reason' => 'Fixture accounting', 'refund_payment' => false ) );
	forus_integration_assert( ! is_wp_error( $first ), 'WooCommerce records first confirmed refund' );
	forus_integration_assert( true === $service->refund( $id, '75.00', 'fixture' ) && 2 === $adapter->refunds, 'Second partial refund respects remaining balance' );
	$second = wc_create_refund( array( 'order_id' => $id, 'amount' => 75, 'reason' => 'Fixture accounting', 'refund_payment' => false ) );
	forus_integration_assert( ! is_wp_error( $second ) && 100.0 === (float) wc_get_order( $id )->get_total_refunded(), 'Full cumulative refund recorded exactly once' );
	forus_integration_assert( is_wp_error( $service->refund( $id, '0.01', 'fixture' ) ) && 2 === $adapter->refunds, 'No over-refund after full cumulative refund' );
	// A separate DB connection proves contention, not merely a mocked busy flag.
	global $wpdb;
	$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$name = 'forus_' . substr( hash( 'sha256', $wpdb->prefix . ':' . $id ), 0, 48 );
	try {
		forus_integration_assert( '1' === (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ), 'Second connection acquires order lock' );
		$called = false;
		$result = $store->locked( $id, static function () use ( &$called ) { $called = true; return true; } );
		forus_integration_assert( is_wp_error( $result ) && ! $called, 'Concurrent connection cannot enter financial operation' );
	} finally {
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		$other->close();
	}
	forus_integration_assert( true === $store->locked( $id, static function () { return true; } ), 'Order lock usable after competing connection releases it' );
} finally {
	as_unschedule_all_actions( 'forus_reconcile_order', array( $order->get_id() ), 'forus' );
	foreach ( wc_get_order( $order->get_id() )->get_refunds() as $refund ) { $refund->delete( true ); }
	$order->delete( true );
	$product->delete( true );
}
echo 'Real WooCommerce lifecycle passed with mocked provider; sandbox E2E remains outstanding.' . PHP_EOL;

$recovery_order = wc_create_order();
try {
	$recovery_order->set_currency( 'ZAR' ); $recovery_order->set_payment_method( 'forus' );
	$recovery_order->set_total( '100.00' ); $recovery_order->save();
	$adapter = new FORUS_Integration_Adapter();
	$service = new FORUS_Service( $adapter, new FORUS_Store(), $config );
	$id = $recovery_order->get_id();
	forus_integration_assert( ! is_wp_error( $service->create( $id ) ), 'Recovery fixture session created' );
	$adapter->payment_state = 'completed'; $service->reconcile( $id );
	$adapter->lose_refund_response = true;
	forus_integration_assert( is_wp_error( $service->refund( $id, '25.00', 'Lost response fixture' ) ), 'Lost response leaves durable refund journal' );
	forus_integration_assert( true === $service->reconcile( $id ), 'Scheduled recovery reads provider refund status' );
	forus_integration_assert( 25.0 === (float) wc_get_order( $id )->get_total_refunded(), 'Recovery records exact partial refund in WooCommerce' );
	forus_integration_assert( true === $service->reconcile_refund( $id ) && 1 === count( wc_get_order( $id )->get_refunds() ) && 1 === $adapter->refunds, 'Recovery is idempotent locally and remotely' );
	$adapter->lose_refund_response = false;
	forus_integration_assert( true === $service->refund( $id, '75.00', 'Remaining balance' ), 'Recovered partial refund permits remaining balance' );
	$final = wc_create_refund( array( 'order_id' => $id, 'amount' => 75, 'refund_payment' => false ) );
	forus_integration_assert( ! is_wp_error( $final ) && 100.0 === (float) wc_get_order( $id )->get_total_refunded(), 'Recovered and synchronous refunds total the original payment' );
} finally {
	as_unschedule_all_actions( 'forus_reconcile_order', array( $recovery_order->get_id() ), 'forus' );
	foreach ( wc_get_order( $recovery_order->get_id() )->get_refunds() as $refund ) { $refund->delete( true ); }
	$recovery_order->delete( true );
}

require __DIR__ . '/merchant-wordpress.php';
