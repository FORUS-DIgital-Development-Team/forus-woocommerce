<?php
if (!defined('WP_CLI') || !WP_CLI) exit(1);
$status=forus_ezar_acceptance_status();
if (!$status['account_id'] || $status['live_payments_enabled'] || $status['refunds_enabled'] || $status['state']!=='awaiting_provider_acceptance') WP_CLI::error('Unsafe merchant status.');
$gateway=new FORUS_Ezar_Acceptance_Gateway();
$gateway->enabled='yes';
if ($gateway->is_available()) WP_CLI::error('Candidate can be enabled unexpectedly.');
if (!is_wp_error($gateway->process_refund(16,1))) WP_CLI::error('Refund did not fail closed.');
if ((get_option('woocommerce_forus_settings')['enabled']??'')!=='no') WP_CLI::error('FORUS live gateway changed.');
$available=array_keys(WC()->payment_gateways()->get_available_payment_gateways());sort($available);
if ($available!==array('forus_demo_ezar','forus_demo_wow')) WP_CLI::error('Public demo gateways changed.');
if (count(wc_get_products(array('limit'=>-1,'meta_key'=>'_forus_merch_catalog','meta_value'=>'yes')))!==6) WP_CLI::error('Catalogue changed.');
$old=wc_get_order(16);
if (!$old || $old->is_paid() || $old->get_transaction_id()) WP_CLI::error('Unresolved historic order changed.');
WP_CLI::success('Merchant destination recorded; candidate and real gateway disabled; refunds disabled; six products and two simulated checkouts preserved; historic order unpaid.');
