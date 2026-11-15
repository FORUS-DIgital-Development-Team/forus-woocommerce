<?php
/** WooCommerce integration; existing gateway ID and settings option are retained. */
defined( 'ABSPATH' ) || exit;

class WC_FORUS_Gateway extends WC_Payment_Gateway {
	private FORUS_API $api;
	private FORUS_Service $service;
	private array $config;

	public function __construct() {
		$this->id = 'forus';
		$this->method_title = 'FORUS';
		$this->method_description = __( 'Hosted checkout. This development build is blocked pending verification of the merchant payment API. Do not install on a live merchant store.', 'forus-woocommerce' );
		$this->has_fields = false;
		$this->supports = array( 'products' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title', 'FORUS' );
		$this->description = $this->get_option( 'description', 'Continue to FORUS secure checkout.' );
		$this->enabled = $this->get_option( 'enabled', 'no' );
		$this->config = $this->configuration();
		$this->api = new FORUS_API( $this->config );
		$this->service = new FORUS_Service( $this->api, new FORUS_Store(), $this->config );
		add_action( 'woocommerce_update_options_payment_gateways_forus', array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_diagnostics' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled' => array( 'title' => 'Enable FORUS', 'type' => 'checkbox', 'label' => 'Enable after release gates pass', 'default' => 'no' ),
			'title' => array( 'title' => 'Checkout title', 'type' => 'text', 'default' => 'FORUS' ),
			'description' => array( 'title' => 'Checkout description', 'type' => 'textarea', 'default' => 'Continue to FORUS secure checkout.' ),
			'environment' => array( 'title' => 'Environment', 'type' => 'select', 'default' => 'sandbox', 'options' => array( 'sandbox' => 'Sandbox', 'live' => 'Live' ) ),
			'api_base_url' => array( 'title' => 'API base URL', 'type' => 'url', 'default' => '', 'description' => 'Enter the merchant API URL supplied by FORUS, including /v1. No environment hostname is assumed.' ),
			'merchant_id' => array( 'title' => 'Merchant / tenant ID', 'type' => 'text', 'default' => '' ),
			'api_key' => array( 'title' => 'API key', 'type' => 'password', 'default' => '', 'description' => 'Leave blank to retain the saved key. FORUS_WC_API_KEY in wp-config.php takes precedence.' ),
			'webhook_secret' => array( 'title' => 'Webhook secret', 'type' => 'password', 'default' => '', 'description' => 'Required in every environment: at least 32 characters. Leave blank to retain. FORUS_WC_WEBHOOK_SECRET takes precedence.' ),
			'checkout_hosts' => array( 'title' => 'Approved checkout hosts', 'type' => 'text', 'default' => '', 'description' => 'Comma-separated exact HTTPS hostnames supplied by FORUS. Wildcards and IP addresses are forbidden.' ),
		);
	}

	private function configuration(): array {
		$base = rtrim( (string) $this->get_option( 'api_base_url', '' ), '/' );
		$host = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
		return array(
			'environment' => $this->get_option( 'environment', 'sandbox' ), 'base' => $base,
			'key' => defined( 'FORUS_WC_API_KEY' ) ? (string) FORUS_WC_API_KEY : (string) $this->get_option( 'api_key', '' ),
			'secret' => defined( 'FORUS_WC_WEBHOOK_SECRET' ) ? (string) FORUS_WC_WEBHOOK_SECRET : (string) $this->get_option( 'webhook_secret', '' ),
			'merchant' => (string) $this->get_option( 'merchant_id', '' ),
			'api_hosts' => array( $host ),
			'checkout_hosts' => array_values( array_filter( array_map( 'trim', explode( ',', strtolower( (string) $this->get_option( 'checkout_hosts', '' ) ) ) ) ) ),
			// Empty until the backend's supported currencies and exponents are verified.
			'currencies' => array(),
		);
	}

	public function is_available() {
		return $this->api->ready() && 'yes' === $this->enabled && parent::is_available();
	}

	public function validate_password_field( $key, $value ) {
		// Password controls never contain an existing secret in the HTML response.
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return $this->get_option( $key, '' );
		}
		if ( preg_match( '/[\x00-\x20\x7f]/', $value ) || ( 'webhook_secret' === $key && strlen( $value ) < 32 ) ) {
			WC_Admin_Settings::add_error( 'FORUS credential rejected: use a valid key or a webhook secret of at least 32 characters.' );
			return $this->get_option( $key, '' );
		}
		return $value;
	}

	public function generate_password_html( $key, $data ) {
		$field = $this->get_field_key( $key );
		return '<tr><th scope="row"><label for="' . esc_attr( $field ) . '">' . esc_html( $data['title'] ) . '</label></th><td><input type="password" autocomplete="new-password" value="" id="' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '"><p>' . esc_html( $data['description'] ?? '' ) . '</p></td></tr>';
	}

