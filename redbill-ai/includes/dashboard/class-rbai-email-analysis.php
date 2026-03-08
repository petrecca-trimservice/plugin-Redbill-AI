<?php
/**
 * Analisi comparativa via email con Gemini AI — tenant-aware.
 * Confronta ultimi 30 giorni vs 30 giorni precedenti per ogni negozio.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Email_Analysis {

    const OPT_RECIPIENTS = 'rbai_email_recipients';
    const OPT_ENABLED    = 'rbai_email_enabled';
    const OPT_LAST_SENT  = 'rbai_email_last_sent';

    public static function register_hooks(): void {
        add_action('admin_menu', [__CLASS__, 'add_admin_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_ajax_rbai_send_email_analysis', [__CLASS__, 'handle_ajax_send']);
    }

    // ─── Pagina admin ────────────────────────────────────────────────────────

    public static function add_admin_page(): void {
        add_submenu_page(
            'redbill-ai',
            __('Analisi Email', 'redbill-ai'),
            __('Analisi Email', 'redbill-ai'),
            'manage_options',
            'rbai-email-analysis',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function register_settings(): void {
        register_setting('rbai_email_analysis_group', self::OPT_ENABLED,    ['type' => 'string', 'default' => '0']);
        register_setting('rbai_email_analysis_group', self::OPT_RECIPIENTS, ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field']);
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $enabled    = get_option(self::OPT_ENABLED, '0');
        $recipients = get_option(self::OPT_RECIPIENTS, '');
        $last_sent  = get_option(self::OPT_LAST_SENT, '');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Analisi Email Glovo', 'redbill-ai'); ?></h1>
            <p><?php esc_html_e('Configura l\'invio automatico di analisi comparative via email. Il confronto copre gli ultimi 30 giorni rispetto ai 30 giorni precedenti, per ogni negozio.', 'redbill-ai'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields('rbai_email_analysis_group'); ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Abilita invio automatico', 'redbill-ai'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo self::OPT_ENABLED; ?>" value="1" <?php checked($enabled, '1'); ?>>
                                <?php esc_html_e('Invia report settimanale automatico', 'redbill-ai'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="rbai-recipients"><?php esc_html_e('Destinatari', 'redbill-ai'); ?></label></th>
                        <td>
                            <textarea id="rbai-recipients" name="<?php echo self::OPT_RECIPIENTS; ?>" rows="4" class="large-text"><?php echo esc_textarea($recipients); ?></textarea>
                            <p class="description"><?php esc_html_e('Un indirizzo email per riga.', 'redbill-ai'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Salva Impostazioni', 'redbill-ai')); ?>
            </form>

            <?php if ($last_sent): ?>
            <p><?php printf(esc_html__('Ultima analisi inviata: %s', 'redbill-ai'), esc_html($last_sent)); ?></p>
            <?php endif; ?>

            <hr>
            <h2><?php esc_html_e('Invio Manuale', 'redbill-ai'); ?></h2>
            <p><?php esc_html_e('Invia subito una analisi comparativa a tutti i tenant attivi.', 'redbill-ai'); ?></p>
            <button type="button" id="rbai-send-analysis-btn" class="button button-primary">
                <?php esc_html_e('Invia Analisi Ora', 'redbill-ai'); ?>
            </button>
            <span id="rbai-analysis-status" style="margin-left:10px;"></span>
        </div>
        <?php
    }

    // ─── AJAX ────────────────────────────────────────────────────────────────

    public static function handle_ajax_send(): void {
        check_ajax_referer('rbai_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Accesso non autorizzato.'], 403);
        }

        $api_key = RBAI_Settings::get_gemini_api_key();
        if (!$api_key) {
            wp_send_json_error(['message' => 'Chiave Gemini non configurata.']);
        }

        $tenants = RBAI_Tenant::get_all('active');
        $sent    = 0;

        foreach ($tenants as $tenant) {
            if (!RBAI_Billing::tenant_has_feature($tenant, 'email_analysis')) {
                continue;
            }
            $result = self::send_for_tenant($tenant, $api_key);
            if ($result) {
                $sent++;
            }
        }

        update_option(self::OPT_LAST_SENT, date('Y-m-d H:i:s'));
        wp_send_json_success(['message' => sprintf(__('Analisi inviata a %d tenant.', 'redbill-ai'), $sent)]);
    }

    // ─── Logica invio ────────────────────────────────────────────────────────

    private static function send_for_tenant(RBAI_Tenant $tenant, string $api_key): bool {
        $scd  = new RBAI_Sales_Costs($tenant->get_db_config());
        $last = $scd->get_last_invoice_date();

        $to_date   = $last;
        $from_date = date('Y-m-d', strtotime($to_date . ' -30 days'));
        $prev_from = date('Y-m-d', strtotime($from_date . ' -30 days'));
        $prev_to   = date('Y-m-d', strtotime($from_date . ' -1 day'));

        $current  = $scd->get_monthly_data_for_email($from_date, $to_date);
        $previous = $scd->get_monthly_data_for_email($prev_from, $prev_to);

        if (empty($current)) {
            return false;
        }

        $prompt_file = RBAI_PLUGIN_DIR . 'prompts/gemini-comparison-prompt.txt';
        $prompt      = file_exists($prompt_file) ? file_get_contents($prompt_file) : '';

        $data_text = "=== PERIODO CORRENTE ({$from_date} – {$to_date}) ===\n";
        foreach ($current as $row) {
            $data_text .= "Mese {$row['mese']}: Vendite € {$row['vendite']}, Costi € {$row['costi_glovo']}\n";
        }
        $data_text .= "\n=== PERIODO PRECEDENTE ({$prev_from} – {$prev_to}) ===\n";
        foreach ($previous as $row) {
            $data_text .= "Mese {$row['mese']}: Vendite € {$row['vendite']}, Costi € {$row['costi_glovo']}\n";
        }

        $full_prompt = $prompt . "\n\n" . $data_text;
        $api_url     = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;

        $response = wp_remote_post($api_url, [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'contents'         => [['parts' => [['text' => $full_prompt]]]],
                'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 8192],
            ]),
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $report  = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($report)) {
            return false;
        }

        // Invia email al tenant
        $to      = $tenant->get_email();
        $subject = sprintf(__('[Redbill AI] Report Analisi Glovo — %s', 'redbill-ai'), $from_date . ' → ' . $to_date);
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $body    = '<html><body>' . nl2br(esc_html($report)) . '</body></html>';

        return wp_mail($to, $subject, $body, $headers);
    }
}
