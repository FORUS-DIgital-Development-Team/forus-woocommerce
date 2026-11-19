<?php
// Explicit WP-CLI check with a disposable unpaid order and synthetic transport only.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
global $wpdb;
if (wp_get_environment_type() !== 'staging' || get_option('siteurl') !== 'https://shop.forus.digital') WP_CLI::error('Unexpected test target.');
if (!class_exists('FORUS_Ezar_OpenPay_Client')) require_once __DIR__ . '/openpay-client.php';
if (!class_exists('FORUS_Ezar_Invoice_Attempt')) require_once __DIR__ . '/invoice-attempt.php';
$config = array('environment' => 'sbx', 'api_key' => 'fixture-key', 'api_secret' => 'fixture-secret',
    'account_id' => '11111111-1111-1111-1111-111111111111', 'token_id' => '22222222-2222-2222-2222-222222222222');
$order = wc_create_order(array('status' => 'pending', 'created_via' => 'forus-ezar-reservation-test'));
if (is_wp_error($order)) WP_CLI::error('Could not create disposable fixture.');
$order->set_payment_method('forus_ezar_openpay'); $order->set_currency('ZAR'); $order->set_total('1.00');
$order->update_meta_data('_forus_ezar_disposable_test', 'yes'); $order->save();
$id = $order->get_id(); $name = FORUS_Ezar_Invoice_Attempt::option_name($id); $calls = 0; $second_calls = 0;
try {
    $service = new FORUS_Ezar_Invoice_Attempt($config, function() use ($id, $name, $config, &$calls, &$second_calls) {
        global $wpdb;
        $calls++;
        $original = $wpdb;
        $second = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $second->set_prefix($original->prefix);
        try {
            $wpdb = $second;
            $competing = new FORUS_Ezar_Invoice_Attempt($config, function() use (&$second_calls) { $second_calls++; throw new Exception('Unexpected second request.'); });
            $seen = $competing->create($id);
            if ($seen['state'] !== 'outcome_unknown' || $second_calls !== 0) throw new Exception('Reservation not visible to a second database connection.');
            $collision = $second->query($second->prepare("INSERT IGNORE INTO {$second->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, 'replacement-must-not-win'));
            if ($collision !== 0 || $competing->create($id) !== $seen) throw new Exception('Duplicate insert overwrote the durable reservation.');
        } finally { $wpdb = $original; $second->close(); }
        return array('response' => array('code' => 200), 'body' => '{"success":true,"result":{"id":"33333333-3333-3333-3333-333333333333"}}');
    });
    $first = $service->create($id);
    if ($first['state'] !== 'response_received' || $service->create($id) !== $first || $calls !== 1 || $second_calls !== 0) throw new Exception('Duplicate request protection failed.');
    $saved = wc_get_order($id);
    if ($saved->is_paid() || $saved->get_transaction_id() || $saved->get_date_paid()) throw new Exception('Fixture was incorrectly settled.');
    $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name));
    if ($autoload !== 'no') throw new Exception('Reservation is autoloaded.');
} finally {
    // Remove only this test's fixture. Real attempt reservations have no reset path.
    $wpdb->delete($wpdb->options, array('option_name' => $name), array('%s'));
    wp_cache_delete($name, 'options'); wp_cache_delete('notoptions', 'options');
    $order->delete(true);
}
WP_CLI::success('Real HPOS order and two database connections: one synthetic request, durable reservation, duplicate insert rejected, order unpaid, disposable fixture removed. No provider calls.');
