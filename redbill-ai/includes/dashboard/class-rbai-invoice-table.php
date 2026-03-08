<?php
/**
 * Shortcode [glovo_invoice_table] — tabella fatture con filtri e PDF viewer.
 * Tenant-aware: ogni utente vede solo le proprie fatture.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Invoice_Table {

    public static function render($atts): string {
        $tenant = RBAI_Tenant::current();
        if (!$tenant) {
            return '<p>' . esc_html__('Effettua il login per accedere alle fatture.', 'redbill-ai') . '</p>';
        }
        if (!$tenant->is_active()) {
            return '<p>' . esc_html__('Account sospeso o in attesa di approvazione.', 'redbill-ai') . '</p>';
        }

        $db             = new RBAI_Invoice_Database($tenant->get_db_config());
        $filter_options = $db->get_filter_options();
        $invoices       = $db->get_filtered_invoices();

        ob_start();
        ?>
        <div class="gid-invoice-table-wrapper">
            <!-- Filtri -->
            <div class="gid-filters">
                <h3><?php esc_html_e('Filtri', 'redbill-ai'); ?></h3>
                <form id="gid-filter-form">
                    <div class="gid-filter-row">
                        <div class="gid-filter-group">
                            <label for="filter-destinatario"><?php esc_html_e('Destinatario', 'redbill-ai'); ?></label>
                            <select id="filter-destinatario" name="destinatario">
                                <option value=""><?php esc_html_e('Tutti', 'redbill-ai'); ?></option>
                                <?php foreach ($filter_options['destinatari'] as $d): ?>
                                    <option value="<?php echo esc_attr($d); ?>"><?php echo esc_html($d); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="gid-filter-group">
                            <label for="filter-negozio"><?php esc_html_e('Negozio', 'redbill-ai'); ?></label>
                            <select id="filter-negozio" name="negozio">
                                <option value=""><?php esc_html_e('Tutti', 'redbill-ai'); ?></option>
                                <?php foreach ($filter_options['negozi'] as $n): ?>
                                    <option value="<?php echo esc_attr($n); ?>"><?php echo esc_html($n); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="gid-filter-row">
                        <div class="gid-filter-group">
                            <label for="filter-data-from"><?php esc_html_e('Data Fattura Da', 'redbill-ai'); ?></label>
                            <input type="date" id="filter-data-from" name="data_from">
                        </div>
                        <div class="gid-filter-group">
                            <label for="filter-data-to"><?php esc_html_e('Data Fattura A', 'redbill-ai'); ?></label>
                            <input type="date" id="filter-data-to" name="data_to">
                        </div>
                        <div class="gid-filter-group">
                            <label for="filter-periodo-from"><?php esc_html_e('Periodo Da', 'redbill-ai'); ?></label>
                            <input type="date" id="filter-periodo-from" name="periodo_from">
                        </div>
                        <div class="gid-filter-group">
                            <label for="filter-periodo-to"><?php esc_html_e('Periodo A', 'redbill-ai'); ?></label>
                            <input type="date" id="filter-periodo-to" name="periodo_to">
                        </div>
                    </div>
                    <div class="gid-filter-row">
                        <div class="gid-filter-group gid-filter-group-wide">
                            <label for="filter-n-fattura"><?php esc_html_e('Cerca Numero Fattura', 'redbill-ai'); ?></label>
                            <input type="text" id="filter-n-fattura" name="n_fattura" placeholder="Es. FT-2024-001">
                        </div>
                    </div>
                    <div class="gid-filter-actions">
                        <button type="submit" class="gid-btn gid-btn-primary"><?php esc_html_e('Applica Filtri', 'redbill-ai'); ?></button>
                        <button type="button" class="gid-btn gid-btn-secondary" id="gid-reset-filters"><?php esc_html_e('Reset', 'redbill-ai'); ?></button>
                        <button type="button" class="gid-btn gid-btn-success" id="gid-export-csv"><?php esc_html_e('Esporta CSV', 'redbill-ai'); ?></button>
                    </div>
                </form>
            </div>

            <!-- Tabella -->
            <div class="gid-table-container">
                <div id="gid-loading" class="gid-loading" style="display:none;"><?php esc_html_e('Caricamento...', 'redbill-ai'); ?></div>
                <div class="gid-table-responsive">
                    <table class="gid-invoice-table" id="gid-invoice-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Destinatario', 'redbill-ai'); ?></th>
                                <th><?php esc_html_e('Negozio', 'redbill-ai'); ?></th>
                                <th><?php esc_html_e('N. Fattura', 'redbill-ai'); ?></th>
                                <th><?php esc_html_e('Data', 'redbill-ai'); ?></th>
                                <th><?php esc_html_e('Periodo Da', 'redbill-ai'); ?></th>
                                <th><?php esc_html_e('Periodo A', 'redbill-ai'); ?></th>
                                <th><?php esc_html_e('Subtotale', 'redbill-ai'); ?></th>
                                <th><?php esc_html_e('Totale Fattura', 'redbill-ai'); ?></th>
                                <th><?php esc_html_e('Prodotti Lordo', 'redbill-ai'); ?></th>
                                <th><?php esc_html_e('Bonifico', 'redbill-ai'); ?></th>
                                <th><?php esc_html_e('Dettagli', 'redbill-ai'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="gid-invoice-tbody">
                            <?php foreach ($invoices as $invoice): ?>
                            <tr data-invoice-id="<?php echo esc_attr($invoice->id ?? ''); ?>">
                                <td><?php echo esc_html($invoice->destinatario ?? ''); ?></td>
                                <td><?php echo esc_html($invoice->negozio ?? ''); ?></td>
                                <td>
                                    <?php if (!empty($invoice->file_pdf)): ?>
                                        <a href="#" class="gid-pdf-link"
                                           data-pdf="<?php echo esc_attr($invoice->file_pdf); ?>"
                                           data-fattura="<?php echo esc_attr($invoice->n_fattura ?? ''); ?>">
                                            <?php echo esc_html($invoice->n_fattura ?? ''); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo esc_html($invoice->n_fattura ?? ''); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($invoice->data) ? esc_html(date('d/m/Y', strtotime($invoice->data))) : ''; ?></td>
                                <td><?php echo !empty($invoice->periodo_da) ? esc_html(date('d/m/Y', strtotime($invoice->periodo_da))) : ''; ?></td>
                                <td><?php echo !empty($invoice->periodo_a) ? esc_html(date('d/m/Y', strtotime($invoice->periodo_a))) : ''; ?></td>
                                <td class="gid-currency"><?php echo number_format((float)($invoice->subtotale ?? 0), 2, ',', '.'); ?> €</td>
                                <td class="gid-currency gid-highlight"><?php echo number_format((float)($invoice->totale_fattura_iva_inclusa ?? 0), 2, ',', '.'); ?> €</td>
                                <td class="gid-currency"><?php echo number_format((float)($invoice->prodotti ?? 0), 2, ',', '.'); ?> €</td>
                                <td class="gid-currency gid-highlight"><?php echo number_format((float)($invoice->importo_bonifico ?? 0), 2, ',', '.'); ?> €</td>
                                <td class="gid-actions-cell">
                                    <button class="gid-btn-icon gid-view-details"
                                            data-invoice="<?php echo esc_attr(wp_json_encode($invoice)); ?>"
                                            title="<?php esc_attr_e('Vedi tutti i dettagli', 'redbill-ai'); ?>">🔍</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modale dettagli -->
            <div id="gid-modal" class="gid-modal" style="display:none;">
                <div class="gid-modal-content gid-details-modal-content">
                    <div class="gid-details-modal-header">
                        <h2 id="gid-details-title"><?php esc_html_e('Dettagli Fattura', 'redbill-ai'); ?></h2>
                        <span class="gid-close">&times;</span>
                    </div>
                    <div id="gid-modal-body" class="gid-details-modal-body"></div>
                </div>
            </div>

            <!-- Modale PDF -->
            <div id="gid-pdf-modal" class="gid-modal gid-pdf-modal" style="display:none;">
                <div class="gid-modal-content gid-pdf-modal-content">
                    <div class="gid-pdf-modal-header">
                        <h2 id="gid-pdf-modal-title"><?php esc_html_e('Fattura', 'redbill-ai'); ?></h2>
                        <span class="gid-close gid-pdf-close">&times;</span>
                    </div>
                    <div id="gid-pdf-modal-body" class="gid-pdf-modal-body">
                        <div id="gid-pdf-loading" class="gid-pdf-loading"><?php esc_html_e('Caricamento PDF...', 'redbill-ai'); ?></div>
                        <iframe id="gid-pdf-viewer" class="gid-pdf-viewer" src="" frameborder="0"></iframe>
                    </div>
                    <div class="gid-pdf-modal-footer">
                        <a id="gid-pdf-download" href="#" class="gid-btn gid-btn-primary" target="_blank" download>
                            <?php esc_html_e('Scarica PDF', 'redbill-ai'); ?>
                        </a>
                        <button type="button" class="gid-btn gid-btn-secondary gid-pdf-close-btn"><?php esc_html_e('Chiudi', 'redbill-ai'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
