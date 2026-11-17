<?php
/** Explicit content refresh for the dedicated deployed shop; preserves products/orders. */
if (!defined('WP_CLI') || !WP_CLI || get_option('siteurl') !== 'https://shop.forus.digital') exit(1);
$home = (int) get_option('page_on_front');
if (!$home || !get_option('forus_demo_seeded')) WP_CLI::error('Dedicated demo was not seeded');
$content = '<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size">Explore the FORUS checkout experience.</p><!-- /wp:paragraph -->'
    . '<!-- wp:paragraph --><p>Browse the sample item, add it to your basket and explore our WooCommerce demonstration store.</p><!-- /wp:paragraph -->'
    . '<!-- wp:paragraph --><p><strong>Demonstration only. No real purchases or live payments.</strong> Payments are currently paused while sandbox validation is completed.</p><!-- /wp:paragraph -->'
    . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"#f47721"}}} --><div class="wp-block-button"><a class="wp-block-button__link has-background wp-element-button" style="background-color:#f47721" href="' . esc_url(wc_get_page_permalink('shop')) . '">Explore the demo shop</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
    . '<!-- wp:paragraph --><p>Looking for the merchant portal? <a href="https://merchant.icba.forus.coop/">Open the ICBA merchant portal</a>.</p><!-- /wp:paragraph -->';
wp_update_post(array('ID'=>$home,'post_content'=>$content));
$nav = '<!-- wp:navigation {"overlayMenu":"mobile","layout":{"type":"flex","justifyContent":"right"}} -->';
foreach (array('Shop'=>'shop','Cart'=>'cart','Checkout'=>'checkout') as $label=>$page) {
    $attrs = wp_json_encode(array('label'=>$label,'url'=>wc_get_page_permalink($page),'kind'=>'custom'));
    $nav .= '<!-- wp:navigation-link ' . $attrs . ' /-->';
}
$nav .= '<!-- /wp:navigation -->';
$parts = array(
    'header'=>'<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"32px","bottom":"32px"}}},"layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} --><div class="wp-block-group alignwide" style="padding-top:32px;padding-bottom:32px"><!-- wp:site-title {"level":0,"fontSize":"large"} /-->' . $nav . '</div><!-- /wp:group -->',
    'footer'=>'<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"48px","bottom":"32px"}}},"layout":{"type":"constrained"}} --><div class="wp-block-group alignwide" style="padding-top:48px;padding-bottom:32px"><!-- wp:paragraph --><p>FORUS · Demonstration store · ZAR<br>No real purchases or live payments.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
);
foreach ($parts as $slug=>$html) {
    $existing=get_posts(array('post_type'=>'wp_template_part','name'=>$slug,'posts_per_page'=>1,'tax_query'=>array(array('taxonomy'=>'wp_theme','field'=>'slug','terms'=>get_stylesheet()))));
    $id=wp_insert_post(array('ID'=>$existing?$existing[0]->ID:0,'post_name'=>$slug,'post_title'=>ucfirst($slug),'post_type'=>'wp_template_part','post_status'=>'publish','post_content'=>$html),true);
    if (is_wp_error($id)) WP_CLI::error('Template update failed');
    wp_set_object_terms($id,get_stylesheet(),'wp_theme');
    wp_set_object_terms($id,$slug,'wp_template_part_area');
}
WP_CLI::success('Demo navigation and current payment status refreshed; orders preserved.');
