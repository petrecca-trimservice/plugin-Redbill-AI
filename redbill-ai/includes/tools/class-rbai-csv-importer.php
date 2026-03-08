<?php
/**
 * RBAI CSV Importer — Importa ordini Glovo da CSV
 *
 * Wrapping OOP di import-glovo-dettagli.php.
 * Importa CSV di dettagli ordini Glovo nella directory csv/ del tenant
 * nelle tabelle gsr_glovo_dettagli e gsr_glovo_dettagli_items.
 *
 * @package Redbill_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RBAI_CSV_Importer {

    private RBAI_Tenant $tenant;
    private array       $log      = [];
    private array       $counters = [
        'processed_ok'   => 0,
        'duplicates'     => 0,
        'errors'         => 0,
        'rows_inserted'  => 0,
        'items_inserted' => 0,
    ];

    // Colonne CSV Glovo (header row)
    private const CSV_COLUMNS = [
        'notification_partner_time',
        'description',
        'store_name',
        'store_address',
        'payment_method',
        'price_of_products',
        'product_promotion_paid_by_partner',
        'flash_offer_promotion_paid_by_partner',
        'charged_to_partner_base',
        'glovo_platform_fee',
        'total_charged_to_partner',
        'total_charged_to_partner_percentage',
        'delivery_promotion_paid_by_partner',
        'refunds_incidents',
        'products_paid_in_cash',
        'delivery_price_paid_in_cash',
        'meal_vouchers_discounts',
        'incidents_to_pay_partner',
        'product_with_incidents',
        'incidents_glovo_platform_fee',
        'wait_time_fee',
        'wait_time_fee_refund',
        'prime_order_vendor_fee',
        'flash_deals_fee',
    ];

    public function __construct( RBAI_Tenant $tenant ) {
        $this->tenant = $tenant;
    }

    // -------------------------------------------------------------------------
    // Punto di ingresso pubblico
    // -------------------------------------------------------------------------

    public function run(): array {
        $csv_dir       = $this->tenant->get_upload_dir() . 'csv/';
        $processed_dir = $csv_dir . 'processed/';

        wp_mkdir_p( $processed_dir );

        $files = glob( $csv_dir . '*.csv' );
        if ( ! $files ) {
            $this->log[] = 'Nessun CSV trovato in ' . $csv_dir;
            return [ 'log' => $this->log, 'counters' => $this->counters ];
        }

        try {
            $pdo = $this->get_pdo();
        } catch ( \PDOException $e ) {
            $this->log[] = 'ERRORE connessione DB: ' . $e->getMessage();
            return [ 'log' => $this->log, 'counters' => $this->counters ];
        }

        $this->create_tables( $pdo );

        foreach ( $files as $file ) {
            $this->process_csv( $file, $pdo, $processed_dir );
        }

        $this->log[] = str_repeat( '=', 50 );
        $this->log[] = 'RIEPILOGO:';
        $this->log[] = 'CSV processati: ' . $this->counters['processed_ok'];
        $this->log[] = 'Righe inserite: ' . $this->counters['rows_inserted'];
        $this->log[] = 'Items inseriti: ' . $this->counters['items_inserted'];
        $this->log[] = 'Duplicati: ' . $this->counters['duplicates'];
        $this->log[] = 'Errori: ' . $this->counters['errors'];

        return [ 'log' => $this->log, 'counters' => $this->counters ];
    }

    // -------------------------------------------------------------------------
    // Creazione tabelle
    // -------------------------------------------------------------------------

    private function create_tables( \PDO $pdo ): void {
        $pdo->exec( "
            CREATE TABLE IF NOT EXISTS gsr_glovo_dettagli (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                csv_filename VARCHAR(255),
                notification_partner_time DATETIME NOT NULL,
                description TEXT,
                allergie_note TEXT,
                store_name VARCHAR(255),
                store_address VARCHAR(255),
                payment_method VARCHAR(50),
                price_of_products DECIMAL(10,2),
                product_promotion_paid_by_partner DECIMAL(10,2),
                flash_offer_promotion_paid_by_partner DECIMAL(10,2),
                charged_to_partner_base DECIMAL(10,2),
                glovo_platform_fee DECIMAL(10,2),
                total_charged_to_partner DECIMAL(10,2),
                total_charged_to_partner_percentage DECIMAL(5,2),
                delivery_promotion_paid_by_partner DECIMAL(10,2),
                refunds_incidents DECIMAL(10,2),
                products_paid_in_cash DECIMAL(10,2),
                delivery_price_paid_in_cash DECIMAL(10,2),
                meal_vouchers_discounts DECIMAL(10,2),
                incidents_to_pay_partner DECIMAL(10,2),
                product_with_incidents DECIMAL(10,2),
                incidents_glovo_platform_fee DECIMAL(10,2),
                wait_time_fee DECIMAL(10,2),
                wait_time_fee_refund DECIMAL(10,2),
                prime_order_vendor_fee DECIMAL(10,2),
                flash_deals_fee DECIMAL(10,2),
                KEY idx_store_time (store_name, notification_partner_time),
                UNIQUE KEY unique_order (store_name, notification_partner_time, description(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        " );

        $pdo->exec( "
            CREATE TABLE IF NOT EXISTS gsr_glovo_dettagli_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                dettaglio_id INT UNSIGNED NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                quantity INT NOT NULL,
                KEY idx_dettaglio (dettaglio_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        " );
    }

    // -------------------------------------------------------------------------
    // Elaborazione singolo CSV
    // -------------------------------------------------------------------------

    private function process_csv( string $file, \PDO $pdo, string $processed_dir ): void {
        $filename = basename( $file );
        $this->log[] = 'Elaboro CSV: ' . $filename;

        $handle = fopen( $file, 'r' );
        if ( ! $handle ) {
            $this->log[] = '  ERRORE: impossibile aprire il file.';
            $this->counters['errors']++;
            return;
        }

        // Header row — trova la mappa colonne per posizione
        $header = fgetcsv( $handle, 0, ',' );
        if ( ! $header ) {
            $this->log[] = '  ERRORE: CSV senza header.';
            fclose( $handle );
            $this->counters['errors']++;
            return;
        }

        $header    = array_map( 'trim', $header );
        $col_index = [];
        foreach ( self::CSV_COLUMNS as $col ) {
            $pos = array_search( $col, $header );
            if ( $pos !== false ) {
                $col_index[ $col ] = $pos;
            }
        }

        $rows_ok = 0;
        $duplicates = 0;

        $insert_dettaglio = $pdo->prepare( "
            INSERT INTO gsr_glovo_dettagli (
                csv_filename, notification_partner_time, description, allergie_note,
                store_name, store_address, payment_method,
                price_of_products, product_promotion_paid_by_partner,
                flash_offer_promotion_paid_by_partner, charged_to_partner_base,
                glovo_platform_fee, total_charged_to_partner,
                total_charged_to_partner_percentage,
                delivery_promotion_paid_by_partner, refunds_incidents,
                products_paid_in_cash, delivery_price_paid_in_cash,
                meal_vouchers_discounts, incidents_to_pay_partner,
                product_with_incidents, incidents_glovo_platform_fee,
                wait_time_fee, wait_time_fee_refund,
                prime_order_vendor_fee, flash_deals_fee
            ) VALUES (
                :csv_filename, :notification_partner_time, :description, :allergie_note,
                :store_name, :store_address, :payment_method,
                :price_of_products, :product_promotion_paid_by_partner,
                :flash_offer_promotion_paid_by_partner, :charged_to_partner_base,
                :glovo_platform_fee, :total_charged_to_partner,
                :total_charged_to_partner_percentage,
                :delivery_promotion_paid_by_partner, :refunds_incidents,
                :products_paid_in_cash, :delivery_price_paid_in_cash,
                :meal_vouchers_discounts, :incidents_to_pay_partner,
                :product_with_incidents, :incidents_glovo_platform_fee,
                :wait_time_fee, :wait_time_fee_refund,
                :prime_order_vendor_fee, :flash_deals_fee
            )
        " );

        $insert_item = $pdo->prepare( "
            INSERT INTO gsr_glovo_dettagli_items (dettaglio_id, product_name, quantity)
            VALUES (:dettaglio_id, :product_name, :quantity)
        " );

        while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
            if ( empty( $row ) || ( count( $row ) === 1 && trim( $row[0] ) === '' ) ) continue;

            $get = fn( $col ) => isset( $col_index[ $col ] ) ? ( trim( $row[ $col_index[ $col ] ] ?? '' ) ?: null ) : null;

            // Datetime
            $datetime_raw = $get( 'notification_partner_time' );
            if ( ! $datetime_raw ) continue;
            $datetime = $this->parse_datetime( $datetime_raw );
            if ( ! $datetime ) continue;

            // Description + allergie
            $description_raw = $get( 'description' );
            [ $description, $allergie ] = $this->parse_description( $description_raw );

            $params = [
                ':csv_filename'                        => $filename,
                ':notification_partner_time'           => $datetime,
                ':description'                         => $description,
                ':allergie_note'                       => $allergie,
                ':store_name'                          => $get( 'store_name' ),
                ':store_address'                       => $get( 'store_address' ),
                ':payment_method'                      => $get( 'payment_method' ),
                ':price_of_products'                   => $this->to_decimal( $get( 'price_of_products' ) ),
                ':product_promotion_paid_by_partner'   => $this->to_decimal( $get( 'product_promotion_paid_by_partner' ) ),
                ':flash_offer_promotion_paid_by_partner' => $this->to_decimal( $get( 'flash_offer_promotion_paid_by_partner' ) ),
                ':charged_to_partner_base'             => $this->to_decimal( $get( 'charged_to_partner_base' ) ),
                ':glovo_platform_fee'                  => $this->to_decimal( $get( 'glovo_platform_fee' ) ),
                ':total_charged_to_partner'            => $this->to_decimal( $get( 'total_charged_to_partner' ) ),
                ':total_charged_to_partner_percentage' => $this->to_decimal( $get( 'total_charged_to_partner_percentage' ) ),
                ':delivery_promotion_paid_by_partner'  => $this->to_decimal( $get( 'delivery_promotion_paid_by_partner' ) ),
                ':refunds_incidents'                   => $this->to_decimal( $get( 'refunds_incidents' ) ),
                ':products_paid_in_cash'               => $this->to_decimal( $get( 'products_paid_in_cash' ) ),
                ':delivery_price_paid_in_cash'         => $this->to_decimal( $get( 'delivery_price_paid_in_cash' ) ),
                ':meal_vouchers_discounts'             => $this->to_decimal( $get( 'meal_vouchers_discounts' ) ),
                ':incidents_to_pay_partner'            => $this->to_decimal( $get( 'incidents_to_pay_partner' ) ),
                ':product_with_incidents'              => $this->to_decimal( $get( 'product_with_incidents' ) ),
                ':incidents_glovo_platform_fee'        => $this->to_decimal( $get( 'incidents_glovo_platform_fee' ) ),
                ':wait_time_fee'                       => $this->to_decimal( $get( 'wait_time_fee' ) ),
                ':wait_time_fee_refund'                => $this->to_decimal( $get( 'wait_time_fee_refund' ) ),
                ':prime_order_vendor_fee'              => $this->to_decimal( $get( 'prime_order_vendor_fee' ) ),
                ':flash_deals_fee'                     => $this->to_decimal( $get( 'flash_deals_fee' ) ),
            ];

            try {
                $insert_dettaglio->execute( $params );
                $dettaglio_id = (int) $pdo->lastInsertId();
                $rows_ok++;
                $this->counters['rows_inserted']++;

                // Parse prodotti dalla description
                if ( $description ) {
                    $items = $this->parse_products( $description );
                    foreach ( $items as $item ) {
                        $insert_item->execute( [
                            ':dettaglio_id' => $dettaglio_id,
                            ':product_name' => $item['name'],
                            ':quantity'     => $item['qty'],
                        ] );
                        $this->counters['items_inserted']++;
                    }
                }

            } catch ( \PDOException $e ) {
                if ( $e->getCode() === '23000' ) {
                    $duplicates++;
                    $this->counters['duplicates']++;
                } else {
                    $this->log[] = '  ERRORE DB riga: ' . $e->getMessage();
                    $this->counters['errors']++;
                }
            }
        }

        fclose( $handle );

        $this->log[] = "  OK — inserite: $rows_ok righe, duplicati: $duplicates";
        $this->counters['processed_ok']++;

        rename( $file, $processed_dir . $filename );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function parse_datetime( string $raw ): ?string {
        // Supporta formati: "2024-03-15 14:30:00", "15/03/2024 14:30", ecc.
        $ts = strtotime( $raw );
        if ( $ts === false ) return null;
        return date( 'Y-m-d H:i:s', $ts );
    }

    private function parse_description( ?string $raw ): array {
        if ( $raw === null ) return [ null, null ];

        $allergie = null;
        if ( preg_match( '/ALLERGIE[:\s]+(.+?)(?:\n|$)/i', $raw, $m ) ) {
            $allergie = trim( $m[1] );
            $raw      = preg_replace( '/ALLERGIE[:\s]+.+?(?:\n|$)/i', '', $raw );
        }

        return [ trim( $raw ) ?: null, $allergie ];
    }

    private function parse_products( string $description ): array {
        $items = [];
        $lines = preg_split( '/\r\n|\r|\n/', $description );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            // Formato: "2x Pollo arrosto" oppure "Pollo arrosto"
            if ( preg_match( '/^(\d+)\s*[xX]\s*(.+)$/', $line, $m ) ) {
                $items[] = [ 'qty' => (int) $m[1], 'name' => trim( $m[2] ) ];
            } else {
                $items[] = [ 'qty' => 1, 'name' => $line ];
            }
        }
        return $items;
    }

    private function to_decimal( ?string $val ): ?string {
        if ( $val === null || $val === '' ) return null;
        // Rimuove simbolo valuta e spazi
        $val = str_replace( [ '€', ' ', '"' ], '', $val );
        // Formato europeo 1.234,56 → 1234.56
        if ( preg_match( '/^\-?[\d\.]+,\d{1,2}$/', $val ) ) {
            $val = str_replace( '.', '', $val );
            $val = str_replace( ',', '.', $val );
        }
        return is_numeric( $val ) ? $val : null;
    }

    private function get_pdo(): \PDO {
        $cfg = $this->tenant->get_db_config();
        return new \PDO(
            "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
            $cfg['db_user'],
            $cfg['db_pass'],
            [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ]
        );
    }
}
