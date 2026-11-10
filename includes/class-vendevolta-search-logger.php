<?php
/**
 * Captura buscas server-side. Deduplica com o AJAX via flags de sessão WC.
 *
 * @package VendeVolta
 */

if (!defined('ABSPATH')) exit;

class Vendevolta_Search_Logger {

    /** @var string|null */
    private $current_search = null;

    /** Janela de deduplicação AJAX↔server-side, em segundos. */
    const DEDUP_WINDOW = 60;

    public function __construct() {
        add_action('pre_get_posts', [$this, 'capture_term'], 10, 1);
        add_action('wp', [$this, 'log_after_query'], 10, 1);
    }

    /**
     * Guarda o termo da main query de busca.
     *
     * @param WP_Query $query
     * @return void
     */
    public function capture_term($query) {
        if (is_admin() || !$query->is_main_query() || !$query->is_search()) return;
        $term = $query->get('s');
        if (!empty($term)) $this->current_search = sanitize_text_field($term);
    }

    /**
     * Registra o termo após a query rodar.
     *
     * Fluxo:
     *  - Se existe flag `vendevolta_ajax_logged[hash]` recente → AJAX já contou;
     *    server-side apenas consolida results_count (skip_increment).
     *  - Caso contrário, server-side incrementa e grava `vendevolta_server_logged`.
     *
     * @return void
     */
    public function log_after_query() {
        if ($this->current_search === null) return;

        if ((int) get_query_var('paged') > 1) {
            $this->current_search = null;
            return;
        }

        global $wp_query;
        $key = md5($this->current_search);
        $skip = self::consume_logged_flag('vendevolta_ajax_logged', $key);

        Vendevolta_Database::log_search($this->current_search, (int) $wp_query->found_posts, $skip);

        if (!$skip) {
            self::push_logged_flag('vendevolta_server_logged', $key);
        }

        $this->current_search = null;
    }

    /**
     * Consome uma flag recente da sessão WC (uma única vez, expira em DEDUP_WINDOW).
     *
     * @param string $bucket `vendevolta_ajax_logged` ou `vendevolta_server_logged`.
     * @param string $key    md5 do termo.
     * @return bool True se havia flag válida para o termo.
     */
    public static function consume_logged_flag($bucket, $key) {
        if (!function_exists('WC') || !WC()->session) return false;

        $entries = WC()->session->get($bucket, []);
        if (!is_array($entries)) {
            WC()->session->set($bucket, []);
            return false;
        }

        $now       = time();
        $found     = false;
        $remaining = [];

        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['t']) || empty($entry['ts'])) continue;
            $ts = (int) $entry['ts'];
            if (($now - $ts) > self::DEDUP_WINDOW) continue;
            if (!$found && $entry['t'] === $key) {
                $found = true;
                continue;
            }
            $remaining[] = ['t' => $entry['t'], 'ts' => $ts];
        }

        WC()->session->set($bucket, $remaining);
        return $found;
    }

    /**
     * Grava flag de "termo já contabilizado" na sessão WC.
     *
     * @param string $bucket
     * @param string $key md5 do termo.
     * @return void
     */
    public static function push_logged_flag($bucket, $key) {
        if (!function_exists('WC') || !WC()->session) return;

        $entries = WC()->session->get($bucket, []);
        if (!is_array($entries)) $entries = [];

        $now   = time();
        $fresh = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['t']) || empty($entry['ts'])) continue;
            if (($now - (int) $entry['ts']) <= self::DEDUP_WINDOW) {
                $fresh[] = $entry;
            }
        }
        $fresh[] = ['t' => $key, 'ts' => $now];

        if (count($fresh) > 20) $fresh = array_slice($fresh, -20);

        WC()->session->set($bucket, $fresh);
    }
}