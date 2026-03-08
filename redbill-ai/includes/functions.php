<?php
/**
 * Helper globali: crittografia, sicurezza AJAX, utilità tenant.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ─── Crittografia ────────────────────────────────────────────────────────────

/**
 * Cifra una stringa con AES-256-CBC usando SECURE_AUTH_KEY come base della chiave.
 */
function rbai_encrypt(string $plaintext): string {
    $key    = substr(hash('sha256', SECURE_AUTH_KEY . 'rbai_enc'), 0, 32);
    $iv     = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . '::' . $cipher);
}

/**
 * Decifra una stringa cifrata con rbai_encrypt().
 */
function rbai_decrypt(string $ciphertext): string {
    $key  = substr(hash('sha256', SECURE_AUTH_KEY . 'rbai_enc'), 0, 32);
    $data = base64_decode($ciphertext);
    if (!$data || strpos($data, '::') === false) {
        return '';
    }
    [$iv, $cipher] = explode('::', $data, 2);
    return (string) openssl_decrypt($cipher, 'AES-256-CBC', $key, 0, $iv);
}

// ─── AJAX helpers ────────────────────────────────────────────────────────────

/**
 * Verifica nonce e tenant per gli AJAX handler.
 * Chiama wp_send_json_error + exit se l'accesso non è autorizzato.
 *
 * @return RBAI_Tenant  Il tenant dell'utente corrente.
 */
function rbai_verify_tenant_ajax(string $nonce_action): RBAI_Tenant {
    check_ajax_referer($nonce_action, 'nonce');

    $tenant = RBAI_Tenant::current();
    if (!$tenant || !$tenant->is_active()) {
        wp_send_json_error(['message' => __('Accesso non autorizzato.', 'redbill-ai')], 403);
    }

    return $tenant;
}

// ─── Utilità tenant ──────────────────────────────────────────────────────────

/**
 * Restituisce true se l'utente corrente è un tenant attivo.
 */
function rbai_is_active_tenant(): bool {
    $tenant = RBAI_Tenant::current();
    return $tenant !== null && $tenant->is_active();
}

/**
 * Restituisce il percorso assoluto della upload dir del tenant corrente,
 * con la sotto-directory specificata già creata.
 * Ritorna stringa vuota se non c'è tenant attivo.
 */
function rbai_tenant_upload_dir(string $subdir = ''): string {
    $tenant = RBAI_Tenant::current();
    if (!$tenant) {
        return '';
    }
    $path = $tenant->get_upload_dir() . ltrim($subdir, '/');
    if (!file_exists($path)) {
        wp_mkdir_p($path);
    }
    return $path;
}

// ─── Intervalli cron personalizzati ─────────────────────────────────────────

add_filter('cron_schedules', function (array $schedules): array {
    $schedules['rbai_every_5min']  = ['interval' => 300,  'display' => __('Ogni 5 minuti',  'redbill-ai')];
    $schedules['rbai_every_15min'] = ['interval' => 900,  'display' => __('Ogni 15 minuti', 'redbill-ai')];
    $schedules['rbai_every_30min'] = ['interval' => 1800, 'display' => __('Ogni 30 minuti', 'redbill-ai')];
    return $schedules;
});
