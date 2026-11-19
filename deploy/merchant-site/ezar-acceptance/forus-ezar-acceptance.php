<?php
/**
 * Plugin Name: FORUS eZAR Merchant Acceptance
 * Description: Merchant wallet binding and a restricted Open Pay integration candidate. Live checkout remains locked pending provider acceptance.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/openpay-client.php';

add_action('before_woocommerce_init', function () {
    if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/** A verified destination is not proof of merchant entitlement or a completed payment. */
function forus_ezar_acceptance_status() {
    $binding = get_option('forus_ezar_merchant_binding', array());
    return array(
        'merchant_name' => $binding['merchant_name'] ?? '',
        'owner_email' => $binding['owner_email'] ?? '',
        'account_id' => $binding['account_id'] ?? '',
        'wallet_address' => $binding['wallet_address'] ?? '',
        'wallet_verified_at' => $binding['wallet_verified_at'] ?? '',
        'provider_wallet_category' => $binding['provider_wallet_category'] ?? '',
        'state' => empty($binding) ? 'unconfigured' : 'awaiting_provider_acceptance',
        // Intentionally not controlled by a WordPress option. This release cannot enable money movement.
        'live_payments_enabled' => false,
        'refunds_enabled' => false,
    );
}

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'eZAR merchant acceptance', 'eZAR merchant', 'manage_woocommerce', 'forus-ezar-merchant', function () {
        if (!current_user_can('manage_woocommerce')) return;
        $s = forus_ezar_acceptance_status();
        echo '<div class="wrap"><h1>FORUS eZAR merchant acceptance</h1>';
        echo '<p><strong>Receiving wallet verified; payment acceptance pending.</strong></p>';
        echo '<p>The existing wallet is the intended destination for this shop. This does not create a new financial account or change the wallet’s provider classification.</p><table class="widefat striped"><tbody>';
        foreach (array('merchant_name'=>'Shop merchant', 'owner_email'=>'Linked FORUS Connect email', 'account_id'=>'Existing eZAR account', 'wallet_address'=>'Receiving wallet', 'wallet_verified_at'=>'Verified with CloudGate', 'provider_wallet_category'=>'Provider classification') as $key=>$label) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($s[$key]) . '</td></tr>';
        }
        echo '</tbody></table><p>Live payments and refunds: <strong>disabled</strong>.</p>';
        echo '<p>Required before release: provider merchant eligibility, sandbox invoice and hosted checkout verification, authenticated amount/token/destination/status evidence, retry and refund acceptance tests.</p></div>';
    });
});

// Reserve a separate method ID so this integration can never reinterpret a simulated order as paid.
add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) return;
    class FORUS_Ezar_Acceptance_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'forus_ezar_openpay';
            $this->method_title = 'eZAR wallet acceptance — pending verification';
            $this->method_description = 'Existing wallet destination is recorded under WooCommerce → eZAR merchant. Live checkout is locked in this candidate release.';
            $this->title = 'eZAR wallet';
            $this->enabled = 'no';
            $this->supports = array('products');
        }
        public function is_available() { return false; }
        public function process_payment($order_id) {
            wc_add_notice('eZAR merchant acceptance is still being verified. No payment has been requested.', 'error');
            return array('result'=>'failure');
        }
        public function process_refund($order_id, $amount = null, $reason = '') {
            return new WP_Error('forus_ezar_refunds_disabled', 'eZAR merchant refunds are not enabled.');
        }
    }
    add_filter('woocommerce_payment_gateways', function ($gateways) { $gateways[] = 'FORUS_Ezar_Acceptance_Gateway'; return $gateways; });
}, 20);
