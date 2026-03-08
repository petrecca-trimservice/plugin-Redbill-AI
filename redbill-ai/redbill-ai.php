<?php
/**
 * Plugin Name: Redbill AI
 * Plugin URI:  https://github.com/petrecca-trimservice/plugin-Redbill-AI
 * Description: Piattaforma SaaS multi-tenant per estrazione, elaborazione e analisi fatture Glovo. Gestisce email IMAP, parsing MSG/PDF, dashboard analytics e AI Gemini.
 * Version:     1.0.0
 * Author:      Trimservice AI
 * Text Domain: redbill-ai
 * Domain Path: /languages
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Costanti plugin
define('RBAI_VERSION',    '1.0.0');
define('RBAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RBAI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RBAI_PLUGIN_FILE', __FILE__);

// Autoload Composer (smalot/pdfparser)
if (file_exists(RBAI_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once RBAI_PLUGIN_DIR . 'vendor/autoload.php';
}

// Helper globali sicurezza/crittografia
require_once RBAI_PLUGIN_DIR . 'includes/functions.php';

// Core
require_once RBAI_PLUGIN_DIR . 'includes/class-redbill-installer.php';
require_once RBAI_PLUGIN_DIR . 'includes/class-redbill-settings.php';
require_once RBAI_PLUGIN_DIR . 'includes/class-redbill-ai.php';

// SaaS layer
require_once RBAI_PLUGIN_DIR . 'includes/saas/class-rbai-tenant.php';
require_once RBAI_PLUGIN_DIR . 'includes/saas/class-rbai-tenant-provisioner.php';
require_once RBAI_PLUGIN_DIR . 'includes/saas/class-rbai-tenant-manager.php';
require_once RBAI_PLUGIN_DIR . 'includes/saas/class-rbai-billing.php';

// Dashboard
require_once RBAI_PLUGIN_DIR . 'includes/dashboard/class-rbai-invoice-database.php';
require_once RBAI_PLUGIN_DIR . 'includes/dashboard/class-rbai-invoice-table.php';
require_once RBAI_PLUGIN_DIR . 'includes/dashboard/class-rbai-invoice-dashboard.php';
require_once RBAI_PLUGIN_DIR . 'includes/dashboard/class-rbai-details-report.php';
require_once RBAI_PLUGIN_DIR . 'includes/dashboard/class-rbai-orders-dashboard.php';
require_once RBAI_PLUGIN_DIR . 'includes/dashboard/class-rbai-sales-costs.php';
require_once RBAI_PLUGIN_DIR . 'includes/dashboard/class-rbai-products-csv-export.php';
require_once RBAI_PLUGIN_DIR . 'includes/dashboard/class-rbai-email-analysis.php';

// Estrattore
require_once RBAI_PLUGIN_DIR . 'includes/estrattore/class-rbai-msg-parser.php';
require_once RBAI_PLUGIN_DIR . 'includes/estrattore/class-rbai-email-reader.php';
require_once RBAI_PLUGIN_DIR . 'includes/estrattore/class-rbai-estrattore-frontend.php';

// Tools
require_once RBAI_PLUGIN_DIR . 'includes/tools/class-rbai-pdf-extractor.php';
require_once RBAI_PLUGIN_DIR . 'includes/tools/class-rbai-csv-importer.php';

// Admin-only
if (is_admin()) {
    require_once RBAI_PLUGIN_DIR . 'includes/estrattore/class-rbai-estrattore-admin.php';
    require_once RBAI_PLUGIN_DIR . 'includes/tools/class-rbai-tools-admin.php';
}

// Hooks attivazione / disattivazione / disinstallazione
register_activation_hook(__FILE__, ['RBAI_Installer', 'activate']);
register_deactivation_hook(__FILE__, ['RBAI_Installer', 'deactivate']);

// Avvia il plugin
RBAI_Plugin::get_instance();
