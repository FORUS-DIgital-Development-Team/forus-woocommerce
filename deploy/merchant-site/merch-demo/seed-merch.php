<?php
/** Explicit, repeatable catalogue update for the dedicated demonstration host. */
if(!defined('WP_CLI')||!WP_CLI||!class_exists('WooCommerce')||'https://shop.forus.digital'!==untrailingslashit(get_option('siteurl'))||'staging'!==wp_get_environment_type())exit(1);
if(!get_option('forus_demo_seeded'))WP_CLI::error('Expected existing dedicated demonstration store.');
$real=get_option('woocommerce_forus_settings',array());
if(($real['enabled']??'')!=='no')WP_CLI::error('Real FORUS gateway must remain disabled.');
if(!get_option('forus_merch_original_state')) {
    $saved=array('stylesheet'=>get_stylesheet());
    foreach(array('blogname','blogdescription','woocommerce_demo_store_notice','woocommerce_calc_taxes','woocommerce_enable_signup_and_login_from_checkout','woocommerce_enable_myaccount_registration','forus_merch_demo_enabled') as $key)$saved[$key]=get_option($key);
    foreach(array('cart','checkout') as $name)$saved[$name.'_content']=get_post_field('post_content',wc_get_page_id($name));
    update_option('forus_merch_original_state',$saved,false);
}
require_once ABSPATH.'wp-admin/includes/image.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/media.php';
$cats=array();
foreach(array('clothing'=>'Clothing','everyday'=>'Everyday essentials','accessories'=>'Accessories') as $slug=>$name){
    $term=get_term_by('slug',$slug,'product_cat');
    if(!$term){$new=wp_insert_term($name,'product_cat',array('slug'=>$slug));if(is_wp_error($new))WP_CLI::error($new->get_error_message());$cats[$slug]=(int)$new['term_id'];}else{$cats[$slug]=(int)$term->term_id;}
}
$catalog=array(
    array('sku'=>'FORUS-TEE-001','name'=>'The Everyday Tee','price'=>'249.00','image'=>'tee','category'=>'clothing','variable'=>true,'short'=>'A clean white tee. A shared identity. The FORUS wordmark, front and centre.','details'=>'Concept design: white crewneck with a navy FORUS wordmark and orange accent. Choose your demo size from S to 2XL.'),
    array('sku'=>'FORUS-HOODIE-001','name'=>'The Together Hoodie','price'=>'649.00','image'=>'hoodie','category'=>'clothing','variable'=>true,'short'=>'Your easy layer for cooler days. Deep navy, a relaxed silhouette and a little FORUS spirit.','details'=>'Concept design: navy pullover hoodie with drawstrings, front pocket and a white FORUS wordmark. Demo sizes S to 2XL.'),
    array('sku'=>'FORUS-MUG-001','name'=>'The Daily Ritual Mug','price'=>'149.00','image'=>'mug','category'=>'everyday','variable'=>false,'short'=>'Good conversations start here. A classic white mug with a navy interior and our signature wordmark.','details'=>'Concept design: white ceramic-style mug with a navy interior, generous handle and FORUS branding. One demo size.'),
    array('sku'=>'FORUS-CAP-001','name'=>'The Community Cap','price'=>'199.00','image'=>'cap','category'=>'accessories','variable'=>false,'short'=>'For the days out and the way home. A navy cap with a subtle embroidered FORUS wordmark.','details'=>'Concept design: navy six-panel cap with a curved brim and adjustable back. One demo size.'),
    array('sku'=>'FORUS-TOTE-001','name'=>'The Carry It Together Tote','price'=>'179.00','image'=>'tote','category'=>'accessories','variable'=>false,'short'=>'A little room for your everyday. Natural canvas tones, long handles and a bold FORUS print.','details'=>'Concept design: natural canvas-style tote with long handles and a navy FORUS wordmark. One demo size.'),
    array('sku'=>'FORUS-BOTTLE-001','name'=>'The On The Go Bottle','price'=>'299.00','image'=>'bottle','category'=>'everyday','variable'=>false,'short'=>'Keep FORUS close wherever the day takes you. A minimal navy bottle with a practical carry loop.','details'=>'Concept design: matte navy metal-style bottle with screw lid, carry loop and a white FORUS wordmark. One demo size.'),
);
$manifest=array();
foreach($catalog as $position=>$item){
    $id=wc_get_product_id_by_sku($item['sku']);
    $product=$id?wc_get_product($id):($item['variable']?new WC_Product_Variable():new WC_Product_Simple());
    if($id&&'yes'!==$product->get_meta('_forus_merch_catalog'))WP_CLI::error('SKU belongs to an unmanaged product.');
    $product->set_name($item['name']);$product->set_slug(sanitize_title($item['name']));$product->set_sku($item['sku']);$product->set_status('publish');$product->set_catalog_visibility('visible');
    $product->set_description('<p>'.esc_html($item['details']).'</p><p><strong>Demonstration merchandise.</strong> This product, imagery and price are concepts for the FORUS demo shop. Nothing is purchased, charged or shipped.</p>');
    $product->set_short_description($item['short']);$product->set_category_ids(array($cats[$item['category']]));$product->set_menu_order($position+1);$product->set_featured(true);
    $product->set_virtual(true);$product->set_manage_stock(false);$product->set_stock_status('instock');$product->set_tax_status('none');$product->set_reviews_allowed(false);$product->update_meta_data('_forus_merch_catalog','yes');
    if($item['variable']){
        $attribute=new WC_Product_Attribute();$attribute->set_name('Size');$attribute->set_options(array('S','M','L','XL','2XL'));$attribute->set_visible(true);$attribute->set_variation(true);$product->set_attributes(array($attribute));
    }else{$product->set_regular_price($item['price']);}
    $id=$product->save();
    $attachment=(int)$product->get_meta('_forus_merch_attachment');
    if(!$attachment||!get_post($attachment)){
        $source=__DIR__.'/images/'.$item['image'].'.jpg';
        if(!is_file($source))WP_CLI::error('Missing product image: '.$item['image']);
        $tmp=wp_tempnam($source);if(!copy($source,$tmp))WP_CLI::error('Image copy failed');
        $attachment=media_handle_sideload(array('name'=>'forus-'.$item['image'].'.jpg','tmp_name'=>$tmp),$id,'FORUS '.$item['name'].' — product concept');
        if(is_wp_error($attachment))WP_CLI::error($attachment->get_error_message());
        update_post_meta($attachment,'_wp_attachment_image_alt','FORUS '.$item['name'].' merchandise concept');
        $product->update_meta_data('_forus_merch_attachment',$attachment);
    }
    $product->set_image_id($attachment);$product->save();
    if($item['variable']){
        foreach(array('S','M','L','XL','2XL') as $size){
            $variation_sku=$item['sku'].'-'.$size;$variation_id=wc_get_product_id_by_sku($variation_sku);
            $variation=$variation_id?wc_get_product($variation_id):new WC_Product_Variation();
            if($variation_id&&$variation->get_parent_id()!==$id)WP_CLI::error('Variation belongs to another parent.');
            $variation->set_parent_id($id);$variation->set_sku($variation_sku);$variation->set_attributes(array('size'=>$size));$variation->set_regular_price($item['price']);$variation->set_virtual(true);$variation->set_manage_stock(false);$variation->set_stock_status('instock');$variation->set_tax_status('none');$variation->set_status('publish');$variation->save();
        }
        WC_Product_Variable::sync($id);
    }
    wc_delete_product_transients($id);$manifest[]=array('id'=>$id,'sku'=>$item['sku'],'price'=>$item['price'],'imageId'=>$attachment);
}
$sample_id=wc_get_product_id_by_sku('FORUS-DEMO-001');
if($sample_id){$sample=wc_get_product($sample_id);$sample->set_catalog_visibility('hidden');$sample->save();}
update_option('blogname','FORUS Store');update_option('blogdescription','Good things. For all of us.');
update_option('woocommerce_demo_store_notice','FORUS demo store. Simulated eZAR and WOW checkouts. No real payments or deliveries.');
update_option('woocommerce_calc_taxes','no');update_option('woocommerce_enable_signup_and_login_from_checkout','no');update_option('woocommerce_enable_myaccount_registration','no');
wp_update_post(array('ID'=>wc_get_page_id('cart'),'post_content'=>'[woocommerce_cart]'));
wp_update_post(array('ID'=>wc_get_page_id('checkout'),'post_content'=>'[woocommerce_checkout]'));
$about=get_page_by_path('about-the-demo');
$about_id=wp_insert_post(array('ID'=>$about?$about->ID:0,'post_type'=>'page','post_title'=>'Welcome to the FORUS demo store','post_name'=>'about-the-demo','post_status'=>'publish','post_content'=>'<div class="faq"><p>Explore a FORUS merchandise collection and try a checkout from start to finish.</p><h2>How does the demo work?</h2><p>Choose your goods, select a size where available, and add them to your bag. At checkout, choose eZAR or WOW, then select “Complete demo order”. You do not need a wallet, card or personal details.</p><h2>Will I be charged?</h2><p>No. Both checkout methods are simulations. No eZAR, WOW, money or tokens are transferred. Orders are labelled “Demo complete (unpaid)” and never represent a successful provider payment.</p><h2>Will my order be delivered?</h2><p>No. Products, prices and images are demonstration concepts. Nothing is sold or shipped, and no confirmation email is sent.</p><h2>Are these connected to a provider wallet?</h2><p>These public demo checkouts do not connect to eZAR or WOW accounts. Provider sandbox acceptance and real payments are separate work; live payments remain disabled.</p><h2>What information do I need to enter?</h2><p>None. Checkout uses a fictional Demo Shopper. Your basket and a demo order record are stored to demonstrate the journey.</p><h2>Where is the merchant portal?</h2><p><a href="https://merchant.icba.forus.coop/">Open the existing ICBA merchant portal ↗</a></p></div>'),true);
if(is_wp_error($about_id))WP_CLI::error('About page failed.');
update_option('forus_merch_demo_enabled','yes');
switch_theme('forus-store');flush_rewrite_rules(false);
WP_CLI::success('Six merchandise concepts, ten size variations, theme and simulated checkouts configured. Existing payment settings and order evidence preserved.');
echo wp_json_encode($manifest,JSON_PRETTY_PRINT)."\n";
