<?php
/*
 * Plugin Name: Mirage – Conditional Payments for WooCommerce
 * Description: Forces Authorize.Net when the cart contains any product in the “Merch” category, and auto-updates from GitHub Releases.
 * Author: Eric Kowalewski
 * Version: 1.0.2
 * Update URI: github.com/emkowale/mirage
 * Last Updated: 2025-10-05 09:35 EDT
 */

if (!defined('ABSPATH')) exit;

/* ======================
   Settings (edit as needed)
   ====================== */
define('MIRAGE_REPO',       'emkowale/mirage'); // owner/repo
define('MIRAGE_SLUG',       'mirage');          // folder name under wp-content/plugins
define('MIRAGE_FILE',       'mirage/mirage.php');
define('MIRAGE_CAT_SLUGS',  serialize(['merch']));
// Common Authorize.Net IDs (SkyVerge): credit card + eCheck
define('MIRAGE_AUTHNET_IDS', serialize(['authorize_net_cim_credit_card','authorize_net_echeck']));

/* ======================
   Force Authorize.Net for Merch
   ====================== */
add_filter('woocommerce_available_payment_gateways', function ($gateways) {
    if (is_admin() || !function_exists('WC') || !is_checkout() || !WC()->cart || WC()->cart->is_empty()) return $gateways;
    $target_slugs = unserialize(MIRAGE_CAT_SLUGS);
    $contains_target = false;

    foreach (WC()->cart->get_cart() as $item) {
        $pid = isset($item['variation_id']) && $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
        $terms = get_the_terms($pid, 'product_cat');
        if (empty($terms) || is_wp_error($terms)) continue;
        foreach ($terms as $t) {
            if (in_array($t->slug, $target_slugs, true)) { $contains_target = true; break 2; }
        }
    }

    if ($contains_target) {
        $allow = unserialize(MIRAGE_AUTHNET_IDS);
        foreach ($gateways as $id => $gw) {
            if (!in_array($id, $allow, true)) unset($gateways[$id]);
        }
    }
    return $gateways;
}, 20);

/* ======================
   Minimal GitHub auto-updater (Releases-based)
   - Compares Version header to latest GitHub tag (vX.Y.Z)
   - Supplies package: https://github.com/{repo}/releases/download/vX.Y.Z/mirage-vX.Y.Z.zip
   ====================== */
add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (empty($transient->checked)) return $transient;

    $plugin_file = MIRAGE_FILE;
    $current_ver = get_file_data(WP_PLUGIN_DIR . '/' . $plugin_file, ['Version' => 'Version'])['Version'] ?? '0.0.0';

    // Fetch latest release
    $resp = wp_remote_get('https://api.github.com/repos/' . MIRAGE_REPO . '/releases/latest', [
        'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/')],
        'timeout' => 10,
    ]);
    if (is_wp_error($resp)) return $transient;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) return $transient;

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($data) || empty($data['tag_name'])) return $transient;

    $tag = ltrim($data['tag_name'], 'v');
    if (version_compare($tag, $current_ver, '<=')) return $transient;

    $pkg = sprintf('https://github.com/%s/releases/download/v%s/%s-v%s.zip', MIRAGE_REPO, $tag, MIRAGE_SLUG, $tag);

    $update = new stdClass();
    $update->slug = MIRAGE_SLUG;
    $update->plugin = $plugin_file;
    $update->new_version = $tag;
    $update->url = 'https://github.com/' . MIRAGE_REPO;
    $update->package = $pkg;

    $transient->response[$plugin_file] = $update;
    return $transient;
});

/* Details modal on Plugins screen (optional but nice) */
add_filter('plugins_api', function ($res, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== MIRAGE_SLUG) return $res;

    $plugin_file = WP_PLUGIN_DIR . '/' . MIRAGE_FILE;
    $hdr = get_file_data($plugin_file, ['Name'=>'Plugin Name','Version'=>'Version','Author'=>'Author','Description'=>'Description']);
    $ver = $hdr['Version'] ?? '';
    $readme = 'https://raw.githubusercontent.com/' . MIRAGE_REPO . '/main/README.md';

    $resp = wp_remote_get($readme, ['headers'=>['User-Agent'=>'WordPress updater']]);
    $sections = ['description' => esc_html($hdr['Description'])];
    if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
        $sections['changelog'] = esc_html(wp_remote_retrieve_body($resp));
    }

    $obj = new stdClass();
    $obj->name = $hdr['Name'] ?? 'Mirage';
    $obj->slug = MIRAGE_SLUG;
    $obj->version = $ver;
    $obj->author = esc_html($hdr['Author'] ?? '');
    $obj->homepage = 'https://github.com/' . MIRAGE_REPO;
    $obj->sections = $sections;
    $obj->download_link = sprintf('https://github.com/%s/releases/latest', MIRAGE_REPO);
    return $obj;
}, 10, 3);
