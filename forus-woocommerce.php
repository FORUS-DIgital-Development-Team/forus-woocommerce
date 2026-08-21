<?php
/**
 * Plugin Name: FORUS Payment Gateway
 * Plugin URI:  https://developer.forus.digital/plugins/woocommerce
 * Description: Accept mobile money, instant payments, and USSD at any WooCommerce store via the FORUS API.
 * Version:     1.1.0
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

define( 'FORUS_WC_VERSION', '1.1.0' );
define( 'FORUS_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FORUS_API_BASE', 'https://api.forus.digital/v1' );

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
                'api_base_url'    => [
                    'title'       => __( 'FORUS API Base URL', 'forus-woocommerce' ),
                    'type'        => 'url',
                    'description' => __( 'Use the URL supplied for your merchant environment, without a trailing slash.', 'forus-woocommerce' ),
                    'default'     => FORUS_API_BASE,
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
            return untrailingslashit( $this->get_option( 'api_base_url', FORUS_API_BASE ) );
        }

        private function get_webhook_url(): string {
            return add_query_arg( 'wc-api', 'forus_webhook', home_url( '/' ) );
        }

        private function checkout_bridge_url( $order_id ): string {
            return add_query_arg(
                [ 'forus_checkout' => absint( $order_id ) ],
                $this->get_return_url( wc_get_order( $order_id ) )
            );
        }

        private function api_headers(): array {
            return [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ];
        }

        private function remote_error_message( $response, string $fallback ): string {
            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( is_array( $body ) && ! empty( $body['error'] ) ) {
                return sprintf( '%s (HTTP %d)', sanitize_text_field( $body['error'] ), $code );
            }
            return sprintf( '%s (HTTP %d)', $fallback, $code );
        }

        public function process_payment( $order_id ): array {
            $order = wc_get_order( $order_id );

            $response = wp_remote_post(
                $this->get_api_base() . '/payments/merchant-sessions',
                [
                    'headers' => $this->api_headers(),
                    'body'    => wp_json_encode( [
                        'merchantReference' => 'wc-' . $order_id,
                        'amountMinor' => (int) round( $order->get_total() * 100 ),
                        'currency'    => get_woocommerce_currency(),
                        'returnUrl'   => $this->get_return_url( $order ),
                        'cancelUrl'   => $order->get_cancel_order_url(),
                        'webhookUrl'  => $this->get_webhook_url(),
                        'webhookSecret' => $this->webhook_secret,
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

            if ( wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
                wc_add_notice( $this->remote_error_message( $response, __( 'Payment session could not be created.', 'forus-woocommerce' ) ), 'error' );
                return [ 'result' => 'failure' ];
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( empty( $body['id'] ) || empty( $body['checkout']['url'] ) || empty( $body['checkout']['fields'] ) || 'POST' !== ( $body['checkout']['method'] ?? '' ) ) {
                wc_add_notice( __( 'Payment error: Invalid response from FORUS.', 'forus-woocommerce' ), 'error' );
                return [ 'result' => 'failure' ];
            }

            $order->update_meta_data( '_forus_payment_session_id', sanitize_text_field( $body['id'] ) );
            $order->update_meta_data( '_forus_checkout_url', esc_url_raw( $body['checkout']['url'] ) );
            $order->update_meta_data( '_forus_checkout_fields', wp_json_encode( $body['checkout']['fields'] ) );
            $order->save();
            $order->update_status( 'pending', __( 'Awaiting FORUS payment.', 'forus-woocommerce' ) );

            return [
                'result'   => 'success',
                'redirect' => $this->checkout_bridge_url( $order_id ),
            ];
        }

        public function render_checkout_bridge(): void {
            $order_id = absint( $_GET['forus_checkout'] ?? 0 );
            $order = $order_id ? wc_get_order( $order_id ) : false;
            if ( ! $order || ! hash_equals( (string) $order->get_order_key(), (string) wc_clean( $_GET['key'] ?? '' ) ) ) {
                wp_die( esc_html__( 'FORUS checkout session could not be verified.', 'forus-woocommerce' ), esc_html__( 'Payment error', 'forus-woocommerce' ), [ 'response' => 403 ] );
            }
            $endpoint = (string) $order->get_meta( '_forus_checkout_url' );
            $fields = json_decode( (string) $order->get_meta( '_forus_checkout_fields' ), true );
            if ( empty( $endpoint ) || ! is_array( $fields ) ) {
                wp_die( esc_html__( 'FORUS checkout details have expired. Please try again.', 'forus-woocommerce' ), esc_html__( 'Payment error', 'forus-woocommerce' ), [ 'response' => 409 ] );
            }
            nocache_headers();
            echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . esc_html__( 'Redirecting to secure payment', 'forus-woocommerce' ) . '</title></head><body>';
            echo '<p>' . esc_html__( 'Redirecting to secure payment…', 'forus-woocommerce' ) . '</p>';
            echo '<form id="forus-checkout" method="post" action="' . esc_url( $endpoint ) . '">';
            foreach ( $fields as $name => $value ) {
                echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
            }
            echo '</form><script>document.getElementById("forus-checkout").submit();</script></body></html>';
            exit;
        }

        public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
            $order = wc_get_order( $order_id );
            $payment_id = $order->get_meta( '_forus_payment_session_id' );

            if ( ! $payment_id ) {
                return new \WP_Error( 'forus_refund_error', __( 'No FORUS payment ID found for this order.', 'forus-woocommerce' ) );
            }

            $response = wp_remote_post(
                $this->get_api_base() . '/payments/merchant-sessions/' . rawurlencode( $payment_id ) . '/refunds',
                [
                    'headers' => $this->api_headers(),
                    'body'    => wp_json_encode( [
                        'amountMinor' => $amount ? (int) round( $amount * 100 ) : null,
                        'reason' => $reason,
                    ] ),
                    'timeout' => 30,
                ]
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 300 && ! empty( $body['id'] ) ) {
                $order->add_order_note( sprintf( __( 'FORUS refund %s processed.', 'forus-woocommerce' ), $body['id'] ) );
                return true;
            }

            return new \WP_Error( 'forus_refund_error', $body['message'] ?? __( 'Refund failed.', 'forus-woocommerce' ) );
        }

        public function handle_webhook(): void {
            $payload   = file_get_contents( 'php://input' );
            $signature = $_SERVER['HTTP_X_FORUS_SIGNATURE'] ?? '';

            if ( ! $this->webhook_secret ) {
                http_response_code( 503 );
                exit( 'Webhook secret is not configured' );
            }
            if ( $this->webhook_secret ) {
                $expected = hash_hmac( 'sha256', $payload, $this->webhook_secret );
                if ( ! hash_equals( $expected, $signature ) ) {
                    http_response_code( 401 );
                    exit( 'Invalid signature' );
                }
            }

            $event = json_decode( $payload, true );

            if ( empty( $event['id'] ) || empty( $event['type'] ) || empty( $event['data']['id'] ) || empty( $event['data']['metadata']['order_id'] ) ) {
                http_response_code( 400 );
                exit( 'Invalid event' );
            }

            $order_id = absint( $event['data']['metadata']['order_id'] );
            $order = $order_id ? wc_get_order( $order_id ) : false;
            if ( ! $order || ! hash_equals( (string) $order->get_meta( '_forus_payment_session_id' ), (string) $event['data']['id'] ) ) {
                http_response_code( 404 );
                exit( 'Order not found' );
            }
            if ( hash_equals( (string) $order->get_meta( '_forus_last_webhook_id' ), (string) $event['id'] ) ) {
                http_response_code( 200 );
                exit( 'Duplicate event ignored' );
            }

            switch ( $event['type'] ) {
                case 'payment.completed':
                    $order->payment_complete( $event['data']['id'] );
                    $order->add_order_note( sprintf( __( 'FORUS payment %s completed.', 'forus-woocommerce' ), $event['data']['id'] ) );
                    break;

                case 'payment.failed':
                    $order->update_status( 'failed', __( 'FORUS payment failed.', 'forus-woocommerce' ) );
                    break;
            }

            $order->update_meta_data( '_forus_last_webhook_id', sanitize_text_field( $event['id'] ) );
            $order->save();

            http_response_code( 200 );
            exit( 'OK' );
        }
    }

    add_action( 'template_redirect', function () {
        if ( isset( $_GET['forus_checkout'] ) ) {
            $gateway = WC()->payment_gateways()->payment_gateways()['forus'] ?? null;
            if ( $gateway instanceof WC_FORUS_Gateway ) {
                $gateway->render_checkout_bridge();
            }
        }
    } );
} );
