<?php
if (!defined('WP_CLI') || !WP_CLI) exit(1);
if (get_option('home')!=='https://shop.forus.digital' || get_option('siteurl')!=='https://shop.forus.digital' || wp_get_environment_type()!=='staging') WP_CLI::error('Unexpected target.');
if ((get_option('woocommerce_forus_settings')['enabled']??'')!=='no') WP_CLI::error('Real gateway must remain disabled.');
$binding=json_decode(file_get_contents('/ezar-binding/merchant.json'),true);
foreach(array('merchant_name','owner_email','account_id','wallet_address','wallet_verified_at','provider_wallet_category') as $field) if(empty($binding[$field]) || !is_string($binding[$field])) WP_CLI::error('Incomplete verified binding.');
if (!is_email($binding['owner_email']) || !preg_match('/^[a-f0-9-]{36}$/iD',$binding['account_id']) || !preg_match('/^0x[a-f0-9]{40}$/iD',$binding['wallet_address'])) WP_CLI::error('Invalid wallet binding.');
$old=get_option('forus_ezar_merchant_binding',array());
if ($old && ($old['account_id']!==$binding['account_id'] || $old['owner_email']!==$binding['owner_email'])) WP_CLI::error('Existing destination differs; nothing changed.');
$binding['state']='awaiting_provider_acceptance';
$binding['live_payments_enabled']=false;
$binding['refunds_enabled']=false;
update_option('forus_ezar_merchant_binding',$binding,false);
WP_CLI::success('Existing eZAR receiving wallet recorded. No CloudGate account was created or upgraded. Payments remain disabled.');
