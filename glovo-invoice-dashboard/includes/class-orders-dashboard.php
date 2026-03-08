<?php
/**
 * Dashboard Ordini Glovo - Analisi dettagliata ordini
 * Utilizza mysqli per connettersi al database dash_glovo
 */

if (!defined('ABSPATH')) {
    exit;
}

class GID_Orders_Dashboard {

    private $db_config;
    private $connection;

    public function __construct() {
        $this->db_config = $this->load_config();
        $this->connection = null;
    }

    /**
     * Carica la configurazione del database
     */
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
                if (is_array($config) && isset($config['db_name'])) {
                    return array_merge(
                        array('db_charset' => 'utf8mb4'),
                        $config
                    );
                }
            }
        }

        if (file_exists(GID_PLUGIN_DIR . 'config-db.php')) {
            return require GID_PLUGIN_DIR . 'config-db.php';
        }

        wp_die('Glovo Invoice Dashboard: File di configurazione database non trovato.');
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

    public function __destruct() {
        $this->close_connection();
    }

    /**
     * Renderizza lo shortcode per la dashboard ordini
     */
    public static function render($atts) {
        $dashboard = new self();

        // Leggi i filtri dai parametri GET
        $filters = array(
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '',
            'store' => isset($_GET['store']) ? stripslashes(sanitize_text_field($_GET['store'])) : '',
            'payment' => isset($_GET['payment']) ? stripslashes(sanitize_text_field($_GET['payment'])) : '',
        );

        // Ottieni i dati
        $kpi_data = $dashboard->get_kpi_data($filters);
        $filter_options = $dashboard->get_filter_options();
        $top_stores = $dashboard->get_top_stores($filters, 10);
        $top_products = $dashboard->get_top_products($filters, 10);
        $hourly_distribution = $dashboard->get_hourly_distribution($filters);
        $daily_products = $dashboard->get_daily_products_value($filters);
        $weekly_products = $dashboard->get_weekly_products_value($filters);
        $monthly_products = $dashboard->get_monthly_products_value($filters);
        $week_of_month_products = $dashboard->get_week_of_month_products_value($filters);
        $stores_vs_average = $dashboard->get_stores_vs_average($filters);

        ob_start();
        ?>
        <div class="gid-dashboard-wrapper gid-orders-dashboard">
            <!-- Filtri -->
            <div class="gid-filters">
                <h3>Filtri Analisi</h3>
                <form id="gid-orders-filter-form">
                    <div class="gid-filter-row">
                        <div class="gid-filter-group">
                            <label for="orders-filter-date-from">Data da</label>
                            <input type="date" id="orders-filter-date-from" name="date_from"
                                   value="<?php echo esc_attr($filters['date_from']); ?>">
                        </div>

                        <div class="gid-filter-group">
                            <label for="orders-filter-date-to">Data a</label>
                            <input type="date" id="orders-filter-date-to" name="date_to"
                                   value="<?php echo esc_attr($filters['date_to']); ?>">
                        </div>

                        <div class="gid-filter-group">
                            <label for="orders-filter-store">Store</label>
                            <select id="orders-filter-store" name="store">
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
                            <label for="orders-filter-payment">Metodo pagamento</label>
                            <select id="orders-filter-payment" name="payment">
                                <option value="">-- Tutti --</option>
                                <?php foreach ($filter_options['payments'] as $payment): ?>
                                    <option value="<?php echo esc_attr($payment); ?>"
                                            <?php selected($filters['payment'], $payment); ?>>
                                        <?php echo esc_html($payment); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="gid-filter-actions">
                        <button type="submit" class="gid-btn gid-btn-primary">Applica Filtri</button>
                        <button type="button" class="gid-btn gid-btn-secondary" id="gid-orders-reset-filters">Reset</button>
                    </div>
                </form>

                <?php
                $filters_active = array_filter($filters);
                if (!empty($filters_active)):
                ?>
                    <div class="gid-filters-active">
                        <strong>Filtri attivi:</strong>
                        <?php
                        $filter_labels = array(
                            'date_from' => 'Data da',
                            'date_to' => 'Data a',
                            'store' => 'Store',
                            'payment' => 'Metodo pagamento'
                        );
                        foreach ($filters_active as $key => $value) {
                            $label = isset($filter_labels[$key]) ? $filter_labels[$key] : $key;
                            echo '<span class="gid-filter-tag">' . esc_html($label) . ': ' . esc_html($value) . '</span> ';
                        }
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- KPI Cards -->
            <div class="gid-kpi-container">
                <div class="gid-kpi-card gid-kpi-primary">
                    <div class="gid-kpi-icon">📦</div>
                    <div class="gid-kpi-content">
                        <h4>Totale Ordini</h4>
                        <p class="gid-kpi-value"><?php echo number_format($kpi_data['total_orders'], 0, ',', '.'); ?></p>
                        <span class="gid-kpi-label">Ordini totali</span>
                    </div>
                </div>

                <div class="gid-kpi-card gid-kpi-info">
                    <div class="gid-kpi-icon">🛍️</div>
                    <div class="gid-kpi-content">
                        <h4>Valore Prodotti Lordo</h4>
                        <p class="gid-kpi-value"><?php echo number_format($kpi_data['total_products_value'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Valore totale prodotti lordo</span>
                    </div>
                </div>

                <div class="gid-kpi-card gid-kpi-warning">
                    <div class="gid-kpi-icon">📊</div>
                    <div class="gid-kpi-content">
                        <h4>Prodotti Venduti</h4>
                        <p class="gid-kpi-value"><?php echo number_format($kpi_data['total_products_qty'], 0, ',', '.'); ?></p>
                        <span class="gid-kpi-label">Quantità totale</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">💳</div>
                    <div class="gid-kpi-content">
                        <h4>Valore Medio Ordine</h4>
                        <p class="gid-kpi-value"><?php echo number_format($kpi_data['avg_order_value'], 2, ',', '.'); ?> €</p>
                        <span class="gid-kpi-label">Media valore prodotti</span>
                    </div>
                </div>

                <div class="gid-kpi-card">
                    <div class="gid-kpi-icon">🔢</div>
                    <div class="gid-kpi-content">
                        <h4>Prodotti per Ordine</h4>
                        <p class="gid-kpi-value"><?php echo number_format($kpi_data['avg_products_per_order'], 1, ',', '.'); ?></p>
                        <span class="gid-kpi-label">Media prodotti</span>
                    </div>
                </div>
            </div>

            <!-- Grafici -->
            <div class="gid-charts-section">
                <h2>Analisi Grafica</h2>

                <div class="gid-chart-grid">
                    <!-- Top negozi -->
                    <div class="gid-chart-container">
                        <h3>Top 10 Negozi per Valore Prodotti Lordo</h3>
                        <canvas id="gid-chart-top-stores"></canvas>
                    </div>

                    <!-- Top prodotti -->
                    <div class="gid-chart-container">
                        <h3>Top 10 Prodotti Più Venduti</h3>
                        <canvas id="gid-chart-top-products"></canvas>
                    </div>

                    <!-- Vendite giornaliere -->
                    <div class="gid-chart-container gid-chart-wide">
                        <h3>Vendite Giornaliere</h3>
                        <canvas id="gid-chart-daily-products"></canvas>
                    </div>

                    <!-- Vendite mensili -->
                    <div class="gid-chart-container gid-chart-wide">
                        <h3>Vendite Mensili</h3>
                        <canvas id="gid-chart-monthly-products"></canvas>
                    </div>

                    <!-- Vendite per settimana del mese -->
                    <div class="gid-chart-container">
                        <h3>Vendite per Settimana del Mese</h3>
                        <canvas id="gid-chart-week-of-month-products"></canvas>
                    </div>

                    <!-- Vendite per negozio vs media -->
                    <div class="gid-chart-container gid-chart-wide">
                        <h3>Vendite per Negozio vs Media Generale</h3>
                        <canvas id="gid-chart-stores-vs-average"></canvas>
                    </div>

                    <!-- Valore prodotti per giorno della settimana -->
                    <div class="gid-chart-container">
                        <h3>Valore Prodotti Lordo per Giorno Settimana</h3>
                        <canvas id="gid-chart-weekly-products"></canvas>
                    </div>

                    <!-- Distribuzione oraria -->
                    <div class="gid-chart-container">
                        <h3>Distribuzione Ordini per Fascia Oraria</h3>
                        <canvas id="gid-chart-hourly-distribution"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Gestione form filtri
            $('#gid-orders-filter-form').on('submit', function(e) {
                e.preventDefault();
                const filters = {
                    date_from: $('#orders-filter-date-from').val(),
                    date_to: $('#orders-filter-date-to').val(),
                    store: $('#orders-filter-store').val(),
                    payment: $('#orders-filter-payment').val()
                };
                const params = new URLSearchParams(filters);
                window.location.search = params.toString();
            });

            $('#gid-orders-reset-filters').on('click', function() {
                window.location.href = window.location.pathname;
            });

            // Dati per i grafici
            const topStoresData = <?php echo json_encode($top_stores); ?>;
            const topProductsData = <?php echo json_encode($top_products); ?>;
            const hourlyData = <?php echo json_encode($hourly_distribution); ?>;
            const dailyProductsData = <?php echo json_encode($daily_products); ?>;
            const weeklyProductsData = <?php echo json_encode($weekly_products); ?>;
            const monthlyProductsData = <?php echo json_encode($monthly_products); ?>;
            const weekOfMonthProductsData = <?php echo json_encode($week_of_month_products); ?>;
            const storesVsAverageData = <?php echo json_encode($stores_vs_average); ?>;

            // Grafico top negozi (Bar orizzontale)
            if (topStoresData.labels.length > 0) {
                new Chart(document.getElementById('gid-chart-top-stores'), {
                    type: 'bar',
                    data: {
                        labels: topStoresData.labels,
                        datasets: [{
                            label: 'Valore Prodotti Lordo (€)',
                            data: topStoresData.values,
                            backgroundColor: '#FF6384'
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return value.toFixed(2) + ' €';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Grafico top prodotti (Bar orizzontale)
            if (topProductsData.labels.length > 0) {
                new Chart(document.getElementById('gid-chart-top-products'), {
                    type: 'bar',
                    data: {
                        labels: topProductsData.labels,
                        datasets: [{
                            label: 'Quantità Venduta',
                            data: topProductsData.values,
                            backgroundColor: '#36A2EB'
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            x: { beginAtZero: true }
                        }
                    }
                });
            }

            // Grafico distribuzione oraria (Line)
            if (hourlyData.labels.length > 0) {
                new Chart(document.getElementById('gid-chart-hourly-distribution'), {
                    type: 'line',
                    data: {
                        labels: hourlyData.labels,
                        datasets: [{
                            label: 'Numero Ordini',
                            data: hourlyData.values,
                            borderColor: '#4BC0C0',
                            backgroundColor: 'rgba(75, 192, 192, 0.2)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            }

            // Grafico valore prodotti per giorno settimana (Bar)
            if (weeklyProductsData.labels.length > 0) {
                new Chart(document.getElementById('gid-chart-weekly-products'), {
                    type: 'bar',
                    data: {
                        labels: weeklyProductsData.labels,
                        datasets: [{
                            label: 'Valore Prodotti Lordo (€)',
                            data: weeklyProductsData.values,
                            backgroundColor: '#FF9F40'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return value.toFixed(2) + ' €';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Grafico vendite giornaliere (Line)
            if (dailyProductsData.labels.length > 0) {
                new Chart(document.getElementById('gid-chart-daily-products'), {
                    type: 'line',
                    data: {
                        labels: dailyProductsData.labels,
                        datasets: [{
                            label: 'Valore Prodotti Lordo (€)',
                            data: dailyProductsData.values,
                            borderColor: '#9966FF',
                            backgroundColor: 'rgba(153, 102, 255, 0.2)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return value.toFixed(2) + ' €';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Grafico vendite mensili (Bar)
            if (monthlyProductsData.labels.length > 0) {
                new Chart(document.getElementById('gid-chart-monthly-products'), {
                    type: 'bar',
                    data: {
                        labels: monthlyProductsData.labels,
                        datasets: [{
                            label: 'Valore Prodotti Lordo (€)',
                            data: monthlyProductsData.values,
                            backgroundColor: '#00A082',
                            borderColor: '#008060',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return value.toFixed(2) + ' €';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Grafico vendite per settimana del mese (Bar)
            if (weekOfMonthProductsData.labels.length > 0) {
                new Chart(document.getElementById('gid-chart-week-of-month-products'), {
                    type: 'bar',
                    data: {
                        labels: weekOfMonthProductsData.labels,
                        datasets: [{
                            label: 'Valore Prodotti Lordo (€)',
                            data: weekOfMonthProductsData.values,
                            backgroundColor: '#FFCE56',
                            borderColor: '#FFB700',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return value.toFixed(2) + ' €';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Grafico vendite per negozio vs media (Bar con linea media)
            if (storesVsAverageData.labels.length > 0) {
                // Crea array della media per ogni negozio
                const averageArray = new Array(storesVsAverageData.labels.length).fill(storesVsAverageData.average);

                new Chart(document.getElementById('gid-chart-stores-vs-average'), {
                    type: 'bar',
                    data: {
                        labels: storesVsAverageData.labels,
                        datasets: [
                            {
                                label: 'Vendite Negozio (€)',
                                data: storesVsAverageData.values,
                                backgroundColor: function(context) {
                                    // Colora in verde se sopra la media, rosso se sotto
                                    const value = context.parsed.y;
                                    return value >= storesVsAverageData.average ? '#00A082' : '#FF6384';
                                },
                                borderColor: function(context) {
                                    const value = context.parsed.y;
                                    return value >= storesVsAverageData.average ? '#008060' : '#FF4560';
                                },
                                borderWidth: 1
                            },
                            {
                                label: 'Media Generale (€)',
                                data: averageArray,
                                type: 'line',
                                borderColor: '#FFA500',
                                borderWidth: 3,
                                borderDash: [10, 5],
                                fill: false,
                                pointRadius: 0,
                                pointHoverRadius: 0
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        label += context.parsed.y.toFixed(2) + ' €';
                                        return label;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return value.toFixed(2) + ' €';
                                    }
                                }
                            },
                            x: {
                                ticks: {
                                    maxRotation: 45,
                                    minRotation: 45
                                }
                            }
                        }
                    }
                });
            }
        });
        </script>

        <style>
        .gid-orders-dashboard .gid-charts-section {
            margin-top: 40px;
        }

        .gid-orders-dashboard .gid-chart-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin-top: 20px;
        }

        .gid-orders-dashboard .gid-chart-container {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .gid-orders-dashboard .gid-chart-container h3 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 16px;
            color: #333;
        }

        .gid-orders-dashboard .gid-chart-container canvas {
            max-height: 300px;
        }

        .gid-orders-dashboard .gid-chart-wide {
            grid-column: span 2;
        }

        .gid-orders-dashboard .gid-chart-wide canvas {
            max-height: 250px;
        }

        @media (max-width: 768px) {
            .gid-orders-dashboard .gid-chart-grid {
                grid-template-columns: 1fr;
            }

            .gid-orders-dashboard .gid-chart-wide {
                grid-column: span 1;
            }
        }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Ottieni KPI principali
     */
    private function get_kpi_data($filters) {
        $conn = $this->get_connection();
        if (!$conn) {
            return $this->get_empty_kpi();
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

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Query principale per ordini e valori (senza JOIN per evitare duplicazioni)
        $query = "SELECT
                    COUNT(d.id) as total_orders,
                    SUM(d.total_charged_to_partner) as total_revenue,
                    SUM(d.price_of_products) as total_products_value
                  FROM gsr_glovo_dettagli d
                  $where_sql";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return $this->get_empty_kpi();
        }

        if (!empty($params)) {
            $refs = array($types);
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();

        $total_orders = (int)$data['total_orders'];
        $total_revenue = (float)$data['total_revenue'];
        $total_products_value = (float)$data['total_products_value'];

        // Query separata per quantità prodotti (con JOIN)
        $query2 = "SELECT SUM(i.quantity) as total_products_qty
                   FROM gsr_glovo_dettagli d
                   INNER JOIN gsr_glovo_dettagli_items i ON i.dettaglio_id = d.id
                   $where_sql";

        $stmt2 = $conn->prepare($query2);
        $total_products_qty = 0;

        if ($stmt2) {
            if (!empty($params)) {
                $refs2 = array($types);
                foreach ($params as $key => $value) {
                    $refs2[] = &$params[$key];
                }
                call_user_func_array(array($stmt2, 'bind_param'), $refs2);
            }

            $stmt2->execute();
            $result2 = $stmt2->get_result();
            $data2 = $result2->fetch_assoc();
            $stmt2->close();
            $total_products_qty = (int)$data2['total_products_qty'];
        }

        return array(
            'total_orders' => $total_orders,
            'total_revenue' => $total_revenue,
            'total_products_value' => $total_products_value,
            'total_products_qty' => $total_products_qty,
            'avg_order_value' => $total_orders > 0 ? $total_products_value / $total_orders : 0,
            'avg_products_per_order' => $total_orders > 0 ? $total_products_qty / $total_orders : 0
        );
    }

    private function get_empty_kpi() {
        return array(
            'total_orders' => 0,
            'total_revenue' => 0,
            'total_products_value' => 0,
            'total_products_qty' => 0,
            'avg_order_value' => 0,
            'avg_products_per_order' => 0
        );
    }

    /**
     * Ottieni opzioni per i filtri
     */
    private function get_filter_options() {
        $conn = $this->get_connection();
        if (!$conn) {
            return array('stores' => array(), 'payments' => array());
        }

        $options = array('stores' => array(), 'payments' => array());

        // Ottieni store unici (solo negozi con items associati)
        $result = $conn->query("SELECT DISTINCT d.store_name FROM gsr_glovo_dettagli d INNER JOIN gsr_glovo_dettagli_items i ON i.dettaglio_id = d.id WHERE d.store_name IS NOT NULL AND d.store_name <> '' ORDER BY d.store_name");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $options['stores'][] = $row['store_name'];
            }
            $result->close();
        }

        // Ottieni metodi pagamento unici (solo per ordini con items associati)
        $result = $conn->query("SELECT DISTINCT d.payment_method FROM gsr_glovo_dettagli d INNER JOIN gsr_glovo_dettagli_items i ON i.dettaglio_id = d.id WHERE d.payment_method IS NOT NULL AND d.payment_method <> '' ORDER BY d.payment_method");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $options['payments'][] = $row['payment_method'];
            }
            $result->close();
        }

        return $options;
    }

    /**
     * Distribuzione metodi di pagamento
     */
    private function get_payment_method_distribution($filters) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array('labels' => array(), 'values' => array());
        }

        $where_clauses = array();
        $params = array();
        $types = '';

        if (!empty($filters['date_from'])) {
            $where_clauses[] = "notification_partner_time >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $where_clauses[] = "notification_partner_time <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        if (!empty($filters['store'])) {
            $where_clauses[] = "TRIM(store_name) = TRIM(?)";
            $params[] = $filters['store'];
            $types .= 's';
        }
        if (!empty($filters['payment'])) {
            $where_clauses[] = "payment_method = ?";
            $params[] = $filters['payment'];
            $types .= 's';
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT payment_method, COUNT(*) as count
                  FROM gsr_glovo_dettagli
                  $where_sql
                  GROUP BY payment_method
                  ORDER BY count DESC";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return array('labels' => array(), 'values' => array());
        }

        if (!empty($params)) {
            $refs = array($types);
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $labels = array();
        $values = array();
        while ($row = $result->fetch_assoc()) {
            $labels[] = $row['payment_method'] ?: 'Non specificato';
            $values[] = (int)$row['count'];
        }
        $stmt->close();

        return array('labels' => $labels, 'values' => $values);
    }

    /**
     * Top prodotti più venduti
     */
    private function get_top_products($filters, $limit = 10) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array('labels' => array(), 'values' => array());
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

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT i.product_name, SUM(i.quantity) as total_qty
                  FROM gsr_glovo_dettagli d
                  INNER JOIN gsr_glovo_dettagli_items i ON i.dettaglio_id = d.id
                  $where_sql
                  GROUP BY i.product_name
                  ORDER BY total_qty DESC
                  LIMIT ?";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return array('labels' => array(), 'values' => array());
        }

        $params[] = $limit;
        $types .= 'i';

        if (!empty($params)) {
            $refs = array($types);
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $labels = array();
        $values = array();
        while ($row = $result->fetch_assoc()) {
            $labels[] = $row['product_name'];
            $values[] = (int)$row['total_qty'];
        }
        $stmt->close();

        return array('labels' => $labels, 'values' => $values);
    }

    /**
     * Top negozi per valore prodotti
     */
    private function get_top_stores($filters, $limit = 10) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array('labels' => array(), 'values' => array());
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

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT COALESCE(NULLIF(d.store_name, ''), 'Non specificato') AS store_name, SUM(d.price_of_products) as total_value
                  FROM gsr_glovo_dettagli d
                  $where_sql
                  GROUP BY store_name
                  ORDER BY total_value DESC
                  LIMIT ?";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return array('labels' => array(), 'values' => array());
        }

        $params[] = $limit;
        $types .= 'i';

        if (!empty($params)) {
            $refs = array($types);
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $labels = array();
        $values = array();
        while ($row = $result->fetch_assoc()) {
            $labels[] = $row['store_name'];
            $values[] = (float)$row['total_value'];
        }
        $stmt->close();

        return array('labels' => $labels, 'values' => $values);
    }

    /**
     * Distribuzione ordini per fascia oraria
     */
    private function get_hourly_distribution($filters) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array('labels' => array(), 'values' => array());
        }

        $where_clauses = array();
        $params = array();
        $types = '';

        if (!empty($filters['date_from'])) {
            $where_clauses[] = "notification_partner_time >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $where_clauses[] = "notification_partner_time <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        if (!empty($filters['store'])) {
            $where_clauses[] = "TRIM(store_name) = TRIM(?)";
            $params[] = $filters['store'];
            $types .= 's';
        }
        if (!empty($filters['payment'])) {
            $where_clauses[] = "payment_method = ?";
            $params[] = $filters['payment'];
            $types .= 's';
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT HOUR(notification_partner_time) as hour, COUNT(*) as count
                  FROM gsr_glovo_dettagli
                  $where_sql
                  GROUP BY HOUR(notification_partner_time)
                  ORDER BY hour";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return array('labels' => array(), 'values' => array());
        }

        if (!empty($params)) {
            $refs = array($types);
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $labels = array();
        $values = array();
        while ($row = $result->fetch_assoc()) {
            $labels[] = sprintf('%02d:00', $row['hour']);
            $values[] = (int)$row['count'];
        }
        $stmt->close();

        return array('labels' => $labels, 'values' => $values);
    }

    /**
     * Valore prodotti giornaliero
     */
    private function get_daily_products_value($filters) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array('labels' => array(), 'values' => array());
        }

        $where_clauses = array();
        $params = array();
        $types = '';

        if (!empty($filters['date_from'])) {
            $where_clauses[] = "notification_partner_time >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $where_clauses[] = "notification_partner_time <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        if (!empty($filters['store'])) {
            $where_clauses[] = "TRIM(store_name) = TRIM(?)";
            $params[] = $filters['store'];
            $types .= 's';
        }
        if (!empty($filters['payment'])) {
            $where_clauses[] = "payment_method = ?";
            $params[] = $filters['payment'];
            $types .= 's';
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT DATE(notification_partner_time) as date, SUM(price_of_products) as products_value
                  FROM gsr_glovo_dettagli
                  $where_sql
                  GROUP BY DATE(notification_partner_time)
                  ORDER BY date";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return array('labels' => array(), 'values' => array());
        }

        if (!empty($params)) {
            $refs = array($types);
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $labels = array();
        $values = array();
        while ($row = $result->fetch_assoc()) {
            $labels[] = date('d/m/Y', strtotime($row['date']));
            $values[] = (float)$row['products_value'];
        }
        $stmt->close();

        return array('labels' => $labels, 'values' => $values);
    }

    /**
     * Valore prodotti per giorno della settimana
     */
    private function get_weekly_products_value($filters) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array('labels' => array(), 'values' => array());
        }

        $where_clauses = array();
        $params = array();
        $types = '';

        if (!empty($filters['date_from'])) {
            $where_clauses[] = "notification_partner_time >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $where_clauses[] = "notification_partner_time <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        if (!empty($filters['store'])) {
            $where_clauses[] = "TRIM(store_name) = TRIM(?)";
            $params[] = $filters['store'];
            $types .= 's';
        }
        if (!empty($filters['payment'])) {
            $where_clauses[] = "payment_method = ?";
            $params[] = $filters['payment'];
            $types .= 's';
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT WEEKDAY(notification_partner_time) as day_num, SUM(price_of_products) as products_value
                  FROM gsr_glovo_dettagli
                  $where_sql
                  GROUP BY WEEKDAY(notification_partner_time)
                  ORDER BY day_num";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return array('labels' => array(), 'values' => array());
        }

        if (!empty($params)) {
            $refs = array($types);
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        // Nomi giorni della settimana (WEEKDAY: 0=Lunedì, 1=Martedì, ..., 6=Domenica)
        $days_map = array(
            0 => 'Lunedì',
            1 => 'Martedì',
            2 => 'Mercoledì',
            3 => 'Giovedì',
            4 => 'Venerdì',
            5 => 'Sabato',
            6 => 'Domenica'
        );

        $labels = array();
        $values = array();
        while ($row = $result->fetch_assoc()) {
            $day_num = (int)$row['day_num'];
            $labels[] = $days_map[$day_num];
            $values[] = (float)$row['products_value'];
        }
        $stmt->close();

        return array('labels' => $labels, 'values' => $values);
    }

    /**
     * Valore prodotti mensile
     */
    private function get_monthly_products_value($filters) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array('labels' => array(), 'values' => array());
        }

        $where_clauses = array();
        $params = array();
        $types = '';

        if (!empty($filters['date_from'])) {
            $where_clauses[] = "notification_partner_time >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $where_clauses[] = "notification_partner_time <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        if (!empty($filters['store'])) {
            $where_clauses[] = "TRIM(store_name) = TRIM(?)";
            $params[] = $filters['store'];
            $types .= 's';
        }
        if (!empty($filters['payment'])) {
            $where_clauses[] = "payment_method = ?";
            $params[] = $filters['payment'];
            $types .= 's';
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT DATE_FORMAT(notification_partner_time, '%Y-%m') as month, SUM(price_of_products) as products_value
                  FROM gsr_glovo_dettagli
                  $where_sql
                  GROUP BY DATE_FORMAT(notification_partner_time, '%Y-%m')
                  ORDER BY month";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return array('labels' => array(), 'values' => array());
        }

        if (!empty($params)) {
            $refs = array($types);
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $labels = array();
        $values = array();
        while ($row = $result->fetch_assoc()) {
            // Formatta il mese come "Gen 2024"
            $date = DateTime::createFromFormat('Y-m', $row['month']);
            $labels[] = $date->format('M Y');
            $values[] = (float)$row['products_value'];
        }
        $stmt->close();

        return array('labels' => $labels, 'values' => $values);
    }

    /**
     * Valore prodotti per settimana del mese (1a, 2a, 3a, 4a settimana)
     */
    private function get_week_of_month_products_value($filters) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array('labels' => array(), 'values' => array());
        }

        $where_clauses = array();
        $params = array();
        $types = '';

        if (!empty($filters['date_from'])) {
            $where_clauses[] = "notification_partner_time >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $where_clauses[] = "notification_partner_time <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        if (!empty($filters['store'])) {
            $where_clauses[] = "TRIM(store_name) = TRIM(?)";
            $params[] = $filters['store'];
            $types .= 's';
        }
        if (!empty($filters['payment'])) {
            $where_clauses[] = "payment_method = ?";
            $params[] = $filters['payment'];
            $types .= 's';
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Calcola la settimana del mese: CEIL(DAY(date) / 7)
        $query = "SELECT
                    CEIL(DAY(notification_partner_time) / 7) as week_of_month,
                    SUM(price_of_products) as products_value
                  FROM gsr_glovo_dettagli
                  $where_sql
                  GROUP BY CEIL(DAY(notification_partner_time) / 7)
                  ORDER BY week_of_month";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return array('labels' => array(), 'values' => array());
        }

        if (!empty($params)) {
            $refs = array($types);
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $week_labels = array(
            1 => '1ª Settimana',
            2 => '2ª Settimana',
            3 => '3ª Settimana',
            4 => '4ª Settimana',
            5 => '5ª Settimana'
        );

        $labels = array();
        $values = array();
        while ($row = $result->fetch_assoc()) {
            $week_num = (int)$row['week_of_month'];
            $labels[] = isset($week_labels[$week_num]) ? $week_labels[$week_num] : $week_num . 'ª Settimana';
            $values[] = (float)$row['products_value'];
        }
        $stmt->close();

        return array('labels' => $labels, 'values' => $values);
    }

    /**
     * Vendite per negozio vs media generale
     */
    private function get_stores_vs_average($filters) {
        $conn = $this->get_connection();
        if (!$conn) {
            return array('labels' => array(), 'values' => array(), 'average' => 0);
        }

        $where_clauses = array();
        $params = array();
        $types = '';

        if (!empty($filters['date_from'])) {
            $where_clauses[] = "notification_partner_time >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $where_clauses[] = "notification_partner_time <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        if (!empty($filters['store'])) {
            $where_clauses[] = "TRIM(store_name) = TRIM(?)";
            $params[] = $filters['store'];
            $types .= 's';
        }
        if (!empty($filters['payment'])) {
            $where_clauses[] = "payment_method = ?";
            $params[] = $filters['payment'];
            $types .= 's';
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Query per ottenere vendite per negozio
        $query = "SELECT COALESCE(NULLIF(store_name, ''), 'Non specificato') AS store_name,
                         SUM(price_of_products) as total_value
                  FROM gsr_glovo_dettagli
                  $where_sql
                  GROUP BY store_name
                  ORDER BY total_value DESC";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return array('labels' => array(), 'values' => array(), 'average' => 0);
        }

        if (!empty($params)) {
            $refs = array($types);
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array(array($stmt, 'bind_param'), $refs);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $labels = array();
        $values = array();
        $total = 0;
        $count = 0;

        while ($row = $result->fetch_assoc()) {
            $labels[] = $row['store_name'];
            $value = (float)$row['total_value'];
            $values[] = $value;
            $total += $value;
            $count++;
        }
        $stmt->close();

        // Calcola la media
        $average = $count > 0 ? $total / $count : 0;

        return array(
            'labels' => $labels,
            'values' => $values,
            'average' => $average
        );
    }

    /**
     * Ordini recenti
     */
    private function get_recent_orders($filters, $limit = 20) {
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

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT
                    d.id,
                    d.notification_partner_time,
                    COALESCE(NULLIF(d.store_name, ''), 'Non specificato') AS store_name,
                    d.payment_method,
                    d.price_of_products,
                    d.total_charged_to_partner,
                    COUNT(i.id) as products_count
                  FROM gsr_glovo_dettagli d
                  LEFT JOIN gsr_glovo_dettagli_items i ON i.dettaglio_id = d.id
                  $where_sql
                  GROUP BY d.id
                  ORDER BY d.notification_partner_time DESC
                  LIMIT ?";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return array();
        }

        $params[] = $limit;
        $types .= 'i';

        if (!empty($params)) {
            $refs = array($types);
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
