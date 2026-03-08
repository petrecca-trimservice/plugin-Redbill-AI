<?php
/**
 * RBAI PDF Extractor — Estrattore fatture Glovo da PDF
 *
 * Wrapping OOP di estrai_fatture_glovo.php.
 * Usa smalot/pdfparser per leggere il testo del PDF e regexes per
 * estrarre i 32 campi della fattura Glovo. Salva nel DB tenant.
 *
 * @package Redbill_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RBAI_PDF_Extractor {

    private RBAI_Tenant $tenant;
    private ?mysqli     $db        = null;
    private array       $log       = [];
    private array       $counters  = [
        'processed_ok'       => 0,
        'validation_failed'  => 0,
        'duplicates'         => 0,
        'db_errors'          => 0,
        'unmapped_amounts'   => 0,
    ];

    /** 32 campi del DB fatture (escluso id, il file_pdf è aggiunto dal loop) */
    private const FIELDS = [
        'file_pdf', 'destinatario', 'negozio', 'n_fattura', 'data',
        'periodo_da', 'periodo_a', 'commissioni', 'marketing_visibilita',
        'subtotale', 'iva_22', 'totale_fattura_iva_inclusa', 'prodotti',
        'servizio_consegna', 'totale_fattura_riepilogo', 'promo_prodotti_partner',
        'promo_consegna_partner', 'costi_offerta_lampo', 'promo_lampo_partner',
        'costo_incidenti_prodotti', 'tariffa_tempo_attesa',
        'rimborsi_partner_senza_comm', 'costo_annullamenti_servizio',
        'consegna_gratuita_incidente', 'buoni_pasto',
        'supplemento_ordine_glovo_prime', 'glovo_gia_pagati',
        'ordini_rimborsati_partner', 'commissione_ordini_rimborsati',
        'sconto_comm_ordini_buoni_pasto', 'debito_accumulato', 'importo_bonifico',
    ];

    private const MANDATORY_FIELDS = [
        'n_fattura', 'data', 'destinatario', 'negozio', 'periodo_da', 'periodo_a',
        'commissioni', 'subtotale', 'iva_22', 'totale_fattura_iva_inclusa',
        'prodotti', 'totale_fattura_riepilogo', 'promo_prodotti_partner',
        'importo_bonifico',
    ];

    public function __construct( RBAI_Tenant $tenant ) {
        $this->tenant = $tenant;
    }

    // -------------------------------------------------------------------------
    // Punto di ingresso pubblico
    // -------------------------------------------------------------------------

    /**
     * Processa tutti i PDF nella directory pdf/ del tenant.
     *
     * @return array{log: string[], counters: array}
     */
    public function run(): array {
        if ( ! class_exists( '\Smalot\PdfParser\Parser' ) ) {
            $autoload = RBAI_PLUGIN_DIR . 'vendor/autoload.php';
            if ( file_exists( $autoload ) ) {
                require_once $autoload;
            } else {
                return [ 'log' => [ 'ERRORE: smalot/pdfparser non trovato. Esegui composer install.' ], 'counters' => $this->counters ];
            }
        }

        $pdf_dir       = $this->tenant->get_upload_dir() . 'pdf/';
        $processed_dir = $pdf_dir . 'processed/';
        $failed_dir    = $pdf_dir . 'failed/';

        wp_mkdir_p( $processed_dir );
        wp_mkdir_p( $failed_dir );

        $files = glob( $pdf_dir . '*.pdf' );
        if ( ! $files ) {
            $this->log[] = 'Nessun PDF trovato in ' . $pdf_dir;
            return [ 'log' => $this->log, 'counters' => $this->counters ];
        }

        $db = $this->get_db();
        if ( ! $db ) {
            $this->log[] = 'ERRORE: impossibile connettersi al DB tenant.';
            return [ 'log' => $this->log, 'counters' => $this->counters ];
        }

        $parser = new \Smalot\PdfParser\Parser();

        foreach ( $files as $file ) {
            $this->process_pdf( $file, $parser, $db, $processed_dir, $failed_dir );
        }

        $this->close_db();

        $this->log[] = str_repeat( '=', 50 );
        $this->log[] = 'RIEPILOGO:';
        $this->log[] = 'OK: ' . $this->counters['processed_ok'];
        $this->log[] = 'Duplicati: ' . $this->counters['duplicates'];
        $this->log[] = 'Validazione fallita: ' . $this->counters['validation_failed'];
        $this->log[] = 'Errori DB: ' . $this->counters['db_errors'];

        return [ 'log' => $this->log, 'counters' => $this->counters ];
    }

    // -------------------------------------------------------------------------
    // Elaborazione singolo PDF
    // -------------------------------------------------------------------------

    private function process_pdf( string $file, $parser, mysqli $db, string $processed_dir, string $failed_dir ): void {
        $filename = basename( $file );
        $this->log[] = 'Elaboro: ' . $filename;

        try {
            $pdf  = $parser->parseFile( $file );
            $pages = $pdf->getPages();
            if ( ! isset( $pages[0] ) ) {
                $this->log[] = '  Nessuna prima pagina, salto.';
                return;
            }
            $text = $pages[0]->getText();
        } catch ( \Exception $e ) {
            $this->log[] = '  ERRORE parser PDF: ' . $e->getMessage();
            $this->counters['validation_failed']++;
            rename( $file, $failed_dir . $filename );
            return;
        }

        $data   = $this->parse_invoice( $text );
        $errors = $this->validate( $data, $filename );

        if ( ! empty( $errors ) ) {
            $this->log[] = '  VALIDAZIONE FALLITA:';
            foreach ( $errors as $e ) {
                $this->log[] = '    - ' . $e;
            }
            $this->counters['validation_failed']++;
            rename( $file, $failed_dir . $filename );
            return;
        }

        // DB insert
        try {
            $placeholders = implode( ',', array_fill( 0, count( self::FIELDS ), '?' ) );
            $stmt = $db->prepare(
                'INSERT INTO gsr_glovo_fatture (' . implode( ',', self::FIELDS ) . ') VALUES (' . $placeholders . ')'
            );
            if ( ! $stmt ) {
                throw new \RuntimeException( $db->error );
            }

            $values = [ $filename ];
            foreach ( array_slice( self::FIELDS, 1 ) as $field ) {
                $values[] = $data[ $field ] ?? null;
            }

            $types = str_repeat( 's', count( $values ) );
            $stmt->bind_param( $types, ...$values );
            $stmt->execute();
            $stmt->close();

            $this->counters['processed_ok']++;
            $this->log[] = '  OK — inserito nel DB.';
            rename( $file, $processed_dir . $filename );

        } catch ( \mysqli_sql_exception $e ) {
            if ( $e->getCode() === 1062 ) {
                $this->counters['duplicates']++;
                $this->log[] = '  Duplicato — già presente nel DB.';
                rename( $file, $processed_dir . $filename );
            } else {
                $this->counters['db_errors']++;
                $this->log[] = '  ERRORE DB: ' . $e->getMessage();
            }
        } catch ( \RuntimeException $e ) {
            $this->counters['db_errors']++;
            $this->log[] = '  ERRORE DB prepare: ' . $e->getMessage();
        }
    }

    // -------------------------------------------------------------------------
    // Parsing testo PDF → array campi
    // -------------------------------------------------------------------------

    private function parse_invoice( string $text ): array {
        $data = array_fill_keys( array_slice( self::FIELDS, 1 ), null );

        // Destinatario + Negozio
        if ( preg_match( '/Foodinho Srl.*?\n(.*?)N\. fattura:/s', $text, $m ) ) {
            $block = trim( $m[1] );
            $lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $block ) ) ) );
            $data['destinatario'] = $this->sanitize_string( $lines[0] ?? null );
            $data['negozio']      = $this->normalize_negozio( $lines[1] ?? null );
        }

        // N. fattura
        if ( preg_match( '/\b([A-Z0-9][A-Z0-9\-\/]{7,})\s+N\. fattura:/', $text, $m ) ) {
            $data['n_fattura'] = trim( $m[1] );
        }

        // Data
        if ( preg_match( '/Data:\s*(\d{4}-\d{2}-\d{2})/', $text, $m ) ) {
            $data['data'] = $m[1];
        }

        // Periodo
        if ( preg_match( '/Servizio fornito da\s*(\d{4}-\d{2}-\d{2})\s*a\s*(\d{4}-\d{2}-\d{2})/', $text, $m ) ) {
            $data['periodo_da'] = $m[1];
            $data['periodo_a']  = $m[2];
        }

        // Importi
        $patterns = [
            'commissioni'                 => '/Commissioni\s*([\d\.,]+)\s*€/u',
            'marketing_visibilita'        => '/Marketing[\s\-]*visibilit[àáa]\s*([\d\.,]+)\s*€/ui',
            'subtotale'                   => '/Subtotale\s*([\d\.,]+)\s*€/u',
            'iva_22'                      => '/IVA\s*\(22\s*%\)\s*([\d\.,]+)\s*€/u',
            'totale_fattura_iva_inclusa'  => '/Totale fattura\s*\(IVA inclusa\)\s*([\d\.,]+)\s*€/us',
            'prodotti'                    => '/\+\s*Prodotti\s*([\d\.,]+)\s*€/u',
            'servizio_consegna'           => '/\+\s*Servizio di consegna\s*([\d\.,]+)\s*€/u',
            'totale_fattura_riepilogo'    => '/-\s*Totale fattura\s*([-]?\d[\d\.,]*)\s*€/u',
            'promo_prodotti_partner'      => '/-\s*Promozione sui prodotti a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
            'promo_consegna_partner'      => '/-?\s*Promozione sulla consegna a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
            'costi_offerta_lampo'         => '/-?\s*Costi per offerta lampo\s*([-]?\d[\d\.,]*)\s*€/u',
            'promo_lampo_partner'         => '/-\s*Promozione lampo a carico del [Pp]artner\s*([-]?\d[\d\.,]*)\s*€/u',
            'costo_incidenti_prodotti'    => '/-\s*Costo degli incidenti relativi ai prodotti\s*([-]?\d[\d\.,]*)\s*€/u',
            'tariffa_tempo_attesa'        => '/-\s*Tariffa\s+per\s+tempo\s+di\s+attesa\s*([-]?\d[\d\.,]*)\s*€/ui',
            'rimborsi_partner_senza_comm' => '/\+\s*Rimborsi al partner senza costo commissione Glovo\s*([\d\.,]+)\s*€/u',
            'costo_annullamenti_servizio' => '/-\s*Costo degli annullamenti e degli incidenti relativi al servizio\s*([-]?\d[\d\.,]*)\s*€/u',
            'consegna_gratuita_incidente' => '/-\s*Consegna gratuita in seguito a incidente\s*([-]?\d[\d\.,]*)\s*€/u',
            'buoni_pasto'                 => '/-\s*Buoni\s+pasto\s*([-]?\d[\d\.,]*)\s*€/ui',
            'supplemento_ordine_glovo_prime' => '/-?\s*Supplemento per ordine con Glovo Prime\s*([-]?\d[\d\.,]*)\s*€/u',
            'glovo_gia_pagati'            => '/-\s*Glovo già pagati\s*([-]?\d[\d\.,]*)\s*€/u',
            'ordini_rimborsati_partner'   => '/\+\s*Ordini rimborsati al partner\s*([\d\.,]+)\s*€/u',
            'commissione_ordini_rimborsati' => '/-?\s*Commissione Glovo sugli ordini rimborsati al partner\s*([-]?\d[\d\.,]*)\s*€/ui',
            'sconto_comm_ordini_buoni_pasto' => '/-?\s*Sconto commissione ordini buoni\s+pasto\s*([-]?\d[\d\.,]*)\s*€/ui',
            'debito_accumulato'           => '/-\s*Debito accumulato\s*([-]?\d[\d\.,]*)\s*€/u',
            'importo_bonifico'            => '/Importo del bonifico\s*(-?[\d\.,]+|-)\s*€/u',
        ];

        foreach ( $patterns as $key => $regex ) {
            if ( preg_match( $regex, $text, $m ) ) {
                $data[ $key ] = $this->normalize_euro( $m[1] );
            }
        }

        return $data;
    }

    private function validate( array $data, string $filename ): array {
        $errors = [];
        foreach ( self::MANDATORY_FIELDS as $field ) {
            if ( empty( $data[ $field ] ) ) {
                $errors[] = "Campo '$field' mancante o vuoto";
            }
        }
        if ( ! empty( $data['n_fattura'] ) && strlen( $data['n_fattura'] ) < 8 ) {
            $errors[] = "n_fattura troppo corto: '{$data['n_fattura']}'";
        }
        if ( ! empty( $data['data'] ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['data'] ) ) {
            $errors[] = "data in formato non valido: '{$data['data']}'";
        }
        return $errors;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function normalize_euro( ?string $val ): ?string {
        if ( $val === null ) return null;
        $val = trim( $val );
        if ( $val === '' || $val === '-' ) return null;
        $val = str_replace( [ '€', ' ', '.' ], '', $val );
        return str_replace( ',', '.', $val );
    }

    private function sanitize_string( ?string $val ): ?string {
        if ( $val === null ) return null;
        $val = trim( $val );
        if ( $val === '' ) return null;
        $val = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $val );
        $val = preg_replace( '/\s+/', ' ', $val );
        $val = preg_replace( '/\bS\.?R\.?L\.?(?!S)/iu', 'S.R.L.', $val );
        $val = preg_replace( '/\bS\.?R\.?L\.?S\.?/iu', 'S.R.L.S.', $val );
        $val = preg_replace( '/\bS\.?P\.?A\.?/iu', 'S.P.A.', $val );
        return trim( $val );
    }

    private function normalize_negozio( ?string $val ): ?string {
        if ( $val === null ) return null;
        $val = trim( $val );
        if ( $val === '' ) return null;

        $map = [
            'Viale Coni Zugna, 43, 20144 Milano MI, Italia'                        => 'Girarrosti Santa Rita - Milano',
            '307, Corso Susa, 301/307, 10098 Rivoli TO, Italy'                     => 'Girarrosti Santa Rita - Rivoli',
            'Via Martiri della Libertà, 74, 10099 San Mauro Torinese TO, Italia'   => 'Girarrosti Santa Rita - San Mauro',
            'Via S. Mauro, 1, 10036 Settimo Torinese TO, Italia'                   => 'Girarrosti Santa Rita - Settimo Torinese',
            'Via Vittorio Alfieri, 9, 10043 Orbassano TO, Italia'                  => 'Girarrosti Santa Rita - Via Vittorio Alfieri - Orbassano',
        ];

        return $map[ $val ] ?? $val;
    }

    // -------------------------------------------------------------------------
    // DB
    // -------------------------------------------------------------------------

    private function get_db(): ?mysqli {
        if ( $this->db && $this->db->ping() ) return $this->db;

        $cfg = $this->tenant->get_db_config();
        $db  = new mysqli( $cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name'] );
        if ( $db->connect_error ) return null;

        $db->set_charset( 'utf8mb4' );
        mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
        $this->db = $db;
        return $db;
    }

    private function close_db(): void {
        if ( $this->db ) {
            $this->db->close();
            $this->db = null;
        }
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function get_log(): array {
        return $this->log;
    }

    public function get_counters(): array {
        return $this->counters;
    }
}
