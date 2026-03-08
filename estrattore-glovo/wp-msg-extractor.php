<?php
/**
 * Plugin Name: Estrattore Email Glovo
 * Description: Estrazione PDF/CSV da file MSG (drag & drop) e lettura automatica email IMAP. Interfaccia grafica moderna con statistiche dettagliate.
 * Version: 8.0
 * Author: Trimservice AI
 * Text Domain: msg-extractor
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

/**
 * Main Plugin Class
 */
class MSG_Extractor_Plugin {

    /**
     * Plugin version
     */
    const VERSION = '8.0';

    /**
     * Plugin directory
     */
    private $plugin_dir;

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->plugin_dir = plugin_dir_path(__FILE__);
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        require_once $this->plugin_dir . 'includes/class-msg-parser.php';
        require_once $this->plugin_dir . 'includes/class-email-reader.php';
        require_once $this->plugin_dir . 'includes/class-frontend.php';

        // Carica admin solo nell'admin
        if (is_admin()) {
            require_once $this->plugin_dir . 'includes/class-admin.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'init'));
        add_action('msg_extractor_cron_hook', array($this, 'run_cron_email_check'));
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Inizializza frontend
        new MSG_Extractor_Frontend_V7();

        // Inizializza admin (solo in area admin)
        if (is_admin()) {
            new MSG_Extractor_Admin();
        }
    }

    /**
     * Eseguito dal cron: legge email e salva allegati
     */
    public function run_cron_email_check() {
        $config = get_option('msg_email_config_v7', array());
        if (empty($config['server']) || empty($config['username']) || empty($config['password'])) {
            return;
        }

        if (!empty($config['password'])) {
            $config['password'] = base64_decode($config['password']);
        }

        $reader = new Email_Auto_Reader_V7();
        $connect_result = $reader->connect(
            $config['server'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['ssl']
        );

        if (!$connect_result['success']) {
            return;
        }

        $filters = array(
            'trusted_senders' => !empty($config['trusted_senders']) ? $config['trusted_senders'] : array(),
            'allowed_extensions' => !empty($config['allowed_extensions']) ? $config['allowed_extensions'] : array('pdf', 'csv'),
            'subject_keywords' => !empty($config['subject_keywords']) ? $config['subject_keywords'] : array(),
            'enable_sender_filter' => !empty($config['enable_sender_filter']),
            'enable_subject_filter' => !empty($config['enable_subject_filter'])
        );

        $batch_limit = !empty($config['cron_batch_limit']) ? intval($config['cron_batch_limit']) : 50;
        $result = $reader->process_emails(!empty($config['mark_as_read']), $filters, $batch_limit);

        // Invia report se abilitato
        if ($result['success'] && !empty($config['enable_report']) && !empty($config['report_recipients'])) {
            $reader->send_report($result['stats'], $config['report_recipients']);
        }
    }

    /**
     * Attivazione plugin: schedula cron
     */
    public static function activate() {
        $config = get_option('msg_email_config_v7', array());
        $frequency = !empty($config['cron_frequency']) ? $config['cron_frequency'] : 'disabled';
        if ($frequency !== 'disabled' && !wp_next_scheduled('msg_extractor_cron_hook')) {
            wp_schedule_event(time(), $frequency, 'msg_extractor_cron_hook');
        }
    }

    /**
     * Disattivazione plugin: rimuove cron
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('msg_extractor_cron_hook');
    }

    /**
     * Get plugin directory path
     */
    public function get_plugin_dir() {
        return $this->plugin_dir;
    }
}

/**
 * Initialize plugin
 */
function msg_extractor_init() {
    return MSG_Extractor_Plugin::get_instance();
}

// Hooks attivazione/disattivazione
register_activation_hook(__FILE__, array('MSG_Extractor_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('MSG_Extractor_Plugin', 'deactivate'));

// Start the plugin
msg_extractor_init();

