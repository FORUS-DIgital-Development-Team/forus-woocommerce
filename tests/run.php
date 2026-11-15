<?php
require __DIR__ . '/bootstrap.php';
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );

foreach ( array( array( '0.01', 2, 1 ), array( '123.45', 2, 12345 ), array( '100', 0, 100 ), array( '0.001', 3, 1 ), array( '1.2300', 2, 123 ) ) as $case ) {
	check( FORUS_Policy::minor( $case[0], $case[1] ) === $case[2], 'Exact minor units: ' . $case[0] );
}
foreach ( array( '0', '-1', '1e2', '1,23', '1.001', '10000000000', '', 'NaN' ) as $value ) {
	$rejected = false;
	try { FORUS_Policy::minor( $value, 2 ); } catch ( InvalidArgumentException $e ) { $rejected = true; }
	check( $rejected, 'Reject invalid amount: ' . $value );
}
$hosts = array( 'checkout.example.test' );
check( FORUS_Policy::https_url( 'https://checkout.example.test/pay?t=x', $hosts ), 'Exact HTTPS checkout host' );
foreach ( array( 'http://checkout.example.test/pay', 'https://checkout.example.test.evil.test', 'https://user@checkout.example.test', 'https://checkout.example.test:444', 'https://127.0.0.1', 'https://checkout.example.test/#x', 'javascript:alert(1)', 'https://checkout.example.test\@evil.test', "https://checkout.example.test/\nfoo" ) as $url ) {
	check( ! FORUS_Policy::https_url( $url, $hosts ), 'Reject unsafe URL: ' . json_encode( $url ) );
}
check( ! FORUS_Policy::signature( '{}', '', '' ), 'Empty secret fails closed' );
check( ! FORUS_Policy::signature( '{}', str_repeat( '0', 64 ), test_config()['secret'] ), 'Forged signature fails closed' );
check( ! FORUS_Policy::signature( str_repeat( 'a', 65537 ), str_repeat( '0', 64 ), test_config()['secret'] ), 'Oversized payload refused' );

list( $order, $api, $store, $service ) = fixture();
check( ! is_wp_error( $service->create( 42 ) ), 'Create payment session using test adapter' );
check( ! is_wp_error( $service->create( 42 ) ) && 1 === $api->creates, 'Duplicate create reuses stored session' );
$order->total = '101.00';
check( is_wp_error( $service->create( 42 ) ) && 1 === $api->creates, 'Changed amount cannot reuse or recreate session' );

list( $order, $api, $store, $service ) = fixture();
$api->created = new WP_Error( 'timeout', 'Timeout' );
check( is_wp_error( $service->create( 42 ) ), 'Timeout leaves unknown create journal' );
check( is_wp_error( $service->create( 42 ) ) && 1 === $api->creates, 'Timeout retry cannot duplicate financial POST' );

foreach ( array( 'http://checkout.example.test', 'https://evil.test/steal' ) as $url ) {
	list( $order, $api, $store, $service ) = fixture();
	$api->created['checkout']['url'] = $url;
	check( is_wp_error( $service->create( 42 ) ) && '' === $order->get_meta( '_forus_payment_session_id' ), 'Unsafe checkout response is not persisted' );
}
list( $order, $api, $store, $service ) = fixture();
$store->busy = true;
check( is_wp_error( $service->create( 42 ) ) && 0 === $api->creates, 'Concurrent operation cannot send request' );
$store->busy = false;
$order->method = 'other';
check( is_wp_error( $service->create( 42 ) ) && 0 === $api->creates, 'Other payment gateway order rejected' );

list( $order, $api, $store, $service ) = fixture();
$service->create( 42 );
$api->evidence = $order->get_meta( '_forus_snapshot' ) + array( 'status' => 'completed' );
check( is_wp_error( $service->webhook( event_body(), str_repeat( '0', 64 ) ) ) && 0 === $api->reads, 'Forged event never reaches status API' );
check( is_wp_error( deliver( $service, '{}' ) ), 'Malformed event rejected' );
check( true === deliver( $service, event_body() ) && 1 === $order->paid_calls, 'Only authenticated matching status completes order' );
check( true === deliver( $service, event_body() ) && 1 === $order->paid_calls && 1 === $api->reads, 'Persistent event deduplication skips replay' );
$api->evidence['status'] = 'failed';
check( true === deliver( $service, event_body( 'event-2', 'payment.failed' ) ) && 'processing' === $order->status, 'Out-of-order failure cannot downgrade paid order' );
check( true === deliver( $service, event_body() ) && 1 === $order->paid_calls, 'Older replay still deduplicated after a newer event' );

