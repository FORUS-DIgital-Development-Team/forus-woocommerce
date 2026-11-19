<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private string $code;
	private string $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_generate_uuid4() { static $n = 0; return sprintf( '00000000-0000-4000-8000-%012d', ++$n ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_salt( $scheme ) { return 'unit-test-only-not-a-real-secret'; }
function home_url( $path ) { return 'https://shop.example.test' . $path; }
function sanitize_text_field( $text ) { return strip_tags( $text ); }
function wc_get_order( $id ) { return $GLOBALS['orders'][$id] ?? false; }
function wp_safe_remote_request( $url, $args ) {
	$GLOBALS['http_calls'][] = array( $url, $args );
	return is_callable( $GLOBALS['http_result'] ) ? $GLOBALS['http_result']( $url, $args ) : $GLOBALS['http_result'];
}
function wc_create_refund( $args ) {
	check( false === $args['refund_payment'], 'Recovery never repeats a remote refund' );
	$order = wc_get_order( $args['order_id'] );
	$order->refunded = (string) ( (float) $order->refunded + $args['amount'] );
	$order->remaining = (string) ( (float) $order->total - (float) $order->refunded );
	$GLOBALS['local_refund_calls'] = ( $GLOBALS['local_refund_calls'] ?? 0 ) + 1;
	return new TestOrder();
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function as_get_scheduled_actions( $query, $format ) { return $GLOBALS['scheduled'] ?? array(); }
function as_schedule_single_action( $time, $hook, $args, $group, $unique ) {
	$GLOBALS['scheduled'][] = count( $GLOBALS['scheduled'] ?? array() ) + 1;
	return end( $GLOBALS['scheduled'] );
}

require_once __DIR__ . '/../includes/class-forus-policy.php';
require_once __DIR__ . '/../includes/class-forus-store.php';
require_once __DIR__ . '/../includes/class-forus-api.php';
require_once __DIR__ . '/../includes/class-forus-service.php';

class TestOrder {
	public array $meta = array();
	public string $status = 'pending';
	public string $total = '100.00';
	public string $currency = 'ZAR';
	public string $method = 'forus';
	public string $refunded = '0';
	public string $remaining = '100.00';
	public int $paid_calls = 0;
	public array $notes = array();
	public function get_id() { return 42; }
	public function get_payment_method() { return $this->method; }
	public function get_status() { return $this->status; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_meta( $name ) { return $this->meta[$name] ?? ''; }
	public function update_meta_data( $name, $value ) { $this->meta[$name] = $value; }
	public function delete_meta_data( $name ) { unset( $this->meta[$name] ); }
	public function save() { return 42; }
	public function is_paid() { return in_array( $this->status, array( 'processing', 'completed' ), true ); }
	public function update_status( $status, $note = '' ) { $this->status = $status; }
	public function payment_complete( $transaction ) { ++$this->paid_calls; $this->status = 'processing'; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function get_remaining_refund_amount() { return $this->remaining; }
	public function get_total_refunded() { return $this->refunded; }
}

class TestStore extends FORUS_Store {
	public bool $busy = false;
	public function locked( int $id, callable $callback ) {
		if ( $this->busy ) { return new WP_Error( 'forus_busy', 'Busy' ); }
		$order = wc_get_order( $id );
		if ( ! $order || 'forus' !== $order->get_payment_method() ) { return new WP_Error( 'forus_order', 'Wrong order' ); }
		$this->busy = true;
		try { return $callback( $order ); } finally { $this->busy = false; }
	}
}

class TestAPI implements FORUS_Adapter {
	public bool $enabled = true;
	public int $creates = 0;
	public int $refunds = 0;
	public int $reads = 0;
	public $created;
	public $evidence;
	public $refunded;
	public function __construct() {
		$this->created = array( 'session' => 'ps_test', 'checkout' => array( 'method' => 'POST', 'url' => 'https://checkout.example.test/pay', 'fields' => array( 'token' => 'test-only' ) ) );
	}
	public function ready(): bool { return $this->enabled; }
	public function create( array $request, string $key ) { ++$this->creates; return $this->created; }
	public function status( string $session ) { ++$this->reads; return $this->evidence; }
	public function refund( string $session, int $amount, string $reason, string $key ) { ++$this->refunds; return $this->refunded; }
	public function refund_status( string $session, string $refund ) { return $this->refunded; }
}

function test_config(): array {
	return array( 'environment' => 'sandbox', 'merchant' => 'merchant-test', 'base' => 'https://api.example.test/v1', 'key' => 'forus_test_sk_fixture', 'secret' => str_repeat( 's', 32 ), 'api_hosts' => array( 'api.example.test' ), 'checkout_hosts' => array( 'checkout.example.test' ), 'currencies' => array( 'ZAR' => 2, 'JPY' => 0, 'KWD' => 3 ) );
}
function fixture(): array {
	$order = new TestOrder();
	$GLOBALS['orders'] = array( 42 => $order );
	$GLOBALS['scheduled'] = array();
	$api = new TestAPI();
	$store = new TestStore();
	$service = new FORUS_Service( $api, $store, test_config() );
	return array( $order, $api, $store, $service );
}
function check( $condition, string $label ): void {
	$GLOBALS['checks'] = ( $GLOBALS['checks'] ?? 0 ) + 1;
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	echo 'PASS ' . $label . PHP_EOL;
}
function event_body( string $id = 'event-1', string $type = 'payment.completed' ): string {
	return json_encode( array( 'id' => $id, 'type' => $type, 'createdAt' => gmdate( 'Y-m-d\TH:i:s' ) . '.000Z', 'data' => array( 'id' => 'ps_test', 'metadata' => array( 'order_id' => '42' ) ) ) );
}
function deliver( FORUS_Service $service, string $body ) {
	return $service->webhook( $body, hash_hmac( 'sha256', $body, test_config()['secret'] ) );
}
