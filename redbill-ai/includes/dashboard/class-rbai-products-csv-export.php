<?php
/**
 * Shortcode [glovo_products_csv_export] — export CSV prodotti, tenant-aware.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Products_CSV_Export {

    public function __construct() {
        add_action('init', [$this, 'handle_csv_download']);
    }

    public function handle_csv_download(): void {
        if (!isset($_GET['rbai_products_csv'])) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'rbai_products_csv')) {
            wp_die('Richiesta non autorizzata.');
        }

        $tenant = RBAI_Tenant::current();
        if (!$tenant || !$tenant->is_active()) {
            wp_die('Accesso non autorizzato.');
        }

        $db_config = $tenant->get_db_config();
        $conn      = new mysqli($db_config['db_host'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name']);
        if ($conn->connect_errno) {
            wp_die('Errore connessione DB.');
        }
        $conn->set_charset('utf8mb4');

        // Verifica se la tabella items esiste
        $r = $conn->query("SHOW TABLES LIKE 'gsr_glovo_dettagli_items'");
        if (!$r || $r->num_rows === 0) {
            $conn->close();
            wp_die('Tabella prodotti non disponibile.');
        }

        $date_from = sanitize_text_field($_GET['csv_from'] ?? date('Y-01-01'));
        $date_to   = sanitize_text_field($_GET['csv_to']   ?? date('Y-m-d'));

        $stmt = $conn->prepare(
            "SELECT i.item_name, i.quantity, i.unit_price, i.total_price,
                    d.store_name, d.activation_time, d.status
             FROM gsr_glovo_dettagli_items i
             JOIN gsr_glovo_dettagli d ON i.order_id = d.order_id
             WHERE d.activation_time >= ? AND d.activation_time <= ?
             ORDER BY d.activation_time DESC, i.item_name ASC"
        );

        if (!$stmt) {
            $conn->close();
            wp_die('Errore query.');
        }

        $stmt->bind_param('ss', $date_from, $date_to);
        $stmt->execute();
        $result = $stmt->get_result();

        $filename = 'prodotti-glovo-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');

        $fh = fopen('php://output', 'w');
        fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8

        fputcsv($fh, ['Prodotto', 'Quantità', 'Prezzo Unitario', 'Totale', 'Negozio', 'Data Ordine', 'Status'], ';');

        while ($row = $result->fetch_assoc()) {
            fputcsv($fh, [
                $row['item_name'],
                $row['quantity'],
                str_replace('.', ',', $row['unit_price']),
                str_replace('.', ',', $row['total_price']),
                $row['store_name'],
                $row['activation_time'],
                $row['status'],
            ], ';');
        }

        fclose($fh);
        $stmt->close();
        $conn->close();
        exit;
    }

    public function shortcode_export_button($atts): string {
        $tenant = RBAI_Tenant::current();
        if (!$tenant) {
            return '<p>' . esc_html__('Effettua il login per esportare i prodotti.', 'redbill-ai') . '</p>';
        }
        if (!$tenant->is_active()) {
            return '<p>' . esc_html__('Account sospeso o in attesa di approvazione.', 'redbill-ai') . '</p>';
        }

        $date_from = date('Y-01-01');
        $date_to   = date('Y-m-d');

        $export_url = add_query_arg([
            'rbai_products_csv' => '1',
            'csv_from'          => $date_from,
            'csv_to'            => $date_to,
            '_wpnonce'          => wp_create_nonce('rbai_products_csv'),
        ]);

        ob_start();
        ?>
        <div class="gid-csv-export-wrapper">
            <h3><?php esc_html_e('Esporta Prodotti CSV', 'redbill-ai'); ?></h3>
            <form method="get" id="rbai-products-csv-form">
                <input type="hidden" name="rbai_products_csv" value="1">
                <?php wp_nonce_field('rbai_products_csv', '_wpnonce'); ?>
                <div class="gid-filter-row">
                    <div class="gid-filter-group">
                        <label><?php esc_html_e('Dal', 'redbill-ai'); ?></label>
                        <input type="date" name="csv_from" value="<?php echo esc_attr($date_from); ?>">
                    </div>
                    <div class="gid-filter-group">
                        <label><?php esc_html_e('Al', 'redbill-ai'); ?></label>
                        <input type="date" name="csv_to" value="<?php echo esc_attr($date_to); ?>">
                    </div>
                </div>
                <button type="submit" class="gid-btn gid-btn-success">
                    <?php esc_html_e('Scarica CSV Prodotti', 'redbill-ai'); ?>
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