foreach ( array( 'session', 'merchant', 'currency', 'reference', 'environment', 'amount' ) as $key ) {
	list( $order, $api, $store, $service ) = fixture();
	$service->create( 42 );
	$api->evidence = $order->get_meta( '_forus_snapshot' ) + array( 'status' => 'completed' );
	$api->evidence[$key] = 'amount' === $key ? 9999 : 'wrong';
	check( is_wp_error( deliver( $service, event_body() ) ) && 0 === $order->paid_calls, 'Mismatched evidence rejected: ' . $key );
}
foreach ( array( 'failed', 'cancelled', 'pending' ) as $status ) {
	list( $order, $api, $store, $service ) = fixture();
	$service->create( 42 );
	$api->evidence = $order->get_meta( '_forus_snapshot' ) + array( 'status' => $status );
	check( true === deliver( $service, event_body() ) && $status === $order->status && 0 === $order->paid_calls, 'Current API status overrides webhook claim: ' . $status );
}
list( $order, $api, $store, $service ) = fixture();
$service->create( 42 );
$order->status = 'cancelled';
$api->evidence = $order->get_meta( '_forus_snapshot' ) + array( 'status' => 'completed' );
check( true === $service->reconcile( 42 ) && 0 === $order->paid_calls && 'yes' === $order->get_meta( '_forus_manual_review' ), 'Late payment on cancelled order needs review' );

list( $order, $api, $store, $service ) = fixture();
$service->create( 42 );
$api->evidence = new WP_Error( 'timeout', 'Timeout' );
check( is_wp_error( deliver( $service, event_body() ) ) && ! $store->event_seen( $order, 'event-1' ), 'Failed status read does not consume event' );
$api->evidence = $order->get_meta( '_forus_snapshot' ) + array( 'status' => 'completed' );
check( true === $service->reconcile( 42 ) && 1 === $order->paid_calls, 'Missing-webhook recovery through matching status' );

list( $order, $api, $store, $service ) = fixture();
$service->create( 42 );
check( 1 === count( $GLOBALS['scheduled'] ), 'Creation schedules recovery' );
$GLOBALS['scheduled'] = array(); // Previous action is running, no pending successor.
$api->evidence = $order->get_meta( '_forus_snapshot' ) + array( 'status' => 'pending' );
check( true === $service->reconcile( 42 ) && 1 === count( $GLOBALS['scheduled'] ), 'Running recovery schedules successor' );
$service->reconcile( 42 );
check( 1 === count( $GLOBALS['scheduled'] ), 'Pending recovery is not duplicated' );
$GLOBALS['scheduled'] = array();
$order->update_meta_data( '_forus_reconcile_attempt', 12 );
$service->reconcile( 42 );
check( 0 === count( $GLOBALS['scheduled'] ), 'Recovery retries are bounded' );
check( 'yes' === $order->get_meta( '_forus_manual_review' ) && 'yes' === $order->get_meta( '_forus_recovery_exhausted' ), 'Exhausted recovery flags merchant review' );
$note_count = count( $order->notes );
$service->reconcile( 42 );
check( $note_count === count( $order->notes ), 'Repeated recovery exhaustion does not duplicate notes' );

foreach ( array( '25.00', '100.00' ) as $amount ) {
	list( $order, $api, $store, $service ) = fixture();
	$service->create( 42 );
	$order->status = 'processing';
	$api->refunded = $order->get_meta( '_forus_snapshot' ) + array( 'status' => 'completed', 'refund' => 'rf_test' );
	$api->refunded['amount'] = FORUS_Policy::minor( $amount, 2 );
	check( true === $service->refund( 42, $amount, 'test' ), 'Confirmed partial/full refund: ' . $amount );
	check( is_wp_error( $service->refund( 42, $amount, 'test' ) ) && 1 === $api->refunds, 'Refund replay cannot repeat remote or local completion' );
}
foreach ( array( '0', '-1', '100.01', null ) as $amount ) {
	list( $order, $api, $store, $service ) = fixture();
	$service->create( 42 );
	$order->status = 'processing';
	check( is_wp_error( $service->refund( 42, $amount, '' ) ) && 0 === $api->refunds, 'Reject invalid/over refund: ' . json_encode( $amount ) );
}
list( $order, $api, $store, $service ) = fixture();
$service->create( 42 );
$order->status = 'processing';
$api->refunded = $order->get_meta( '_forus_snapshot' ) + array( 'status' => 'pending', 'refund' => 'rf_test' );
$api->refunded['amount'] = 2500;
check( is_wp_error( $service->refund( 42, '25.00', '' ) ) && 'pending' === $order->get_meta( '_forus_refund_operation' )['state'], 'Accepted refund is not reported as settled' );
$api->refunded['status'] = 'completed';
check( true === $service->reconcile_refund( 42 ) && 'completed' === $order->get_meta( '_forus_refund_operation' )['state'], 'Pending refund status tracked with authenticated evidence' );
check( is_wp_error( $service->refund( 42, '25.00', '' ) ) && 1 === $api->refunds, 'Async refund requires local accounting reconciliation' );

