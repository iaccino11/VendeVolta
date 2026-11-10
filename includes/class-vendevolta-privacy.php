<?php
/**
 * Integração com a Privacy API do WordPress.
 *
 * @package VendeVolta
 *
 */

if (!defined('ABSPATH')) exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables; queries are prepared or use hardcoded table names, no user input; real-time data, caching not applicable.

class Vendevolta_Privacy {

    public function __construct() {
        add_action('admin_init', [$this, 'register_policy']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers',   [$this, 'register_eraser']);
        add_action('init', [$this, 'schedule_cleanup']);
        add_action('vendevolta_cleanup_old_data', [$this, 'cleanup']);
    }

    public function register_policy() {
        if (!function_exists('wp_add_privacy_policy_content')) return;
        $days = (int) apply_filters('vendevolta_retention_days', 90);
        wp_add_privacy_policy_content(__('VendeVolta', 'vendevolta'), wpautop(sprintf(
            /* translators: %d: retention days */
            esc_html__('Este plugin armazena termos de busca sem resultado e dados de carrinhos abandonados (e-mail, itens, valor) exclusivamente no banco de dados do próprio site. Endereços IP são processados temporariamente (1 hora, bucket de rate limit) apenas para limitar requisições automatizadas ao endpoint de registro de buscas. Todos os registros — inclusive carrinhos recuperados — são removidos automaticamente após %d dias. A exportação e remoção de dados pessoais podem ser feitas em Ferramentas > Privacidade.', 'vendevolta'),
            $days
        )));
    }

    public function register_exporter($exporters) {
        $exporters['vendevolta'] = [
            'exporter_friendly_name' => __('VendeVolta', 'vendevolta'),
            'callback'               => [$this, 'export'],
        ];
        return $exporters;
    }

    public function export($email, $page = 1) {
        global $wpdb;
        $carts_table    = Vendevolta_Database::get_carts_table_name();
        $searches_table = Vendevolta_Database::get_table_name();
        $offset         = ((int) $page - 1) * 500;
        $items          = [];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $carts_table WHERE email = %s ORDER BY id ASC LIMIT 500 OFFSET %d",
            $email, $offset
        ));
        foreach ($rows as $r) {
            $product_list = [];
            $contents = maybe_unserialize($r->cart_contents);
            if (is_array($contents)) {
                foreach ($contents as $item) {
                    try {
                        if (isset($item['data']) && is_object($item['data']) && method_exists($item['data'], 'get_name')) {
                            $name = $item['data']->get_name();
                            $qty  = isset($item['quantity']) ? (int) $item['quantity'] : 1;
                            if ($name) $product_list[] = $name . ' x' . $qty;
                        }
                    } catch (\Exception $e) {}
                }
            }
            $items[] = [
                'group_id'    => 'vendevolta-abandoned-carts',
                'group_label' => __('Carrinhos Abandonados (VendeVolta)', 'vendevolta'),
                'item_id'     => 'vendevolta-cart-' . $r->id,
                'data'        => [
                    ['name' => __('Valor do carrinho', 'vendevolta'), 'value' => $r->cart_total],
                    ['name' => __('Status', 'vendevolta'),            'value' => $r->status],
                    ['name' => __('Data', 'vendevolta'),              'value' => $r->created_at],
                    ['name' => __('Produtos', 'vendevolta'),          'value' => implode(', ', $product_list) ?: __('(vazio)', 'vendevolta')],
                ],
            ];
        }

        $searches_done = true;
        if (is_email($email)) {
            $matches = $wpdb->get_results($wpdb->prepare(
                "SELECT id, term, last_searched FROM $searches_table
                  WHERE term LIKE %s ORDER BY id ASC LIMIT 500 OFFSET %d",
                '%' . $wpdb->esc_like($email) . '%', $offset
            ));
            foreach ($matches as $m) {
                $items[] = [
                    'group_id'    => 'vendevolta-search-terms',
                    'group_label' => __('Termos de Busca com E-mail (VendeVolta)', 'vendevolta'),
                    'item_id'     => 'vendevolta-search-' . $m->id,
                    'data'        => [
                        ['name' => __('Termo', 'vendevolta'),        'value' => $m->term],
                        ['name' => __('Última busca', 'vendevolta'), 'value' => $m->last_searched],
                    ],
                ];
            }
            $searches_done = count($matches) < 500;
        }

        return ['data' => $items, 'done' => (count($rows) < 500) && $searches_done];
    }

    public function register_eraser($erasers) {
        $erasers['vendevolta'] = [
            'eraser_friendly_name' => __('VendeVolta', 'vendevolta'),
            'callback'             => [$this, 'erase'],
        ];
        return $erasers;
    }

    public function erase($email) {
        global $wpdb;
        $carts_table    = Vendevolta_Database::get_carts_table_name();
        $searches_table = Vendevolta_Database::get_table_name();

        $carts_deleted = $wpdb->delete($carts_table, ['email' => $email], ['%s']);

        $searches_deleted = 0;
        if (is_email($email)) {
            $searches_deleted = (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM $searches_table WHERE term LIKE %s",
                '%' . $wpdb->esc_like($email) . '%'
            ));
        }
        return [
            'items_removed'  => ($carts_deleted > 0) || ($searches_deleted > 0),
            'items_retained' => false,
            'messages'       => [],
            'done'           => true,
        ];
    }

    public function schedule_cleanup() {
        if (!wp_next_scheduled('vendevolta_cleanup_old_data')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'vendevolta_cleanup_old_data');
        }
    }

    public function cleanup() {
        global $wpdb;
        $days = (int) apply_filters('vendevolta_retention_days', 90);
        if ($days <= 0) return;

        $cutoff         = wp_date('Y-m-d H:i:s', strtotime("-$days days"));
        $searches_table = Vendevolta_Database::get_table_name();
        $carts_table    = Vendevolta_Database::get_carts_table_name();

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$searches_table} WHERE last_searched < %s",
            $cutoff
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$carts_table} WHERE last_activity < %s",
            $cutoff
        ));
        Vendevolta_Database::maybe_log_db_error('cleanup');
    }
}