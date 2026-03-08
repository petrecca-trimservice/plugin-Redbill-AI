/**
 * Redbill AI — Tools JS
 * Esecuzione asincrona PDF extractor / CSV importer con log in tempo reale
 */
(function ($) {
    'use strict';

    if (typeof $ === 'undefined') return;

    var ajaxUrl = (typeof rbaiTools !== 'undefined') ? rbaiTools.ajaxUrl : '/wp-admin/admin-ajax.php';
    var nonce   = (typeof rbaiTools !== 'undefined') ? rbaiTools.nonce   : '';

    $(document).on('click', '#rbai-run-pdf, #rbai-run-csv', function () {
        var $btn       = $(this);
        var action     = $btn.data('action');
        var tenantId   = $('#rbai-tenant-select').val() || 0;
        var $logBox    = $('#rbai-log-box');
        var $logOutput = $('#rbai-log-output');
        var $logStats  = $('#rbai-log-stats');
        var $statsText = $('#rbai-log-stats-text');

        if (!action) return;

        $btn.prop('disabled', true).text('In esecuzione...');
        $logBox.show();
        $logOutput.text('Avvio elaborazione...\n');
        $logStats.hide();

        $.ajax({
            url     : ajaxUrl,
            method  : 'POST',
            data    : {
                action    : action,
                nonce     : nonce,
                tenant_id : tenantId
            },
            timeout : 600000, // 10 minuti
            success : function (res) {
                if (res.success) {
                    $logOutput.text(res.data.log || '(nessun output)');
                    // Scroll al fondo
                    $logOutput[0].scrollTop = $logOutput[0].scrollHeight;

                    // Mostra contatori
                    var c = res.data.counters || {};
                    if (c && Object.keys(c).length > 0) {
                        var statsHtml = '';
                        for (var key in c) {
                            if (c.hasOwnProperty(key)) {
                                statsHtml += ' | <strong>' + escHtml(key.replace(/_/g, ' ')) + ':</strong> ' + escHtml(String(c[key]));
                            }
                        }
                        $statsText.html(statsHtml.replace(/^\s*\|\s*/, ''));
                        $logStats.show();
                    }
                } else {
                    var errMsg = (res.data && res.data.message) ? res.data.message : 'Errore sconosciuto.';
                    $logOutput.text('ERRORE: ' + errMsg);
                }
            },
            error: function (xhr, status) {
                if (status === 'timeout') {
                    $logOutput.text('ERRORE: Timeout — elaborazione troppo lunga. Controlla i log del server.');
                } else {
                    $logOutput.text('ERRORE di rete: ' + status);
                }
            },
            complete: function () {
                $btn.prop('disabled', false).text($btn.data('original-text') || 'Esegui');
            }
        });
    });

    // Salva il testo originale dei bottoni
    $(function () {
        $('#rbai-run-pdf, #rbai-run-csv').each(function () {
            $(this).data('original-text', $(this).text());
        });
    });

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})(window.jQuery);
