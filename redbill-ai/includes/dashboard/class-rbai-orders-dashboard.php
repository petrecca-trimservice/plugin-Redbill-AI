<?php
/**
 * Shortcode [glovo_orders_dashboard] — dashboard ordini, tenant-aware.
 * Legge dalla tabella gsr_glovo_dettagli del DB tenant.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Orders_Dashboard {

    public static function render($atts): string {
        $tenant = RBAI_Tenant::current();
        if (!$tenant) {
            return '<p>' . esc_html__('Effettua il login per accedere agli ordini.', 'redbill-ai') . '</p>';
        }
        if (!$tenant->is_active()) {
            return '<p>' . esc_html__('Account sospeso o in attesa di approvazione.', 'redbill-ai') . '</p>';
        }

        $db_config = $tenant->get_db_config();
        $conn      = self::get_connection($db_config);

        if (!$conn) {
            return '<p>' . esc_html__('Impossibile connettersi al database degli ordini.', 'redbill-ai') . '</p>';
        }

        $date_from = sanitize_text_field($_GET['order_from'] ?? date('Y-m-01', strtotime('-3 months')));
        $date_to   = sanitize_text_field($_GET['order_to']   ?? date('Y-m-d'));

        // Statistiche ordini
        $stats = self::get_order_stats($conn, $date_from, $date_to);

        ob_start();
        ?>
        <div class="gid-orders-dashboard-wrapper">
            <h2><?php esc_html_e('Dashboard Ordini', 'redbill-ai'); ?></h2>

            <form method="get" class="gid-filters">
                <div class="gid-filter-row">
                    <div class="gid-filter-group">
                        <label><?php esc_html_e('Dal', 'redbill-ai'); ?></label>
                        <input type="date" name="order_from" value="<?php echo esc_attr($date_from); ?>">
                    </div>
                    <div class="gid-filter-group">
                        <label><?php esc_html_e('Al', 'redbill-ai'); ?></label>
                        <input type="date" name="order_to" value="<?php echo esc_attr($date_to); ?>">
                    </div>
                </div>
                <div class="gid-filter-actions">
                    <button type="submit" class="gid-btn gid-btn-primary"><?php esc_html_e('Applica', 'redbill-ai'); ?></button>
                    <a href="?" class="gid-btn gid-btn-secondary"><?php esc_html_e('Reset', 'redbill-ai'); ?></a>
                </div>
            </form>

            <!-- KPI ordini -->
            <div class="gid-kpi-grid" style="margin-top:20px;">
                <?php foreach ($stats['kpi'] as [$label, $value]): ?>
                <div class="gid-kpi-card">
                    <div class="gid-kpi-label"><?php echo esc_html($label); ?></div>
                    <div class="gid-kpi-value"><?php echo esc_html($value); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Ordini per negozio -->
            <?php if (!empty($stats['per_store'])): ?>
            <h3 style="margin-top:28px;"><?php esc_html_e('Ordini per Negozio', 'redbill-ai'); ?></h3>
            <div class="gid-table-responsive">
                <table class="gid-invoice-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Negozio', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('N° Ordini', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Totale €', 'redbill-ai'); ?></th>
                            <th><?php esc_html_e('Media Ordine €', 'redbill-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['per_store'] as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['store_name'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['n_ordini']); ?></td>
                            <td class="gid-currency"><?php echo number_format((float)$row['totale'], 2, ',', '.'); ?> €</td>
                            <td class="gid-currency"><?php echo number_format((float)$row['media'], 2, ',', '.'); ?> €</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
        $conn->close();
        return ob_get_clean();
    }

    private static function get_connection(array $db_config): mysqli|false {
        $conn = new mysqli(
            $db_config['db_host'],
            $db_config['db_user'],
            $db_config['db_pass'],
            $db_config['db_name']
        );
        if ($conn->connect_errno) {
            return false;
        }
        $conn->set_charset('utf8mb4');
        return $conn;
    }

    private static function get_order_stats(mysqli $conn, string $from, string $to): array {
        $stats = ['kpi' => [], 'per_store' => []];

        // Controlla se la tabella esiste
        $r = $conn->query("SHOW TABLES LIKE 'gsr_glovo_dettagli'");
        if (!$r || $r->num_rows === 0) {
            return $stats;
        }

        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS n, SUM(total_amount) AS totale, AVG(total_amount) AS media
             FROM gsr_glovo_dettagli
             WHERE activation_time >= ? AND activation_time <= ?"
        );
        if ($stmt) {
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stats['kpi'] = [
                [__('Ordini Totali',       'redbill-ai'), number_format((int)($row['n']      ?? 0), 0, ',', '.')],
                [__('Fatturato Totale €',  'redbill-ai'), '€ ' . number_format((float)($row['totale'] ?? 0), 2, ',', '.')],
                [__('Media per Ordine €',  'redbill-ai'), '€ ' . number_format((float)($row['media']  ?? 0), 2, ',', '.')],
            ];
        }

        $stmt2 = $conn->prepare(
            "SELECT store_name, COUNT(*) AS n_ordini, SUM(total_amount) AS totale, AVG(total_amount) AS media
             FROM gsr_glovo_dettagli
             WHERE activation_time >= ? AND activation_time <= ?
             GROUP BY store_name ORDER BY totale DESC"
        );
        if ($stmt2) {
            $stmt2->bind_param('ss', $from, $to);
            $stmt2->execute();
            $result = $stmt2->get_result();
            while ($row = $result->fetch_assoc()) {
                $stats['per_store'][] = $row;
            }
            $stmt2->close();
        }

        return $stats;
    }
}
