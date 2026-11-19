<?php
if(!defined('WP_CLI')||!WP_CLI||!function_exists('forus_merch_demo_active')||!forus_merch_demo_active())exit(1);
function merch_assert($test,$message){if(!$test)WP_CLI::error($message);WP_CLI::success($message);}
merch_assert(get_option('woocommerce_forus_settings')['enabled']==='no','Real FORUS gateway remains disabled');
merch_assert(!defined('FORUS_WC_ENABLE_TEST_REFUNDS')||FORUS_WC_ENABLE_TEST_REFUNDS!==true,'Provider refunds remain disabled');
merch_assert(Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),'HPOS remains enabled');
$products=wc_get_products(array('limit'=>-1,'meta_key'=>'_forus_merch_catalog','meta_value'=>'yes','type'=>array('simple','variable')));
merch_assert(count($products)===6,'Exactly six managed merchandise products');
$images=true;$variations=0;
foreach($products as $product){$images=$images&&(bool)wp_get_attachment_url($product->get_image_id());if($product->is_type('variable'))$variations+=count($product->get_children());}
merch_assert($images&&$variations===10,'All six product images and ten clothing size variations present');
$gateways=WC()->payment_gateways()->payment_gateways();
$available=WC()->payment_gateways()->get_available_payment_gateways();
merch_assert(array_keys($available)===array('forus_demo_ezar','forus_demo_wow'),'Only eZAR and WOW simulation gateways are available');
// Any attempted provider call or transactional email makes this verification fail.
$http_calls=0;$mail_calls=0;
$block_http=function($pre)use(&$http_calls){$http_calls++;return new WP_Error('merch_test_http_blocked','No HTTP allowed in simulation');};
$block_mail=function($pre)use(&$mail_calls){$mail_calls++;return false;};
add_filter('pre_http_request',$block_http,999,3);add_filter('pre_wp_mail',$block_mail,999,2);
$order_ids=array();
foreach(array('forus_demo_ezar','forus_demo_wow') as $gateway_id){
    $order=wc_create_order(array('customer_id'=>0));$order->set_created_via('forus-merch-verification');$order->set_currency('ZAR');$order->set_payment_method($gateway_id);$order->set_billing_first_name('Demo');$order->set_billing_last_name('Verification');$order->set_billing_email('demo@example.test');
    $order->update_meta_data('_forus_merch_demo','yes');$order->add_product(wc_get_product(wc_get_product_id_by_sku('FORUS-MUG-001')),1);$order->calculate_totals();$order->save();
    $gateway=$gateways[$gateway_id];$result=$gateway->process_payment($order->get_id());$saved=wc_get_order($order->get_id());
    merch_assert($result['result']==='success'&&$saved->has_status('forus-demo')&&!$saved->is_paid()&&!$saved->get_date_paid()&&!$saved->get_transaction_id(),$gateway_id.' completes as unpaid simulation without transaction evidence');
    $notes=count(wc_get_order_notes(array('order_id'=>$order->get_id())));$again=$gateway->process_payment($order->get_id());
    merch_assert($again['redirect']===$result['redirect']&&count(wc_get_order_notes(array('order_id'=>$order->get_id())))===$notes,$gateway_id.' repeated completion reuses the order without new effects');
    merch_assert(!$gateway->supports('refunds')&&!$saved->needs_payment(),$gateway_id.' does not expose refunds or a second payment');
    merch_assert(apply_filters('woocommerce_email_enabled_new_order',true,$saved)===false,'Demo order email is suppressed');
    $order_ids[]=$order->get_id();
}
$old=wc_get_order(16);$before=$old->get_status();$rejected=$gateways['forus_demo_ezar']->process_payment(16);
merch_assert($rejected['result']==='failure'&&wc_get_order(16)->get_status()===$before&&!wc_get_order(16)->is_paid(),'Original Ecentric order cannot be converted to a demo payment');
wc_clear_notices();
$disable=function(){return 'no';};add_filter('pre_option_forus_merch_demo_enabled',$disable);
merch_assert(!$gateways['forus_demo_ezar']->is_available()&&!$gateways['forus_demo_wow']->is_available(),'Both gateways fail closed when demo mode is disabled');remove_filter('pre_option_forus_merch_demo_enabled',$disable);
$wrong_host=function(){return 'https://unapproved.example';};add_filter('pre_option_home',$wrong_host);
merch_assert(!forus_merch_demo_active(),'Demo checkout fails closed on another hostname');remove_filter('pre_option_home',$wrong_host);
merch_assert($http_calls===0&&$mail_calls===0,'Simulation made no HTTP provider call or email attempt');
remove_filter('pre_http_request',$block_http,999);remove_filter('pre_wp_mail',$block_mail,999);
echo wp_json_encode(array('verificationOrders'=>$order_ids,'retainedOrder'=>16,'retainedOrderStatus'=>$before,'livePayments'=>false,'providerRefunds'=>false,'providerRequests'=>$http_calls,'emailAttempts'=>$mail_calls))."\n";
