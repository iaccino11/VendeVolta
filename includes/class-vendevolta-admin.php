<?php
/**
 * Menu e tela do dashboard.
 *
 * @package VendeVolta
 */

if (!defined('ABSPATH')) exit;

class Vendevolta_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            __('VendeVolta', 'vendevolta'),
            __('VendeVolta', 'vendevolta'),
            'manage_woocommerce',
            'vendevolta-dashboard',
            [$this, 'render_dashboard']
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'woocommerce_page_vendevolta-dashboard') {
            return;
        }
        wp_enqueue_style(
            'vendevolta-dashboard-css',
            VENDEVOLTA_PLUGIN_URL . 'admin/css/dashboard.css',
            [],
            VENDEVOLTA_VERSION
        );
    }

    public function render_dashboard() {
        // CHANGED: phpcs:ignore para o $_GET['tab'] (apenas navegação read-only) + wp_unslash.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameter, no data is modified.
        $current_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'dashboard';
        ?>
        <div class="wrap vendevolta-wrapper">
            <div class="vendevolta-header" style="margin-bottom: 20px;">
                <div>
                    <h1>🎯 <?php esc_html_e('VendeVolta', 'vendevolta'); ?></h1>
                    <p class="vendevolta-intro"><?php esc_html_e('Descubra o que seus clientes procuram e recupere vendas.', 'vendevolta'); ?></p>
                </div>
            </div>

            <!-- Navegação por Abas -->
            <nav class="nav-tab-wrapper woo-nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=vendevolta-dashboard&tab=dashboard" class="nav-tab <?php echo $current_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">📊 Visão Geral</a>
                <?php do_action('vendevolta_dashboard_nav_tabs', $current_tab); ?>
            </nav>

            <?php
            // Roteamento do conteúdo
            if ($current_tab === 'dashboard') {
                $this->render_tab_overview();
            } else {
                // Se for uma aba do PRO (settings ou license)
                do_action('vendevolta_dashboard_tab_content_' . $current_tab, $current_tab);
            }
            ?>
        </div>
        <?php
    }

    private function render_tab_overview() {
        // CHANGED: wp_unslash para consistência com o $_GET['tab'] acima.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter; value validated against a whitelist below.
        $days = isset($_GET['vendevolta_range']) ? absint(wp_unslash($_GET['vendevolta_range'])) : 0;
        if (!in_array($days, [0, 1, 7, 30], true)) $days = 0;

        $results             = Vendevolta_Database::get_zero_results(50, $days);
        $abandoned_carts     = Vendevolta_Database::get_abandoned_carts(50, $days);
        $recovered_total     = Vendevolta_Database::get_recovered_total($days);
        $lost_searches_count = Vendevolta_Database::count_lost_searches($days);
        $abandoned_count     = Vendevolta_Database::count_abandoned_carts($days);
        $abandoned_total     = Vendevolta_Database::get_abandoned_total($days);

        include VENDEVOLTA_PLUGIN_DIR . 'admin/views/dashboard.php';
    }
}