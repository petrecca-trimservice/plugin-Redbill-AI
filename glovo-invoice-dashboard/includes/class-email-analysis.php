<?php
/**
 * GID Email Analysis — Invio automatico e manuale di analisi comparative via email.
 *
 * Confronta gli ultimi 30 giorni con i 30 giorni precedenti per ogni negozio,
 * genera un report con Gemini AI e lo invia via email.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GID_Email_Analysis {

    // Chiavi wp_options
    const OPT_RECIPIENTS     = 'gid_email_recipients';
    const OPT_ENABLED        = 'gid_email_enabled';
    const OPT_LAST_SENT      = 'gid_email_last_sent';
    const OPT_LAST_INV_COUNT = 'gid_email_last_invoice_count';

    /**
     * Registra hook WordPress: pagina admin e AJAX.
     */
    public static function register_hooks() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

        // AJAX per invio manuale (dal bottone nella dashboard o dalla pagina admin)
        add_action( 'wp_ajax_gid_send_email_analysis', array( __CLASS__, 'handle_ajax_send' ) );
    }

    // -------------------------------------------------------------------------
    // Pagina Admin Settings
    // -------------------------------------------------------------------------

    public static function add_admin_page() {
        add_options_page(
            'Analisi Email Glovo',
            'Analisi Email Glovo',
            'manage_options',
            'gid-email-analysis',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function register_settings() {
        register_setting( 'gid_email_analysis_group', self::OPT_ENABLED, array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '0',
        ) );
        register_setting( 'gid_email_analysis_group', self::OPT_RECIPIENTS, array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ) );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $enabled    = get_option( self::OPT_ENABLED, '0' );
        $recipients = get_option( self::OPT_RECIPIENTS, '' );
        $last_sent  = get_option( self::OPT_LAST_SENT, '' );
        $last_count = get_option( self::OPT_LAST_INV_COUNT, '0' );

        // Conteggio fatture attuale
        $dashboard    = new GID_Sales_Costs_Dashboard();
        $current_count = $dashboard->get_invoice_count();
        ?>
        <div class="wrap">
            <h1>Analisi Email Glovo</h1>
            <p>Configura l'invio automatico di analisi comparative via email. Il confronto copre gli ultimi 30 giorni rispetto ai 30 giorni precedenti, per ogni gruppo di negozi.</p>

            <form method="post" action="options.php">
                <?php settings_fields( 'gid_email_analysis_group' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Abilitato</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPT_ENABLED ); ?>" value="1" <?php checked( $enabled, '1' ); ?>>
                                Abilita il rilevamento automatico di nuove fatture (richiede cron Plesk)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Destinatari email</th>
                        <td>
                            <textarea name="<?php echo esc_attr( self::OPT_RECIPIENTS ); ?>" rows="5" cols="50" class="large-text"><?php echo esc_textarea( $recipients ); ?></textarea>
                            <p class="description">Un indirizzo email per riga.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Salva impostazioni' ); ?>
            </form>

            <hr>

            <h2>Stato</h2>
            <table class="widefat" style="max-width:600px">
                <tbody>
                    <tr>
                        <td><strong>Ultimo invio</strong></td>
                        <td><?php echo $last_sent ? esc_html( $last_sent ) : 'Mai'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Fatture al momento dell'ultimo invio</strong></td>
                        <td><?php echo esc_html( $last_count ); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Fatture attuali nel database</strong></td>
                        <td><?php echo esc_html( $current_count ); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Gemini API Key</strong></td>
                        <td><?php echo defined( 'GID_GEMINI_API_KEY' ) && GID_GEMINI_API_KEY !== '' ? '✅ Configurata' : '❌ Non configurata'; ?></td>
                    </tr>
                </tbody>
            </table>

            <hr>

            <h2>Invio manuale</h2>
            <p>Clicca per inviare subito l'analisi comparativa a tutti i destinatari configurati.</p>
            <button id="gid-admin-send-email" class="button button-primary button-large">
                Invia confronto ora
            </button>
            <div id="gid-admin-send-status" style="margin-top:15px"></div>

            <script>
            jQuery(function($) {
                $('#gid-admin-send-email').on('click', function() {
                    var $btn    = $(this);
                    var $status = $('#gid-admin-send-status');
                    $btn.prop('disabled', true).text('Invio in corso…');
                    $status.html('<p style="color:#666">Generazione analisi con Gemini AI e invio email in corso. Potrebbe richiedere qualche minuto…</p>');

                    $.ajax({
                        url:     ajaxurl,
                        method:  'POST',
                        timeout: 600000,
                        data: {
                            action: 'gid_send_email_analysis',
                            nonce:  '<?php echo wp_create_nonce( 'gid_email_analysis_nonce' ); ?>',
                            source: 'admin'
                        },
                        success: function(response) {
                            $btn.prop('disabled', false).text('Invia confronto ora');
                            if (response.success) {
                                $status.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                            } else {
                                $status.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                            }
                        },
                        error: function(xhr, status, error) {
                            $btn.prop('disabled', false).text('Invia confronto ora');
                            $status.html('<div class="notice notice-error"><p>Errore di connessione: ' + error + '</p></div>');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX handler per invio manuale
    // -------------------------------------------------------------------------

    public static function handle_ajax_send() {
        check_ajax_referer( 'gid_email_analysis_nonce', 'nonce' );

        // Dalla dashboard: solo utenti loggati. Dall'admin: solo manage_options.
        $source = isset( $_POST['source'] ) ? sanitize_text_field( $_POST['source'] ) : 'dashboard';
        if ( $source === 'admin' && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permessi insufficienti.' ) );
        }

        $result = self::execute_analysis( true );

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => $result['message'] ) );
        } else {
            wp_send_json_error( array( 'message' => $result['message'] ) );
        }
    }

    // -------------------------------------------------------------------------
    // Esecuzione analisi e invio email
    // -------------------------------------------------------------------------

    /**
     * Esegue l'analisi comparativa per tutti i negozi e invia le email.
     *
     * @param bool $force Se true, salta il check abilitazione (per invio manuale).
     * @return array ['success' => bool, 'message' => string]
     */
    public static function execute_analysis( $force = false ) {
        set_time_limit( 600 );

        // Verifica prerequisiti
        if ( ! $force ) {
            $enabled = get_option( self::OPT_ENABLED, '0' );
            if ( $enabled !== '1' ) {
                return array( 'success' => false, 'message' => 'Invio automatico disabilitato.' );
            }
        }

        if ( ! defined( 'GID_GEMINI_API_KEY' ) || empty( GID_GEMINI_API_KEY ) ) {
            return array( 'success' => false, 'message' => 'Chiave API Gemini non configurata in wp-config.php.' );
        }

        $recipients_raw = get_option( self::OPT_RECIPIENTS, '' );
        $recipients     = self::parse_recipients( $recipients_raw );
        if ( empty( $recipients ) ) {
            return array( 'success' => false, 'message' => 'Nessun destinatario email configurato.' );
        }

        $store_groups   = GID_Sales_Costs_Dashboard::get_store_groups();
        $dashboard      = new GID_Sales_Costs_Dashboard();

        // Ancora i periodi ai cicli di fatturazione bimensili (1→15 e 16→fine mese).
        // Se l'ancora cade nel primo semestre (≤15): corrente = 16 mese prec → 15 mese corrente.
        // Se cade nel secondo semestre (>15): corrente = 1° → ultimo giorno mese corrente.
        // mktime gestisce automaticamente lo sforamento di mese/anno (es. mese 0 = dicembre).
        $anchor       = $dashboard->get_last_invoice_date();
        $anchor_ts    = strtotime( $anchor );
        $anchor_day   = (int) date( 'j', $anchor_ts );
        $anchor_month = (int) date( 'n', $anchor_ts );
        $anchor_year  = (int) date( 'Y', $anchor_ts );

        $current_to = $anchor;
        if ( $anchor_day <= 15 ) {
            $current_from  = date( 'Y-m-d', mktime( 0, 0, 0, $anchor_month - 1, 16, $anchor_year ) );
            $previous_to   = date( 'Y-m-d', mktime( 0, 0, 0, $anchor_month - 1, 15, $anchor_year ) );
            $previous_from = date( 'Y-m-d', mktime( 0, 0, 0, $anchor_month - 2, 16, $anchor_year ) );
        } else {
            $current_from  = date( 'Y-m-d', mktime( 0, 0, 0, $anchor_month, 1, $anchor_year ) );
            $previous_to   = date( 'Y-m-d', mktime( 0, 0, 0, $anchor_month, 0, $anchor_year ) ); // giorno 0 = ultimo giorno del mese precedente
            $previous_from = date( 'Y-m-d', mktime( 0, 0, 0, $anchor_month - 1, 1, $anchor_year ) );
        }

        // Formato italiano per la visualizzazione nell'email (gg-mm-aaaa)
        $fmt = function( $ymd ) { return date( 'd-m-Y', strtotime( $ymd ) ); };

        // Carica il prompt dal file
        $prompt_file = GID_PLUGIN_DIR . 'includes/gemini-comparison-prompt.txt';
        $prompt_base = file_exists( $prompt_file ) ? file_get_contents( $prompt_file ) : '';

        $sent_count   = 0;
        $error_stores = array();

        foreach ( $store_groups as $key => $group ) {
            $negozio_filter = array( 'type' => $group['type'], 'value' => $group['value'] );
            $store_label    = $group['label'];

            // Dati periodo corrente
            $rows_current  = $dashboard->get_monthly_data_for_email( $current_from, $current_to, $negozio_filter );
            $text_current  = GID_Sales_Costs_Dashboard::generate_export_text(
                $rows_current, $current_from, $current_to, $store_label
            );

            // Dati periodo precedente
            $rows_previous = $dashboard->get_monthly_data_for_email( $previous_from, $previous_to, $negozio_filter );
            $text_previous = GID_Sales_Costs_Dashboard::generate_export_text(
                $rows_previous, $previous_from, $previous_to, $store_label
            );

            // Assembla prompt
            $full_prompt = $prompt_base
                . "=== PERIODO CORRENTE (ultimi 30 giorni: {$current_from} → {$current_to}) ===\n\n"
                . $text_current
                . "\n\n=== PERIODO PRECEDENTE (30 giorni prima: {$previous_from} → {$previous_to}) ===\n\n"
                . $text_previous;

            // Chiama Gemini
            $gemini_result = self::call_gemini_api( $full_prompt );

            if ( $gemini_result['success'] ) {
                $report_html = self::markdown_to_html( $gemini_result['text'] );
                $email_html  = self::build_email_html(
                    $store_label,
                    $report_html,
                    $fmt( $current_from ) . ' → ' . $fmt( $current_to ),
                    $fmt( $previous_from ) . ' → ' . $fmt( $previous_to )
                );

                $subject = "Glovo — Confronto {$store_label}: ultimi 30gg vs precedenti";

                $headers = array(
                    'Content-Type: text/html; charset=UTF-8',
                    'From: Glovo Dashboard <noreply@' . self::get_email_domain() . '>',
                );

                $sent = wp_mail( $recipients, $subject, $email_html, $headers );
                if ( $sent ) {
                    $sent_count++;
                } else {
                    $error_stores[] = $store_label . ' (errore invio email)';
                }
            } else {
                $error_stores[] = $store_label . ' (' . $gemini_result['error'] . ')';
            }
        }

        // Aggiorna stato
        update_option( self::OPT_LAST_SENT, date( 'Y-m-d H:i:s' ) );
        $current_inv_count = $dashboard->get_invoice_count();
        update_option( self::OPT_LAST_INV_COUNT, $current_inv_count );

        $dashboard->close_connection();

        // Messaggio risultato
        if ( $sent_count === count( $store_groups ) ) {
            return array(
                'success' => true,
                'message' => "Analisi inviata con successo per {$sent_count} negozi a " . implode( ', ', $recipients ) . '.',
            );
        } elseif ( $sent_count > 0 ) {
            return array(
                'success' => true,
                'message' => "Inviata per {$sent_count} negozi. Errori: " . implode( '; ', $error_stores ),
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Nessuna email inviata. Errori: ' . implode( '; ', $error_stores ),
            );
        }
    }

    // -------------------------------------------------------------------------
    // Rilevamento nuove fatture (per cron Plesk)
    // -------------------------------------------------------------------------

    /**
     * Controlla se ci sono nuove fatture nel database.
     * Se sì, esegue l'analisi e invia le email.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public static function check_new_invoices() {
        $enabled = get_option( self::OPT_ENABLED, '0' );
        if ( $enabled !== '1' ) {
            return array( 'success' => false, 'message' => 'Invio automatico disabilitato.' );
        }

        $dashboard     = new GID_Sales_Costs_Dashboard();
        $current_count = $dashboard->get_invoice_count();
        $last_count    = (int) get_option( self::OPT_LAST_INV_COUNT, '0' );
        $dashboard->close_connection();

        if ( $current_count <= $last_count ) {
            return array( 'success' => false, 'message' => "Nessuna nuova fattura (attuale: {$current_count}, ultimo check: {$last_count})." );
        }

        $new_invoices = $current_count - $last_count;
        error_log( "GID Email Analysis: rilevate {$new_invoices} nuove fatture. Avvio analisi." );

        return self::execute_analysis( false );
    }

    // -------------------------------------------------------------------------
    // Chiamata API Gemini
    // -------------------------------------------------------------------------

    private static function call_gemini_api( $prompt ) {
        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GID_GEMINI_API_KEY;

        $body = wp_json_encode( array(
            'contents'         => array(
                array( 'parts' => array( array( 'text' => $prompt ) ) ),
            ),
            'generationConfig' => array(
                'temperature'     => 0.3,
                'maxOutputTokens' => 32768,
            ),
        ) );

        $response = wp_remote_post( $api_url, array(
            'timeout' => 120,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => 'Errore connessione: ' . $response->get_error_message() );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $resp_body = wp_remote_retrieve_body( $response );
        $decoded   = json_decode( $resp_body, true );

        if ( $http_code !== 200 ) {
            $err_msg = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : 'HTTP ' . $http_code;
            return array( 'success' => false, 'error' => 'Errore API Gemini: ' . $err_msg );
        }

        $text = isset( $decoded['candidates'][0]['content']['parts'][0]['text'] )
            ? $decoded['candidates'][0]['content']['parts'][0]['text']
            : '';

        if ( empty( $text ) ) {
            return array( 'success' => false, 'error' => 'Risposta Gemini vuota.' );
        }

        return array( 'success' => true, 'text' => $text );
    }

    // -------------------------------------------------------------------------
    // Conversione Markdown → HTML (server-side, per email)
    // -------------------------------------------------------------------------

    private static function markdown_to_html( $md ) {
        $html = $md;

        // Tabelle Markdown
        $html = preg_replace_callback( '/^(\|.+\|)\n(\|[\s\-\|:]+\|)\n((?:\|.+\|\n?)+)/m', function( $m ) {
            $header_line = trim( $m[1] );
            $body_lines  = trim( $m[3] );

            $headers = array_map( 'trim', explode( '|', trim( $header_line, '|' ) ) );
            $thead   = '<tr>' . implode( '', array_map( function( $h ) {
                return '<th style="padding:8px 12px;background:#4285F4;color:#fff;text-align:left;font-size:13px">' . htmlspecialchars( $h ) . '</th>';
            }, $headers ) ) . '</tr>';

            $rows = '';
            foreach ( explode( "\n", $body_lines ) as $i => $line ) {
                $line = trim( $line );
                if ( empty( $line ) ) { continue; }
                $cells = array_map( 'trim', explode( '|', trim( $line, '|' ) ) );
                $bg    = $i % 2 === 0 ? '#fff' : '#f8f9fa';
                $rows .= '<tr>' . implode( '', array_map( function( $c ) use ( $bg ) {
                    return '<td style="padding:8px 12px;border-bottom:1px solid #e0e0e0;background:' . $bg . ';font-size:13px">' . htmlspecialchars( $c ) . '</td>';
                }, $cells ) ) . '</tr>';
            }

            return '<table style="border-collapse:collapse;width:100%;margin:16px 0;border:1px solid #e0e0e0">'
                . '<thead>' . $thead . '</thead>'
                . '<tbody>' . $rows . '</tbody>'
                . '</table>';
        }, $html );

        // Headers
        $html = preg_replace( '/^### (.+)$/m', '<h3 style="color:#1a3c5e;font-size:16px;margin:20px 0 8px">$1</h3>', $html );
        $html = preg_replace( '/^## (.+)$/m', '<h2 style="color:#1a3c5e;font-size:18px;margin:24px 0 10px;border-bottom:1px solid #e0e0e0;padding-bottom:6px">$1</h2>', $html );
        $html = preg_replace( '/^# (.+)$/m', '<h1 style="color:#1a3c5e;font-size:22px;margin:24px 0 12px;border-bottom:2px solid #4285F4;padding-bottom:8px">$1</h1>', $html );

        // Bold e italic
        $html = preg_replace( '/\*\*(.+?)\*\*/', '<strong style="color:#1a3c5e">$1</strong>', $html );
        $html = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $html );

        // Liste
        $html = preg_replace( '/^- (.+)$/m', '<li style="margin-bottom:4px">$1</li>', $html );
        $html = preg_replace( '/^(\d+)\. (.+)$/m', '<li style="margin-bottom:4px">$2</li>', $html );
        $html = preg_replace( '/(<li[^>]*>.*<\/li>\n?)+/', '<ul style="margin:8px 0 8px 20px;padding:0">$0</ul>', $html );

        // Paragrafi (linee non vuote che non sono già tag)
        $html = preg_replace( '/^(?!<[hluot])(.+)$/m', '<p style="margin:0 0 10px;line-height:1.6">$1</p>', $html );

        // Rimuovi linee vuote multiple
        $html = preg_replace( '/\n{3,}/', "\n\n", $html );

        return $html;
    }

    // -------------------------------------------------------------------------
    // Template email HTML
    // -------------------------------------------------------------------------

    private static function build_email_html( $store_label, $report_html, $period_current, $period_previous ) {
        $date_generated = date( 'd/m/Y H:i' );

        return '<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:20px 0">
<tr><td align="center">
<table width="680" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">

    <!-- Header -->
    <tr>
        <td style="background:linear-gradient(135deg,#00A082,#00796B);padding:28px 32px;color:#fff">
            <h1 style="margin:0;font-size:22px;font-weight:700">Glovo Dashboard — Confronto Periodico</h1>
            <p style="margin:8px 0 0;font-size:14px;opacity:0.9">' . htmlspecialchars( $store_label ) . '</p>
        </td>
    </tr>

    <!-- Periodi -->
    <tr>
        <td style="padding:20px 32px 0">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td width="48%" style="background:#e8f5e9;border-radius:6px;padding:12px 16px">
                        <strong style="font-size:12px;color:#2e7d32;text-transform:uppercase">Periodo corrente</strong><br>
                        <span style="font-size:14px;color:#1b5e20">' . htmlspecialchars( $period_current ) . '</span>
                    </td>
                    <td width="4%">&nbsp;</td>
                    <td width="48%" style="background:#fff3e0;border-radius:6px;padding:12px 16px">
                        <strong style="font-size:12px;color:#e65100;text-transform:uppercase">Periodo precedente</strong><br>
                        <span style="font-size:14px;color:#bf360c">' . htmlspecialchars( $period_previous ) . '</span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Report -->
    <tr>
        <td style="padding:24px 32px">
            ' . $report_html . '
        </td>
    </tr>

    <!-- Footer -->
    <tr>
        <td style="background:#f8f9fa;padding:16px 32px;border-top:1px solid #e0e0e0">
            <p style="margin:0;font-size:12px;color:#888">
                Report generato automaticamente da Glovo Invoice Dashboard con Gemini AI<br>
                Data generazione: ' . $date_generated . '
            </p>
        </td>
    </tr>

</table>
</td></tr>
</table>

</body>
</html>';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function parse_recipients( $raw ) {
        $lines = preg_split( '/[\r\n,;]+/', $raw );
        $emails = array();
        foreach ( $lines as $line ) {
            $email = trim( $line );
            if ( is_email( $email ) ) {
                $emails[] = $email;
            }
        }
        return array_unique( $emails );
    }

    private static function get_email_domain() {
        $site_url = parse_url( site_url(), PHP_URL_HOST );
        return $site_url ? $site_url : 'localhost';
    }
}
