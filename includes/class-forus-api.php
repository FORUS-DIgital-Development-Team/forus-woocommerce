<?php
/** Sandbox merchant-checkout-v1 transport. Live payments and refunds remain disabled. */
defined( 'ABSPATH' ) || exit;

interface FORUS_Adapter {
	public function ready(): bool;
	public function create( array $request, string $key );
	public function status( string $session );
	public function refund( string $session, int $amount, string $reason, string $key );
	public function refund_status( string $session, string $refund );
}

class FORUS_API implements FORUS_Adapter {
	private array $config;

	public function __construct( array $config ) {
		$this->config = $config;
	}
	public function ready(): bool {
		return defined( 'FORUS_WC_ENABLE_TEST_CHECKOUT' ) && true === FORUS_WC_ENABLE_TEST_CHECKOUT
			&& 'sandbox' === ( $this->config['environment'] ?? '' )
			&& 1 === preg_match( '/^forus_test_sk_[a-f0-9]{48}$/D', $this->config['key'] ?? '' )
			&& strlen( $this->config['secret'] ?? '' ) >= 32 && ! empty( $this->config['merchant'] )
			&& ! empty( $this->config['checkout_hosts'] )
			&& FORUS_Policy::https_url( $this->config['base'] ?? '', $this->config['api_hosts'] ?? array() )
			&& false === strpos( $this->config['base'], '?' );
	}
	public function can_retry_create(): bool {
		return $this->ready();
	}
	public function supports_refunds(): bool {
		return false;
	}
	public function create( array $request, string $key ) {
		if ( ! $this->ready() ) {
			return $this->blocked();
		}
		if ( ! preg_match( '/^wc-([1-9][0-9]*)-[a-f0-9]{16}$/D', $request['reference'] ?? '', $match ) ) {
			return $this->blocked();
		}
		$order = wc_get_order( (int) $match[1] );
		if ( ! $order || 'forus' !== $order->get_payment_method() || get_option( 'forus_attempt_' . $order->get_id() ) ) {
			return $this->blocked();
		}
		$body = array(
			'merchantReference' => $request['reference'], 'amountMinor' => $request['amount'],
			'currency' => $request['currency'], 'returnUrl' => $order->get_checkout_order_received_url(),
			'cancelUrl' => $order->get_cancel_order_url_raw(),
			'webhookUrl' => add_query_arg( 'wc-api', 'forus_webhook', home_url( '/' ) ),
			'metadata' => array( 'order_id' => (string) $order->get_id() ),
		);
		// Freeze destinations across retries; credentials are never stored in this option.
		$option = 'forus_wire_' . hash( 'sha256', $key );
		add_option( $option, $body, '', false );
		$body = get_option( $option );
		if ( ! is_array( $body ) || $body['merchantReference'] !== $request['reference']
			|| $body['amountMinor'] !== $request['amount'] || $body['currency'] !== $request['currency'] ) {
			return $this->blocked();
		}
		$body['webhookSecret'] = $this->config['secret'];
		$result = $this->request( '/payments/merchant-sessions', $body );
		$evidence = $this->normalize( $result );
		if ( is_wp_error( $evidence ) || ! FORUS_Policy::evidence_matches( $evidence, $request + array( 'session' => $evidence['session'] ) )
			|| ( $result['metadata']['order_id'] ?? null ) !== $order->get_id() ) {
			return $this->blocked();
		}
		if ( 'pending' !== $evidence['status'] ) {
			return $evidence + array( 'checkout' => null );
		}
		$checkout = $result['checkout'] ?? null;
		$fields = is_array( $checkout ) ? ( $checkout['fields'] ?? array() ) : array();
		if ( ! is_array( $fields ) || ( $fields['Amount'] ?? null ) !== (string) $request['amount']
			|| ( $fields['Currency'] ?? null ) !== $request['currency'] || 'Payment' !== ( $fields['TransactionType'] ?? null )
			|| ! is_string( $fields['Checksum'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/iD', $fields['Checksum'] ) ) {
			return $this->blocked();
		}
		return $evidence + array( 'checkout' => $checkout );
	}
	public function status( string $session ) {
		if ( ! $this->ready() || ! preg_match( '/^[a-f0-9-]{36}$/D', $session ) ) {
			return $this->blocked();
		}
		$evidence = $this->normalize( $this->request( '/payments/merchant-sessions/' . rawurlencode( $session ) ) );
		return ! is_wp_error( $evidence ) && $session === $evidence['session'] ? $evidence : $this->blocked();
	}
	public function refund( string $session, int $amount, string $reason, string $key ) {
		return $this->blocked();
	}
	public function refund_status( string $session, string $refund ) {
		return $this->blocked();
	}
	public function capabilities() {
		if ( ! $this->ready() ) {
			return $this->blocked();
		}
		$data = $this->request( '/payments/merchant-capabilities' );
		if ( is_wp_error( $data ) || 'test' !== ( $data['environment'] ?? null )
			|| $this->config['merchant'] !== ( $data['merchant_id'] ?? null ) || 'ZAR' !== ( $data['currency'] ?? null )
			|| true !== ( $data['status'] ?? null ) || false !== ( $data['live'] ?? null ) || false !== ( $data['refunds'] ?? null ) ) {
			return $this->blocked();
		}
		return $data;
	}
	private function normalize( $data ) {
		if ( ! is_array( $data ) || 'test' !== ( $data['environment'] ?? null )
			|| $this->config['merchant'] !== ( $data['merchant_id'] ?? null )
			|| ! is_string( $data['id'] ?? null ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $data['id'] )
			|| ! is_string( $data['reference'] ?? null ) || 'ZAR' !== ( $data['currency'] ?? null )
			|| ! is_int( $data['amount'] ?? null ) || $data['amount'] < 1 || $data['amount'] > 100000000
			|| ! in_array( $data['status'] ?? '', array( 'pending', 'completed', 'failed' ), true ) ) {
			return $this->blocked();
		}
		return array( 'session' => $data['id'], 'merchant' => $data['merchant_id'], 'reference' => $data['reference'],
			'environment' => 'sandbox', 'currency' => $data['currency'], 'amount' => $data['amount'], 'status' => $data['status'] );
	}
	private function request( string $path, ?array $body = null ) {
		$args = array( 'headers' => array( 'Authorization' => 'Bearer ' . $this->config['key'], 'Content-Type' => 'application/json' ),
			'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 65536 );
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
			$response = wp_safe_remote_post( $this->config['base'] . $path, $args );
		} else {
			$response = wp_safe_remote_get( $this->config['base'] . $path, $args );
		}
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return $this->blocked();
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && 'forus-merchant-checkout-v1' === ( $data['contract'] ?? null ) ? $data : $this->blocked();
	}
	private function blocked(): WP_Error {
		return new WP_Error( 'forus_contract_unverified', 'FORUS merchant operation could not be verified. Live payments and automatic refunds are unavailable.' );
	}
}
