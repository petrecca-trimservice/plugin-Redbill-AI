<?php
/**
 * Classe per gestire il report dettagli Glovo
 * Utilizza mysqli per connettersi al database dash_glovo
 */

if (!defined('ABSPATH')) {
    exit;
}

class GID_Glovo_Details_Report {

    private $db_config;
    private $connection;

    public function __construct() {
        // Carica la configurazione del database
        $this->db_config = $this->load_config();
        $this->connection = null;
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
     * Renderizza lo shortcode per il report dettagli
     */
    public static function render($atts) {
        // Crea un'istanza della classe per accedere ai metodi
        $report = new self();

        // Leggi i filtri dai parametri GET
        $filters = array(
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '',
            'product' => isset($_GET['product']) ? stripslashes(sanitize_text_field($_GET['product'])) : '',
            'store' => isset($_GET['store']) ? stripslashes(sanitize_text_field($_GET['store'])) : '',
            'payment' => isset($_GET['payment']) ? stripslashes(sanitize_text_field($_GET['payment'])) : '',
            'hour_from' => isset($_GET['hour_from']) ? sanitize_text_field($_GET['hour_from']) : '',
            'hour_to' => isset($_GET['hour_to']) ? sanitize_text_field($_GET['hour_to']) : '',
        );

        // Ottieni le opzioni per i filtri
        $filter_options = $report->get_filter_options();

        // Ottieni i dati
        $limit_details = 500;
        $limit_orders = 500;

        $details_data = $report->get_details_data($filters, $limit_details);
        $products_data = $report->get_products_aggregate($filters);
        $orders_data = $report->get_orders_data($filters, $limit_orders);

        ob_start();
        ?>
        <div class="gid-dashboard-wrapper gid-details-report">
            <h1>Report Glovo - Dettagli, prodotti e ordini</h1>

            <!-- Filtri -->
            <div class="gid-filters gid-details-filters">
                <h3>Filtri Report</h3>
                <form id="gid-details-filter-form">
                    <div class="gid-filter-row">
                        <div class="gid-filter-group">
                            <label for="details-filter-date-from">Data da</label>
                            <input type="date" id="details-filter-date-from" name="date_from"
                                   value="<?php echo esc_attr($filters['date_from']); ?>">
                        </div>

                        <div class="gid-filter-group">
                            <label for="details-filter-date-to">Data a</label>
                            <input type="date" id="details-filter-date-to" name="date_to"
                                   value="<?php echo esc_attr($filters['date_to']); ?>">
                        </div>

                        <div class="gid-filter-group">
                            <label for="details-filter-product">Prodotto</label>
                            <select id="details-filter-product" name="product">
                                <option value="">-- Tutti --</option>
                                <?php foreach ($filter_options['products'] as $product): ?>
                                    <option value="<?php echo esc_attr($product); ?>"
                                            <?php selected($filters['product'], $product); ?>>
                                        <?php echo esc_html($product); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="gid-filter-group">
                            <label for="details-filter-store">Store</label>
                            <select id="details-filter-store" name="store">
                                <option value="">-- Tutti --</option>
                                <?php foreach ($filter_options['stores'] as $store): ?>
                                    <option value="<?php echo esc_attr($store); ?>"
                                            <?php selected($filters['store'], $store); ?>>
                                        <?php echo esc_html($store); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="gid-filter-group">
                            <label for="details-filter-payment">Metodo pagamento</label>
                            <select id="details-filter-payment" name="payment">
                                <option value="">-- Tutti --</option>
                                <?php foreach ($filter_options['payments'] as $payment): ?>
                                    <option value="<?php echo esc_attr($payment); ?>"
                                            <?php selected($filters['payment'], $payment); ?>>
                                        <?php echo esc_html($payment); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="gid-filter-group">
                            <label>Ora (da / a)</label>
                            <div style="display: flex; gap: 5px;">
                                <select name="hour_from" id="details-filter-hour-from">
                                    <option value="">-- da --</option>
                                    <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?php echo $h; ?>"
                                                <?php selected($filters['hour_from'], (string)$h); ?>>
                                            <?php echo sprintf('%02d:00', $h); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <select name="hour_to" id="details-filter-hour-to">
                                    <option value="">-- a --</option>
                                    <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?php echo $h; ?>"
                                                <?php selected($filters['hour_to'], (string)$h); ?>>
                                            <?php echo sprintf('%02d:59', $h); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="gid-filter-actions">
                        <button type="submit" class="gid-btn gid-btn-primary">Applica Filtri</button>
                        <button type="button" class="gid-btn gid-btn-secondary" id="gid-details-reset-filters">Reset</button>
                    </div>
                </form>

                <?php
                // Mostra filtri attivi
                $filters_active = array_filter($filters);
                if (!empty($filters_active)):
                ?>
                    <div class="gid-filters-active">
                        <strong>Filtri attivi:</strong>
                        <?php
                        $filter_labels = array(
                            'date_from' => 'Data da',
                            'date_to' => 'Data a',
                            'product' => 'Prodotto',
                            'store' => 'Store',
                            'payment' => 'Metodo pagamento',
                            'hour_from' => 'Ora da',
                            'hour_to' => 'Ora a'
                        );
                        foreach ($filters_active as $key => $value) {
                            $label = isset($filter_labels[$key]) ? $filter_labels[$key] : $key;
                            echo '<span class="gid-filter-tag">' . esc_html($label) . ': ' . esc_html($value) . '</span> ';
                        }
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- SEZIONE 1: DETTAGLIO RIGA/PRODOTTO -->
            <div class="gid-details-section">
                <h2>Dettaglio righe (prodotti per ordine)</h2>
                <p class="gid-section-info">
                    Trovate <strong><?php echo count($details_data); ?></strong> righe
                    (limite massimo: <?php echo $limit_details; ?>).
                    Ogni riga = <strong>un prodotto in un singolo ordine</strong>.
                </p>

                <div class="gid-table-responsive">
                    <table class="gid-details-table">
                        <thead>
                            <tr>
                                <th>Data/Ora</th>
                                <th>Store</th>
                                <th>Metodo pagamento</th>
                                <th>Prodotto</th>
                                <th class="gid-text-right">Quantità</th>
                                <th class="gid-text-right">Prezzo prodotti</th>
                                <th class="gid-text-right">Totale addebitato</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($details_data)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center;">Nessun dato trovato per i filtri selezionati.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($details_data as $row): ?>
                                <tr>
                                    <td><?php echo esc_html($row['notification_partner_time']); ?></td>
                                    <td><?php echo esc_html($row['store_name']); ?></td>
                                    <td><?php echo esc_html($row['payment_method']); ?></td>
                                    <td><?php echo esc_html($row['product_name']); ?></td>
                                    <td class="gid-text-right"><?php echo (int)$row['quantity']; ?></td>
                                    <td class="gid-text-right">
                                        <?php echo $row['price_of_products'] !== null ? number_format($row['price_of_products'], 2, ',', '.') . ' €' : ''; ?>
                                    </td>
                                    <td class="gid-text-right">
                                        <?php echo $row['total_charged_to_partner'] !== null ? number_format($row['total_charged_to_partner'], 2, ',', '.') . ' €' : ''; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SEZIONE 2: AGGREGATO PER PRODOTTO -->
            <div class="gid-details-section">
                <h2>Riepilogo per prodotto</h2>
                <p class="gid-section-info">
                    Prodotti trovati: <strong><?php echo count($products_data); ?></strong>.
                    Valori calcolati con i filtri selezionati.
                </p>

                <div class="gid-table-responsive">
                    <table class="gid-details-table">
                        <thead>
                            <tr>
                                <th>Prodotto</th>
                                <th class="gid-text-right">Quantità totale</th>
                                <th class="gid-text-right">Numero ordini</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($products_data)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center;">Nessun prodotto trovato per i filtri selezionati.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products_data as $row): ?>
                                <tr>
                                    <td><?php echo esc_html($row['product_name']); ?></td>
                                    <td class="gid-text-right"><?php echo (int)$row['total_quantity']; ?></td>
                                    <td class="gid-text-right"><?php echo (int)$row['orders_count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SEZIONE 3: ORDINI INTERI -->
            <div class="gid-details-section">
                <h2>Ordini interi</h2>
                <p class="gid-section-info">
                    Ordini trovati: <strong><?php echo count($orders_data); ?></strong>
                    (limite massimo: <?php echo $limit_orders; ?>).
                    Ogni riga = <strong>un ordine completo</strong>, con elenco dei prodotti ordinati.
                </p>

                <div class="gid-table-responsive">
                    <table class="gid-details-table">
                        <thead>
                            <tr>
                                <th>ID Ordine</th>
                                <th>Data/Ora</th>
                                <th>Store</th>
                                <th>Metodo pagamento</th>
                                <th class="gid-text-right">Prezzo prodotti</th>
                                <th class="gid-text-right">Totale addebitato</th>
                                <th>Prodotti (quantità x nome)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($orders_data)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center;">Nessun ordine trovato per i filtri selezionati.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orders_data as $row): ?>
                                <tr>
                                    <td><?php echo (int)$row['id']; ?></td>
                                    <td><?php echo esc_html($row['notification_partner_time']); ?></td>
                                    <td><?php echo esc_html($row['store_name']); ?></td>
                                    <td><?php echo esc_html($row['payment_method']); ?></td>
                                    <td class="gid-text-right">
                                        <?php echo $row['price_of_products'] !== null ? number_format($row['price_of_products'], 2, ',', '.') . ' €' : ''; ?>
                                    </td>
                                    <td class="gid-text-right">
                                        <?php echo $row['total_charged_to_partner'] !== null ? number_format($row['total_charged_to_partner'], 2, ',', '.') . ' €' : ''; ?>
                                    </td>
                                    <td><?php echo esc_html($row['items']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Inizializza Select2 SOLO per il filtro Prodotto
                $('#details-filter-product').select2({
                    placeholder: '-- Cerca un prodotto --',
                    allowClear: true,
                    width: '100%',
                    language: {
                        noResults: function() {
                            return "Nessun prodotto trovato";
                        },
                        searching: function() {
                            return "Ricerca in corso...";
                        },
                        inputTooShort: function() {
                            return "Digita per cercare";
                        }
                    }
                });

                // Gestione form filtri
                $('#gid-details-filter-form').on('submit', function(e) {
                    e.preventDefault();

                    const filters = {
                        date_from: $('#details-filter-date-from').val(),
                        date_to: $('#details-filter-date-to').val(),
                        product: $('#details-filter-product').val(),
                        store: $('#details-filter-store').val(),
                        payment: $('#details-filter-payment').val(),
                        hour_from: $('#details-filter-hour-from').val(),
                        hour_to: $('#details-filter-hour-to').val()
                    };

                    // Costruisci URL con parametri
                    const params = new URLSearchParams(filters);
                    window.location.search = params.toString();
                });

                // Reset filtri
                $('#gid-details-reset-filters').on('click', function() {
                    window.location.href = window.location.pathname;
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Ottieni le opzioni per i filtri
     */
    private function get_filter_options() {
        $conn = $this->get_connection();
        if (!$conn) {
            return array('products' => array(), 'stores' => array(), 'payments' => array());
        }

        $options = array(
            'products' => array(),
            'stores' => array(),
            'payments' => array()
        );

        // Ottieni prodotti unici
        $query = "SELECT DISTINCT product_name
                  FROM gsr_glovo_dettagli_items
                  WHERE product_name IS NOT NULL AND product_name <> ''
                  ORDER BY product_name ASC
                  LIMIT 500";
        $result = $conn->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $options['products'][] = $row['product_name'];
            }
            $result->close();
        }

        // Ottieni store unici (solo negozi con items associati)
        $query = "SELECT DISTINCT d.store_name
                  FROM gsr_glovo_dettagli d
                  INNER JOIN gsr_glovo_dettagli_items i ON i.dettaglio_id = d.id
                  WHERE d.store_name IS NOT NULL AND d.store_name <> ''
                  ORDER BY d.store_name ASC";
        $result = $conn->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $options['stores'][] = $row['store_name'];
            }
            $result->close();
        }

        // Ottieni metodi pagamento unici (solo per ordini con items associati)
        $query = "SELECT DISTINCT d.payment_method
                  FROM gsr_glovo_dettagli d
                  INNER JOIN gsr_glovo_dettagli_items i ON i.dettaglio_id = d.id
                  WHERE d.payment_method IS NOT NULL AND d.payment_method <> ''
                  ORDER BY d.payment_method ASC";
        $result = $conn->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $options['payments'][] = $row['payment_method'];
            }
            $result->close();
        }

        return $options;
    }

