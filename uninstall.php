<?php
/**
 * Remoção total dos dados do plugin.
 *
 * @package VendeVolta
 *
 *
 * Justificativa: rotina de desinstalação — DROP TABLE e limpeza de
 * transients do próprio plugin são intencionais e ocorrem uma única vez.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin tables; queries are prepared or use hardcoded table names, no user input; real-time data, caching not applicable.
global $wpdb;

$vendevolta_cleanup_site = function() use ($wpdb) {
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('vendevolta_check_cart_status', [], 'vendevolta');
    }
    wp_clear_scheduled_hook('vendevolta_cleanup_old_data');

    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}vendevolta_lost_searches");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}vendevolta_abandoned_carts");

    $wpdb->query(
        "DELETE FROM {$wpdb->options}
          WHERE option_name LIKE '_transient_vendevolta_rl_%'
             OR option_name LIKE '_transient_timeout_vendevolta_rl_%'"
    );
    delete_option('vendevolta_db_version');
};

if (is_multisite()) {
    $vendevolta_sites = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($vendevolta_sites as $vendevolta_site_id) {
        switch_to_blog($vendevolta_site_id);
        $vendevolta_cleanup_site();
        restore_current_blog();
    }
} else {
    $vendevolta_cleanup_site();
}