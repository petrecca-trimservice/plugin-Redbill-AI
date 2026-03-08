<?php
/**
 * Pagina impostazioni globali super-admin.
 * Registra il menu principale "Redbill AI" e gestisce le impostazioni
 * di livello piattaforma (MySQL root, Gemini API key, ecc.).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Settings {

    private const OPTION_KEY = 'rbai_settings';

    public function __construct() {
        add_action('admin_menu',  [$this, 'register_menu']);
        add_action('admin_init',  [$this, 'register_settings']);
    }

    // ─── Menu ────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        add_menu_page(
            __('Redbill AI', 'redbill-ai'),
            __('Redbill AI', 'redbill-ai'),
            'manage_options',
            'redbill-ai',
            [$this, 'render_overview'],
            'dashicons-chart-area',
            60
        );

        add_submenu_page(
            'redbill-ai',
            __('Panoramica', 'redbill-ai'),
            __('Panoramica', 'redbill-ai'),
            'manage_options',
            'redbill-ai',
            [$this, 'render_overview']
        );

        add_submenu_page(
            'redbill-ai',
            __('Impostazioni Piattaforma', 'redbill-ai'),
            __('Impostazioni', 'redbill-ai'),
            'manage_options',
            'rbai-settings',
            [$this, 'render_settings']
        );
    }

    // ─── Settings API ────────────────────────────────────────────────────────

    public function register_settings(): void {
        register_setting(
            'rbai_settings_group',
            self::OPTION_KEY,
            [$this, 'sanitize']
        );
    }

    public function sanitize(array $input): array {
        $out = get_option(self::OPTION_KEY, []);

        $out['mysql_root_host'] = sanitize_text_field($input['mysql_root_host'] ?? 'localhost');
        $out['mysql_root_user'] = sanitize_text_field($input['mysql_root_user'] ?? '');
        $out['default_plan']    = in_array($input['default_plan'] ?? '', ['basic', 'pro', 'enterprise'])
            ? $input['default_plan']
            : 'basic';
        $out['auto_approve']    = !empty($input['auto_approve']);

        // Password root: cifra solo se è stata modificata (non placeholder)
        $new_pass = $input['mysql_root_pass'] ?? '';
        if ($new_pass !== '' && $new_pass !== '••••••••') {
            $out['mysql_root_pass'] = rbai_encrypt($new_pass);
        }

        // Gemini API key: cifra se modificata
        $new_gemini = $input['gemini_api_key'] ?? '';
        if ($new_gemini !== '' && $new_gemini !== '••••••••') {
            $out['gemini_api_key'] = rbai_encrypt($new_gemini);
        } elseif ($new_gemini === '') {
            $out['gemini_api_key'] = '';
        }

        return $out;
    }

    // ─── Render Panoramica ───────────────────────────────────────────────────

    public function render_overview(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $counts = RBAI_Tenant::count_by_status();
        $total  = array_sum($counts);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Redbill AI – Panoramica Piattaforma', 'redbill-ai'); ?></h1>
            <div style="display:flex;gap:16px;margin-top:20px;flex-wrap:wrap;">
                <?php
                $tiles = [
                    [__('Tenant Totali', 'redbill-ai'), $total, '#2271b1'],
                    [__('Attivi', 'redbill-ai'),     $counts['active'],    '#00a32a'],
                    [__('In Attesa', 'redbill-ai'),  $counts['pending'],   '#d63638'],
                    [__('Sospesi', 'redbill-ai'),    $counts['suspended'], '#dba617'],
                ];
                foreach ($tiles as [$label, $value, $color]):
                ?>
                <div style="background:#fff;border-left:4px solid <?php echo $color; ?>;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.12);padding:16px 24px;min-width:140px;">
                    <div style="font-size:32px;font-weight:700;color:<?php echo $color; ?>;"><?php echo $value; ?></div>
                    <div style="color:#666;font-size:13px;"><?php echo esc_html($label); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:24px;">
                <a href="<?php echo admin_url('admin.php?page=rbai-tenants'); ?>" class="button button-primary">
                    <?php esc_html_e('Gestisci Tenant', 'redbill-ai'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=rbai-tools'); ?>" class="button" style="margin-left:8px;">
                    <?php esc_html_e('Strumenti', 'redbill-ai'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=rbai-settings'); ?>" class="button" style="margin-left:8px;">
                    <?php esc_html_e('Impostazioni', 'redbill-ai'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    // ─── Render Impostazioni ─────────────────────────────────────────────────

    public function render_settings(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option(self::OPTION_KEY, []);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Impostazioni Piattaforma', 'redbill-ai'); ?></h1>

            <?php if (isset($_GET['settings-updated'])): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Impostazioni salvate.', 'redbill-ai'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('rbai_settings_group'); ?>

                <h2><?php esc_html_e('Database MySQL (Provisioning)', 'redbill-ai'); ?></h2>
                <p class="description"><?php esc_html_e('Credenziali con permessi CREATE DATABASE/USER per il provisioning automatico dei tenant.', 'redbill-ai'); ?></p>
                <table class="form-table">
                    <tr>
                        <th><label for="mysql_root_host"><?php esc_html_e('Host MySQL', 'redbill-ai'); ?></label></th>
                        <td><input type="text" id="mysql_root_host" name="<?php echo self::OPTION_KEY; ?>[mysql_root_host]"
                                   value="<?php echo esc_attr($settings['mysql_root_host'] ?? 'localhost'); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="mysql_root_user"><?php esc_html_e('Utente MySQL (root)', 'redbill-ai'); ?></label></th>
                        <td><input type="text" id="mysql_root_user" name="<?php echo self::OPTION_KEY; ?>[mysql_root_user]"
                                   value="<?php echo esc_attr($settings['mysql_root_user'] ?? ''); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="mysql_root_pass"><?php esc_html_e('Password MySQL (root)', 'redbill-ai'); ?></label></th>
                        <td>
                            <input type="password" id="mysql_root_pass" name="<?php echo self::OPTION_KEY; ?>[mysql_root_pass]"
                                   value="<?php echo !empty($settings['mysql_root_pass']) ? '••••••••' : ''; ?>" class="regular-text"
                                   placeholder="<?php esc_attr_e('Lascia vuoto per non modificare', 'redbill-ai'); ?>">
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Intelligenza Artificiale', 'redbill-ai'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="gemini_api_key"><?php esc_html_e('Gemini API Key', 'redbill-ai'); ?></label></th>
                        <td>
                            <input type="password" id="gemini_api_key" name="<?php echo self::OPTION_KEY; ?>[gemini_api_key]"
                                   value="<?php echo !empty($settings['gemini_api_key']) ? '••••••••' : ''; ?>" class="regular-text"
                                   placeholder="<?php esc_attr_e('Lascia vuoto per non modificare', 'redbill-ai'); ?>">
                            <p class="description"><?php esc_html_e('Necessaria per i piani Pro ed Enterprise (analisi Gemini AI).', 'redbill-ai'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Registrazione Tenant', 'redbill-ai'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Auto-approvazione', 'redbill-ai'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[auto_approve]"
                                       <?php checked(!empty($settings['auto_approve'])); ?>>
                                <?php esc_html_e('Approva automaticamente i nuovi tenant alla registrazione', 'redbill-ai'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="default_plan"><?php esc_html_e('Piano Default', 'redbill-ai'); ?></label></th>
                        <td>
                            <select id="default_plan" name="<?php echo self::OPTION_KEY; ?>[default_plan]">
                                <?php foreach (['basic', 'pro', 'enterprise'] as $plan): ?>
                                <option value="<?php echo $plan; ?>" <?php selected($settings['default_plan'] ?? 'basic', $plan); ?>>
                                    <?php echo esc_html(ucfirst($plan)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Salva Impostazioni', 'redbill-ai')); ?>
            </form>
        </div>
        <?php
    }

    // ─── Getter API key (per le classi che ne hanno bisogno) ─────────────────

    /**
     * Restituisce la Gemini API key decifrata.
     */
    public static function get_gemini_api_key(): string {
        $settings = get_option(self::OPTION_KEY, []);
        $enc = $settings['gemini_api_key'] ?? '';
        return $enc ? rbai_decrypt($enc) : '';
    }
}
