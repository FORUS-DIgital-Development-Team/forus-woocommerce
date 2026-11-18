<?php
defined('ABSPATH') || exit;
add_action('after_setup_theme', function () {
    add_theme_support('title-tag'); add_theme_support('post-thumbnails');
    add_theme_support('woocommerce', array('thumbnail_image_width'=>600,'single_image_width'=>900));
    add_theme_support('wc-product-gallery-zoom'); add_theme_support('wc-product-gallery-lightbox'); add_theme_support('wc-product-gallery-slider');
});
add_action('wp_enqueue_scripts', function () { wp_enqueue_style('forus-store', get_stylesheet_uri(), array(), '1.0.0'); wp_enqueue_script('wc-cart-fragments'); });
add_filter('woocommerce_add_to_cart_fragments',function($fragments){$fragments['.cart-count']='<span class="cart-count">'.(WC()->cart?(int)WC()->cart->get_cart_contents_count():0).'</span>';return $fragments;});
add_filter('loop_shop_per_page', function () { return 12; });
add_filter('loop_shop_columns', function () { return 3; });
add_filter('woocommerce_product_related_products_heading', function () { return 'A little more FORUS'; });
function forus_store_asset($file) { return esc_url(get_template_directory_uri().'/assets/'.$file); }
function forus_store_product_image($sku) {
    $id=wc_get_product_id_by_sku($sku); $product=$id?wc_get_product($id):null;
    return $product ? wp_get_attachment_image_url($product->get_image_id(),'large') : '';
}
function forus_store_categories() {
    echo '<nav class="category-nav" aria-label="Product collections"><a href="'.esc_url(wc_get_page_permalink('shop')).'">All goods</a>';
    foreach(array('clothing'=>'Clothing','everyday'=>'Everyday essentials','accessories'=>'Accessories') as $slug=>$label) {
        $term=get_term_by('slug',$slug,'product_cat'); if(!$term)continue;
        echo '<a href="'.esc_url(get_term_link($term)).'">'.esc_html($label).'</a>';
    } echo '</nav>';
}
remove_action('woocommerce_before_main_content','woocommerce_output_content_wrapper',10);
remove_action('woocommerce_after_main_content','woocommerce_output_content_wrapper_end',10);
remove_action('woocommerce_sidebar','woocommerce_get_sidebar',10);
add_action('woocommerce_before_main_content',function(){echo '<main id="main" class="wrap page-main">';},10);
add_action('woocommerce_after_main_content',function(){echo '</main>';},10);
add_action('woocommerce_before_shop_loop','forus_store_categories',9);
