<?php
/** Payment integrity engine. Adapters must return authenticated canonical evidence. */
defined( 'ABSPATH' ) || exit;

final class FORUS_Service {
	private FORUS_Adapter $api;
	private FORUS_Store $store;
	private array $config;

	public function __construct( FORUS_Adapter $api, FORUS_Store $store, array $config ) {
		$this->api = $api;
		$this->store = $store;
		$this->config = $config;
	}

	public function create( int $id ) {
		if ( ! $this->api->ready() ) {
			return $this->error( 'contract_unverified' );
		}
		return $this->store->locked( $id, function ( $order ) {
			if ( ! in_array( $order->get_status(), array( 'pending', 'failed' ), true ) || $order->is_paid() ) {
				return $this->error( 'order_state' );
			}
			try {
				$snapshot = $this->snapshot( $order );
			} catch ( InvalidArgumentException $e ) {
				return $this->error( 'amount_or_currency' );
			}
			$operation = $order->get_meta( '_forus_create_operation' );
			if ( is_array( $operation ) ) {
				if ( $operation['snapshot'] !== $snapshot ) {
					return $this->error( 'order_changed' );
				}
				if ( 'ready' === $operation['state'] && $order->get_meta( '_forus_checkout_expires' ) > time() && $order->get_meta( '_forus_checkout_fields' ) ) {
					return array( 'session' => $order->get_meta( '_forus_payment_session_id' ) );
				}
				// Never retry an ambiguous financial POST, even after a worker crash.
				return $this->error( 'reconciliation_required' );
			}
			$operation = array( 'key' => wp_generate_uuid4(), 'snapshot' => $snapshot, 'state' => 'unknown' );
			$order->update_meta_data( '_forus_create_operation', $operation );
			$order->save();
			$result = $this->api->create( $snapshot, $operation['key'] );
			if ( is_wp_error( $result ) || ! is_array( $result ) || ! $this->valid_checkout( $result ) ) {
				return $this->error( 'reconciliation_required' );
			}
			$snapshot['session'] = $result['session'];
			$order->update_meta_data( '_forus_snapshot', $snapshot );
			$order->update_meta_data( '_forus_payment_session_id', $result['session'] );
			$order->update_meta_data( '_forus_checkout_url', $result['checkout']['url'] );
			$order->update_meta_data( '_forus_checkout_fields', $result['checkout']['fields'] );
			$order->update_meta_data( '_forus_checkout_expires', time() + 900 );
			$operation['state'] = 'ready';
			$order->update_meta_data( '_forus_create_operation', $operation );
			$order->save();
			$this->schedule( $order );
			return array( 'session' => $result['session'] );
		} );
	}

