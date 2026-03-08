<?php
/**
 * Gestisce le query al database delle fatture del tenant.
 * Riceve la configurazione DB da RBAI_Tenant::get_db_config() – nessun file
 * di config hardcodato, isolamento totale per tenant.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Invoice_Database {

    private array   $db_config;
    private ?mysqli $connection = null;
    private string  $table_name;

    public function __construct(array $db_config) {
        $this->db_config  = $db_config;
        $this->table_name = $db_config['db_table'] ?? 'gsr_glovo_fatture';
    }

    // ─── Connessione ─────────────────────────────────────────────────────────

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

        if ($conn->connect_error) {
            error_log('RBAI DB Connection Error: ' . $conn->connect_error);
            return false;
        }

        $conn->set_charset($this->db_config['db_charset'] ?? 'utf8mb4');
        $this->connection = $conn;
        return $this->connection;
    }

    public function close_connection(): void {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    public function __destruct() {
        $this->close_connection();
    }

    // ─── Query fatture ───────────────────────────────────────────────────────

    public function get_filtered_invoices(array $filters = []): array {
        $conn = $this->get_connection();
        if (!$conn) {
            return [];
        }

        $where_clauses = ['1=1'];
        $params        = [];
        $types         = '';

        if (!empty($filters['destinatario'])) {
            $where_clauses[] = 'destinatario LIKE ?';
            $params[]        = '%' . $filters['destinatario'] . '%';
            $types          .= 's';
        }
        if (!empty($filters['negozio'])) {
            $where_clauses[] = 'negozio LIKE ?';
            $params[]        = '%' . $filters['negozio'] . '%';
            $types          .= 's';
        }
        if (!empty($filters['data_from'])) {
            $where_clauses[] = 'data >= ?';
            $params[]        = $filters['data_from'];
            $types          .= 's';
        }
        if (!empty($filters['data_to'])) {
            $where_clauses[] = 'data <= ?';
            $params[]        = $filters['data_to'];
            $types          .= 's';
        }
        if (!empty($filters['periodo_from'])) {
            $where_clauses[] = 'periodo_da >= ?';
            $params[]        = $filters['periodo_from'];
            $types          .= 's';
        }
        if (!empty($filters['periodo_to'])) {
            $where_clauses[] = 'periodo_a <= ?';
            $params[]        = $filters['periodo_to'];
            $types          .= 's';
        }
        if (!empty($filters['n_fattura'])) {
            $where_clauses[] = 'n_fattura LIKE ?';
            $params[]        = '%' . $filters['n_fattura'] . '%';
            $types          .= 's';
        }

        $where_sql = implode(' AND ', $where_clauses);
        $query     = "SELECT * FROM `{$this->table_name}` WHERE {$where_sql} ORDER BY data DESC";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log('RBAI Query Prepare Error: ' . $conn->error);
            return [];
        }

        if (!empty($params)) {
            $bind_params = array_merge([$types], $params);
            $refs        = [];
            foreach ($bind_params as $k => $v) {
                $refs[$k] = &$bind_params[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }

        $stmt->execute();
        $result   = $stmt->get_result();
        $invoices = [];
        while ($row = $result->fetch_object()) {
            $invoices[] = $row;
        }
        $stmt->close();

        return $invoices;
    }

    public function get_filter_options(): array {
        $conn = $this->get_connection();
        if (!$conn) {
            return ['destinatari' => [], 'negozi' => []];
        }

        $options = ['destinatari' => [], 'negozi' => []];

        $r = $conn->query("SELECT DISTINCT destinatario FROM `{$this->table_name}` WHERE destinatario IS NOT NULL ORDER BY destinatario");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $options['destinatari'][] = $row['destinatario'];
            }
            $r->free();
        }

        $r = $conn->query("SELECT DISTINCT negozio FROM `{$this->table_name}` WHERE negozio IS NOT NULL ORDER BY negozio");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $options['negozi'][] = $row['negozio'];
            }
            $r->free();
        }

        return $options;
    }

    public function get_kpi_data(array $filters = []): array {
        $invoices = $this->get_filtered_invoices($filters);

        $kpi = [
            'totale_fatture'                  => count($invoices),
            'totale_fatturato'                => 0,
            'totale_prodotti'                 => 0,
            'totale_subtotale'                => 0,
            'totale_commissioni'              => 0,
            'totale_iva'                      => 0,
            'totale_marketing'                => 0,
            'totale_servizio_consegna'        => 0,
            'totale_costi_incidenti'          => 0,
            'totale_rimborsi'                 => 0,
            'totale_buoni_pasto'              => 0,
            'totale_promo_partner'            => 0,
            'totale_tariffa_attesa'           => 0,
            'totale_costo_annullamenti'       => 0,
            'totale_consegna_gratuita'        => 0,
            'totale_supplemento_glovo_prime'  => 0,
            'totale_promo_consegna_partner'   => 0,
            'totale_costi_offerta_lampo'      => 0,
            'totale_promo_lampo_partner'      => 0,
            'totale_glovo_gia_pagati'         => 0,
            'totale_ordini_rimborsati_partner' => 0,
            'totale_commissione_ordini_rimborsati' => 0,
            'totale_sconto_comm_buoni_pasto'  => 0,
            'debito_totale'                   => 0,
            'importo_bonifico_totale'         => 0,
            'media_per_fattura'               => 0,
        ];

        foreach ($invoices as $inv) {
            $kpi['totale_fatturato']               += abs((float) ($inv->totale_fattura_iva_inclusa ?? 0));
            $kpi['totale_prodotti']                += abs((float) ($inv->prodotti ?? 0));
            $kpi['totale_subtotale']               += abs((float) ($inv->subtotale ?? 0));
            $kpi['totale_commissioni']             += abs((float) ($inv->commissioni ?? 0));
            $kpi['totale_iva']                     += abs((float) ($inv->iva_22 ?? 0));
            $kpi['totale_marketing']               += abs((float) ($inv->marketing_visibilita ?? 0));
            $kpi['totale_servizio_consegna']       += abs((float) ($inv->servizio_consegna ?? 0));
            $kpi['totale_costi_incidenti']         += abs((float) ($inv->costo_incidenti_prodotti ?? 0));
            $kpi['totale_rimborsi']                += abs((float) ($inv->rimborsi_partner_senza_comm ?? 0));
            $kpi['totale_buoni_pasto']             += abs((float) ($inv->buoni_pasto ?? 0));
            $kpi['totale_promo_partner']           += abs((float) ($inv->promo_prodotti_partner ?? 0));
            $kpi['totale_tariffa_attesa']          += abs((float) ($inv->tariffa_tempo_attesa ?? 0));
            $kpi['totale_costo_annullamenti']      += abs((float) ($inv->costo_annullamenti_servizio ?? 0));
            $kpi['totale_consegna_gratuita']       += abs((float) ($inv->consegna_gratuita_incidente ?? 0));
            $kpi['totale_supplemento_glovo_prime'] += abs((float) ($inv->supplemento_ordine_glovo_prime ?? 0));
            $kpi['totale_promo_consegna_partner']  += abs((float) ($inv->promo_consegna_partner ?? 0));
            $kpi['totale_costi_offerta_lampo']     += abs((float) ($inv->costi_offerta_lampo ?? 0));
            $kpi['totale_promo_lampo_partner']     += abs((float) ($inv->promo_lampo_partner ?? 0));
            $kpi['totale_glovo_gia_pagati']        += abs((float) ($inv->glovo_gia_pagati ?? 0));
            $kpi['totale_ordini_rimborsati_partner'] += abs((float) ($inv->ordini_rimborsati_partner ?? 0));
            $kpi['totale_commissione_ordini_rimborsati'] += abs((float) ($inv->commissione_ordini_rimborsati ?? 0));
            $kpi['totale_sconto_comm_buoni_pasto'] += abs((float) ($inv->sconto_comm_ordini_buoni_pasto ?? 0));
            $kpi['debito_totale']                  += abs((float) ($inv->debito_accumulato ?? 0));
            $kpi['importo_bonifico_totale']        += (float) ($inv->importo_bonifico ?? 0);
        }

        if ($kpi['totale_fatture'] > 0) {
            $kpi['media_per_fattura'] = $kpi['totale_prodotti'] / $kpi['totale_fatture'];
        }

        return $kpi;
    }

    public function get_chart_data(array $filters = []): array {
        $invoices   = $this->get_filtered_invoices($filters);
        $chart_data = ['prodotti_per_mese' => [], 'prodotti_per_negozio' => []];

        foreach ($invoices as $inv) {
            if (!empty($inv->data)) {
                $month = date('Y-m', strtotime($inv->data));
                $chart_data['prodotti_per_mese'][$month] = ($chart_data['prodotti_per_mese'][$month] ?? 0) + abs((float) ($inv->prodotti ?? 0));
            }
            if (!empty($inv->negozio)) {
                $chart_data['prodotti_per_negozio'][$inv->negozio] = ($chart_data['prodotti_per_negozio'][$inv->negozio] ?? 0) + abs((float) ($inv->prodotti ?? 0));
            }
        }

        return $chart_data;
    }

    public function get_store_impact_data(array $filters = []): array {
        $invoices   = $this->get_filtered_invoices($filters);
        $store_data = [];

        foreach ($invoices as $inv) {
            $negozio = !empty($inv->negozio) ? $inv->negozio : 'Sconosciuto';
            if (!isset($store_data[$negozio])) {
                $store_data[$negozio] = array_fill_keys([
                    'totale_prodotti','totale_commissioni','totale_marketing',
                    'totale_supplemento_prime','totale_promo_partner',
                    'totale_promo_consegna_partner','totale_costi_offerta_lampo',
                    'totale_promo_lampo_partner','totale_costi_incidenti',
                    'totale_tariffa_attesa','totale_ordini_rimborsati_partner',
                    'totale_commissione_ordini_rimborsati','totale_sconto_comm_buoni_pasto',
                ], 0);
            }
            $d = &$store_data[$negozio];
            $d['totale_prodotti']                    += abs((float)($inv->prodotti ?? 0));
            $d['totale_commissioni']                 += abs((float)($inv->commissioni ?? 0));
            $d['totale_marketing']                   += abs((float)($inv->marketing_visibilita ?? 0));
            $d['totale_supplemento_prime']           += abs((float)($inv->supplemento_ordine_glovo_prime ?? 0));
            $d['totale_promo_partner']               += abs((float)($inv->promo_prodotti_partner ?? 0));
            $d['totale_promo_consegna_partner']      += abs((float)($inv->promo_consegna_partner ?? 0));
            $d['totale_costi_offerta_lampo']         += abs((float)($inv->costi_offerta_lampo ?? 0));
            $d['totale_promo_lampo_partner']         += abs((float)($inv->promo_lampo_partner ?? 0));
            $d['totale_costi_incidenti']             += abs((float)($inv->costo_incidenti_prodotti ?? 0));
            $d['totale_tariffa_attesa']              += abs((float)($inv->tariffa_tempo_attesa ?? 0));
            $d['totale_ordini_rimborsati_partner']   += abs((float)($inv->ordini_rimborsati_partner ?? 0));
            $d['totale_commissione_ordini_rimborsati'] += abs((float)($inv->commissione_ordini_rimborsati ?? 0));
            $d['totale_sconto_comm_buoni_pasto']     += abs((float)($inv->sconto_comm_ordini_buoni_pasto ?? 0));
        }

        $result = [];
        foreach ($store_data as $negozio => $d) {
            $impatto_glovo = $d['totale_commissioni'] + $d['totale_marketing'] +
                             $d['totale_supplemento_prime'] + $d['totale_promo_consegna_partner'] +
                             $d['totale_costi_offerta_lampo'] + $d['totale_promo_lampo_partner'] +
                             $d['totale_costi_incidenti'];

            $riduzione_15        = $d['totale_prodotti'] - (($d['totale_prodotti'] / 115) * 100);
            $impatto_glovo_real  = $impatto_glovo - $riduzione_15;
            $impatto_glovo_promo = $impatto_glovo_real + $d['totale_promo_partner'] +
                                   $d['totale_tariffa_attesa'] + $d['totale_commissione_ordini_rimborsati'] -
                                   $d['totale_ordini_rimborsati_partner'] - $d['totale_sconto_comm_buoni_pasto'];

            $perc_real  = $d['totale_prodotti'] > 0 ? ($impatto_glovo_real  / $d['totale_prodotti']) * 100 : 0;
            $perc_promo = $d['totale_prodotti'] > 0 ? ($impatto_glovo_promo / $d['totale_prodotti']) * 100 : 0;

            $result[] = [
                'negozio'                    => $negozio,
                'totale_prodotti'            => $d['totale_prodotti'],
                'impatto_glovo'              => $impatto_glovo,
                'totale_promo_partner'       => $d['totale_promo_partner'],
                'impatto_glovo_real'         => $impatto_glovo_real,
                'percentuale_impatto_real'   => $perc_real,
                'impatto_glovo_promo_real'   => $impatto_glovo_promo,
                'percentuale_impatto_promo_real' => $perc_promo,
            ];
        }

        usort($result, fn($a, $b) => $b['percentuale_impatto_real'] <=> $a['percentuale_impatto_real']);
        return $result;
    }

    public function test_connection(): array {
        $conn = $this->get_connection();
        if (!$conn) {
            return ['success' => false, 'message' => 'Impossibile connettersi al database'];
        }

        $r = $conn->query("SHOW TABLES LIKE '{$this->table_name}'");
        if (!$r || $r->num_rows === 0) {
            return ['success' => false, 'message' => "Tabella {$this->table_name} non trovata"];
        }

        $r2    = $conn->query("SELECT COUNT(*) as n FROM `{$this->table_name}`");
        $count = $r2 ? (int) $r2->fetch_assoc()['n'] : 0;

        return [
            'success'  => true,
            'message'  => "Connessione OK — {$count} fatture",
            'count'    => $count,
            'database' => $this->db_config['db_name'],
            'table'    => $this->table_name,
        ];
    }

    public function get_high_impact_invoices(array $filters = [], float $threshold = 0): array {
        $invoices       = $this->get_filtered_invoices($filters);
        $impact_invoices = [];

        foreach ($invoices as $inv) {
            $totale_prodotti = abs((float) ($inv->prodotti ?? 0));
            if ($totale_prodotti <= 0) {
                continue;
            }

            $commissioni              = abs((float) ($inv->commissioni ?? 0));
            $marketing                = abs((float) ($inv->marketing_visibilita ?? 0));
            $supplemento_prime        = abs((float) ($inv->supplemento_ordine_glovo_prime ?? 0));
            $promo_consegna_partner   = abs((float) ($inv->promo_consegna_partner ?? 0));
            $costi_offerta_lampo      = abs((float) ($inv->costi_offerta_lampo ?? 0));
            $promo_lampo_partner      = abs((float) ($inv->promo_lampo_partner ?? 0));
            $costi_incidenti          = abs((float) ($inv->costo_incidenti_prodotti ?? 0));
            $promo_partner            = abs((float) ($inv->promo_prodotti_partner ?? 0));
            $tariffa_attesa           = abs((float) ($inv->tariffa_tempo_attesa ?? 0));
            $ordini_rimborsati        = abs((float) ($inv->ordini_rimborsati_partner ?? 0));
            $comm_ordini_rimborsati   = abs((float) ($inv->commissione_ordini_rimborsati ?? 0));
            $sconto_comm_buoni_pasto  = abs((float) ($inv->sconto_comm_ordini_buoni_pasto ?? 0));

            $impatto_glovo       = $commissioni + $marketing + $supplemento_prime + $promo_consegna_partner + $costi_offerta_lampo + $promo_lampo_partner + $costi_incidenti;
            $impatto_glovo_promo = $impatto_glovo + $promo_partner + $tariffa_attesa + $comm_ordini_rimborsati - $ordini_rimborsati - $sconto_comm_buoni_pasto;

            $perc_impatto       = ($impatto_glovo       / $totale_prodotti) * 100;
            $perc_impatto_promo = ($impatto_glovo_promo / $totale_prodotti) * 100;

            $livello = $perc_impatto > 28 ? 'critico' : ($perc_impatto >= 25 ? 'attenzione' : 'normale');

            if ($perc_impatto >= $threshold) {
                $impact_invoices[] = [
                    'id'                       => $inv->id ?? null,
                    'numero_fattura'           => $inv->n_fattura ?? '',
                    'data'                     => $inv->data ?? '',
                    'negozio'                  => $inv->negozio ?? '',
                    'destinatario'             => $inv->destinatario ?? '',
                    'totale_prodotti'          => $totale_prodotti,
                    'impatto_glovo'            => $impatto_glovo,
                    'impatto_glovo_promo'      => $impatto_glovo_promo,
                    'percentuale_impatto'      => $perc_impatto,
                    'percentuale_impatto_promo'=> $perc_impatto_promo,
                    'commissioni'              => $commissioni,
                    'marketing'                => $marketing,
                    'livello_allerta'          => $livello,
                ];
            }
        }

        usort($impact_invoices, fn($a, $b) => $b['percentuale_impatto'] <=> $a['percentuale_impatto']);
        return $impact_invoices;
    }

    public function count_alert_invoices(array $filters = []): array {
        $counts = ['critico' => 0, 'attenzione' => 0, 'normale' => 0];
        foreach ($this->get_high_impact_invoices($filters, 0) as $inv) {
            $counts[$inv['livello_allerta']]++;
        }
        return $counts;
    }
}
