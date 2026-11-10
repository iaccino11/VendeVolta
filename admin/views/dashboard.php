<?php if (!defined('ABSPATH')) exit;
// Variáveis fornecidas por Vendevolta_Admin::render_dashboard():
// $results, $abandoned_carts, $recovered_total, $lost_searches_count,
// $abandoned_count, $abandoned_total, $days
?>
<div class="wrap vendevolta-wrapper">
    <div class="vendevolta-header">
        <div>
            <h1>🎯 <?php esc_html_e('VendeVolta', 'vendevolta'); ?></h1>
            <p class="vendevolta-intro"><?php esc_html_e('Seu e-commerce está perdendo oportunidades? Descubra o que seus clientes procuram, identifique carrinhos abandonados e encontre oportunidades para vender mais.', 'vendevolta'); ?></p>
        </div>
        <div><?php do_action('vendevolta_dashboard_header_filters', $days); ?></div>
    </div>

    <!-- STATS GLOBAIS -->
    <div class="vendevolta-stats-grid">
        <div class="vendevolta-stat-card">
            <div class="vendevolta-stat-icon vendevolta-stat-icon--search">🚨</div>
            <div class="vendevolta-stat-info"><h3><?php esc_html_e('Buscas sem Resultado', 'vendevolta'); ?></h3><div class="number"><?php echo (int) $lost_searches_count; ?></div></div>
        </div>
        <div class="vendevolta-stat-card">
            <div class="vendevolta-stat-icon vendevolta-stat-icon--cart">🛒</div>
            <div class="vendevolta-stat-info"><h3><?php esc_html_e('Carrinhos Abandonados', 'vendevolta'); ?></h3><div class="number"><?php echo (int) $abandoned_count; ?></div></div>
        </div>
        <div class="vendevolta-stat-card">
            <div class="vendevolta-stat-icon vendevolta-stat-icon--money">💰</div>
            <div class="vendevolta-stat-info"><h3><?php esc_html_e('Oportunidades de Recuperação', 'vendevolta'); ?></h3><div class="number vendevolta-number--green"><?php echo wp_kses_post(wc_price($abandoned_total)); ?></div></div>
        </div>
        <div class="vendevolta-stat-card">
            <div class="vendevolta-stat-icon vendevolta-stat-icon--ok">✅</div>
            <div class="vendevolta-stat-info"><h3><?php esc_html_e('Receita Recuperada', 'vendevolta'); ?></h3><div class="number vendevolta-number--green"><?php echo wp_kses_post(wc_price($recovered_total)); ?></div></div>
        </div>
    </div>

    <?php do_action('vendevolta_after_header_charts', $days); ?>
    <?php do_action('vendevolta_before_dashboard_table', $days); ?>

    <!-- ABA: CARRINHOS ABANDONADOS -->
    <div class="vendevolta-section-title">
        <span>🛒 <?php esc_html_e('Recuperação de Carrinhos', 'vendevolta'); ?></span>
        <?php do_action('vendevolta_cart_table_filters', $days); ?>
    </div>

    <?php if (!empty($abandoned_carts)) : ?>
        <div class="vendevolta-table-container">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="vendevolta-col-35"><?php esc_html_e('Cliente', 'vendevolta'); ?></th>
                        <th class="vendevolta-col-15"><?php esc_html_e('Valor', 'vendevolta'); ?></th>
                        <th class="vendevolta-col-20"><?php esc_html_e('Última atividade', 'vendevolta'); ?></th>
                        <?php
                        $vendevolta_cart_headers = apply_filters('vendevolta_cart_table_headers', []);
                        echo wp_kses_post(implode('', (array) $vendevolta_cart_headers));
                        ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($abandoned_carts as $vendevolta_cart) :
                        $vendevolta_when = !empty($vendevolta_cart->last_activity) ? $vendevolta_cart->last_activity : $vendevolta_cart->created_at;
                    ?>
                        <tr>
                            <td>
                                <?php if (empty($vendevolta_cart->email)) : ?>
                                    <em class="vendevolta-text--danger"><?php esc_html_e('Sem e-mail (Visitante)', 'vendevolta'); ?></em>
                                <?php else : ?>
                                    <strong><?php echo esc_html($vendevolta_cart->email); ?></strong>
                                <?php endif; ?>
                            </td>
                            <td><?php echo wp_kses_post(wc_price($vendevolta_cart->cart_total)); ?></td>
                            <td><span class="vendevolta-text--muted"><?php echo esc_html(wp_date('d/m/Y H:i', strtotime($vendevolta_when))); ?></span></td>
                            <?php
                            echo wp_kses_post(apply_filters('vendevolta_cart_row_actions', '', $vendevolta_cart));
                            ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else : ?>
        <div class="vendevolta-pro-teaser"><p class="vendevolta-empty"><?php esc_html_e('Nenhum carrinho abandonado registrado.', 'vendevolta'); ?></p></div>
    <?php endif; ?>

    <!-- ABA: BUSCAS PERDIDAS -->
    <div class="vendevolta-section-title"><span>🔍 <?php esc_html_e('Buscas sem Resultado', 'vendevolta'); ?></span></div>

    <?php if (!empty($results)) : ?>
        <div class="vendevolta-table-container">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="vendevolta-col-35"><?php esc_html_e('Termo', 'vendevolta'); ?></th>
                        <th class="vendevolta-col-20"><?php esc_html_e('Frequência', 'vendevolta'); ?></th>
                        <th class="vendevolta-col-20"><?php esc_html_e('Última Busca', 'vendevolta'); ?></th>
                        <?php
                        $vendevolta_search_headers = apply_filters('vendevolta_table_headers', []);
                        echo wp_kses_post(implode('', (array) $vendevolta_search_headers));
                        ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $vendevolta_row) : ?>
                        <tr>
                            <td><strong>"<?php echo esc_html($vendevolta_row->term); ?>"</strong></td>
                            <td>
				<span class="vendevolta-badge <?php echo esc_attr($vendevolta_row->search_count >= 5 ? 'hot' : ''); ?>">
                                    <?php
                                    /* translators: %s: número de buscas */
                                    printf(esc_html(_n('%s vez', '%s vezes', (int) $vendevolta_row->search_count, 'vendevolta')), esc_html($vendevolta_row->search_count));
                                    ?>
                                </span>
                            </td>
                            <td><span class="vendevolta-text--muted"><?php echo esc_html(wp_date('d/m/Y H:i', strtotime($vendevolta_row->last_searched))); ?></span></td>
                            <?php
                            echo wp_kses_post(apply_filters('vendevolta_table_row_actions', '', $vendevolta_row));
                            ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else : ?>
        <div class="vendevolta-pro-teaser"><p class="vendevolta-empty"><?php esc_html_e('Nenhuma busca perdida registrada.', 'vendevolta'); ?></p></div>
    <?php endif; ?>

    <div class="vendevolta-pro-teaser">
        <p class="vendevolta-pro-teaser-title">🚀 <strong><?php esc_html_e('Quer recuperar essas vendas automaticamente?', 'vendevolta'); ?></strong></p>
        <p class="vendevolta-empty">
            <?php
            printf(
                /* translators: %s: badge PRO */
                esc_html__('Com o %s você envia e-mails automáticos, gera cupons de desconto e dispara WhatsApp com 1 clique.', 'vendevolta'),
                '<span class="vendevolta-pro-badge">PRO</span>'
            );
            ?>
        </p>
    </div>
</div>