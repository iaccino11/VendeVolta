<?php
/**
 * Plugin Name:       VendeVolta for WooCommerce
 * Plugin URI:        https://brasilviavel.com/vendevolta/
 * Description:       Descubra o que seus clientes procuram, identifique carrinhos abandonados e encontre oportunidades para vender mais.
 * Version:           2.5.2
 * Author:            Roberto Adriano Iaccino da Silva
 * Author URI:        https://brasilviavel.com/roberto-adriano/
 * Text Domain:       vendevolta
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * WC requires at least: 6.0
 * WC tested up to:     9.5
 */

if (!defined('ABSPATH')) exit;

add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

define('VENDEVOLTA_VERSION', '2.5.2');
define('VENDEVOLTA_DB_VERSION', '2.5.1'); // Sem mudança de schema em 2.5.2; mantido para não disparar migração desnecessária.
define('VENDEVOLTA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VENDEVOLTA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once VENDEVOLTA_PLUGIN_DIR . 'includes/class-vendevolta-database.php';
require_once VENDEVOLTA_PLUGIN_DIR . 'includes/class-vendevolta-search-logger.php';
require_once VENDEVOLTA_PLUGIN_DIR . 'includes/class-vendevolta-admin.php';
require_once VENDEVOLTA_PLUGIN_DIR . 'includes/class-vendevolta-activator.php';
require_once VENDEVOLTA_PLUGIN_DIR . 'includes/class-vendevolta-privacy.php';

register_activation_hook(__FILE__, ['Vendevolta_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Vendevolta_Activator', 'deactivate']);

add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>' .
                esc_html__('VendeVolta precisa do WooCommerce ativo.', 'vendevolta') .
                '</p></div>';
        });
        return;
    }
    if (get_option('vendevolta_db_version') !== VENDEVOLTA_DB_VERSION) {
        Vendevolta_Activator::migrate();
        update_option('vendevolta_db_version', VENDEVOLTA_DB_VERSION);
    }
    new Vendevolta_Search_Logger();
    new Vendevolta_Admin();
    new Vendevolta_Privacy();
});

// Merge de carrinhos guest→logado no login
add_action('wp_login', function($user_login, $user) {
    $session_id = (function_exists('WC') && WC()->session)
        ? (string) WC()->session->get_customer_id()
        : '';
    Vendevolta_Database::merge_guest_carts_for_user((int) $user->ID, $user->user_email, $session_id);
}, 10, 2);

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=vendevolta-dashboard') . '">' . esc_html__('Painel', 'vendevolta') . '</a>';
    array_unshift($links, $settings_link);

    // CHANGED: link PRO agora aponta para o domínio oficial (brasilviavel.com).
    $links[] = '<a href="https://brasilviavel.com/vendevolta/" target="_blank" rel="noopener noreferrer">' .
        esc_html__('🚀 Obter o PRO', 'vendevolta') . '</a>';

    return $links;
});

/* ==========================================================================
 * CAPTURA DE BUSCA VIA JS (compatível com page cache)
 * ========================================================================== */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;

    wp_enqueue_script(
        'vendevolta-search-capture',
        VENDEVOLTA_PLUGIN_URL . 'assets/js/search-capture.js',
        [],
        VENDEVOLTA_VERSION,
        true
    );

    wp_localize_script('vendevolta-search-capture', 'vendevoltaConfig', [
        'ajaxUrl' => admin_url('admin-ajax.php')
    ]);
});

add_action('wp_ajax_vendevolta_get_nonce', 'vendevolta_get_nonce_handler');
add_action('wp_ajax_nopriv_vendevolta_get_nonce', 'vendevolta_get_nonce_handler');

function vendevolta_get_nonce_handler() {
    $session_id = (function_exists('WC') && WC()->session)
        ? (string) WC()->session->get_customer_id()
        : 'anon';
    wp_send_json_success([
        'nonce'  => wp_create_nonce('vendevolta_log_ajax_' . $session_id),
        'action' => 'vendevolta_log_ajax_' . $session_id,
    ]);
}

add_action('wp_ajax_vendevolta_log_ajax', 'vendevolta_log_ajax_handler');
add_action('wp_ajax_nopriv_vendevolta_log_ajax', 'vendevolta_log_ajax_handler');

function vendevolta_log_ajax_handler() {
    $session_id = (function_exists('WC') && WC()->session)
        ? (string) WC()->session->get_customer_id()
        : 'anon';
    $action = 'vendevolta_log_ajax_' . $session_id;

    if (!check_ajax_referer($action, '_wpnonce', false)
        && !check_ajax_referer('vendevolta_log_ajax_anon', '_wpnonce', false)) {
        wp_send_json_error(['message' => esc_html__('Nonce inválido', 'vendevolta')], 403);
    }

    // CHANGED: phpcs:ignore para o $_GET após verificação de nonce.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above by check_ajax_referer.
    $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
    $len  = function_exists('mb_strlen') ? mb_strlen($term) : strlen($term);
    if ($len < 2) wp_send_json_error(['message' => esc_html__('Termo inválido', 'vendevolta')], 400);
    if ($len > 100) {
        $term = function_exists('mb_substr') ? mb_substr($term, 0, 100) : substr($term, 0, 100);
    }

    $ip     = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    $bucket = (int) floor(time() / HOUR_IN_SECONDS);
    if (!Vendevolta_Database::rate_limit_hit('vendevolta_rl_' . md5($ip) . '_' . $bucket, 300, HOUR_IN_SECONDS)) {
        wp_send_json_error(['message' => esc_html__('Muitas requisições', 'vendevolta')], 429);
    }

    $key = md5($term);

    if (Vendevolta_Search_Logger::consume_logged_flag('vendevolta_server_logged', $key)) {
        wp_send_json_success(['logged' => false, 'deduplicated' => true]);
    }

    Vendevolta_Database::log_ajax_search($term);

    Vendevolta_Search_Logger::push_logged_flag('vendevolta_ajax_logged', $key);

    wp_send_json_success(['logged' => true]);
}

