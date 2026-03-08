<?php
/**
 * Classe per gestire l'export CSV dei prodotti
 * Estrae tutti i prodotti dalla tabella gsr_glovo_dettagli_items
 */

if (!defined('ABSPATH')) {
    exit;
}

class GID_Products_CSV_Export {

    private $db_config;
    private $connection;

    public function __construct() {
        // Carica la configurazione del database
        $this->db_config = $this->load_config();
        $this->connection = null;

        // Registra gli hook
        add_action('init', array($this, 'handle_csv_download'));
    }

    /**
     * Carica la configurazione del database
     * Cerca prima config-glovo.php esistente, poi usa config-db.php come fallback
     */
    private function load_config() {
        // Percorsi possibili per config-glovo.php
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

        // Cerca config-glovo.php esistente
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $config = require $path;

                // Se il config-glovo.php ritorna un array con le chiavi corrette, usalo
                if (is_array($config) && isset($config['db_name'])) {
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
     * Distruttore - chiude la connessione
     */
    public function __destruct() {
        $this->close_connection();
    }

    /**
     * Gestisce il download del CSV quando viene richiesto
     */
    public function handle_csv_download() {
        if (isset($_GET['gid_export_products_csv']) && $_GET['gid_export_products_csv'] === '1') {
            // Verifica il nonce per sicurezza
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'gid_export_products_csv')) {
                wp_die('Operazione non autorizzata');
            }

            $this->export_products_csv();
            exit;
        }
    }

    /**
     * Esporta tutti i prodotti in formato CSV
     */
    private function export_products_csv() {
        $conn = $this->get_connection();
        if (!$conn) {
            wp_die('Errore di connessione al database');
        }

        // Query per ottenere tutti i prodotti con statistiche aggregate
        $query = "
            SELECT
                i.product_name AS 'Nome Prodotto',
                SUM(i.quantity) AS 'Quantità Totale Venduta',
                COUNT(DISTINCT d.id) AS 'Numero Ordini',
                MIN(d.notification_partner_time) AS 'Primo Ordine',
                MAX(d.notification_partner_time) AS 'Ultimo Ordine',
                COUNT(DISTINCT d.store_name) AS 'Numero Negozi'
            FROM gsr_glovo_dettagli_items i
            INNER JOIN gsr_glovo_dettagli d ON i.dettaglio_id = d.id
            GROUP BY i.product_name
            ORDER BY SUM(i.quantity) DESC
        ";

        $result = $conn->query($query);

        if (!$result) {
            error_log('GID CSV Export Error: ' . $conn->error);
            wp_die('Errore durante l\'estrazione dei dati: ' . $conn->error);
        }

        // Prepara il file CSV per il download
        $filename = 'prodotti-glovo-' . date('Y-m-d-His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Apri l'output stream
        $output = fopen('php://output', 'w');

        // Aggiungi BOM UTF-8 per Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Scrivi l'intestazione
        $headers = array(
            'Nome Prodotto',
            'Quantità Totale Venduta',
            'Numero Ordini',
            'Primo Ordine',
            'Ultimo Ordine',
            'Numero Negozi'
        );
        fputcsv($output, $headers, ';');

        // Scrivi i dati
        while ($row = $result->fetch_assoc()) {
            $csv_row = array(
                $row['Nome Prodotto'],
                $row['Quantità Totale Venduta'],
                $row['Numero Ordini'],
                $row['Primo Ordine'],
                $row['Ultimo Ordine'],
                $row['Numero Negozi']
            );
            fputcsv($output, $csv_row, ';');
        }

        fclose($output);
        $result->free();
        $this->close_connection();
    }

    /**
     * Renderizza il pulsante per l'export CSV
     */
    public function render_export_button() {
        $nonce = wp_create_nonce('gid_export_products_csv');
        $export_url = add_query_arg(array(
            'gid_export_products_csv' => '1',
            '_wpnonce' => $nonce
        ), home_url());

        ob_start();
        ?>
        <div class="gid-products-csv-export-container" style="margin: 20px 0;">
            <a href="<?php echo esc_url($export_url); ?>" class="button button-primary">
                <span class="dashicons dashicons-download" style="vertical-align: middle; margin-top: 3px;"></span>
                Scarica Prodotti CSV
            </a>
            <p class="description" style="margin-top: 10px;">
                Scarica l'elenco completo di tutti i prodotti presenti nei dettagli ordini in formato CSV.
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode per visualizzare il pulsante di export
     */
    public function shortcode_export_button($atts) {
        return $this->render_export_button();
    }
}