	public function admin_options() {
		echo '<h2>FORUS</h2><p><strong>' . esc_html__( 'Release status: BLOCKED — merchant contract and sandbox verification outstanding.', 'forus-woocommerce' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Existing credentials and environment settings are retained. No new payments or refunds are sent by this build. Payment-method and country availability must be confirmed for your merchant.', 'forus-woocommerce' ) . '</p>';
		echo '<p>Webhook URL: <code>' . esc_html( add_query_arg( 'wc-api', 'forus_webhook', home_url( '/' ) ) ) . '</code></p>';
		echo '<p><a href="https://github.com/FORUS-DIgital-Development-Team/forus-woocommerce">' . esc_html__( 'Merchant documentation and release status', 'forus-woocommerce' ) . '</a></p>';
		parent::admin_options();
		echo '<p>' . esc_html__( 'Save settings before running the read-only sandbox connection test. A successful connection does not authorise payment execution.', 'forus-woocommerce' ) . '</p>';
		// Use an external submit target rather than nesting a form inside WC settings.
		echo '<button type="submit" class="button" formaction="' . esc_url( admin_url( 'admin-post.php?action=forus_connection_test' ) ) . '" formmethod="post" name="forus_test_nonce" value="' . esc_attr( wp_create_nonce( 'forus_connection_test' ) ) . '">Test saved sandbox connection</button>';
	}

	public function connection_test(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not authorised.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'forus_connection_test', 'forus_test_nonce' );
		$result = $this->api->capabilities();
		wp_die( esc_html( is_wp_error( $result ) ? $result->get_error_message() : 'Sandbox connection verified. Payment execution remains blocked.' ), 'FORUS connection test', array( 'response' => 200, 'back_link' => true ) );
	}

	public function process_payment( $order_id ): array {
		$result = $this->service->create( (int) $order_id );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( __( 'FORUS checkout is currently unavailable. Please select another payment method or contact the merchant.', 'forus-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$order = wc_get_order( $order_id );
		return array( 'result' => 'success', 'redirect' => add_query_arg( array( 'wc-api' => 'forus_checkout', 'order_id' => $order->get_id(), 'key' => $order->get_order_key() ), home_url( '/' ) ) );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return $this->service->refund( (int) $order_id, $amount, sanitize_text_field( $reason ) );
	}

	public function handle_webhook(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			status_header( 405 );
			header( 'Allow: POST' );
			exit;
		}
		$raw = file_get_contents( 'php://input', false, null, 0, 65537 );
		$header = $_SERVER['HTTP_X_FORUS_SIGNATURE'] ?? '';
		$result = $this->service->webhook( (string) $raw, is_string( $header ) ? $header : '' );
		$code = 200;
		if ( is_wp_error( $result ) ) {
			$code = 'forus_authentication' === $result->get_error_code() ? 401 : ( 'forus_event' === $result->get_error_code() ? 400 : 503 );
		}
		nocache_headers();
		status_header( $code );
		if ( 503 === $code ) {
			header( 'Retry-After: 60' );
		}
		exit( 200 === $code ? 'OK' : 'Unable to verify payment' );
	}

	public function render_checkout_bridge(): void {
		$id = isset( $_GET['order_id'] ) && is_scalar( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wp_unslash( $_GET['key'] ) : '';
		$order = wc_get_order( $id );
		if ( ! $this->api->ready() || ! $order || 'forus' !== $order->get_payment_method() || '' === $key || ! hash_equals( $order->get_order_key(), $key ) || ! in_array( $order->get_status(), array( 'pending', 'failed' ), true ) || (int) $order->get_meta( '_forus_checkout_expires' ) < time() ) {
			wp_die( 'FORUS checkout is unavailable or expired.', '', array( 'response' => 403 ) );
		}
		$url = $order->get_meta( '_forus_checkout_url' );
		$fields = $order->get_meta( '_forus_checkout_fields' );
		if ( ! FORUS_Policy::https_url( $url, $this->config['checkout_hosts'] ) || ! is_array( $fields ) ) {
			wp_die( 'Invalid checkout destination.', '', array( 'response' => 409 ) );
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'; base-uri \'none\'; frame-ancestors \'none\'; form-action https://' . wp_parse_url( $url, PHP_URL_HOST ) );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>FORUS secure checkout</title></head><body><h1>Continue to secure checkout</h1><form method="post" action="' . esc_url( $url ) . '">';
		foreach ( $fields as $name => $value ) {
			if ( ! is_string( $value ) || ! is_string( $name ) ) {
				wp_die( 'Invalid checkout fields.' );
			}
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '<button type="submit">Continue to FORUS</button></form></body></html>';
		exit;
	}

	public function reconcile( int $order_id ): void {
		$this->service->reconcile( $order_id );
	}

	public function order_diagnostics( $order ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || 'forus' !== $order->get_payment_method() ) {
			return;
		}
		echo '<p><strong>FORUS:</strong> ' . esc_html( (string) $order->get_meta( '_forus_last_status' ) ?: 'No verified payment status' ) . '</p>';
		foreach ( array( '_forus_payment_session_id', '_forus_last_checked', '_forus_manual_review', '_forus_recovery_exhausted' ) as $key ) {
			echo '<p>' . esc_html( $key . ': ' . (string) $order->get_meta( $key ) ) . '</p>';
		}
		$refund = $order->get_meta( '_forus_refund_operation' );
		if ( is_array( $refund ) ) {
			echo '<p>FORUS refund: ' . esc_html( $refund['state'] ?? 'unknown' ) . '</p>';
		}
	}
}
