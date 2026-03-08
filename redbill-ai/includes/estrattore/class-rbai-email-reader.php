<?php
/**
 * RBAI Email Reader — Lettore IMAP tenant-aware
 *
 * Legge email via IMAP, estrae allegati PDF/CSV e li salva nella
 * directory upload del tenant. Usa la tabella indice_UID_mail nel
 * database dedicato del tenant per evitare duplicati.
 *
 * @package Redbill_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RBAI_Email_Reader {

    private RBAI_Tenant $tenant;
    private array       $email_config;
    private string      $pdf_dir;
    private string      $csv_dir;
    private ?mysqli     $tenant_db = null;
    private             $connection = null;

    public function __construct( RBAI_Tenant $tenant ) {
        $this->tenant       = $tenant;
        $this->email_config = $tenant->get_email_config();

        $base_dir      = $tenant->get_upload_dir();
        $this->pdf_dir = $base_dir . 'pdf/';
        $this->csv_dir = $base_dir . 'csv/';
    }

    /**
     * Punto di ingresso per il cron per-tenant.
     */
    public static function run_for_tenant( RBAI_Tenant $tenant ): void {
        if ( ! $tenant->is_active() ) return;

        $reader = new self( $tenant );
        if ( empty( $reader->email_config['server'] ) ) return;

        $connect = $reader->connect(
            $reader->email_config['server'],
            $reader->email_config['port']    ?? 993,
            $reader->email_config['username'] ?? '',
            $reader->email_config['password'] ?? '',
            $reader->email_config['ssl']      ?? true
        );

        if ( ! $connect['success'] ) return;

        $filters = [
            'trusted_senders'      => $reader->email_config['trusted_senders']      ?? [],
            'allowed_extensions'   => $reader->email_config['allowed_extensions']   ?? [ 'pdf', 'csv' ],
            'subject_keywords'     => $reader->email_config['subject_keywords']      ?? [],
            'enable_sender_filter' => $reader->email_config['enable_sender_filter'] ?? false,
            'enable_subject_filter'=> $reader->email_config['enable_subject_filter']?? false,
        ];

        $batch_limit = intval( $reader->email_config['cron_batch_limit'] ?? 50 );
        $mark_read   = ! empty( $reader->email_config['mark_as_read'] );

        $result = $reader->process_emails( $mark_read, $filters, $batch_limit );

        if ( $result['success'] && ! empty( $reader->email_config['enable_report'] )
             && ! empty( $reader->email_config['report_recipients'] )
             && $result['stats']['total_attachments'] > 0 ) {
            $reader->send_report( $result['stats'], $reader->email_config['report_recipients'] );
        }
    }

    // -------------------------------------------------------------------------
    // Connessione IMAP
    // -------------------------------------------------------------------------

    public function is_imap_available(): bool {
        return function_exists( 'imap_open' );
    }

    public function connect( string $server, int $port, string $username, string $password, bool $ssl = true ): array {
        if ( ! $this->is_imap_available() ) {
            return [ 'success' => false, 'error' => 'Estensione PHP IMAP non disponibile sul server' ];
        }

        $protocol = $ssl ? '/imap/ssl/novalidate-cert' : '/imap/novalidate-cert';
        $mailbox  = '{' . $server . ':' . $port . $protocol . '}INBOX';

        $this->connection = @imap_open( $mailbox, $username, $password );

        if ( ! $this->connection ) {
            return [ 'success' => false, 'error' => 'Connessione fallita: ' . imap_last_error() ];
        }

        return [ 'success' => true ];
    }

    public function test_connection( string $server, int $port, string $username, string $password, bool $ssl = true ): array {
        $result = $this->connect( $server, $port, $username, $password, $ssl );
        if ( ! $result['success'] ) return $result;

        $protocol = $ssl ? '/imap/ssl/novalidate-cert' : '/imap/novalidate-cert';
        $mailbox_info = @imap_status(
            $this->connection,
            '{' . $server . ':' . $port . $protocol . '}INBOX',
            SA_ALL
        );

        $info = [];
        if ( $mailbox_info ) {
            $info['total']  = $mailbox_info->messages;
            $info['unseen'] = $mailbox_info->unseen;
        }
        $this->close();

        $message = 'Connessione riuscita!';
        if ( ! empty( $info ) ) {
            $message .= ' Email in INBOX: ' . $info['total'] . ' totali, ' . $info['unseen'] . ' non lette.';
        }

        return [ 'success' => true, 'message' => $message, 'mailbox_info' => $info ];
    }

    public function close(): void {
        if ( $this->connection ) {
            imap_close( $this->connection );
            $this->connection = null;
        }
    }

    // -------------------------------------------------------------------------
    // Tracking UID nel DB tenant
    // -------------------------------------------------------------------------

    private function get_tenant_db(): ?mysqli {
        if ( $this->tenant_db && $this->tenant_db->ping() ) {
            return $this->tenant_db;
        }

        $cfg = $this->tenant->get_db_config();
        $db  = new mysqli( $cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name'] );

        if ( $db->connect_error ) return null;

        $db->set_charset( 'utf8mb4' );
        $db->query( "
            CREATE TABLE IF NOT EXISTS indice_UID_mail (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                uid        INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_uid (uid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        " );

        $this->tenant_db = $db;
        return $db;
    }

    public function get_processed_uids_count(): int {
        $db = $this->get_tenant_db();
        if ( ! $db ) return 0;
        $result = $db->query( 'SELECT COUNT(*) AS cnt FROM indice_UID_mail' );
        return $result ? (int) $result->fetch_assoc()['cnt'] : 0;
    }

    public function reset_processed_uids(): bool {
        $db = $this->get_tenant_db();
        return $db ? (bool) $db->query( 'TRUNCATE TABLE indice_UID_mail' ) : false;
    }

    private function get_processed_uids(): array {
        $db = $this->get_tenant_db();
        if ( ! $db ) return [];
        $result = $db->query( 'SELECT uid FROM indice_UID_mail' );
        if ( ! $result ) return [];
        $uids = [];
        while ( $row = $result->fetch_assoc() ) {
            $uids[] = (int) $row['uid'];
        }
        return $uids;
    }

    private function save_processed_uids( array $uids ): void {
        $db = $this->get_tenant_db();
        if ( ! $db || empty( $uids ) ) return;

        foreach ( array_chunk( array_unique( $uids ), 500 ) as $chunk ) {
            $values = implode( ',', array_map( fn( $u ) => '(' . (int) $u . ')', $chunk ) );
            $db->query( "INSERT IGNORE INTO indice_UID_mail (uid) VALUES $values" );
        }
    }

    // -------------------------------------------------------------------------
    // Elaborazione email
    // -------------------------------------------------------------------------

    public function process_emails( bool $mark_as_read = false, array $filters = [], int $batch_limit = 0 ): array {
        if ( ! $this->connection ) {
            return [ 'success' => false, 'error' => 'Nessuna connessione attiva' ];
        }

        wp_mkdir_p( $this->pdf_dir );
        wp_mkdir_p( $this->csv_dir );

        $empty_stats = [
            'emails' => 0, 'pdf' => 0, 'csv' => 0, 'total_attachments' => 0,
            'skipped_sender' => 0, 'skipped_subject' => 0, 'skipped_ext' => 0, 'skipped_already' => 0,
        ];

        $processed_uids = $this->get_processed_uids();
        $is_first_run   = empty( $processed_uids );

        if ( $is_first_run ) {
            return $this->process_first_run( $mark_as_read, $filters, $batch_limit, $empty_stats );
        }
        return $this->process_subsequent_run( $mark_as_read, $filters, $batch_limit, $empty_stats, $processed_uids );
    }

    private function process_first_run( bool $mark_as_read, array $filters, int $batch_limit, array $stats ): array {
        $since_date    = date( 'd-M-Y', strtotime( '-7 days' ) );
        $recent_emails = @imap_search( $this->connection, 'SINCE "' . $since_date . '"', SE_FREE, 'UTF-8' );
        if ( $recent_emails === false ) {
            $recent_emails = @imap_search( $this->connection, 'SINCE "' . $since_date . '"' );
        }

        $all_emails = @imap_search( $this->connection, 'ALL', SE_FREE, 'UTF-8' );
        if ( $all_emails === false ) {
            $all_emails = @imap_search( $this->connection, 'ALL' );
        }

        $all_uids = [];
        if ( $all_emails && is_array( $all_emails ) ) {
            foreach ( $all_emails as $num ) {
                $all_uids[] = imap_uid( $this->connection, $num );
            }
        }

        if ( ! $recent_emails || ! is_array( $recent_emails ) || count( $recent_emails ) === 0 ) {
            $this->save_processed_uids( $all_uids );
            $this->close();
            $stats['skipped_already'] = count( $all_uids );
            return [
                'success' => true,
                'stats'   => $stats,
                'message' => 'Prima esecuzione: nessuna email negli ultimi 7 giorni. Indicizzate ' . count( $all_uids ) . ' email esistenti.',
            ];
        }

        $result      = $this->do_process_list( $recent_emails, $mark_as_read, $filters, $batch_limit, $stats, [] );
        $merged_uids = array_unique( array_merge( $all_uids, $result['_uids'] ) );
        $this->save_processed_uids( $merged_uids );

        $seeded_count = count( $all_uids ) - count( $recent_emails );
        if ( $seeded_count > 0 ) {
            $result['stats']['skipped_already'] = $seeded_count;
        }
        $result['message'] = 'Prima esecuzione: elaborate ' . count( $recent_emails ) . ' email degli ultimi 7 giorni. Indicizzate ' . count( $all_uids ) . ' email totali.';
        unset( $result['_uids'] );
        return $result;
    }

    private function process_subsequent_run( bool $mark_as_read, array $filters, int $batch_limit, array $stats, array $processed_uids ): array {
        $all_emails = @imap_search( $this->connection, 'ALL', SE_FREE, 'UTF-8' );
        if ( $all_emails === false ) {
            $all_emails = @imap_search( $this->connection, 'ALL' );
        }

        if ( ! $all_emails || ! is_array( $all_emails ) || count( $all_emails ) === 0 ) {
            $imap_errors = imap_errors();
            $this->close();
            if ( ! empty( $imap_errors ) ) {
                return [ 'success' => false, 'error' => 'Errore IMAP: ' . implode( ', ', $imap_errors ) ];
            }
            return [ 'success' => true, 'stats' => $stats, 'message' => 'Nessuna email trovata nella casella.' ];
        }

        $new_emails = [];
        foreach ( $all_emails as $num ) {
            $uid = imap_uid( $this->connection, $num );
            if ( ! in_array( $uid, $processed_uids, true ) ) {
                $new_emails[] = $num;
            }
        }

        if ( empty( $new_emails ) ) {
            $this->close();
            $stats['skipped_already'] = count( $all_emails );
            return [ 'success' => true, 'stats' => $stats, 'message' => 'Nessuna nuova email da elaborare. (' . count( $all_emails ) . ' in casella, tutte già elaborate)' ];
        }

        $stats['skipped_already'] = count( $all_emails ) - count( $new_emails );
        $result = $this->do_process_list( $new_emails, $mark_as_read, $filters, $batch_limit, $stats, $processed_uids );
        $this->save_processed_uids( $result['_uids'] );
        unset( $result['_uids'] );
        return $result;
    }

    private function do_process_list( array $emails, bool $mark_as_read, array $filters, int $batch_limit, array $stats, array $processed_uids ): array {
        @set_time_limit( 300 );

        if ( $batch_limit > 0 && count( $emails ) > $batch_limit ) {
            $emails = array_slice( $emails, 0, $batch_limit );
        }

        $filter_sender  = ! empty( $filters['enable_sender_filter'] ) && ! empty( $filters['trusted_senders'] );
        $filter_subject = ! empty( $filters['enable_subject_filter'] ) && ! empty( $filters['subject_keywords'] );
        $allowed_ext    = ! empty( $filters['allowed_extensions'] ) ? $filters['allowed_extensions'] : [ 'pdf', 'csv' ];

        foreach ( $emails as $email_number ) {
            $uid    = imap_uid( $this->connection, $email_number );
            $header = null;

            if ( $filter_sender || $filter_subject ) {
                $header = imap_headerinfo( $this->connection, $email_number );
            }

            if ( $filter_sender ) {
                $from_email = '';
                if ( isset( $header->from ) && is_array( $header->from ) ) {
                    $from       = $header->from[0];
                    $from_email = strtolower( $from->mailbox . '@' . $from->host );
                }
                $trusted = array_map( 'strtolower', $filters['trusted_senders'] );
                if ( ! in_array( $from_email, $trusted, true ) ) {
                    $stats['skipped_sender']++;
                    $processed_uids[] = $uid;
                    continue;
                }
            }

            if ( $filter_subject ) {
                $subject  = isset( $header->subject ) ? strtolower( $header->subject ) : '';
                $found    = false;
                foreach ( $filters['subject_keywords'] as $keyword ) {
                    if ( stripos( $subject, strtolower( $keyword ) ) !== false ) {
                        $found = true;
                        break;
                    }
                }
                if ( ! $found ) {
                    $stats['skipped_subject']++;
                    $processed_uids[] = $uid;
                    continue;
                }
            }

            $stats['emails']++;
            $structure = imap_fetchstructure( $this->connection, $email_number );
            if ( isset( $structure->parts ) && is_array( $structure->parts ) ) {
                $this->process_parts( $structure->parts, $email_number, 0, $stats, $allowed_ext );
            }

            if ( $mark_as_read ) {
                imap_setflag_full( $this->connection, $email_number, '\\Seen' );
            }

            $processed_uids[] = $uid;
        }

        $this->close();
        return [ 'success' => true, 'stats' => $stats, '_uids' => $processed_uids ];
    }

    private function process_parts( array $parts, int $email_number, $prefix, array &$stats, array $allowed_ext ): void {
        foreach ( $parts as $part_number => $part ) {
            $part_id = $prefix . ( $part_number + 1 );

            if ( isset( $part->parts ) && is_array( $part->parts ) ) {
                $this->process_parts( $part->parts, $email_number, $part_id . '.', $stats, $allowed_ext );
                continue;
            }

            if ( isset( $part->disposition ) && strtolower( $part->disposition ) === 'attachment' ) {
                $this->extract_attachment( $part, $email_number, $part_id, $stats, $allowed_ext );
            } elseif ( isset( $part->dparameters ) ) {
                foreach ( $part->dparameters as $dparam ) {
                    if ( strtolower( $dparam->attribute ) === 'filename' ) {
                        $this->extract_attachment( $part, $email_number, $part_id, $stats, $allowed_ext );
                        break;
                    }
                }
            }
        }
    }

    private function extract_attachment( $part, int $email_number, $part_id, array &$stats, array $allowed_ext ): void {
        $filename = '';

        if ( isset( $part->dparameters ) ) {
            foreach ( $part->dparameters as $dparam ) {
                if ( strtolower( $dparam->attribute ) === 'filename' ) {
                    $filename = $dparam->value;
                    break;
                }
            }
        }
        if ( ! $filename && isset( $part->parameters ) ) {
            foreach ( $part->parameters as $param ) {
                if ( strtolower( $param->attribute ) === 'name' ) {
                    $filename = $param->value;
                    break;
                }
            }
        }
        if ( ! $filename ) return;

        $decoded = imap_mime_header_decode( $filename );
        $filename = '';
        foreach ( $decoded as $p ) {
            $filename .= $p->text;
        }

        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed_ext, true ) ) {
            $stats['skipped_ext']++;
            return;
        }

        $data = imap_fetchbody( $this->connection, $email_number, $part_id );
        if ( isset( $part->encoding ) ) {
            switch ( $part->encoding ) {
                case 3: $data = base64_decode( $data ); break;
                case 4: $data = quoted_printable_decode( $data ); break;
            }
        }
        if ( empty( $data ) ) return;

        $dest_dir  = ( $ext === 'pdf' ) ? $this->pdf_dir : $this->csv_dir;
        $clean     = sanitize_file_name( $filename );
        $dest_path = $dest_dir . $clean;

        // Unique filename
        if ( file_exists( $dest_path ) ) {
            $info      = pathinfo( $clean );
            $base_name = $info['filename'];
            $ext_str   = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
            $c         = 1;
            while ( file_exists( $dest_dir . $base_name . '-' . $c . $ext_str ) ) {
                $c++;
            }
            $dest_path = $dest_dir . $base_name . '-' . $c . $ext_str;
        }

        if ( file_put_contents( $dest_path, $data ) ) {
            $stats['total_attachments']++;
            if ( $ext === 'pdf' ) $stats['pdf']++;
            if ( $ext === 'csv' ) $stats['csv']++;
        }
    }

    // -------------------------------------------------------------------------
    // Report
    // -------------------------------------------------------------------------

    public function send_report( array $stats, array $recipients ): void {
        if ( empty( $recipients ) || $stats['total_attachments'] === 0 ) return;

        $subject  = '[' . get_bloginfo( 'name' ) . '] Report Estrazione Email — ' . $this->tenant->get_slug();
        $slug     = esc_html( $this->tenant->get_slug() );

        $body  = '<html><body>';
        $body .= '<h2>Report Estrazione Allegati — Tenant: ' . $slug . '</h2>';
        $body .= '<p>Data: <strong>' . date_i18n( 'd/m/Y H:i:s' ) . '</strong></p>';
        $body .= '<table style="border-collapse:collapse;min-width:300px;">';
        $body .= '<tr style="background:#0073aa;color:#fff;"><th style="padding:8px 15px;text-align:left;">Voce</th><th style="padding:8px 15px;text-align:right;">Valore</th></tr>';
        $body .= '<tr><td style="padding:8px 15px;border-bottom:1px solid #ddd;">Email elaborate</td><td style="padding:8px 15px;border-bottom:1px solid #ddd;text-align:right;"><strong>' . $stats['emails'] . '</strong></td></tr>';
        $body .= '<tr><td style="padding:8px 15px;border-bottom:1px solid #ddd;">Allegati totali</td><td style="padding:8px 15px;border-bottom:1px solid #ddd;text-align:right;"><strong>' . $stats['total_attachments'] . '</strong></td></tr>';
        $body .= '<tr><td style="padding:8px 15px;border-bottom:1px solid #ddd;">PDF estratti</td><td style="padding:8px 15px;border-bottom:1px solid #ddd;text-align:right;"><strong>' . $stats['pdf'] . '</strong></td></tr>';
        $body .= '<tr><td style="padding:8px 15px;border-bottom:1px solid #ddd;">CSV estratti</td><td style="padding:8px 15px;border-bottom:1px solid #ddd;text-align:right;"><strong>' . $stats['csv'] . '</strong></td></tr>';
        $body .= '</table>';
        $body .= '</body></html>';

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        foreach ( $recipients as $to ) {
            wp_mail( $to, $subject, $body, $headers );
        }
    }
}
