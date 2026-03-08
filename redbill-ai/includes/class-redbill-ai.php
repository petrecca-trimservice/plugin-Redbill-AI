<?php
/**
 * Loader principale del plugin (singleton).
 * Inizializza tutti i moduli e registra gli hook WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Plugin {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init(): void {
        // Admin layer
        if (is_admin()) {
            new RBAI_Settings();
            new RBAI_Tenant_Manager();
            new RBAI_Estrattore_Admin();
            new RBAI_Tools_Admin();
        }

        // Frontend: shortcode + assets
        new RBAI_Estrattore_Frontend();

        // Dashboard shortcode (tenant-aware)
        $products_export = new RBAI_Products_CSV_Export();
        add_shortcode('glovo_invoice_table',       ['RBAI_Invoice_Table',    'render']);
        add_shortcode('glovo_invoice_dashboard',   ['RBAI_Invoice_Dashboard','render']);
        add_shortcode('glovo_details_report',      ['RBAI_Details_Report',   'render']);
        add_shortcode('glovo_orders_dashboard',    ['RBAI_Orders_Dashboard', 'render']);
        add_shortcode('glovo_sales_costs_dashboard',['RBAI_Sales_Costs',     'render']);
        add_shortcode('glovo_products_csv_export', [$products_export,        'shortcode_export_button']);

        // Email analysis hooks (AJAX + admin page)
        RBAI_Email_Analysis::register_hooks();

        // AJAX: filtro fatture
        add_action('wp_ajax_rbai_filter_invoices',        [$this, 'ajax_filter_invoices']);
        add_action('wp_ajax_nopriv_rbai_filter_invoices', [$this, 'ajax_filter_invoices']);

        // AJAX: serve PDF
        add_action('wp_ajax_rbai_serve_pdf',        [$this, 'ajax_serve_pdf']);
        add_action('wp_ajax_nopriv_rbai_serve_pdf', [$this, 'ajax_serve_pdf']);

        // AJAX: Gemini analisi
        add_action('wp_ajax_rbai_gemini_analyze',        ['RBAI_Sales_Costs', 'handle_gemini_analyze']);
        add_action('wp_ajax_nopriv_rbai_gemini_analyze', ['RBAI_Sales_Costs', 'handle_gemini_analyze']);

        // Export dati SCD
        add_action('template_redirect', function () {
            if (isset($_GET['scd_download']) && $_GET['scd_download'] === '1') {
                RBAI_Sales_Costs::handle_export();
            }
        });

        // Cron per-tenant: registra i callback per tutti i tenant attivi
        $this->register_tenant_crons();

        // Enqueue assets frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }

    // ─── AJAX handlers ───────────────────────────────────────────────────────

    public function ajax_filter_invoices(): void {
        $tenant = rbai_verify_tenant_ajax('rbai_ajax_nonce');

        $filters = [
            'destinatario'  => sanitize_text_field($_POST['destinatario']  ?? ''),
            'negozio'       => sanitize_text_field($_POST['negozio']       ?? ''),
            'data_from'     => sanitize_text_field($_POST['data_from']     ?? ''),
            'data_to'       => sanitize_text_field($_POST['data_to']       ?? ''),
            'periodo_from'  => sanitize_text_field($_POST['periodo_from']  ?? ''),
            'periodo_to'    => sanitize_text_field($_POST['periodo_to']    ?? ''),
            'n_fattura'     => sanitize_text_field($_POST['n_fattura']     ?? ''),
        ];

        $db       = new RBAI_Invoice_Database($tenant->get_db_config());
        $invoices = $db->get_filtered_invoices($filters);
        wp_send_json_success($invoices);
    }

    public function ajax_serve_pdf(): void {
        // Verifica nonce
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'rbai_ajax_nonce')) {
            wp_die('Accesso non autorizzato', 'Errore', ['response' => 403]);
        }

        $tenant   = RBAI_Tenant::current();
        $filename = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';

        if (!$tenant || !$tenant->is_active() || empty($filename)) {
            wp_die('Accesso non autorizzato', 'Errore', ['response' => 403]);
        }

        $pdf_dir  = realpath($tenant->get_pdf_processed_dir());
        $pdf_path = realpath($pdf_dir . '/' . $filename);

        // Path traversal check
        if (!$pdf_path || !$pdf_dir || strpos($pdf_path, $pdf_dir) !== 0) {
            wp_die('Accesso non consentito', 'Errore', ['response' => 403]);
        }

        if (!file_exists($pdf_path) || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
            wp_die('File non trovato', 'Errore', ['response' => 404]);
        }

        $force_download = isset($_GET['download']) && $_GET['download'] === '1';
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($force_download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($pdf_path));
        header('Cache-Control: private, max-age=3600');
        readfile($pdf_path);
        exit;
    }

    // ─── Asset frontend ──────────────────────────────────────────────────────

    public function enqueue_frontend_assets(): void {
        $tenant = RBAI_Tenant::current();
        if (!$tenant) {
            return;
        }

        wp_enqueue_style(
            'rbai-dashboard',
            RBAI_PLUGIN_URL . 'assets/css/dashboard.css',
            [],
            RBAI_VERSION
        );

        // CDN libraries
        wp_enqueue_style('rbai-select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');
        wp_enqueue_script('rbai-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
        wp_enqueue_script('rbai-select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
        wp_enqueue_script('rbai-marked',  'https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js', [], '12.0.0', true);
        wp_enqueue_script('rbai-htmldocx','https://cdn.jsdelivr.net/npm/html-docx-js@0.3.1/dist/html-docx.js', [], '0.3.1', true);

        wp_enqueue_script(
            'rbai-dashboard',
            RBAI_PLUGIN_URL . 'assets/js/dashboard.js',
            ['jquery', 'rbai-chartjs', 'rbai-select2', 'rbai-marked', 'rbai-htmldocx'],
            RBAI_VERSION,
            true
        );

        $gemini_key = RBAI_Settings::get_gemini_api_key();
        wp_localize_script('rbai-dashboard', 'rbaiAjax', [
            'ajaxurl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('rbai_ajax_nonce'),
            'geminiAvailable' => ($gemini_key && RBAI_Billing::tenant_has_feature($tenant, 'gemini_ai')) ? '1' : '0',
            'pdfBaseUrl'      => admin_url('admin-ajax.php') . '?action=rbai_serve_pdf&nonce=' . wp_create_nonce('rbai_ajax_nonce') . '&file=',
        ]);
    }

    // ─── Cron per-tenant ─────────────────────────────────────────────────────

    /**
     * Registra i callback WP-Cron per tutti i tenant attivi.
     * Eseguito ad ogni plugins_loaded.
     */
    private function register_tenant_crons(): void {
        $tenants = RBAI_Tenant::get_all('active');
        foreach ($tenants as $tenant) {
            $hook = 'rbai_email_check_' . $tenant->get_id();
            add_action($hook, function () use ($tenant) {
                RBAI_Email_Reader::run_for_tenant($tenant);
            });
        }
    }
}
