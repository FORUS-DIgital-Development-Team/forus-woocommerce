<?php
defined( 'ABSPATH' ) || exit;

final class FORUS_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
	protected $name = 'forus';
	private $gateway;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_forus_settings', array() );
		$this->gateway = WC()->payment_gateways()->payment_gateways()['forus'] ?? null;
	}
	public function is_active() {
		return $this->gateway && $this->gateway->is_available();
	}
	public function get_payment_method_script_handles() {
		wp_register_script( 'forus-blocks', plugins_url( 'assets/blocks.js', FORUS_WC_FILE ), array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ), FORUS_WC_VERSION, true );
		return array( 'forus-blocks' );
	}
	public function get_payment_method_data() {
		// Explicit public allowlist. Never serialize the settings object.
		return array(
			'title' => sanitize_text_field( $this->settings['title'] ?? 'FORUS' ),
			'description' => sanitize_text_field( $this->settings['description'] ?? 'Continue to FORUS secure checkout.' ),
			'available' => (bool) $this->is_active(),
			'supports' => array( 'products' ),
		);
	}
}
