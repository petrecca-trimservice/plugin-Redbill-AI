<?php
/**
 * Plugin Name: Glovo Invoice Dashboard
 * Plugin URI: https://github.com/petrecca-trimservice/dashboard-Glovo
 * Description: Plugin per visualizzare fatture in tabella e dashboard con KPI e indicatori
 * Version: 1.4.0
 * Author: Glovo Team
 * License: GPL v2 or later
 * Text Domain: glovo-invoice-dashboard
 */

// Impedisce l'accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// Sopprimi warning di deprecation da plugin di terze parti (Freemius)
// Questi warning non dipendono dal nostro codice
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Ignora deprecation warning da Freemius/login-customizer
    if ($errno === E_DEPRECATED && strpos($errfile, 'freemius') !== false) {
        return true; // Sopprimi l'errore
    }
    // Lascia passare tutti gli altri errori al gestore predefinito
    return false;
}, E_DEPRECATED);

// Definisce le costanti del plugin
define('GID_VERSION', '1.4.0');
define('GID_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GID_PLUGIN_URL', plugin_dir_url(__FILE__));

// Carica le classi necessarie
require_once GID_PLUGIN_DIR . 'includes/class-invoice-database.php';
require_once GID_PLUGIN_DIR . 'includes/class-invoice-table.php';
require_once GID_PLUGIN_DIR . 'includes/class-invoice-dashboard.php';
require_once GID_PLUGIN_DIR . 'includes/class-glovo-details-report.php';
require_once GID_PLUGIN_DIR . 'includes/class-orders-dashboard.php';
require_once GID_PLUGIN_DIR . 'includes/class-products-csv-export.php';
require_once GID_PLUGIN_DIR . 'includes/class-sales-costs-dashboard.php';
require_once GID_PLUGIN_DIR . 'includes/class-email-analysis.php';

/**
 * Classe principale del plugin
 */
class Glovo_Invoice_Dashboard {

    private static $instance = null;
    private $products_csv_export;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
    }

    public function init() {
        // Inizializza la classe per l'export CSV dei prodotti
        $this->products_csv_export = new GID_Products_CSV_Export();

        // Registra gli shortcode
        add_shortcode('glovo_invoice_table', array('GID_Invoice_Table', 'render'));
        add_shortcode('glovo_invoice_dashboard', array('GID_Invoice_Dashboard', 'render'));
        add_shortcode('glovo_details_report', array('GID_Glovo_Details_Report', 'render'));
        add_shortcode('glovo_orders_dashboard', array('GID_Orders_Dashboard', 'render'));
        add_shortcode('glovo_products_csv_export', array($this->products_csv_export, 'shortcode_export_button'));
        add_shortcode('glovo_sales_costs_dashboard', array('GID_Sales_Costs_Dashboard', 'render'));

        // Registra hooks per email analysis (pagina admin + AJAX)
        GID_Email_Analysis::register_hooks();

        // Export dati per AI dal Sales Costs Dashboard
        add_action('template_redirect', function () {
            if (isset($_GET['scd_download']) && $_GET['scd_download'] === '1') {
                GID_Sales_Costs_Dashboard::handle_export();
            }
        });

        // Registra gli stili e gli script
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function enqueue_assets() {
        // CSS
        wp_enqueue_style(
            'glovo-invoice-dashboard',
            GID_PLUGIN_URL . 'assets/css/style.css',
            array(),
            GID_VERSION
        );

        // Select2 CSS
        wp_enqueue_style(
            'select2-css',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            array(),
            '4.1.0',
            'all'
        );

        // Chart.js library
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            array(),
            '4.4.1',
            true
        );

        // Select2 JS
        wp_enqueue_script(
            'select2-js',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            array('jquery'),
            '4.1.0',
            true
        );

        // marked.js – rendering Markdown per il report Gemini
        wp_enqueue_script(
            'marked-js',
            'https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js',
            array(),
            '12.0.0',
            true
        );

        // html-docx-js – export DOCX client-side
        wp_enqueue_script(
            'html-docx-js',
            'https://cdn.jsdelivr.net/npm/html-docx-js@0.3.1/dist/html-docx.js',
            array(),
            '0.3.1',
            true
        );

        // JavaScript
        wp_enqueue_script(
            'glovo-invoice-dashboard',
            GID_PLUGIN_URL . 'assets/js/filters.js',
            array('jquery', 'chart-js', 'select2-js', 'marked-js', 'html-docx-js'),
            GID_VERSION,
            true
        );

        // Localizza script per AJAX
        wp_localize_script('glovo-invoice-dashboard', 'gidAjax', array(
            'ajaxurl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('gid_ajax_nonce'),
            'geminiNonce'     => wp_create_nonce('gid_gemini_nonce'),
            'emailNonce'      => wp_create_nonce('gid_email_analysis_nonce'),
            'geminiAvailable' => defined('GID_GEMINI_API_KEY') ? '1' : '0',
            'pdfBaseUrl'      => content_url('/uploads/msg-extracted/pdf/processed/')
        ));
    }
}

