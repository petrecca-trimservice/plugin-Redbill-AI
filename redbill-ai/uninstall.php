<?php
/**
 * Redbill AI — Uninstall
 *
 * Eseguito quando il plugin viene disinstallato da wp-admin → Plugin → Elimina.
 * Rimuove: tabella wp_rbai_tenants, tutte le opzioni del plugin,
 * i cron jobs schedulati e (opzionalmente) i dati tenant.
 *
 * NOTA: Non elimina automaticamente i DB MySQL dei tenant né i file upload
 * per sicurezza — super-admin deve farlo manualmente dalla pagina Tenant.
 *
 * @package Redbill_AI
 * @since   1.0.0
 */

// Sicurezza: questo file deve essere chiamato solo da WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 1. Rimuovi cron jobs per-tenant
$tenants = $wpdb->get_results(
    "SELECT id FROM {$wpdb->prefix}rbai_tenants",
    ARRAY_A
);

if ( $tenants ) {
    foreach ( $tenants as $t ) {
        $hook = 'rbai_email_check_' . $t['id'];
        wp_clear_scheduled_hook( $hook );
    }
}

// 2. Elimina tabella tenant
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rbai_tenants" );

// 3. Rimuovi tutte le opzioni del plugin
$options_to_delete = [
    'rbai_db_root_host',
    'rbai_db_root_user',
    'rbai_db_root_pass',
    'rbai_gemini_api_key',
    'rbai_auto_approve',
    'rbai_default_plan',
    'rbai_db_version',
];

foreach ( $options_to_delete as $option ) {
    delete_option( $option );
}

// 4. Rimuovi eventuali transient del plugin
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_rbai_%'
        OR option_name LIKE '_transient_timeout_rbai_%'"
);
