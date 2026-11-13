<?php
/**
 * Plugin Name: FORUS Payment Gateway
 * Plugin URI:  https://developer.forus.digital/plugins/woocommerce
 * Description: FORUS payment gateway development candidate. Staging validation required.
 * Version:     1.1.0-dev
 * Author:      FORUS
 * Author URI:  https://forus.digital
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: forus-woocommerce
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/includes/class-forus-wc-evidence.php';

define( 'FORUS_WC_VERSION', '1.1.0-dev' );
define( 'FORUS_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FORUS_API_BASE', 'https://api.forus.digital/v1' );
define( 'FORUS_SANDBOX_BASE', 'https://sandbox-api.forus.digital/v1' );

add_action( 'woocommerce_blocks_loaded', function () {
    if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        require_once __DIR__ . '/includes/class-forus-wc-blocks.php';
        add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
            $registry->register( new FORUS_WC_Blocks() );
        } );
    }
} );

/**
 * Register the FORUS gateway with WooCommerce.
 */
add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
    $gateways[] = 'WC_FORUS_Gateway';
    return $gateways;
} );

/**
 * Load the gateway class after WooCommerce is initialised.
 */
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    class WC_FORUS_Gateway extends WC_Payment_Gateway {

        public $api_key;
        public $environment;
        public $webhook_secret;

        public function __construct() {
            $this->id                 = 'forus';
            $this->method_title       = __( 'FORUS', 'forus-woocommerce' );
            $this->method_description = __( 'Development candidate: the payment contract is not approved for live checkout.', 'forus-woocommerce' );
            $this->has_fields         = false;
            $this->supports           = [ 'products' ];

            $this->init_form_fields();
            $this->init_settings();

            $this->title          = $this->get_option( 'title', 'FORUS' );
            $this->description    = $this->get_option( 'description', 'Pay using FORUS.' );
            $this->api_key        = $this->get_option( 'api_key' );
            $this->environment    = $this->get_option( 'environment', 'sandbox' );
            $this->webhook_secret = $this->get_option( 'webhook_secret' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
            add_action( 'woocommerce_api_forus_webhook', [ $this, 'handle_webhook' ] );
            add_action( 'admin_notices', [ $this, 'release_notice' ] );
        }

        public function init_form_fields(): void {
            $this->form_fields = [
                'merchant_id' => [ 'title' => 'FORUS Merchant ID', 'type' => 'text', 'default' => '' ],
                'checkout_hosts' => [ 'title' => 'Approved checkout hosts', 'type' => 'text', 'description' => 'Comma-separated exact hostnames supplied by FORUS. No wildcards.', 'default' => '' ],
                'enabled'        => [
                    'title'   => __( 'Enable/Disable', 'forus-woocommerce' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable FORUS Payment Gateway', 'forus-woocommerce' ),
                    'default' => 'no',
                ],
                'title'          => [
                    'title'       => __( 'Title', 'forus-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Payment method title shown at checkout.', 'forus-woocommerce' ),
                    'default'     => 'FORUS',
                ],
                'description'    => [
                    'title'       => __( 'Description', 'forus-woocommerce' ),
                    'type'        => 'textarea',
                    'description' => __( 'Description shown below the payment method title.', 'forus-woocommerce' ),
                    'default'     => 'Pay using FORUS.',
                ],
                'api_key'        => [
                    'title'       => __( 'API Key', 'forus-woocommerce' ),
                    'type'        => 'password',
                    'description' => __( 'Your FORUS secret key. Get one at developer.forus.digital.', 'forus-woocommerce' ),
                    'default'     => '',
                ],
                'environment'    => [
                    'title'       => __( 'Environment', 'forus-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Use Sandbox for testing, Live for production.', 'forus-woocommerce' ),
                    'options'     => [
                        'sandbox' => __( 'Sandbox (testing)', 'forus-woocommerce' ),
                        'live'    => __( 'Live (production)', 'forus-woocommerce' ),
                    ],
                    'default'     => 'sandbox',
                ],
                'webhook_secret' => [
                    'title'       => __( 'Webhook Secret', 'forus-woocommerce' ),
                    'type'        => 'password',
                    'description' => __( 'Required HMAC secret. Unsigned events are rejected.', 'forus-woocommerce' ),
                    'default'     => '',
                ],
            ];
        }

        private function get_api_base(): string {
            return $this->environment === 'live' ? FORUS_API_BASE : FORUS_SANDBOX_BASE;
        }


        private function hosts(): array {
            return array_values( array_filter( array_map( 'trim', explode( ',', strtolower( $this->get_option( 'checkout_hosts', '' ) ) ) ) ) );
        }

        public function is_available() {
            // Remove this release gate only after backend contract and E2E verification.
            return defined( 'FORUS_WC_VERIFIED_LEGACY_CONTRACT' )
                && true === FORUS_WC_VERIFIED_LEGACY_CONTRACT
                && 'sandbox' === $this->environment
                && parent::is_available() && '' !== (string) $this->api_key
                && '' !== (string) $this->webhook_secret
                && '' !== (string) $this->get_option( 'merchant_id', '' )
                && ! empty( $this->hosts() )
                && in_array( $this->environment, array( 'sandbox', 'live' ), true );
        }

        public function release_notice(): void {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                return;
            }
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__( 'FORUS 1.1 development candidate: live payments and automatic refunds are disabled pending backend and sandbox acceptance. Existing settings are retained. Do not use this candidate on a live store.', 'forus-woocommerce' );
            echo '</p></div>';
        }

        private function failure(): array {
            wc_add_notice( __( 'FORUS could not confirm this payment attempt. Please contact the store before trying another payment.', 'forus-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        public function process_payment( $order_id ): array {
            $order = wc_get_order( $order_id );
            if ( ! $this->is_available() || ! $order || ! $order->needs_payment()
                || 'forus' !== $order->get_payment_method() ) {
                return $this->failure();
            }
            // Until currency exponents are verified, support only the pilot's ZAR.
            if ( 'ZAR' !== $order->get_currency() ) {
                return $this->failure();
            }
            try {
                $amount = FORUS_WC_Evidence::minor( (string) $order->get_total() );
            } catch ( InvalidArgumentException $error ) {
                return $this->failure();
            }
            if ( $amount <= 0 ) {
                return $this->failure();
            }
            // A durable, non-expiring unique claim: uncertain requests are never retried.
            // This is a safety brake, not a replacement for backend idempotency.
            $claim = 'forus_attempt_' . absint( $order_id );
            if ( ! add_option( $claim, 'claimed', '', false ) ) {
                return $this->failure();
            }
            $expected = array(
                'reference' => 'wc-' . $order_id,
                'currency' => $order->get_currency(),
                'amount' => $amount,
                'merchant_id' => (string) $this->get_option( 'merchant_id', '' ),
            );
            $order->update_meta_data( '_forus_expected', $expected );
            $order->update_meta_data( '_forus_environment', $this->environment );
            $order->save();
            $response = wp_safe_remote_post(
                $this->get_api_base() . '/payments',
                array(
                    'headers' => array( 'Authorization' => 'Bearer ' . $this->api_key, 'Content-Type' => 'application/json' ),
                    'body' => wp_json_encode( array_merge( $expected, array(
                        'return_url' => $this->get_return_url( $order ),
                        'cancel_url' => $order->get_cancel_order_url(),
                        'metadata' => array( 'order_id' => $order_id, 'site_url' => get_site_url() ),
                    ) ) ),
                    'timeout' => 30, 'redirection' => 0, 'limit_response_size' => 65536,
                )
            );
            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200
                || wp_remote_retrieve_response_code( $response ) >= 300 ) {
                return $this->failure();
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $body ) || ! is_string( $body['id'] ?? null ) || '' === $body['id']
                || ! FORUS_WC_Evidence::redirect( $body['checkout_url'] ?? null, $this->hosts() ) ) {
                return $this->failure();
            }
            $expected['id'] = $body['id'];
            $order->update_meta_data( '_forus_expected', $expected );
            $order->update_meta_data( '_forus_payment_id', $body['id'] );
            $order->save();
            return array( 'result' => 'success', 'redirect' => $body['checkout_url'] );
        }

        public function process_refund( $order_id, $amount = null, $reason = '' ): bool|WP_Error {
            return new WP_Error( 'forus_refund_unverified', __( 'Automatic refunds are unavailable until the FORUS refund contract is verified. Reconcile any provider refund before recording a manual refund.', 'forus-woocommerce' ) );
        }

        /**
         * Independently testable event handling. Metadata is routing information only.
         * Completion requires authenticated evidence matching the stored payment.
         */
        public function receive_event( string $payload, string $signature ): int {
            // The legacy event shape is not emitted by the audited backend.
            // An installation setting must never enable this handler in live mode.
            if ( 'sandbox' !== $this->environment
                || ! defined( 'FORUS_WC_VERIFIED_LEGACY_CONTRACT' )
                || true !== FORUS_WC_VERIFIED_LEGACY_CONTRACT ) {
                return 503;
            }
            if ( ! FORUS_WC_Evidence::signature( $payload, $signature, (string) $this->webhook_secret ) ) {
                return 401;
            }
            $event = json_decode( $payload, true );
            if ( ! is_array( $event ) || ! is_string( $event['id'] ?? null ) || '' === $event['id'] || strlen( $event['id'] ) > 200
                || ! is_array( $event['data'] ?? null ) || ! is_string( $event['type'] ?? null ) ) {
                return 400;
            }
            // Proposed sandbox contract: timestamp is inside the authenticated body.
            if ( ! is_int( $event['created_at'] ?? null )
                || abs( time() - $event['created_at'] ) > 300 ) {
                return 400;
            }
            $data = $event['data'];
            $order_id = $data['metadata']['order_id'] ?? null;
            if ( ! is_int( $order_id ) || $order_id <= 0 ) {
                return 400;
            }
            $lock = 'forus_event_lock_' . $order_id;
            if ( ! add_option( $lock, time(), '', false ) ) {
                return 503;
            }
            try {
                $order = wc_get_order( $order_id );
                if ( ! $order || 'forus' !== $order->get_payment_method()
                    || $order->get_meta( '_forus_environment' ) !== $this->environment ) {
                    return 409;
                }
                $expected = $order->get_meta( '_forus_expected' );
                if ( ! is_array( $expected ) || ! FORUS_WC_Evidence::payment( $data, $expected )
                    || $order->get_currency() !== $expected['currency']
                    || FORUS_WC_Evidence::minor( (string) $order->get_total() ) !== $expected['amount'] ) {
                    return 409;
                }
                $event_key = '_forus_event_' . hash( 'sha256', $event['id'] );
                if ( $order->get_meta( $event_key ) ) {
                    return 200;
                }
                // Paid, refunded, cancelled and failed orders cannot be downgraded or resurrected.
                if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
                    return $order->is_paid() ? 200 : 409;
                }
                $statuses = array(
                    'payment.completed' => 'completed', 'payment.failed' => 'failed',
                    'payment.cancelled' => 'cancelled', 'payment.expired' => 'expired',
                );
                if ( ! isset( $statuses[$event['type']] ) || ( $data['status'] ?? null ) !== $statuses[$event['type']] ) {
                    return 400;
                }
                if ( 'payment.completed' === $event['type'] ) {
                    $order->payment_complete( $expected['id'] );
                } else {
                    $order->update_status( 'payment.failed' === $event['type'] ? 'failed' : 'cancelled', 'FORUS verified terminal event.' );
                }
                $order->update_meta_data( $event_key, true );
                $order->save();
                return 200;
            } catch ( Throwable $error ) {
                return 503;
            } finally {
                delete_option( $lock );
            }
        }

        public function handle_webhook(): void {
            if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
                status_header( 405 );
                exit;
            }
            $payload = file_get_contents( 'php://input', false, null, 0, 65537 );
            $code = $this->receive_event( (string) $payload, (string) ( $_SERVER['HTTP_X_FORUS_SIGNATURE'] ?? '' ) );
            status_header( $code );
            exit;
        }
    }
} );