/* ==========================================================================
 * RASTREAMENTO DE CARRINHO
 * ========================================================================== */
add_action('woocommerce_add_to_cart', 'vendevolta_track_cart_activity');
add_action('woocommerce_cart_updated', 'vendevolta_track_cart_activity_throttled');
add_action('woocommerce_checkout_update_order_review', 'vendevolta_track_checkout_activity', 10, 1);

function vendevolta_track_cart_activity() {
    vendevolta_track_cart_activity_impl(false, '');
}
function vendevolta_track_cart_activity_throttled() {
    vendevolta_track_cart_activity_impl(true, '');
}
function vendevolta_track_checkout_activity($post_data) {
    vendevolta_track_cart_activity_impl(true, is_string($post_data) ? $post_data : '');
}

function vendevolta_track_cart_activity_impl($throttle, $post_data) {
    if (wp_doing_cron() || (is_admin() && !wp_doing_ajax())) return;
    if (!function_exists('WC') || !WC()->cart || !WC()->session) return;

    $cart = WC()->cart->get_cart();
    if (empty($cart)) return;

    if ($throttle) {
        $last = (int) WC()->session->get('vendevolta_last_tracked', 0);
        if ($last && (time() - $last) < 5) return;
    }

    $session_id = (string) WC()->session->get_customer_id();
    $wp_user_id = (int) get_current_user_id();
    if ($session_id === '' && $wp_user_id === 0) return;

    $email = '';
    $phone = '';
    
    if ($wp_user_id > 0) {
        $email = wp_get_current_user()->user_email;
    } else {
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_post = '';
        if ('' !== $post_data) {
            $raw_post = $post_data;
        } elseif (isset($_POST['post_data'])) {
            $raw_post = wp_unslash($_POST['post_data']);
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ('' !== $raw_post) {
            parse_str($raw_post, $data);
            if (!empty($data['billing_email']) && is_email($data['billing_email'])) {
                $email = sanitize_email($data['billing_email']);
            }
            if (!empty($data['billing_phone'])) {
                $phone = sanitize_text_field($data['billing_phone']);
            }
        }
    }

    $cart_id = Vendevolta_Database::save_active_cart(
        $wp_user_id, $session_id, $email, $phone,
        $cart, (float) WC()->cart->get_total('edit')
    );

    if ($cart_id && function_exists('as_schedule_single_action')) {
        $fire_at    = time() + (int) apply_filters('vendevolta_abandon_window', 2 * HOUR_IN_SECONDS);
        $next_check = (int) WC()->session->get('vendevolta_next_check', 0);
        if (abs($fire_at - $next_check) > 60) {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions('vendevolta_check_cart_status', ['cart_id' => $cart_id], 'vendevolta');
            }
            as_schedule_single_action($fire_at, 'vendevolta_check_cart_status', ['cart_id' => $cart_id], 'vendevolta');
            WC()->session->set('vendevolta_next_check', $fire_at);
        }
    }

    WC()->session->set('vendevolta_last_tracked', time());
}

add_action('vendevolta_check_cart_status', function($cart_id) {
    global $wpdb;
    $table = Vendevolta_Database::get_carts_table_name();

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $cart = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $cart_id));
    if (!$cart || $cart->status !== 'active') return;

    $window = (int) apply_filters('vendevolta_abandon_window', 2 * HOUR_IN_SECONDS);
    $cutoff = wp_date('Y-m-d H:i:s', time() - $window);

    if ($cart->last_activity > $cutoff) {
        if (function_exists('as_schedule_single_action')) {
            $fire_at = strtotime($cart->last_activity) + $window + 5;
            $already = function_exists('as_has_scheduled_action')
                && as_has_scheduled_action('vendevolta_check_cart_status', ['cart_id' => (int) $cart_id], 'vendevolta');
            if (!$already) {
                as_schedule_single_action($fire_at, 'vendevolta_check_cart_status', ['cart_id' => (int) $cart_id], 'vendevolta');
            }
        }
        return;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->update($table, ['status' => 'abandoned'], ['id' => (int) $cart_id]);
    Vendevolta_Database::maybe_log_db_error('vendevolta_check_cart_status');
    do_action('vendevolta_cart_abandoned', (int) $cart_id);
}, 10, 1);

add_action('woocommerce_payment_complete', 'vendevolta_mark_cart_recovered', 10, 1);

function vendevolta_mark_cart_recovered($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    $session_id = (function_exists('WC') && WC()->session)
        ? (string) WC()->session->get_customer_id()
        : '';
    Vendevolta_Database::mark_cart_recovered_by_order($order, $session_id);
}