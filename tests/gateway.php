<?php
/** Behavioral regression tests with isolated WooCommerce doubles, not E2E. */
define( 'ABSPATH', __DIR__ );
if ( ! in_array( '--release-gate', $argv, true ) ) {
    define( 'FORUS_WC_VERIFIED_LEGACY_CONTRACT', true );
}
$hooks = $options = $orders = array();
$calls = 0;
$response = array( 'code' => 201, 'body' => '{"id":"p1","checkout_url":"https://checkout.example.com/pay"}' );
function add_action( $name, $callback ) { $GLOBALS['hooks'][$name][] = $callback; }
function add_filter( $name, $callback ) {}
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function __( $message, $domain = '' ) { return $message; }
function absint( $value ) { return abs( (int) $value ); }
function add_option( $key, $value, $deprecated = '', $autoload = false ) {
    if ( isset( $GLOBALS['options'][$key] ) ) { return false; }
    $GLOBALS['options'][$key] = $value;
    return true;
}
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); }
function wc_get_order( $id ) { return $GLOBALS['orders'][$id] ?? false; }
function wc_add_notice( $message, $type ) {}
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_site_url() { return 'https://shop.example.com'; }
function wp_safe_remote_post( $url, $args ) { ++$GLOBALS['calls']; return $GLOBALS['response']; }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error { public function __construct( ...$args ) {} }
#[AllowDynamicProperties]
class WC_Payment_Gateway {
    public $settings = array();
    public function init_settings() {
        $this->settings = array( 'api_key' => 'test-only', 'environment' => 'sandbox',
            'merchant_id' => 'm1', 'checkout_hosts' => 'checkout.example.com', 'webhook_secret' => 'test-secret' );
    }
    public function get_option( $key, $default = '' ) { return $this->settings[$key] ?? $default; }
    public function is_available() { return true; }
    public function get_return_url( $order ) { return 'https://shop.example.com/thanks'; }
}
class TestOrder {
    public $meta = array();
    public $status = 'pending';
    public $total = '12.34';
    public $currency = 'ZAR';
    public $method = 'forus';
    public $completions = 0;
    public $throw_on_complete = false;
    public function needs_payment() { return 'pending' === $this->status; }
    public function get_payment_method() { return $this->method; }
    public function get_currency() { return $this->currency; }
    public function get_total() { return $this->total; }
    public function get_meta( $key ) { return $this->meta[$key] ?? ''; }
    public function update_meta_data( $key, $value ) { $this->meta[$key] = $value; }
    public function save() {}
    public function get_cancel_order_url() { return 'https://shop.example.com/cancel'; }
    public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
    public function is_paid() { return 'processing' === $this->status; }
    public function payment_complete( $id ) {
        if ( $this->throw_on_complete ) { throw new RuntimeException( 'Simulated failure' ); }
        ++$this->completions;
        $this->status = 'processing';
    }
    public function update_status( $status, $note ) { $this->status = $status; }
}
require __DIR__ . '/../forus-woocommerce.php';
foreach ( $hooks['plugins_loaded'] as $callback ) { $callback(); }
$gateway = new WC_FORUS_Gateway();
$checks = 0;
function verify( $actual, $expected, $label ) {
    ++$GLOBALS['checks'];
    if ( $actual !== $expected ) {
        throw new RuntimeException( $label . ': ' . var_export( $actual, true ) );
    }
}
function fresh_order( $id = 1 ) {
    $order = new TestOrder();
    $order->meta = array( '_forus_environment' => 'sandbox', '_forus_expected' => array(
        'id' => 'p1', 'reference' => 'wc-' . $id, 'merchant_id' => 'm1', 'amount' => 1234, 'currency' => 'ZAR',
    ) );
    $GLOBALS['orders'][$id] = $order;
    return $order;
}
function event_data() {
    return array( 'id' => 'event-1', 'created_at' => time(), 'type' => 'payment.completed',
        'data' => array( 'id' => 'p1', 'reference' => 'wc-1', 'merchant_id' => 'm1',
            'currency' => 'ZAR', 'amount' => 1234, 'status' => 'completed', 'metadata' => array( 'order_id' => 1 ) ) );
}
function deliver( $event ) {
    $body = json_encode( $event );
    return $GLOBALS['gateway']->receive_event( $body, hash_hmac( 'sha256', $body, 'test-secret' ) );
}
if ( in_array( '--release-gate', $argv, true ) ) {
    fresh_order();
    verify( $gateway->is_available(), false, 'default checkout gate' );
    verify( deliver( event_data() ), 503, 'default webhook gate' );
    verify( $gateway->process_payment( 1 )['result'], 'failure', 'default payment gate' );
    verify( $calls, 0, 'no network under release gate' );
    echo "$checks release-gate checks passed.\n";
    exit;
}
verify( $gateway->receive_event( '{}', '' ), 401, 'unsigned webhook' );
verify( $gateway->receive_event( '{', hash_hmac( 'sha256', '{', 'test-secret' ) ), 400, 'malformed JSON' );
foreach ( array( 'id', 'reference', 'merchant_id', 'currency', 'amount', 'status' ) as $field ) {
    $order = fresh_order();
    $event = event_data();
    $event['data'][$field] = 'wrong';
    verify( deliver( $event ), 'status' === $field ? 400 : 409, 'mismatched ' . $field );
    verify( $order->completions, 0, 'no completion with wrong ' . $field );
}
foreach ( array( -301, 301 ) as $offset ) {
    fresh_order(); $event = event_data(); $event['created_at'] += $offset;
    verify( deliver( $event ), 400, 'timestamp outside window' );
}
$order = fresh_order(); $order->total = '12.35';
verify( deliver( event_data() ), 409, 'edited order amount' );
$order = fresh_order(); $order->method = 'cod';
verify( deliver( event_data() ), 409, 'other gateway' );
$order = fresh_order();
verify( deliver( event_data() ), 200, 'verified completion' );
verify( deliver( event_data() ), 200, 'duplicate acknowledged' );
verify( $order->completions, 1, 'duplicate not completed twice' );
$event = event_data(); $event['id'] = 'failed-later'; $event['type'] = 'payment.failed'; $event['data']['status'] = 'failed';
verify( deliver( $event ), 200, 'late failure acknowledged' );
verify( $order->status, 'processing', 'paid order not downgraded' );
foreach ( array( 'failed', 'cancelled', 'expired' ) as $status ) {
    $order = fresh_order(); $event = event_data();
    $event['id'] = 'terminal-' . $status;
    $event['type'] = 'payment.' . $status; $event['data']['status'] = $status;
    verify( deliver( $event ), 200, 'terminal ' . $status );
    verify( $order->status, 'failed' === $status ? 'failed' : 'cancelled', 'mapped terminal status' );
    verify( deliver( event_data() ), 409, 'no terminal resurrection' );
}
$order = fresh_order(); $order->throw_on_complete = true;
verify( deliver( event_data() ), 503, 'retryable persistence error' );
verify( isset( $options['forus_event_lock_1'] ), false, 'lock released on exception' );
$order->throw_on_complete = false;
verify( deliver( event_data() ), 200, 'retry after error' );
$order = fresh_order(); $options['forus_event_lock_1'] = time();
verify( deliver( event_data() ), 503, 'concurrent callback rejected' );
delete_option( 'forus_event_lock_1' );
$gateway->environment = 'live';
verify( $gateway->is_available(), false, 'live gate cannot be bypassed by constant' );
verify( deliver( event_data() ), 503, 'live webhook gate' );
$gateway->environment = 'sandbox';
fresh_order( 2 );
verify( $gateway->process_payment( 2 )['result'], 'success', 'approved redirect response' );
verify( $gateway->process_payment( 2 )['result'], 'failure', 'duplicate payment attempt' );
verify( $calls, 1, 'one creation request only' );
$response = new WP_Error(); fresh_order( 3 );
verify( $gateway->process_payment( 3 )['result'], 'failure', 'uncertain creation' );
verify( $gateway->process_payment( 3 )['result'], 'failure', 'uncertain creation not retried' );
verify( $calls, 2, 'no second network request after timeout' );
foreach ( array( array( 'code' => 500, 'body' => '{}' ), array( 'code' => 200, 'body' => '<html>' ),
    array( 'code' => 201, 'body' => '{"id":"p1","checkout_url":"https://evil.example/pay"}' ) ) as $index => $invalid ) {
    $response = $invalid; fresh_order( 10 + $index );
    verify( $gateway->process_payment( 10 + $index )['result'], 'failure', 'invalid API response' );
}
verify( is_wp_error( $gateway->process_refund( 1, 1.00 ) ), true, 'unverified refund disabled' );
echo "$checks gateway regression checks passed using test doubles; not sandbox E2E.\n";
