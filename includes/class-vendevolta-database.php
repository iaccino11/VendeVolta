<?php
/**
 * Camada de persistência. Tabelas, merges e consultas agregadas.
 *
 * @package VendeVolta
 */

if (!defined('ABSPATH')) exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables; queries are prepared or use hardcoded table names, no user input; real-time data, caching not applicable.

class Vendevolta_Database {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'vendevolta_lost_searches';
    }

    public static function get_carts_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'vendevolta_abandoned_carts';
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_searches = self::get_table_name();
        dbDelta("CREATE TABLE $table_searches (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            term VARCHAR(191) NOT NULL,
            results_count INT(11) NOT NULL DEFAULT 0,
            search_count INT(11) NOT NULL DEFAULT 1,
            last_searched DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY term (term),
            KEY last_searched (last_searched)
        ) $charset;");

        $table_carts = self::get_carts_table_name();
        dbDelta("CREATE TABLE $table_carts (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            email VARCHAR(100) NOT NULL DEFAULT '',
            phone VARCHAR(30) NOT NULL DEFAULT '',
            cart_contents LONGTEXT NOT NULL,
            cart_total DECIMAL(10,2) NOT NULL DEFAULT 0,
            token VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            order_total DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_activity DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY email (email),
            KEY phone (phone),
            KEY customer_id (customer_id),
            KEY session_id (session_id),
            KEY status_activity (status, last_activity),
            KEY order_id (order_id)
        ) $charset;");
    }

    /* =================== LOG DE ERRO =================== */

    public static function maybe_log_db_error($context) {
        global $wpdb;
        if (!empty($wpdb->last_error) && defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Guarded by WP_DEBUG; intentional for diagnostics.
            error_log(sprintf('[VendeVolta][%s] DB error: %s', $context, $wpdb->last_error));
        }
    }

    /* =================== BUSCAS =================== */

    public static function log_search($term, $results_count, $skip_increment = false) {
        global $wpdb;
        $term = trim(sanitize_text_field($term));
        $len  = function_exists('mb_strlen') ? mb_strlen($term) : strlen($term);
        if (empty($term) || $len < 2) return;

        $table = self::get_table_name();

        if ($skip_increment) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table
                    SET results_count = %d,
                        last_searched = %s
                  WHERE term = %s",
                (int) $results_count, current_time('mysql'), $term
            ));
            self::maybe_log_db_error('log_search.consolidate');
            return;
        }

        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (term, results_count, search_count, last_searched)
             VALUES (%s, %d, 1, %s)
             ON DUPLICATE KEY UPDATE
                search_count = search_count + 1,
                results_count = VALUES(results_count),
                last_searched = VALUES(last_searched)",
            $term, (int) $results_count, current_time('mysql')
        ));
        self::maybe_log_db_error('log_search');
    }

    public static function log_ajax_search($term) {
        global $wpdb;
        $term = trim(sanitize_text_field($term));
        $len  = function_exists('mb_strlen') ? mb_strlen($term) : strlen($term);
        if (empty($term) || $len < 2) return;

        $table = self::get_table_name();
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, results_count, search_count FROM $table WHERE term = %s", $term
        ));
        if ($existing) {
            if ((int) $existing->results_count > 0) return;
            $wpdb->update($table, [
                'search_count'  => (int) $existing->search_count + 1,
                'last_searched' => current_time('mysql'),
            ], ['id' => (int) $existing->id]);
            self::maybe_log_db_error('log_ajax_search.update');
            return;
        }
        $wpdb->insert($table, [
            'term' => $term, 'results_count' => 0, 'search_count' => 1,
            'last_searched' => current_time('mysql'),
        ]);
        self::maybe_log_db_error('log_ajax_search.insert');
    }

    public static function get_zero_results($limit = 50, $days = 0) {
        global $wpdb;
        $table = self::get_table_name();
        $sql   = "SELECT * FROM $table WHERE results_count = 0";
        $args  = [];
        if ($days > 0) {
            $sql   .= " AND last_searched >= %s";
            $args[] = self::date_n_days_ago($days);
        }
        $sql   .= " ORDER BY search_count DESC LIMIT %d";
        $args[] = (int) $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    public static function count_lost_searches($days = 0) {
        global $wpdb;
        $table = self::get_table_name();
        $sql   = "SELECT COALESCE(SUM(search_count), 0) FROM $table WHERE results_count = 0";
        $args  = [];
        if ($days > 0) {
            $sql   .= " AND last_searched >= %s";
            $args[] = self::date_n_days_ago($days);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($wpdb->prepare($sql, $args));
    }

    /* =================== RATE LIMIT ATÔMICO =================== */

    public static function rate_limit_hit($key, $limit, $ttl_seconds) {
        global $wpdb;
        $opt_name     = '_transient_' . $key;
        $timeout_name = '_transient_timeout_' . $key;

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')",
            $timeout_name, (string) (time() + (int) $ttl_seconds)
        ));

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
            $opt_name
        ));
        self::maybe_log_db_error('rate_limit_hit');

        $hits = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $opt_name
        ));

        return $hits <= (int) $limit;
    }

    /* =================== CARRINHOS =================== */

    /**
     * Cria ou atualiza o carrinho "vivo" do usuário/sessão.
     */
    public static function save_active_cart($wp_user_id, $session_id, $email, $phone, $contents, $total) {
        global $wpdb;
        $table      = self::get_carts_table_name();
        $wp_user_id = (int) $wp_user_id;
        $session_id = trim((string) $session_id);
        if ($wp_user_id === 0 && $session_id === '') return 0;

        $existing = null;

        if ($wp_user_id > 0) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table
                  WHERE customer_id = %d AND status IN ('active','abandoned')
                  ORDER BY last_activity DESC LIMIT 1",
                $wp_user_id
            ));
            if (!$existing && $session_id !== '') {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table
                      WHERE session_id = %s AND customer_id = 0
                        AND status IN ('active','abandoned')
                      ORDER BY last_activity DESC LIMIT 1",
                    $session_id
                ));
            }
        } elseif ($session_id !== '') {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table
                  WHERE session_id = %s AND status IN ('active','abandoned')
                  ORDER BY last_activity DESC LIMIT 1",
                $session_id
            ));
        }

        $data = [
            'cart_contents' => maybe_serialize($contents),
            'cart_total'    => (float) $total,
            'last_activity' => current_time('mysql'),
            'status'        => 'active',
        ];
        
        // BLINDAGEM: Só atualiza o e-mail se ele NÃO estiver vazio para não apagar o que já foi salvo
        if (!empty($email))     $data['email'] = $email;
        // BLINDAGEM: Só atualiza o telefone se ele NÃO estiver vazio
        if (!empty($phone))     $data['phone'] = $phone;
        
        if ($wp_user_id > 0)    $data['customer_id'] = $wp_user_id;
        if ($session_id !== '') $data['session_id']  = $session_id;

        if ($existing && $wp_user_id > 0 && $session_id !== '') {
            $primary_contents = maybe_unserialize($existing->cart_contents);
            if (!is_array($primary_contents)) $primary_contents = [];

            $guests = $wpdb->get_results($wpdb->prepare(
                "SELECT id, cart_contents FROM $table
                  WHERE session_id = %s AND customer_id = 0
                    AND status IN ('active','abandoned')
                    AND id != %d",
                $session_id, (int) $existing->id
            ));

            $merged_any = false;
            foreach ($guests as $g) {
                $gc = maybe_unserialize($g->cart_contents);
                if (is_array($gc)) {
                    foreach ($gc as $k => $item) {
                        if (!isset($primary_contents[$k])) {
                            $primary_contents[$k] = $item;
                        }
                    }
                }
                $wpdb->update($table, ['status' => 'merged', 'customer_id' => $wp_user_id], ['id' => (int) $g->id]);
                $merged_any = true;
            }
            if ($merged_any) {
                $data['cart_contents'] = maybe_serialize($primary_contents);
            }
        }

        if ($existing) {
            $wpdb->update($table, $data, ['id' => (int) $existing->id]);
            self::maybe_log_db_error('save_active_cart.update');
            do_action('vendevolta_cart_updated', (int) $existing->id);
            return (int) $existing->id;
        }

        $data['token']      = wp_generate_password(32, false);
        $data['created_at'] = current_time('mysql');
        $ok = $wpdb->insert($table, $data);
        self::maybe_log_db_error('save_active_cart.insert');
        if (!$ok) return 0;

        $new_id = (int) $wpdb->insert_id;
        do_action('vendevolta_cart_logged', $new_id);
        return $new_id;
    }

    public static function merge_guest_carts_for_user($user_id, $email, $session_id = '') {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) return;

        $table = self::get_carts_table_name();

        $where      = [];
        $args_where = [];
        if (!empty($email)) {
            $where[] = 'email = %s';
            $args_where[] = $email;
        }
        if ($session_id !== '') {
            $where[] = 'session_id = %s';
            $args_where[] = $session_id;
        }
        if (empty($where)) return;
        $where_sql = '(' . implode(' OR ', $where) . ')';

        $primary = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table
              WHERE customer_id = %d AND status IN ('active','abandoned')
              ORDER BY last_activity DESC LIMIT 1",
            $user_id
        ));

        if (!$primary) {
            $query = "SELECT id FROM $table
                      WHERE customer_id = 0 AND status IN ('active','abandoned')
                        AND $where_sql
                      ORDER BY last_activity DESC LIMIT 1";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $primary = (int) $wpdb->get_var($wpdb->prepare($query, $args_where));
            if ($primary) {
                $wpdb->update($table, ['customer_id' => $user_id], ['id' => $primary]);
            }
        }

        if (!$primary) return;

        $query = "UPDATE $table SET status = 'merged', customer_id = %d
                  WHERE customer_id = 0 AND status IN ('active','abandoned')
                    AND id != %d AND $where_sql";
        $args  = array_merge([$user_id, $primary], $args_where);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare($query, $args));
        self::maybe_log_db_error('merge_guest_carts_for_user');
    }

    public static function get_abandoned_carts($limit = 50, $days = 0) {
        global $wpdb;
        $table = self::get_carts_table_name();
        $sql   = "SELECT * FROM $table WHERE status = 'abandoned'";
        $args  = [];
        if ($days > 0) {
            $sql   .= " AND last_activity >= %s";
            $args[] = self::date_n_days_ago($days);
        }
        $sql   .= " ORDER BY last_activity DESC LIMIT %d";
        $args[] = (int) $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    public static function count_abandoned_carts($days = 0) {
        global $wpdb;
        $table = self::get_carts_table_name();
        $sql   = "SELECT COUNT(*) FROM $table WHERE status = 'abandoned'";
        $args  = [];
        if ($days > 0) {
            $sql   .= " AND last_activity >= %s";
            $args[] = self::date_n_days_ago($days);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($wpdb->prepare($sql, $args));
    }

    public static function get_abandoned_total($days = 0) {
        global $wpdb;
        $table = self::get_carts_table_name();
        $sql   = "SELECT COALESCE(SUM(cart_total), 0) FROM $table WHERE status = 'abandoned'";
        $args  = [];
        if ($days > 0) {
            $sql   .= " AND last_activity >= %s";
            $args[] = self::date_n_days_ago($days);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (float) $wpdb->get_var($wpdb->prepare($sql, $args));
    }

    public static function get_cart_by_token($token) {
        global $wpdb;
        $table = self::get_carts_table_name();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE token = %s", $token));
    }

    public static function mark_cart_recovered_by_order($order, $session_id = '') {
        global $wpdb;
        $table  = self::get_carts_table_name();
        $update = [
            'status'      => 'recovered',
            'order_id'    => (int) $order->get_id(),
            'order_total' => (float) $order->get_total(),
        ];
        $cart_id = null;

        if ($session_id !== '') {
            $cart_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table
                  WHERE session_id = %s AND status = 'abandoned'
                  ORDER BY last_activity DESC LIMIT 1",
                $session_id
            ));
        }
        if (!$cart_id && (int) $order->get_customer_id() > 0) {
            $cart_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table
                  WHERE customer_id = %d AND status = 'abandoned'
                  ORDER BY last_activity DESC LIMIT 1",
                (int) $order->get_customer_id()
            ));
        }
        if (!$cart_id) {
            $email = $order->get_billing_email();
            if ($email) {
                $cart_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table
                      WHERE email = %s AND status = 'abandoned'
                      ORDER BY last_activity DESC LIMIT 1",
                    $email
                ));
            }
        }
        if (!$cart_id) return 0;
        $ok = $wpdb->update($table, $update, ['id' => (int) $cart_id]);
        self::maybe_log_db_error('mark_cart_recovered_by_order');
        return $ok;
    }

    public static function get_recovered_total($days = 0) {
        global $wpdb;
        $table = self::get_carts_table_name();
        $sql   = "SELECT COALESCE(SUM(order_total), 0) FROM $table WHERE status = 'recovered' AND order_id > 0";
        $args  = [];
        if ($days > 0) {
            $sql   .= " AND last_activity >= %s";
            $args[] = self::date_n_days_ago($days);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (float) $wpdb->get_var($wpdb->prepare($sql, $args));
    }

    private static function date_n_days_ago($days) {
        return wp_date('Y-m-d H:i:s', strtotime("-$days days"));
    }
}