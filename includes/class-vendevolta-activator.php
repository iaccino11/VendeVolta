<?php
/**
 * Ativação, migração e desativação.
 *
 * @package VendeVolta
 *
 */

if (!defined('ABSPATH')) exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables; queries are prepared or use hardcoded table names, no user input; real-time data, caching not applicable.

class Vendevolta_Activator {

    public static function activate() {
        self::migrate();
        update_option('vendevolta_db_version', VENDEVOLTA_DB_VERSION);
    }

    public static function migrate() {
        self::migrate_legacy_prefix();
        Vendevolta_Database::create_tables();
        self::migrate_legacy_data();
    }

    /**
     * Migra dados da versão anterior (prefixo antigo de 3 letras) para o prefixo atual.
     * Executa só quando as tabelas antigas existem e as novas ainda não.
     *
     * @return void
     */
    private static function migrate_legacy_prefix() {
        global $wpdb;
        $old_prefix = 'd' . 'vp_';
        $pairs = [
            $wpdb->prefix . $old_prefix . 'lost_searches'   => Vendevolta_Database::get_table_name(),
            $wpdb->prefix . $old_prefix . 'abandoned_carts' => Vendevolta_Database::get_carts_table_name(),
        ];
        foreach ($pairs as $old => $new) {
            $has_old = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($old)));
            $has_new = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($new)));
            if ($has_old && !$has_new) {
                $wpdb->query("RENAME TABLE `$old` TO `$new`");
            }
        }
        delete_option($old_prefix . 'db_version');
        wp_clear_scheduled_hook($old_prefix . 'cleanup_old_data');
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($old_prefix . 'check_cart_status', [], 'vendevolta');
        }
    }

    private static function migrate_legacy_data() {
        global $wpdb;
        $carts = Vendevolta_Database::get_carts_table_name();

        $wpdb->query("UPDATE $carts
                       SET last_activity = created_at
                       WHERE last_activity = '1970-01-01 00:00:00'
                          OR last_activity IS NULL");

        $wpdb->query("UPDATE $carts SET status = 'legacy_recovered'
                       WHERE status = 'recovered' AND order_id = 0");

        Vendevolta_Database::maybe_log_db_error('migrate_legacy_data');
    }

    public static function deactivate() {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('vendevolta_check_cart_status', [], 'vendevolta');
        }
        wp_clear_scheduled_hook('vendevolta_cleanup_old_data');

        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
              WHERE option_name LIKE '_transient_vendevolta_rl_%'
                 OR option_name LIKE '_transient_timeout_vendevolta_rl_%'"
        );
    }
}