foreach ( array( 'failed', 'unknown', '', null ) as $status ) {
	list( $order, $api, $store, $service ) = fixture();
	$service->create( 42 );
	$order->status = 'processing';
	$api->refunded = $order->get_meta( '_forus_snapshot' ) + array( 'status' => $status, 'refund' => 'rf_test' );
	$api->refunded['amount'] = 2500;
	check( is_wp_error( $service->refund( 42, '25.00', '' ) ), 'Unsettled or unknown refund status cannot succeed: ' . json_encode( $status ) );
	$expected_state = 'failed' === $status ? 'failed' : 'unknown';
	check( $expected_state === $order->get_meta( '_forus_refund_operation' )['state'], 'Refund journal preserves failure or ambiguity: ' . json_encode( $status ) );
	check( is_wp_error( $service->refund( 42, '25.00', '' ) ) && 1 === $api->refunds, 'Unresolved refund cannot be resubmitted: ' . json_encode( $status ) );
}
foreach ( array( '', "rf\nforged", str_repeat( 'r', 201 ) ) as $refund_id ) {
	list( $order, $api, $store, $service ) = fixture();
	$service->create( 42 );
	$order->status = 'processing';
	$api->refunded = $order->get_meta( '_forus_snapshot' ) + array( 'status' => 'completed', 'refund' => $refund_id );
	$api->refunded['amount'] = 2500;
	check( is_wp_error( $service->refund( 42, '25.00', '' ) ) && 'unknown' === $order->get_meta( '_forus_refund_operation' )['state'], 'Malformed refund identifier leaves operation unresolved' );
}

$real = new FORUS_API( test_config() );
check( false === $real->ready(), 'Production adapter cannot bypass contract gate' );
check( is_wp_error( $real->create( array(), 'key' ) ) && is_wp_error( $real->status( 'id' ) ) && is_wp_error( $real->refund( 'id', 1, '', 'key' ) ), 'Unverified financial routes fail closed without HTTP' );
$GLOBALS['http_calls'] = array();
check( is_wp_error( $real->capabilities() ) && 0 === count( $GLOBALS['http_calls'] ), 'Public capabilities cannot verify a merchant or receive its credential' );
$live = test_config(); $live['environment'] = 'live';
$calls = count( $GLOBALS['http_calls'] );
check( is_wp_error( ( new FORUS_API( $live ) )->capabilities() ) && $calls === count( $GLOBALS['http_calls'] ), 'Connection test never contacts live API' );

foreach ( array( gmdate( 'Y-m-d\TH:i:s', time() - 301 ) . '.000Z', gmdate( 'Y-m-d\TH:i:s', time() + 301 ) . '.000Z', '2026-02-31T10:00:00.000Z', 'tomorrow', null ) as $created ) {
	list( $order, $api, $store, $service ) = fixture();
	$service->create( 42 );
	$event = json_decode( event_body(), true );
	$event['createdAt'] = $created;
	check( is_wp_error( deliver( $service, json_encode( $event ) ) ) && 0 === $api->reads, 'Reject stale, future or malformed signed timestamp: ' . json_encode( $created ) );
}
foreach ( array( '042', '42e0', '42.0', str_repeat( '9', 30 ), 0, -42, array( 42 ) ) as $id ) {
	list( $order, $api, $store, $service ) = fixture();
	$service->create( 42 );
	$event = json_decode( event_body(), true );
	$event['data']['metadata']['order_id'] = $id;
	check( is_wp_error( deliver( $service, json_encode( $event ) ) ) && 0 === $api->reads, 'Reject ambiguous callback order identifier: ' . json_encode( $id ) );
}
list( $order, $api, $store, $service ) = fixture();
$service->create( 42 );
$order->status = 'failed';
$api->evidence = $order->get_meta( '_forus_snapshot' ) + array( 'status' => 'completed' );
check( true === $service->reconcile( 42 ) && 0 === $order->paid_calls && 'yes' === $order->get_meta( '_forus_manual_review' ), 'Late settlement on failed order requires review' );
echo 'All ' . $GLOBALS['checks'] . ' assertions passed.' . PHP_EOL;
