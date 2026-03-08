<?php
/**
 * Gestisce attivazione, disattivazione e creazione tabelle WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Installer {

    /**
     * Eseguito all'attivazione del plugin.
     */
    public static function activate(): void {
        self::create_tables();
        self::create_upload_dirs();
        self::set_default_options();
        flush_rewrite_rules();
    }

    /**
     * Eseguito alla disattivazione del plugin.
     * Non elimina dati (solo pulizia cron).
     */
    public static function deactivate(): void {
        // Rimuove tutti i cron schedulati da questo plugin
        $cron_hooks = self::get_all_cron_hooks();
        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    // ─── Tabelle WordPress ───────────────────────────────────────────────────

    /**
     * Crea la tabella wp_rbai_tenants nel database WordPress.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table           = $wpdb->prefix . 'rbai_tenants';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id      BIGINT UNSIGNED NOT NULL,
            tenant_slug     VARCHAR(50)     NOT NULL,
            db_host         VARCHAR(255)    NOT NULL DEFAULT 'localhost',
            db_name         VARCHAR(255)    NOT NULL DEFAULT '',
            db_user         VARCHAR(255)    NOT NULL DEFAULT '',
            db_pass         VARCHAR(500)    NOT NULL DEFAULT '',
            email_config    LONGTEXT        NULL,
            plan            VARCHAR(50)     NOT NULL DEFAULT 'basic',
            status          VARCHAR(20)     NOT NULL DEFAULT 'pending',
            db_provisioned  TINYINT(1)      NOT NULL DEFAULT 0,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wp_user (wp_user_id),
            UNIQUE KEY uq_slug   (tenant_slug),
            KEY idx_status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('rbai_db_version', RBAI_VERSION);
    }

    // ─── Cartelle upload ─────────────────────────────────────────────────────

    /**
     * Crea la radice upload del plugin con .htaccess protettivo.
     */
    private static function create_upload_dirs(): void {
        $base = WP_CONTENT_DIR . '/uploads/rbai/';
        wp_mkdir_p($base);

        // Impedisce directory listing e accesso diretto
        $htaccess = $base . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Options -Indexes\nDeny from all\n");
        }
    }

    // ─── Opzioni default ─────────────────────────────────────────────────────

    private static function set_default_options(): void {
        add_option('rbai_settings', [
            'mysql_root_host'    => 'localhost',
            'mysql_root_user'    => '',
            'mysql_root_pass'    => '',
            'gemini_api_key'     => '',
            'auto_approve'       => false,
            'default_plan'       => 'basic',
        ]);
    }

    // ─── Utility ─────────────────────────────────────────────────────────────

    /**
     * Restituisce tutti gli hook cron schedulati da questo plugin.
     */
    private static function get_all_cron_hooks(): array {
        global $wpdb;

        $hooks = [];
        // Hook cron per-tenant hanno il formato: rbai_email_check_{tenant_id}
        $crons = _get_cron_array();
        if (!is_array($crons)) {
            return $hooks;
        }
        foreach ($crons as $timestamp => $cron_hooks) {
            foreach (array_keys($cron_hooks) as $hook) {
                if (strpos($hook, 'rbai_') === 0) {
                    $hooks[] = $hook;
                }
            }
        }
        return array_unique($hooks);
    }
}
