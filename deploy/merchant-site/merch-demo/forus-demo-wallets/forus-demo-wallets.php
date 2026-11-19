<?php
/**
 * Plugin Name: FORUS Demo Wallet Checkouts
 * Description: Isolated, explicitly simulated eZAR and WOW checkout for the FORUS merchandise demonstration. Never transfers money or marks an order paid.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

function forus_merch_demo_active() {
    return 'yes' === get_option('forus_merch_demo_enabled')
        && 'staging' === wp_get_environment_type()
        && 'https://shop.forus.digital' === untrailingslashit(get_option('siteurl'))
        && 'https://shop.forus.digital' === untrailingslashit(get_option('home'));
}
function forus_merch_demo_order($order) {
    return $order instanceof WC_Order && 'yes' === $order->get_meta('_forus_merch_demo');
}
add_action('before_woocommerce_init',function(){
    if(class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables',__FILE__,true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks',__FILE__,false);
    }
});
add_action('init',function(){
    register_post_status('wc-forus-demo',array('label'=>'Demo complete (unpaid)','public'=>true,'exclude_from_search'=>false,'show_in_admin_all_list'=>true,'show_in_admin_status_list'=>true,'label_count'=>_n_noop('Demo complete (unpaid) <span class="count">(%s)</span>','Demo complete (unpaid) <span class="count">(%s)</span>')));
});
add_filter('wc_order_statuses',function($statuses){$statuses['wc-forus-demo']='Demo complete (unpaid)';return $statuses;});

add_action('plugins_loaded',function(){
    if(!class_exists('WC_Payment_Gateway'))return;
    class FORUS_Demo_Wallet extends WC_Payment_Gateway {
        public function __construct($rail='ezar') {
            $rail=in_array($rail,array('ezar','wow'),true)?$rail:'ezar';
            $this->id='forus_demo_'.$rail;
            $label='ezar'===$rail?'eZAR':'WOW';
            $this->method_title=$label.' — simulated demo';
            $this->method_description='Demo only. No provider request, wallet debit, token transfer, settlement or refund is performed.';
            $this->title=$label.' · Demo';
            $this->description='Try the '.$label.' checkout experience. This is a simulation: no money or tokens move, and no goods are shipped.';
            $this->enabled='yes';
            $this->has_fields=false;
            $this->supports=array('products');
            $this->order_button_text='Complete demo order';
        }
        public function is_available(){return forus_merch_demo_active() && parent::is_available();}
        public function process_payment($order_id) {
            $order=wc_get_order($order_id);
            if(!forus_merch_demo_active() || !$order || $order->get_payment_method()!==$this->id || !forus_merch_demo_order($order) || 'ZAR'!==$order->get_currency()) {
                wc_add_notice('Demo checkout is unavailable for this order.','error'); return array('result'=>'failure');
            }
            if($order->has_status('forus-demo'))return array('result'=>'success','redirect'=>$this->get_return_url($order));
            if(!$order->has_status('pending') || $order->is_paid() || $order->get_transaction_id() || (float)$order->get_total()<=0) {
                wc_add_notice('This order cannot be completed as a demo.','error');return array('result'=>'failure');
            }
            foreach($order->get_items() as $item) {
                $product=$item->get_product();
                $owner=$product && $product->get_parent_id()?wc_get_product($product->get_parent_id()):$product;
                if(!$owner || 'yes'!==$owner->get_meta('_forus_merch_catalog')) {
                    wc_add_notice('Only FORUS demo merchandise can use this checkout.','error');return array('result'=>'failure');
                }
            }
            // Deliberately never call payment_complete(), reduce stock, or any provider API.
            $order->update_meta_data('_forus_demo_rail',str_replace('forus_demo_','',$this->id));
            $order->update_meta_data('_forus_demo_simulated','yes');
            $order->update_status('forus-demo','Simulated FORUS demo checkout completed. No payment, token transfer, stock movement or fulfilment.');
            $order->save();
            if(WC()->cart)WC()->cart->empty_cart();
            return array('result'=>'success','redirect'=>$this->get_return_url($order));
        }
    }
    class FORUS_Demo_Ezar extends FORUS_Demo_Wallet {public function __construct(){parent::__construct('ezar');}}
    class FORUS_Demo_Wow extends FORUS_Demo_Wallet {public function __construct(){parent::__construct('wow');}}
    add_filter('woocommerce_payment_gateways',function($gateways){$gateways[]='FORUS_Demo_Ezar';$gateways[]='FORUS_Demo_Wow';return $gateways;});
},20);
add_filter('woocommerce_available_payment_gateways',function($gateways){
    if(forus_merch_demo_active())return array_intersect_key($gateways,array_flip(array('forus_demo_ezar','forus_demo_wow')));
    unset($gateways['forus_demo_ezar'],$gateways['forus_demo_wow']);return $gateways;
},999);
add_filter('woocommerce_checkout_fields',function($fields){return forus_merch_demo_active()?array('billing'=>array(),'shipping'=>array(),'account'=>array(),'order'=>array()):$fields;},999);
add_filter('woocommerce_checkout_posted_data',function($data){
    if(!forus_merch_demo_active())return $data;
    foreach(array_keys($data) as $key)if(0===strpos($key,'billing_')||0===strpos($key,'shipping_'))$data[$key]='';
    return array_merge($data,array('billing_first_name'=>'Demo','billing_last_name'=>'Shopper','billing_email'=>'demo@example.test','billing_country'=>'ZA','createaccount'=>0));
},999);
add_action('woocommerce_checkout_create_order',function($order){
    if(forus_merch_demo_active()){$order->update_meta_data('_forus_merch_demo','yes');$order->set_customer_id(0);}
},10);
add_filter('woocommerce_checkout_registration_enabled',function($enabled){return forus_merch_demo_active()?false:$enabled;});
add_filter('woocommerce_checkout_registration_required',function($required){return forus_merch_demo_active()?false:$required;});
add_filter('woocommerce_enable_order_notes_field',function($enabled){return forus_merch_demo_active()?false:$enabled;});
add_filter('woocommerce_checkout_privacy_policy_text',function($text){return forus_merch_demo_active()?'This demo uses a fictional shopper. No wallet details or personal information are needed.':$text;});
add_filter('woocommerce_get_privacy_policy_text',function($text){return forus_merch_demo_active()?'Demo only. No real payment or delivery.':$text;});
add_action('woocommerce_before_checkout_form',function(){
    if(forus_merch_demo_active())echo '<div class="demo-checkout-notice"><strong>You’re trying a demo checkout.</strong><p>Choose eZAR or WOW below. We’ll use a fictional shopper: no personal details, wallet connection or payment required.</p></div>';
},5);
add_filter('woocommerce_order_button_text',function($text){return forus_merch_demo_active()?'Complete demo order':$text;});
add_filter('woocommerce_thankyou_order_received_text',function($text,$order){return forus_merch_demo_order($order)?'Your demo is complete. No payment was taken and nothing will be delivered.':$text;},10,2);
add_action('woocommerce_before_thankyou',function($order_id){
    $order=wc_get_order($order_id);
    if(forus_merch_demo_order($order)&&$order->has_status('forus-demo'))echo '<div class="demo-result"><h2>Demo complete. Nicely done.</h2><p>You explored '.esc_html('wow'===$order->get_meta('_forus_demo_rail')?'WOW':'eZAR').' checkout. No money or tokens moved. This order remains unpaid and will not be fulfilled.</p></div>';
});
foreach(array('new_order','cancelled_order','failed_order','customer_on_hold_order','customer_processing_order','customer_completed_order','customer_refunded_order','customer_invoice','customer_note') as $email_id) {
    add_filter('woocommerce_email_enabled_'.$email_id,function($enabled,$order){return forus_merch_demo_order($order)?false:$enabled;},999,2);
}
add_filter('woocommerce_valid_order_statuses_for_payment',function($statuses,$order){return forus_merch_demo_order($order)&&$order->has_status('forus-demo')?array():$statuses;},10,2);
add_filter('woocommerce_valid_order_statuses_for_payment_complete',function($statuses,$order){return forus_merch_demo_order($order)?array():$statuses;},10,2);
