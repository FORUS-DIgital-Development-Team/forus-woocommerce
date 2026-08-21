<?php
/**
 * Static contract regression check.
 *
 * The production gateway executes inside WordPress/WooCommerce, which is not
 * bundled with this repository. This test protects the integration boundary
 * that can be verified without a WordPress runtime.
 */

$source = file_get_contents( __DIR__ . '/../forus-woocommerce.php' );
if ( false === $source ) {
    fwrite( STDERR, "Could not read gateway source\n" );
    exit( 1 );
}

$required = [
    '/payments/merchant-sessions',
    '/refunds',
    "'amountMinor'",
    "'webhookSecret'",
    "'HTTP_X_FORUS_SIGNATURE'",
    "'_forus_payment_session_id'",
    "'_forus_last_webhook_id'",
    'render_checkout_bridge',
    'document.getElementById("forus-checkout").submit()',
];

foreach ( $required as $needle ) {
    if ( false === strpos( $source, $needle ) ) {
        fwrite( STDERR, "Missing merchant contract safeguard: {$needle}\n" );
        exit( 1 );
    }
}

if ( false !== strpos( $source, 'FORUS_SANDBOX_BASE' ) ) {
    fwrite( STDERR, "Legacy unverified sandbox base must not remain in the gateway\n" );
    exit( 1 );
}

echo "WooCommerce merchant contract smoke test passed\n";
