<?php
/**
 * Dashboard Vendite & Costi Glovo — Riepilogo mensile
 * Shortcode: [glovo_sales_costs_dashboard]
 */

if (!defined('ABSPATH')) {
    exit;
}

class GID_Sales_Costs_Dashboard {

    private $db_config;
    private $connection;
    private $table_name;

    private static $mesi_it = array(
        '01' => 'Gennaio',  '02' => 'Febbraio', '03' => 'Marzo',
        '04' => 'Aprile',   '05' => 'Maggio',   '06' => 'Giugno',
        '07' => 'Luglio',   '08' => 'Agosto',   '09' => 'Settembre',
        '10' => 'Ottobre',  '11' => 'Novembre', '12' => 'Dicembre',
    );

    /**
     * Gruppi di negozi predefiniti per il filtro.
     * type 'prefix' → WHERE negozio LIKE '<value>%'
     * type 'exact'  → WHERE negozio = '<value>'
     */
    private static $store_groups = array(
        'girarrosti' => array(
            'label' => 'Girarrosti',
            'type'  => 'prefix',
            'value' => 'Girarrosti Santa Rita - ',
        ),
        'mimmos' => array(
            'label' => "Mimmo's Pollo Fritto",
            'type'  => 'exact',
            'value' => "Mimmo's Pollo Fritto",
        ),
        'piedmont' => array(
            'label' => 'Piedmont Burgers',
            'type'  => 'exact',
            'value' => 'Piedmont Burgers',
        ),
    );

    /**
     * Definizione di tutte le voci di costo Glovo.
     * is_credit = true → la voce è una detrazione (riduce il totale costi).
     */
    private static $voci_costo = array(
        array('key' => 'commissioni',                   'label' => 'Commissioni',                   'col' => 'commissioni',                    'color' => '#2196F3', 'is_credit' => false),
        array('key' => 'marketing_visibilita',          'label' => 'Marketing & Visibilità',        'col' => 'marketing_visibilita',           'color' => '#9C27B0', 'is_credit' => false),
        array('key' => 'supplemento_prime',             'label' => 'Supplemento Glovo Prime',       'col' => 'supplemento_ordine_glovo_prime', 'color' => '#FF9800', 'is_credit' => false),
        array('key' => 'promo_consegna_partner',        'label' => 'Promo Consegna Partner',        'col' => 'promo_consegna_partner',         'color' => '#00BCD4', 'is_credit' => false),
        array('key' => 'costi_offerta_lampo',           'label' => 'Costi Offerta Lampo',           'col' => 'costi_offerta_lampo',            'color' => '#FF5722', 'is_credit' => false),
        array('key' => 'promo_lampo_partner',           'label' => 'Promo Lampo Partner',           'col' => 'promo_lampo_partner',            'color' => '#E91E63', 'is_credit' => false),
        array('key' => 'costo_incidenti',               'label' => 'Costi Incidenti',               'col' => 'costo_incidenti_prodotti',       'color' => '#795548', 'is_credit' => false),
        array('key' => 'promo_prodotti_partner',        'label' => 'Promo Prodotti Partner',        'col' => 'promo_prodotti_partner',         'color' => '#607D8B', 'is_credit' => false),
        array('key' => 'tariffa_attesa',                'label' => 'Tariffa Tempo Attesa',          'col' => 'tariffa_tempo_attesa',           'color' => '#FFC107', 'is_credit' => false),
        array('key' => 'comm_ordini_rimborsati',        'label' => 'Comm. Ordini Rimborsati',       'col' => 'commissione_ordini_rimborsati',  'color' => '#F44336', 'is_credit' => false),
        array('key' => 'rimborsi_partner',              'label' => 'Rimborsi Partner',              'col' => 'ordini_rimborsati_partner',      'color' => '#4CAF50', 'is_credit' => true),
        array('key' => 'sconto_comm_buoni_pasto',       'label' => 'Sconto Comm. Buoni Pasto',      'col' => 'sconto_comm_ordini_buoni_pasto', 'color' => '#8BC34A', 'is_credit' => true),
    );

    public function __construct() {
        $this->db_config  = $this->load_config();
        $this->table_name = $this->db_config['db_table'];
        $this->connection = null;
    }

    // -------------------------------------------------------------------------
    // Config & connessione
    // -------------------------------------------------------------------------

