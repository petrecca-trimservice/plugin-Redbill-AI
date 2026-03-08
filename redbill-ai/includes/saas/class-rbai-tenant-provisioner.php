<?php
/**
 * Provisioning automatico del database e delle cartelle per un nuovo tenant.
 *
 * Eseguito dal super-admin quando approva un tenant con status 'pending'.
 * Crea un database MySQL dedicato, le tabelle necessarie e la cartella upload.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Tenant_Provisioner {

    // ─── Entry point ─────────────────────────────────────────────────────────

    /**
     * Esegue il provisioning completo di un tenant.
     *
     * @param  int       $wp_user_id  ID utente WordPress.
     * @return true|WP_Error
     */
    public static function provision(int $wp_user_id): bool|WP_Error {
        $user = get_userdata($wp_user_id);
        if (!$user) {
            return new WP_Error('invalid_user', __('Utente WordPress non trovato.', 'redbill-ai'));
        }

        // Genera slug dal username (sanitizzato)
        $slug = self::generate_slug($user->user_login);

        // Verifica se il tenant esiste già
        $existing = RBAI_Tenant::for_user($wp_user_id);
        if ($existing) {
            return new WP_Error('already_exists', __('Il tenant esiste già.', 'redbill-ai'));
        }

        // 1. Credenziali DB tenant
        $db_name = 'rbai_' . $slug;
        $db_user = 'rbai_' . substr($slug, 0, 12);
        $db_pass = wp_generate_password(24, true, false);

        // 2. Connessione root MySQL
        $root = self::get_root_connection();
        if (is_wp_error($root)) {
            return $root;
        }

        // 3. Crea database e utente MySQL
        $create_result = self::create_mysql_db($root, $db_name, $db_user, $db_pass);
        if (is_wp_error($create_result)) {
            $root->close();
            return $create_result;
        }

        // 4. Crea tabelle nel DB del tenant
        $tenant_conn = new mysqli($root->host_info ? 'localhost' : 'localhost', $db_user, $db_pass, $db_name);
        // Fallback: usa le credenziali root per creare le tabelle
        if ($tenant_conn->connect_errno) {
            // Prova con connessione root
            $tenant_conn = new mysqli('localhost', self::get_root_user(), self::get_root_pass(), $db_name);
        }

        if (!$tenant_conn->connect_errno) {
            self::create_tables($tenant_conn);
            $tenant_conn->close();
        }

        $root->close();

        // 5. Crea cartella upload
        self::create_upload_dirs($slug);

        // 6. Salva nel DB WordPress
        $settings = get_option('rbai_settings', []);
        $db_host  = $settings['mysql_root_host'] ?? 'localhost';

        global $wpdb;
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'rbai_tenants',
            [
                'wp_user_id'   => $wp_user_id,
                'tenant_slug'  => $slug,
                'db_host'      => $db_host,
                'db_name'      => $db_name,
                'db_user'      => $db_user,
                'db_pass'      => rbai_encrypt($db_pass),
                'plan'         => $settings['default_plan'] ?? 'basic',
                'status'       => 'active',
                'db_provisioned' => 1,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );

        if (!$inserted) {
            return new WP_Error('db_insert_failed', __('Impossibile salvare il tenant nel database WordPress.', 'redbill-ai'));
        }

        // 7. Schedula cron per-tenant
        self::schedule_cron($wp_user_id);

        return true;
    }

    /**
     * Elimina completamente un tenant (DB, upload, record WP).
     */
    public static function deprovision(int $tenant_id): bool|WP_Error {
        $tenant = RBAI_Tenant::for_id($tenant_id);
        if (!$tenant) {
            return new WP_Error('not_found', __('Tenant non trovato.', 'redbill-ai'));
        }

        // Rimuovi cron
        wp_clear_scheduled_hook('rbai_email_check_' . $tenant->get_id());

        // Elimina DB MySQL
        $root = self::get_root_connection();
        if (!is_wp_error($root)) {
            $db_config = $tenant->get_db_config();
            $db_name   = self::escape_identifier($db_config['db_name']);
            $db_user   = $db_config['db_user'];

            $root->query("DROP DATABASE IF EXISTS `{$db_name}`");
            $root->query("DROP USER IF EXISTS '{$db_user}'@'localhost'");
            $root->close();
        }

        // Elimina cartella upload
        $upload_dir = $tenant->get_upload_dir();
        if (is_dir($upload_dir)) {
            self::delete_dir_recursive($upload_dir);
        }

        // Elimina record dal DB WordPress
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'rbai_tenants',
            ['id' => $tenant_id],
            ['%d']
        );

        return true;
    }

    // ─── MySQL ───────────────────────────────────────────────────────────────

    private static function get_root_connection(): mysqli|WP_Error {
        $settings = get_option('rbai_settings', []);
        $host     = $settings['mysql_root_host'] ?? 'localhost';
        $user     = self::get_root_user();
        $pass     = self::get_root_pass();

        if (empty($user)) {
            return new WP_Error('no_root_creds', __('Credenziali MySQL root non configurate nelle impostazioni.', 'redbill-ai'));
        }

        $conn = new mysqli($host, $user, $pass);
        if ($conn->connect_errno) {
            return new WP_Error('mysql_connect', sprintf(
                __('Impossibile connettersi a MySQL come root: %s', 'redbill-ai'),
                $conn->connect_error
            ));
        }

        return $conn;
    }

    private static function get_root_user(): string {
        $settings = get_option('rbai_settings', []);
        return $settings['mysql_root_user'] ?? '';
    }

    private static function get_root_pass(): string {
        $settings = get_option('rbai_settings', []);
        $enc = $settings['mysql_root_pass'] ?? '';
        return $enc ? rbai_decrypt($enc) : '';
    }

    private static function create_mysql_db(mysqli $root, string $db_name, string $db_user, string $db_pass): bool|WP_Error {
        $safe_db   = self::escape_identifier($db_name);
        $safe_user = self::escape_string($root, $db_user);
        $safe_pass = self::escape_string($root, $db_pass);

        if (!$root->query("CREATE DATABASE IF NOT EXISTS `{$safe_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            return new WP_Error('mysql_create_db', sprintf(__('Errore creazione database: %s', 'redbill-ai'), $root->error));
        }

        // Crea utente solo se non esiste già
        $root->query("CREATE USER IF NOT EXISTS '{$safe_user}'@'localhost' IDENTIFIED BY '{$safe_pass}'");
        $root->query("GRANT ALL PRIVILEGES ON `{$safe_db}`.* TO '{$safe_user}'@'localhost'");
        $root->query("FLUSH PRIVILEGES");

        return true;
    }

    // ─── Tabelle tenant ──────────────────────────────────────────────────────

    /**
     * Crea tutte le tabelle nel database del tenant appena provisionato.
     */
    private static function create_tables(mysqli $conn): void {
        $conn->set_charset('utf8mb4');

        // Tabella fatture principali
        $conn->query("CREATE TABLE IF NOT EXISTS `gsr_glovo_fatture` (
            id                              INT UNSIGNED       NOT NULL AUTO_INCREMENT,
            file_pdf                        VARCHAR(255)       NULL,
            destinatario                    VARCHAR(255)       NULL,
            negozio                         VARCHAR(255)       NULL,
            n_fattura                       VARCHAR(100)       NULL,
            data                            DATE               NULL,
            periodo_da                      DATE               NULL,
            periodo_a                       DATE               NULL,
            commissioni                     DECIMAL(10,2)      NULL,
            marketing_visibilita            DECIMAL(10,2)      NULL,
            subtotale                       DECIMAL(10,2)      NULL,
            iva_22                          DECIMAL(10,2)      NULL,
            totale_fattura_iva_inclusa      DECIMAL(10,2)      NULL,
            prodotti                        DECIMAL(10,2)      NULL,
            servizio_consegna               DECIMAL(10,2)      NULL,
            totale_fattura_riepilogo        DECIMAL(10,2)      NULL,
            promo_prodotti_partner          DECIMAL(10,2)      NULL,
            promo_consegna_partner          DECIMAL(10,2)      NULL,
            costi_offerta_lampo             DECIMAL(10,2)      NULL,
            promo_lampo_partner             DECIMAL(10,2)      NULL,
            costo_incidenti_prodotti        DECIMAL(10,2)      NULL,
            tariffa_tempo_attesa            DECIMAL(10,2)      NULL,
            rimborsi_partner_senza_comm     DECIMAL(10,2)      NULL,
            costo_annullamenti_servizio     DECIMAL(10,2)      NULL,
            consegna_gratuita_incidente     DECIMAL(10,2)      NULL,
            buoni_pasto                     DECIMAL(10,2)      NULL,
            supplemento_ordine_glovo_prime  DECIMAL(10,2)      NULL,
            glovo_gia_pagati                DECIMAL(10,2)      NULL,
            ordini_rimborsati_partner       DECIMAL(10,2)      NULL,
            commissione_ordini_rimborsati   DECIMAL(10,2)      NULL,
            sconto_comm_ordini_buoni_pasto  DECIMAL(10,2)      NULL,
            debito_accumulato               DECIMAL(10,2)      NULL,
            importo_bonifico                DECIMAL(10,2)      NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_n_fattura (n_fattura),
            KEY idx_destinatario (destinatario),
            KEY idx_negozio      (negozio),
            KEY idx_data         (data)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Tabella dettagli ordini
        $conn->query("CREATE TABLE IF NOT EXISTS `gsr_glovo_dettagli` (
            id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            order_id        VARCHAR(100)  NULL,
            store_name      VARCHAR(255)  NULL,
            activation_time DATETIME      NULL,
            total_amount    DECIMAL(10,2) NULL,
            status          VARCHAR(50)   NULL,
            courier_fee     DECIMAL(10,2) NULL,
            partner_fee     DECIMAL(10,2) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_order_id (order_id),
            KEY idx_store (store_name),
            KEY idx_time  (activation_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Tabella items ordine
        $conn->query("CREATE TABLE IF NOT EXISTS `gsr_glovo_dettagli_items` (
            id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            order_id    VARCHAR(100)  NULL,
            item_name   VARCHAR(255)  NULL,
            quantity    INT           NULL,
            unit_price  DECIMAL(10,2) NULL,
            total_price DECIMAL(10,2) NULL,
            PRIMARY KEY (id),
            KEY idx_order_id (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Tabella UID email (deduplicazione IMAP)
        $conn->query("CREATE TABLE IF NOT EXISTS `indice_UID_mail` (
            uid         VARCHAR(100) NOT NULL,
            processed   TINYINT(1)   NOT NULL DEFAULT 1,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // ─── Cartelle upload ─────────────────────────────────────────────────────

    private static function create_upload_dirs(string $slug): void {
        $base = WP_CONTENT_DIR . '/uploads/rbai/' . $slug . '/';
        wp_mkdir_p($base . 'pdf/processed/');
        wp_mkdir_p($base . 'pdf/failed/');
        wp_mkdir_p($base . 'csv/');

        // Proteggi da accesso diretto via HTTP
        file_put_contents($base . '.htaccess', "Options -Indexes\nDeny from all\n");
    }

    // ─── Cron per-tenant ─────────────────────────────────────────────────────

    /**
     * Schedula il cron di controllo email per il tenant (se IMAP configurato).
     */
    public static function schedule_cron(int $wp_user_id): void {
        $tenant = RBAI_Tenant::for_user($wp_user_id);
        if (!$tenant) {
            return;
        }

        $email_config = $tenant->get_email_config();
        $frequency    = $email_config['cron_frequency'] ?? 'disabled';

        if ($frequency === 'disabled' || empty($email_config['server'])) {
            return;
        }

        $hook = 'rbai_email_check_' . $tenant->get_id();
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), $frequency, $hook);
        }

        // Registra il callback
        add_action($hook, function () use ($tenant) {
            RBAI_Email_Reader::run_for_tenant($tenant);
        });
    }

    /**
     * Rimuove il cron di un tenant.
     */
    public static function unschedule_cron(int $tenant_id): void {
        wp_clear_scheduled_hook('rbai_email_check_' . $tenant_id);
    }

    // ─── Utility ─────────────────────────────────────────────────────────────

    /**
     * Genera uno slug URL-safe da un username WordPress.
     */
    private static function generate_slug(string $username): string {
        $slug = sanitize_title($username);
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 40);

        // Verifica unicità, aggiunge suffisso numerico se necessario
        global $wpdb;
        $base    = $slug;
        $counter = 2;
        while ($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rbai_tenants WHERE tenant_slug = %s",
            $slug
        ))) {
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }

    private static function escape_identifier(string $name): string {
        return str_replace('`', '', $name);
    }

    private static function escape_string(mysqli $conn, string $str): string {
        return $conn->real_escape_string($str);
    }

    private static function delete_dir_recursive(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? self::delete_dir_recursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
