<?php
/*
 * Plugin Name: Mirage – Conditional Payments for WooCommerce
 * Description: Enforces Merch-only carts and restricts gateways: only Merch carts can use Authorize.Net; non-Merch carts cannot.
 * Author: Eric Kowalewski
 * Version: 1.0.3
 * Update URI: github.com/emkowale/mirage
 * Last Updated: 2025-10-05 09:55 EDT
 */
if (!defined('ABSPATH')) exit;

/* ===== Config ===== */
define('MIRAGE_TARGET_CAT', 'merch'); // category slug
// Authorize.Net gateway IDs (adjust if yours differ)
define('MIRAGE_AUTHNET_IDS_JSON', '["authorize_net_cim_credit_card","authorize_net_echeck"]');

/* ===== Helpers ===== */
function mirage_item_is_merch($product_id){
    // consider variations too
    if (has_term(MIRAGE_TARGET_CAT, 'product_cat', $product_id)) return true;
    $parent_id = wp_get_post_parent_id($product_id);
    return $parent_id ? has_term(MIRAGE_TARGET_CAT, 'product_cat', $parent_id) : false;
}
function mirage_cart_stats(){
    $stats = ['merch'=>0,'other'=>0,'total'=>0];
    if (!function_exists('WC') || !WC()->cart) return $stats;
    foreach (WC()->cart->get_cart() as $item){
        $pid = $item['variation_id'] ?: $item['product_id'];
        $is_merch = mirage_item_is_merch($pid);
        $stats['total']++;
        $is_merch ? $stats['merch']++ : $stats['other']++;
    }
    return $stats;
}

/* ===== 1) Block mixing Merch with non-Merch at add-to-cart ===== */
add_filter('woocommerce_add_to_cart_validation', function($valid, $product_id, $qty, $variation_id = 0){
    if (is_admin()) return $valid;
    $incoming_is_merch = mirage_item_is_merch($variation_id ?: $product_id);
    $stats = mirage_cart_stats();

    // If cart already has items, enforce purity
    if ($stats['total'] > 0){
        $cart_is_merch_only  = ($stats['merch'] > 0 && $stats['other'] === 0);
        $cart_is_other_only  = ($stats['other'] > 0 && $stats['merch'] === 0);

        // Disallow mixing
        if (($cart_is_merch_only && !$incoming_is_merch) || ($cart_is_other_only && $incoming_is_merch)){
            wc_add_notice(sprintf(
                'You can’t mix <strong>%s</strong> items with other categories in the same cart. Please complete this purchase separately.',
                esc_html(ucfirst(MIRAGE_TARGET_CAT))
            ), 'error');
            return false;
        }
    }
    return $valid;
}, 10, 4);

/* ===== 2) Double-check cart purity before checkout ===== */
add_action('woocommerce_check_cart_items', function(){
    $s = mirage_cart_stats();
    if ($s['merch'] > 0 && $s['other'] > 0){
        wc_add_notice('Your cart mixes <strong>Merch</strong> with other categories. Please remove one type so the cart is category-pure.', 'error');
    }
});

/* ===== 3) Payment gateway rules =====
   - Merch-only cart  => allow ONLY Authorize.Net
   - Non-Merch-only   => HIDE Authorize.Net
*/
add_filter('woocommerce_available_payment_gateways', function($gateways){
    if (is_admin() || !is_checkout() || !function_exists('WC') || !WC()->cart) return $gateways;

    $authnet_ids = json_decode(MIRAGE_AUTHNET_IDS_JSON, true) ?: [];
    $s = mirage_cart_stats();

    // Mixed carts should already be blocked; as a safeguard, remove all gateways.
    if ($s['merch'] > 0 && $s['other'] > 0){
        return [];
    }

    // Merch-only: keep only Authorize.Net
    if ($s['merch'] > 0 && $s['other'] === 0){
        foreach ($gateways as $id => $gw){
            if (!in_array($id, $authnet_ids, true)) unset($gateways[$id]);
        }
        return $gateways;
    }

    // Non-Merch-only: remove Authorize.Net
    if ($s['other'] > 0 && $s['merch'] === 0){
        foreach ($authnet_ids as $id){ unset($gateways[$id]); }
        return $gateways;
    }

    return $gateways;
}, 20);
