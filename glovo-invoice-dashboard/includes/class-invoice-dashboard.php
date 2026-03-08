<?php
/**
 * Classe per gestire la dashboard con KPI e indicatori
 */

if (!defined('ABSPATH')) {
    exit;
}

class GID_Invoice_Dashboard {

    /**
     * Renderizza lo shortcode per la dashboard
     */
    public static function render($atts) {
        $db = new GID_Invoice_Database();
        $filter_options = $db->get_filter_options();

        // Leggi i filtri dai parametri GET
        $filters = array(
            'destinatario' => isset($_GET['destinatario']) ? sanitize_text_field($_GET['destinatario']) : '',
            'negozio' => isset($_GET['negozio']) ? sanitize_text_field($_GET['negozio']) : '',
            'data_from' => isset($_GET['data_from']) ? sanitize_text_field($_GET['data_from']) : '',
            'data_to' => isset($_GET['data_to']) ? sanitize_text_field($_GET['data_to']) : '',
            'periodo_from' => isset($_GET['periodo_from']) ? sanitize_text_field($_GET['periodo_from']) : '',
            'periodo_to' => isset($_GET['periodo_to']) ? sanitize_text_field($_GET['periodo_to']) : '',
        );

        // Applica i filtri ai dati
        $kpi = $db->get_kpi_data($filters);
        $chart_data = $db->get_chart_data($filters);
        $alert_counts = $db->count_alert_invoices($filters);
        $high_impact_invoices = $db->get_high_impact_invoices($filters, 0);
        $store_impact_data = $db->get_store_impact_data($filters);

        ob_start();
        ?>
        <div class="gid-dashboard-wrapper">
            <!-- Filtri -->
            <div class="gid-filters gid-dashboard-filters">
                <h3>Filtri Dashboard</h3>
                <form id="gid-dashboard-filter-form">
                    <div class="gid-filter-row">
                        <div class="gid-filter-group">
                            <label for="dashboard-filter-destinatario">Destinatario</label>
                            <select id="dashboard-filter-destinatario" name="destinatario">
                                <option value="">Tutti</option>
                                <?php foreach ($filter_options['destinatari'] as $destinatario): ?>
                                    <option value="<?php echo esc_attr($destinatario); ?>"
                                            <?php selected($filters['destinatario'], $destinatario); ?>>
                                        <?php echo esc_html($destinatario); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="gid-filter-group">
                            <label for="dashboard-filter-negozio">Negozio</label>
                            <select id="dashboard-filter-negozio" name="negozio">
                                <option value="">Tutti</option>
                                <?php foreach ($filter_options['negozi'] as $negozio): ?>
                                    <option value="<?php echo esc_attr($negozio); ?>"
                                            <?php selected($filters['negozio'], $negozio); ?>>
                                        <?php echo esc_html($negozio); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="gid-filter-group">
                            <label for="dashboard-filter-data-from">Fattura Da</label>
                            <input type="date" id="dashboard-filter-data-from" name="data_from"
                                   value="<?php echo esc_attr($filters['data_from']); ?>">
                        </div>

                        <div class="gid-filter-group">
                            <label for="dashboard-filter-data-to">Fattura A</label>
                            <input type="date" id="dashboard-filter-data-to" name="data_to"
                                   value="<?php echo esc_attr($filters['data_to']); ?>">
                        </div>
                    </div>

                    <div class="gid-filter-row">
                        <div class="gid-filter-group">
                            <label for="dashboard-filter-periodo-from">Vendita Da</label>
                            <input type="date" id="dashboard-filter-periodo-from" name="periodo_from"
                                   value="<?php echo esc_attr($filters['periodo_from']); ?>">
                        </div>

                        <div class="gid-filter-group">
                            <label for="dashboard-filter-periodo-to">Vendita A</label>
                            <input type="date" id="dashboard-filter-periodo-to" name="periodo_to"
                                   value="<?php echo esc_attr($filters['periodo_to']); ?>">
                        </div>
                    </div>

                    <div class="gid-filter-actions">
                        <button type="submit" class="gid-btn gid-btn-primary">Applica Filtri</button>
                        <button type="button" class="gid-btn gid-btn-secondary" id="gid-dashboard-reset-filters">Reset</button>
                    </div>
                </form>

                <?php
                // Mostra filtri attivi
                $filters_active = array_filter($filters);
                if (!empty($filters_active)):
                ?>
                    <div class="gid-filters-active">
                        <strong>Filtri attivi:</strong>
                        <?php
                        $filter_labels = array(
                            'destinatario' => 'Destinatario',
                            'negozio' => 'Negozio',
                            'data_from' => 'Fattura da',
                            'data_to' => 'Fattura a',
                            'periodo_from' => 'Vendita da',
                            'periodo_to' => 'Vendita a'
                        );
                        foreach ($filters_active as $key => $value) {
                            $label = isset($filter_labels[$key]) ? $filter_labels[$key] : $key;
                            echo '<span class="gid-filter-tag">' . esc_html($label) . ': ' . esc_html($value) . '</span> ';
                        }
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <div id="gid-dashboard-loading" class="gid-loading" style="display: none;">
                Caricamento in corso...
            </div>

            <!-- KPI Cards -->
            <div class="gid-kpi-container" id="gid-kpi-container">
                <div class="gid-kpi-card gid-kpi-primary">
                    <div class="gid-kpi-icon">🛍️</div>
                    <div class="gid-kpi-content">
                        <h4>Totale Prodotti Lordo</h4>
                        <p class="gid-kpi-value"><?php echo number_format($kpi['totale_prodotti'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Valore totale dei prodotti</span>
                    </div>
                </div>

                <div class="gid-kpi-card gid-kpi-success">
                    <div class="gid-kpi-icon">📈</div>
                    <div class="gid-kpi-content">
                        <h4>Numero Fatture</h4>
                        <p class="gid-kpi-value"><?php echo number_format($kpi['totale_fatture'], 0, ',', '.'); ?></p>
                        <span class="gid-kpi-label">Fatture totali</span>
                    </div>
                </div>

                <div class="gid-kpi-card gid-kpi-info">
                    <div class="gid-kpi-icon">💰</div>
                    <div class="gid-kpi-content">
                        <h4>Media per Fattura</h4>
                        <p class="gid-kpi-value"><?php echo number_format($kpi['media_per_fattura'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Valore medio</span>
                    </div>
                </div>

                <div class="gid-kpi-card gid-kpi-warning">
                    <div class="gid-kpi-icon">💳</div>
                    <div class="gid-kpi-content">
                        <h4>Bonifici Totali</h4>
                        <p class="gid-kpi-value"><?php echo number_format($kpi['importo_bonifico_totale'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Importo bonifici</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">🍽️</div>
                    <div class="gid-kpi-content">
                        <h4>Buoni Pasto</h4>
                        <p class="gid-kpi-value"><?php echo number_format($kpi['totale_buoni_pasto'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Valore buoni pasto</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">✅</div>
                    <div class="gid-kpi-content">
                        <h4>Glovo Già Pagati</h4>
                        <p class="gid-kpi-value"><?php echo number_format($kpi['totale_glovo_gia_pagati'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Importi già pagati da Glovo</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">💸</div>
                    <div class="gid-kpi-content">
                        <h4>Commissioni Glovo</h4>
                        <p class="gid-kpi-value gid-negative"><?php echo number_format($kpi['totale_commissioni'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Commissioni totali Glovo</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">📢</div>
                    <div class="gid-kpi-content">
                        <h4>Marketing e Visibilità</h4>
                        <p class="gid-kpi-value gid-negative"><?php echo number_format($kpi['totale_marketing'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Costi marketing e visibilità</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">🎁</div>
                    <div class="gid-kpi-content">
                        <h4>Promozioni Partner</h4>
                        <p class="gid-kpi-value gid-negative"><?php echo number_format($kpi['totale_promo_partner'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Sconti a carico del partner</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">⚠️</div>
                    <div class="gid-kpi-content">
                        <h4>Costo Incidenti</h4>
                        <p class="gid-kpi-value gid-negative"><?php echo number_format($kpi['totale_costi_incidenti'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Incidenti sui prodotti</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">⏱️</div>
                    <div class="gid-kpi-content">
                        <h4>Tariffa Attesa</h4>
                        <p class="gid-kpi-value gid-negative"><?php echo number_format($kpi['totale_tariffa_attesa'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Tempo di attesa</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">⭐</div>
                    <div class="gid-kpi-content">
                        <h4>Supplemento Glovo Prime</h4>
                        <p class="gid-kpi-value gid-negative"><?php echo number_format($kpi['totale_supplemento_glovo_prime'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Supplemento ordini Glovo Prime</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">🚚</div>
                    <div class="gid-kpi-content">
                        <h4>Promo Consegna Partner</h4>
                        <p class="gid-kpi-value gid-negative"><?php echo number_format($kpi['totale_promo_consegna_partner'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Promozione sulla consegna a carico del partner</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">⚡</div>
                    <div class="gid-kpi-content">
                        <h4>Costi Offerta Lampo</h4>
                        <p class="gid-kpi-value gid-negative"><?php echo number_format($kpi['totale_costi_offerta_lampo'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Costi per offerta lampo</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">🔥</div>
                    <div class="gid-kpi-content">
                        <h4>Promo Lampo Partner</h4>
                        <p class="gid-kpi-value gid-negative"><?php echo number_format($kpi['totale_promo_lampo_partner'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Promozione lampo a carico del partner</span>
                    </div>
                </div>

                <!-- Box Alert per Fatture con Alto Impatto Glovo -->
                <?php if ($alert_counts['critico'] > 0 || $alert_counts['attenzione'] > 0): ?>
                <div class="gid-kpi-card gid-kpi-danger gid-alert-critico">
                    <div class="gid-kpi-icon">🚨</div>
                    <div class="gid-kpi-content">
                        <h4>Fatture Critiche</h4>
                        <p class="gid-kpi-value"><?php echo $alert_counts['critico']; ?></p>
                        <span class="gid-kpi-label">Impatto Glovo > 28%</span>
                    </div>
                </div>

                <div class="gid-kpi-card gid-kpi-warning gid-alert-attenzione">
                    <div class="gid-kpi-icon">👁️</div>
                    <div class="gid-kpi-content">
                        <h4>Richiede Attenzione</h4>
                        <p class="gid-kpi-value"><?php echo $alert_counts['attenzione']; ?></p>
                        <span class="gid-kpi-label">Impatto Glovo 25-28%</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Indicatori Dettagliati -->
            <div class="gid-indicators-section">
                <h3>Indicatori Dettagliati</h3>

                <?php
                // Calcoli per Box Effettivo
                $vendite_nominale = $kpi['totale_prodotti'];
                $totale_costi_nominale = $kpi['totale_commissioni'] +
                                         $kpi['totale_promo_partner'] +
                                         $kpi['totale_marketing'] +
                                         $kpi['totale_costi_incidenti'] +
                                         $kpi['totale_supplemento_glovo_prime'] +
                                         $kpi['totale_tariffa_attesa'] +
                                         $kpi['totale_promo_consegna_partner'] +
                                         $kpi['totale_costi_offerta_lampo'] +
                                         $kpi['totale_promo_lampo_partner'] +
                                         $kpi['totale_commissione_ordini_rimborsati'] -
                                         $kpi['totale_ordini_rimborsati_partner'] -
                                         $kpi['totale_sconto_comm_buoni_pasto'];
                $subtotale_nominale = $vendite_nominale - $totale_costi_nominale;
                $incassi_netti_nominale = $subtotale_nominale - $kpi['totale_iva'];
                ?>

                <div class="gid-indicators-grid gid-indicators-grid-2col">
                    <!-- Box Effettivo -->
                    <div class="gid-indicator-group">
                        <h4>Effettivo</h4>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Vendite (totale prodotti lordo)</span>
                            <span class="gid-indicator-value gid-positive">
                                <?php echo number_format($vendite_nominale, 2, ',', '.'); ?> €
                                <small>(100.00%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Commissioni Glovo</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php
                                $perc_comm = ($vendite_nominale > 0) ? ($kpi['totale_commissioni'] / $vendite_nominale * 100) : 0;
                                echo number_format($kpi['totale_commissioni'], 2, ',', '.');
                                ?> €
                                <small>(<?php echo number_format($perc_comm, 2); ?>%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Promozioni Prodotti Partner</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php
                                $perc_promo = ($vendite_nominale > 0) ? ($kpi['totale_promo_partner'] / $vendite_nominale * 100) : 0;
                                echo number_format($kpi['totale_promo_partner'], 2, ',', '.');
                                ?> €
                                <small>(<?php echo number_format($perc_promo, 2); ?>%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Marketing e Visibilità</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php
                                $perc_mkt = ($vendite_nominale > 0) ? ($kpi['totale_marketing'] / $vendite_nominale * 100) : 0;
                                echo number_format($kpi['totale_marketing'], 2, ',', '.');
                                ?> €
                                <small>(<?php echo number_format($perc_mkt, 2); ?>%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Costi Incidenti Prodotti</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php
                                $perc_inc = ($vendite_nominale > 0) ? ($kpi['totale_costi_incidenti'] / $vendite_nominale * 100) : 0;
                                echo number_format($kpi['totale_costi_incidenti'], 2, ',', '.');
                                ?> €
                                <small>(<?php echo number_format($perc_inc, 2); ?>%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Supplemento Glovo Prime</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php
                                $perc_prime = ($vendite_nominale > 0) ? ($kpi['totale_supplemento_glovo_prime'] / $vendite_nominale * 100) : 0;
                                echo number_format($kpi['totale_supplemento_glovo_prime'], 2, ',', '.');
                                ?> €
                                <small>(<?php echo number_format($perc_prime, 2); ?>%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Tariffa Tempo Attesa</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php
                                $perc_attesa = ($vendite_nominale > 0) ? ($kpi['totale_tariffa_attesa'] / $vendite_nominale * 100) : 0;
                                echo number_format($kpi['totale_tariffa_attesa'], 2, ',', '.');
                                ?> €
                                <small>(<?php echo number_format($perc_attesa, 2); ?>%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Promo Consegna Partner</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php
                                $perc_promo_consegna = ($vendite_nominale > 0) ? ($kpi['totale_promo_consegna_partner'] / $vendite_nominale * 100) : 0;
                                echo number_format($kpi['totale_promo_consegna_partner'], 2, ',', '.');
                                ?> €
                                <small>(<?php echo number_format($perc_promo_consegna, 2); ?>%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Costi Offerta Lampo</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php
                                $perc_offerta_lampo = ($vendite_nominale > 0) ? ($kpi['totale_costi_offerta_lampo'] / $vendite_nominale * 100) : 0;
                                echo number_format($kpi['totale_costi_offerta_lampo'], 2, ',', '.');
                                ?> €
                                <small>(<?php echo number_format($perc_offerta_lampo, 2); ?>%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Promo Lampo Partner</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php
                                $perc_promo_lampo = ($vendite_nominale > 0) ? ($kpi['totale_promo_lampo_partner'] / $vendite_nominale * 100) : 0;
                                echo number_format($kpi['totale_promo_lampo_partner'], 2, ',', '.');
                                ?> €
                                <small>(<?php echo number_format($perc_promo_lampo, 2); ?>%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Ordini Rimborsati Partner</span>
                            <span class="gid-indicator-value gid-positive">
                                <?php
                                $perc_ordini_rimb = ($vendite_nominale > 0) ? ($kpi['totale_ordini_rimborsati_partner'] / $vendite_nominale * 100) : 0;
                                echo number_format($kpi['totale_ordini_rimborsati_partner'], 2, ',', '.');
                                ?> €
                                <small>(<?php echo number_format($perc_ordini_rimb, 2); ?>%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Commissione Ordini Rimborsati</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php
                                $perc_comm_rimb = ($vendite_nominale > 0) ? ($kpi['totale_commissione_ordini_rimborsati'] / $vendite_nominale * 100) : 0;
                                echo number_format($kpi['totale_commissione_ordini_rimborsati'], 2, ',', '.');
                                ?> €
                                <small>(<?php echo number_format($perc_comm_rimb, 2); ?>%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Sconto Commissione Buoni Pasto</span>
                            <span class="gid-indicator-value gid-positive">
                                <?php
                                $perc_sconto_bp = ($vendite_nominale > 0) ? ($kpi['totale_sconto_comm_buoni_pasto'] / $vendite_nominale * 100) : 0;
                                echo number_format($kpi['totale_sconto_comm_buoni_pasto'], 2, ',', '.');
                                ?> €
                                <small>(<?php echo number_format($perc_sconto_bp, 2); ?>%)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item gid-indicator-separator">
                            <span class="gid-indicator-label"><strong>Subtotale</strong></span>
                            <span class="gid-indicator-value">
                                <strong><?php echo number_format($subtotale_nominale, 2, ',', '.'); ?> €</strong>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">IVA</span>
                            <span class="gid-indicator-value">
                                <?php echo number_format($kpi['totale_iva'], 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item gid-indicator-total">
                            <span class="gid-indicator-label"><strong>Incassi netti</strong></span>
                            <span class="gid-indicator-value gid-positive">
                                <strong><?php echo number_format($incassi_netti_nominale, 2, ',', '.'); ?> €</strong>
                            </span>
                        </div>
                    </div>

                    <!-- Colonna destra: Metodo di pagamento + Incasso puro -->
                    <div class="gid-indicator-column-right">

                    <!-- Box Metodo di pagamento -->
                    <div class="gid-indicator-group">
                        <h4>Quadratura</h4>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Vendite (totale prodotti lordo)</span>
                            <span class="gid-indicator-value gid-positive">
                                <?php echo number_format($kpi['totale_prodotti'], 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Buoni Pasto</span>
                            <span class="gid-indicator-value">
                                <?php echo number_format($kpi['totale_buoni_pasto'], 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Contanti (Glovo Già Pagati)</span>
                            <span class="gid-indicator-value">
                                <?php echo number_format($kpi['totale_glovo_gia_pagati'], 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Bonifico (Bonifici Totali)</span>
                            <span class="gid-indicator-value">
                                <?php echo number_format($kpi['importo_bonifico_totale'], 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <?php
                        $trattenute_totali = $kpi['totale_commissioni'] +
                                             $kpi['totale_promo_partner'] +
                                             $kpi['totale_marketing'] +
                                             $kpi['totale_costi_incidenti'] +
                                             $kpi['totale_supplemento_glovo_prime'] +
                                             $kpi['totale_tariffa_attesa'] +
                                             $kpi['totale_servizio_consegna'] +
                                             $kpi['totale_rimborsi'] +
                                             $kpi['totale_costo_annullamenti'] +
                                             $kpi['totale_consegna_gratuita'] +
                                             $kpi['totale_promo_consegna_partner'] +
                                             $kpi['totale_costi_offerta_lampo'] +
                                             $kpi['totale_promo_lampo_partner'] +
                                             $kpi['totale_commissione_ordini_rimborsati'] -
                                             $kpi['totale_ordini_rimborsati_partner'] -
                                             $kpi['totale_sconto_comm_buoni_pasto'];
                        $totale_metodo_pagamento = $kpi['totale_buoni_pasto'] +
                                                   $kpi['totale_glovo_gia_pagati'] +
                                                   $kpi['importo_bonifico_totale'] +
                                                   $trattenute_totali +
                                                   $kpi['totale_iva'] +
                                                   $kpi['debito_totale'];
                        ?>

                        <div class="gid-indicator-item gid-indicator-separator">
                            <span class="gid-indicator-label">Trattenute (costi/fee)</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php echo number_format($trattenute_totali, 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">IVA</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php echo number_format($kpi['totale_iva'], 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Debito Accumulato</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php echo number_format($kpi['debito_totale'], 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item gid-indicator-total">
                            <span class="gid-indicator-label">
                                <strong>Totale</strong><br>
                                <small style="font-weight:normal;color:#888;">(Buoni Pasto + Contanti + Bonifico + Trattenute + IVA + Debito)</small>
                            </span>
                            <span class="gid-indicator-value">
                                <strong><?php echo number_format($totale_metodo_pagamento, 2, ',', '.'); ?> €</strong>
                            </span>
                        </div>
                    </div>

                    <!-- Box Incasso puro -->
                    <div class="gid-indicator-group">
                        <h4>Incasso puro - cosa rimane (altrimenti detta Ciccia)</h4>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Buoni Pasto</span>
                            <span class="gid-indicator-value">
                                <?php echo number_format($kpi['totale_buoni_pasto'], 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Contanti (Glovo Già Pagati)</span>
                            <span class="gid-indicator-value">
                                <?php echo number_format($kpi['totale_glovo_gia_pagati'], 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Bonifico (Bonifici Totali)</span>
                            <span class="gid-indicator-value">
                                <?php echo number_format($kpi['importo_bonifico_totale'], 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item gid-indicator-total">
                            <span class="gid-indicator-label">
                                <strong>Totale Incasso Puro</strong>
                            </span>
                            <span class="gid-indicator-value gid-positive">
                                <strong><?php echo number_format($kpi['totale_buoni_pasto'] + $kpi['totale_glovo_gia_pagati'] + $kpi['importo_bonifico_totale'], 2, ',', '.'); ?> €</strong>
                            </span>
                        </div>
                    </div>
                    </div><!-- /gid-indicator-column-right -->
                </div>
            </div>

            <!-- Se avessimo venduto in negozio -->
            <div class="gid-indicators-section gid-shop-simulation-section">
                <h3>Se avessimo venduto in negozio...</h3>
                <p class="gid-simulation-subtitle">Simulazione dei ricavi se le vendite fossero avvenute direttamente in negozio (con scorporo fisso del 15% sui prezzi Glovo)</p>

                <?php
                // Calcoli per la simulazione negozio (valori iniziali con 15%)
                $percentuale_scorporo_default = 15;
                $vendite_originale = $kpi['totale_prodotti'];
                $vendite_reali_sim = ($kpi['totale_prodotti'] / (100 + $percentuale_scorporo_default)) * 100; // Scorporo dinamico
                $scorporo_valore = $vendite_originale - $vendite_reali_sim;

                // Voci senza aumento del 22%
                $supplemento_prime_sim = $kpi['totale_supplemento_glovo_prime'];
                $commissioni_sim = $kpi['totale_commissioni'];
                $marketing_sim = $kpi['totale_marketing'];

                // Voci normali
                $promo_partner_sim = $kpi['totale_promo_partner'];
                $costi_incidenti_sim = $kpi['totale_costi_incidenti'];
                $tariffa_attesa_sim = $kpi['totale_tariffa_attesa'];
                $promo_consegna_partner_sim = $kpi['totale_promo_consegna_partner'];
                $costi_offerta_lampo_sim = $kpi['totale_costi_offerta_lampo'];
                $promo_lampo_partner_sim = $kpi['totale_promo_lampo_partner'];

                // Calcolo Negozio simulato
                $totale_costi_simulati = $supplemento_prime_sim +
                                        $commissioni_sim +
                                        $marketing_sim +
                                        $promo_partner_sim +
                                        $costi_incidenti_sim +
                                        $tariffa_attesa_sim +
                                        $promo_consegna_partner_sim +
                                        $costi_offerta_lampo_sim +
                                        $promo_lampo_partner_sim;

                $negozio_simulato = $vendite_reali_sim - $totale_costi_simulati;
                ?>

                <div class="gid-shop-simulation-container">
                    <!-- Box Simulazione Negozio -->
                    <div class="gid-indicator-group gid-shop-simulation-box">
                        <h4>📊 Simulazione Negozio Fisico</h4>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Vendite reali</span>
                            <span class="gid-indicator-value gid-positive">
                                <?php echo number_format($vendite_reali_sim, 2, ',', '.'); ?> €
                                <small>(originale Glovo: <?php echo number_format($vendite_originale, 2, ',', '.'); ?> €, scorporo 15%: <?php echo number_format($scorporo_valore, 2, ',', '.'); ?> €)</small>
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Supplemento Glovo Prime</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php echo number_format($supplemento_prime_sim, 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Commissioni Glovo</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php echo number_format($commissioni_sim, 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Marketing e Visibilità</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php echo number_format($marketing_sim, 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Promozioni Prodotti Partner</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php echo number_format($promo_partner_sim, 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Costi Incidenti Prodotti</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php echo number_format($costi_incidenti_sim, 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Tariffa Tempo Attesa</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php echo number_format($tariffa_attesa_sim, 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Promo Consegna Partner</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php echo number_format($promo_consegna_partner_sim, 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Costi Offerta Lampo</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php echo number_format($costi_offerta_lampo_sim, 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item">
                            <span class="gid-indicator-label">Promo Lampo Partner</span>
                            <span class="gid-indicator-value gid-negative">
                                <?php echo number_format($promo_lampo_partner_sim, 2, ',', '.'); ?> €
                            </span>
                        </div>

                        <div class="gid-indicator-item gid-indicator-separator">
                            <span class="gid-indicator-label"><strong>Totale Costi</strong></span>
                            <span class="gid-indicator-value gid-negative">
                                <strong><?php echo number_format($totale_costi_simulati, 2, ',', '.'); ?> €</strong>
                            </span>
                        </div>

                        <div class="gid-indicator-item gid-indicator-total gid-shop-result">
                            <span class="gid-indicator-label"><strong>🏪 Negozio simulato</strong></span>
                            <span class="gid-indicator-value <?php echo $negozio_simulato >= 0 ? 'gid-positive' : 'gid-negative'; ?>">
                                <strong><?php echo number_format($negozio_simulato, 2, ',', '.'); ?> €</strong>
                            </span>
                        </div>
                    </div>

                    <!-- Box Confronto -->
                    <div class="gid-indicator-group gid-shop-comparison-box">
                        <h4>📈 Confronto con scenario attuale</h4>

                        <div class="gid-comparison-content">
                            <div class="gid-comparison-item">
                                <span class="gid-comparison-label">Incassi netti nominali (Glovo)</span>
                                <span class="gid-comparison-value"><?php echo number_format($incassi_netti_nominale, 2, ',', '.'); ?> €</span>
                            </div>
                            <div class="gid-comparison-item">
                                <span class="gid-comparison-label">Negozio simulato</span>
                                <span class="gid-comparison-value"><?php echo number_format($negozio_simulato, 2, ',', '.'); ?> €</span>
                            </div>
                            <div class="gid-comparison-item gid-comparison-difference">
                                <?php
                                $differenza = $negozio_simulato - $incassi_netti_nominale;
                                $percentuale_diff = ($incassi_netti_nominale != 0) ? (($differenza / $incassi_netti_nominale) * 100) : 0;
                                ?>
                                <span class="gid-comparison-label"><strong>Differenza</strong></span>
                                <span class="gid-comparison-value <?php echo $differenza >= 0 ? 'gid-positive' : 'gid-negative'; ?>">
                                    <strong>
                                        <?php echo ($differenza >= 0 ? '+' : '') . number_format($differenza, 2, ',', '.'); ?> €
                                        (<?php echo ($differenza >= 0 ? '+' : '') . number_format($percentuale_diff, 2); ?>%)
                                    </strong>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grafici -->
            <div class="gid-charts-section">
                <h3>Analisi Grafica</h3>

                <div class="gid-charts-grid">
                    <!-- Grafico Totale Prodotti per Mese -->
                    <div class="gid-chart-container">
                        <h4>Totale Prodotti Lordo per Mese</h4>
                        <div class="gid-chart">
                            <canvas id="gid-chart-monthly"></canvas>
                        </div>
                    </div>

                    <!-- Grafico Totale Prodotti per Negozio -->
                    <div class="gid-chart-container">
                        <h4>Totale Prodotti Lordo per Negozio (Top 10)</h4>
                        <div class="gid-chart">
                            <canvas id="gid-chart-stores"></canvas>
                        </div>
                    </div>

                    <!-- Grafico a Torta: Impatto effettivo Parziale -->
                    <div class="gid-chart-container">
                        <h4>Impatto effettivo Parziale</h4>
                        <div class="gid-chart">
                            <canvas id="gid-chart-impact-glovo"></canvas>
                        </div>
                    </div>

                    <!-- Grafico a Torta: Impatto effettivo Globale -->
                    <div class="gid-chart-container">
                        <h4>Impatto effettivo Globale</h4>
                        <div class="gid-chart">
                            <canvas id="gid-chart-impact-glovo-promo"></canvas>
                        </div>
                    </div>

                    <!-- Grafico a Torta: Impatto teorico Parziale -->
                    <div class="gid-chart-container">
                        <h4>Impatto teorico Parziale</h4>
                        <div class="gid-chart">
                            <canvas id="gid-chart-impact-glovo-real"></canvas>
                        </div>
                    </div>

                    <!-- Grafico a Torta: Impatto teorico Globale -->
                    <div class="gid-chart-container">
                        <h4>Impatto teorico Globale</h4>
                        <div class="gid-chart">
                            <canvas id="gid-chart-impact-glovo-promo-real"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Impatto effettivo Parziale -->
            <div class="gid-impact-section">
                <h3>Impatto effettivo Parziale</h3>
                <p class="gid-impact-subtitle">
                    Totale Prodotti Lordo: <strong><?php echo number_format($kpi['totale_prodotti'], 2, ',', '.'); ?> €</strong>
                </p>

                <div class="gid-impact-grid">
                    <?php
                    // Definiamo le voci da analizzare
                    $impact_items = array(
                        array(
                            'label' => 'Commissioni Glovo',
                            'value' => $kpi['totale_commissioni'],
                            'icon' => '💰',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Marketing e Visibilità',
                            'value' => $kpi['totale_marketing'],
                            'icon' => '📢',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Servizio Consegna',
                            'value' => $kpi['totale_servizio_consegna'],
                            'icon' => '🚚',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Buoni Pasto',
                            'value' => $kpi['totale_buoni_pasto'],
                            'icon' => '🍽️',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Costi Incidenti Prodotti',
                            'value' => $kpi['totale_costi_incidenti'],
                            'icon' => '⚠️',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Rimborsi Partner',
                            'value' => $kpi['totale_rimborsi'],
                            'icon' => '↩️',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Promozioni Prodotti Partner',
                            'value' => $kpi['totale_promo_partner'],
                            'icon' => '🎁',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Tariffa Tempo Attesa',
                            'value' => $kpi['totale_tariffa_attesa'],
                            'icon' => '⏱️',
                            'type' => 'revenue'
                        ),
                        array(
                            'label' => 'Costo Annullamenti Servizio',
                            'value' => $kpi['totale_costo_annullamenti'],
                            'icon' => '❌',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Consegna Gratuita Incidenti',
                            'value' => $kpi['totale_consegna_gratuita'],
                            'icon' => '🆓',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Supplemento Glovo Prime',
                            'value' => $kpi['totale_supplemento_glovo_prime'],
                            'icon' => '⭐',
                            'type' => 'revenue'
                        ),
                        array(
                            'label' => 'Promo Consegna Partner',
                            'value' => $kpi['totale_promo_consegna_partner'],
                            'icon' => '🚚',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Costi Offerta Lampo',
                            'value' => $kpi['totale_costi_offerta_lampo'],
                            'icon' => '⚡',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Promo Lampo Partner',
                            'value' => $kpi['totale_promo_lampo_partner'],
                            'icon' => '🔥',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Ordini Rimborsati Partner',
                            'value' => $kpi['totale_ordini_rimborsati_partner'],
                            'icon' => '↩️',
                            'type' => 'revenue'
                        ),
                        array(
                            'label' => 'Commissione Ordini Rimborsati',
                            'value' => $kpi['totale_commissione_ordini_rimborsati'],
                            'icon' => '💸',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'Sconto Commissione Buoni Pasto',
                            'value' => $kpi['totale_sconto_comm_buoni_pasto'],
                            'icon' => '🎫',
                            'type' => 'revenue'
                        ),
                        array(
                            'label' => 'Glovo Già Pagati',
                            'value' => $kpi['totale_glovo_gia_pagati'],
                            'icon' => '✅',
                            'type' => 'cost'
                        ),
                        array(
                            'label' => 'IVA 22%',
                            'value' => $kpi['totale_iva'],
                            'icon' => '🧾',
                            'type' => 'tax'
                        )
                    );

                    // Calcola percentuali e ordina per impatto
                    $base = $kpi['totale_prodotti'];
                    foreach ($impact_items as &$item) {
                        $item['percentage'] = ($base > 0) ? ($item['value'] / $base) * 100 : 0;
                    }
                    unset($item);

                    // Ordina per valore decrescente
                    usort($impact_items, function($a, $b) {
                        return $b['value'] <=> $a['value'];
                    });

                    foreach ($impact_items as $item):
                    ?>
                        <div class="gid-impact-item <?php echo 'gid-impact-' . $item['type']; ?>">
                            <div class="gid-impact-header">
                                <span class="gid-impact-icon"><?php echo $item['icon']; ?></span>
                                <span class="gid-impact-name"><?php echo $item['label']; ?></span>
                            </div>
                            <div class="gid-impact-values">
                                <div class="gid-impact-amount">
                                    <span class="gid-impact-amount-label">Valore</span>
                                    <span class="gid-impact-amount-value"><?php echo number_format($item['value'], 2, ',', '.'); ?> €</span>
                                </div>
                                <div class="gid-impact-percent">
                                    <span class="gid-impact-percent-label">% su Prodotti Lordo</span>
                                    <span class="gid-impact-percent-value"><?php echo number_format($item['percentage'], 2); ?>%</span>
                                </div>
                            </div>
                            <div class="gid-impact-bar">
                                <div class="gid-impact-bar-fill gid-impact-bar-<?php echo $item['type']; ?>"
                                     style="width: <?php echo min($item['percentage'], 100); ?>%">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Riepilogo Totali -->
                <div class="gid-impact-summary">
                    <?php
                    $impatto_glovo = $kpi['totale_commissioni'] + $kpi['totale_marketing'] + $kpi['totale_supplemento_glovo_prime'] +
                                    $kpi['totale_promo_consegna_partner'] + $kpi['totale_costi_offerta_lampo'] + $kpi['totale_promo_lampo_partner'] +
                                    $kpi['totale_costi_incidenti'];
                    $percentuale_impatto = ($kpi['totale_prodotti'] > 0) ? ($impatto_glovo / $kpi['totale_prodotti']) * 100 : 0;

                    $impatto_glovo_promo = $impatto_glovo + $kpi['totale_promo_partner'] + $kpi['totale_tariffa_attesa']
                                          + $kpi['totale_commissione_ordini_rimborsati']
                                          - $kpi['totale_ordini_rimborsati_partner']
                                          - $kpi['totale_sconto_comm_buoni_pasto'];
                    $percentuale_impatto_promo = ($kpi['totale_prodotti'] > 0) ? ($impatto_glovo_promo / $kpi['totale_prodotti']) * 100 : 0;
                    ?>
                    <div class="gid-impact-summary-item">
                        <span class="gid-impact-summary-label">📊 Impatto Glovo Parziale</span>
                        <span class="gid-impact-summary-value">
                            <?php echo number_format($impatto_glovo, 2, ',', '.'); ?> €
                            (<?php echo number_format($percentuale_impatto, 2); ?>%)
                        </span>
                    </div>
                    <div class="gid-impact-summary-item">
                        <span class="gid-impact-summary-label">🎁 Impatto Glovo Globale</span>
                        <span class="gid-impact-summary-value">
                            <?php echo number_format($impatto_glovo_promo, 2, ',', '.'); ?> €
                            (<?php echo number_format($percentuale_impatto_promo, 2); ?>%)
                        </span>
                    </div>
                </div>
            </div>

            <!-- Impatto teorico Parziale -->
            <div class="gid-impact-section">
                <h3>Impatto teorico Parziale (maggiorazione fissa 15% già sottratta)</h3>
                <p class="gid-impact-subtitle">
                    Totale Prodotti Lordo: <strong><?php echo number_format($kpi['totale_prodotti'], 2, ',', '.'); ?> €</strong>
                    <br>
                    <span style="color: #28a745;">Maggiorazione 15% sottratta dai costi Glovo: <strong><?php echo number_format($kpi['totale_prodotti'] - (($kpi['totale_prodotti'] / (100 + $percentuale_scorporo_default)) * 100), 2, ',', '.'); ?> €</strong></span>
                </p>

                <div class="gid-impact-grid">
                    <?php
                    // Definiamo le voci da analizzare (identiche alla sezione effettivo)
                    $impact_items_real = array(
                        array(
                            'label' => 'Commissioni Glovo',
                            'value' => $kpi['totale_commissioni'],
                            'icon' => '💰',
                            'type' => 'cost',
                            'key' => 'totale_commissioni'
                        ),
                        array(
                            'label' => 'Marketing e Visibilità',
                            'value' => $kpi['totale_marketing'],
                            'icon' => '📢',
                            'type' => 'cost',
                            'key' => 'totale_marketing'
                        ),
                        array(
                            'label' => 'Servizio Consegna',
                            'value' => $kpi['totale_servizio_consegna'],
                            'icon' => '🚚',
                            'type' => 'cost',
                            'key' => 'totale_servizio_consegna'
                        ),
                        array(
                            'label' => 'Buoni Pasto',
                            'value' => $kpi['totale_buoni_pasto'],
                            'icon' => '🍽️',
                            'type' => 'cost',
                            'key' => 'totale_buoni_pasto'
                        ),
                        array(
                            'label' => 'Costi Incidenti Prodotti',
                            'value' => $kpi['totale_costi_incidenti'],
                            'icon' => '⚠️',
                            'type' => 'cost',
                            'key' => 'totale_costi_incidenti'
                        ),
                        array(
                            'label' => 'Rimborsi Partner',
                            'value' => $kpi['totale_rimborsi'],
                            'icon' => '↩️',
                            'type' => 'cost',
                            'key' => 'totale_rimborsi'
                        ),
                        array(
                            'label' => 'Promozioni Prodotti Partner',
                            'value' => $kpi['totale_promo_partner'],
                            'icon' => '🎁',
                            'type' => 'cost',
                            'key' => 'totale_promo_partner'
                        ),
                        array(
                            'label' => 'Tariffa Tempo Attesa',
                            'value' => $kpi['totale_tariffa_attesa'],
                            'icon' => '⏱️',
                            'type' => 'revenue',
                            'key' => 'totale_tariffa_attesa'
                        ),
                        array(
                            'label' => 'Costo Annullamenti Servizio',
                            'value' => $kpi['totale_costo_annullamenti'],
                            'icon' => '❌',
                            'type' => 'cost',
                            'key' => 'totale_costo_annullamenti'
                        ),
                        array(
                            'label' => 'Consegna Gratuita Incidenti',
                            'value' => $kpi['totale_consegna_gratuita'],
                            'icon' => '🆓',
                            'type' => 'cost',
                            'key' => 'totale_consegna_gratuita'
                        ),
                        array(
                            'label' => 'Supplemento Glovo Prime',
                            'value' => $kpi['totale_supplemento_glovo_prime'],
                            'icon' => '⭐',
                            'type' => 'revenue',
                            'key' => 'totale_supplemento_glovo_prime'
                        ),
                        array(
                            'label' => 'Promo Consegna Partner',
                            'value' => $kpi['totale_promo_consegna_partner'],
                            'icon' => '🚚',
                            'type' => 'cost',
                            'key' => 'totale_promo_consegna_partner'
                        ),
                        array(
                            'label' => 'Costi Offerta Lampo',
                            'value' => $kpi['totale_costi_offerta_lampo'],
                            'icon' => '⚡',
                            'type' => 'cost',
                            'key' => 'totale_costi_offerta_lampo'
                        ),
                        array(
                            'label' => 'Promo Lampo Partner',
                            'value' => $kpi['totale_promo_lampo_partner'],
                            'icon' => '🔥',
                            'type' => 'cost',
                            'key' => 'totale_promo_lampo_partner'
                        ),
                        array(
                            'label' => 'Ordini Rimborsati Partner',
                            'value' => $kpi['totale_ordini_rimborsati_partner'],
                            'icon' => '↩️',
                            'type' => 'revenue',
                            'key' => 'totale_ordini_rimborsati_partner'
                        ),
                        array(
                            'label' => 'Commissione Ordini Rimborsati',
                            'value' => $kpi['totale_commissione_ordini_rimborsati'],
                            'icon' => '💸',
                            'type' => 'cost',
                            'key' => 'totale_commissione_ordini_rimborsati'
                        ),
                        array(
                            'label' => 'Sconto Commissione Buoni Pasto',
                            'value' => $kpi['totale_sconto_comm_buoni_pasto'],
                            'icon' => '🎫',
                            'type' => 'revenue',
                            'key' => 'totale_sconto_comm_buoni_pasto'
                        ),
                        array(
                            'label' => 'Glovo Già Pagati',
                            'value' => $kpi['totale_glovo_gia_pagati'],
                            'icon' => '✅',
                            'type' => 'cost',
                            'key' => 'totale_glovo_gia_pagati'
                        ),
                        array(
                            'label' => 'IVA 22%',
                            'value' => $kpi['totale_iva'],
                            'icon' => '🧾',
                            'type' => 'tax',
                            'key' => 'totale_iva'
                        )
                    );

                    // Calcola riduzione (scorporo) e impatto totale per distribuzione proporzionale
                    $riduzione_15 = $kpi['totale_prodotti'] - (($kpi['totale_prodotti'] / (100 + $percentuale_scorporo_default)) * 100);

                    // Calcola il totale di tutte le voci (impatto_glovo)
                    $totale_impatto = $kpi['totale_commissioni'] + $kpi['totale_marketing'] +
                                     $kpi['totale_servizio_consegna'] + $kpi['totale_buoni_pasto'] +
                                     $kpi['totale_costi_incidenti'] + $kpi['totale_rimborsi'] +
                                     $kpi['totale_promo_partner'] + $kpi['totale_tariffa_attesa'] +
                                     $kpi['totale_costo_annullamenti'] + $kpi['totale_consegna_gratuita'] +
                                     $kpi['totale_supplemento_glovo_prime'] + $kpi['totale_glovo_gia_pagati'] +
                                     $kpi['totale_promo_consegna_partner'] + $kpi['totale_costi_offerta_lampo'] +
                                     $kpi['totale_promo_lampo_partner'] +
                                     $kpi['totale_ordini_rimborsati_partner'] +
                                     $kpi['totale_commissione_ordini_rimborsati'] +
                                     $kpi['totale_sconto_comm_buoni_pasto'] +
                                     $kpi['totale_iva'];

                    // Calcola percentuali: sottrai riduzione proporzionale da ogni voce, dividi per totale_prodotti
                    $base_real = $kpi['totale_prodotti'];
                    foreach ($impact_items_real as &$item) {
                        // Calcola la quota proporzionale di riduzione per questa voce
                        $riduzione_share = ($totale_impatto > 0) ? ($riduzione_15 * ($item['value'] / $totale_impatto)) : 0;
                        // Valore teorico = valore originale - quota di riduzione
                        $valore_teorico = $item['value'] - $riduzione_share;
                        // Percentuale = (valore_teorico / totale_prodotti) * 100
                        $item['percentage'] = ($base_real > 0) ? ($valore_teorico / $base_real) * 100 : 0;
                    }
                    unset($item);

                    // Ordina per valore decrescente
                    usort($impact_items_real, function($a, $b) {
                        return $b['value'] <=> $a['value'];
                    });

                    foreach ($impact_items_real as $item):
                    ?>
                        <div class="gid-impact-item <?php echo 'gid-impact-' . $item['type']; ?>" data-impact-key="<?php echo $item['key']; ?>">
                            <div class="gid-impact-header">
                                <span class="gid-impact-icon"><?php echo $item['icon']; ?></span>
                                <span class="gid-impact-name"><?php echo $item['label']; ?></span>
                            </div>
                            <div class="gid-impact-values">
                                <div class="gid-impact-amount">
                                    <span class="gid-impact-amount-label">Valore</span>
                                    <span class="gid-impact-amount-value"><?php echo number_format($item['value'], 2, ',', '.'); ?> €</span>
                                </div>
                                <div class="gid-impact-percent">
                                    <span class="gid-impact-percent-label">% su Vendite Reali</span>
                                    <span class="gid-impact-percent-value"><?php echo number_format($item['percentage'], 2); ?>%</span>
                                </div>
                            </div>
                            <div class="gid-impact-bar">
                                <div class="gid-impact-bar-fill gid-impact-bar-<?php echo $item['type']; ?>"
                                     style="width: <?php echo min($item['percentage'], 100); ?>%">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Riepilogo Totali con riduzione -->
                <div class="gid-impact-summary">
                    <?php
                    // Calcola la riduzione scorporando la percentuale: totale - (totale / (100 + perc) * 100)
                    $riduzione_15 = $kpi['totale_prodotti'] - (($kpi['totale_prodotti'] / (100 + $percentuale_scorporo_default)) * 100);

                    // Impatto Glovo teorico: sottrae la riduzione
                    $impatto_glovo_real = $impatto_glovo - $riduzione_15;
                    $percentuale_impatto_real = ($kpi['totale_prodotti'] > 0) ? ($impatto_glovo_real / $kpi['totale_prodotti']) * 100 : 0;

                    $impatto_glovo_promo_real = $impatto_glovo_promo - $riduzione_15;
                    $percentuale_impatto_promo_real = ($kpi['totale_prodotti'] > 0) ? ($impatto_glovo_promo_real / $kpi['totale_prodotti']) * 100 : 0;
                    ?>
                    <div class="gid-impact-summary-item">
                        <span class="gid-impact-summary-label">📊 Impatto Glovo Teorico Parziale (maggiorazione 15% sottratta)</span>
                        <span class="gid-impact-summary-value">
                            <?php echo number_format($impatto_glovo_real, 2, ',', '.'); ?> €
                            (<?php echo number_format($percentuale_impatto_real, 2); ?>%)
                        </span>
                    </div>
                    <div class="gid-impact-summary-item">
                        <span class="gid-impact-summary-label">🎁 Impatto Glovo Teorico Globale (maggiorazione 15% sottratta)</span>
                        <span class="gid-impact-summary-value">
                            <?php echo number_format($impatto_glovo_promo_real, 2, ',', '.'); ?> €
                            (<?php echo number_format($percentuale_impatto_promo_real, 2); ?>%)
                        </span>
                    </div>
                </div>
            </div>

            <!-- Simulatore Maggiorazione -->
            <div class="gid-impact-section gid-simulator-section">
                <h3>🎮 Simulatore Maggiorazione</h3>
                <p class="gid-impact-subtitle">
                    Simula cosa succederebbe applicando una maggiorazione diversa sui prezzi Glovo per vedere quale sarebbe l'impatto teorico dei costi
                </p>

                <!-- Campo per percentuale maggiorazione dinamica -->
                <div class="gid-scorporo-control">
                    <label for="gid-percentuale-simulatore">Percentuale maggiorazione per simulazione:</label>
                    <div class="gid-scorporo-input-wrapper">
                        <input type="number" id="gid-percentuale-simulatore"
                               value="15" min="0" max="50" step="0.5"
                               class="gid-scorporo-input">
                        <span class="gid-scorporo-suffix">%</span>
                    </div>
                    <small class="gid-scorporo-help">Modifica la percentuale per simulare diversi scenari di maggiorazione (default: 15%)</small>
                </div>

                <?php
                // Calcoli iniziali per simulatore (con 15% di default)
                $percentuale_sim_default = 15;
                $maggiorazione_simulata = $kpi['totale_prodotti'] - (($kpi['totale_prodotti'] / (100 + $percentuale_sim_default)) * 100);

                // Costi Glovo nominali (somma di tutte le voci Glovo)
                $costi_glovo_nominali = $kpi['totale_commissioni'] +
                                       $kpi['totale_marketing'] +
                                       $kpi['totale_supplemento_glovo_prime'] +
                                       $kpi['totale_promo_consegna_partner'] +
                                       $kpi['totale_costi_offerta_lampo'] +
                                       $kpi['totale_promo_lampo_partner'];

                // Costi Glovo simulati = costi nominali - maggiorazione
                $costi_glovo_simulati = $costi_glovo_nominali - $maggiorazione_simulata;
                $impatto_simulato_perc = ($kpi['totale_prodotti'] > 0) ? ($costi_glovo_simulati / $kpi['totale_prodotti']) * 100 : 0;

                // Calcola percentuale necessaria per azzerare i costi
                $perc_azzeramento = ($kpi['totale_prodotti'] > 0 && ($kpi['totale_prodotti'] - $costi_glovo_nominali) > 0)
                    ? ((($kpi['totale_prodotti'] / ($kpi['totale_prodotti'] - $costi_glovo_nominali)) - 1) * 100)
                    : 0;

                // Confronto con impatto teorico al 15% (Simulato - Teorico)
                $differenza_impatto = $impatto_simulato_perc - $percentuale_impatto_real;

                // Versione Globale (+ Promo Partner + Tariffa Attesa)
                $costi_glovo_simulati_promo = $costi_glovo_simulati + $kpi['totale_promo_partner'] + $kpi['totale_tariffa_attesa']
                                             + $kpi['totale_commissione_ordini_rimborsati']
                                             - $kpi['totale_ordini_rimborsati_partner']
                                             - $kpi['totale_sconto_comm_buoni_pasto'];
                $impatto_simulato_promo_perc = ($kpi['totale_prodotti'] > 0) ? ($costi_glovo_simulati_promo / $kpi['totale_prodotti']) * 100 : 0;

                // Confronto anche per versione + Promo (Simulato - Teorico)
                $differenza_impatto_promo = $impatto_simulato_promo_perc - $percentuale_impatto_promo_real;
                ?>

                <div class="gid-simulator-results">
                    <div class="gid-indicators-grid">
                        <!-- Maggiorazione Calcolata -->
                        <div class="gid-indicator-group">
                            <h4>💰 Maggiorazione Applicata</h4>
                            <div class="gid-indicator-item gid-indicator-total">
                                <span class="gid-indicator-label"><strong>Maggiorazione <span id="gid-sim-perc-label"><?php echo $percentuale_sim_default; ?></span>%</strong></span>
                                <span class="gid-indicator-value gid-positive">
                                    <strong><span id="gid-sim-maggiorazione"><?php echo number_format($maggiorazione_simulata, 2, ',', '.'); ?></span> €</strong>
                                </span>
                            </div>
                            <div class="gid-indicator-item">
                                <span class="gid-indicator-label">Totale Prodotti Glovo</span>
                                <span class="gid-indicator-value">
                                    <?php echo number_format($kpi['totale_prodotti'], 2, ',', '.'); ?> €
                                </span>
                            </div>
                            <div class="gid-indicator-item">
                                <span class="gid-indicator-label">Costi Glovo Effettivi Parziali</span>
                                <span class="gid-indicator-value gid-negative">
                                    <?php echo number_format($costi_glovo_nominali, 2, ',', '.'); ?> €
                                    <small>(<?php echo number_format(($costi_glovo_nominali / $kpi['totale_prodotti']) * 100, 2); ?>%)</small>
                                </span>
                            </div>
                            <div class="gid-indicator-item gid-indicator-separator">
                                <span class="gid-indicator-label">Costi Glovo Effettivi Globali</span>
                                <span class="gid-indicator-value gid-negative">
                                    <?php
                                    $costi_nominali_promo = $costi_glovo_nominali + $kpi['totale_promo_partner'] + $kpi['totale_tariffa_attesa']
                                                             + $kpi['totale_commissione_ordini_rimborsati']
                                                             - $kpi['totale_ordini_rimborsati_partner']
                                                             - $kpi['totale_sconto_comm_buoni_pasto'];
                                    echo number_format($costi_nominali_promo, 2, ',', '.');
                                    ?> €
                                    <small>(<?php echo number_format(($costi_nominali_promo / $kpi['totale_prodotti']) * 100, 2); ?>%)</small>
                                </span>
                            </div>
                        </div>

                        <!-- Impatto Simulato -->
                        <div class="gid-indicator-group">
                            <h4>📊 Impatto Simulato</h4>

                            <!-- Solo Glovo -->
                            <div class="gid-indicator-item">
                                <span class="gid-indicator-label">Costi Glovo Simulati Parziali</span>
                                <span class="gid-indicator-value gid-negative">
                                    <strong><span id="gid-sim-costi-glovo"><?php echo number_format($costi_glovo_simulati, 2, ',', '.'); ?></span> €</strong>
                                    <small>(<span id="gid-sim-impatto-perc"><?php echo number_format($impatto_simulato_perc, 2); ?></span>%)</small>
                                </span>
                            </div>

                            <!-- Glovo Globale -->
                            <div class="gid-indicator-item gid-indicator-separator">
                                <span class="gid-indicator-label">Costi Glovo Simulati Globali</span>
                                <span class="gid-indicator-value gid-negative">
                                    <strong><span id="gid-sim-costi-glovo-promo"><?php echo number_format($costi_glovo_simulati_promo, 2, ',', '.'); ?></span> €</strong>
                                    <small>(<span id="gid-sim-impatto-promo-perc"><?php echo number_format($impatto_simulato_promo_perc, 2); ?></span>%)</small>
                                </span>
                            </div>

                            <div class="gid-indicator-item">
                                <span class="gid-indicator-label">Voci fisse Globali (non simulate)</span>
                                <span class="gid-indicator-value">
                                    <?php
                                    $voci_fisse_globali = $kpi['totale_promo_partner'] + $kpi['totale_tariffa_attesa']
                                                          + $kpi['totale_commissione_ordini_rimborsati']
                                                          - $kpi['totale_ordini_rimborsati_partner']
                                                          - $kpi['totale_sconto_comm_buoni_pasto'];
                                    echo number_format($voci_fisse_globali, 2, ',', '.');
                                    ?> €
                                </span>
                            </div>
                        </div>

                        <!-- Confronto con Teorico -->
                        <div class="gid-indicator-group">
                            <h4>🔄 Confronto con Impatto Teorico (15%)</h4>

                            <!-- Parziale -->
                            <div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #ddd;">
                                <div class="gid-indicator-item">
                                    <span class="gid-indicator-label"><strong>Parziale</strong></span>
                                </div>
                                <div class="gid-indicator-item">
                                    <span class="gid-indicator-label">Impatto Teorico Parziale (15%)</span>
                                    <span class="gid-indicator-value">
                                        <?php echo number_format($percentuale_impatto_real, 2); ?>%
                                    </span>
                                </div>
                                <div class="gid-indicator-item">
                                    <span class="gid-indicator-label">Impatto Simulato (<span id="gid-sim-perc-label-2"><?php echo $percentuale_sim_default; ?></span>%)</span>
                                    <span class="gid-indicator-value">
                                        <span id="gid-sim-impatto-perc-2"><?php echo number_format($impatto_simulato_perc, 2); ?></span>%
                                    </span>
                                </div>
                                <div class="gid-indicator-item gid-indicator-separator">
                                    <span class="gid-indicator-label"><strong>Differenza (Simulato - Teorico)</strong></span>
                                    <span class="gid-indicator-value <?php echo $differenza_impatto < 0 ? 'gid-positive' : 'gid-negative'; ?>" id="gid-sim-differenza-wrapper">
                                        <strong><span id="gid-sim-differenza"><?php echo ($differenza_impatto >= 0 ? '+' : '') . number_format($differenza_impatto, 2); ?></span>%</strong>
                                        <small id="gid-sim-differenza-text">(<?php echo $differenza_impatto < 0 ? 'Miglioramento' : 'Peggioramento'; ?>)</small>
                                    </span>
                                </div>
                            </div>

                            <!-- Globale -->
                            <div>
                                <div class="gid-indicator-item">
                                    <span class="gid-indicator-label"><strong>Globale</strong></span>
                                </div>
                                <div class="gid-indicator-item">
                                    <span class="gid-indicator-label">Impatto Teorico Globale (15%)</span>
                                    <span class="gid-indicator-value">
                                        <?php echo number_format($percentuale_impatto_promo_real, 2); ?>%
                                    </span>
                                </div>
                                <div class="gid-indicator-item">
                                    <span class="gid-indicator-label">Impatto Simulato (<span id="gid-sim-perc-label-3"><?php echo $percentuale_sim_default; ?></span>%)</span>
                                    <span class="gid-indicator-value">
                                        <span id="gid-sim-impatto-promo-perc-2"><?php echo number_format($impatto_simulato_promo_perc, 2); ?></span>%
                                    </span>
                                </div>
                                <div class="gid-indicator-item gid-indicator-separator">
                                    <span class="gid-indicator-label"><strong>Differenza (Simulato - Teorico)</strong></span>
                                    <span class="gid-indicator-value <?php echo $differenza_impatto_promo < 0 ? 'gid-positive' : 'gid-negative'; ?>" id="gid-sim-differenza-promo-wrapper">
                                        <strong><span id="gid-sim-differenza-promo"><?php echo ($differenza_impatto_promo >= 0 ? '+' : '') . number_format($differenza_impatto_promo, 2); ?></span>%</strong>
                                        <small id="gid-sim-differenza-promo-text">(<?php echo $differenza_impatto_promo < 0 ? 'Miglioramento' : 'Peggioramento'; ?>)</small>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Analisi e Suggerimenti -->
                        <div class="gid-indicator-group">
                            <h4>💡 Analisi</h4>
                            <div class="gid-indicator-item gid-indicator-info">
                                <span class="gid-indicator-label">Percentuale per azzerare i costi</span>
                                <span class="gid-indicator-value">
                                    <strong><?php echo number_format($perc_azzeramento, 2); ?>%</strong>
                                </span>
                            </div>
                            <div class="gid-indicator-item">
                                <span class="gid-indicator-label" style="font-size: 0.9em; color: #666;">
                                    <span id="gid-sim-messaggio">
                                        <?php if ($impatto_simulato_perc > 0): ?>
                                            Con una maggiorazione del <?php echo $percentuale_sim_default; ?>%, i costi Glovo rimangono positivi.
                                            Serve una maggiorazione di almeno <?php echo number_format($perc_azzeramento, 2); ?>% per coprire tutti i costi.
                                        <?php elseif ($impatto_simulato_perc == 0): ?>
                                            Con una maggiorazione del <?php echo $percentuale_sim_default; ?>%, i costi Glovo sono completamente coperti!
                                        <?php else: ?>
                                            Con una maggiorazione del <?php echo $percentuale_sim_default; ?>%, stai guadagnando di più della maggiorazione applicata!
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabella Fatture con Alto Impatto Glovo -->
            <?php if (!empty($high_impact_invoices)): ?>
            <div class="gid-high-impact-section">
                <div class="gid-section-header">
                    <h3>Dettaglio Impatto Glovo per Fattura</h3>
                    <div class="gid-section-header-actions">
                        <select id="gid-filter-alert-level" class="gid-alert-filter">
                            <option value="all">Tutti i livelli</option>
                            <option value="critico">🔴 Solo Critiche (> 28%)</option>
                            <option value="attenzione">🟠 Solo Attenzione (25-28%)</option>
                            <option value="normale">🟢 Solo Normali (< 25%)</option>
                        </select>
                        <button class="gid-btn gid-btn-secondary gid-toggle-table" id="gid-toggle-high-impact">
                            <span class="gid-toggle-icon">▼</span> Mostra Dettagli
                        </button>
                    </div>
                </div>

                <div class="gid-collapsible-table" id="gid-high-impact-table" style="display: none;">
                    <div class="gid-table-responsive">
                        <table class="gid-impact-table">
                            <thead>
                                <tr>
                                    <th>Stato</th>
                                    <th>Numero Fattura</th>
                                    <th>Data</th>
                                    <th>Negozio</th>
                                    <th>Destinatario</th>
                                    <th>Totale Prodotti Lordo</th>
                                    <th>Impatto Glovo</th>
                                    <th>% Impatto</th>
                                    <th>Glovo + Promo</th>
                                    <th>% Impatto + Promo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($high_impact_invoices as $invoice): ?>
                                <tr class="gid-row-<?php echo $invoice['livello_allerta']; ?>">
                                    <td>
                                        <?php if ($invoice['livello_allerta'] == 'critico'): ?>
                                            <span class="gid-badge gid-badge-danger">🔴 Critico</span>
                                        <?php elseif ($invoice['livello_allerta'] == 'attenzione'): ?>
                                            <span class="gid-badge gid-badge-warning">🟠 Attenzione</span>
                                        <?php else: ?>
                                            <span class="gid-badge gid-badge-success">🟢 Normale</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($invoice['numero_fattura']); ?></td>
                                    <td><?php echo esc_html(date('d/m/Y', strtotime($invoice['data']))); ?></td>
                                    <td><?php echo esc_html($invoice['negozio']); ?></td>
                                    <td><?php echo esc_html($invoice['destinatario']); ?></td>
                                    <td class="gid-text-right"><?php echo number_format($invoice['totale_prodotti'], 2, ',', '.'); ?> €</td>
                                    <td class="gid-text-right"><?php echo number_format($invoice['impatto_glovo'], 2, ',', '.'); ?> €</td>
                                    <td class="gid-text-right gid-text-bold">
                                        <?php
                                        $percentage_class = 'gid-percentage-success';
                                        if ($invoice['livello_allerta'] == 'critico') {
                                            $percentage_class = 'gid-percentage-danger';
                                        } elseif ($invoice['livello_allerta'] == 'attenzione') {
                                            $percentage_class = 'gid-percentage-warning';
                                        }
                                        ?>
                                        <span class="gid-percentage <?php echo $percentage_class; ?>">
                                            <?php echo number_format($invoice['percentuale_impatto'], 2); ?>%
                                        </span>
                                    </td>
                                    <td class="gid-text-right"><?php echo number_format($invoice['impatto_glovo_promo'], 2, ',', '.'); ?> €</td>
                                    <td class="gid-text-right">
                                        <?php
                                        $percentage_promo_class = 'gid-percentage-success';
                                        if ($invoice['percentuale_impatto_promo'] > 28) {
                                            $percentage_promo_class = 'gid-percentage-danger';
                                        } elseif ($invoice['percentuale_impatto_promo'] >= 25) {
                                            $percentage_promo_class = 'gid-percentage-warning';
                                        }
                                        ?>
                                        <span class="gid-percentage <?php echo $percentage_promo_class; ?>">
                                            <?php echo number_format($invoice['percentuale_impatto_promo'], 2); ?>%
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="gid-table-summary">
                        <p>
                            <strong>Totale fatture visualizzate:</strong> <?php echo count($high_impact_invoices); ?>
                            (<?php echo $alert_counts['critico']; ?> critiche, <?php echo $alert_counts['attenzione']; ?> che richiedono attenzione, <?php echo $alert_counts['normale']; ?> normali)
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Analisi Percentuale con Istogrammi -->
            <div class="gid-summary-section">
                <h3>Analisi Percentuale - Vista Comparativa</h3>
                <p class="gid-summary-subtitle">Confronto visivo dell'incidenza delle voci principali sul totale prodotti</p>

                <div class="gid-summary-grid">
                    <?php
                    // Definiamo le voci principali da visualizzare
                    $percentages = array(
                        array(
                            'label' => 'Commissioni Glovo',
                            'value' => $kpi['totale_commissioni'],
                            'percentage' => ($kpi['totale_prodotti'] > 0) ? ($kpi['totale_commissioni'] / $kpi['totale_prodotti']) * 100 : 0,
                            'color' => 'primary'
                        ),
                        array(
                            'label' => 'Marketing e Visibilità',
                            'value' => $kpi['totale_marketing'],
                            'percentage' => ($kpi['totale_prodotti'] > 0) ? ($kpi['totale_marketing'] / $kpi['totale_prodotti']) * 100 : 0,
                            'color' => 'danger'
                        ),
                        array(
                            'label' => 'Servizio Consegna',
                            'value' => $kpi['totale_servizio_consegna'],
                            'percentage' => ($kpi['totale_prodotti'] > 0) ? ($kpi['totale_servizio_consegna'] / $kpi['totale_prodotti']) * 100 : 0,
                            'color' => 'info'
                        ),
                        array(
                            'label' => 'Buoni Pasto',
                            'value' => $kpi['totale_buoni_pasto'],
                            'percentage' => ($kpi['totale_prodotti'] > 0) ? ($kpi['totale_buoni_pasto'] / $kpi['totale_prodotti']) * 100 : 0,
                            'color' => 'warning'
                        ),
                        array(
                            'label' => 'Costi Incidenti',
                            'value' => $kpi['totale_costi_incidenti'],
                            'percentage' => ($kpi['totale_prodotti'] > 0) ? ($kpi['totale_costi_incidenti'] / $kpi['totale_prodotti']) * 100 : 0,
                            'color' => 'secondary'
                        ),
                        array(
                            'label' => 'Rimborsi Partner',
                            'value' => $kpi['totale_rimborsi'],
                            'percentage' => ($kpi['totale_prodotti'] > 0) ? ($kpi['totale_rimborsi'] / $kpi['totale_prodotti']) * 100 : 0,
                            'color' => 'success'
                        ),
                        array(
                            'label' => 'Promozioni Prodotti Partner',
                            'value' => $kpi['totale_promo_partner'],
                            'percentage' => ($kpi['totale_prodotti'] > 0) ? ($kpi['totale_promo_partner'] / $kpi['totale_prodotti']) * 100 : 0,
                            'color' => 'purple'
                        ),
                        array(
                            'label' => 'Tariffa Tempo Attesa',
                            'value' => $kpi['totale_tariffa_attesa'],
                            'percentage' => ($kpi['totale_prodotti'] > 0) ? ($kpi['totale_tariffa_attesa'] / $kpi['totale_prodotti']) * 100 : 0,
                            'color' => 'info'
                        ),
                        array(
                            'label' => 'Promo Consegna Partner',
                            'value' => $kpi['totale_promo_consegna_partner'],
                            'percentage' => ($kpi['totale_prodotti'] > 0) ? ($kpi['totale_promo_consegna_partner'] / $kpi['totale_prodotti']) * 100 : 0,
                            'color' => 'danger'
                        ),
                        array(
                            'label' => 'Costi Offerta Lampo',
                            'value' => $kpi['totale_costi_offerta_lampo'],
                            'percentage' => ($kpi['totale_prodotti'] > 0) ? ($kpi['totale_costi_offerta_lampo'] / $kpi['totale_prodotti']) * 100 : 0,
                            'color' => 'warning'
                        ),
                        array(
                            'label' => 'Promo Lampo Partner',
                            'value' => $kpi['totale_promo_lampo_partner'],
                            'percentage' => ($kpi['totale_prodotti'] > 0) ? ($kpi['totale_promo_lampo_partner'] / $kpi['totale_prodotti']) * 100 : 0,
                            'color' => 'purple'
                        )
                    );

                    // Ordina per percentuale decrescente
                    usort($percentages, function($a, $b) {
                        return $b['percentage'] <=> $a['percentage'];
                    });

                    foreach ($percentages as $item):
                        if ($item['value'] == 0) continue; // Salta voci a zero
                    ?>
                        <div class="gid-percentage-bar">
                            <div class="gid-percentage-header">
                                <span class="gid-percentage-name"><?php echo $item['label']; ?></span>
                                <span class="gid-percentage-stats">
                                    <span class="gid-percentage-amount"><?php echo number_format($item['value'], 2, ',', '.'); ?> €</span>
                                    <span class="gid-percentage-value"><?php echo number_format($item['percentage'], 2); ?>%</span>
                                </span>
                            </div>
                            <div class="gid-percentage-progress">
                                <div class="gid-percentage-fill gid-percentage-fill-<?php echo $item['color']; ?>"
                                     style="width: <?php echo min($item['percentage'], 100); ?>%"
                                     data-percentage="<?php echo number_format($item['percentage'], 2); ?>">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Impatto Glovo Teorico per Negozio -->
            <div class="gid-summary-section">
                <h3>Impatto Glovo Teorico per Negozio (maggiorazione fissa 15%)</h3>
                <p class="gid-summary-subtitle">Confronto dell'impatto teorico di Glovo per ogni negozio con maggiorazione 15% già sottratta (ordinato dal più alto al più basso)</p>

                <div class="gid-store-impact-container" id="gid-store-impact-container">
                    <?php
                    if (!empty($store_impact_data)):
                        $store_index = 0;
                        foreach ($store_impact_data as $store):
                            // Determina il colore in base alla percentuale
                            $color_class_real = 'success';
                            if ($store['percentuale_impatto_real'] >= 28) {
                                $color_class_real = 'danger';
                            } elseif ($store['percentuale_impatto_real'] >= 25) {
                                $color_class_real = 'warning';
                            }

                            $color_class_promo = 'success';
                            if ($store['percentuale_impatto_promo_real'] >= 28) {
                                $color_class_promo = 'danger';
                            } elseif ($store['percentuale_impatto_promo_real'] >= 25) {
                                $color_class_promo = 'warning';
                            }
                    ?>
                        <div class="gid-store-impact-item" data-store-index="<?php echo $store_index; ?>">
                            <div class="gid-store-name">
                                <strong><?php echo esc_html($store['negozio']); ?></strong>
                                <span class="gid-store-products">Prodotti Lordo: <?php echo number_format($store['totale_prodotti'], 2, ',', '.'); ?> €</span>
                            </div>

                            <div class="gid-store-impact-bars">
                                <!-- Impatto Glovo Teorico Parziale -->
                                <div class="gid-store-bar-row">
                                    <div class="gid-store-bar-label">
                                        <span class="gid-store-bar-name">Impatto Glovo Teorico Parziale</span>
                                        <span class="gid-store-bar-value">
                                            <span class="gid-store-impatto-real"><?php echo number_format($store['impatto_glovo_real'], 2, ',', '.'); ?></span> €
                                            <strong class="gid-store-perc-real gid-percentage-<?php echo $color_class_real; ?>">
                                                (<span class="gid-store-perc-real-value"><?php echo number_format($store['percentuale_impatto_real'], 2); ?></span>%)
                                            </strong>
                                        </span>
                                    </div>
                                    <div class="gid-store-bar-progress">
                                        <div class="gid-store-bar-fill gid-store-bar-fill-real gid-store-bar-fill-<?php echo $color_class_real; ?>"
                                             style="width: <?php echo min($store['percentuale_impatto_real'], 100); ?>%">
                                        </div>
                                    </div>
                                </div>

                                <!-- Impatto Glovo Teorico Globale -->
                                <div class="gid-store-bar-row">
                                    <div class="gid-store-bar-label">
                                        <span class="gid-store-bar-name">Impatto Glovo Teorico Globale</span>
                                        <span class="gid-store-bar-value">
                                            <span class="gid-store-impatto-promo-real"><?php echo number_format($store['impatto_glovo_promo_real'], 2, ',', '.'); ?></span> €
                                            <strong class="gid-store-perc-promo-real gid-percentage-<?php echo $color_class_promo; ?>">
                                                (<span class="gid-store-perc-promo-real-value"><?php echo number_format($store['percentuale_impatto_promo_real'], 2); ?></span>%)
                                            </strong>
                                        </span>
                                    </div>
                                    <div class="gid-store-bar-progress">
                                        <div class="gid-store-bar-fill gid-store-bar-fill-promo-real gid-store-bar-fill-<?php echo $color_class_promo; ?>"
                                             style="width: <?php echo min($store['percentuale_impatto_promo_real'], 100); ?>%">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php
                            $store_index++;
                        endforeach;
                    else:
                    ?>
                        <p class="gid-no-data">Nessun dato disponibile per i negozi.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
            var gidChartData = <?php echo json_encode($chart_data); ?>;
            var gidImpactData = {
                totale_prodotti: <?php echo $kpi['totale_prodotti']; ?>,
                impatto_glovo: <?php echo $impatto_glovo; ?>,
                percentuale_impatto: <?php echo $percentuale_impatto; ?>,
                impatto_glovo_promo: <?php echo $impatto_glovo_promo; ?>,
                percentuale_impatto_promo: <?php echo $percentuale_impatto_promo; ?>,
                // Dati per l'impatto teorico (con riduzione scorporata)
                percentuale_scorporo: <?php echo $percentuale_scorporo_default; ?>,
                riduzione_scorporo: <?php echo $kpi['totale_prodotti'] - (($kpi['totale_prodotti'] / (100 + $percentuale_scorporo_default)) * 100); ?>,
                impatto_glovo_real: <?php echo $impatto_glovo - ($kpi['totale_prodotti'] - (($kpi['totale_prodotti'] / (100 + $percentuale_scorporo_default)) * 100)); ?>,
                percentuale_impatto_real: <?php echo ($kpi['totale_prodotti'] > 0) ? (($impatto_glovo - ($kpi['totale_prodotti'] - (($kpi['totale_prodotti'] / (100 + $percentuale_scorporo_default)) * 100))) / $kpi['totale_prodotti']) * 100 : 0; ?>,
                impatto_glovo_promo_real: <?php echo $impatto_glovo_promo - ($kpi['totale_prodotti'] - (($kpi['totale_prodotti'] / (100 + $percentuale_scorporo_default)) * 100)); ?>,
                percentuale_impatto_promo_real: <?php echo ($kpi['totale_prodotti'] > 0) ? (($impatto_glovo_promo - ($kpi['totale_prodotti'] - (($kpi['totale_prodotti'] / (100 + $percentuale_scorporo_default)) * 100))) / $kpi['totale_prodotti']) * 100 : 0; ?>,
                // Dati singole voci per aggiornamento dinamico cards
                totale_commissioni: <?php echo $kpi['totale_commissioni']; ?>,
                totale_marketing: <?php echo $kpi['totale_marketing']; ?>,
                totale_servizio_consegna: <?php echo $kpi['totale_servizio_consegna']; ?>,
                totale_buoni_pasto: <?php echo $kpi['totale_buoni_pasto']; ?>,
                totale_costi_incidenti: <?php echo $kpi['totale_costi_incidenti']; ?>,
                totale_rimborsi: <?php echo $kpi['totale_rimborsi']; ?>,
                totale_promo_partner: <?php echo $kpi['totale_promo_partner']; ?>,
                totale_tariffa_attesa: <?php echo $kpi['totale_tariffa_attesa']; ?>,
                totale_costo_annullamenti: <?php echo $kpi['totale_costo_annullamenti']; ?>,
                totale_consegna_gratuita: <?php echo $kpi['totale_consegna_gratuita']; ?>,
                totale_supplemento_glovo_prime: <?php echo $kpi['totale_supplemento_glovo_prime']; ?>,
                totale_promo_consegna_partner: <?php echo $kpi['totale_promo_consegna_partner']; ?>,
                totale_costi_offerta_lampo: <?php echo $kpi['totale_costi_offerta_lampo']; ?>,
                totale_promo_lampo_partner: <?php echo $kpi['totale_promo_lampo_partner']; ?>,
                totale_glovo_gia_pagati: <?php echo $kpi['totale_glovo_gia_pagati']; ?>,
                totale_ordini_rimborsati_partner: <?php echo $kpi['totale_ordini_rimborsati_partner']; ?>,
                totale_commissione_ordini_rimborsati: <?php echo $kpi['totale_commissione_ordini_rimborsati']; ?>,
                totale_sconto_comm_buoni_pasto: <?php echo $kpi['totale_sconto_comm_buoni_pasto']; ?>,
                totale_iva: <?php echo $kpi['totale_iva']; ?>,
                // Dati per simulazione negozio
                totale_costi_simulati: <?php echo $totale_costi_simulati; ?>,
                incassi_netti_nominale: <?php echo $incassi_netti_nominale; ?>,
                // Dati per negozi (per aggiornamento dinamico)
                store_data: <?php echo json_encode(array_map(function($store) {
                    return array(
                        'negozio' => $store['negozio'],
                        'totale_prodotti' => $store['totale_prodotti'],
                        'impatto_glovo' => $store['impatto_glovo'],
                        'totale_promo_partner' => $store['totale_promo_partner']
                    );
                }, $store_impact_data)); ?>
            };
        </script>
        <?php
        return ob_get_clean();
    }
}
