<?php
/** One-time seed for the dedicated demonstration store, executed through WP-CLI. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WooCommerce' ) ) {
    exit( 1 );
}
if ( get_option( 'forus_demo_seeded' ) ) {
    WP_CLI::success( 'Existing demonstration data preserved.' );
    return;
}
update_option( 'blogname', 'FORUS Merchant Demo' );
update_option( 'blogdescription', 'Explore FORUS checkout in a demonstration store.' );
update_option( 'blog_public', '0' );
update_option( 'timezone_string', 'Africa/Johannesburg' );
update_option( 'woocommerce_currency', 'ZAR' );
update_option( 'woocommerce_default_country', 'ZA' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_demo_store', 'yes' );
update_option( 'woocommerce_demo_store_notice', 'FORUS demonstration store. No real purchases or live payments.' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_enable_checkout_login_reminder', 'yes' );
update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
foreach ( array( 'bacs', 'cheque', 'cod', 'forus' ) as $gateway ) {
    $settings = get_option( 'woocommerce_' . $gateway . '_settings', array() );
    $settings['enabled'] = 'no';
    update_option( 'woocommerce_' . $gateway . '_settings', $settings );
}
WC_Install::create_pages();
$product = new WC_Product_Simple();
$product->set_name( 'FORUS checkout demonstration' );
$product->set_slug( 'forus-checkout-demonstration' );
$product->set_sku( 'FORUS-DEMO-001' );
$product->set_status( 'publish' );
$product->set_virtual( true );
$product->set_regular_price( '1.00' );
$product->set_description( 'A demonstration item for testing the checkout journey. This is not a product for sale. Payments remain unavailable until the sandbox is configured.' );
$product->save();
$home = wp_insert_post( array(
    'post_title' => 'FORUS Merchant Demo', 'post_name' => 'home', 'post_status' => 'publish', 'post_type' => 'page',
    'post_content' => '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Explore FORUS checkout</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Welcome to the FORUS merchant demonstration store. Browse the sample item, explore your basket and view checkout.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p><strong>This is a test store. No real purchases or live payments are available.</strong></p><!-- /wp:paragraph -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"#f47721"}}} --><div class="wp-block-button"><a class="wp-block-button__link has-background wp-element-button" style="background-color:#f47721" href="' . esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ) . '">Explore the demo shop</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
), true );
if ( is_wp_error( $home ) ) {
    WP_CLI::error( $home->get_error_message() );
}
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home );
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules( true );
update_option( 'forus_demo_seeded', gmdate( 'c' ) );
WP_CLI::success( 'Demonstration store configured; all payment gateways disabled.' );
