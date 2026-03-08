<?php
/**
 * Modello Tenant – cuore del multi-tenancy SaaS.
 *
 * Ogni istanza rappresenta un cliente (ristorante/partner Glovo) con:
 *  - credenziali DB MySQL dedicato (isolamento totale)
 *  - configurazione IMAP propria
 *  - cartella upload propria
 *  - piano Freemius e status (pending/active/suspended)
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Tenant {

    /** Riga completa da wp_rbai_tenants */
    private array $data;

    // ─── Factory methods ─────────────────────────────────────────────────────

    /**
     * Restituisce il tenant dell'utente WordPress correntemente loggato,
     * o null se non è loggato / non ha un tenant associato.
     */
    public static function current(): ?self {
        $uid = get_current_user_id();
        return $uid ? self::for_user($uid) : null;
    }

    /**
     * Restituisce il tenant associato a un wp_user_id specifico.
     */
    public static function for_user(int $wp_user_id): ?self {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rbai_tenants WHERE wp_user_id = %d LIMIT 1",
                $wp_user_id
            ),
            ARRAY_A
        );
        return $row ? new self($row) : null;
    }

    /**
     * Restituisce il tenant per slug (es. "mario-pizzeria").
     */
    public static function for_slug(string $slug): ?self {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rbai_tenants WHERE tenant_slug = %s LIMIT 1",
                $slug
            ),
            ARRAY_A
        );
        return $row ? new self($row) : null;
    }

    /**
     * Restituisce il tenant per id numerico interno.
     */
    public static function for_id(int $id): ?self {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rbai_tenants WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );
        return $row ? new self($row) : null;
    }

    // ─── Costruttore ─────────────────────────────────────────────────────────

    private function __construct(array $data) {
        $this->data = $data;
    }

    // ─── Getters base ────────────────────────────────────────────────────────

    public function get_id(): int {
        return (int) $this->data['id'];
    }

    public function get_wp_user_id(): int {
        return (int) $this->data['wp_user_id'];
    }

    public function get_slug(): string {
        return $this->data['tenant_slug'];
    }

    public function get_plan(): string {
        return $this->data['plan'];
    }

    public function get_status(): string {
        return $this->data['status'];
    }

    public function is_active(): bool {
        return $this->data['status'] === 'active';
    }

    public function is_provisioned(): bool {
        return (bool) $this->data['db_provisioned'];
    }

    // ─── Configurazione DB ───────────────────────────────────────────────────

    /**
     * Restituisce l'array di configurazione per RBAI_Invoice_Database.
     * La password è decifrata on-the-fly (mai esposta in chiaro a riposo).
     */
    public function get_db_config(): array {
        return [
            'db_host'    => $this->data['db_host'],
            'db_name'    => $this->data['db_name'],
            'db_user'    => $this->data['db_user'],
            'db_pass'    => rbai_decrypt($this->data['db_pass']),
            'db_charset' => 'utf8mb4',
            'db_table'   => 'gsr_glovo_fatture',
        ];
    }

    // ─── Configurazione Email IMAP ───────────────────────────────────────────

    /**
     * Restituisce la configurazione IMAP del tenant (password decifrata).
     */
    public function get_email_config(): array {
        $config = json_decode($this->data['email_config'] ?? '{}', true);
        if (!is_array($config)) {
            $config = [];
        }

        // Decifra la password IMAP se presente
        if (!empty($config['password'])) {
            $config['password'] = rbai_decrypt($config['password']);
        }

        return $config;
    }

    /**
     * Salva la configurazione IMAP (cifra la password prima del salvataggio).
     */
    public function save_email_config(array $config): bool {
        global $wpdb;

        if (!empty($config['password'])) {
            $config['password'] = rbai_encrypt($config['password']);
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'rbai_tenants',
            ['email_config' => wp_json_encode($config)],
            ['id'           => $this->get_id()],
            ['%s'],
            ['%d']
        );

        if ($result !== false) {
            $this->data['email_config'] = wp_json_encode($config);
            return true;
        }
        return false;
    }

    // ─── Upload directory ────────────────────────────────────────────────────

    /**
     * Percorso assoluto della cartella upload del tenant.
     * Struttura: /wp-content/uploads/rbai/{slug}/
     */
    public function get_upload_dir(): string {
        return WP_CONTENT_DIR . '/uploads/rbai/' . $this->data['tenant_slug'] . '/';
    }

    /**
     * URL pubblico della cartella upload del tenant.
     */
    public function get_upload_url(): string {
        return WP_CONTENT_URL . '/uploads/rbai/' . $this->data['tenant_slug'] . '/';
    }

    /**
     * Percorso assoluto della cartella PDF processed del tenant.
     */
    public function get_pdf_processed_dir(): string {
        return $this->get_upload_dir() . 'pdf/processed/';
    }

    /**
     * URL PDF processed del tenant.
     */
    public function get_pdf_processed_url(): string {
        return $this->get_upload_url() . 'pdf/processed/';
    }

    // ─── Dati utente WordPress ───────────────────────────────────────────────

    /**
     * Restituisce l'oggetto WP_User del tenant.
     */
    public function get_wp_user(): ?WP_User {
        $user = get_userdata($this->get_wp_user_id());
        return $user instanceof WP_User ? $user : null;
    }

    public function get_display_name(): string {
        $user = $this->get_wp_user();
        return $user ? $user->display_name : $this->get_slug();
    }

    public function get_email(): string {
        $user = $this->get_wp_user();
        return $user ? $user->user_email : '';
    }

    // ─── Aggiornamento status/piano ──────────────────────────────────────────

    public function update_status(string $status): bool {
        global $wpdb;
        $allowed = ['pending', 'active', 'suspended'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'rbai_tenants',
            ['status' => $status],
            ['id'     => $this->get_id()],
            ['%s'],
            ['%d']
        );

        if ($result !== false) {
            $this->data['status'] = $status;
            return true;
        }
        return false;
    }

    public function update_plan(string $plan): bool {
        global $wpdb;
        $allowed = ['basic', 'pro', 'enterprise'];
        if (!in_array($plan, $allowed, true)) {
            return false;
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'rbai_tenants',
            ['plan' => $plan],
            ['id'   => $this->get_id()],
            ['%s'],
            ['%d']
        );

        if ($result !== false) {
            $this->data['plan'] = $plan;
            return true;
        }
        return false;
    }

    // ─── Static helpers ──────────────────────────────────────────────────────

    /**
     * Restituisce tutti i tenant (per pagina admin super-admin).
     *
     * @return self[]
     */
    public static function get_all(string $status = ''): array {
        global $wpdb;

        $where = $status ? $wpdb->prepare('WHERE status = %s', $status) : '';
        $rows  = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rbai_tenants {$where} ORDER BY created_at DESC",
            ARRAY_A
        );

        return array_map(fn($row) => new self($row), $rows ?: []);
    }

    /**
     * Conta i tenant per status.
     */
    public static function count_by_status(): array {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as n FROM {$wpdb->prefix}rbai_tenants GROUP BY status",
            ARRAY_A
        );
        $counts = ['pending' => 0, 'active' => 0, 'suspended' => 0];
        foreach ($results as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
        return $counts;
    }
}
