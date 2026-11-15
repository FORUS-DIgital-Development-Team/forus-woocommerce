<?php
/** Pure validation. Canonical evidence is an internal model, NOT a claimed backend schema. */
defined( 'ABSPATH' ) || exit;

final class FORUS_Policy {
	public static function minor( $amount, int $decimals ): int {
		if ( ! is_string( $amount ) || $decimals < 0 || $decimals > 3 || ! preg_match( '/^([0-9]{1,12})(?:\.([0-9]{1,8}))?$/D', $amount, $parts ) ) {
			throw new InvalidArgumentException( 'invalid_amount' );
		}
		$fraction = $parts[2] ?? '';
		if ( trim( substr( $fraction, $decimals ), '0' ) !== '' ) {
			throw new InvalidArgumentException( 'amount_precision' );
		}
		$digits = ltrim( $parts[1] . str_pad( substr( $fraction, 0, $decimals ), $decimals, '0' ), '0' );
		// Portable on 32-bit PHP and below the JavaScript exact-integer boundary.
		if ( strlen( $digits ) > 9 || (int) $digits <= 0 ) {
			throw new InvalidArgumentException( 'amount_range' );
		}
		return (int) $digits;
	}

	public static function https_url( $url, array $hosts ): bool {
		if ( ! is_string( $url ) || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$p = parse_url( $url );
		if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['fragment'] ) || ( isset( $p['port'] ) && 443 !== $p['port'] ) ) {
			return false;
		}
		$host = strtolower( $p['host'] ?? '' );
		return false === filter_var( $host, FILTER_VALIDATE_IP ) && in_array( $host, $hosts, true );
	}

	public static function signature( string $raw, string $header, string $secret ): bool {
		return strlen( $secret ) >= 32 && strlen( $raw ) <= 65536
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $header )
			&& hash_equals( hash_hmac( 'sha256', $raw, $secret ), $header );
	}

	public static function evidence_matches( array $e, array $s ): bool {
		foreach ( array( 'session', 'merchant', 'reference', 'currency', 'environment' ) as $key ) {
			if ( ! isset( $e[$key], $s[$key] ) || ! is_string( $s[$key] ) || ! is_string( $e[$key] ) || '' === $e[$key] || ! hash_equals( $s[$key], $e[$key] ) ) {
				return false;
			}
		}
		return isset( $e['amount'], $s['amount'] ) && is_int( $e['amount'] ) && $e['amount'] === $s['amount'];
	}

	public static function transition( string $current, string $payment ): string {
		if ( in_array( $current, array( 'processing', 'completed', 'refunded' ), true ) ) {
			return 'ignore';
		}
		if ( ! in_array( $current, array( 'pending', 'on-hold', 'failed', 'cancelled' ), true ) ) {
			return 'review';
		}
		if ( 'completed' === $payment ) {
			// A late settlement on a locally cancelled order requires stock/fulfilment review.
			return in_array( $current, array( 'cancelled', 'failed' ), true ) ? 'review' : 'paid';
		}
		if ( in_array( $payment, array( 'failed', 'cancelled' ), true ) && in_array( $current, array( 'pending', 'on-hold' ), true ) ) {
			return $payment;
		}
		return 'ignore';
	}
}
