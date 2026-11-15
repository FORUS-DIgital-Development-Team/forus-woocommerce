<?php
/** Contract boundary. Financial routes remain gated until backend and sandbox acceptance. */
defined( 'ABSPATH' ) || exit;

interface FORUS_Adapter {
	public function ready(): bool;
	public function create( array $request, string $key );
	public function status( string $session );
	public function refund( string $session, int $amount, string $reason, string $key );
	public function refund_status( string $session, string $refund );
}

class FORUS_API implements FORUS_Adapter {
	public function __construct( array $config ) {
		// Configuration alone cannot establish backend or provider readiness.
	}
	public function ready(): bool {
		// No merchant setting, constant or filter may bypass this gate.
		return false;
	}
	public function create( array $request, string $key ) {
		return $this->blocked();
	}
	public function status( string $session ) {
		return $this->blocked();
	}
	public function refund( string $session, int $amount, string $reason, string $key ) {
		return $this->blocked();
	}
	public function refund_status( string $session, string $refund ) {
		return $this->blocked();
	}
	private function blocked(): WP_Error {
		return new WP_Error( 'forus_contract_unverified', 'Merchant payment contract is not verified. No payment request was sent.' );
	}
	public function capabilities() {
		// Rail /v1/capabilities is public deployment information. Sending a key
		// there cannot verify a merchant. Do not present it as a connection test.
		return new WP_Error( 'forus_connection_unverified', 'Merchant connection verification is unavailable until an authenticated merchant endpoint is implemented. No credential was transmitted.' );
	}
}
