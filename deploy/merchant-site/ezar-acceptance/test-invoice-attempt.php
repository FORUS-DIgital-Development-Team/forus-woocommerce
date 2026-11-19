<?php
// Standalone adversarial tests of durable attempt handling. No network or real orders.
define('ABSPATH', __DIR__);
require __DIR__ . '/openpay-client.php';
require __DIR__ . '/invoice-attempt.php';
$passed = 0; $options = array('siteurl' => 'https://shop.forus.digital'); $environment = 'staging'; $write_failure = false;
function check($ok, $label) { global $passed; if (!$ok) throw new Exception('FAIL: ' . $label); $passed++; }
function rejects($fn, $label) { $thrown = false; try { $fn(); } catch (Throwable $e) { $thrown = true; } check($thrown, $label); }
function is_wp_error($r) { return $r instanceof Exception; }
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function get_option($name, $default = false) { global $options; return $options[$name] ?? $default; }
function maybe_serialize($value) { return serialize($value); }
function maybe_unserialize($value) { return unserialize($value); }
function wp_cache_delete($name, $group) {}
final class Test_Database {
    public $options = 'wp_options'; public $last_error = ''; public $before_insert = null;
    function prepare($sql, ...$args) { return array($sql, $args); }
    function get_var($query) { global $options; $name = $query[1][0]; return isset($options[$name]) ? serialize($options[$name]) : null; }
    function query($query) {
        global $options, $write_failure;
        if ($write_failure) return false;
        if ($this->before_insert) { $hook = $this->before_insert; $this->before_insert = null; $hook(); }
        list($name, $value) = $query[1];
        if (array_key_exists($name, $options)) return 0;
        check(strpos($query[0], 'INSERT IGNORE') === 0 && strpos($query[0], "'no'") !== false, 'Atomic non-autoloaded reservation');
        $options[$name] = unserialize($value); return 1;
    }
    function update($table, $data, $where, $formats, $where_formats) {
        global $options, $write_failure;
        if ($write_failure) return false;
        $options[$where['option_name']] = unserialize($data['option_value']); return 1;
    }
}
$wpdb = new Test_Database();
function wp_get_environment_type() { global $environment; return $environment; }
function wp_generate_uuid4() { return '44444444-4444-4444-4444-444444444444'; }
final class Test_Order {
    public $method = 'forus_ezar_openpay'; public $status = 'pending'; public $currency = 'ZAR'; public $total = '149.99'; public $paid = false;
    function get_payment_method() { return $this->method; } function get_status() { return $this->status; }
    function get_currency() { return $this->currency; } function get_total() { return $this->total; } function is_paid() { return $this->paid; }
}
$order = new Test_Order();
function wc_get_order($id) { global $order; return $order; }
$config = array('environment' => 'sbx', 'api_key' => 'test-key', 'api_secret' => 'test-secret',
    'account_id' => '11111111-1111-1111-1111-111111111111', 'token_id' => '22222222-2222-2222-2222-222222222222');
$calls = 0;
$service = new FORUS_Ezar_Invoice_Attempt($config, function() use (&$calls, &$service) {
    $calls++;
    check(get_option('forus_ezar_attempt_1')['state'] === 'outcome_unknown', 'Reservation survives before provider request');
    check($service->create(1)['state'] === 'outcome_unknown', 'Concurrent attempt sees reservation without another POST');
    return array('response' => array('code' => 200), 'body' => '{"success":true,"result":{"id":"33333333-3333-3333-3333-333333333333"}}');
});
$result = $service->create(1);
check($result['state'] === 'response_received' && $result['invoice_id'] !== null, 'Response handle retained without claiming settlement');
check($service->create(1) === $result && $calls === 1, 'Repeated checkout reuses durable attempt');
$order->total = '150.00'; rejects(function() use ($service) { $service->create(1); }, 'Changed amount cannot reuse invoice'); $order->total = '149.99';
$changed = $config; $changed['account_id'] = '55555555-5555-5555-5555-555555555555';
rejects(function() use ($changed) { (new FORUS_Ezar_Invoice_Attempt($changed))->create(1); }, 'Changed receiving wallet rejected before I/O');
$timeout_calls = 0;
$timeout = new FORUS_Ezar_Invoice_Attempt($config, function() use (&$timeout_calls) { $timeout_calls++; return new Exception('secret provider diagnostic'); });
rejects(function() use ($timeout) { $timeout->create(2); }, 'Timeout requires reconciliation');
check($timeout->create(2)['state'] === 'outcome_unknown' && $timeout_calls === 1, 'Timeout never permits a new create');
check(strpos(json_encode($options), 'secret provider diagnostic') === false && strpos(json_encode($options), 'test-secret') === false, 'No error diagnostics or credentials persisted');
$write_failure = true; rejects(function() use ($timeout) { $timeout->create(3); }, 'Failed reservation prevents I/O');
check($timeout_calls === 1, 'No provider call without durable reservation'); $write_failure = false;
$lost_save_calls = 0;
$lost_save = new FORUS_Ezar_Invoice_Attempt($config, function() use (&$lost_save_calls, &$write_failure) {
    $lost_save_calls++; $write_failure = true;
    return array('response' => array('code' => 200), 'body' => '{"success":true,"result":{"id":"33333333-3333-3333-3333-333333333333"}}');
});
rejects(function() use ($lost_save) { $lost_save->create(4); }, 'Failed evidence persistence stays uncertain'); $write_failure = false;
check($lost_save->create(4)['state'] === 'outcome_unknown' && $lost_save_calls === 1, 'Persistence failure cannot duplicate an invoice');
$options['forus_ezar_attempt_5'] = 'corrupt'; rejects(function() use ($service) { $service->create(5); }, 'Malformed reservation fails closed');
$race_calls = 0;
$race = new FORUS_Ezar_Invoice_Attempt($config, function() use (&$race_calls) {
    $race_calls++;
    return array('response' => array('code' => 200), 'body' => '{"success":true,"result":{"id":"33333333-3333-3333-3333-333333333333"}}');
});
$wpdb->before_insert = function() use ($race) { $race->create(7); };
check($race->create(7)['state'] === 'response_received' && $race_calls === 1, 'Competing worker between initial read and insert wins without a duplicate POST');
$wpdb->last_error = 'database unavailable';
rejects(function() use ($race) { $race->create(8); }, 'Read failure cannot be mistaken for missing reservation'); $wpdb->last_error = '';
foreach (array('forus_demo_ezar', 'forus_demo_wow', 'forus') as $method) {
    $order->method = $method; rejects(function() use ($service) { $service->create(6); }, 'Other gateways and demo orders rejected');
}
$order->method = 'forus_ezar_openpay'; $order->paid = true; rejects(function() use ($service) { $service->create(6); }, 'Paid order rejected'); $order->paid = false;
$order->currency = 'USD'; rejects(function() use ($service) { $service->create(6); }, 'Wrong currency rejected'); $order->currency = 'ZAR';
$environment = 'production'; rejects(function() use ($service) { $service->create(6); }, 'Production site rejected'); $environment = 'staging';
$options['siteurl'] = 'https://merchant.forus.digital'; rejects(function() use ($service) { $service->create(6); }, 'Other site rejected');
check($calls === 1 && !isset($options['forus_ezar_attempt_6']) && !$order->paid, 'Validation failures never request payment or settle an order');
echo "PASS: $passed invoice reservation safety checks; no provider calls.\n";
