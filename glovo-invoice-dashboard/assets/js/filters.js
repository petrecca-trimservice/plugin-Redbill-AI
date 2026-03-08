/**
 * Glovo Invoice Dashboard - JavaScript
 */

(function($) {
    'use strict';

    // Variabili globali
    let chartMonthly = null;
    let chartStores = null;
    let chartImpactGlovo = null;
    let chartImpactGlovoPromo = null;
    let chartImpactGlovoReal = null;
    let chartImpactGlovoPromoReal = null;

    $(document).ready(function() {
        initInvoiceTable();
        initDashboard();
        initGeminiAnalysis();
    });

    /**
     * Inizializza la tabella fatture
     */
    function initInvoiceTable() {
        // Filtri tabella
        $('#gid-filter-form').on('submit', function(e) {
            e.preventDefault();
            applyFilters();
        });

        // Reset filtri
        $('#gid-reset-filters').on('click', function() {
            $('#gid-filter-form')[0].reset();
            applyFilters();
        });

        // Export CSV
        $('#gid-export-csv').on('click', function() {
            exportToCSV();
        });

        // Visualizza dettagli fattura
        $(document).on('click', '.gid-view-details', function() {
            const invoice = $(this).data('invoice');
            showInvoiceDetails(invoice);
        });

        // Chiudi modale dettagli
        $('.gid-close, .gid-modal').on('click', function(e) {
            if (e.target === this) {
                $('#gid-modal').hide();
            }
        });

        // Click sul link PDF per visualizzare il PDF in popup
        $(document).on('click', '.gid-pdf-link', function(e) {
            e.preventDefault();
            const pdfFile = $(this).data('pdf');
            const nFattura = $(this).data('fattura');
            showPdfModal(pdfFile, nFattura);
        });

        // Chiudi modale PDF
        $(document).on('click', '.gid-pdf-close, .gid-pdf-close-btn', function(e) {
            e.preventDefault();
            closePdfModal();
        });

        // Chiudi modale PDF cliccando fuori
        $(document).on('click', '#gid-pdf-modal', function(e) {
            if (e.target === this) {
                closePdfModal();
            }
        });

        // Chiudi modale PDF con tasto ESC
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                if ($('#gid-pdf-modal').is(':visible')) {
                    closePdfModal();
                }
            }
        });
    }

    /**
     * Mostra il PDF nel modale
     */
    function showPdfModal(pdfFile, nFattura) {
        // Usa l'endpoint AJAX per servire il PDF (bypassa .htaccess)
        const pdfUrl = gidAjax.ajaxurl + '?action=gid_serve_pdf&file=' + encodeURIComponent(pdfFile) + '&nonce=' + gidAjax.nonce;

        // Aggiorna titolo
        $('#gid-pdf-modal-title').text('Fattura ' + nFattura);

        // Mostra loading
        $('#gid-pdf-loading').show();
        $('#gid-pdf-viewer').hide();

        // Imposta l'URL del PDF nell'iframe
        $('#gid-pdf-viewer').attr('src', pdfUrl);

        // Imposta link download (con parametro download=1 per forzare il download)
        const downloadUrl = gidAjax.ajaxurl + '?action=gid_serve_pdf&file=' + encodeURIComponent(pdfFile) + '&nonce=' + gidAjax.nonce + '&download=1';
        $('#gid-pdf-download').attr('href', downloadUrl);

        // Mostra il modale
        $('#gid-pdf-modal').show();

        // Quando l'iframe ha caricato, nascondi loading
        $('#gid-pdf-viewer').off('load').on('load', function() {
            $('#gid-pdf-loading').hide();
            $(this).show();
        });
    }

    /**
     * Chiude il modale PDF
     */
    function closePdfModal() {
        $('#gid-pdf-modal').hide();
        $('#gid-pdf-viewer').attr('src', '');
    }

    /**
     * Inizializza la dashboard
     */
    function initDashboard() {
        // Filtri dashboard
        $('#gid-dashboard-filter-form').on('submit', function(e) {
            e.preventDefault();
            applyDashboardFilters();
        });

        // Reset filtri dashboard
        $('#gid-dashboard-reset-filters').on('click', function() {
            // Rimuovi tutti i parametri GET dalla URL
            window.location.href = window.location.pathname;
        });

        // Inizializza i grafici se disponibile Chart.js
        if (typeof Chart !== 'undefined' && typeof gidChartData !== 'undefined') {
            initCharts();
        }

        // Gestione tabella collassabile per fatture con alto impatto
        $('#gid-toggle-high-impact').on('click', function() {
            const $button = $(this);
            const $table = $('#gid-high-impact-table');

            $button.toggleClass('active');

            if ($table.is(':visible')) {
                $table.slideUp(300);
                $button.html('<span class="gid-toggle-icon">▼</span> Mostra Dettagli');
            } else {
                $table.slideDown(300);
                $button.html('<span class="gid-toggle-icon">▲</span> Nascondi Dettagli');
            }
        });

        // Filtro per livello di allerta
        $('#gid-filter-alert-level').on('change', function() {
            const filterValue = $(this).val();
            const $rows = $('.gid-impact-table tbody tr');
            let visibleCount = 0;
            let criticoCount = 0;
            let attenzioneCount = 0;
            let normaleCount = 0;

            $rows.each(function() {
                const $row = $(this);
                const rowClass = $row.attr('class');

                if (filterValue === 'all') {
                    $row.show();
                    visibleCount++;
                } else if (filterValue === 'critico' && rowClass.includes('gid-row-critico')) {
                    $row.show();
                    visibleCount++;
                } else if (filterValue === 'attenzione' && rowClass.includes('gid-row-attenzione')) {
                    $row.show();
                    visibleCount++;
                } else if (filterValue === 'normale' && rowClass.includes('gid-row-normale')) {
                    $row.show();
                    visibleCount++;
                } else {
                    $row.hide();
                }

                // Conta i totali
                if (rowClass.includes('gid-row-critico')) {
                    criticoCount++;
                } else if (rowClass.includes('gid-row-attenzione')) {
                    attenzioneCount++;
                } else if (rowClass.includes('gid-row-normale')) {
                    normaleCount++;
                }
            });

            // Aggiorna il riepilogo
            updateTableSummary(visibleCount, criticoCount, attenzioneCount, normaleCount, filterValue);
        });

        // Gestione percentuale simulatore dinamica
        $('#gid-percentuale-simulatore').on('input change', function() {
            const percentuale = parseFloat($(this).val()) || 0;
            updateSimulatorCalculations(percentuale);
        });
    }

    /**
     * Aggiorna il riepilogo della tabella
     */
    function updateTableSummary(visible, critico, attenzione, normale, filter) {
        const $summary = $('.gid-table-summary p');
        let summaryText = '<strong>Totale fatture visualizzate:</strong> ' + visible;

        if (filter === 'all') {
            summaryText += ' (' + critico + ' critiche, ' + attenzione + ' che richiedono attenzione, ' + normale + ' normali)';
        } else if (filter === 'critico') {
            summaryText += ' (tutte critiche)';
        } else if (filter === 'attenzione') {
            summaryText += ' (tutte che richiedono attenzione)';
        } else if (filter === 'normale') {
            summaryText += ' (tutte normali)';
        }

        $summary.html(summaryText);
    }

    /**
     * Aggiorna i calcoli del simulatore basati sulla percentuale di maggiorazione
     */
    function updateSimulatorCalculations(percentuale) {
        if (typeof gidImpactData === 'undefined') return;

        const totaleProdotti = gidImpactData.totale_prodotti;

        // Calcola costi Glovo effettivi (somma di tutte le voci Glovo)
        const costiGlovoNominali = gidImpactData.totale_commissioni + gidImpactData.totale_marketing +
                                   gidImpactData.totale_supplemento_glovo_prime +
                                   gidImpactData.totale_promo_consegna_partner +
                                   gidImpactData.totale_costi_offerta_lampo +
                                   gidImpactData.totale_promo_lampo_partner +
                                   gidImpactData.totale_costi_incidenti;

        // Calcola maggiorazione con la percentuale dinamica
        const maggiorazioneSimulata = totaleProdotti - ((totaleProdotti / (100 + percentuale)) * 100);

        // Calcola costi Glovo simulati (effettivi - maggiorazione)
        const costiGlovoSimulati = costiGlovoNominali - maggiorazioneSimulata;
        const impattoSimulatoPerc = totaleProdotti > 0 ? (costiGlovoSimulati / totaleProdotti) * 100 : 0;

        // Calcola percentuale necessaria per azzerare i costi
        const percAzzeramento = (totaleProdotti > 0 && (totaleProdotti - costiGlovoNominali) > 0)
            ? (((totaleProdotti / (totaleProdotti - costiGlovoNominali)) - 1) * 100)
            : 0;

        // Impatto teorico al 15% (fisso)
        const impattoReal15 = gidImpactData.percentuale_impatto_real || 0;

        // Confronto: differenza (Simulato - Teorico)
        const differenzaImpatto = impattoSimulatoPerc - impattoReal15;

        // Versione Globale (tutte le voci Glovo)
        const promoPartner = gidImpactData.totale_promo_partner || 0;
        const tariffaAttesa = gidImpactData.totale_tariffa_attesa || 0;
        const ordiniRimborsatiPartner = gidImpactData.totale_ordini_rimborsati_partner || 0;
        const commOrdiniRimborsati = gidImpactData.totale_commissione_ordini_rimborsati || 0;
        const scontoCommBuoniPasto = gidImpactData.totale_sconto_comm_buoni_pasto || 0;
        const costiGlovoSimulatiPromo = costiGlovoSimulati + promoPartner + tariffaAttesa
                                        + commOrdiniRimborsati
                                        - ordiniRimborsatiPartner
                                        - scontoCommBuoniPasto;
        const impattoSimulatoPromoPerc = totaleProdotti > 0
            ? (costiGlovoSimulatiPromo / totaleProdotti) * 100
            : 0;

        // Impatto teorico + promo al 15% (fisso)
        const impattoPromoReal15 = gidImpactData.percentuale_impatto_promo_real || 0;

        // Confronto per versione + promo (Simulato - Teorico)
        const differenzaImpattoPromo = impattoSimulatoPromoPerc - impattoPromoReal15;

        // Funzione per formattare numeri
        const formatNumber = (num) => {
            return num.toLocaleString('it-IT', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        };

        // Aggiorna i valori nella sezione "Simulatore"
        $('#gid-sim-perc-label').text(percentuale);
        $('#gid-sim-perc-label-2').text(percentuale);
        $('#gid-sim-perc-label-3').text(percentuale);
        $('#gid-sim-maggiorazione').text(formatNumber(maggiorazioneSimulata));
        $('#gid-sim-costi-glovo').text(formatNumber(costiGlovoSimulati));
        $('#gid-sim-impatto-perc').text(formatNumber(impattoSimulatoPerc));
        $('#gid-sim-impatto-perc-2').text(formatNumber(impattoSimulatoPerc));

        // Aggiorna valori versione + Promo Partner
        $('#gid-sim-costi-glovo-promo').text(formatNumber(costiGlovoSimulatiPromo));
        $('#gid-sim-impatto-promo-perc').text(formatNumber(impattoSimulatoPromoPerc));
        $('#gid-sim-impatto-promo-perc-2').text(formatNumber(impattoSimulatoPromoPerc));

        // Aggiorna differenza con segno e colore (negativo = miglioramento)
        const diffText = (differenzaImpatto >= 0 ? '+' : '') + formatNumber(differenzaImpatto);
        $('#gid-sim-differenza').text(diffText);

        const $diffWrapper = $('#gid-sim-differenza-wrapper');
        $diffWrapper.removeClass('gid-positive gid-negative');
        $diffWrapper.addClass(differenzaImpatto < 0 ? 'gid-positive' : 'gid-negative');

        // Aggiorna testo interpretativo
        const diffTextLabel = differenzaImpatto < 0 ? 'Miglioramento' : 'Peggioramento';
        $('#gid-sim-differenza-text').text('(' + diffTextLabel + ')');

        // Aggiorna differenza + promo con segno e colore (negativo = miglioramento)
        const diffPromoText = (differenzaImpattoPromo >= 0 ? '+' : '') + formatNumber(differenzaImpattoPromo);
        $('#gid-sim-differenza-promo').text(diffPromoText);

        const $diffPromoWrapper = $('#gid-sim-differenza-promo-wrapper');
        $diffPromoWrapper.removeClass('gid-positive gid-negative');
        $diffPromoWrapper.addClass(differenzaImpattoPromo < 0 ? 'gid-positive' : 'gid-negative');

        const diffPromoTextLabel = differenzaImpattoPromo < 0 ? 'Miglioramento' : 'Peggioramento';
        $('#gid-sim-differenza-promo-text').text('(' + diffPromoTextLabel + ')');

        // Aggiorna messaggio interpretativo
        let messaggio = '';
        if (costiGlovoSimulati > 0) {
            messaggio = 'Con una maggiorazione del ' + percentuale + '%, i costi Glovo rimangono positivi. ' +
                       'Serve una maggiorazione di almeno ' + formatNumber(percAzzeramento) + '% per coprire tutti i costi.';
        } else if (costiGlovoSimulati === 0) {
            messaggio = 'Con una maggiorazione del ' + percentuale + '%, i costi Glovo sono completamente coperti!';
        } else {
            messaggio = 'Con una maggiorazione del ' + percentuale + '%, stai guadagnando di più della maggiorazione applicata!';
        }
        $('#gid-sim-messaggio').text(messaggio);
    }

    /**
     * Applica i filtri alla tabella
     */
    function applyFilters() {
        const formData = {
            action: 'gid_filter_invoices',
            nonce: gidAjax.nonce,
            destinatario: $('#filter-destinatario').val(),
            negozio: $('#filter-negozio').val(),
            data_from: $('#filter-data-from').val(),
            data_to: $('#filter-data-to').val(),
            periodo_from: $('#filter-periodo-from').val(),
            periodo_to: $('#filter-periodo-to').val(),
            n_fattura: $('#filter-n-fattura').val()
        };

        $('#gid-loading').show();

        $.ajax({
            url: gidAjax.ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    updateTable(response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('Errore nel caricamento dei dati:', error);
                alert('Errore nel caricamento dei dati. Riprova.');
            },
            complete: function() {
                $('#gid-loading').hide();
            }
        });
    }

    /**
     * Aggiorna la tabella con i nuovi dati
     */
    function updateTable(invoices) {
        const tbody = $('#gid-invoice-tbody');
        tbody.empty();

        if (invoices.length === 0) {
            tbody.append('<tr><td colspan="11" style="text-align: center;">Nessuna fattura trovata</td></tr>');
            return;
        }

        invoices.forEach(function(invoice) {
            // Genera la cella per il numero fattura con o senza link PDF
            let nFatturaCell;
            if (invoice.file_pdf) {
                nFatturaCell = `<a href="#" class="gid-pdf-link"
                    data-pdf="${escapeHtml(invoice.file_pdf)}"
                    data-fattura="${escapeHtml(invoice.n_fattura)}"
                    title="Clicca per visualizzare il PDF">${escapeHtml(invoice.n_fattura)}</a>`;
            } else {
                nFatturaCell = escapeHtml(invoice.n_fattura);
            }

            const row = `
                <tr data-invoice-id="${invoice.id || ''}">
                    <td>${escapeHtml(invoice.destinatario)}</td>
                    <td>${escapeHtml(invoice.negozio)}</td>
                    <td>${nFatturaCell}</td>
                    <td>${formatDate(invoice.data)}</td>
                    <td>${formatDate(invoice.periodo_da)}</td>
                    <td>${formatDate(invoice.periodo_a)}</td>
                    <td class="gid-currency">${formatCurrency(invoice.subtotale)}</td>
                    <td class="gid-currency gid-highlight">${formatCurrency(invoice.totale_fattura_iva_inclusa)}</td>
                    <td class="gid-currency">${formatCurrency(invoice.prodotti)}</td>
                    <td class="gid-currency gid-highlight">${formatCurrency(invoice.importo_bonifico)}</td>
                    <td class="gid-actions-cell">
                        <button class="gid-btn-icon gid-view-details"
                                data-invoice="${JSON.stringify(invoice).replace(/"/g, '&quot;')}"
                                title="Vedi tutti i dettagli">
                            🔍
                        </button>
                    </td>
                </tr>
            `;
            tbody.append(row);
        });
    }

    /**
     * Mostra i dettagli della fattura in un modale
     */
    function showInvoiceDetails(invoice) {
        const modalBody = $('#gid-modal-body');
        modalBody.empty();

        // Aggiorna il titolo del modale
        $('#gid-details-title').text('Fattura ' + escapeHtml(invoice.n_fattura));

        const details = `
            <div class="gid-invoice-details">
                <div class="gid-details-grid">
                    <!-- Informazioni Generali -->
                    <div class="gid-detail-section gid-detail-info">
                        <h3>Informazioni Generali</h3>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Destinatario</span>
                            <span class="gid-detail-value">${escapeHtml(invoice.destinatario)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Negozio</span>
                            <span class="gid-detail-value">${escapeHtml(invoice.negozio)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">N. Fattura</span>
                            <span class="gid-detail-value">${escapeHtml(invoice.n_fattura)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Data Fattura</span>
                            <span class="gid-detail-value">${formatDate(invoice.data)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Periodo</span>
                            <span class="gid-detail-value">${formatDate(invoice.periodo_da)} - ${formatDate(invoice.periodo_a)}</span>
                        </div>
                    </div>

                    <!-- Importi Principali -->
                    <div class="gid-detail-section gid-detail-amounts">
                        <h3>Importi Principali</h3>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Prodotti</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.prodotti)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Subtotale</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.subtotale)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">IVA 22%</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.iva_22)}</span>
                        </div>
                        <div class="gid-detail-row gid-detail-highlight">
                            <span class="gid-detail-label">Totale Fattura</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.totale_fattura_iva_inclusa)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Totale Riepilogo</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.totale_fattura_riepilogo)}</span>
                        </div>
                    </div>

                    <!-- Commissioni e Servizi Glovo -->
                    <div class="gid-detail-section gid-detail-fees">
                        <h3>Commissioni e Servizi Glovo</h3>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Commissioni</span>
                            <span class="gid-detail-value gid-currency gid-negative">${formatCurrency(invoice.commissioni)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Marketing e Visibilità</span>
                            <span class="gid-detail-value gid-currency gid-negative">${formatCurrency(invoice.marketing_visibilita)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Servizio Consegna</span>
                            <span class="gid-detail-value gid-currency gid-negative">${formatCurrency(invoice.servizio_consegna)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Suppl. Glovo Prime</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.supplemento_ordine_glovo_prime)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Tariffa Tempo Attesa</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.tariffa_tempo_attesa)}</span>
                        </div>
                    </div>

                    <!-- Costi, Rimborsi e Promozioni -->
                    <div class="gid-detail-section gid-detail-costs">
                        <h3>Costi, Rimborsi e Promozioni</h3>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Costi Incidenti Prodotti</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.costo_incidenti_prodotti)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Rimborsi Partner</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.rimborsi_partner_senza_comm)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Costo Annullamenti</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.costo_annullamenti_servizio)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Consegna Gratuita Incidente</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.consegna_gratuita_incidente)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Promo Prodotti Partner</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.promo_prodotti_partner)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Promo Consegna Partner</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.promo_consegna_partner)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Costi Offerta Lampo</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.costi_offerta_lampo)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Promo Lampo Partner</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.promo_lampo_partner)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Buoni Pasto</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.buoni_pasto)}</span>
                        </div>
                    </div>

                    <!-- Riepilogo Pagamento -->
                    <div class="gid-detail-section gid-detail-payment">
                        <h3>Riepilogo Pagamento</h3>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Glovo Già Pagati</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.glovo_gia_pagati)}</span>
                        </div>
                        <div class="gid-detail-row">
                            <span class="gid-detail-label">Debito Accumulato</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.debito_accumulato)}</span>
                        </div>
                        <div class="gid-detail-row gid-detail-highlight gid-detail-bonifico">
                            <span class="gid-detail-label">Importo Bonifico</span>
                            <span class="gid-detail-value gid-currency">${formatCurrency(invoice.importo_bonifico)}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;

        modalBody.html(details);
        $('#gid-modal').show();
    }

    /**
     * Esporta la tabella in CSV
     */
    function exportToCSV() {
        const table = document.getElementById('gid-invoice-table');
        let csv = [];

        // Header
        const headers = [];
        $(table).find('thead th').each(function() {
            if ($(this).text() !== 'Azioni') {
                headers.push($(this).text());
            }
        });
        csv.push(headers.join(';'));

        // Rows
        $(table).find('tbody tr').each(function() {
            const row = [];
            $(this).find('td').not(':last').each(function() {
                let text = $(this).text().trim();
                // Rimuovi il simbolo € e sostituisci . con , per i numeri
                text = text.replace(' €', '').replace(/\./g, '').replace(',', '.');
                row.push(text);
            });
            csv.push(row.join(';'));
        });

        // Download
        const csvContent = csv.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);

        link.setAttribute('href', url);
        link.setAttribute('download', 'fatture_glovo_' + new Date().toISOString().split('T')[0] + '.csv');
        link.style.visibility = 'hidden';

        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    /**
     * Applica i filtri alla dashboard
     */
    function applyDashboardFilters() {
        // Implementazione simile a applyFilters ma per la dashboard
        // Ricarica la pagina con i parametri di filtro
        const filters = {
            destinatario: $('#dashboard-filter-destinatario').val(),
            negozio: $('#dashboard-filter-negozio').val(),
            data_from: $('#dashboard-filter-data-from').val(),
            data_to: $('#dashboard-filter-data-to').val(),
            periodo_from: $('#dashboard-filter-periodo-from').val(),
            periodo_to: $('#dashboard-filter-periodo-to').val()
        };

        // Rimuovi i parametri vuoti
        const filteredParams = {};
        Object.keys(filters).forEach(key => {
            if (filters[key] && filters[key] !== '') {
                filteredParams[key] = filters[key];
            }
        });

        // Costruisci URL con parametri
        const params = new URLSearchParams(filteredParams);
        window.location.search = params.toString();
    }

    /**
     * Inizializza i grafici
     */
    function initCharts() {
        // Grafico mensile
        const monthlyCtx = document.getElementById('gid-chart-monthly');
        if (monthlyCtx) {
            const monthlyData = gidChartData.prodotti_per_mese;
            const months = Object.keys(monthlyData).sort();
            const values = months.map(m => monthlyData[m]);

            chartMonthly = new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Totale Prodotti Lordo',
                        data: values,
                        borderColor: '#00A082',
                        backgroundColor: 'rgba(0, 160, 130, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '€ ' + value.toLocaleString('it-IT');
                                }
                            }
                        }
                    }
                }
            });
        }

        // Grafico per negozio
        const storesCtx = document.getElementById('gid-chart-stores');
        if (storesCtx) {
            const storesData = gidChartData.prodotti_per_negozio;
            const stores = Object.entries(storesData)
                .sort((a, b) => b[1] - a[1])
                .slice(0, 10);

            const labels = stores.map(s => s[0]);
            const values = stores.map(s => s[1]);

            chartStores = new Chart(storesCtx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Totale Prodotti Lordo',
                        data: values,
                        backgroundColor: '#FFC244',
                        borderColor: '#FFB300',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '€ ' + value.toLocaleString('it-IT');
                                }
                            }
                        }
                    }
                }
            });
        }

        // Grafico a Torta: Impatto Glovo
        const impactGlovoCtx = document.getElementById('gid-chart-impact-glovo');
        if (impactGlovoCtx && typeof gidImpactData !== 'undefined') {
            const impatto = gidImpactData.impatto_glovo;
            const resto = gidImpactData.totale_prodotti - impatto;

            chartImpactGlovo = new Chart(impactGlovoCtx, {
                type: 'pie',
                data: {
                    labels: ['Impatto Glovo Parziale', 'Margine Lordo Partner'],
                    datasets: [{
                        data: [impatto, resto],
                        backgroundColor: [
                            '#00A082',  // Verde primary per Impatto Glovo
                            '#FFC244'   // Giallo per Resto
                        ],
                        borderColor: '#ffffff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const percentage = ((value / gidImpactData.totale_prodotti) * 100).toFixed(2);
                                    return label + ': € ' + value.toLocaleString('it-IT', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    }) + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }

        // Grafico a Torta: Impatto Glovo con Promozioni Partner
        const impactGlovoPromoCtx = document.getElementById('gid-chart-impact-glovo-promo');
        if (impactGlovoPromoCtx && typeof gidImpactData !== 'undefined') {
            const impatto = gidImpactData.impatto_glovo_promo;
            const resto = gidImpactData.totale_prodotti - impatto;

            chartImpactGlovoPromo = new Chart(impactGlovoPromoCtx, {
                type: 'pie',
                data: {
                    labels: ['Impatto Glovo Globale', 'Margine Lordo Partner'],
                    datasets: [{
                        data: [impatto, resto],
                        backgroundColor: [
                            '#00A082',  // Verde primary per Impatto totale
                            '#FFC244'   // Giallo per Resto
                        ],
                        borderColor: '#ffffff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const percentage = ((value / gidImpactData.totale_prodotti) * 100).toFixed(2);
                                    return label + ': € ' + value.toLocaleString('it-IT', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    }) + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }

        // Grafico a Torta: Impatto Glovo Teorico (con riduzione 15%)
        const impactGlovoRealCtx = document.getElementById('gid-chart-impact-glovo-real');
        if (impactGlovoRealCtx && typeof gidImpactData !== 'undefined') {
            const impatto = gidImpactData.impatto_glovo_real;
            const resto = gidImpactData.totale_prodotti - impatto;

            chartImpactGlovoReal = new Chart(impactGlovoRealCtx, {
                type: 'pie',
                data: {
                    labels: ['Impatto Glovo Teorico Parziale (scorporo 15%)', 'Margine Lordo Partner'],
                    datasets: [{
                        data: [impatto, resto],
                        backgroundColor: [
                            '#28a745',  // Verde per Impatto Glovo Teorico
                            '#FFC244'   // Giallo per Resto
                        ],
                        borderColor: '#ffffff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const percentage = ((value / gidImpactData.totale_prodotti) * 100).toFixed(2);
                                    return label + ': € ' + value.toLocaleString('it-IT', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    }) + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }

        // Grafico a Torta: Impatto Glovo Teorico con Promozioni Partner
        const impactGlovoPromoRealCtx = document.getElementById('gid-chart-impact-glovo-promo-real');
        if (impactGlovoPromoRealCtx && typeof gidImpactData !== 'undefined') {
            const impatto = gidImpactData.impatto_glovo_promo_real;
            const resto = gidImpactData.totale_prodotti - impatto;

            chartImpactGlovoPromoReal = new Chart(impactGlovoPromoRealCtx, {
                type: 'pie',
                data: {
                    labels: ['Impatto Glovo Teorico Globale (scorporo 15%)', 'Margine Lordo Partner'],
                    datasets: [{
                        data: [impatto, resto],
                        backgroundColor: [
                            '#28a745',  // Verde per Impatto totale teorico
                            '#FFC244'   // Giallo per Resto
                        ],
                        borderColor: '#ffffff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const percentage = ((value / gidImpactData.totale_prodotti) * 100).toFixed(2);
                                    return label + ': € ' + value.toLocaleString('it-IT', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    }) + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    /**
     * Utility: Formatta una data
     */
    function formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString('it-IT');
    }

    // =========================================================================
    // GEMINI AI ANALYSIS
    // =========================================================================

    /**
     * Inizializza l'analisi Gemini: gestisce il bottone, la modale e i download.
     */
    function initGeminiAnalysis() {
        let geminiReportMarkdown = '';

        // ── Apri modale e lancia analisi ──────────────────────────────────────
        $(document).on('click', '#gid-gemini-analyze-btn', function() {
            const btn = $(this);
            geminiReportMarkdown = '';

            // Reset modale
            $('#gid-gemini-loading').show();
            $('#gid-gemini-report-body').hide().empty();
            $('#gid-gemini-error').hide().empty();
            $('#gid-gemini-download-md, #gid-gemini-download-docx').hide();

            $('#gid-gemini-modal').css('display', 'flex');

            $.ajax({
                url: gidAjax.ajaxurl,
                type: 'POST',
                data: {
                    action:      'gid_gemini_analyze',
                    nonce:       gidAjax.geminiNonce,
                    scd_from:    btn.data('from'),
                    scd_to:      btn.data('to'),
                    scd_negozio: btn.data('negozio')
                },
                timeout: 130000,
                success: function(response) {
                    $('#gid-gemini-loading').hide();
                    if (response.success && response.data && response.data.report) {
                        geminiReportMarkdown = response.data.report;
                        const html = typeof marked !== 'undefined'
                            ? marked.parse(geminiReportMarkdown)
                            : '<pre>' + escapeHtml(geminiReportMarkdown) + '</pre>';
                        $('#gid-gemini-report-body').html(html).show();
                        $('#gid-gemini-download-md, #gid-gemini-download-docx').show();
                        if (response.data.finish_reason === 'MAX_TOKENS') {
                            $('#gid-gemini-error').text('⚠️ Il report è stato troncato: la risposta ha superato il limite massimo di token. Prova a ridurre il periodo analizzato.').show();
                        }
                    } else {
                        const msg = (response.data && response.data.message)
                            ? response.data.message
                            : 'Errore sconosciuto dalla risposta del server.';
                        $('#gid-gemini-error').text(msg).show();
                    }
                },
                error: function(xhr, status) {
                    $('#gid-gemini-loading').hide();
                    const msg = status === 'timeout'
                        ? 'Timeout: la richiesta ha impiegato troppo tempo. Riprova.'
                        : 'Errore di rete. Controlla la connessione e riprova.';
                    $('#gid-gemini-error').text(msg).show();
                }
            });
        });

        // ── Chiudi modale ─────────────────────────────────────────────────────
        $(document).on('click', '.gid-gemini-close', function() {
            $('#gid-gemini-modal').hide();
        });

        $(document).on('click', '#gid-gemini-modal', function(e) {
            if (e.target === this) {
                $('#gid-gemini-modal').hide();
            }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#gid-gemini-modal').is(':visible')) {
                $('#gid-gemini-modal').hide();
            }
        });

        // ── Download .md ──────────────────────────────────────────────────────
        $(document).on('click', '#gid-gemini-download-md', function() {
            if (!geminiReportMarkdown) return;
            const blob = new Blob([geminiReportMarkdown], { type: 'text/markdown;charset=utf-8' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;
            a.download = 'report-gemini-' + new Date().toISOString().slice(0, 10) + '.md';
            a.click();
            URL.revokeObjectURL(url);
        });

        // ── Download .docx ────────────────────────────────────────────────────
        $(document).on('click', '#gid-gemini-download-docx', function() {
            if (!geminiReportMarkdown || typeof htmlDocx === 'undefined') return;

            const html = typeof marked !== 'undefined'
                ? marked.parse(geminiReportMarkdown)
                : '<pre>' + escapeHtml(geminiReportMarkdown) + '</pre>';

            // html-docx-js richiede un documento HTML completo
            const fullHtml = '<!DOCTYPE html><html><head><meta charset="utf-8">' +
                '<style>body{font-family:Calibri,sans-serif;font-size:11pt;line-height:1.5}' +
                'table{border-collapse:collapse;width:100%}' +
                'th,td{border:1px solid #ccc;padding:6px 10px}' +
                'th{background:#f2f2f2}h1,h2,h3{color:#1a5276}</style>' +
                '</head><body>' + html + '</body></html>';

            const blob = htmlDocx.asBlob(fullHtml);
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;
            a.download = 'report-gemini-' + new Date().toISOString().slice(0, 10) + '.docx';
            a.click();
            URL.revokeObjectURL(url);
        });
    }

    // =========================================================================
    // INVIO EMAIL CONFRONTO PERIODICO
    // =========================================================================

    $(document).on('click', '#gid-email-comparison-btn', function() {
        var $btn = $(this);
        var originalText = $btn.text();

        if (!confirm('Vuoi inviare l\'analisi comparativa (ultimi 30gg vs 30gg precedenti) via email per ogni negozio?\n\nL\'operazione potrebbe richiedere qualche minuto.')) {
            return;
        }

        $btn.prop('disabled', true).text('Invio in corso…');

        $.ajax({
            url:     gidAjax.ajaxurl,
            type:    'POST',
            timeout: 600000,
            data: {
                action: 'gid_send_email_analysis',
                nonce:  gidAjax.emailNonce,
                source: 'dashboard'
            },
            success: function(response) {
                $btn.prop('disabled', false).text(originalText);
                if (response.success) {
                    alert('✅ ' + response.data.message);
                } else {
                    alert('❌ ' + response.data.message);
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).text(originalText);
                var msg = status === 'timeout'
                    ? 'Timeout: l\'operazione ha impiegato troppo tempo.'
                    : 'Errore di rete: ' + error;
                alert('❌ ' + msg);
            }
        });
    });

    /**
     * Utility: Formatta una valuta
     */
    function formatCurrency(value) {
        if (!value) return '0,00 €';
        const num = parseFloat(value);
        return num.toLocaleString('it-IT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' €';
    }

    /**
     * Utility: Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }

})(jQuery);
