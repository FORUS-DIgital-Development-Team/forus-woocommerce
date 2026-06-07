<?php
/**
 * Plugin Name: FORUS Payment Gateway
 * Plugin URI:  https://developer.forus.digital/plugins/woocommerce
 * Description: Accept mobile money, instant payments, and USSD at any WooCommerce store via the FORUS API.
 * Version:     1.0.0
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

define( 'FORUS_WC_VERSION', '1.0.0' );
define( 'FORUS_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FORUS_API_BASE', 'https://api.forus.digital/v1' );
define( 'FORUS_SANDBOX_BASE', 'https://sandbox-api.forus.digital/v1' );

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

        public function __construct() {
            $this->id                 = 'forus';
            $this->method_title       = __( 'FORUS', 'forus-woocommerce' );
            $this->method_description = __( 'Accept mobile money, instant payments, card, QR, and USSD via the FORUS API.', 'forus-woocommerce' );
            $this->has_fields         = false;
            $this->supports           = [ 'products', 'refunds' ];

            $this->init_form_fields();
            $this->init_settings();

            $this->title          = $this->get_option( 'title', 'FORUS' );
            $this->description    = $this->get_option( 'description', 'Pay with mobile money, card, or instant bank transfer.' );
            $this->api_key        = $this->get_option( 'api_key' );
            $this->environment    = $this->get_option( 'environment', 'sandbox' );
            $this->webhook_secret = $this->get_option( 'webhook_secret' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
            add_action( 'woocommerce_api_forus_webhook', [ $this, 'handle_webhook' ] );
        }

        public function init_form_fields(): void {
            $this->form_fields = [
                'enabled'        => [
                    'title'   => __( 'Enable/Disable', 'forus-woocommerce' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable FORUS Payment Gateway', 'forus-woocommerce' ),
                    'default' => 'yes',
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
                    'default'     => 'Pay with mobile money, card, instant bank transfer, or QR code.',
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
                    'description' => __( 'HMAC secret for webhook signature verification (recommended).', 'forus-woocommerce' ),
                    'default'     => '',
                ],
            ];
        }

        private function get_api_base(): string {
            return $this->environment === 'live' ? FORUS_API_BASE : FORUS_SANDBOX_BASE;
        }

        public function process_payment( $order_id ): array {
            $order = wc_get_order( $order_id );

            $response = wp_remote_post(
                $this->get_api_base() . '/payments',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->api_key,
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => wp_json_encode( [
                        'amount'      => (int) round( $order->get_total() * 100 ),
                        'currency'    => get_woocommerce_currency(),
                        'reference'   => 'wc-' . $order_id,
                        'description' => 'Order #' . $order->get_order_number(),
                        'return_url'  => $this->get_return_url( $order ),
                        'cancel_url'  => $order->get_cancel_order_url(),
                        'metadata'    => [
                            'order_id'  => $order_id,
                            'site_url'  => get_site_url(),
                        ],
                    ] ),
                    'timeout' => 30,
                ]
            );

            if ( is_wp_error( $response ) ) {
                wc_add_notice( __( 'Payment error: Could not connect to FORUS. Please try again.', 'forus-woocommerce' ), 'error' );
                return [ 'result' => 'failure' ];
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( empty( $body['checkout_url'] ) ) {
                wc_add_notice( __( 'Payment error: Invalid response from FORUS.', 'forus-woocommerce' ), 'error' );
                return [ 'result' => 'failure' ];
            }

            $order->update_status( 'pending', __( 'Awaiting FORUS payment.', 'forus-woocommerce' ) );

            return [
                'result'   => 'success',
                'redirect' => $body['checkout_url'],
            ];
        }

        public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
            $order = wc_get_order( $order_id );
            $payment_id = $order->get_transaction_id();

            if ( ! $payment_id ) {
                return new \WP_Error( 'forus_refund_error', __( 'No FORUS payment ID found for this order.', 'forus-woocommerce' ) );
            }

            $response = wp_remote_post(
                $this->get_api_base() . '/payments/' . $payment_id . '/refund',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->api_key,
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => wp_json_encode( [
                        'amount' => $amount ? (int) round( $amount * 100 ) : null,
                        'reason' => $reason,
                    ] ),
                    'timeout' => 30,
                ]
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( ! empty( $body['id'] ) ) {
                $order->add_order_note( sprintf( __( 'FORUS refund %s processed.', 'forus-woocommerce' ), $body['id'] ) );
                return true;
            }

            return new \WP_Error( 'forus_refund_error', $body['message'] ?? __( 'Refund failed.', 'forus-woocommerce' ) );
        }

        public function handle_webhook(): void {
            $payload   = file_get_contents( 'php://input' );
            $signature = $_SERVER['HTTP_X_FORUS_SIGNATURE'] ?? '';

            if ( $this->webhook_secret ) {
                $expected = hash_hmac( 'sha256', $payload, $this->webhook_secret );
                if ( ! hash_equals( $expected, $signature ) ) {
                    http_response_code( 401 );
                    exit( 'Invalid signature' );
                }
            }

            $event = json_decode( $payload, true );

            if ( empty( $event['type'] ) ) {
                http_response_code( 400 );
                exit( 'Invalid event' );
            }

            switch ( $event['type'] ) {
                case 'payment.completed':
                    $order_id = $event['data']['metadata']['order_id'] ?? null;
                    if ( $order_id ) {
                        $order = wc_get_order( $order_id );
                        if ( $order ) {
                            $order->payment_complete( $event['data']['id'] );
                            $order->add_order_note( sprintf( __( 'FORUS payment %s completed.', 'forus-woocommerce' ), $event['data']['id'] ) );
                        }
                    }
                    break;

                case 'payment.failed':
                    $order_id = $event['data']['metadata']['order_id'] ?? null;
                    if ( $order_id ) {
                        $order = wc_get_order( $order_id );
                        if ( $order ) {
                            $order->update_status( 'failed', __( 'FORUS payment failed.', 'forus-woocommerce' ) );
                        }
                    }
                    break;
            }

            http_response_code( 200 );
            exit( 'OK' );
        }
    }
} );
