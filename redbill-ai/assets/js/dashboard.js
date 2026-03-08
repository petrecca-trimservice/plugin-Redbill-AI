/**
 * Redbill AI — Dashboard JS
 * Filtri AJAX, modali, Chart.js, export Gemini
 */
(function ($) {
    'use strict';

    if (typeof $ === 'undefined') return;

    var ajaxUrl = (typeof rbaiAjax !== 'undefined') ? rbaiAjax.ajaxUrl : '/wp-admin/admin-ajax.php';
    var nonce   = (typeof rbaiAjax !== 'undefined') ? rbaiAjax.nonce   : '';

    /* ------------------------------------------------------------------
       Filtri AJAX tabella fatture
    ------------------------------------------------------------------ */
    $(document).on('submit', '#rbai-filter-form', function (e) {
        e.preventDefault();

        var $form    = $(this);
        var $target  = $('#rbai-table-container');
        var $spinner = $('<span class="rbai-loader" style="margin-left:8px;"></span>');

        $form.find('[type=submit]').prop('disabled', true).after($spinner);

        $.ajax({
            url     : ajaxUrl,
            method  : 'POST',
            data    : $form.serialize() + '&action=rbai_filter_invoices&nonce=' + nonce,
            success : function (res) {
                if (res.success) {
                    $target.html(res.data.html);
                    initCharts();
                }
            },
            complete: function () {
                $form.find('[type=submit]').prop('disabled', false);
                $spinner.remove();
            }
        });
    });

    /* ------------------------------------------------------------------
       Reset filtri
    ------------------------------------------------------------------ */
    $(document).on('click', '#rbai-filter-reset', function () {
        $('#rbai-filter-form')[0].reset();
        $('#rbai-filter-form').trigger('submit');
    });

    /* ------------------------------------------------------------------
       Dettaglio fattura — modal
    ------------------------------------------------------------------ */
    $(document).on('click', '.rbai-detail-btn', function () {
        var invoiceId = $(this).data('id');
        var $modal    = $('#rbai-detail-modal');

        if (!$modal.length) return;

        $modal.find('.rbai-modal-body').html('<div style="text-align:center;padding:40px"><span class="rbai-loader"></span></div>');
        $modal.addClass('is-open');

        $.ajax({
            url    : ajaxUrl,
            method : 'POST',
            data   : { action: 'rbai_invoice_detail', nonce: nonce, id: invoiceId },
            success: function (res) {
                if (res.success) {
                    $modal.find('.rbai-modal-body').html(res.data.html);
                }
            }
        });
    });

    /* ------------------------------------------------------------------
       PDF viewer — modal
    ------------------------------------------------------------------ */
    $(document).on('click', '.rbai-pdf-btn', function () {
        var pdfUrl = $(this).data('pdf');
        var $modal = $('#rbai-pdf-modal');
        if (!$modal.length) return;

        $modal.find('iframe').attr('src', pdfUrl);
        $modal.addClass('is-open');
    });

    /* ------------------------------------------------------------------
       Chiudi modal
    ------------------------------------------------------------------ */
    $(document).on('click', '.rbai-modal-close, .rbai-modal-overlay', function (e) {
        if ($(e.target).is('.rbai-modal-overlay') || $(e.target).is('.rbai-modal-close')) {
            $('.rbai-modal-overlay').removeClass('is-open');
            // Svuota iframe per evitare caricamento in background
            $('iframe.rbai-pdf-iframe').attr('src', '');
        }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('.rbai-modal-overlay').removeClass('is-open');
            $('iframe.rbai-pdf-iframe').attr('src', '');
        }
    });

    /* ------------------------------------------------------------------
       Gemini AI analysis
    ------------------------------------------------------------------ */
    $(document).on('click', '#rbai-gemini-btn', function () {
        var $btn    = $(this);
        var $output = $('#rbai-gemini-output');
        var $raw    = $('#rbai-raw-data');

        $btn.prop('disabled', true).text('Analisi in corso...');
        $output.html('<span class="rbai-loader"></span>');

        $.ajax({
            url    : ajaxUrl,
            method : 'POST',
            data   : {
                action   : 'rbai_gemini_analyze',
                nonce    : nonce,
                raw_data : $raw.val()
            },
            success: function (res) {
                if (res.success) {
                    $output.html(res.data.html);
                    // Render markdown se marked.js è disponibile
                    if (typeof marked !== 'undefined') {
                        var rawText = res.data.text || '';
                        $output.html('<div class="rbai-gemini-output">' + marked.parse(rawText) + '</div>');
                    }
                } else {
                    $output.html('<p style="color:red;">' + (res.data.message || 'Errore.') + '</p>');
                }
            },
            error: function () {
                $output.html('<p style="color:red;">Errore di rete.</p>');
            },
            complete: function () {
                $btn.prop('disabled', false).text('Analizza con Gemini AI');
            }
        });
    });

    /* ------------------------------------------------------------------
       Export TXT per AI
    ------------------------------------------------------------------ */
    $(document).on('click', '#rbai-export-txt-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);

        var form = $('<form method="POST" action="' + ajaxUrl + '">' +
            '<input name="action" value="rbai_export_ai_txt">' +
            '<input name="nonce" value="' + nonce + '">' +
            '</form>').appendTo('body');
        form.submit();
        form.remove();

        setTimeout(function () { $btn.prop('disabled', false); }, 2000);
    });

    /* ------------------------------------------------------------------
       Chart.js — inizializzazione
    ------------------------------------------------------------------ */
    function initCharts() {
        if (typeof Chart === 'undefined') return;
        if (typeof window.rbaiChartData === 'undefined') return;

        // Distruggi chart precedenti se esistono
        if (window.rbaiChartInstances) {
            window.rbaiChartInstances.forEach(function (c) { c.destroy(); });
        }
        window.rbaiChartInstances = [];

        var data = window.rbaiChartData;

        // Chart fatturato mensile
        var $salesCanvas = document.getElementById('rbai-sales-chart');
        if ($salesCanvas && data.monthly) {
            var salesChart = new Chart($salesCanvas.getContext('2d'), {
                type : 'bar',
                data : {
                    labels   : data.monthly.labels,
                    datasets : [
                        {
                            label           : 'Fatturato €',
                            data            : data.monthly.sales,
                            backgroundColor : 'rgba(0, 115, 170, 0.7)',
                            borderColor     : 'rgba(0, 115, 170, 1)',
                            borderWidth     : 1,
                        },
                        {
                            label           : 'Commissioni €',
                            data            : data.monthly.commissions,
                            backgroundColor : 'rgba(220, 53, 69, 0.6)',
                            borderColor     : 'rgba(220, 53, 69, 1)',
                            borderWidth     : 1,
                            type            : 'line',
                        }
                    ]
                },
                options: {
                    responsive         : true,
                    maintainAspectRatio: true,
                    plugins            : { legend: { position: 'top' } },
                    scales             : { y: { beginAtZero: true } }
                }
            });
            window.rbaiChartInstances.push(salesChart);
        }
    }

    // Init al caricamento pagina
    initCharts();

})(window.jQuery);