// Inizializza il plugin
Glovo_Invoice_Dashboard::get_instance();

// Registra l'AJAX handler per i filtri
add_action('wp_ajax_gid_filter_invoices', 'gid_filter_invoices_callback');
add_action('wp_ajax_nopriv_gid_filter_invoices', 'gid_filter_invoices_callback');

// Registra l'AJAX handler per l'analisi Gemini
add_action('wp_ajax_gid_gemini_analyze', array('GID_Sales_Costs_Dashboard', 'handle_gemini_analyze'));
add_action('wp_ajax_nopriv_gid_gemini_analyze', array('GID_Sales_Costs_Dashboard', 'handle_gemini_analyze'));

function gid_filter_invoices_callback() {
    check_ajax_referer('gid_ajax_nonce', 'nonce');

    $filters = array(
        'destinatario' => isset($_POST['destinatario']) ? sanitize_text_field($_POST['destinatario']) : '',
        'negozio' => isset($_POST['negozio']) ? sanitize_text_field($_POST['negozio']) : '',
        'data_from' => isset($_POST['data_from']) ? sanitize_text_field($_POST['data_from']) : '',
        'data_to' => isset($_POST['data_to']) ? sanitize_text_field($_POST['data_to']) : '',
        'periodo_from' => isset($_POST['periodo_from']) ? sanitize_text_field($_POST['periodo_from']) : '',
        'periodo_to' => isset($_POST['periodo_to']) ? sanitize_text_field($_POST['periodo_to']) : '',
        'n_fattura' => isset($_POST['n_fattura']) ? sanitize_text_field($_POST['n_fattura']) : '',
    );

    $db = new GID_Invoice_Database();
    $invoices = $db->get_filtered_invoices($filters);

    wp_send_json_success($invoices);
}

// Registra l'AJAX handler per servire i PDF (bypassa .htaccess)
add_action('wp_ajax_gid_serve_pdf', 'gid_serve_pdf_callback');
add_action('wp_ajax_nopriv_gid_serve_pdf', 'gid_serve_pdf_callback');

function gid_serve_pdf_callback() {
    // Verifica nonce
    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'gid_ajax_nonce')) {
        wp_die('Accesso non autorizzato', 'Errore', array('response' => 403));
    }

    // Ottieni il nome del file
    $filename = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';

    if (empty($filename)) {
        wp_die('File non specificato', 'Errore', array('response' => 400));
    }

    // Costruisci il percorso completo del file
    $upload_dir = wp_upload_dir();
    $pdf_path = $upload_dir['basedir'] . '/msg-extracted/pdf/processed/' . $filename;

    // Verifica che il file esista e sia un PDF
    if (!file_exists($pdf_path)) {
        wp_die('File non trovato: ' . esc_html($filename), 'Errore', array('response' => 404));
    }

    // Verifica l'estensione del file (solo PDF)
    $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($file_ext !== 'pdf') {
        wp_die('Tipo file non consentito', 'Errore', array('response' => 403));
    }

    // Verifica che il file sia dentro la cartella consentita (prevenzione path traversal)
    $real_path = realpath($pdf_path);
    $allowed_dir = realpath($upload_dir['basedir'] . '/msg-extracted/pdf/processed');
    if ($real_path === false || strpos($real_path, $allowed_dir) !== 0) {
        wp_die('Accesso non consentito', 'Errore', array('response' => 403));
    }

    // Determina se forzare il download
    $force_download = isset($_GET['download']) && $_GET['download'] == '1';

    // Imposta gli header per servire il PDF
    header('Content-Type: application/pdf');
    if ($force_download) {
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    } else {
        header('Content-Disposition: inline; filename="' . $filename . '"');
    }
    header('Content-Length: ' . filesize($pdf_path));
    header('Cache-Control: public, max-age=3600');

    // Serve il file
    readfile($pdf_path);
    exit;
}
