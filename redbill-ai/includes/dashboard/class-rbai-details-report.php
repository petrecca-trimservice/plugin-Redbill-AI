<?php
/**
 * Shortcode [glovo_details_report] — report dettagliato per negozio, tenant-aware.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Details_Report {

    public static function render($atts): string {
        $tenant = RBAI_Tenant::current();
        if (!$tenant) {
            return '<p>' . esc_html__('Effettua il login per accedere al report.', 'redbill-ai') . '</p>';
        }
        if (!$tenant->is_active()) {
            return '<p>' . esc_html__('Account sospeso o in attesa di approvazione.', 'redbill-ai') . '</p>';
        }

        $db             = new RBAI_Invoice_Database($tenant->get_db_config());
        $filter_options = $db->get_filter_options();

        $filters = [
            'destinatario' => sanitize_text_field($_GET['destinatario'] ?? ''),
            'negozio'      => sanitize_text_field($_GET['negozio']      ?? ''),
            'data_from'    => sanitize_text_field($_GET['data_from']    ?? ''),
            'data_to'      => sanitize_text_field($_GET['data_to']      ?? ''),
        ];

        $invoices    = $db->get_filtered_invoices($filters);
        $store_data  = $db->get_store_impact_data($filters);

        ob_start();
        ?>
        <div class="gid-details-report-wrapper">
            <h2><?php esc_html_e('Report Dettagliato Negozi', 'redbill-ai'); ?></h2>

            <!-- Filtri -->
            <form id="gid-details-filter-form" method="get" class="gid-filters">
                <div class="gid-filter-row">
                    <div class="gid-filter-group">
                        <label><?php esc_html_e('Destinatario', 'redbill-ai'); ?></label>
                        <select name="destinatario">
                            <option value=""><?php esc_html_e('Tutti', 'redbill-ai'); ?></option>
                            <?php foreach ($filter_options['destinatari'] as $d): ?>
                            <option value="<?php echo esc_attr($d); ?>" <?php selected($filters['destinatario'], $d); ?>><?php echo esc_html($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="gid-filter-group">
                        <label><?php esc_html_e('Negozio', 'redbill-ai'); ?></label>
                        <select name="negozio">
                            <option value=""><?php esc_html_e('Tutti', 'redbill-ai'); ?></option>
                            <?php foreach ($filter_options['negozi'] as $n): ?>
                            <option value="<?php echo esc_attr($n); ?>" <?php selected($filters['negozio'], $n); ?>><?php echo esc_html($n); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="gid-filter-group">
                        <label><?php esc_html_e('Da', 'redbill-ai'); ?></label>
                        <input type="date" name="data_from" value="<?php echo esc_attr($filters['data_from']); ?>">
                    </div>
                    <div class="gid-filter-group">
                        <label><?php esc_html_e('A', 'redbill-ai'); ?></label>
                        <input type="date" name="data_to" value="<?php echo esc_attr($filters['data_to']); ?>">
                    </div>
                </div>
                <div class="gid-filter-actions">
                    <button type="submit" class="gid-btn gid-btn-primary"><?php esc_html_e('Applica', 'redbill-ai'); ?></button>
                    <a href="?" class="gid-btn gid-btn-secondary"><?php esc_html_e('Reset', 'redbill-ai'); ?></a>
                </div>
            </form>

            <!-- Riepilogo per negozio -->
            <?php if (!empty($store_data)): ?>
            <div class="gid-table-responsive" style="margin-top:24px;">
                <table class="gid-invoice-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Negozio', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Prodotti €', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Commissioni €', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Marketing €', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Impatto Glovo €', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('% Impatto', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('% +Promo', 'redbill-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($store_data as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['negozio']); ?></td>
                            <td class="gid-currency"><?php echo number_format($row['totale_prodotti'], 2, ',', '.'); ?> €</td>
                            <td class="gid-currency"><?php echo number_format($row['impatto_glovo'] - $row['impatto_glovo_real'], 2, ',', '.'); ?> €</td>
                            <td class="gid-currency"><?php echo number_format($row['totale_promo_partner'], 2, ',', '.'); ?> €</td>
                            <td class="gid-currency"><?php echo number_format($row['impatto_glovo_real'], 2, ',', '.'); ?> €</td>
                            <td><?php echo number_format($row['percentuale_impatto_real'], 2, ',', '.'); ?>%</td>
                            <td><?php echo number_format($row['percentuale_impatto_promo_real'], 2, ',', '.'); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p><?php esc_html_e('Nessun dato disponibile per i filtri selezionati.', 'redbill-ai'); ?></p>
            <?php endif; ?>

            <!-- Lista fatture -->
            <?php if (!empty($invoices)): ?>
            <h3 style="margin-top:32px;"><?php printf(esc_html__('Fatture (%d)', 'redbill-ai'), count($invoices)); ?></h3>
            <div class="gid-table-responsive">
                <table class="gid-invoice-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Negozio', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('N. Fattura', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Data', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Periodo', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Prodotti €', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Totale €', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Bonifico €', 'redbill-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td><?php echo esc_html($inv->negozio ?? ''); ?></td>
                            <td><?php echo esc_html($inv->n_fattura ?? ''); ?></td>
                            <td><?php echo !empty($inv->data) ? esc_html(date('d/m/Y', strtotime($inv->data))) : ''; ?></td>
                            <td>
                                <?php
                                $da = !empty($inv->periodo_da) ? date('d/m/Y', strtotime($inv->periodo_da)) : '';
                                $a  = !empty($inv->periodo_a)  ? date('d/m/Y', strtotime($inv->periodo_a))  : '';
                                echo esc_html($da . ($a ? ' – ' . $a : ''));
                                ?>
                            </td>
                            <td class="gid-currency"><?php echo number_format((float)($inv->prodotti ?? 0), 2, ',', '.'); ?> €</td>
                            <td class="gid-currency"><?php echo number_format((float)($inv->totale_fattura_iva_inclusa ?? 0), 2, ',', '.'); ?> €</td>
                            <td class="gid-currency"><?php echo number_format((float)($inv->importo_bonifico ?? 0), 2, ',', '.'); ?> €</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
