<?php
/**
 * Email Auto Reader
 *
 * Lettore automatico email via IMAP
 * Connessione, lettura email non lette, estrazione allegati PDF/CSV
 *
 * @package MSG_Extractor
 * @since 8.0
 */

if (!defined('ABSPATH')) exit;

class Email_Auto_Reader_V7 {

    private $upload_dir;
    private $pdf_dir;
    private $csv_dir;
    private $connection;
    private $glovo_db = null;

    public function __construct() {
        $u = wp_upload_dir();
        $this->upload_dir = $u['basedir'] . '/msg-extracted';
        $this->pdf_dir = $this->upload_dir . '/pdf';
        $this->csv_dir = $this->upload_dir . '/csv';
    }

    /**
     * Connessione al database dash_glovo e creazione tabella se non esiste
     */
    private function get_glovo_db() {
        if ($this->glovo_db && $this->glovo_db->ping()) {
            return $this->glovo_db;
        }

        $config_path = $_SERVER['DOCUMENT_ROOT'] . '/scripts-glovo/config-glovo.php';
        if (!file_exists($config_path)) {
            return null;
        }

        $config = require $config_path;
        if (!is_array($config) || empty($config['db_host'])) {
            return null;
        }

        $this->glovo_db = new mysqli(
            $config['db_host'],
            $config['db_user'],
            $config['db_pass'],
            $config['db_name']
        );

        if ($this->glovo_db->connect_error) {
            $this->glovo_db = null;
            return null;
        }

        $this->glovo_db->set_charset('utf8mb4');

        // Crea tabella se non esiste
        $this->glovo_db->query("
            CREATE TABLE IF NOT EXISTS indice_UID_mail (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uid INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_uid (uid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        return $this->glovo_db;
    }

    /**
     * Conta gli UID nel DB dash_glovo (usato dall'admin per mostrare il conteggio)
     */
    public function get_processed_uids_count() {
        $db = $this->get_glovo_db();
        if (!$db) return 0;

        $result = $db->query("SELECT COUNT(*) as cnt FROM indice_UID_mail");
        if ($result) {
            $row = $result->fetch_assoc();
            return intval($row['cnt']);
        }
        return 0;
    }

    /**
     * Resetta (svuota) la tabella indice_UID_mail
     */
    public function reset_processed_uids() {
        $db = $this->get_glovo_db();
        if (!$db) return false;

        return $db->query("TRUNCATE TABLE indice_UID_mail");
    }

    /**
     * Verifica disponibilità estensione IMAP
     */
    public function is_imap_available() {
        return function_exists('imap_open');
    }

    /**
     * Connessione all'account email
     */
    public function connect($server, $port, $username, $password, $ssl = true) {
        if (!$this->is_imap_available()) {
            return ['success' => false, 'error' => 'Estensione PHP IMAP non disponibile sul server'];
        }

        $protocol = $ssl ? '/imap/ssl/novalidate-cert' : '/imap/novalidate-cert';
        $mailbox = '{' . $server . ':' . $port . $protocol . '}INBOX';

        $this->connection = @imap_open($mailbox, $username, $password);

        if (!$this->connection) {
            return ['success' => false, 'error' => 'Connessione fallita: ' . imap_last_error()];
        }

        return ['success' => true];
    }

    /**
     * Carica gli UID delle email già elaborate dal DB dash_glovo
     */
    private function get_processed_uids() {
        $db = $this->get_glovo_db();
        if (!$db) return array();

        $result = $db->query("SELECT uid FROM indice_UID_mail");
        if (!$result) return array();

        $uids = array();
        while ($row = $result->fetch_assoc()) {
            $uids[] = intval($row['uid']);
        }
        return $uids;
    }

    /**
     * Salva gli UID delle email elaborate nel DB dash_glovo (INSERT IGNORE per evitare duplicati)
     */
    private function save_processed_uids($uids) {
        $db = $this->get_glovo_db();
        if (!$db || empty($uids)) return;

        // Inserisci in blocchi da 500 per efficienza
        $chunks = array_chunk(array_unique($uids), 500);
        foreach ($chunks as $chunk) {
            $values = array();
            foreach ($chunk as $uid) {
                $values[] = '(' . intval($uid) . ')';
            }
            $db->query("INSERT IGNORE INTO indice_UID_mail (uid) VALUES " . implode(',', $values));
        }
    }

    /**
     * Legge email e estrae allegati PDF/CSV con filtri opzionali.
     *
     * Strategia:
     * - Prima esecuzione (nessun UID salvato): cerca SINCE ultimi 7 giorni,
     *   processa quelle email, poi salva TUTTI gli UID della casella come "già visti"
     *   così dalla volta successiva vengono processate solo le email nuove.
     * - Esecuzioni successive (UID presenti): cerca ALL, filtra per UID non ancora
     *   elaborati, processa solo quelli.
     * - In entrambi i casi, allegati già presenti su disco vengono saltati.
     *
     * @param bool $mark_as_read Marca email come lette dopo elaborazione
     * @param array $filters Filtri opzionali (trusted_senders, allowed_extensions, subject_keywords, enable_*)
     * @param int $batch_limit Numero massimo di email da processare (0 = tutte)
     */
    public function process_emails($mark_as_read = false, $filters = array(), $batch_limit = 0) {
        if (!$this->connection) {
            return ['success' => false, 'error' => 'Nessuna connessione attiva'];
        }

        if (!file_exists($this->pdf_dir)) wp_mkdir_p($this->pdf_dir);
        if (!file_exists($this->csv_dir)) wp_mkdir_p($this->csv_dir);

        $empty_stats = ['emails' => 0, 'pdf' => 0, 'csv' => 0, 'total_attachments' => 0, 'skipped_sender' => 0, 'skipped_subject' => 0, 'skipped_ext' => 0, 'skipped_already' => 0];

        $processed_uids = $this->get_processed_uids();
        $is_first_run = empty($processed_uids);

        if ($is_first_run) {
            // === PRIMA ESECUZIONE: SINCE 7 giorni + seed completo UID ===
            return $this->process_first_run($mark_as_read, $filters, $batch_limit, $empty_stats);
        } else {
            // === ESECUZIONI SUCCESSIVE: ALL + UID tracking ===
            return $this->process_subsequent_run($mark_as_read, $filters, $batch_limit, $empty_stats, $processed_uids);
        }
    }

    /**
     * Prima esecuzione: processa email ultimi 7 giorni, poi salva TUTTI gli UID della casella
     */
    private function process_first_run($mark_as_read, $filters, $batch_limit, $stats) {
        // Cerca email degli ultimi 7 giorni
        $since_date = date('d-M-Y', strtotime('-7 days'));
        $recent_emails = @imap_search($this->connection, 'SINCE "' . $since_date . '"', SE_FREE, 'UTF-8');
        if ($recent_emails === false) {
            $recent_emails = @imap_search($this->connection, 'SINCE "' . $since_date . '"');
        }

        // Raccogli TUTTI gli UID della casella per il seed
        $all_emails = @imap_search($this->connection, 'ALL', SE_FREE, 'UTF-8');
        if ($all_emails === false) {
            $all_emails = @imap_search($this->connection, 'ALL');
        }

        $all_uids = array();
        if ($all_emails && is_array($all_emails)) {
            foreach ($all_emails as $email_number) {
                $all_uids[] = imap_uid($this->connection, $email_number);
            }
        }

        // Se non ci sono email recenti, salva comunque tutti gli UID e esci
        if (!$recent_emails || !is_array($recent_emails) || count($recent_emails) === 0) {
            $this->save_processed_uids($all_uids);
            $this->close();
            $stats['skipped_already'] = count($all_uids);
            return [
                'success' => true,
                'stats' => $stats,
                'message' => 'Prima esecuzione: nessuna email negli ultimi 7 giorni. Indicizzate ' . count($all_uids) . ' email esistenti.'
            ];
        }

        // Processa le email recenti
        $result = $this->do_process_list($recent_emails, $mark_as_read, $filters, $batch_limit, $stats, array());

        // Unisci gli UID processati con TUTTI gli UID della casella (seed completo)
        $merged_uids = array_unique(array_merge($all_uids, $result['_uids']));
        $this->save_processed_uids($merged_uids);

        $total_in_mailbox = count($all_uids);
        $processed_count = count($recent_emails);
        $seeded_count = $total_in_mailbox - $processed_count;
        if ($seeded_count > 0) {
            $result['stats']['skipped_already'] = $seeded_count;
        }
        $result['message'] = 'Prima esecuzione: elaborate ' . $processed_count . ' email degli ultimi 7 giorni. Indicizzate ' . $total_in_mailbox . ' email totali.';

        unset($result['_uids']);
        return $result;
    }

    /**
     * Esecuzioni successive: cerca ALL, filtra per UID non ancora elaborati
     */
    private function process_subsequent_run($mark_as_read, $filters, $batch_limit, $stats, $processed_uids) {
        $all_emails = @imap_search($this->connection, 'ALL', SE_FREE, 'UTF-8');
        if ($all_emails === false) {
            $all_emails = @imap_search($this->connection, 'ALL');
        }

        if (!$all_emails || !is_array($all_emails) || count($all_emails) === 0) {
            $imap_errors = imap_errors();
            $this->close();
            if (!empty($imap_errors)) {
                return ['success' => false, 'error' => 'Errore ricerca IMAP: ' . implode(', ', $imap_errors)];
            }
            return ['success' => true, 'stats' => $stats, 'message' => 'Nessuna email trovata nella casella.'];
        }

        // Filtra per UID non ancora elaborati
        $new_emails = array();
        foreach ($all_emails as $email_number) {
            $uid = imap_uid($this->connection, $email_number);
            if (!in_array($uid, $processed_uids)) {
                $new_emails[] = $email_number;
            }
        }

        if (empty($new_emails)) {
            $this->close();
            $stats['skipped_already'] = count($all_emails);
            return ['success' => true, 'stats' => $stats, 'message' => 'Nessuna nuova email da elaborare. (' . count($all_emails) . ' in casella, tutte gi&agrave; elaborate)'];
        }

        $stats['skipped_already'] = count($all_emails) - count($new_emails);
        $result = $this->do_process_list($new_emails, $mark_as_read, $filters, $batch_limit, $stats, $processed_uids);
        $this->save_processed_uids($result['_uids']);
        unset($result['_uids']);
        return $result;
    }

    /**
     * Processa una lista di email, tracciando gli UID e saltando allegati duplicati su disco
     */
    private function do_process_list($emails, $mark_as_read, $filters, $batch_limit, $stats, $processed_uids) {
        @set_time_limit(300);

        if ($batch_limit > 0 && count($emails) > $batch_limit) {
            $emails = array_slice($emails, 0, $batch_limit);
        }

        $filter_sender = !empty($filters['enable_sender_filter']) && !empty($filters['trusted_senders']);
        $filter_subject = !empty($filters['enable_subject_filter']) && !empty($filters['subject_keywords']);
        $allowed_extensions = !empty($filters['allowed_extensions']) ? $filters['allowed_extensions'] : array('pdf', 'csv');

        foreach ($emails as $email_number) {
            $uid = imap_uid($this->connection, $email_number);

            $header = null;
            if ($filter_sender || $filter_subject) {
                $header = imap_headerinfo($this->connection, $email_number);
            }

            if ($filter_sender) {
                $from_email = '';
                if (isset($header->from) && is_array($header->from)) {
                    $from = $header->from[0];
                    $from_email = strtolower($from->mailbox . '@' . $from->host);
                }
                $trusted_senders = array_map('strtolower', $filters['trusted_senders']);
                if (!in_array($from_email, $trusted_senders)) {
                    $stats['skipped_sender']++;
                    $processed_uids[] = $uid;
                    continue;
                }
            }

            if ($filter_subject) {
                $subject = isset($header->subject) ? strtolower($header->subject) : '';
                $found_keyword = false;
                foreach ($filters['subject_keywords'] as $keyword) {
                    if (stripos($subject, strtolower($keyword)) !== false) {
                        $found_keyword = true;
                        break;
                    }
                }
                if (!$found_keyword) {
                    $stats['skipped_subject']++;
                    $processed_uids[] = $uid;
                    continue;
                }
            }

            $stats['emails']++;
            $structure = imap_fetchstructure($this->connection, $email_number);
            if (isset($structure->parts) && is_array($structure->parts)) {
                $this->process_parts($structure->parts, $email_number, 0, $stats, $allowed_extensions);
            }

            if ($mark_as_read) {
                imap_setflag_full($this->connection, $email_number, "\\Seen");
            }

            $processed_uids[] = $uid;
        }

        $this->close();
        return ['success' => true, 'stats' => $stats, '_uids' => $processed_uids];
    }

    /**
     * Processa le parti dell'email (ricorsivo per allegati)
     */
    private function process_parts($parts, $email_number, $prefix, &$stats, $allowed_extensions) {
        foreach ($parts as $part_number => $part) {
            $part_id = $prefix . ($part_number + 1);

            // Se la parte ha sotto-parti, ricorsione
            if (isset($part->parts) && is_array($part->parts)) {
                $this->process_parts($part->parts, $email_number, $part_id . '.', $stats, $allowed_extensions);
                continue;
            }

            // Verifica se è un allegato
            if (isset($part->disposition) && strtolower($part->disposition) == 'attachment') {
                $this->extract_attachment($part, $email_number, $part_id, $stats, $allowed_extensions);
            } elseif (isset($part->dparameters)) {
                // Alcuni server mettono allegati in dparameters
                foreach ($part->dparameters as $dparam) {
                    if (strtolower($dparam->attribute) == 'filename') {
                        $this->extract_attachment($part, $email_number, $part_id, $stats, $allowed_extensions);
                        break;
                    }
                }
            }
        }
    }

    /**
     * Estrae singolo allegato con filtro estensioni
     */
    private function extract_attachment($part, $email_number, $part_id, &$stats, $allowed_extensions) {
        $filename = '';

        // Estrae il nome del file
        if (isset($part->dparameters)) {
            foreach ($part->dparameters as $dparam) {
                if (strtolower($dparam->attribute) == 'filename') {
                    $filename = $dparam->value;
                    break;
                }
            }
        }

        if (!$filename && isset($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) == 'name') {
                    $filename = $param->value;
                    break;
                }
            }
        }

        if (!$filename) return;

        // Decodifica il nome file se necessario (RFC2047)
        $filename = $this->decode_mime_string($filename);

        // Verifica estensione
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Applica filtro estensioni
        if (!in_array($ext, $allowed_extensions)) {
            $stats['skipped_ext']++;
            return;
        }

        // Scarica il contenuto dell'allegato
        $data = imap_fetchbody($this->connection, $email_number, $part_id);

        // Decodifica in base all'encoding
        if (isset($part->encoding)) {
            switch ($part->encoding) {
                case 3: // BASE64
                    $data = base64_decode($data);
                    break;
                case 4: // QUOTED-PRINTABLE
                    $data = quoted_printable_decode($data);
                    break;
            }
        }

        if (empty($data)) return;

        // Determina directory destinazione in base all'estensione
        if ($ext === 'pdf') {
            $dest_dir = $this->pdf_dir;
        } elseif ($ext === 'csv') {
            $dest_dir = $this->csv_dir;
        } else {
            // Altri file vanno nella directory principale
            $dest_dir = $this->upload_dir;
            if (!file_exists($dest_dir)) wp_mkdir_p($dest_dir);
        }

        // Salva il file
        $clean_name = sanitize_file_name($filename);
        $target_path = $dest_dir . '/' . $clean_name;

        if (file_put_contents($target_path, $data)) {
            $stats['total_attachments']++;
            if ($ext === 'pdf') $stats['pdf']++;
            if ($ext === 'csv') $stats['csv']++;
        }
    }

    /**
     * Decodifica stringhe MIME encoded
     */
    private function decode_mime_string($string) {
        $decoded = imap_mime_header_decode($string);
        $result = '';
        foreach ($decoded as $part) {
            $result .= $part->text;
        }
        return $result;
    }

    /**
     * Chiude la connessione
     */
    public function close() {
        if ($this->connection) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Invia report estrazione via email
     */
    public function send_report($stats, $recipients) {
        if (empty($recipients) || $stats['total_attachments'] == 0) {
            return;
        }

        $site_name = get_bloginfo('name');
        $subject = '[' . $site_name . '] Report Estrazione Email Glovo';

        $body = '<html><body>';
        $body .= '<h2>Report Estrazione Allegati</h2>';
        $body .= '<p>Data: <strong>' . date_i18n('d/m/Y H:i:s') . '</strong></p>';
        $body .= '<table style="border-collapse: collapse; min-width: 300px;">';
        $body .= '<tr style="background: #0073aa; color: #fff;"><th style="padding: 8px 15px; text-align: left;">Voce</th><th style="padding: 8px 15px; text-align: right;">Valore</th></tr>';
        $body .= '<tr><td style="padding: 8px 15px; border-bottom: 1px solid #ddd;">Email elaborate</td><td style="padding: 8px 15px; border-bottom: 1px solid #ddd; text-align: right;"><strong>' . $stats['emails'] . '</strong></td></tr>';
        $body .= '<tr><td style="padding: 8px 15px; border-bottom: 1px solid #ddd;">Allegati totali</td><td style="padding: 8px 15px; border-bottom: 1px solid #ddd; text-align: right;"><strong>' . $stats['total_attachments'] . '</strong></td></tr>';
        $body .= '<tr><td style="padding: 8px 15px; border-bottom: 1px solid #ddd;">PDF estratti</td><td style="padding: 8px 15px; border-bottom: 1px solid #ddd; text-align: right;"><strong>' . $stats['pdf'] . '</strong></td></tr>';
        $body .= '<tr><td style="padding: 8px 15px; border-bottom: 1px solid #ddd;">CSV estratti</td><td style="padding: 8px 15px; border-bottom: 1px solid #ddd; text-align: right;"><strong>' . $stats['csv'] . '</strong></td></tr>';

        if ($stats['skipped_sender'] > 0 || $stats['skipped_subject'] > 0 || $stats['skipped_ext'] > 0) {
            $body .= '<tr><td colspan="2" style="padding: 12px 15px; background: #fff3cd;"><strong>Scartate dai filtri:</strong>';
            if ($stats['skipped_sender'] > 0) $body .= ' Mittente: ' . $stats['skipped_sender'];
            if ($stats['skipped_subject'] > 0) $body .= ' | Oggetto: ' . $stats['skipped_subject'];
            if ($stats['skipped_ext'] > 0) $body .= ' | Estensione: ' . $stats['skipped_ext'];
            $body .= '</td></tr>';
        }

        $body .= '</table>';
        $body .= '<p style="color: #666; font-size: 12px; margin-top: 20px;">Inviato automaticamente da Estrattore Email Glovo</p>';
        $body .= '</body></html>';

        $headers = array('Content-Type: text/html; charset=UTF-8');

        foreach ($recipients as $to) {
            wp_mail($to, $subject, $body, $headers);
        }
    }

    /**
     * Testa la connessione senza processare email e restituisce info sulla casella
     */
    public function test_connection($server, $port, $username, $password, $ssl = true) {
        $result = $this->connect($server, $port, $username, $password, $ssl);
        if ($result['success']) {
            // Recupera statistiche casella
            $mailbox_info = @imap_status($this->connection, '{' . $server . ':' . $port . ($ssl ? '/imap/ssl/novalidate-cert' : '/imap/novalidate-cert') . '}INBOX', SA_ALL);
            $info = [];
            if ($mailbox_info) {
                $info['total'] = $mailbox_info->messages;
                $info['unseen'] = $mailbox_info->unseen;
            }
            $this->close();

            $message = 'Connessione riuscita!';
            if (!empty($info)) {
                $message .= ' Email in INBOX: ' . $info['total'] . ' totali, ' . $info['unseen'] . ' non lette.';
            }

            return ['success' => true, 'message' => $message, 'mailbox_info' => $info];
        }
        return $result;
    }
}
