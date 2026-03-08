<?php
/**
 * Classe per gestire le query al database delle fatture
 * Utilizza mysqli per connettersi a un database separato da WordPress
 */

if (!defined('ABSPATH')) {
    exit;
}

class GID_Invoice_Database {

    private $db_config;
    private $connection;
    private $table_name;

    public function __construct() {
        // Carica la configurazione del database
        $this->db_config = $this->load_config();
        $this->table_name = $this->db_config['db_table'];
        $this->connection = null;
    }

    /**
     * Carica la configurazione del database
     * Cerca prima config-glovo.php esistente, poi usa config-db.php come fallback
     */
    private function load_config() {
        // Percorsi possibili per config-glovo.php
        // Il file si trova in: httpdocs/scripts-glovo/config-glovo.php
        $possible_paths = array(
            // Percorso relativo a ABSPATH (root WordPress)
            ABSPATH . 'httpdocs/scripts-glovo/config-glovo.php',
            ABSPATH . 'scripts-glovo/config-glovo.php',

            // Percorsi relativi alla directory parent di WordPress
            ABSPATH . '../httpdocs/scripts-glovo/config-glovo.php',
            dirname(ABSPATH) . '/httpdocs/scripts-glovo/config-glovo.php',

            // Se WordPress è in una sottocartella di httpdocs
            dirname(dirname(ABSPATH)) . '/scripts-glovo/config-glovo.php',

            // Percorso assoluto dalla root del server
            $_SERVER['DOCUMENT_ROOT'] . '/httpdocs/scripts-glovo/config-glovo.php',
            $_SERVER['DOCUMENT_ROOT'] . '/scripts-glovo/config-glovo.php',

            // Relativo al plugin
            GID_PLUGIN_DIR . '../../../scripts-glovo/config-glovo.php',
            GID_PLUGIN_DIR . '../../../../httpdocs/scripts-glovo/config-glovo.php',
        );

        // Cerca config-glovo.php esistente
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $config = require $path;

                // Se il config-glovo.php ritorna un array con le chiavi corrette, usalo
                if (is_array($config) && isset($config['db_name']) && isset($config['db_table'])) {
                    // Assicurati che tutte le chiavi necessarie esistano
                    return array_merge(
                        array('db_charset' => 'utf8mb4'),
                        $config
                    );
                }
            }
        }

        // Fallback: usa config-db.php del plugin
        if (file_exists(GID_PLUGIN_DIR . 'config-db.php')) {
            return require GID_PLUGIN_DIR . 'config-db.php';
        }

        // Se nessun file di configurazione esiste, genera errore
        wp_die('Glovo Invoice Dashboard: File di configurazione database non trovato. Configura config-db.php nel plugin.');
    }

    /**
     * Crea una connessione mysqli al database
     */
    private function get_connection() {
        if ($this->connection === null) {
            $this->connection = new mysqli(
                $this->db_config['db_host'],
                $this->db_config['db_user'],
                $this->db_config['db_pass'],
                $this->db_config['db_name']
            );

            if ($this->connection->connect_error) {
                error_log('GID Database Connection Error: ' . $this->connection->connect_error);
                return false;
            }

            $this->connection->set_charset($this->db_config['db_charset']);
        }

        return $this->connection;
    }

    /**
     * Chiude la connessione
     */
    public function close_connection() {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    /**
     * Ottiene tutte le fatture con filtri opzionali
     */
    public function get_filtered_invoices($filters = array()) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array();
        }

        $where_clauses = array('1=1');
        $params = array();
        $types = '';

        // Filtro per destinatario
        if (!empty($filters['destinatario'])) {
            $where_clauses[] = "destinatario LIKE ?";
            $params[] = '%' . $filters['destinatario'] . '%';
            $types .= 's';
        }

        // Filtro per negozio
        if (!empty($filters['negozio'])) {
            $where_clauses[] = "negozio LIKE ?";
            $params[] = '%' . $filters['negozio'] . '%';
            $types .= 's';
        }

        // Filtro per intervallo date fattura
        if (!empty($filters['data_from'])) {
            $where_clauses[] = "data >= ?";
            $params[] = $filters['data_from'];
            $types .= 's';
        }
        if (!empty($filters['data_to'])) {
            $where_clauses[] = "data <= ?";
            $params[] = $filters['data_to'];
            $types .= 's';
        }

        // Filtro per intervallo periodo
        if (!empty($filters['periodo_from'])) {
            $where_clauses[] = "periodo_da >= ?";
            $params[] = $filters['periodo_from'];
            $types .= 's';
        }
        if (!empty($filters['periodo_to'])) {
            $where_clauses[] = "periodo_a <= ?";
            $params[] = $filters['periodo_to'];
            $types .= 's';
        }

        // Filtro per numero fattura
        if (!empty($filters['n_fattura'])) {
            $where_clauses[] = "n_fattura LIKE ?";
            $params[] = '%' . $filters['n_fattura'] . '%';
            $types .= 's';
        }

        $where_sql = implode(' AND ', $where_clauses);
        $query = "SELECT * FROM {$this->table_name} WHERE {$where_sql} ORDER BY data DESC";

        $stmt = $conn->prepare($query);

        if (!$stmt) {
            error_log('GID Query Preparation Error: ' . $conn->error);
            return array();
        }

        // Bind dei parametri se presenti
        if (!empty($params)) {
            $bind_params = array_merge(array($types), $params);
            $refs = array();
            foreach ($bind_params as $key => $value) {
                $refs[$key] = &$bind_params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $invoices = array();
        while ($row = $result->fetch_object()) {
            $invoices[] = $row;
        }

        $stmt->close();

        return $invoices;
    }

    /**
     * Ottiene i valori unici per i filtri
     */
    public function get_filter_options() {
        $conn = $this->get_connection();
        if (!$conn) {
            return array('destinatari' => array(), 'negozi' => array());
        }

        $options = array(
            'destinatari' => array(),
            'negozi' => array()
        );

        // Ottieni destinatari unici
        $query = "SELECT DISTINCT destinatario FROM {$this->table_name} WHERE destinatario IS NOT NULL ORDER BY destinatario";
        $result = $conn->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $options['destinatari'][] = $row['destinatario'];
            }
            $result->close();
        }

        // Ottieni negozi unici
        $query = "SELECT DISTINCT negozio FROM {$this->table_name} WHERE negozio IS NOT NULL ORDER BY negozio";
        $result = $conn->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $options['negozi'][] = $row['negozio'];
            }
            $result->close();
        }

        return $options;
    }

    /**
     * Calcola i KPI per la dashboard
     */
    public function get_kpi_data($filters = array()) {
        $invoices = $this->get_filtered_invoices($filters);

        $kpi = array(
            'totale_fatture' => count($invoices),
            'totale_fatturato' => 0,
            'totale_prodotti' => 0,
            'totale_subtotale' => 0,  // Imponibile totale
            'totale_commissioni' => 0,
            'totale_iva' => 0,
            'totale_marketing' => 0,
            'totale_servizio_consegna' => 0,
            'totale_costi_incidenti' => 0,
            'totale_rimborsi' => 0,
            'totale_buoni_pasto' => 0,
            'totale_promo_partner' => 0,
            'totale_tariffa_attesa' => 0,
            'totale_costo_annullamenti' => 0,
            'totale_consegna_gratuita' => 0,
            'totale_supplemento_glovo_prime' => 0,
            'totale_promo_consegna_partner' => 0,
            'totale_costi_offerta_lampo' => 0,
            'totale_promo_lampo_partner' => 0,
            'totale_glovo_gia_pagati' => 0,
            'totale_ordini_rimborsati_partner' => 0,
            'totale_commissione_ordini_rimborsati' => 0,
            'totale_sconto_comm_buoni_pasto' => 0,
            'debito_totale' => 0,
            'importo_bonifico_totale' => 0,
            'media_per_fattura' => 0
        );

        foreach ($invoices as $invoice) {
            $kpi['totale_fatturato'] += abs(floatval($invoice->totale_fattura_iva_inclusa ?? 0));
            $kpi['totale_prodotti'] += abs(floatval($invoice->prodotti ?? 0));
            $kpi['totale_subtotale'] += abs(floatval($invoice->subtotale ?? 0));
            $kpi['totale_commissioni'] += abs(floatval($invoice->commissioni ?? 0));
            $kpi['totale_iva'] += abs(floatval($invoice->iva_22 ?? 0));
            $kpi['totale_marketing'] += abs(floatval($invoice->marketing_visibilita ?? 0));
            $kpi['totale_servizio_consegna'] += abs(floatval($invoice->servizio_consegna ?? 0));
            $kpi['totale_costi_incidenti'] += abs(floatval($invoice->costo_incidenti_prodotti ?? 0));
            $kpi['totale_rimborsi'] += abs(floatval($invoice->rimborsi_partner_senza_comm ?? 0));
            $kpi['totale_buoni_pasto'] += abs(floatval($invoice->buoni_pasto ?? 0));
            $kpi['totale_promo_partner'] += abs(floatval($invoice->promo_prodotti_partner ?? 0));
            $kpi['totale_tariffa_attesa'] += abs(floatval($invoice->tariffa_tempo_attesa ?? 0));
            $kpi['totale_costo_annullamenti'] += abs(floatval($invoice->costo_annullamenti_servizio ?? 0));
            $kpi['totale_consegna_gratuita'] += abs(floatval($invoice->consegna_gratuita_incidente ?? 0));
            $kpi['totale_supplemento_glovo_prime'] += abs(floatval($invoice->supplemento_ordine_glovo_prime ?? 0));
            $kpi['totale_promo_consegna_partner'] += abs(floatval($invoice->promo_consegna_partner ?? 0));
            $kpi['totale_costi_offerta_lampo'] += abs(floatval($invoice->costi_offerta_lampo ?? 0));
            $kpi['totale_promo_lampo_partner'] += abs(floatval($invoice->promo_lampo_partner ?? 0));
            $kpi['totale_glovo_gia_pagati'] += abs(floatval($invoice->glovo_gia_pagati ?? 0));
            $kpi['totale_ordini_rimborsati_partner'] += abs(floatval($invoice->ordini_rimborsati_partner ?? 0));
            $kpi['totale_commissione_ordini_rimborsati'] += abs(floatval($invoice->commissione_ordini_rimborsati ?? 0));
            $kpi['totale_sconto_comm_buoni_pasto'] += abs(floatval($invoice->sconto_comm_ordini_buoni_pasto ?? 0));
            $kpi['debito_totale'] += abs(floatval($invoice->debito_accumulato ?? 0));
            $kpi['importo_bonifico_totale'] += floatval($invoice->importo_bonifico ?? 0);
        }

        if ($kpi['totale_fatture'] > 0) {
            $kpi['media_per_fattura'] = $kpi['totale_prodotti'] / $kpi['totale_fatture'];
        }

        return $kpi;
    }

    /**
     * Ottiene i dati per i grafici
     */
    public function get_chart_data($filters = array()) {
        $invoices = $this->get_filtered_invoices($filters);

        $chart_data = array(
            'prodotti_per_mese' => array(),
            'prodotti_per_negozio' => array(),
            'commissioni_vs_fatturato' => array()
        );

        // Raggruppa per mese
        foreach ($invoices as $invoice) {
            if (!empty($invoice->data)) {
                $month = date('Y-m', strtotime($invoice->data));

                if (!isset($chart_data['prodotti_per_mese'][$month])) {
                    $chart_data['prodotti_per_mese'][$month] = 0;
                }
                $chart_data['prodotti_per_mese'][$month] += abs(floatval($invoice->prodotti ?? 0));
            }

            // Raggruppa per negozio
            if (!empty($invoice->negozio)) {
                if (!isset($chart_data['prodotti_per_negozio'][$invoice->negozio])) {
                    $chart_data['prodotti_per_negozio'][$invoice->negozio] = 0;
                }
                $chart_data['prodotti_per_negozio'][$invoice->negozio] += abs(floatval($invoice->prodotti ?? 0));
            }
        }

        return $chart_data;
    }

    /**
     * Ottiene i dati dell'impatto Glovo per negozio
     */
    public function get_store_impact_data($filters = array()) {
        $invoices = $this->get_filtered_invoices($filters);
        $store_data = array();

        // Raggruppa i dati per negozio
        foreach ($invoices as $invoice) {
            $negozio = !empty($invoice->negozio) ? $invoice->negozio : 'Sconosciuto';

            if (!isset($store_data[$negozio])) {
                $store_data[$negozio] = array(
                    'totale_prodotti' => 0,
                    'totale_commissioni' => 0,
                    'totale_marketing' => 0,
                    'totale_supplemento_prime' => 0,
                    'totale_promo_partner' => 0,
                    'totale_promo_consegna_partner' => 0,
                    'totale_costi_offerta_lampo' => 0,
                    'totale_promo_lampo_partner' => 0,
                    'totale_costi_incidenti' => 0,
                    'totale_tariffa_attesa' => 0,
                    'totale_ordini_rimborsati_partner' => 0,
                    'totale_commissione_ordini_rimborsati' => 0,
                    'totale_sconto_comm_buoni_pasto' => 0
                );
            }

            $store_data[$negozio]['totale_prodotti'] += abs(floatval($invoice->prodotti ?? 0));
            $store_data[$negozio]['totale_commissioni'] += abs(floatval($invoice->commissioni ?? 0));
            $store_data[$negozio]['totale_marketing'] += abs(floatval($invoice->marketing_visibilita ?? 0));
            $store_data[$negozio]['totale_supplemento_prime'] += abs(floatval($invoice->supplemento_ordine_glovo_prime ?? 0));
            $store_data[$negozio]['totale_promo_partner'] += abs(floatval($invoice->promo_prodotti_partner ?? 0));
            $store_data[$negozio]['totale_promo_consegna_partner'] += abs(floatval($invoice->promo_consegna_partner ?? 0));
            $store_data[$negozio]['totale_costi_offerta_lampo'] += abs(floatval($invoice->costi_offerta_lampo ?? 0));
            $store_data[$negozio]['totale_promo_lampo_partner'] += abs(floatval($invoice->promo_lampo_partner ?? 0));
            $store_data[$negozio]['totale_costi_incidenti'] += abs(floatval($invoice->costo_incidenti_prodotti ?? 0));
            $store_data[$negozio]['totale_tariffa_attesa'] += abs(floatval($invoice->tariffa_tempo_attesa ?? 0));
            $store_data[$negozio]['totale_ordini_rimborsati_partner'] += abs(floatval($invoice->ordini_rimborsati_partner ?? 0));
            $store_data[$negozio]['totale_commissione_ordini_rimborsati'] += abs(floatval($invoice->commissione_ordini_rimborsati ?? 0));
            $store_data[$negozio]['totale_sconto_comm_buoni_pasto'] += abs(floatval($invoice->sconto_comm_ordini_buoni_pasto ?? 0));
        }

        // Calcola impatti e percentuali per ogni negozio
        $result = array();
        foreach ($store_data as $negozio => $data) {
            // Impatto Glovo = Commissioni + Marketing + Supplemento Prime + Promo Consegna Partner + Costi Offerta Lampo + Promo Lampo Partner + Costi Incidenti
            $impatto_glovo = $data['totale_commissioni'] + $data['totale_marketing'] + $data['totale_supplemento_prime'] +
                             $data['totale_promo_consegna_partner'] + $data['totale_costi_offerta_lampo'] + $data['totale_promo_lampo_partner'] +
                             $data['totale_costi_incidenti'];

            // Riduzione del 15% sui prodotti (scorporo corretto)
            $riduzione_15 = $data['totale_prodotti'] - (($data['totale_prodotti'] / 115) * 100);

            // Impatto Glovo Teorico = Impatto Glovo - Riduzione 15%
            $impatto_glovo_real = $impatto_glovo - $riduzione_15;

            // Impatto Glovo Teorico Globale (include tutte le voci Glovo)
            $impatto_glovo_promo_real = $impatto_glovo_real + $data['totale_promo_partner'] + $data['totale_tariffa_attesa']
                                       + $data['totale_commissione_ordini_rimborsati']
                                       - $data['totale_ordini_rimborsati_partner']
                                       - $data['totale_sconto_comm_buoni_pasto'];

            // Calcola percentuali
            $percentuale_impatto_real = ($data['totale_prodotti'] > 0) ? ($impatto_glovo_real / $data['totale_prodotti']) * 100 : 0;
            $percentuale_impatto_promo_real = ($data['totale_prodotti'] > 0) ? ($impatto_glovo_promo_real / $data['totale_prodotti']) * 100 : 0;

            $result[] = array(
                'negozio' => $negozio,
                'totale_prodotti' => $data['totale_prodotti'],
                'impatto_glovo' => $impatto_glovo,
                'totale_promo_partner' => $data['totale_promo_partner'],
                'impatto_glovo_real' => $impatto_glovo_real,
                'percentuale_impatto_real' => $percentuale_impatto_real,
                'impatto_glovo_promo_real' => $impatto_glovo_promo_real,
                'percentuale_impatto_promo_real' => $percentuale_impatto_promo_real
            );
        }

        // Ordina per impatto Glovo Teorico decrescente
        usort($result, function($a, $b) {
            return $b['percentuale_impatto_real'] <=> $a['percentuale_impatto_real'];
        });

        return $result;
    }

    /**
     * Testa la connessione al database
     */
    public function test_connection() {
        $conn = $this->get_connection();
        if (!$conn) {
            return array(
                'success' => false,
                'message' => 'Impossibile connettersi al database'
            );
        }

        // Verifica che la tabella esista
        $query = "SHOW TABLES LIKE '{$this->table_name}'";
        $result = $conn->query($query);

        if (!$result || $result->num_rows === 0) {
            return array(
                'success' => false,
                'message' => "La tabella {$this->table_name} non esiste nel database"
            );
        }

        // Conta i record
        $query = "SELECT COUNT(*) as count FROM {$this->table_name}";
        $result = $conn->query($query);
        $count = 0;

        if ($result) {
            $row = $result->fetch_assoc();
            $count = $row['count'];
        }

        return array(
            'success' => true,
            'message' => "Connessione riuscita. Trovati {$count} record nella tabella {$this->table_name}",
            'count' => $count,
            'database' => $this->db_config['db_name'],
            'table' => $this->table_name,
            'host' => $this->db_config['db_host']
        );
    }

    /**
     * Ottiene tutte le fatture con calcolo dell'impatto Glovo
     * Se $threshold è impostato, filtra solo quelle sopra la soglia
     */
    public function get_high_impact_invoices($filters = array(), $threshold = 0) {
        $invoices = $this->get_filtered_invoices($filters);
        $impact_invoices = array();

        foreach ($invoices as $invoice) {
            $totale_prodotti = abs(floatval($invoice->prodotti ?? 0));

            if ($totale_prodotti > 0) {
                $commissioni = abs(floatval($invoice->commissioni ?? 0));
                $marketing = abs(floatval($invoice->marketing_visibilita ?? 0));
                $promo_partner = abs(floatval($invoice->promo_prodotti_partner ?? 0));
                $supplemento_prime = abs(floatval($invoice->supplemento_ordine_glovo_prime ?? 0));
                $promo_consegna_partner = abs(floatval($invoice->promo_consegna_partner ?? 0));
                $costi_offerta_lampo = abs(floatval($invoice->costi_offerta_lampo ?? 0));
                $promo_lampo_partner = abs(floatval($invoice->promo_lampo_partner ?? 0));
                $costi_incidenti = abs(floatval($invoice->costo_incidenti_prodotti ?? 0));
                $tariffa_attesa = abs(floatval($invoice->tariffa_tempo_attesa ?? 0));
                $ordini_rimborsati_partner = abs(floatval($invoice->ordini_rimborsati_partner ?? 0));
                $commissione_ordini_rimborsati = abs(floatval($invoice->commissione_ordini_rimborsati ?? 0));
                $sconto_comm_buoni_pasto = abs(floatval($invoice->sconto_comm_ordini_buoni_pasto ?? 0));

                $impatto_glovo = $commissioni + $marketing + $supplemento_prime +
                                 $promo_consegna_partner + $costi_offerta_lampo + $promo_lampo_partner +
                                 $costi_incidenti;
                $impatto_glovo_promo = $impatto_glovo + $promo_partner + $tariffa_attesa
                                      + $commissione_ordini_rimborsati
                                      - $ordini_rimborsati_partner
                                      - $sconto_comm_buoni_pasto;

                $percentuale_impatto = ($impatto_glovo / $totale_prodotti) * 100;
                $percentuale_impatto_promo = ($impatto_glovo_promo / $totale_prodotti) * 100;

                // Determina il livello di allerta
                $livello_allerta = 'normale';
                if ($percentuale_impatto > 28) {
                    $livello_allerta = 'critico';
                } elseif ($percentuale_impatto >= 25) {
                    $livello_allerta = 'attenzione';
                }

                if ($percentuale_impatto >= $threshold) {
                    $invoice_data = array(
                        'id' => $invoice->id ?? null,
                        'numero_fattura' => $invoice->n_fattura ?? '',
                        'data' => $invoice->data ?? '',
                        'negozio' => $invoice->negozio ?? '',
                        'destinatario' => $invoice->destinatario ?? '',
                        'totale_prodotti' => $totale_prodotti,
                        'impatto_glovo' => $impatto_glovo,
                        'impatto_glovo_promo' => $impatto_glovo_promo,
                        'percentuale_impatto' => $percentuale_impatto,
                        'percentuale_impatto_promo' => $percentuale_impatto_promo,
                        'commissioni' => $commissioni,
                        'marketing' => $marketing,
                        'supplemento_prime' => $supplemento_prime,
                        'promo_consegna_partner' => $promo_consegna_partner,
                        'costi_offerta_lampo' => $costi_offerta_lampo,
                        'promo_lampo_partner' => $promo_lampo_partner,
                        'promo_partner' => $promo_partner,
                        'livello_allerta' => $livello_allerta
                    );

                    $impact_invoices[] = $invoice_data;
                }
            }
        }

        // Ordina per percentuale impatto decrescente
        usort($impact_invoices, function($a, $b) {
            return $b['percentuale_impatto'] <=> $a['percentuale_impatto'];
        });

        return $impact_invoices;
    }

    /**
     * Conta le fatture per livello di allerta
     */
    public function count_alert_invoices($filters = array()) {
        $all_invoices = $this->get_high_impact_invoices($filters, 0);

        $counts = array(
            'critico' => 0,
            'attenzione' => 0,
            'normale' => 0
        );

        foreach ($all_invoices as $invoice) {
            if ($invoice['livello_allerta'] == 'critico') {
                $counts['critico']++;
            } elseif ($invoice['livello_allerta'] == 'attenzione') {
                $counts['attenzione']++;
            } else {
                $counts['normale']++;
            }
        }

        return $counts;
    }

    /**
     * Ottiene informazioni sul file di configurazione caricato (per debug)
     */
    public function get_config_info() {
        $info = array(
            'config_source' => 'Non trovato',
            'database' => $this->db_config['db_name'] ?? 'N/A',
            'table' => $this->table_name ?? 'N/A',
            'host' => $this->db_config['db_host'] ?? 'N/A'
        );

        // Verifica quale config è stato caricato
        $possible_paths = array(
            ABSPATH . 'httpdocs/scripts-glovo/config-glovo.php' => 'config-glovo.php (httpdocs/scripts-glovo)',
            ABSPATH . 'scripts-glovo/config-glovo.php' => 'config-glovo.php (scripts-glovo)',
            ABSPATH . '../httpdocs/scripts-glovo/config-glovo.php' => 'config-glovo.php (parent/httpdocs)',
            dirname(ABSPATH) . '/httpdocs/scripts-glovo/config-glovo.php' => 'config-glovo.php (dirname)',
            GID_PLUGIN_DIR . 'config-db.php' => 'config-db.php (plugin fallback)'
        );

        foreach ($possible_paths as $path => $label) {
            if (file_exists($path)) {
                $info['config_source'] = $label;
                $info['config_path'] = $path;
                break;
            }
        }

        return $info;
    }

    /**
     * Distruttore - chiude la connessione
     */
    public function __destruct() {
        $this->close_connection();
    }
}