    /**
     * Ottieni dati dettaglio
     */
    private function get_details_data($filters, $limit) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array();
        }

        $where_clauses = array();
        $params = array();
        $types = '';

        if (!empty($filters['date_from'])) {
            $where_clauses[] = "d.notification_partner_time >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $where_clauses[] = "d.notification_partner_time <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        if (!empty($filters['product'])) {
            $where_clauses[] = "i.product_name = ?";
            $params[] = $filters['product'];
            $types .= 's';
        }
        if (!empty($filters['store'])) {
            $where_clauses[] = "TRIM(d.store_name) = TRIM(?)";
            $params[] = $filters['store'];
            $types .= 's';
        }
        if (!empty($filters['payment'])) {
            $where_clauses[] = "d.payment_method = ?";
            $params[] = $filters['payment'];
            $types .= 's';
        }
        if (!empty($filters['hour_from']) && is_numeric($filters['hour_from'])) {
            $where_clauses[] = "HOUR(d.notification_partner_time) >= ?";
            $params[] = (int)$filters['hour_from'];
            $types .= 'i';
        }
        if (!empty($filters['hour_to']) && is_numeric($filters['hour_to'])) {
            $where_clauses[] = "HOUR(d.notification_partner_time) <= ?";
            $params[] = (int)$filters['hour_to'];
            $types .= 'i';
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT
                    d.notification_partner_time,
                    COALESCE(NULLIF(d.store_name, ''), 'Non specificato') AS store_name,
                    d.payment_method,
                    i.product_name,
                    i.quantity,
                    d.price_of_products,
                    d.total_charged_to_partner
                  FROM gsr_glovo_dettagli d
                  INNER JOIN gsr_glovo_dettagli_items i ON i.dettaglio_id = d.id
                  $where_sql
                  ORDER BY d.notification_partner_time DESC
                  LIMIT ?";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log('GID Query Preparation Error: ' . $conn->error);
            return array();
        }

        // Aggiungi limit ai parametri
        $params[] = $limit;
        $types .= 'i';

        // Bind dei parametri
        if (!empty($params)) {
            $refs = array();
            $refs[] = $types;
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $data = array();
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $stmt->close();

        return $data;
    }

    /**
     * Ottieni aggregato per prodotto
     */
    private function get_products_aggregate($filters) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array();
        }

        $where_clauses = array();
        $params = array();
        $types = '';

        if (!empty($filters['date_from'])) {
            $where_clauses[] = "d.notification_partner_time >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $where_clauses[] = "d.notification_partner_time <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        if (!empty($filters['product'])) {
            $where_clauses[] = "i.product_name = ?";
            $params[] = $filters['product'];
            $types .= 's';
        }
        if (!empty($filters['store'])) {
            $where_clauses[] = "TRIM(d.store_name) = TRIM(?)";
            $params[] = $filters['store'];
            $types .= 's';
        }
        if (!empty($filters['payment'])) {
            $where_clauses[] = "d.payment_method = ?";
            $params[] = $filters['payment'];
            $types .= 's';
        }
        if (!empty($filters['hour_from']) && is_numeric($filters['hour_from'])) {
            $where_clauses[] = "HOUR(d.notification_partner_time) >= ?";
            $params[] = (int)$filters['hour_from'];
            $types .= 'i';
        }
        if (!empty($filters['hour_to']) && is_numeric($filters['hour_to'])) {
            $where_clauses[] = "HOUR(d.notification_partner_time) <= ?";
            $params[] = (int)$filters['hour_to'];
            $types .= 'i';
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT
                    i.product_name,
                    SUM(i.quantity) AS total_quantity,
                    COUNT(DISTINCT d.id) AS orders_count
                  FROM gsr_glovo_dettagli d
                  INNER JOIN gsr_glovo_dettagli_items i ON i.dettaglio_id = d.id
                  $where_sql
                  GROUP BY i.product_name
                  ORDER BY total_quantity DESC, i.product_name ASC";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log('GID Query Preparation Error: ' . $conn->error);
            return array();
        }

        // Bind dei parametri se presenti
        if (!empty($params)) {
            $refs = array();
            $refs[] = $types;
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $data = array();
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $stmt->close();

        return $data;
    }

    /**
     * Ottieni dati ordini
     */
    private function get_orders_data($filters, $limit) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array();
        }

        $where_clauses = array();
        $params = array();
        $types = '';

        if (!empty($filters['date_from'])) {
            $where_clauses[] = "d.notification_partner_time >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $where_clauses[] = "d.notification_partner_time <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        if (!empty($filters['product'])) {
            $where_clauses[] = "i.product_name = ?";
            $params[] = $filters['product'];
            $types .= 's';
        }
        if (!empty($filters['store'])) {
            $where_clauses[] = "TRIM(d.store_name) = TRIM(?)";
            $params[] = $filters['store'];
            $types .= 's';
        }
        if (!empty($filters['payment'])) {
            $where_clauses[] = "d.payment_method = ?";
            $params[] = $filters['payment'];
            $types .= 's';
        }
        if (!empty($filters['hour_from']) && is_numeric($filters['hour_from'])) {
            $where_clauses[] = "HOUR(d.notification_partner_time) >= ?";
            $params[] = (int)$filters['hour_from'];
            $types .= 'i';
        }
        if (!empty($filters['hour_to']) && is_numeric($filters['hour_to'])) {
            $where_clauses[] = "HOUR(d.notification_partner_time) <= ?";
            $params[] = (int)$filters['hour_to'];
            $types .= 'i';
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT
                    d.id,
                    d.notification_partner_time,
                    COALESCE(NULLIF(d.store_name, ''), 'Non specificato') AS store_name,
                    d.payment_method,
                    d.price_of_products,
                    d.total_charged_to_partner,
                    GROUP_CONCAT(
                        CONCAT(i.quantity, ' x ', i.product_name)
                        ORDER BY i.product_name SEPARATOR ' | '
                    ) AS items
                  FROM gsr_glovo_dettagli d
                  INNER JOIN gsr_glovo_dettagli_items i ON i.dettaglio_id = d.id
                  $where_sql
                  GROUP BY d.id
                  ORDER BY d.notification_partner_time DESC
                  LIMIT ?";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log('GID Query Preparation Error: ' . $conn->error);
            return array();
        }

        // Aggiungi limit ai parametri
        $params[] = $limit;
        $types .= 'i';

        // Bind dei parametri
        if (!empty($params)) {
            $refs = array();
            $refs[] = $types;
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $data = array();
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $stmt->close();

        return $data;
    }
}
