<?php
/**
 * Classe per gestire la visualizzazione della tabella fatture
 */

if (!defined('ABSPATH')) {
    exit;
}

class GID_Invoice_Table {

    /**
     * Renderizza lo shortcode per la tabella
     */
    public static function render($atts) {
        $db = new GID_Invoice_Database();
        $filter_options = $db->get_filter_options();
        $invoices = $db->get_filtered_invoices();

        ob_start();
        ?>
        <div class="gid-invoice-table-wrapper">
            <!-- Filtri -->
            <div class="gid-filters">
                <h3>Filtri</h3>
                <form id="gid-filter-form">
                    <div class="gid-filter-row">
                        <div class="gid-filter-group">
                            <label for="filter-destinatario">Destinatario</label>
                            <select id="filter-destinatario" name="destinatario">
                                <option value="">Tutti</option>
                                <?php foreach ($filter_options['destinatari'] as $destinatario): ?>
                                    <option value="<?php echo esc_attr($destinatario); ?>">
                                        <?php echo esc_html($destinatario); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="gid-filter-group">
                            <label for="filter-negozio">Negozio</label>
                            <select id="filter-negozio" name="negozio">
                                <option value="">Tutti</option>
                                <?php foreach ($filter_options['negozi'] as $negozio): ?>
                                    <option value="<?php echo esc_attr($negozio); ?>">
                                        <?php echo esc_html($negozio); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="gid-filter-row">
                        <div class="gid-filter-group">
                            <label for="filter-data-from">Data Fattura Da</label>
                            <input type="date" id="filter-data-from" name="data_from">
                        </div>

                        <div class="gid-filter-group">
                            <label for="filter-data-to">Data Fattura A</label>
                            <input type="date" id="filter-data-to" name="data_to">
                        </div>
                    </div>

                    <div class="gid-filter-row">
                        <div class="gid-filter-group">
                            <label for="filter-periodo-from">Periodo Da</label>
                            <input type="date" id="filter-periodo-from" name="periodo_from">
                        </div>

                        <div class="gid-filter-group">
                            <label for="filter-periodo-to">Periodo A</label>
                            <input type="date" id="filter-periodo-to" name="periodo_to">
                        </div>
                    </div>

                    <div class="gid-filter-row">
                        <div class="gid-filter-group gid-filter-group-wide">
                            <label for="filter-n-fattura">Cerca Numero Fattura</label>
                            <input type="text" id="filter-n-fattura" name="n_fattura" placeholder="Es. FT-2024-001">
                        </div>
                    </div>

                    <div class="gid-filter-actions">
                        <button type="submit" class="gid-btn gid-btn-primary">Applica Filtri</button>
                        <button type="button" class="gid-btn gid-btn-secondary" id="gid-reset-filters">Reset</button>
                        <button type="button" class="gid-btn gid-btn-success" id="gid-export-csv">Esporta CSV</button>
                    </div>
                </form>
            </div>

            <!-- Tabella -->
            <div class="gid-table-container">
                <div id="gid-loading" class="gid-loading" style="display: none;">
                    Caricamento in corso...
                </div>

                <div class="gid-table-responsive">
                    <table class="gid-invoice-table" id="gid-invoice-table">
                        <thead>
                            <tr>
                                <th>Destinatario</th>
                                <th>Negozio</th>
                                <th>N. Fattura</th>
                                <th>Data</th>
                                <th>Periodo Da</th>
                                <th>Periodo A</th>
                                <th>Subtotale</th>
                                <th>Totale Fattura</th>
                                <th>Prodotti Lordo</th>
                                <th>Bonifico</th>
                                <th>Dettagli</th>
                            </tr>
                        </thead>
                        <tbody id="gid-invoice-tbody">
                            <?php foreach ($invoices as $invoice): ?>
                                <tr data-invoice-id="<?php echo esc_attr($invoice->id ?? ''); ?>">
                                    <td><?php echo esc_html($invoice->destinatario); ?></td>
                                    <td><?php echo esc_html($invoice->negozio); ?></td>
                                    <td>
                                        <?php if (!empty($invoice->file_pdf)): ?>
                                            <a href="#" class="gid-pdf-link"
                                               data-pdf="<?php echo esc_attr($invoice->file_pdf); ?>"
                                               data-fattura="<?php echo esc_attr($invoice->n_fattura); ?>"
                                               title="Clicca per visualizzare il PDF">
                                                <?php echo esc_html($invoice->n_fattura); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo esc_html($invoice->n_fattura); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html(date('d/m/Y', strtotime($invoice->data))); ?></td>
                                    <td><?php echo esc_html(date('d/m/Y', strtotime($invoice->periodo_da))); ?></td>
                                    <td><?php echo esc_html(date('d/m/Y', strtotime($invoice->periodo_a))); ?></td>
                                    <td class="gid-currency">
                                        <?php echo number_format(floatval($invoice->subtotale), 2, ',', '.'); ?> €
                                    </td>
                                    <td class="gid-currency gid-highlight">
                                        <?php echo number_format(floatval($invoice->totale_fattura_iva_inclusa), 2, ',', '.'); ?> €
                                    </td>
                                    <td class="gid-currency">
                                        <?php echo number_format(floatval($invoice->prodotti), 2, ',', '.'); ?> €
                                    </td>
                                    <td class="gid-currency gid-highlight">
                                        <?php echo number_format(floatval($invoice->importo_bonifico), 2, ',', '.'); ?> €
                                    </td>
                                    <td class="gid-actions-cell">
                                        <button class="gid-btn-icon gid-view-details"
                                                data-invoice="<?php echo esc_attr(json_encode($invoice)); ?>"
                                                title="Vedi tutti i dettagli">
                                            🔍
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modale per i dettagli completi -->
            <div id="gid-modal" class="gid-modal" style="display: none;">
                <div class="gid-modal-content gid-details-modal-content">
                    <div class="gid-details-modal-header">
                        <h2 id="gid-details-title">Dettagli Fattura</h2>
                        <span class="gid-close">&times;</span>
                    </div>
                    <div id="gid-modal-body" class="gid-details-modal-body"></div>
                </div>
            </div>

            <!-- Modale per visualizzazione PDF -->
            <div id="gid-pdf-modal" class="gid-modal gid-pdf-modal" style="display: none;">
                <div class="gid-modal-content gid-pdf-modal-content">
                    <div class="gid-pdf-modal-header">
                        <h2 id="gid-pdf-modal-title">Fattura</h2>
                        <span class="gid-close gid-pdf-close">&times;</span>
                    </div>
                    <div id="gid-pdf-modal-body" class="gid-pdf-modal-body">
                        <div id="gid-pdf-loading" class="gid-pdf-loading">
                            Caricamento PDF in corso...
                        </div>
                        <iframe id="gid-pdf-viewer" class="gid-pdf-viewer" src="" frameborder="0"></iframe>
                    </div>
                    <div class="gid-pdf-modal-footer">
                        <a id="gid-pdf-download" href="#" class="gid-btn gid-btn-primary" target="_blank" download>
                            Scarica PDF
                        </a>
                        <button type="button" class="gid-btn gid-btn-secondary gid-pdf-close-btn">Chiudi</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
