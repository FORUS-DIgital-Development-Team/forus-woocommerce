<?php
defined('ABSPATH') || exit;

/** A permanent, atomic reservation: a lost response must never create a second invoice. */
final class FORUS_Ezar_Invoice_Attempt {
    private $client;
    private $account_id;
    private $token_id;

    public function __construct($config, $transport = null) {
        $this->client = new FORUS_Ezar_OpenPay_Client($config, $transport);
        $this->account_id = strtolower($config['account_id']);
        $this->token_id = strtolower($config['token_id']);
    }

    public static function option_name($order_id) {
        if (!is_int($order_id) || $order_id <= 0) throw new InvalidArgumentException('Invalid order ID.');
        return 'forus_ezar_attempt_' . $order_id;
    }

    /** Internal sandbox service only; no public route or checkout activation is registered. */
    public function create($order_id) {
        $name = self::option_name($order_id);
        if (wp_get_environment_type() !== 'staging' || get_option('siteurl') !== 'https://shop.forus.digital') {
            throw new RuntimeException('Invoice tests are restricted to the staging shop.');
        }
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'forus_ezar_openpay' || $order->is_paid()
            || !in_array($order->get_status(), array('pending', 'failed'), true) || $order->get_currency() !== 'ZAR') {
            throw new RuntimeException('Order is not eligible for a sandbox eZAR invoice.');
        }
        $amount = (string) $order->get_total();
        $snapshot = array('environment' => 'sbx', 'order_id' => $order_id, 'account_id' => $this->account_id,
            'token_id' => $this->token_id, 'currency' => 'ZAR', 'amount_minor' => FORUS_Ezar_OpenPay_Client::amount_minor($amount));
        $existing = $this->read($name);
        if ($existing !== null) return $this->existing($existing, $snapshot);

        $attempt = array('version' => 1, 'snapshot' => $snapshot, 'reference' => 'FORUS-' . $order_id . '-' . wp_generate_uuid4(),
            'state' => 'outcome_unknown', 'created_at' => gmdate('c'), 'invoice_id' => null);
        // wp_options has a UNIQUE option_name index. Insert BEFORE any provider I/O.
        // Do not use add_option: its duplicate-key update can overwrite a competing reservation.
        // No expiring lease or deletion path: neither a crash nor a concurrent worker may retry POST.
        global $wpdb;
        $reserved = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, maybe_serialize($attempt)));
        $this->clear_cache($name);
        if ($reserved !== 1) {
            return $this->existing($this->read($name), $snapshot);
        }
        try {
            $invoice = $this->client->create_invoice($amount, $attempt['reference']);
            $invoice_id = $invoice['id'] ?? null;
            if (!is_string($invoice_id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $invoice_id)) {
                throw new RuntimeException('Unverified invoice response.');
            }
            // An ID is a reconciliation handle, not proof of an eligible merchant or a paid order.
            $attempt['invoice_id'] = strtolower($invoice_id);
            $attempt['state'] = 'response_received';
            if ($wpdb->update($wpdb->options, array('option_value' => maybe_serialize($attempt), 'autoload' => 'no'), array('option_name' => $name), array('%s', '%s'), array('%s')) !== 1) {
                throw new RuntimeException('Invoice evidence could not be saved.');
            }
            $this->clear_cache($name);
        } catch (Throwable $error) {
            // Never persist provider response bodies, exception text or credentials.
            throw new RuntimeException('Invoice outcome requires reconciliation. Creation will not be retried.');
        }
        return $attempt;
    }

    private function read($name) {
        global $wpdb;
        // Bypass per-request and persistent option caches when deciding whether a POST is safe.
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($wpdb->last_error) throw new RuntimeException('Invoice reservation could not be read.');
        return $value === null ? null : maybe_unserialize($value);
    }

    private function clear_cache($name) {
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private function existing($attempt, $snapshot) {
        if (!is_array($attempt) || ($attempt['version'] ?? null) !== 1 || ($attempt['snapshot'] ?? null) !== $snapshot
            || !in_array($attempt['state'] ?? null, array('outcome_unknown', 'response_received'), true)) {
            throw new RuntimeException('Invoice reservation is unavailable or the order binding changed.');
        }
        return $attempt;
    }
}
