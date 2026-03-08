<?php
/**
 * Gestisce la pagina admin super-admin per la gestione dei tenant:
 * lista, approvazione, sospensione, eliminazione.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Tenant_Manager {

    public function __construct() {
        add_action('admin_menu',             [$this, 'register_menu']);
        add_action('admin_post_rbai_approve_tenant',  [$this, 'handle_approve']);
        add_action('admin_post_rbai_suspend_tenant',  [$this, 'handle_suspend']);
        add_action('admin_post_rbai_activate_tenant', [$this, 'handle_activate']);
        add_action('admin_post_rbai_delete_tenant',   [$this, 'handle_delete']);
        add_action('admin_post_rbai_create_tenant',   [$this, 'handle_create']);
    }

    // ─── Menu ────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        // Il menu principale è registrato da RBAI_Settings; questo aggiunge sottopagine.
        add_submenu_page(
            'redbill-ai',
            __('Gestione Tenant', 'redbill-ai'),
            __('Tenant', 'redbill-ai'),
            'manage_options',
            'rbai-tenants',
            [$this, 'render_page']
        );
    }

    // ─── Pagina principale ───────────────────────────────────────────────────

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $counts  = RBAI_Tenant::count_by_status();
        $tenants = RBAI_Tenant::get_all();

        // Notifica da redirect
        $notice = '';
        if (isset($_GET['rbai_msg'])) {
            $msg_map = [
                'approved'  => ['success', __('Tenant approvato e provisionato con successo.', 'redbill-ai')],
                'suspended' => ['warning', __('Tenant sospeso.', 'redbill-ai')],
                'activated' => ['success', __('Tenant riattivato.', 'redbill-ai')],
                'deleted'   => ['success', __('Tenant eliminato.', 'redbill-ai')],
                'created'   => ['success', __('Tenant creato e provisionato con successo.', 'redbill-ai')],
                'error'     => ['error',   esc_html(urldecode($_GET['rbai_error'] ?? 'Errore'))],
            ];
            $key = sanitize_key($_GET['rbai_msg']);
            if (isset($msg_map[$key])) {
                [$type, $text] = $msg_map[$key];
                $notice = "<div class='notice notice-{$type} is-dismissible'><p>{$text}</p></div>";
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Gestione Tenant', 'redbill-ai'); ?></h1>
            <?php echo $notice; ?>

            <!-- Riepilogo stato -->
            <div style="display:flex;gap:16px;margin-bottom:24px;">
                <?php foreach ($counts as $status => $n): ?>
                <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px 20px;min-width:100px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;"><?php echo $n; ?></div>
                    <div style="color:#666;font-size:12px;text-transform:uppercase;"><?php echo esc_html($status); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Form crea tenant manuale -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:24px;">
                <h3 style="margin-top:0;"><?php esc_html_e('Crea Tenant Manuale', 'redbill-ai'); ?></h3>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                    <input type="hidden" name="action" value="rbai_create_tenant">
                    <?php wp_nonce_field('rbai_create_tenant', 'rbai_nonce'); ?>
                    <div>
                        <label><strong><?php esc_html_e('Utente WordPress', 'redbill-ai'); ?></strong><br>
                        <select name="wp_user_id" required style="min-width:220px;">
                            <option value=""><?php esc_html_e('— Seleziona utente —', 'redbill-ai'); ?></option>
                            <?php
                            $existing_uids = array_map(fn($t) => $t->get_wp_user_id(), $tenants);
                            $users = get_users(['exclude' => $existing_uids]);
                            foreach ($users as $u):
                            ?>
                            <option value="<?php echo $u->ID; ?>"><?php echo esc_html("{$u->display_name} ({$u->user_email})"); ?></option>
                            <?php endforeach; ?>
                        </select></label>
                    </div>
                    <div>
                        <label><strong><?php esc_html_e('Piano', 'redbill-ai'); ?></strong><br>
                        <select name="plan">
                            <option value="basic">Basic</option>
                            <option value="pro">Pro</option>
                            <option value="enterprise">Enterprise</option>
                        </select></label>
                    </div>
                    <?php submit_button(__('Crea e Provisionia', 'redbill-ai'), 'primary', 'submit', false); ?>
                </form>
            </div>

            <!-- Tabella tenant -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Utente', 'redbill-ai'); ?></th>
                        <th><?php esc_html_e('Slug', 'redbill-ai'); ?></th>
                        <th><?php esc_html_e('DB', 'redbill-ai'); ?></th>
                        <th><?php esc_html_e('Piano', 'redbill-ai'); ?></th>
                        <th><?php esc_html_e('Status', 'redbill-ai'); ?></th>
                        <th><?php esc_html_e('Creato', 'redbill-ai'); ?></th>
                        <th><?php esc_html_e('Azioni', 'redbill-ai'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tenants)): ?>
                    <tr><td colspan="7" style="text-align:center;"><?php esc_html_e('Nessun tenant registrato.', 'redbill-ai'); ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($tenants as $tenant): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($tenant->get_display_name()); ?></strong><br>
                            <small><?php echo esc_html($tenant->get_email()); ?></small>
                        </td>
                        <td><?php echo esc_html($tenant->get_slug()); ?></td>
                        <td>
                            <?php
                            $db = $tenant->get_db_config();
                            echo esc_html($db['db_name']);
                            ?>
                            <?php if ($tenant->is_provisioned()): ?>
                                <span style="color:green;">✓</span>
                            <?php else: ?>
                                <span style="color:orange;">⚠</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(strtoupper($tenant->get_plan())); ?></td>
                        <td>
                            <?php
                            $status_colors = ['active' => 'green', 'pending' => 'orange', 'suspended' => 'red'];
                            $color = $status_colors[$tenant->get_status()] ?? '#666';
                            echo "<span style='color:{$color};font-weight:bold;'>" . esc_html(strtoupper($tenant->get_status())) . "</span>";
                            ?>
                        </td>
                        <td><?php echo esc_html(wp_date('d/m/Y', strtotime($tenant->data['created_at'] ?? 'now'))); ?></td>
                        <td>
                            <?php echo $this->render_actions($tenant); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_actions(RBAI_Tenant $tenant): string {
        $id    = $tenant->get_id();
        $html  = '';
        $base  = admin_url('admin-post.php');

        if ($tenant->get_status() === 'pending') {
            $html .= $this->action_form('rbai_approve_tenant', $id, __('Approva', 'redbill-ai'), 'primary');
        }
        if ($tenant->get_status() === 'active') {
            $html .= ' ' . $this->action_form('rbai_suspend_tenant', $id, __('Sospendi', 'redbill-ai'), 'secondary');
        }
        if ($tenant->get_status() === 'suspended') {
            $html .= ' ' . $this->action_form('rbai_activate_tenant', $id, __('Riattiva', 'redbill-ai'), 'primary');
        }
        $html .= ' ' . $this->action_form(
            'rbai_delete_tenant',
            $id,
            __('Elimina', 'redbill-ai'),
            'delete',
            __("Sei sicuro di voler eliminare questo tenant? Verranno eliminati il database MySQL e tutti i dati.", 'redbill-ai')
        );

        return $html;
    }

    private function action_form(string $action, int $tenant_id, string $label, string $type, string $confirm = ''): string {
        $btn_class = $type === 'delete' ? 'button button-link-delete' : "button button-{$type}";
        $confirm_attr = $confirm ? ' onclick="return confirm(' . esc_js(json_encode($confirm)) . ');"' : '';
        return sprintf(
            '<form method="post" action="%s" style="display:inline-block;">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="tenant_id" value="%d">
                %s
                <button type="submit" class="%s"%s>%s</button>
            </form>',
            esc_url(admin_url('admin-post.php')),
            esc_attr($action),
            $tenant_id,
            wp_nonce_field($action, 'rbai_nonce', true, false),
            esc_attr($btn_class),
            $confirm_attr,
            esc_html($label)
        );
    }

    // ─── Handlers POST ───────────────────────────────────────────────────────

    public function handle_approve(): void {
        $this->verify_admin_referer('rbai_approve_tenant');
        $tenant_id = intval($_POST['tenant_id'] ?? 0);
        $tenant    = RBAI_Tenant::for_id($tenant_id);

        if (!$tenant) {
            $this->redirect_with_error('Tenant non trovato.');
        }

        $result = RBAI_Tenant_Provisioner::provision($tenant->get_wp_user_id());
        if (is_wp_error($result)) {
            $this->redirect_with_error($result->get_error_message());
        }

        // Se il provisioner crea il tenant come nuovo, aggiorna status se già esisteva
        if ($tenant->get_status() !== 'active') {
            $tenant->update_status('active');
        }

        $this->redirect('approved');
    }

    public function handle_suspend(): void {
        $this->verify_admin_referer('rbai_suspend_tenant');
        $tenant_id = intval($_POST['tenant_id'] ?? 0);
        $tenant    = RBAI_Tenant::for_id($tenant_id);

        if (!$tenant) {
            $this->redirect_with_error('Tenant non trovato.');
        }

        $tenant->update_status('suspended');
        RBAI_Tenant_Provisioner::unschedule_cron($tenant_id);
        $this->redirect('suspended');
    }

    public function handle_activate(): void {
        $this->verify_admin_referer('rbai_activate_tenant');
        $tenant_id = intval($_POST['tenant_id'] ?? 0);
        $tenant    = RBAI_Tenant::for_id($tenant_id);

        if (!$tenant) {
            $this->redirect_with_error('Tenant non trovato.');
        }

        $tenant->update_status('active');
        RBAI_Tenant_Provisioner::schedule_cron($tenant->get_wp_user_id());
        $this->redirect('activated');
    }

    public function handle_delete(): void {
        $this->verify_admin_referer('rbai_delete_tenant');
        $tenant_id = intval($_POST['tenant_id'] ?? 0);

        $result = RBAI_Tenant_Provisioner::deprovision($tenant_id);
        if (is_wp_error($result)) {
            $this->redirect_with_error($result->get_error_message());
        }

        $this->redirect('deleted');
    }

    public function handle_create(): void {
        $this->verify_admin_referer('rbai_create_tenant');

        $wp_user_id = intval($_POST['wp_user_id'] ?? 0);
        if (!$wp_user_id) {
            $this->redirect_with_error('Seleziona un utente WordPress.');
        }

        $result = RBAI_Tenant_Provisioner::provision($wp_user_id);
        if (is_wp_error($result)) {
            $this->redirect_with_error($result->get_error_message());
        }

        // Aggiorna piano se specificato
        $plan   = sanitize_key($_POST['plan'] ?? 'basic');
        $tenant = RBAI_Tenant::for_user($wp_user_id);
        if ($tenant) {
            $tenant->update_plan($plan);
        }

        $this->redirect('created');
    }

    // ─── Utility ─────────────────────────────────────────────────────────────

    private function verify_admin_referer(string $action): void {
        check_admin_referer($action, 'rbai_nonce');
        if (!current_user_can('manage_options')) {
            wp_die(__('Accesso non autorizzato.', 'redbill-ai'), 403);
        }
    }

    private function redirect(string $msg): void {
        wp_redirect(add_query_arg(['page' => 'rbai-tenants', 'rbai_msg' => $msg], admin_url('admin.php')));
        exit;
    }

    private function redirect_with_error(string $error): void {
        wp_redirect(add_query_arg([
            'page'       => 'rbai-tenants',
            'rbai_msg'   => 'error',
            'rbai_error' => urlencode($error),
        ], admin_url('admin.php')));
        exit;
    }
}
