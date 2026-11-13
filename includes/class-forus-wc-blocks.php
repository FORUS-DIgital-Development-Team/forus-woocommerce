<?php
/** Checkout Blocks adapter; uses the same availability and server payment gates. */
defined( 'ABSPATH' ) || exit;

final class FORUS_WC_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    protected $name = 'forus';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_forus_settings', array() );
    }

    public function is_active() {
        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset( $gateways['forus'] ) && $gateways['forus']->is_available();
    }

    public function get_payment_method_script_handles() {
        wp_register_script( 'forus-wc-blocks', plugins_url( 'blocks.js', __FILE__ ),
            array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ), FORUS_WC_VERSION, true );
        return array( 'forus-wc-blocks' );
    }

    public function get_payment_method_data() {
        // Never expose credentials or the complete gateway settings to JavaScript.
        return array(
            'title' => wp_strip_all_tags( $this->get_setting( 'title', 'FORUS' ) ),
            'description' => wp_strip_all_tags( $this->get_setting( 'description', 'Pay using FORUS.' ) ),
            'enabled' => $this->is_active(),
            'supports' => array( 'products' ),
        );
    }
}