    private function load_config() {
        $possible_paths = array(
            ABSPATH . 'httpdocs/scripts-glovo/config-glovo.php',
            ABSPATH . 'scripts-glovo/config-glovo.php',
            ABSPATH . '../httpdocs/scripts-glovo/config-glovo.php',
            dirname(ABSPATH) . '/httpdocs/scripts-glovo/config-glovo.php',
            dirname(dirname(ABSPATH)) . '/scripts-glovo/config-glovo.php',
            $_SERVER['DOCUMENT_ROOT'] . '/httpdocs/scripts-glovo/config-glovo.php',
            $_SERVER['DOCUMENT_ROOT'] . '/scripts-glovo/config-glovo.php',
            GID_PLUGIN_DIR . '../../../scripts-glovo/config-glovo.php',
            GID_PLUGIN_DIR . '../../../../httpdocs/scripts-glovo/config-glovo.php',
        );

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $config = require $path;
                if (is_array($config) && isset($config['db_name']) && isset($config['db_table'])) {
                    return array_merge(array('db_charset' => 'utf8mb4'), $config);
                }
            }
        }

        if (file_exists(GID_PLUGIN_DIR . 'config-db.php')) {
            return require GID_PLUGIN_DIR . 'config-db.php';
        }

        wp_die('Glovo Invoice Dashboard: File di configurazione database non trovato.');
    }

    private function get_connection() {
        if ($this->connection === null) {
            $this->connection = new mysqli(
                $this->db_config['db_host'],
                $this->db_config['db_user'],
                $this->db_config['db_pass'],
                $this->db_config['db_name']
            );

            if ($this->connection->connect_error) {
                error_log('GID_Sales_Costs_Dashboard DB Error: ' . $this->connection->connect_error);
                return false;
            }

            $this->connection->set_charset($this->db_config['db_charset']);
        }

        return $this->connection;
    }

    public function close_connection() {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    public function __destruct() {
        $this->close_connection();
    }

    // -------------------------------------------------------------------------
    // Getters statici per uso esterno
    // -------------------------------------------------------------------------

    public static function get_store_groups() {
        return self::$store_groups;
    }

    public function get_invoice_count() {
        $conn = $this->get_connection();
        if ( ! $conn ) {
            return 0;
        }
        $result = $conn->query( "SELECT COUNT(*) AS cnt FROM {$this->table_name}" );
        if ( $result && $row = $result->fetch_assoc() ) {
            return (int) $row['cnt'];
        }
        return 0;
    }

    // -------------------------------------------------------------------------
    // Query
    // -------------------------------------------------------------------------

    /**
     * Variante di get_monthly_data() per l'analisi email: filtra su periodo_a
     * invece di periodo_da, così le fatture vengono attribuite al periodo in cui
     * si chiudono (evita il problema dei cicli Glovo a cavallo della finestra).
     */
    public function get_monthly_data_for_email($date_from, $date_to, $negozio_filter = null) {
        return $this->get_monthly_data($date_from, $date_to, $negozio_filter, 'periodo_a');
    }

    /**
     * Restituisce l'ultima data disponibile nel DB (su tutti i negozi),
     * usando COALESCE(periodo_a, data) come nell'analisi email.
     * Serve ad ancorare i periodi di confronto all'ultimo dato disponibile
     * indipendentemente dal ciclo di fatturazione bimensile.
     *
     * @return string  Data Y-m-d, o oggi come fallback
     */
    public function get_last_invoice_date() {
        $conn = $this->get_connection();
        if ( ! $conn ) {
            return date( 'Y-m-d' );
        }

        $query = "SELECT MAX(COALESCE(periodo_a, data)) FROM {$this->table_name}";
        $stmt  = $conn->prepare( $query );
        if ( ! $stmt ) {
            return date( 'Y-m-d' );
        }

        $stmt->execute();
        $stmt->bind_result( $max_date );
        $stmt->fetch();
        $stmt->close();

        return $max_date ?: date( 'Y-m-d' );
    }

    /**
     * Dati aggregati per mese: totale vendite, totale costi Glovo e
     * ogni singola voce di costo, raggruppati per periodo_da (o data).
     */
    public function get_monthly_data($date_from, $date_to, $negozio_filter = null, $date_field = 'periodo_da') {
        $conn = $this->get_connection();
        if (!$conn) {
            return array();
        }

        $date_col = "COALESCE({$date_field}, data)";

        // Costruisci le colonne delle singole voci
        $voce_selects = array();
        foreach (self::$voci_costo as $voce) {
            $voce_selects[] = "SUM(ABS(IFNULL({$voce['col']}, 0))) AS {$voce['key']}";
        }
        $voce_sql = implode(",\n                ", $voce_selects);

        // Clausola negozio opzionale
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

        $query = "
            SELECT
                DATE_FORMAT({$date_col}, '%Y-%m') AS mese,
                SUM(ABS(IFNULL(prodotti, 0)))       AS vendite,
                SUM(
                    ABS(IFNULL(commissioni, 0))
                    + ABS(IFNULL(marketing_visibilita, 0))
                    + ABS(IFNULL(supplemento_ordine_glovo_prime, 0))
                    + ABS(IFNULL(promo_consegna_partner, 0))
                    + ABS(IFNULL(costi_offerta_lampo, 0))
                    + ABS(IFNULL(promo_lampo_partner, 0))
                    + ABS(IFNULL(costo_incidenti_prodotti, 0))
                    + ABS(IFNULL(promo_prodotti_partner, 0))
                    + ABS(IFNULL(tariffa_tempo_attesa, 0))
                    + ABS(IFNULL(commissione_ordini_rimborsati, 0))
                    - ABS(IFNULL(ordini_rimborsati_partner, 0))
                    - ABS(IFNULL(sconto_comm_ordini_buoni_pasto, 0))
                )                                   AS costi_glovo,
                {$voce_sql},
                SUM(ABS(IFNULL(buoni_pasto, 0)))       AS buoni_pasto,
                SUM(ABS(IFNULL(glovo_gia_pagati, 0)))  AS glovo_gia_pagati,
                SUM(IFNULL(importo_bonifico, 0))       AS importo_bonifico
            FROM {$this->table_name}
            WHERE {$date_col} >= ?
              AND {$date_col} <= ?
              {$negozio_sql}
            GROUP BY DATE_FORMAT({$date_col}, '%Y-%m')
            ORDER BY mese ASC
        ";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log('GID_Sales_Costs_Dashboard prepare error: ' . $conn->error);
            return array();
        }

        if ($negozio_filter !== null) {
            $stmt->bind_param('ss' . $negozio_type, $date_from, $date_to, $negozio_value);
        } else {
            $stmt->bind_param('ss', $date_from, $date_to);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = array();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();
        return $rows;
    }

    /**
     * Restituisce i valori distinti di negozio presenti in tabella,
     * ordinati alfabeticamente. Usato per popolare il dropdown del filtro.
     *
     * @return string[]
     */
    private function get_store_options() {
        $conn = $this->get_connection();
        if (!$conn) {
            return array();
        }

        $query = "SELECT DISTINCT negozio FROM {$this->table_name}
                  WHERE negozio IS NOT NULL AND negozio != ''
                  ORDER BY negozio ASC";

        $result = $conn->query($query);
        if (!$result) {
            error_log('GID_Sales_Costs_Dashboard get_store_options error: ' . $conn->error);
            return array();
        }

        $stores = array();
        while ($row = $result->fetch_assoc()) {
            $stores[] = $row['negozio'];
        }
        $result->close();
        return $stores;
    }

    // -------------------------------------------------------------------------
    // Formattazione
    // -------------------------------------------------------------------------

    private static function fmt_currency($val) {
        return '€ ' . number_format(floatval($val), 2, ',', '.');
    }

    private static function fmt_pct($val) {
        return number_format(floatval($val), 2, ',', '.') . '%';
    }

    private static function mese_label($ym) {
        $parts = explode('-', $ym);
        $anno  = $parts[0] ?? '';
        $num   = $parts[1] ?? '';
        return (self::$mesi_it[$num] ?? $num) . ' ' . $anno;
    }

    private static function delta_arrow($val) {
        if ($val > 0) {
            return '<span class="gid-scd-delta gid-scd-delta-pos">▲ ' . self::fmt_currency(abs($val)) . '</span>';
        } elseif ($val < 0) {
            return '<span class="gid-scd-delta gid-scd-delta-neg">▼ ' . self::fmt_currency(abs($val)) . '</span>';
        }
        return '<span class="gid-scd-delta gid-scd-delta-neutral">= 0</span>';
    }

    private static function delta_pct_arrow($val, $invert = false) {
        if ($val > 0) {
            $cls = $invert ? 'gid-scd-delta-neg' : 'gid-scd-delta-pos';
            return '<span class="gid-scd-delta ' . $cls . '">▲ ' . self::fmt_pct(abs($val)) . '</span>';
        } elseif ($val < 0) {
            $cls = $invert ? 'gid-scd-delta-pos' : 'gid-scd-delta-neg';
            return '<span class="gid-scd-delta ' . $cls . '">▼ ' . self::fmt_pct(abs($val)) . '</span>';
        }
        return '<span class="gid-scd-delta gid-scd-delta-neutral">= 0%</span>';
    }

    // -------------------------------------------------------------------------
    // Export dati per AI
    // -------------------------------------------------------------------------

    /**
     * Gestisce il download del file .txt per l'analisi AI.
     * Chiamato da template_redirect quando scd_download=1 è presente nell'URL.
     */
    /**
     * Gestisce la chiamata AJAX per l'analisi Gemini.
     * Prende gli stessi dati di handle_export() e li invia all'API Gemini.
     */
    public static function handle_gemini_analyze() {
        check_ajax_referer( 'gid_gemini_nonce', 'nonce' );

        if ( ! defined( 'GID_GEMINI_API_KEY' ) || empty( GID_GEMINI_API_KEY ) ) {
            wp_send_json_error( array( 'message' => 'Chiave API Gemini non configurata in wp-config.php.' ) );
        }

        $inst         = new self();
        $default_from = date( 'Y-m-01', strtotime( '-11 months' ) );
        $default_to   = date( 'Y-m-t' );

        $date_from = isset( $_POST['scd_from'] ) ? sanitize_text_field( $_POST['scd_from'] ) : $default_from;
        $date_to   = isset( $_POST['scd_to'] )   ? sanitize_text_field( $_POST['scd_to'] )   : $default_to;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = $default_from; }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) )   { $date_to   = $default_to; }

        $scd_negozio_raw      = isset( $_POST['scd_negozio'] ) ? sanitize_text_field( wp_unslash( $_POST['scd_negozio'] ) ) : '';
        $negozio_filter       = null;
        $negozio_filter_label = '';
        if ( $scd_negozio_raw !== '' ) {
            if ( isset( self::$store_groups[ $scd_negozio_raw ] ) ) {
                $grp                  = self::$store_groups[ $scd_negozio_raw ];
                $negozio_filter       = array( 'type' => $grp['type'], 'value' => $grp['value'] );
                $negozio_filter_label = $grp['label'];
            } else {
                $negozio_filter       = array( 'type' => 'exact', 'value' => $scd_negozio_raw );
                $negozio_filter_label = $scd_negozio_raw;
            }
        }

        $monthly_rows = $inst->get_monthly_data( $date_from, $date_to, $negozio_filter );
        $data_text    = self::generate_export_text( $monthly_rows, $date_from, $date_to, $negozio_filter_label );

        // Carica il prompt dal file separato
        $prompt_file = GID_PLUGIN_DIR . 'includes/gemini-analysis-prompt.txt';
        $prompt      = file_exists( $prompt_file ) ? file_get_contents( $prompt_file ) : '';

        $full_prompt = $prompt . $data_text;

        // Chiamata all'API Gemini
        $api_url  = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GID_GEMINI_API_KEY;
        $response = wp_remote_post( $api_url, array(
            'timeout'     => 120,
            'headers'     => array( 'Content-Type' => 'application/json' ),
            'body'        => wp_json_encode( array(
                'contents'         => array(
                    array( 'parts' => array( array( 'text' => $full_prompt ) ) ),
                ),
                'generationConfig' => array(
                    'temperature'     => 0.3,
                    'maxOutputTokens' => 32768,
                ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'Errore di connessione: ' . $response->get_error_message() ) );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $decoded   = json_decode( $body, true );

        if ( $http_code !== 200 ) {
            $err_msg = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : 'Errore HTTP ' . $http_code;
            wp_send_json_error( array( 'message' => 'Errore API Gemini: ' . $err_msg ) );
        }

        $report       = isset( $decoded['candidates'][0]['content']['parts'][0]['text'] )
            ? $decoded['candidates'][0]['content']['parts'][0]['text']
            : '';
        $finish_reason = isset( $decoded['candidates'][0]['finishReason'] )
            ? $decoded['candidates'][0]['finishReason']
            : '';

        if ( empty( $report ) ) {
            wp_send_json_error( array( 'message' => 'Risposta Gemini vuota o non valida.' ) );
        }

        wp_send_json_success( array(
            'report'        => $report,
            'finish_reason' => $finish_reason,
        ) );
    }

    public static function handle_export() {
        if ( ! isset( $_GET['_wpnonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'gid_scd_export' ) ) {
            wp_die( 'Richiesta non autorizzata.' );
        }

        $inst         = new self();
        $default_from = date( 'Y-m-01', strtotime( '-11 months' ) );
        $default_to   = date( 'Y-m-t' );

        $date_from = isset( $_GET['scd_from'] ) ? sanitize_text_field( $_GET['scd_from'] ) : $default_from;
        $date_to   = isset( $_GET['scd_to'] )   ? sanitize_text_field( $_GET['scd_to'] )   : $default_to;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = $default_from; }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) )   { $date_to   = $default_to; }

        $scd_negozio_raw      = isset( $_GET['scd_negozio'] ) ? sanitize_text_field( wp_unslash( $_GET['scd_negozio'] ) ) : '';
        $negozio_filter       = null;
        $negozio_filter_label = '';
        if ( $scd_negozio_raw !== '' ) {
            if ( isset( self::$store_groups[ $scd_negozio_raw ] ) ) {
                $grp                  = self::$store_groups[ $scd_negozio_raw ];
                $negozio_filter       = array( 'type' => $grp['type'], 'value' => $grp['value'] );
                $negozio_filter_label = $grp['label'];
            } else {
                $negozio_filter       = array( 'type' => 'exact', 'value' => $scd_negozio_raw );
                $negozio_filter_label = $scd_negozio_raw;
            }
        }

        $monthly_rows = $inst->get_monthly_data( $date_from, $date_to, $negozio_filter );
        $text         = self::generate_export_text( $monthly_rows, $date_from, $date_to, $negozio_filter_label );

        $slug     = $negozio_filter_label ? '-' . sanitize_title( $negozio_filter_label ) : '';
        $filename = 'glovo-analisi-' . date( 'Y-m-d' ) . $slug . '.txt';

        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Genera il testo strutturato (Markdown-like) con tutti i dati della dashboard,
     * ottimizzato per essere incollato in un prompt AI.
     *
     * @param array  $rows                Array di righe mensili da get_monthly_data()
     * @param string $date_from           Data inizio periodo (YYYY-MM-DD)
     * @param string $date_to             Data fine periodo (YYYY-MM-DD)
     * @param string $negozio_filter_label Etichetta del filtro negozio (vuota = tutti)
     * @return string
     */
    public static function generate_export_text( array $rows, $date_from, $date_to, $negozio_filter_label = '' ) {
        // ── Ricalcola struttura dati (come in render) ──────────────────────────
        $data = array();
        $prev = null;
        foreach ( $rows as $row ) {
            $vendite   = floatval( $row['vendite'] );
            $costi     = floatval( $row['costi_glovo'] );
            $margine   = $vendite - $costi;
            $incidenza = ( $vendite > 0 ) ? ( $costi / $vendite * 100 ) : 0;

            $entry = array(
                'mese'            => $row['mese'],
                'mese_label'      => self::mese_label( $row['mese'] ),
                'vendite'         => $vendite,
                'costi_glovo'     => $costi,
                'margine'         => $margine,
                'incidenza'       => $incidenza,
                'delta_vendite'   => null,
                'delta_margine'   => null,
                'delta_incidenza' => null,
                'buoni_pasto'     => floatval( $row['buoni_pasto'] ?? 0 ),
                'glovo_gia_pagati'=> floatval( $row['glovo_gia_pagati'] ?? 0 ),
                'importo_bonifico'=> floatval( $row['importo_bonifico'] ?? 0 ),
            );
            foreach ( self::$voci_costo as $voce ) {
                $entry[ $voce['key'] ] = floatval( $row[ $voce['key'] ] ?? 0 );
            }
            if ( $prev !== null ) {
                $entry['delta_vendite']   = $vendite   - $prev['vendite'];
                $entry['delta_margine']   = $margine   - $prev['margine'];
                $entry['delta_incidenza'] = $incidenza - $prev['incidenza'];
            }
            $data[] = $entry;
            $prev   = array( 'vendite' => $vendite, 'margine' => $margine, 'incidenza' => $incidenza );
        }

        $tot_vendite = array_sum( array_column( $data, 'vendite' ) );
        $tot_costi   = array_sum( array_column( $data, 'costi_glovo' ) );
        $tot_margine = $tot_vendite - $tot_costi;
        $tot_inc     = ( $tot_vendite > 0 ) ? ( $tot_costi / $tot_vendite * 100 ) : 0;
        $num_mesi    = count( $data );

        $tot_voci = array();
        foreach ( self::$voci_costo as $voce ) {
            $tot_voci[ $voce['key'] ] = array_sum( array_column( $data, $voce['key'] ) );
        }
        $tot_buoni_pasto      = array_sum( array_column( $data, 'buoni_pasto' ) );
        $tot_glovo_gia_pagati = array_sum( array_column( $data, 'glovo_gia_pagati' ) );
        $tot_importo_bonifico = array_sum( array_column( $data, 'importo_bonifico' ) );
        $tot_incasso_puro     = $tot_buoni_pasto + $tot_glovo_gia_pagati + $tot_importo_bonifico;

        $mese_migliore = null;
        $mese_peggiore = null;
        foreach ( $data as $d ) {
            if ( $d['vendite'] <= 0 ) { continue; }
            if ( $mese_migliore === null || $d['incidenza'] < $mese_migliore['incidenza'] ) { $mese_migliore = $d; }
            if ( $mese_peggiore === null || $d['incidenza'] > $mese_peggiore['incidenza'] ) { $mese_peggiore = $d; }
        }

        // ── Helper per formato delta testo ─────────────────────────────────────
        $fmt_delta_eur = function( $v ) {
            if ( $v === null ) { return '—'; }
            $sign = $v >= 0 ? '+' : '-';
            return $sign . number_format( abs( $v ), 2, ',', '.' );
        };
        $fmt_delta_pct = function( $v ) {
            if ( $v === null ) { return '—'; }
            $sign = $v >= 0 ? '+' : '-';
            return $sign . number_format( abs( $v ), 2, ',', '.' ) . '%';
        };

        // ── Inizio output ──────────────────────────────────────────────────────
        $nl = "\n";
        $o  = '';

        // Header
        $o .= '# Analisi Vendite & Costi Glovo' . $nl;
        $o .= 'Periodo:   ' . self::mese_label( date( 'Y-m', strtotime( $date_from ) ) )
            . ' → ' . self::mese_label( date( 'Y-m', strtotime( $date_to ) ) ) . $nl;
        $o .= 'Negozio:   ' . ( $negozio_filter_label !== '' ? $negozio_filter_label : 'Tutti i negozi' ) . $nl;
        $o .= 'Esportato: ' . date_i18n( 'Y-m-d H:i' ) . $nl;
        $o .= $nl;

        if ( empty( $data ) ) {
            $o .= 'Nessun dato disponibile per il periodo selezionato.' . $nl;
            return $o;
        }

        // KPI Generali
        $o .= '## KPI Generali' . $nl;
        $o .= 'Totale Vendite:     ' . self::fmt_currency( $tot_vendite ) . $nl;
        $o .= 'Totale Costi Glovo: ' . self::fmt_currency( $tot_costi ) . $nl;
        $o .= 'Incidenza Media:    ' . self::fmt_pct( $tot_inc ) . $nl;
        $o .= 'Margine Totale:     ' . self::fmt_currency( $tot_margine ) . $nl;
        $o .= 'Numero Mesi:        ' . $num_mesi . $nl;
        if ( $mese_migliore ) {
            $o .= 'Mese Migliore:      ' . $mese_migliore['mese_label']
                . '  (incidenza ' . self::fmt_pct( $mese_migliore['incidenza'] ) . ')' . $nl;
        }
        if ( $mese_peggiore && $mese_peggiore !== $mese_migliore ) {
            $o .= 'Mese Peggiore:      ' . $mese_peggiore['mese_label']
                . '  (incidenza ' . self::fmt_pct( $mese_peggiore['incidenza'] ) . ')' . $nl;
        }
        $o .= $nl;

        // Andamento Mensile
        $o .= '## Andamento Mensile' . $nl;
        $o .= sprintf(
            "%-20s %14s %14s %10s %14s %14s %14s %10s\n",
            'Mese', 'Vendite €', 'Costi €', 'Incid.%', 'Margine €', 'ΔVendite €', 'ΔMargine €', 'ΔIncid.%'
        );
        $o .= str_repeat( '-', 116 ) . $nl;
        foreach ( $data as $d ) {
            $o .= sprintf(
                "%-20s %14s %14s %10s %14s %14s %14s %10s\n",
                $d['mese_label'],
                number_format( $d['vendite'],     2, ',', '.' ),
                number_format( $d['costi_glovo'], 2, ',', '.' ),
                number_format( $d['incidenza'],   2, ',', '.' ) . '%',
                number_format( $d['margine'],     2, ',', '.' ),
                $fmt_delta_eur( $d['delta_vendite'] ),
                $fmt_delta_eur( $d['delta_margine'] ),
                $fmt_delta_pct( $d['delta_incidenza'] )
            );
        }
        $o .= $nl;

        // Incidenza Voci di Costo per Mese
        $o .= '## Incidenza Voci di Costo per Mese' . $nl;
        // Intestazione: Mese + una colonna per voce (€ e %)
        $header_parts = array( sprintf( '%-20s', 'Mese' ) );
        foreach ( self::$voci_costo as $voce ) {
            // Abbrevia etichette lunghe per leggibilità
            $lbl = mb_strimwidth( $voce['label'], 0, 18, '..' );
            $header_parts[] = sprintf( '%14s %7s', $lbl, '%' );
        }
        $o .= implode( '  ', $header_parts ) . $nl;
        $o .= str_repeat( '-', min( 200, strlen( implode( '  ', $header_parts ) ) ) ) . $nl;

        foreach ( $data as $d ) {
            $row_parts = array( sprintf( '%-20s', $d['mese_label'] ) );
            foreach ( self::$voci_costo as $voce ) {
                $val = $d[ $voce['key'] ];
                $pct = ( $d['vendite'] > 0 ) ? ( $val / $d['vendite'] * 100 ) : 0;
                // I crediti hanno segno negativo (riducono i costi)
                if ( $voce['is_credit'] ) {
                    $val = -$val;
                    $pct = -$pct;
                }
                $row_parts[] = sprintf(
                    '%14s %7s',
                    number_format( $val, 2, ',', '.' ),
                    number_format( $pct, 2, ',', '.' ) . '%'
                );
            }
            $o .= implode( '  ', $row_parts ) . $nl;
        }
        $o .= $nl;

        // Riepilogo Voci di Costo — totale periodo
        $o .= '## Riepilogo Voci di Costo (totale periodo)' . $nl;
        $o .= sprintf( "%-35s %14s %12s\n", 'Voce', 'Importo €', '% su Vendite' );
        $o .= str_repeat( '-', 65 ) . $nl;
        foreach ( self::$voci_costo as $voce ) {
            $val = $tot_voci[ $voce['key'] ];
            $pct = ( $tot_vendite > 0 ) ? ( $val / $tot_vendite * 100 ) : 0;
            if ( $voce['is_credit'] ) {
                $val = -$val;
                $pct = -$pct;
            }
            $credit_note = $voce['is_credit'] ? '  [credito]' : '';
            $o .= sprintf(
                "%-35s %14s %12s%s\n",
                $voce['label'],
                number_format( $val, 2, ',', '.' ),
                number_format( $pct, 2, ',', '.' ) . '%',
                $credit_note
            );
        }
        $o .= $nl;

        // Incasso Puro
        $o .= '## Incasso Puro' . $nl;
        $o .= 'Buoni Pasto:             ' . self::fmt_currency( $tot_buoni_pasto ) . $nl;
        $o .= 'Contanti (Già Pagati):   ' . self::fmt_currency( $tot_glovo_gia_pagati ) . $nl;
        $o .= 'Bonifici:                ' . self::fmt_currency( $tot_importo_bonifico ) . $nl;
        $o .= 'Totale Incasso Puro:     ' . self::fmt_currency( $tot_incasso_puro ) . $nl;
        $o .= $nl;

        $o .= '---' . $nl;
        $o .= 'Dati estratti da Glovo Invoice Dashboard.' . $nl;

        return $o;
    }

    // -------------------------------------------------------------------------
    // Shortcode render
    // -------------------------------------------------------------------------

    public static function render($atts) {
        $dashboard = new self();

        $default_from = date('Y-m-01', strtotime('-11 months'));
        $default_to   = date('Y-m-t');

        $date_from = isset($_GET['scd_from']) ? sanitize_text_field($_GET['scd_from']) : $default_from;
        $date_to   = isset($_GET['scd_to'])   ? sanitize_text_field($_GET['scd_to'])   : $default_to;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $default_from;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $default_to;

        // Filtro negozio / gruppo
        $scd_negozio_raw    = isset($_GET['scd_negozio']) ? sanitize_text_field(wp_unslash($_GET['scd_negozio'])) : '';
        $negozio_filter     = null;
        $negozio_filter_label = '';
        if ($scd_negozio_raw !== '') {
            if (isset(self::$store_groups[$scd_negozio_raw])) {
                $grp                  = self::$store_groups[$scd_negozio_raw];
                $negozio_filter       = array('type' => $grp['type'], 'value' => $grp['value']);
                $negozio_filter_label = $grp['label'];
            } else {
                $negozio_filter       = array('type' => 'exact', 'value' => $scd_negozio_raw);
                $negozio_filter_label = $scd_negozio_raw;
            }
        }

        $monthly_rows = $dashboard->get_monthly_data($date_from, $date_to, $negozio_filter);
        $store_options = $dashboard->get_store_options();

        // Arricchisci ogni riga con margine, incidenza e delta
        $data = array();
        $prev = null;
        foreach ($monthly_rows as $row) {
            $vendite   = floatval($row['vendite']);
            $costi     = floatval($row['costi_glovo']);
            $margine   = $vendite - $costi;
            $incidenza = ($vendite > 0) ? ($costi / $vendite * 100) : 0;

            $entry = array(
                'mese'            => $row['mese'],
                'mese_label'      => self::mese_label($row['mese']),
                'vendite'         => $vendite,
                'costi_glovo'     => $costi,
                'margine'         => $margine,
                'incidenza'       => $incidenza,
                'delta_vendite'   => null,
                'delta_margine'   => null,
                'delta_incidenza' => null,
            );

            // Singole voci di costo
            foreach (self::$voci_costo as $voce) {
                $entry[$voce['key']] = floatval($row[$voce['key']] ?? 0);
            }

            // Incasso puro (Ciccia)
            $entry['buoni_pasto']      = floatval($row['buoni_pasto'] ?? 0);
            $entry['glovo_gia_pagati'] = floatval($row['glovo_gia_pagati'] ?? 0);
            $entry['importo_bonifico'] = floatval($row['importo_bonifico'] ?? 0);
            $entry['incasso_puro']     = $entry['buoni_pasto'] + $entry['glovo_gia_pagati'] + $entry['importo_bonifico'];

            if ($prev !== null) {
                $entry['delta_vendite']   = $vendite   - $prev['vendite'];
                $entry['delta_margine']   = $margine   - $prev['margine'];
                $entry['delta_incidenza'] = $incidenza - $prev['incidenza'];
            }

            $data[] = $entry;
            $prev = array(
                'vendite'   => $vendite,
                'margine'   => $margine,
                'incidenza' => $incidenza,
            );
        }

        // KPI totali
        $tot_vendite         = array_sum(array_column($data, 'vendite'));
        $tot_costi           = array_sum(array_column($data, 'costi_glovo'));
        $tot_margine         = $tot_vendite - $tot_costi;
        $tot_incidenza_media = ($tot_vendite > 0) ? ($tot_costi / $tot_vendite * 100) : 0;
        $num_mesi            = count($data);

        // Totali per singola voce
        $tot_voci = array();
        foreach (self::$voci_costo as $voce) {
            $tot_voci[$voce['key']] = array_sum(array_column($data, $voce['key']));
        }

        $mese_migliore = null;
        $mese_peggiore = null;
        foreach ($data as $d) {
            if ($d['vendite'] <= 0) continue;
            if ($mese_migliore === null || $d['incidenza'] < $mese_migliore['incidenza']) $mese_migliore = $d;
            if ($mese_peggiore === null || $d['incidenza'] > $mese_peggiore['incidenza']) $mese_peggiore = $d;
        }

        // Dati grafici principali
        $chart_labels    = array_column($data, 'mese_label');
        $chart_vendite   = array_column($data, 'vendite');
        $chart_costi     = array_column($data, 'costi_glovo');
        $chart_margine   = array_column($data, 'margine');
        $chart_incidenza = array_map(function($v) { return round($v, 2); }, array_column($data, 'incidenza'));

        // Dati grafici incasso puro
        $chart_buoni_pasto      = array_column($data, 'buoni_pasto');
        $chart_glovo_gia_pagati = array_column($data, 'glovo_gia_pagati');
        $chart_importo_bonifico = array_column($data, 'importo_bonifico');
        $chart_incasso_puro     = array_column($data, 'incasso_puro');

        // Totali incasso puro
        $tot_buoni_pasto      = array_sum($chart_buoni_pasto);
        $tot_glovo_gia_pagati = array_sum($chart_glovo_gia_pagati);
        $tot_importo_bonifico = array_sum($chart_importo_bonifico);
        $tot_incasso_puro     = $tot_buoni_pasto + $tot_glovo_gia_pagati + $tot_importo_bonifico;

        // Dati grafici per singole voci (solo costi positivi per stacked bar)
        $chart_voci = array();
        foreach (self::$voci_costo as $voce) {
            $chart_voci[$voce['key']] = array_column($data, $voce['key']);
        }

        // Dati grafici: incidenza % di ogni voce sulle vendite (voce / vendite * 100)
        $chart_voci_pct = array();
        foreach (self::$voci_costo as $voce) {
            $chart_voci_pct[$voce['key']] = array_map(function($d) use ($voce) {
                return ($d['vendite'] > 0) ? round($d[$voce['key']] / $d['vendite'] * 100, 2) : 0;
            }, $data);
        }

        $page_url   = strtok($_SERVER['REQUEST_URI'], '?');
        $has_filter = isset($_GET['scd_from']) || isset($_GET['scd_to']) || ($scd_negozio_raw !== '');

        // URL per il download dati AI (preserva tutti i filtri attivi)
        $download_url = wp_nonce_url(
            add_query_arg(
                array(
                    'scd_from'     => $date_from,
                    'scd_to'       => $date_to,
                    'scd_negozio'  => $scd_negozio_raw,
                    'scd_download' => '1',
                ),
                $page_url
            ),
            'gid_scd_export'
        );

        ob_start();
        ?>
<div class="gid-dashboard-wrapper gid-scd-wrapper">

    <!-- ===== FILTRI ===== -->
    <div class="gid-filters gid-scd-filters">
        <h3 class="gid-scd-title">
            <span class="gid-scd-title-icon">📊</span>
            Vendite &amp; Costi Glovo — Riepilogo Mensile
        </h3>
        <form method="GET" class="gid-scd-form">
            <div class="gid-filter-row">
                <div class="gid-filter-group">
                    <label for="scd_from">Vendite dal</label>
                    <input type="date" id="scd_from" name="scd_from"
                           value="<?php echo esc_attr($date_from); ?>">
                </div>
                <div class="gid-filter-group">
                    <label for="scd_to">Vendite al</label>
                    <input type="date" id="scd_to" name="scd_to"
                           value="<?php echo esc_attr($date_to); ?>">
                </div>
                <div class="gid-filter-group">
                    <label for="scd_negozio">Negozio</label>
                    <select id="scd_negozio" name="scd_negozio">
                        <option value="">— Tutti i negozi —</option>
                        <optgroup label="— Gruppi —">
                            <?php foreach (self::$store_groups as $key => $grp): ?>
                            <option value="<?php echo esc_attr($key); ?>"
                                    <?php selected($scd_negozio_raw, $key); ?>>
                                <?php echo esc_html($grp['label']); ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php if (!empty($store_options)): ?>
                        <optgroup label="— Singoli negozi —">
                            <?php foreach ($store_options as $store): ?>
                            <option value="<?php echo esc_attr($store); ?>"
                                    <?php selected($scd_negozio_raw, $store); ?>>
                                <?php echo esc_html($store); ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="gid-filter-group gid-filter-group--btn">
                    <label>&nbsp;</label>
                    <button type="submit" class="gid-btn gid-btn-primary">Applica</button>
                </div>
                <?php if ($has_filter): ?>
                <div class="gid-filter-group gid-filter-group--btn">
                    <label>&nbsp;</label>
                    <a href="<?php echo esc_url($page_url); ?>" class="gid-btn gid-btn-secondary">
                        ↺ Ultimi 12 mesi
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <div class="gid-scd-filter-info">
                Periodo: <strong><?php echo esc_html(self::mese_label(date('Y-m', strtotime($date_from)))); ?></strong>
                &rarr; <strong><?php echo esc_html(self::mese_label(date('Y-m', strtotime($date_to)))); ?></strong>
                <?php if ($negozio_filter_label !== ''): ?>
                &mdash; Negozio: <strong><?php echo esc_html($negozio_filter_label); ?></strong>
                <?php endif; ?>
                <?php if (!$has_filter): ?><em>(ultimi 12 mesi — default)</em><?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (empty($data)): ?>
    <div class="gid-scd-empty">
        <p>⚠️ Nessun dato disponibile per il periodo selezionato.</p>
    </div>
    <?php else: ?>

    <!-- ===== KPI CARDS ===== -->
    <div class="gid-scd-kpi-grid">
        <div class="gid-scd-kpi-card gid-scd-kpi-vendite">
            <div class="gid-scd-kpi-icon">🛒</div>
            <div class="gid-scd-kpi-label">Totale Vendite</div>
            <div class="gid-scd-kpi-value"><?php echo esc_html(self::fmt_currency($tot_vendite)); ?></div>
            <div class="gid-scd-kpi-sub"><?php echo esc_html($num_mesi); ?> mesi &middot; media <?php echo esc_html(self::fmt_currency($num_mesi > 0 ? $tot_vendite / $num_mesi : 0)); ?>/mese</div>
        </div>
        <div class="gid-scd-kpi-card gid-scd-kpi-costi">
            <div class="gid-scd-kpi-icon">💸</div>
            <div class="gid-scd-kpi-label">Totale Costi Glovo</div>
            <div class="gid-scd-kpi-value"><?php echo esc_html(self::fmt_currency($tot_costi)); ?></div>
            <div class="gid-scd-kpi-sub">media <?php echo esc_html(self::fmt_currency($num_mesi > 0 ? $tot_costi / $num_mesi : 0)); ?>/mese</div>
        </div>
        <div class="gid-scd-kpi-card gid-scd-kpi-incidenza">
            <div class="gid-scd-kpi-icon">⚖️</div>
            <div class="gid-scd-kpi-label">Incidenza Media</div>
            <div class="gid-scd-kpi-value <?php echo $tot_incidenza_media > 28 ? 'gid-scd-neg' : ($tot_incidenza_media >= 25 ? 'gid-scd-warn' : ''); ?>">
                <?php echo esc_html(self::fmt_pct($tot_incidenza_media)); ?>
            </div>
            <div class="gid-scd-kpi-sub">Costi Glovo / Vendite</div>
        </div>
        <div class="gid-scd-kpi-card gid-scd-kpi-margine">
            <div class="gid-scd-kpi-icon">📈</div>
            <div class="gid-scd-kpi-label">Margine Totale</div>
            <div class="gid-scd-kpi-value <?php echo $tot_margine >= 0 ? 'gid-scd-pos' : 'gid-scd-neg'; ?>">
                <?php echo esc_html(self::fmt_currency($tot_margine)); ?>
            </div>
            <div class="gid-scd-kpi-sub">Vendite &minus; Costi Glovo</div>
        </div>
        <?php if ($mese_migliore && $mese_migliore !== $mese_peggiore): ?>
        <div class="gid-scd-kpi-card gid-scd-kpi-best">
            <div class="gid-scd-kpi-icon">🏆</div>
            <div class="gid-scd-kpi-label">Mese Migliore</div>
            <div class="gid-scd-kpi-value"><?php echo esc_html($mese_migliore['mese_label']); ?></div>
            <div class="gid-scd-kpi-sub">Incidenza: <?php echo esc_html(self::fmt_pct($mese_migliore['incidenza'])); ?></div>
        </div>
        <div class="gid-scd-kpi-card gid-scd-kpi-worst">
            <div class="gid-scd-kpi-icon">⚠️</div>
            <div class="gid-scd-kpi-label">Mese Peggiore</div>
            <div class="gid-scd-kpi-value"><?php echo esc_html($mese_peggiore['mese_label']); ?></div>
            <div class="gid-scd-kpi-sub">Incidenza: <?php echo esc_html(self::fmt_pct($mese_peggiore['incidenza'])); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== GRAFICI PRINCIPALI ===== -->
    <div class="gid-scd-charts-grid">
        <div class="gid-scd-chart-card gid-scd-chart-wide">
            <h4>Vendite vs Costi Glovo</h4>
            <canvas id="gid-scd-chart-sales-costs" height="100"></canvas>
        </div>
        <div class="gid-scd-chart-card">
            <h4>Incidenza % Costi Glovo</h4>
            <canvas id="gid-scd-chart-incidenza" height="120"></canvas>
        </div>
        <div class="gid-scd-chart-card">
            <h4>Margine Mensile (Vendite − Costi Glovo)</h4>
            <canvas id="gid-scd-chart-margine" height="120"></canvas>
        </div>
    </div>

    <!-- ===== INCASSO PURO (CICCIA) ===== -->
    <div class="gid-scd-section-header">
        <h3 class="gid-scd-section-title">
            <span class="gid-scd-title-icon">💰</span>
            Incasso Puro — Cosa Rimane (Ciccia)
        </h3>
        <p class="gid-scd-section-desc">
            Andamento mensile dell'incasso puro: la somma di <strong>Buoni Pasto</strong>,
            <strong>Contanti (Glovo Già Pagati)</strong> e <strong>Bonifico (Bonifici Totali)</strong>.
        </p>
    </div>

    <div class="gid-scd-kpi-grid" style="grid-template-columns: repeat(4, 1fr);">
        <div class="gid-scd-kpi-card">
            <div class="gid-scd-kpi-label">Buoni Pasto</div>
            <div class="gid-scd-kpi-value"><?php echo esc_html(self::fmt_currency($tot_buoni_pasto)); ?></div>
        </div>
        <div class="gid-scd-kpi-card">
            <div class="gid-scd-kpi-label">Contanti (Glovo Già Pagati)</div>
            <div class="gid-scd-kpi-value"><?php echo esc_html(self::fmt_currency($tot_glovo_gia_pagati)); ?></div>
        </div>
        <div class="gid-scd-kpi-card">
            <div class="gid-scd-kpi-label">Bonifico (Bonifici Totali)</div>
            <div class="gid-scd-kpi-value"><?php echo esc_html(self::fmt_currency($tot_importo_bonifico)); ?></div>
        </div>
        <div class="gid-scd-kpi-card" style="border-top-color: #4CAF50;">
            <div class="gid-scd-kpi-label">Totale Incasso Puro</div>
            <div class="gid-scd-kpi-value gid-scd-kpi-pos"><?php echo esc_html(self::fmt_currency($tot_incasso_puro)); ?></div>
        </div>
    </div>

    <div class="gid-scd-charts-grid">
        <div class="gid-scd-chart-card gid-scd-chart-wide">
            <h4>Incasso Puro</h4>
            <canvas id="gid-scd-chart-incasso-puro" height="110"></canvas>
        </div>
    </div>

    <!-- ===== TABELLA MENSILE ===== -->
    <div class="gid-scd-table-card">
        <h4>Dettaglio Mensile</h4>
        <div class="gid-scd-table-wrap">
            <table class="gid-scd-table">
                <thead>
                    <tr>
                        <th>Mese</th>
                        <th>Vendite</th>
                        <th>Costi Glovo</th>
                        <th>Incidenza %</th>
                        <th>Margine</th>
                        <th title="Confronto delta margine e incidenza rispetto al mese precedente">Δ vs Mese Prec.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($data) as $row): ?>
                    <?php
                        if ($row['incidenza'] > 28)      $inc_cls = 'gid-scd-badge-critico';
                        elseif ($row['incidenza'] >= 25) $inc_cls = 'gid-scd-badge-attenzione';
                        else                             $inc_cls = 'gid-scd-badge-ok';
                    ?>
                    <tr>
                        <td class="gid-scd-td-mese"><strong><?php echo esc_html($row['mese_label']); ?></strong></td>
                        <td class="gid-scd-td-num"><?php echo esc_html(self::fmt_currency($row['vendite'])); ?></td>
                        <td class="gid-scd-td-num"><?php echo esc_html(self::fmt_currency($row['costi_glovo'])); ?></td>
                        <td class="gid-scd-td-pct">
                            <span class="gid-scd-badge <?php echo esc_attr($inc_cls); ?>">
                                <?php echo esc_html(self::fmt_pct($row['incidenza'])); ?>
                            </span>
                        </td>
                        <td class="gid-scd-td-num <?php echo $row['margine'] >= 0 ? 'gid-scd-pos' : 'gid-scd-neg'; ?>">
                            <strong><?php echo esc_html(self::fmt_currency($row['margine'])); ?></strong>
                        </td>
                        <td class="gid-scd-td-delta">
                            <?php if ($row['delta_margine'] !== null): ?>
                            <div class="gid-scd-delta-group">
                                <div class="gid-scd-delta-row">
                                    <span class="gid-scd-delta-label">Margine</span>
                                    <?php echo self::delta_arrow($row['delta_margine']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                </div>
                                <div class="gid-scd-delta-row">
                                    <span class="gid-scd-delta-label">Incid.</span>
                                    <?php echo self::delta_pct_arrow($row['delta_incidenza'], true); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                </div>
                                <div class="gid-scd-delta-row">
                                    <span class="gid-scd-delta-label">Vendite</span>
                                    <?php echo self::delta_arrow($row['delta_vendite']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <span class="gid-scd-delta-na">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="gid-scd-tfoot">
                        <td><strong>TOTALE</strong></td>
                        <td class="gid-scd-td-num"><strong><?php echo esc_html(self::fmt_currency($tot_vendite)); ?></strong></td>
                        <td class="gid-scd-td-num"><strong><?php echo esc_html(self::fmt_currency($tot_costi)); ?></strong></td>
                        <td class="gid-scd-td-pct">
                            <span class="gid-scd-badge <?php echo $tot_incidenza_media > 28 ? 'gid-scd-badge-critico' : ($tot_incidenza_media >= 25 ? 'gid-scd-badge-attenzione' : 'gid-scd-badge-ok'); ?>">
                                <strong><?php echo esc_html(self::fmt_pct($tot_incidenza_media)); ?></strong>
                            </span>
                        </td>
                        <td class="gid-scd-td-num <?php echo $tot_margine >= 0 ? 'gid-scd-pos' : 'gid-scd-neg'; ?>">
                            <strong><?php echo esc_html(self::fmt_currency($tot_margine)); ?></strong>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ===== ANDAMENTO SINGOLE VOCI DI COSTO ===== -->
    <div class="gid-scd-section-header">
        <h3 class="gid-scd-section-title">
            <span class="gid-scd-title-icon">🔍</span>
            Andamento Singole Voci di Costo Glovo
        </h3>
        <p class="gid-scd-section-desc">
            Composizione e andamento mensile delle singole voci che compongono il totale costi Glovo.
            Le voci in <strong>verde</strong> sono detrazioni (riducono il totale).
            Clicca su una voce in legenda per mostrarla/nasconderla.
        </p>
    </div>

    <!-- Grafico stacked: composizione costi per mese -->
    <div class="gid-scd-charts-grid">
        <div class="gid-scd-chart-card gid-scd-chart-wide">
            <h4>Composizione Costi Glovo per Mese</h4>
            <canvas id="gid-scd-chart-voci-stacked" height="110"></canvas>
        </div>
        <div class="gid-scd-chart-card gid-scd-chart-wide">
            <h4>Incidenza % Voci di Costo sulle Vendite</h4>
            <canvas id="gid-scd-chart-voci-incidenza" height="110"></canvas>
        </div>
        <div class="gid-scd-chart-card gid-scd-chart-wide">
            <h4>Andamento Voci di Costo (linee)</h4>
            <canvas id="gid-scd-chart-voci-lines" height="110"></canvas>
        </div>
    </div>

    <!-- KPI voci: totale periodo per voce -->
    <div class="gid-scd-voci-kpi-grid">
        <?php foreach (self::$voci_costo as $voce): ?>
        <?php
            $tot_v    = $tot_voci[$voce['key']];
            $pct_v    = ($tot_costi != 0) ? ($tot_v / array_sum(array_map(function($v) use ($tot_voci) {
                            return $v['is_credit'] ? 0 : $tot_voci[$v['key']];
                        }, self::$voci_costo))) * 100 : 0;
        ?>
        <div class="gid-scd-voce-card <?php echo $voce['is_credit'] ? 'gid-scd-voce-credit' : ''; ?>"
             style="border-top-color: <?php echo esc_attr($voce['color']); ?>">
            <div class="gid-scd-voce-dot" style="background:<?php echo esc_attr($voce['color']); ?>"></div>
            <div class="gid-scd-voce-label">
                <?php echo esc_html($voce['label']); ?>
                <?php if ($voce['is_credit']): ?>
                    <span class="gid-scd-voce-credit-tag">detrazione</span>
                <?php endif; ?>
            </div>
            <div class="gid-scd-voce-value"><?php echo esc_html(self::fmt_currency($tot_v)); ?></div>
            <?php if (!$voce['is_credit'] && $tot_costi > 0): ?>
            <div class="gid-scd-voce-pct">
                <?php echo esc_html(self::fmt_pct($pct_v)); ?> dei costi lordi
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Tabella dettaglio voci per mese -->
    <div class="gid-scd-table-card">
        <h4>Dettaglio Voci di Costo per Mese</h4>
        <div class="gid-scd-table-wrap">
            <table class="gid-scd-table gid-scd-table-voci">
                <thead>
                    <tr>
                        <th class="gid-scd-th-mese">Mese</th>
                        <?php foreach (self::$voci_costo as $voce): ?>
                        <th style="border-bottom: 3px solid <?php echo esc_attr($voce['color']); ?>"
                            title="<?php echo esc_attr($voce['label']); ?><?php echo $voce['is_credit'] ? ' (detrazione)' : ''; ?>">
                            <?php echo esc_html($voce['label']); ?>
                            <?php if ($voce['is_credit']): ?><br><small class="gid-scd-th-credit">↩ detrazione</small><?php endif; ?>
                        </th>
                        <?php endforeach; ?>
                        <th>Tot. Costi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($data) as $row): ?>
                    <tr>
                        <td class="gid-scd-td-mese"><strong><?php echo esc_html($row['mese_label']); ?></strong></td>
                        <?php foreach (self::$voci_costo as $voce): ?>
                        <?php $val = $row[$voce['key']]; ?>
                        <td class="gid-scd-td-num <?php echo ($voce['is_credit'] && $val > 0) ? 'gid-scd-voce-td-credit' : ''; ?>">
                            <?php if ($val > 0): ?>
                                <?php echo $voce['is_credit'] ? '−&nbsp;' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <?php echo esc_html(self::fmt_currency($val)); ?>
                            <?php else: ?>
                                <span class="gid-scd-td-zero">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="gid-scd-td-num"><strong><?php echo esc_html(self::fmt_currency($row['costi_glovo'])); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="gid-scd-tfoot">
                        <td><strong>TOTALE</strong></td>
                        <?php foreach (self::$voci_costo as $voce): ?>
                        <?php $tv = $tot_voci[$voce['key']]; ?>
                        <td class="gid-scd-td-num <?php echo $voce['is_credit'] ? 'gid-scd-voce-td-credit' : ''; ?>">
                            <strong>
                                <?php if ($tv > 0): ?>
                                    <?php echo $voce['is_credit'] ? '−&nbsp;' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                    <?php echo esc_html(self::fmt_currency($tv)); ?>
                                <?php else: ?>—<?php endif; ?>
                            </strong>
                        </td>
                        <?php endforeach; ?>
                        <td class="gid-scd-td-num"><strong><?php echo esc_html(self::fmt_currency($tot_costi)); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ===== CHART.JS ===== -->
    <script>
    (function() {
        var labels    = <?php echo wp_json_encode(array_values($chart_labels)); ?>;
        var vendite   = <?php echo wp_json_encode(array_values($chart_vendite)); ?>;
        var costi     = <?php echo wp_json_encode(array_values($chart_costi)); ?>;
        var margine   = <?php echo wp_json_encode(array_values($chart_margine)); ?>;
        var incidenza = <?php echo wp_json_encode(array_values($chart_incidenza)); ?>;

        // Incasso puro
        var buoniPasto      = <?php echo wp_json_encode(array_values($chart_buoni_pasto)); ?>;
        var glovoPagati     = <?php echo wp_json_encode(array_values($chart_glovo_gia_pagati)); ?>;
        var importoBonifico = <?php echo wp_json_encode(array_values($chart_importo_bonifico)); ?>;
        var incassoPuro     = <?php echo wp_json_encode(array_values($chart_incasso_puro)); ?>;

        // Singole voci
        var vociDefs = <?php
            $defs = array();
            foreach (self::$voci_costo as $voce) {
                $defs[] = array(
                    'key'      => $voce['key'],
                    'label'    => $voce['label'],
                    'color'    => $voce['color'],
                    'isCredit' => $voce['is_credit'],
                    'data'     => array_values($chart_voci[$voce['key']]),
                    'dataPct'  => array_values($chart_voci_pct[$voce['key']]),
                );
            }
            echo wp_json_encode($defs);
        ?>;

        function fmtEur(v) {
            return '€ ' + parseFloat(v).toLocaleString('it-IT', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
        function hexAlpha(hex, a) {
            var r = parseInt(hex.slice(1,3),16),
                g = parseInt(hex.slice(3,5),16),
                b = parseInt(hex.slice(5,7),16);
            return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
        }

        function initScdCharts() {

            // Grafico 1: Vendite vs Costi
            var el1 = document.getElementById('gid-scd-chart-sales-costs');
            if (el1) {
                new Chart(el1, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            { label: 'Vendite',      data: vendite, backgroundColor: 'rgba(0,160,130,0.75)', borderColor: '#00A082', borderWidth: 1, borderRadius: 4 },
                            { label: 'Costi Glovo',  data: costi,   backgroundColor: 'rgba(255,194,68,0.85)', borderColor: '#e6a800', borderWidth: 1, borderRadius: 4 }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': ' + fmtEur(ctx.parsed.y); } } } },
                        scales: { y: { beginAtZero: true, ticks: { callback: function(v) { return '€ ' + v.toLocaleString('it-IT'); } } } }
                    }
                });
            }

            // Grafico 2: Incidenza %
            var el2 = document.getElementById('gid-scd-chart-incidenza');
            if (el2) {
                new Chart(el2, {
                    type: 'line',
                    data: { labels: labels, datasets: [{ label: 'Incidenza % Costi Glovo', data: incidenza, borderColor: '#DC3545', backgroundColor: 'rgba(220,53,69,0.12)', borderWidth: 2, pointRadius: 5, pointHoverRadius: 7, fill: true, tension: 0.3 }] },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + '%'; } } } },
                        scales: { y: { ticks: { callback: function(v) { return v.toFixed(1) + '%'; } } } }
                    }
                });
            }

            // Grafico 3: Margine
            var el3 = document.getElementById('gid-scd-chart-margine');
            if (el3) {
                new Chart(el3, {
                    type: 'bar',
                    data: { labels: labels, datasets: [{ label: 'Margine', data: margine, backgroundColor: margine.map(function(v){return v>=0?'rgba(40,167,69,0.75)':'rgba(220,53,69,0.75)';}), borderColor: margine.map(function(v){return v>=0?'#28A745':'#DC3545';}), borderWidth: 1, borderRadius: 4 }] },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: function(ctx) { return 'Margine: ' + fmtEur(ctx.parsed.y); } } } },
                        scales: { y: { ticks: { callback: function(v) { return '€ ' + v.toLocaleString('it-IT'); } } } }
                    }
                });
            }

            // Grafico Incasso Puro
            var elIP = document.getElementById('gid-scd-chart-incasso-puro');
            if (elIP) {
                new Chart(elIP, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            { label: 'Buoni Pasto', data: buoniPasto, backgroundColor: 'rgba(255,152,0,0.7)', borderColor: '#FF9800', borderWidth: 1, borderRadius: 4, stack: 'incasso' },
                            { label: 'Contanti (Glovo Già Pagati)', data: glovoPagati, backgroundColor: 'rgba(76,175,80,0.7)', borderColor: '#4CAF50', borderWidth: 1, borderRadius: 4, stack: 'incasso' },
                            { label: 'Bonifico (Bonifici Totali)', data: importoBonifico, backgroundColor: 'rgba(33,150,243,0.7)', borderColor: '#2196F3', borderWidth: 1, borderRadius: 4, stack: 'incasso' },
                            { label: 'Totale Incasso Puro', data: incassoPuro, type: 'line', borderColor: '#E91E63', backgroundColor: 'transparent', borderWidth: 2, pointRadius: 4, pointHoverRadius: 6, tension: 0.3, fill: false }
                        ]
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                            tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': ' + fmtEur(ctx.parsed.y); } } }
                        },
                        scales: {
                            x: { stacked: true },
                            y: { stacked: true, ticks: { callback: function(v) { return '€ ' + v.toLocaleString('it-IT'); } } }
                        }
                    }
                });
            }

            // Grafico 4: Stacked bar voci di costo
            var el4 = document.getElementById('gid-scd-chart-voci-stacked');
            if (el4) {
                var stackedDs = vociDefs
                    .filter(function(v){ return !v.isCredit; })
                    .map(function(v) {
                        return {
                            label: v.label,
                            data: v.data,
                            backgroundColor: hexAlpha(v.color, 0.82),
                            borderColor: v.color,
                            borderWidth: 1,
                            borderRadius: 2,
                            stack: 'costi'
                        };
                    });
                // Aggiungi detrazioni come dataset separato (valori negativi)
                vociDefs.filter(function(v){ return v.isCredit; }).forEach(function(v) {
                    stackedDs.push({
                        label: v.label + ' (detraz.)',
                        data: v.data.map(function(x){ return -x; }),
                        backgroundColor: hexAlpha(v.color, 0.7),
                        borderColor: v.color,
                        borderWidth: 1,
                        borderRadius: 2,
                        stack: 'detrazioni'
                    });
                });

                new Chart(el4, {
                    type: 'bar',
                    data: { labels: labels, datasets: stackedDs },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                            tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': ' + fmtEur(Math.abs(ctx.parsed.y)); } } }
                        },
                        scales: {
                            x: { stacked: true },
                            y: { stacked: true, ticks: { callback: function(v) { return '€ ' + Math.abs(v).toLocaleString('it-IT'); } } }
                        }
                    }
                });
            }

            // Grafico 5: Incidenza % voci di costo sulle vendite
            var el5 = document.getElementById('gid-scd-chart-voci-incidenza');
            if (el5) {
                var pctDs = vociDefs.map(function(v) {
                    return {
                        label: v.label + (v.isCredit ? ' ↩' : ''),
                        data: v.dataPct,
                        borderColor: v.color,
                        backgroundColor: 'transparent',
                        borderWidth: v.isCredit ? 2 : 1.5,
                        borderDash: v.isCredit ? [5,3] : [],
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0.3,
                        fill: false
                    };
                });
                new Chart(el5, {
                    type: 'line',
                    data: { labels: labels, datasets: pctDs },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                            tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + '%'; } } }
                        },
                        scales: { y: { ticks: { callback: function(v) { return v.toFixed(1) + '%'; } } } }
                    }
                });
            }

            // Grafico 6: Linee singole voci
            var el6 = document.getElementById('gid-scd-chart-voci-lines');
            if (el6) {
                var lineDs = vociDefs.map(function(v) {
                    return {
                        label: v.label + (v.isCredit ? ' ↩' : ''),
                        data: v.data,
                        borderColor: v.color,
                        backgroundColor: 'transparent',
                        borderWidth: v.isCredit ? 2 : 1.5,
                        borderDash: v.isCredit ? [5,3] : [],
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0.3,
                        fill: false
                    };
                });
                new Chart(el6, {
                    type: 'line',
                    data: { labels: labels, datasets: lineDs },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                            tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': ' + fmtEur(ctx.parsed.y); } } }
                        },
                        scales: { y: { ticks: { callback: function(v) { return '€ ' + v.toLocaleString('it-IT'); } } } }
                    }
                });
            }
        }

        if (typeof Chart !== 'undefined') {
            initScdCharts();
        } else {
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof Chart !== 'undefined') { initScdCharts(); }
                else { window.addEventListener('load', initScdCharts); }
            });
        }
    })();
    </script>

    <!-- ===== EXPORT SECTION ===== -->
    <div class="gid-scd-export-section">
        <div class="gid-scd-export-info">
            <h3 class="gid-scd-section-title">
                <span class="gid-scd-title-icon">⬇</span>
                Esporta &amp; Analizza
            </h3>
            <p class="gid-scd-section-desc">
                Scarica i dati del periodo in formato testo oppure falli analizzare automaticamente da Gemini AI.
            </p>
        </div>
        <div class="gid-scd-export-actions">
            <?php if ( defined('GID_GEMINI_API_KEY') && GID_GEMINI_API_KEY !== '' ) : ?>
            <button id="gid-gemini-analyze-btn"
                    class="gid-btn gid-btn-gemini"
                    data-from="<?php echo esc_attr($date_from); ?>"
                    data-to="<?php echo esc_attr($date_to); ?>"
                    data-negozio="<?php echo esc_attr($scd_negozio_raw); ?>"
                    title="Analizza i dati con Gemini AI e genera un report">
                ✨ Parere di Letizia
            </button>
            <button id="gid-email-comparison-btn"
                    class="gid-btn gid-btn-email-compare"
                    title="Invia via email un confronto degli ultimi 30 giorni rispetto ai 30 giorni precedenti per ogni negozio">
                ✉ Invia confronto rispetto al mese precedente
            </button>
            <?php endif; ?>
            <a href="<?php echo esc_url($download_url); ?>"
               class="gid-btn gid-btn-secondary"
               title="Scarica i dati visualizzati come testo per analisi AI">
                ⬇ Esporta .txt
            </a>
        </div>
    </div>

    <!-- ===== MODALE GEMINI ===== -->
    <div id="gid-gemini-modal" class="gid-modal gid-gemini-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="gid-gemini-modal-title">
        <div class="gid-gemini-modal-content">
            <div class="gid-gemini-modal-header">
                <span id="gid-gemini-modal-title">✨ Report AI — Analisi Gemini</span>
                <button class="gid-gemini-close" aria-label="Chiudi">&times;</button>
            </div>
            <div class="gid-gemini-modal-body">
                <div id="gid-gemini-loading" class="gid-gemini-loading">
                    <div class="gid-gemini-spinner"></div>
                    <p>Analisi in corso con Gemini AI…</p>
                </div>
                <div id="gid-gemini-report-body" class="gid-gemini-report" style="display:none"></div>
                <div id="gid-gemini-error" class="gid-gemini-error" style="display:none"></div>
            </div>
            <div class="gid-gemini-modal-footer">
                <button id="gid-gemini-download-md" class="gid-btn gid-btn-secondary" style="display:none">⬇ Scarica .md</button>
                <button id="gid-gemini-download-docx" class="gid-btn gid-btn-primary" style="display:none">⬇ Scarica .docx</button>
                <button class="gid-gemini-close gid-btn gid-btn-outline">Chiudi</button>
            </div>
        </div>
    </div>

    <?php endif; ?>

</div><!-- .gid-scd-wrapper -->
        <?php
        return ob_get_clean();
    }
}
