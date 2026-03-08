<?php
/**
 * Shortcode [glovo_invoice_dashboard] — KPI dashboard tenant-aware.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Invoice_Dashboard {

    public static function render($atts): string {
        $tenant = RBAI_Tenant::current();
        if (!$tenant) {
            return '<p>' . esc_html__('Effettua il login per accedere alla dashboard.', 'redbill-ai') . '</p>';
        }
        if (!$tenant->is_active()) {
            return '<p>' . esc_html__('Account sospeso o in attesa di approvazione.', 'redbill-ai') . '</p>';
        }

        $db = new RBAI_Invoice_Database($tenant->get_db_config());

        $filters = [
            'destinatario' => sanitize_text_field($_GET['destinatario'] ?? ''),
            'negozio'      => sanitize_text_field($_GET['negozio']      ?? ''),
            'data_from'    => sanitize_text_field($_GET['data_from']    ?? ''),
            'data_to'      => sanitize_text_field($_GET['data_to']      ?? ''),
            'periodo_from' => sanitize_text_field($_GET['periodo_from'] ?? ''),
            'periodo_to'   => sanitize_text_field($_GET['periodo_to']   ?? ''),
        ];

        $filter_options      = $db->get_filter_options();
        $kpi                 = $db->get_kpi_data($filters);
        $chart_data          = $db->get_chart_data($filters);
        $alert_counts        = $db->count_alert_invoices($filters);
        $high_impact         = $db->get_high_impact_invoices($filters, 0);
        $store_impact        = $db->get_store_impact_data($filters);

        ob_start();
        ?>
        <div class="gid-dashboard-wrapper">
            <!-- Filtri -->
            <div class="gid-filters gid-dashboard-filters">
                <h3><?php esc_html_e('Filtri Dashboard', 'redbill-ai'); ?></h3>
                <form id="gid-dashboard-filter-form" method="get">
                    <div class="gid-filter-row">
                        <div class="gid-filter-group">
                            <label for="dashboard-filter-destinatario"><?php esc_html_e('Destinatario', 'redbill-ai'); ?></label>
                            <select id="dashboard-filter-destinatario" name="destinatario">
                                <option value=""><?php esc_html_e('Tutti', 'redbill-ai'); ?></option>
                                <?php foreach ($filter_options['destinatari'] as $d): ?>
                                <option value="<?php echo esc_attr($d); ?>" <?php selected($filters['destinatario'], $d); ?>><?php echo esc_html($d); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="gid-filter-group">
                            <label for="dashboard-filter-negozio"><?php esc_html_e('Negozio', 'redbill-ai'); ?></label>
                            <select id="dashboard-filter-negozio" name="negozio">
                                <option value=""><?php esc_html_e('Tutti', 'redbill-ai'); ?></option>
                                <?php foreach ($filter_options['negozi'] as $n): ?>
                                <option value="<?php echo esc_attr($n); ?>" <?php selected($filters['negozio'], $n); ?>><?php echo esc_html($n); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="gid-filter-group">
                            <label><?php esc_html_e('Fattura Da', 'redbill-ai'); ?></label>
                            <input type="date" name="data_from" value="<?php echo esc_attr($filters['data_from']); ?>">
                        </div>
                        <div class="gid-filter-group">
                            <label><?php esc_html_e('Fattura A', 'redbill-ai'); ?></label>
                            <input type="date" name="data_to" value="<?php echo esc_attr($filters['data_to']); ?>">
                        </div>
                        <div class="gid-filter-group">
                            <label><?php esc_html_e('Periodo Da', 'redbill-ai'); ?></label>
                            <input type="date" name="periodo_from" value="<?php echo esc_attr($filters['periodo_from']); ?>">
                        </div>
                        <div class="gid-filter-group">
                            <label><?php esc_html_e('Periodo A', 'redbill-ai'); ?></label>
                            <input type="date" name="periodo_to" value="<?php echo esc_attr($filters['periodo_to']); ?>">
                        </div>
                    </div>
                    <div class="gid-filter-actions">
                        <button type="submit" class="gid-btn gid-btn-primary"><?php esc_html_e('Applica', 'redbill-ai'); ?></button>
                        <a href="?" class="gid-btn gid-btn-secondary"><?php esc_html_e('Reset', 'redbill-ai'); ?></a>
                    </div>
                </form>
            </div>

            <!-- KPI cards -->
            <div class="gid-kpi-grid">
                <?php
                $kpi_items = [
                    [__('Fatture Totali',       'redbill-ai'), $kpi['totale_fatture'],    'count'],
                    [__('Fatturato Totale',      'redbill-ai'), $kpi['totale_fatturato'],  'currency'],
                    [__('Prodotti Lordo',        'redbill-ai'), $kpi['totale_prodotti'],   'currency'],
                    [__('Totale Commissioni',    'redbill-ai'), $kpi['totale_commissioni'],'currency'],
                    [__('Totale Marketing',      'redbill-ai'), $kpi['totale_marketing'],  'currency'],
                    [__('IVA Totale',            'redbill-ai'), $kpi['totale_iva'],        'currency'],
                    [__('Bonifico Totale',       'redbill-ai'), $kpi['importo_bonifico_totale'], 'currency'],
                    [__('Media per Fattura',     'redbill-ai'), $kpi['media_per_fattura'], 'currency'],
                ];
                foreach ($kpi_items as [$label, $value, $type]):
                ?>
                <div class="gid-kpi-card">
                    <div class="gid-kpi-label"><?php echo esc_html($label); ?></div>
                    <div class="gid-kpi-value">
                        <?php
                        if ($type === 'currency') {
                            echo '€ ' . number_format((float)$value, 2, ',', '.');
                        } else {
                            echo esc_html($value);
                        }
                        ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Alert counts -->
            <div class="gid-alert-summary">
                <div class="gid-alert-card gid-alert-critico">
                    <span class="gid-alert-count"><?php echo $alert_counts['critico']; ?></span>
                    <span class="gid-alert-label"><?php esc_html_e('Critici (&gt;28%)', 'redbill-ai'); ?></span>
                </div>
                <div class="gid-alert-card gid-alert-attenzione">
                    <span class="gid-alert-count"><?php echo $alert_counts['attenzione']; ?></span>
                    <span class="gid-alert-label"><?php esc_html_e('Attenzione (25–28%)', 'redbill-ai'); ?></span>
                </div>
                <div class="gid-alert-card gid-alert-normale">
                    <span class="gid-alert-count"><?php echo $alert_counts['normale']; ?></span>
                    <span class="gid-alert-label"><?php esc_html_e('Nella norma (&lt;25%)', 'redbill-ai'); ?></span>
                </div>
            </div>

            <!-- Grafici -->
            <div class="gid-charts-grid">
                <div class="gid-chart-container">
                    <h3><?php esc_html_e('Prodotti per Mese', 'redbill-ai'); ?></h3>
                    <canvas id="gid-chart-prodotti-mese"></canvas>
                </div>
                <div class="gid-chart-container">
                    <h3><?php esc_html_e('Prodotti per Negozio', 'redbill-ai'); ?></h3>
                    <canvas id="gid-chart-prodotti-negozio"></canvas>
                </div>
            </div>

            <!-- Impatto per negozio -->
            <?php if (!empty($store_impact)): ?>
            <div class="gid-store-impact">
                <h3><?php esc_html_e('Impatto Glovo per Negozio', 'redbill-ai'); ?></h3>
                <table class="gid-impact-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Negozio', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Prodotti €', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Impatto Glovo €', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('% Impatto', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('% Impatto +Promo', 'redbill-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($store_impact as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['negozio']); ?></td>
                            <td class="gid-currency"><?php echo number_format($row['totale_prodotti'], 2, ',', '.'); ?> €</td>
                            <td class="gid-currency"><?php echo number_format($row['impatto_glovo_real'], 2, ',', '.'); ?> €</td>
                            <td class="<?php echo $row['percentuale_impatto_real'] > 28 ? 'gid-alert-critico-text' : ($row['percentuale_impatto_real'] >= 25 ? 'gid-alert-attenzione-text' : ''); ?>">
                                <?php echo number_format($row['percentuale_impatto_real'], 2, ',', '.'); ?>%
                            </td>
                            <td><?php echo number_format($row['percentuale_impatto_promo_real'], 2, ',', '.'); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Dati grafici inline per JS -->
            <script>
            window.rbaiChartData = <?php echo wp_json_encode($chart_data); ?>;
            </script>
        </div>
        <?php
        return ob_get_clean();
    }
}
