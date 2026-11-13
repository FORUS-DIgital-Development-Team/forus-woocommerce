<?php
require __DIR__ . '/../includes/class-forus-wc-evidence.php';
function check( $condition, $label ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
}
check( FORUS_WC_Evidence::minor( '12.34' ) === 1234, 'decimal conversion' );
check( FORUS_WC_Evidence::minor( '0.01' ) === 1, 'small amount' );
foreach ( array( '-1', '1.001', '1e2', 'NaN', '', '01' ) as $invalid ) {
    try { FORUS_WC_Evidence::minor( $invalid ); throw new RuntimeException( 'accepted invalid amount' ); }
    catch ( InvalidArgumentException $expected ) {}
}
$body = '{"id":"event-1"}';
$secret = 'test-secret';
$sig = hash_hmac( 'sha256', $body, $secret );
check( FORUS_WC_Evidence::signature( $body, $sig, $secret ), 'valid signature' );
check( ! FORUS_WC_Evidence::signature( $body . ' ', $sig, $secret ), 'tampered body' );
check( ! FORUS_WC_Evidence::signature( $body, $sig, '' ), 'empty secret' );
check( ! FORUS_WC_Evidence::signature( str_repeat( 'a', 65537 ), $sig, $secret ), 'oversized body' );
$expected = array( 'id'=>'p1', 'reference'=>'wc-1', 'currency'=>'ZAR', 'merchant_id'=>'m1', 'amount'=>1234 );
check( FORUS_WC_Evidence::payment( $expected, $expected ), 'matching payment' );
foreach ( array_keys( $expected ) as $field ) {
    $bad = $expected; unset( $bad[$field] );
    check( ! FORUS_WC_Evidence::payment( $bad, $expected ), 'missing ' . $field );
    $bad = $expected; $bad[$field] = 'wrong';
    check( ! FORUS_WC_Evidence::payment( $bad, $expected ), 'wrong ' . $field );
}
$bad = $expected; $bad['amount'] = '1234';
check( ! FORUS_WC_Evidence::payment( $bad, $expected ), 'amount type' );
$hosts = array( 'checkout.example.com' );
check( FORUS_WC_Evidence::redirect( 'https://checkout.example.com/pay', $hosts ), 'approved host' );
foreach ( array( 'http://checkout.example.com', 'https://checkout.example.com.evil.test',
    'https://checkout.example.com@evil.test', '//checkout.example.com', 'https://checkout.example.com:8080',
    "https://checkout.example.com/\n", 'javascript:alert(1)' ) as $url ) {
    check( ! FORUS_WC_Evidence::redirect( $url, $hosts ), 'unsafe redirect ' . $url );
}
echo "Security helper regression tests passed. This is not WooCommerce or sandbox E2E verification.\n";

