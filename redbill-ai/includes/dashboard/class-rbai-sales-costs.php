<?php
/**
 * Shortcode [glovo_sales_costs_dashboard] — vendite & costi mensili con Gemini AI.
 * Tenant-aware: ogni istanza usa il DB del tenant corrente.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Sales_Costs {

    private mysqli|false $connection = null;
    private string       $table_name = 'gsr_glovo_fatture';
    private array        $db_config;

    private static array $mesi_it = [
        '01' => 'Gennaio',  '02' => 'Febbraio', '03' => 'Marzo',
        '04' => 'Aprile',   '05' => 'Maggio',   '06' => 'Giugno',
        '07' => 'Luglio',   '08' => 'Agosto',   '09' => 'Settembre',
        '10' => 'Ottobre',  '11' => 'Novembre', '12' => 'Dicembre',
    ];

    private static array $voci_costo = [
        ['key' => 'commissioni',              'label' => 'Commissioni',                'col' => 'commissioni',                    'color' => '#2196F3', 'is_credit' => false],
        ['key' => 'marketing_visibilita',     'label' => 'Marketing & Visibilità',     'col' => 'marketing_visibilita',           'color' => '#9C27B0', 'is_credit' => false],
        ['key' => 'supplemento_prime',        'label' => 'Supplemento Glovo Prime',    'col' => 'supplemento_ordine_glovo_prime', 'color' => '#FF9800', 'is_credit' => false],
        ['key' => 'promo_consegna_partner',   'label' => 'Promo Consegna Partner',     'col' => 'promo_consegna_partner',         'color' => '#00BCD4', 'is_credit' => false],
        ['key' => 'costi_offerta_lampo',      'label' => 'Costi Offerta Lampo',        'col' => 'costi_offerta_lampo',            'color' => '#FF5722', 'is_credit' => false],
        ['key' => 'promo_lampo_partner',      'label' => 'Promo Lampo Partner',        'col' => 'promo_lampo_partner',            'color' => '#E91E63', 'is_credit' => false],
        ['key' => 'costo_incidenti',          'label' => 'Costi Incidenti',            'col' => 'costo_incidenti_prodotti',       'color' => '#795548', 'is_credit' => false],
        ['key' => 'promo_prodotti_partner',   'label' => 'Promo Prodotti Partner',     'col' => 'promo_prodotti_partner',         'color' => '#607D8B', 'is_credit' => false],
        ['key' => 'tariffa_attesa',           'label' => 'Tariffa Tempo Attesa',       'col' => 'tariffa_tempo_attesa',           'color' => '#FFC107', 'is_credit' => false],
        ['key' => 'comm_ordini_rimborsati',   'label' => 'Comm. Ordini Rimborsati',    'col' => 'commissione_ordini_rimborsati',  'color' => '#F44336', 'is_credit' => false],
        ['key' => 'rimborsi_partner',         'label' => 'Rimborsi Partner',           'col' => 'ordini_rimborsati_partner',      'color' => '#4CAF50', 'is_credit' => true],
        ['key' => 'sconto_comm_buoni_pasto',  'label' => 'Sconto Comm. Buoni Pasto',   'col' => 'sconto_comm_ordini_buoni_pasto', 'color' => '#8BC34A', 'is_credit' => true],
    ];

    public function __construct(array $db_config) {
        $this->db_config  = $db_config;
        $this->table_name = $db_config['db_table'] ?? 'gsr_glovo_fatture';
    }

    // ─── Shortcode entry point ───────────────────────────────────────────────

    public static function render($atts): string {
        $tenant = RBAI_Tenant::current();
        if (!$tenant) {
            return '<p>' . esc_html__('Effettua il login per accedere alla dashboard.', 'redbill-ai') . '</p>';
        }
        if (!$tenant->is_active()) {
            return '<p>' . esc_html__('Account sospeso o in attesa di approvazione.', 'redbill-ai') . '</p>';
        }

        $inst         = new self($tenant->get_db_config());
        $default_from = date('Y-m-01', strtotime('-11 months'));
        $default_to   = date('Y-m-d');

        $date_from    = sanitize_text_field($_GET['scd_from'] ?? $default_from);
        $date_to      = sanitize_text_field($_GET['scd_to']   ?? $default_to);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = $default_from; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to   = $default_to; }

        $stores        = $inst->get_store_options();
        $scd_negozio   = sanitize_text_field($_GET['scd_negozio'] ?? '');
        $neg_filter    = $scd_negozio !== '' ? ['type' => 'exact', 'value' => $scd_negozio] : null;

        $monthly_rows  = $inst->get_monthly_data($date_from, $date_to, $neg_filter);
        $gemini_ok     = RBAI_Billing::tenant_has_feature($tenant, 'gemini_ai') && RBAI_Settings::get_gemini_api_key();

        $export_url = add_query_arg([
            'scd_download' => '1',
            'scd_from'     => $date_from,
            'scd_to'       => $date_to,
            'scd_negozio'  => $scd_negozio,
            '_wpnonce'     => wp_create_nonce('rbai_scd_export'),
        ]);

        ob_start();
        ?>
        <div class="gid-scd-wrapper">
            <h2><?php esc_html_e('Vendite & Costi Mensili', 'redbill-ai'); ?></h2>

            <form method="get" class="gid-filters gid-scd-filters">
                <div class="gid-filter-row">
                    <div class="gid-filter-group">
                        <label><?php esc_html_e('Dal', 'redbill-ai'); ?></label>
                        <input type="date" name="scd_from" value="<?php echo esc_attr($date_from); ?>">
                    </div>
                    <div class="gid-filter-group">
                        <label><?php esc_html_e('Al', 'redbill-ai'); ?></label>
                        <input type="date" name="scd_to" value="<?php echo esc_attr($date_to); ?>">
                    </div>
                    <div class="gid-filter-group">
                        <label><?php esc_html_e('Negozio', 'redbill-ai'); ?></label>
                        <select name="scd_negozio">
                            <option value=""><?php esc_html_e('Tutti', 'redbill-ai'); ?></option>
                            <?php foreach ($stores as $s): ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($scd_negozio, $s); ?>><?php echo esc_html($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="gid-filter-actions">
                    <button type="submit" class="gid-btn gid-btn-primary"><?php esc_html_e('Applica', 'redbill-ai'); ?></button>
                    <a href="?" class="gid-btn gid-btn-secondary"><?php esc_html_e('Reset', 'redbill-ai'); ?></a>
                    <a href="<?php echo esc_url($export_url); ?>" class="gid-btn gid-btn-secondary"><?php esc_html_e('Scarica TXT per AI', 'redbill-ai'); ?></a>
                    <?php if ($gemini_ok): ?>
                    <button type="button" id="rbai-gemini-btn" class="gid-btn gid-btn-primary" data-from="<?php echo esc_attr($date_from); ?>" data-to="<?php echo esc_attr($date_to); ?>" data-negozio="<?php echo esc_attr($scd_negozio); ?>">
                        <?php esc_html_e('Analizza con Gemini AI', 'redbill-ai'); ?>
                    </button>
                    <?php else: ?>
                    <span class="gid-btn" style="opacity:.5;cursor:not-allowed;" title="<?php esc_attr_e('Richiede piano Pro', 'redbill-ai'); ?>"><?php esc_html_e('Analisi Gemini (Pro)', 'redbill-ai'); ?></span>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Grafico mensile -->
            <canvas id="gid-scd-chart" style="margin-top:20px;max-height:400px;"></canvas>

            <!-- Tabella dati mensili -->
            <?php if (!empty($monthly_rows)): ?>
            <div class="gid-table-responsive" style="margin-top:24px;">
                <table class="gid-invoice-table gid-scd-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Mese', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Vendite €', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Costi Glovo €', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('% Costi', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Bonifico €', 'redbill-ai'); ?></th>
                            <?php foreach (self::$voci_costo as $v): ?>
                            <th style="font-size:11px;"><?php echo esc_html($v['label']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthly_rows as $row):
                            $perc = $row['vendite'] > 0 ? ($row['costi_glovo'] / $row['vendite']) * 100 : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html(self::mese_label($row['mese'])); ?></strong></td>
                            <td class="gid-currency">€ <?php echo number_format((float)$row['vendite'], 2, ',', '.'); ?></td>
                            <td class="gid-currency">€ <?php echo number_format((float)$row['costi_glovo'], 2, ',', '.'); ?></td>
                            <td class="<?php echo $perc > 28 ? 'gid-alert-critico-text' : ($perc >= 25 ? 'gid-alert-attenzione-text' : ''); ?>">
                                <?php echo number_format($perc, 2, ',', '.'); ?>%
                            </td>
                            <td class="gid-currency">€ <?php echo number_format((float)($row['importo_bonifico'] ?? 0), 2, ',', '.'); ?></td>
                            <?php foreach (self::$voci_costo as $v): ?>
                            <td class="gid-currency" style="font-size:11px;">€ <?php echo number_format((float)($row[$v['key']] ?? 0), 2, ',', '.'); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p style="margin-top:20px;"><?php esc_html_e('Nessun dato disponibile per il periodo selezionato.', 'redbill-ai'); ?></p>
            <?php endif; ?>

            <!-- Area risposta Gemini -->
            <?php if ($gemini_ok): ?>
            <div id="rbai-gemini-result" style="display:none;margin-top:24px;" class="gid-gemini-result">
                <div id="rbai-gemini-loading" style="display:none;"><?php esc_html_e('Analisi in corso con Gemini AI...', 'redbill-ai'); ?></div>
                <div id="rbai-gemini-content"></div>
            </div>
            <?php endif; ?>

            <!-- Dati per JS -->
            <script>
            window.rbaiScdData = <?php echo wp_json_encode([
                'rows'       => $monthly_rows,
                'voci_costo' => array_map(fn($v) => ['key' => $v['key'], 'label' => $v['label'], 'color' => $v['color']], self::$voci_costo),
            ]); ?>;
            </script>
        </div>
        <?php
        return ob_get_clean();
    }

    // ─── Query ───────────────────────────────────────────────────────────────

    private function get_connection(): mysqli|false {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $conn = new mysqli(
            $this->db_config['db_host'],
            $this->db_config['db_user'],
            $this->db_config['db_pass'],
            $this->db_config['db_name']
        );
        if ($conn->connect_errno) {
            return false;
        }
        $conn->set_charset('utf8mb4');
        $this->connection = $conn;
        return $this->connection;
    }

    public function get_monthly_data(string $date_from, string $date_to, ?array $negozio_filter = null, string $date_field = 'periodo_da'): array {
        $conn = $this->get_connection();
        if (!$conn) {
            return [];
        }

        $date_col    = "COALESCE({$date_field}, data)";
        $voce_sels   = array_map(fn($v) => "SUM(ABS(IFNULL({$v['col']}, 0))) AS {$v['key']}", self::$voci_costo);
        $voce_sql    = implode(",\n                ", $voce_sels);

        $negozio_sql   = '';
        $negozio_type  = '';
        $negozio_value = null;

        if ($negozio_filter !== null) {
            if ($negozio_filter['type'] === 'prefix') {
                $negozio_sql   = 'AND negozio LIKE ?';
                $negozio_type  = 's';
                $negozio_value = $negozio_filter['value'] . '%';
            } else {
                $negozio_sql   = 'AND negozio = ?';
                $negozio_type  = 's';
                $negozio_value = $negozio_filter['value'];
            }
        }

        $query = "SELECT
                DATE_FORMAT({$date_col}, '%Y-%m') AS mese,
                SUM(ABS(IFNULL(prodotti, 0))) AS vendite,
                SUM(
                    ABS(IFNULL(commissioni, 0)) + ABS(IFNULL(marketing_visibilita, 0))
                    + ABS(IFNULL(supplemento_ordine_glovo_prime, 0)) + ABS(IFNULL(promo_consegna_partner, 0))
                    + ABS(IFNULL(costi_offerta_lampo, 0)) + ABS(IFNULL(promo_lampo_partner, 0))
                    + ABS(IFNULL(costo_incidenti_prodotti, 0)) + ABS(IFNULL(promo_prodotti_partner, 0))
                    + ABS(IFNULL(tariffa_tempo_attesa, 0)) + ABS(IFNULL(commissione_ordini_rimborsati, 0))
                    - ABS(IFNULL(ordini_rimborsati_partner, 0)) - ABS(IFNULL(sconto_comm_ordini_buoni_pasto, 0))
                ) AS costi_glovo,
                {$voce_sql},
                SUM(IFNULL(importo_bonifico, 0)) AS importo_bonifico
            FROM `{$this->table_name}`
            WHERE {$date_col} >= ? AND {$date_col} <= ? {$negozio_sql}
            GROUP BY DATE_FORMAT({$date_col}, '%Y-%m')
            ORDER BY mese ASC";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return [];
        }

        if ($negozio_filter !== null) {
            $stmt->bind_param('ss' . $negozio_type, $date_from, $date_to, $negozio_value);
        } else {
            $stmt->bind_param('ss', $date_from, $date_to);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    private function get_store_options(): array {
        $conn = $this->get_connection();
        if (!$conn) {
            return [];
        }
        $result = $conn->query("SELECT DISTINCT negozio FROM `{$this->table_name}` WHERE negozio IS NOT NULL AND negozio != '' ORDER BY negozio ASC");
        if (!$result) {
            return [];
        }
        $stores = [];
        while ($row = $result->fetch_assoc()) {
            $stores[] = $row['negozio'];
        }
        return $stores;
    }

    public function get_invoice_count(): int {
        $conn = $this->get_connection();
        if (!$conn) {
            return 0;
        }
        $r = $conn->query("SELECT COUNT(*) AS cnt FROM `{$this->table_name}`");
        return $r ? (int) $r->fetch_assoc()['cnt'] : 0;
    }

    public function get_last_invoice_date(): string {
        $conn = $this->get_connection();
        if (!$conn) {
            return date('Y-m-d');
        }
        $stmt = $conn->prepare("SELECT MAX(COALESCE(periodo_a, data)) FROM `{$this->table_name}`");
        if (!$stmt) {
            return date('Y-m-d');
        }
        $stmt->execute();
        $stmt->bind_result($max_date);
        $stmt->fetch();
        $stmt->close();
        return $max_date ?: date('Y-m-d');
    }

    public function get_monthly_data_for_email(string $from, string $to, ?array $neg_filter = null): array {
        return $this->get_monthly_data($from, $to, $neg_filter, 'periodo_a');
    }

    // ─── AJAX Gemini ─────────────────────────────────────────────────────────

    public static function handle_gemini_analyze(): void {
        check_ajax_referer('rbai_ajax_nonce', 'nonce');

        $tenant = RBAI_Tenant::current();
        if (!$tenant || !$tenant->is_active()) {
            wp_send_json_error(['message' => 'Accesso non autorizzato.'], 403);
        }

        if (!RBAI_Billing::tenant_has_feature($tenant, 'gemini_ai')) {
            wp_send_json_error(['message' => 'Funzionalità disponibile solo per piani Pro ed Enterprise.']);
        }

        $api_key = RBAI_Settings::get_gemini_api_key();
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'Chiave API Gemini non configurata.']);
        }

        $inst         = new self($tenant->get_db_config());
        $default_from = date('Y-m-01', strtotime('-11 months'));
        $default_to   = date('Y-m-d');

        $date_from = sanitize_text_field($_POST['scd_from'] ?? $default_from);
        $date_to   = sanitize_text_field($_POST['scd_to']   ?? $default_to);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = $default_from; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to   = $default_to; }

        $neg_raw    = sanitize_text_field($_POST['scd_negozio'] ?? '');
        $neg_filter = $neg_raw !== '' ? ['type' => 'exact', 'value' => $neg_raw] : null;

        $monthly_rows = $inst->get_monthly_data($date_from, $date_to, $neg_filter);
        $data_text    = self::generate_export_text($monthly_rows, $date_from, $date_to, $neg_raw);

        $prompt_file = RBAI_PLUGIN_DIR . 'prompts/gemini-analysis-prompt.txt';
        $prompt      = file_exists($prompt_file) ? file_get_contents($prompt_file) : '';
        $full_prompt = $prompt . $data_text;

        $api_url  = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;
        $response = wp_remote_post($api_url, [
            'timeout' => 120,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'contents'         => [['parts' => [['text' => $full_prompt]]]],
                'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 32768],
            ]),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Errore di connessione: ' . $response->get_error_message()]);
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $decoded   = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code !== 200) {
            wp_send_json_error(['message' => 'Errore API Gemini: ' . ($decoded['error']['message'] ?? 'HTTP ' . $http_code)]);
        }

        $report = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (empty($report)) {
            wp_send_json_error(['message' => 'Risposta Gemini vuota.']);
        }

        wp_send_json_success(['report' => $report, 'finish_reason' => $decoded['candidates'][0]['finishReason'] ?? '']);
    }

    // ─── Export TXT ──────────────────────────────────────────────────────────

    public static function handle_export(): void {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'rbai_scd_export')) {
            wp_die('Richiesta non autorizzata.');
        }

        $tenant = RBAI_Tenant::current();
        if (!$tenant || !$tenant->is_active()) {
            wp_die('Accesso non autorizzato.');
        }

        $inst         = new self($tenant->get_db_config());
        $default_from = date('Y-m-01', strtotime('-11 months'));
        $default_to   = date('Y-m-d');

        $date_from = sanitize_text_field($_GET['scd_from'] ?? $default_from);
        $date_to   = sanitize_text_field($_GET['scd_to']   ?? $default_to);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = $default_from; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to   = $default_to; }

        $neg_raw    = sanitize_text_field($_GET['scd_negozio'] ?? '');
        $neg_filter = $neg_raw !== '' ? ['type' => 'exact', 'value' => $neg_raw] : null;

        $monthly_rows = $inst->get_monthly_data($date_from, $date_to, $neg_filter);
        $text         = self::generate_export_text($monthly_rows, $date_from, $date_to, $neg_raw);

        $slug     = $neg_raw ? '-' . sanitize_title($neg_raw) : '';
        $filename = 'glovo-analisi-' . date('Y-m-d') . $slug . '.txt';

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private static function generate_export_text(array $rows, string $from, string $to, string $neg_label): string {
        $lines   = [];
        $lines[] = "# DATI VENDITE & COSTI GLOVO";
        $lines[] = "Periodo: {$from} – {$to}";
        if ($neg_label) {
            $lines[] = "Negozio: {$neg_label}";
        }
        $lines[] = '';

        foreach ($rows as $row) {
            $label   = self::mese_label($row['mese']);
            $perc    = $row['vendite'] > 0 ? round($row['costi_glovo'] / $row['vendite'] * 100, 2) : 0;
            $lines[] = "## {$label}";
            $lines[] = "Vendite: € " . number_format($row['vendite'], 2, ',', '.');
            $lines[] = "Costi Glovo: € " . number_format($row['costi_glovo'], 2, ',', '.') . " ({$perc}%)";
            $lines[] = "Bonifico: € " . number_format($row['importo_bonifico'] ?? 0, 2, ',', '.');
            foreach (self::$voci_costo as $v) {
                $val = $row[$v['key']] ?? 0;
                if ($val != 0) {
                    $lines[] = "  {$v['label']}: € " . number_format($val, 2, ',', '.');
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private static function mese_label(string $ym): string {
        [$anno, $num] = explode('-', $ym) + ['', ''];
        return (self::$mesi_it[$num] ?? $num) . ' ' . $anno;
    }

    public function __destruct() {
        if ($this->connection) {
            $this->connection->close();
        }
    }
}
