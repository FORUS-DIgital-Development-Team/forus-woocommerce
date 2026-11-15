<?php
/** Connection-owned DB lock: no expiring lease can permit two overlapping money operations. */
defined( 'ABSPATH' ) || exit;

class FORUS_Store {
	public function locked( int $id, callable $callback ) {
		global $wpdb;
		$name = 'forus_' . substr( hash( 'sha256', $wpdb->prefix . ':' . $id ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
			return new WP_Error( 'forus_busy', 'Payment operation is busy; retry later.' );
		}
		try {
			// Load AFTER lock acquisition, including on HPOS installations.
			$order = wc_get_order( $id );
			if ( ! $order || 'forus' !== $order->get_payment_method() ) {
				return new WP_Error( 'forus_order', 'FORUS order not found.' );
			}
			return $callback( $order );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	public function event_seen( $order, string $id ): bool {
		return isset( $this->events( $order )[ hash( 'sha256', $id ) ] );
	}

	public function events( $order ): array {
		$value = $order->get_meta( '_forus_events' );
		return is_array( $value ) ? $value : array();
	}

	public function record_event( $order, string $id ): void {
		$events = $this->events( $order );
		$events[ hash( 'sha256', $id ) ] = time();
		$order->update_meta_data( '_forus_events', $events );
		$order->save();
	}
}