	public function webhook( string $raw, string $signature ) {
		if ( ! FORUS_Policy::signature( $raw, $signature, $this->config['secret'] ) ) {
			return $this->error( 'authentication' );
		}
		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) || ! is_string( $event['id'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,200}$/D', $event['id'] ) || ! is_string( $event['type'] ?? null ) || ! is_array( $event['data'] ?? null ) ) {
			return $this->error( 'event' );
		}
		$id = $event['data']['metadata']['order_id'] ?? null;
		$session = $event['data']['id'] ?? null;
		// Rail Connector PR #29 emits string-valued metadata and an ISO UTC
		// createdAt inside the signed body. Never invent an unsigned header.
		if ( is_string( $id ) && preg_match( '/^[1-9][0-9]*$/D', $id ) && strlen( $id ) <= strlen( (string) PHP_INT_MAX ) && (string) (int) $id === $id ) {
			$id = (int) $id;
		}
		if ( ! is_int( $id ) || $id <= 0 || ! is_string( $session ) || '' === $session ) {
			return $this->error( 'event' );
		}
		$created = $event['createdAt'] ?? null;
		if ( ! is_string( $created ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $created ) ) {
			return $this->error( 'event' );
		}
		$timestamp = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s.v\Z', $created, new DateTimeZone( 'UTC' ) );
		if ( false === $timestamp || $timestamp->format( 'Y-m-d\TH:i:s.v\Z' ) !== $created || abs( time() - $timestamp->getTimestamp() ) > 300 ) {
			return $this->error( 'event' );
		}
		if ( ! in_array( $event['type'], array( 'payment.completed', 'payment.failed', 'payment.cancelled' ), true ) ) {
			return $this->error( 'event' );
		}
		if ( ! $this->api->ready() ) {
			return $this->error( 'contract_unverified' );
		}
		return $this->store->locked( $id, function ( $order ) use ( $event, $session ) {
			if ( ! hash_equals( (string) $order->get_meta( '_forus_payment_session_id' ), $session ) ) {
				return $this->error( 'binding' );
			}
			if ( $this->store->event_seen( $order, $event['id'] ) ) {
				return true;
			}
			if ( count( $this->store->events( $order ) ) >= 4096 ) {
				return $this->error( 'event_capacity' );
			}
			// Even a fresh signed event is solely a wake-up signal. Current
			// authenticated status must independently bind the payment to this order.
			$result = $this->apply_status( $order );
			if ( ! is_wp_error( $result ) ) {
				$this->store->record_event( $order, $event['id'] );
			}
			return $result;
		} );
	}

	public function reconcile( int $id ) {
		if ( ! $this->api->ready() ) {
			return $this->error( 'contract_unverified' );
		}
		return $this->store->locked( $id, function ( $order ) {
			$result = $this->apply_status( $order );
			if ( is_wp_error( $result ) || in_array( $order->get_status(), array( 'pending', 'on-hold', 'failed' ), true ) ) {
				$this->schedule( $order );
			}
			return $result;
		} );
	}

	/** Read-only refund tracking; never creates a second remote refund or a local refund. */
	public function reconcile_refund( int $id ) {
		if ( ! $this->api->ready() ) {
			return $this->error( 'contract_unverified' );
		}
		return $this->store->locked( $id, function ( $order ) {
			$snapshot = $order->get_meta( '_forus_snapshot' );
			$operation = $order->get_meta( '_forus_refund_operation' );
			if ( ! is_array( $snapshot ) || ! is_array( $operation ) || empty( $operation['refund'] ) || ! $this->current_snapshot_matches( $order, $snapshot ) ) {
				return $this->error( 'refund_reconciliation_required' );
			}
			$result = $this->api->refund_status( $snapshot['session'], $operation['refund'] );
			$expected = $snapshot;
			$expected['amount'] = $operation['amount'];
			if ( is_wp_error( $result ) || ! is_array( $result ) || ! FORUS_Policy::evidence_matches( $result, $expected ) || ( $result['refund'] ?? null ) !== $operation['refund'] || ! in_array( $result['status'] ?? null, array( 'pending', 'completed', 'failed' ), true ) ) {
				return $this->error( 'evidence' );
			}
			// No downgrade if an eventually consistent backend reports stale data.
			if ( 'completed' !== $operation['state'] ) {
				$operation['state'] = $result['status'];
				$order->update_meta_data( '_forus_refund_operation', $operation );
				$order->save();
			}
			return true;
		} );
	}

	private function apply_status( $order ) {
		$snapshot = $order->get_meta( '_forus_snapshot' );
		if ( ! is_array( $snapshot ) || empty( $snapshot['session'] ) || ! $this->current_snapshot_matches( $order, $snapshot ) ) {
			return $this->error( 'binding' );
		}
		$evidence = $this->api->status( $snapshot['session'] );
		if ( is_wp_error( $evidence ) ) {
			return $this->error( 'status_unavailable' );
		}
		if ( ! is_array( $evidence ) || ! FORUS_Policy::evidence_matches( $evidence, $snapshot ) || ! in_array( $evidence['status'] ?? null, array( 'pending', 'completed', 'failed', 'cancelled' ), true ) ) {
			return $this->error( 'evidence' );
		}
		$action = FORUS_Policy::transition( $order->get_status(), $evidence['status'] );
		if ( 'paid' === $action ) {
			$order->payment_complete( $snapshot['session'] );
		} elseif ( in_array( $action, array( 'failed', 'cancelled' ), true ) ) {
			$order->update_status( $action, 'FORUS authenticated payment status confirmed.' );
		} elseif ( 'review' === $action ) {
			if ( 'yes' !== $order->get_meta( '_forus_manual_review' ) ) {
				$order->update_meta_data( '_forus_manual_review', 'yes' );
				$order->add_order_note( 'FORUS payment needs manual review. Fulfilment has not been authorised.' );
			}
		}
		$order->update_meta_data( '_forus_last_status', $evidence['status'] );
		$order->update_meta_data( '_forus_last_checked', time() );
		if ( 'pending' !== $evidence['status'] ) {
			$order->delete_meta_data( '_forus_checkout_fields' );
			$order->delete_meta_data( '_forus_checkout_url' );
		}
		$order->save();
		return true;
	}

	public function refund( int $id, $amount, string $reason ) {
		if ( ! $this->api->ready() ) {
			return $this->error( 'contract_unverified' );
		}
		return $this->store->locked( $id, function ( $order ) use ( $amount, $reason ) {
			$snapshot = $order->get_meta( '_forus_snapshot' );
			if ( ! $order->is_paid() || ! is_array( $snapshot ) || ! $this->current_snapshot_matches( $order, $snapshot ) ) {
				return $this->error( 'binding' );
			}
			try {
				$value = FORUS_Policy::minor( (string) $amount, $snapshot['decimals'] );
				$remaining = FORUS_Policy::minor( (string) $order->get_remaining_refund_amount(), $snapshot['decimals'] );
			} catch ( InvalidArgumentException $e ) {
				return $this->error( 'refund_amount' );
			}
			if ( $value > $remaining ) {
				return $this->error( 'refund_amount' );
			}
			$previous = $order->get_meta( '_forus_refund_operation' );
			$local_total = (string) $order->get_total_refunded();
			if ( is_array( $previous ) ) {
				if ( 'completed' !== $previous['state'] || $local_total === $previous['local_total'] ) {
					return $this->error( 'refund_reconciliation_required' );
				}
				// The local Woo refund must reflect at least the confirmed remote refund
				// before another financial operation can be submitted.
				$prior = '0' === $previous['local_total'] || 0.0 === (float) $previous['local_total'] ? 0 : FORUS_Policy::minor( $previous['local_total'], $snapshot['decimals'] );
				$now = FORUS_Policy::minor( $local_total, $snapshot['decimals'] );
				if ( $now < $prior + $previous['amount'] ) {
					return $this->error( 'refund_reconciliation_required' );
				}
			}
			$operation = array( 'key' => wp_generate_uuid4(), 'state' => 'unknown', 'amount' => $value, 'local_total' => $local_total );
			$order->update_meta_data( '_forus_refund_operation', $operation );
			$order->save();
			$result = $this->api->refund( $snapshot['session'], $value, $reason, $operation['key'] );
			$expected = $snapshot;
			$expected['amount'] = $value;
			if ( is_wp_error( $result ) || ! is_array( $result ) || ! FORUS_Policy::evidence_matches( $result, $expected ) || ! is_string( $result['refund'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,200}$/D', $result['refund'] ) || ! in_array( $result['status'] ?? null, array( 'pending', 'completed', 'failed' ), true ) ) {
				return $this->error( 'refund_reconciliation_required' );
			}
			$operation['refund'] = $result['refund'];
			$operation['state'] = $result['status'];
			$order->update_meta_data( '_forus_refund_operation', $operation );
			$order->save();
			if ( 'completed' !== $operation['state'] ) {
				return $this->error( 'refund_reconciliation_required' );
			}
			$order->add_order_note( 'FORUS authenticated refund confirmed. Reference: ' . sanitize_text_field( $result['refund'] ) );
			return true;
		} );
	}

	private function snapshot( $order ): array {
		$currency = $order->get_currency();
		$decimals = $this->config['currencies'][$currency] ?? null;
		if ( ! is_int( $decimals ) || '' === $this->config['merchant'] || '' === $this->config['key'] || strlen( $this->config['secret'] ) < 32 || empty( $this->config['checkout_hosts'] ) || ! FORUS_Policy::https_url( $this->config['base'], $this->config['api_hosts'] ) || ! in_array( $this->config['environment'], array( 'sandbox', 'live' ), true ) ) {
			throw new InvalidArgumentException( 'configuration' );
		}
		return array(
			'amount' => FORUS_Policy::minor( (string) $order->get_total(), $decimals ),
			'currency' => $currency, 'decimals' => $decimals,
			'merchant' => $this->config['merchant'], 'environment' => $this->config['environment'],
			'reference' => 'wc-' . $order->get_id() . '-' . substr( hash( 'sha256', home_url( '/' ) ), 0, 16 ),
			'credential' => hash_hmac( 'sha256', $this->config['base'] . '|' . $this->config['key'] . '|' . $this->config['secret'], wp_salt( 'auth' ) ),
		);
	}

	private function current_snapshot_matches( $order, array $snapshot ): bool {
		try {
			$current = $this->snapshot( $order );
		} catch ( InvalidArgumentException $e ) {
			return false;
		}
		unset( $snapshot['session'] );
		return $snapshot === $current;
	}

	private function valid_checkout( array $result ): bool {
		$c = $result['checkout'] ?? null;
		if ( ! is_string( $result['session'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,200}$/D', $result['session'] ) || ! is_array( $c ) || 'POST' !== ( $c['method'] ?? '' ) || ! FORUS_Policy::https_url( $c['url'] ?? null, $this->config['checkout_hosts'] ) || ! is_array( $c['fields'] ?? null ) || count( $c['fields'] ) > 100 || empty( $c['fields'] ) ) {
			return false;
		}
		foreach ( $c['fields'] as $name => $value ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_.-]{1,100}$/D', $name ) || ! is_string( $value ) || strlen( $value ) > 8192 ) {
				return false;
			}
		}
		return strlen( wp_json_encode( $c['fields'] ) ) <= 32768;
	}

	private function schedule( $order ): void {
		$attempt = (int) $order->get_meta( '_forus_reconcile_attempt' );
		if ( $attempt >= 12 ) {
			if ( 'yes' !== $order->get_meta( '_forus_recovery_exhausted' ) ) {
				$order->update_meta_data( '_forus_recovery_exhausted', 'yes' );
				$order->update_meta_data( '_forus_manual_review', 'yes' );
				$order->add_order_note( 'FORUS automatic payment verification exhausted its retry limit. Check the provider payment record before fulfilment or another payment attempt.' );
				$order->save();
			}
			return;
		}
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}
		$args = array( $order->get_id() );
		// Exclude the currently running action, otherwise a recovery worker cannot
		// enqueue its successor. The per-order lock serializes this check/enqueue.
		$pending = as_get_scheduled_actions( array( 'hook' => 'forus_reconcile_order', 'args' => $args, 'group' => 'forus', 'status' => 'pending', 'per_page' => 1 ), 'ids' );
		if ( ! $pending ) {
			$scheduled = as_schedule_single_action( time() + min( 3600, 60 * ( 2 ** $attempt ) ), 'forus_reconcile_order', $args, 'forus', false );
			if ( ! $scheduled ) {
				return;
			}
			$order->update_meta_data( '_forus_reconcile_attempt', $attempt + 1 );
			$order->save();
		}
	}

	private function error( string $code ): WP_Error {
		return new WP_Error( 'forus_' . $code, 'FORUS payment requires verification. Contact the merchant with your order number. Diagnostic: ' . $code );
	}
}
