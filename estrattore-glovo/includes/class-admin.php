<?php
/**
 * Admin Interface
 *
 * Interfaccia amministratore WordPress per configurazione email
 * e gestione plugin
 *
 * @package MSG_Extractor
 * @since 8.0
 */

if (!defined('ABSPATH')) exit;

class MSG_Extractor_Admin {

    private $option_name = 'msg_email_config_v7';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_post_test_email_connection', array($this, 'handle_test_connection'));
        add_action('admin_post_process_emails_now', array($this, 'handle_process_emails'));
        add_action('admin_post_reset_processed_emails', array($this, 'handle_reset_processed'));
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
    }

    /**
     * Aggiunge intervalli cron personalizzati
     */
    public function add_cron_schedules($schedules) {
        $schedules['every_5_minutes'] = array(
            'interval' => 300,
            'display'  => 'Ogni 5 minuti'
        );
        $schedules['every_15_minutes'] = array(
            'interval' => 900,
            'display'  => 'Ogni 15 minuti'
        );
        $schedules['every_30_minutes'] = array(
            'interval' => 1800,
            'display'  => 'Ogni 30 minuti'
        );
        return $schedules;
    }

    /**
     * Aggiunge menu admin
     */
    public function add_admin_menu() {
        add_menu_page(
            'Estrattore Email Glovo',
            'Estrattore Glovo',
            'manage_options',
            'msg-extractor',
            array($this, 'render_main_page'),
            'dashicons-email-alt',
            65
        );

        add_submenu_page(
            'msg-extractor',
            'Configurazione Email',
            'Configurazione Email',
            'manage_options',
            'msg-extractor',
            array($this, 'render_main_page')
        );

        add_submenu_page(
            'msg-extractor',
            'Carica File MSG',
            'Carica File MSG',
            'manage_options',
            'msg-extractor-upload',
            array($this, 'render_upload_page')
        );
    }

    /**
     * Registra impostazioni plugin
     */
    public function register_settings() {
        register_setting('msg_extractor_settings', $this->option_name, array($this, 'sanitize_config'));
    }

    /**
     * Sanitizza configurazione prima del salvataggio
     */
    public function sanitize_config($input) {
        $sanitized = array();

        if (isset($input['server'])) {
            $sanitized['server'] = sanitize_text_field($input['server']);
        }

        if (isset($input['port'])) {
            $sanitized['port'] = intval($input['port']);
        }

        if (isset($input['username'])) {
            $sanitized['username'] = sanitize_email($input['username']);
        }

        if (isset($input['password'])) {
            // Cripta password
            $sanitized['password'] = base64_encode($input['password']);
        }

        $sanitized['ssl'] = isset($input['ssl']) ? true : false;
        $sanitized['mark_as_read'] = isset($input['mark_as_read']) ? true : false;

        // Filtri email
        if (isset($input['trusted_senders'])) {
            // Pulisce e valida gli indirizzi email
            if (is_array($input['trusted_senders'])) {
                $senders = $input['trusted_senders'];
            } else {
                $senders = array_map('trim', explode("\n", $input['trusted_senders']));
            }
            $senders = array_filter($senders); // Rimuove righe vuote
            $valid_senders = array();
            foreach ($senders as $sender) {
                $email = sanitize_email($sender);
                if (is_email($email)) {
                    $valid_senders[] = $email;
                }
            }
            $sanitized['trusted_senders'] = $valid_senders;
        }

        if (isset($input['allowed_extensions'])) {
            // Pulisce le estensioni
            if (is_array($input['allowed_extensions'])) {
                $extensions = array_map('trim', array_map('strtolower', $input['allowed_extensions']));
            } else {
                $extensions = array_map('trim', explode(',', strtolower($input['allowed_extensions'])));
            }
            $extensions = array_filter($extensions);
            $extensions = array_map(function($ext) {
                return ltrim($ext, '.');
            }, $extensions);
            $sanitized['allowed_extensions'] = $extensions;
        } else {
            $sanitized['allowed_extensions'] = array('pdf', 'csv'); // Default
        }

        if (isset($input['subject_keywords'])) {
            if (is_array($input['subject_keywords'])) {
                $keywords = array_map('trim', $input['subject_keywords']);
            } else {
                $keywords = array_map('trim', explode(',', $input['subject_keywords']));
            }
            $keywords = array_filter($keywords);
            $sanitized['subject_keywords'] = array_map('sanitize_text_field', $keywords);
        }

        $sanitized['enable_sender_filter'] = isset($input['enable_sender_filter']) ? true : false;
        $sanitized['enable_subject_filter'] = isset($input['enable_subject_filter']) ? true : false;

        // Notifiche report
        $sanitized['enable_report'] = isset($input['enable_report']) ? true : false;
        if (isset($input['report_recipients'])) {
            if (is_array($input['report_recipients'])) {
                $recipients = $input['report_recipients'];
            } else {
                $recipients = array_map('trim', explode("\n", $input['report_recipients']));
            }
            $recipients = array_filter($recipients);
            $valid_recipients = array();
            foreach ($recipients as $recipient) {
                $email = sanitize_email($recipient);
                if (is_email($email)) {
                    $valid_recipients[] = $email;
                }
            }
            $sanitized['report_recipients'] = $valid_recipients;
        }

        // Batch limit cron
        if (isset($input['cron_batch_limit'])) {
            $sanitized['cron_batch_limit'] = max(1, min(50, intval($input['cron_batch_limit'])));
        } else {
            $sanitized['cron_batch_limit'] = 50;
        }

        // Frequenza cron
        $valid_frequencies = array('disabled', 'every_5_minutes', 'every_15_minutes', 'every_30_minutes', 'hourly', 'twicedaily', 'daily');
        $sanitized['cron_frequency'] = isset($input['cron_frequency']) && in_array($input['cron_frequency'], $valid_frequencies)
            ? $input['cron_frequency']
            : 'disabled';

        // Rischedula cron se la frequenza è cambiata
        $old_config = get_option($this->option_name, array());
        $old_frequency = !empty($old_config['cron_frequency']) ? $old_config['cron_frequency'] : 'disabled';
        if ($sanitized['cron_frequency'] !== $old_frequency) {
            wp_clear_scheduled_hook('msg_extractor_cron_hook');
            if ($sanitized['cron_frequency'] !== 'disabled') {
                wp_schedule_event(time(), $sanitized['cron_frequency'], 'msg_extractor_cron_hook');
            }
        }

        return $sanitized;
    }

    /**
     * Carica assets CSS/JS admin
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'msg-extractor') === false) {
            return;
        }

        wp_enqueue_style(
            'msg-extractor-admin',
            plugins_url('../assets/css/admin.css', __FILE__),
            array(),
            '8.0'
        );
    }

    /**
     * Render pagina principale admin
     */
    public function render_main_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $config = get_option($this->option_name, array());
        if (!empty($config['password'])) {
            $config['password'] = base64_decode($config['password']);
        }

        $reader = new Email_Auto_Reader_V7();

        // Messaggi di notifica
        $notice = '';
        if (isset($_GET['settings-updated'])) {
            $notice = '<div class="notice notice-success is-dismissible"><p>Configurazione salvata con successo!</p></div>';
        }
        if (isset($_GET['test_result'])) {
            if ($_GET['test_result'] === 'success') {
                $test_msg = isset($_GET['test_msg']) ? esc_html(urldecode($_GET['test_msg'])) : 'Test connessione riuscito!';
                $notice = '<div class="notice notice-success is-dismissible"><p>' . $test_msg . '</p></div>';
            } else {
                $error_msg = isset($_GET['error_msg']) ? urldecode($_GET['error_msg']) : 'Test connessione fallito';
                $notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_msg) . '</p></div>';
            }
        }
        if (isset($_GET['reset_result']) && $_GET['reset_result'] === 'success') {
            $notice = '<div class="notice notice-success is-dismissible"><p>Lista email elaborate resettata con successo! Alla prossima elaborazione verranno riprocessate tutte le email.</p></div>';
        }
        if (isset($_GET['process_result'])) {
            if ($_GET['process_result'] === 'success') {
                $emails = isset($_GET['emails']) ? intval($_GET['emails']) : 0;
                $pdf = isset($_GET['pdf']) ? intval($_GET['pdf']) : 0;
                $csv = isset($_GET['csv']) ? intval($_GET['csv']) : 0;
                $total = isset($_GET['total']) ? intval($_GET['total']) : 0;
                $skipped_sender = isset($_GET['skipped_sender']) ? intval($_GET['skipped_sender']) : 0;
                $skipped_subject = isset($_GET['skipped_subject']) ? intval($_GET['skipped_subject']) : 0;
                $skipped_ext = isset($_GET['skipped_ext']) ? intval($_GET['skipped_ext']) : 0;
                $skipped_already = isset($_GET['skipped_already']) ? intval($_GET['skipped_already']) : 0;

                $stats_html = '<p><strong>Elaborazione completata!</strong></p>';
                $stats_html .= '<p>Email nuove elaborate: <strong>' . $emails . '</strong> | Allegati totali: <strong>' . $total . '</strong> | PDF: <strong>' . $pdf . '</strong> | CSV: <strong>' . $csv . '</strong></p>';

                // Mostra messaggio diagnostico se presente
                if (isset($_GET['diag_msg'])) {
                    $diag_msg = esc_html(urldecode($_GET['diag_msg']));
                    $stats_html .= '<p style="color: #856404;"><strong>Info:</strong> ' . $diag_msg . '</p>';
                }

                if ($skipped_already > 0 || $skipped_sender > 0 || $skipped_subject > 0 || $skipped_ext > 0) {
                    $stats_html .= '<p style="color: #856404;">';
                    if ($skipped_already > 0) $stats_html .= 'Gi&agrave; elaborate: <strong>' . $skipped_already . '</strong> ';
                    if ($skipped_sender > 0) $stats_html .= '| Scartate per mittente: <strong>' . $skipped_sender . '</strong> ';
                    if ($skipped_subject > 0) $stats_html .= '| Scartate per oggetto: <strong>' . $skipped_subject . '</strong> ';
                    if ($skipped_ext > 0) $stats_html .= '| Scartate per estensione: <strong>' . $skipped_ext . '</strong> ';
                    $stats_html .= '</p>';
                }

                $notice = '<div class="notice notice-success is-dismissible">' . $stats_html . '</div>';
            } else {
                $error_msg = isset($_GET['error_msg']) ? urldecode($_GET['error_msg']) : 'Elaborazione fallita';
                $notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_msg) . '</p></div>';
            }
        }

        ?>
        <div class="wrap msg-extractor-admin">
            <h1>Estrattore Email Glovo - Configurazione</h1>

            <?php echo $notice; ?>

            <?php if (!$reader->is_imap_available()): ?>
                <div class="notice notice-error">
                    <h3>⚠️ Estensione IMAP non disponibile</h3>
                    <p>L'estensione PHP IMAP non è installata o abilitata sul server. Contatta il tuo hosting provider per abilitarla.</p>
                </div>
            <?php else: ?>

            <div class="msg-admin-container">
                <!-- Sezione Configurazione -->
                <div class="msg-admin-card">
                    <h2>⚙️ Configurazione Account Email</h2>
                    <p class="description">Configura l'account email da cui estrarre automaticamente gli allegati PDF e CSV.</p>

                    <form method="post" action="options.php">
                        <?php
                        settings_fields('msg_extractor_settings');
                        do_settings_sections('msg_extractor_settings');
                        ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="email_server">Server IMAP</label></th>
                                <td>
                                    <input type="text"
                                           id="email_server"
                                           name="<?php echo $this->option_name; ?>[server]"
                                           value="<?php echo esc_attr($config['server'] ?? ''); ?>"
                                           class="regular-text"
                                           placeholder="es. imap.gmail.com"
                                           required>
                                    <p class="description">Server IMAP del tuo provider email</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="email_port">Porta</label></th>
                                <td>
                                    <input type="number"
                                           id="email_port"
                                           name="<?php echo $this->option_name; ?>[port]"
                                           value="<?php echo esc_attr($config['port'] ?? '993'); ?>"
                                           class="small-text"
                                           required>
                                    <p class="description">Solitamente 993 per IMAP con SSL</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="email_username">Username/Email</label></th>
                                <td>
                                    <input type="email"
                                           id="email_username"
                                           name="<?php echo $this->option_name; ?>[username]"
                                           value="<?php echo esc_attr($config['username'] ?? ''); ?>"
                                           class="regular-text"
                                           placeholder="tuo@email.com"
                                           required>
                                    <p class="description">Indirizzo email completo</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="email_password">Password</label></th>
                                <td>
                                    <input type="password"
                                           id="email_password"
                                           name="<?php echo $this->option_name; ?>[password]"
                                           value="<?php echo esc_attr($config['password'] ?? ''); ?>"
                                           class="regular-text"
                                           placeholder="Password o App Password"
                                           required>
                                    <p class="description">Per Gmail, usa "App Password" dalle impostazioni di sicurezza</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Opzioni</th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="checkbox"
                                                   name="<?php echo $this->option_name; ?>[ssl]"
                                                   <?php checked(!empty($config['ssl']), true); ?>>
                                            Usa SSL/TLS (raccomandato)
                                        </label>
                                        <br>
                                        <label>
                                            <input type="checkbox"
                                                   name="<?php echo $this->option_name; ?>[mark_as_read]"
                                                   <?php checked(!empty($config['mark_as_read']), true); ?>>
                                            Marca email come lette dopo l'elaborazione
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>

                        </table>

                        <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">

                        <h3 style="margin-top: 30px;">⏱️ Lettura Automatica Email</h3>
                        <p class="description">Configura la frequenza con cui il plugin controlla automaticamente la casella email.</p>

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="cron_frequency">Frequenza Controllo</label></th>
                                <td>
                                    <select id="cron_frequency" name="<?php echo $this->option_name; ?>[cron_frequency]">
                                        <?php
                                        $current_freq = !empty($config['cron_frequency']) ? $config['cron_frequency'] : 'disabled';
                                        $frequencies = array(
                                            'disabled'         => 'Disabilitato (solo manuale)',
                                            'every_5_minutes'  => 'Ogni 5 minuti',
                                            'every_15_minutes' => 'Ogni 15 minuti',
                                            'every_30_minutes' => 'Ogni 30 minuti',
                                            'hourly'           => 'Ogni ora',
                                            'twicedaily'       => 'Due volte al giorno',
                                            'daily'            => 'Una volta al giorno',
                                        );
                                        foreach ($frequencies as $value => $label):
                                        ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($current_freq, $value); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php
                                    $next_run = wp_next_scheduled('msg_extractor_cron_hook');
                                    if ($next_run) {
                                        echo '<p class="description" style="color: green;">Prossimo controllo: <strong>' . date_i18n('d/m/Y H:i:s', $next_run + (get_option('gmt_offset') * 3600)) . '</strong></p>';
                                    } else {
                                        echo '<p class="description">Il controllo automatico non è attivo.</p>';
                                    }
                                    ?>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="cron_batch_limit">Email per ciclo</label></th>
                                <td>
                                    <input type="number"
                                           id="cron_batch_limit"
                                           name="<?php echo $this->option_name; ?>[cron_batch_limit]"
                                           value="<?php echo esc_attr($config['cron_batch_limit'] ?? '50'); ?>"
                                           class="small-text"
                                           min="1"
                                           max="50">
                                    <p class="description">
                                        Numero massimo di email elaborate per ogni ciclo automatico.<br>
                                        <strong>Default:</strong> 50 (abbassa solo se il server va in timeout)
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">

                        <h3 style="margin-top: 30px;">🔒 Regole di Filtraggio Email</h3>
                        <p class="description">Configura i criteri per determinare quali email sono attendibili e quali allegati scaricare.</p>

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="trusted_senders">Mittenti Attendibili</label></th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="checkbox"
                                                   name="<?php echo $this->option_name; ?>[enable_sender_filter]"
                                                   id="enable_sender_filter"
                                                   <?php checked(!empty($config['enable_sender_filter']), true); ?>>
                                            Abilita filtro mittenti (elabora solo email da mittenti attendibili)
                                        </label>
                                    </fieldset>
                                    <textarea id="trusted_senders"
                                              name="<?php echo $this->option_name; ?>[trusted_senders]"
                                              rows="5"
                                              class="large-text code"
                                              placeholder="esempio1@azienda.com&#10;esempio2@provider.it&#10;noreply@servizio.com"><?php
                                        if (!empty($config['trusted_senders'])) {
                                            echo esc_textarea(implode("\n", $config['trusted_senders']));
                                        }
                                    ?></textarea>
                                    <p class="description">
                                        <strong>Un indirizzo email per riga.</strong> Solo le email da questi mittenti verranno processate se il filtro è abilitato.<br>
                                        Se disabilitato o lasciato vuoto, verranno processate tutte le email non lette.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="allowed_extensions">Estensioni File Ammesse</label></th>
                                <td>
                                    <input type="text"
                                           id="allowed_extensions"
                                           name="<?php echo $this->option_name; ?>[allowed_extensions]"
                                           value="<?php
                                               if (!empty($config['allowed_extensions'])) {
                                                   echo esc_attr(implode(', ', $config['allowed_extensions']));
                                               } else {
                                                   echo 'pdf, csv';
                                               }
                                           ?>"
                                           class="regular-text"
                                           placeholder="pdf, csv, xlsx, doc">
                                    <p class="description">
                                        Estensioni separate da virgola. Solo i file con queste estensioni verranno scaricati.<br>
                                        <strong>Default:</strong> pdf, csv
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="subject_keywords">Parole Chiave Oggetto</label></th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="checkbox"
                                                   name="<?php echo $this->option_name; ?>[enable_subject_filter]"
                                                   id="enable_subject_filter"
                                                   <?php checked(!empty($config['enable_subject_filter']), true); ?>>
                                            Abilita filtro oggetto (elabora solo email con queste parole nell'oggetto)
                                        </label>
                                    </fieldset>
                                    <input type="text"
                                           id="subject_keywords"
                                           name="<?php echo $this->option_name; ?>[subject_keywords]"
                                           value="<?php
                                               if (!empty($config['subject_keywords'])) {
                                                   echo esc_attr(implode(', ', $config['subject_keywords']));
                                               }
                                           ?>"
                                           class="regular-text"
                                           placeholder="fattura, ordine, documento">
                                    <p class="description">
                                        Parole chiave separate da virgola. Se abilitato, verranno processate solo le email che contengono almeno una di queste parole nell'oggetto (case-insensitive).<br>
                                        Se disabilitato o lasciato vuoto, non verrà applicato alcun filtro sull'oggetto.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">

                        <h3 style="margin-top: 30px;">📬 Notifiche Report</h3>
                        <p class="description">Invia un report via email con il riepilogo degli allegati estratti dopo ogni elaborazione.</p>

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="report_recipients">Destinatari Report</label></th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="checkbox"
                                                   name="<?php echo $this->option_name; ?>[enable_report]"
                                                   id="enable_report"
                                                   <?php checked(!empty($config['enable_report']), true); ?>>
                                            Abilita invio report via email
                                        </label>
                                    </fieldset>
                                    <textarea id="report_recipients"
                                              name="<?php echo $this->option_name; ?>[report_recipients]"
                                              rows="3"
                                              class="large-text code"
                                              placeholder="admin@azienda.com&#10;responsabile@azienda.com"><?php
                                        if (!empty($config['report_recipients'])) {
                                            echo esc_textarea(implode("\n", $config['report_recipients']));
                                        }
                                    ?></textarea>
                                    <p class="description">
                                        <strong>Un indirizzo email per riga.</strong> Il report viene inviato solo se sono stati trovati allegati.<br>
                                        Se disabilitato o senza destinatari, nessuna notifica viene inviata.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button('Salva Configurazione', 'primary'); ?>
                    </form>
                </div>

                <!-- Sezione Test & Elaborazione -->
                <?php if (!empty($config['server'])): ?>
                <div class="msg-admin-card">
                    <h2>🧪 Test & Elaborazione</h2>

                    <div class="msg-admin-actions">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block;">
                            <input type="hidden" name="action" value="test_email_connection">
                            <?php wp_nonce_field('test_email_connection', 'test_nonce'); ?>
                            <button type="submit" class="button button-secondary">
                                🔌 Testa Connessione
                            </button>
                        </form>

                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block; margin-left: 10px;">
                            <input type="hidden" name="action" value="process_emails_now">
                            <?php wp_nonce_field('process_emails_now', 'process_nonce'); ?>
                            <button type="submit" class="button button-primary">
                                📧 Leggi Email ed Estrai Allegati
                            </button>
                        </form>

                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block; margin-left: 10px;">
                            <input type="hidden" name="action" value="reset_processed_emails">
                            <?php wp_nonce_field('reset_processed_emails', 'reset_nonce'); ?>
                            <button type="submit" class="button" onclick="return confirm('Vuoi resettare la lista delle email gi\u00e0 elaborate? Alla prossima elaborazione verranno riprocessate tutte le email.');">
                                🔄 Reset Email Elaborate
                            </button>
                        </form>
                    </div>

                    <?php
                    $uid_reader = new Email_Auto_Reader_V7();
                    $processed_count = $uid_reader->get_processed_uids_count();
                    ?>
                    <p class="description">
                        Testa prima la connessione, poi processa le email per estrarre gli allegati.<br>
                        Email gi&agrave; elaborate in memoria: <strong><?php echo $processed_count; ?></strong>
                        <?php if ($processed_count > 0): ?>
                         — usa "Reset Email Elaborate" per riprocessare tutto da zero.
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>

                <!-- Info Box -->
                <div class="msg-admin-card msg-admin-info">
                    <h3>💡 Informazioni Utili</h3>
                    <ul>
                        <li><strong>Gmail:</strong> Server: imap.gmail.com, Porta: 993, usa "App Password" (Impostazioni → Sicurezza → Verifica in 2 passaggi → App Password)</li>
                        <li><strong>Outlook/Office 365:</strong> Server: outlook.office365.com, Porta: 993</li>
                        <li><strong>Yahoo:</strong> Server: imap.mail.yahoo.com, Porta: 993</li>
                        <li>Vengono estratti <strong>solo allegati PDF e CSV</strong> dalle email non ancora elaborate</li>
                        <li>Gli allegati vengono salvati in <code>/wp-content/uploads/msg-extracted/pdf/</code> e <code>/csv/</code></li>
                    </ul>
                </div>

            </div>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render pagina upload MSG
     */
    public function render_upload_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>Carica File MSG</h1>';
        echo '<p>Usa lo shortcode <code>[msg_uploader]</code> in qualsiasi pagina o post per mostrare l\'interfaccia di caricamento file MSG.</p>';
        echo '<p>In alternativa, puoi usare l\'interfaccia qui sotto:</p>';
        echo do_shortcode('[msg_uploader]');
        echo '</div>';
    }

    /**
     * Gestisce test connessione
     */
    public function handle_test_connection() {
        check_admin_referer('test_email_connection', 'test_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Non hai i permessi necessari');
        }

        $config = get_option($this->option_name, array());
        if (empty($config)) {
            wp_redirect(add_query_arg(array(
                'page' => 'msg-extractor',
                'test_result' => 'error',
                'error_msg' => urlencode('Configurazione non trovata')
            ), admin_url('admin.php')));
            exit;
        }

        // Decodifica password
        if (!empty($config['password'])) {
            $config['password'] = base64_decode($config['password']);
        }

        $reader = new Email_Auto_Reader_V7();
        $result = $reader->test_connection(
            $config['server'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['ssl']
        );

        if ($result['success']) {
            wp_redirect(add_query_arg(array(
                'page' => 'msg-extractor',
                'test_result' => 'success',
                'test_msg' => urlencode($result['message'])
            ), admin_url('admin.php')));
        } else {
            wp_redirect(add_query_arg(array(
                'page' => 'msg-extractor',
                'test_result' => 'error',
                'error_msg' => urlencode($result['error'])
            ), admin_url('admin.php')));
        }
        exit;
    }

    /**
     * Gestisce reset email elaborate
     */
    public function handle_reset_processed() {
        check_admin_referer('reset_processed_emails', 'reset_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Non hai i permessi necessari');
        }

        $reader = new Email_Auto_Reader_V7();
        $reader->reset_processed_uids();

        wp_redirect(add_query_arg(array(
            'page' => 'msg-extractor',
            'reset_result' => 'success'
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * Gestisce elaborazione email
     */
    public function handle_process_emails() {
        check_admin_referer('process_emails_now', 'process_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Non hai i permessi necessari');
        }

        $config = get_option($this->option_name, array());
        if (empty($config)) {
            wp_redirect(add_query_arg(array(
                'page' => 'msg-extractor',
                'process_result' => 'error',
                'error_msg' => urlencode('Configurazione non trovata')
            ), admin_url('admin.php')));
            exit;
        }

        // Decodifica password
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
            wp_redirect(add_query_arg(array(
                'page' => 'msg-extractor',
                'process_result' => 'error',
                'error_msg' => urlencode($connect_result['error'])
            ), admin_url('admin.php')));
            exit;
        }

        // Prepara filtri
        $filters = array(
            'trusted_senders' => !empty($config['trusted_senders']) ? $config['trusted_senders'] : array(),
            'allowed_extensions' => !empty($config['allowed_extensions']) ? $config['allowed_extensions'] : array('pdf', 'csv'),
            'subject_keywords' => !empty($config['subject_keywords']) ? $config['subject_keywords'] : array(),
            'enable_sender_filter' => !empty($config['enable_sender_filter']),
            'enable_subject_filter' => !empty($config['enable_subject_filter'])
        );

        $process_result = $reader->process_emails($config['mark_as_read'], $filters);

        // Invia report se abilitato
        if ($process_result['success'] && !empty($config['enable_report']) && !empty($config['report_recipients'])) {
            $reader->send_report($process_result['stats'], $config['report_recipients']);
        }

        if ($process_result['success']) {
            $redirect_args = array(
                'page' => 'msg-extractor',
                'process_result' => 'success',
                'emails' => $process_result['stats']['emails'],
                'pdf' => $process_result['stats']['pdf'],
                'csv' => $process_result['stats']['csv'],
                'total' => $process_result['stats']['total_attachments']
            );

            // Aggiungi statistiche filtri se presenti
            if (isset($process_result['stats']['skipped_sender'])) {
                $redirect_args['skipped_sender'] = $process_result['stats']['skipped_sender'];
            }
            if (isset($process_result['stats']['skipped_subject'])) {
                $redirect_args['skipped_subject'] = $process_result['stats']['skipped_subject'];
            }
            if (isset($process_result['stats']['skipped_ext'])) {
                $redirect_args['skipped_ext'] = $process_result['stats']['skipped_ext'];
            }
            if (isset($process_result['stats']['skipped_already'])) {
                $redirect_args['skipped_already'] = $process_result['stats']['skipped_already'];
            }

            // Messaggio diagnostico (es. "Nessuna email non letta trovata. (Casella contiene X email totali)")
            if (!empty($process_result['message'])) {
                $redirect_args['diag_msg'] = urlencode($process_result['message']);
            }

            wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        } else {
            wp_redirect(add_query_arg(array(
                'page' => 'msg-extractor',
                'process_result' => 'error',
                'error_msg' => urlencode($process_result['error'])
            ), admin_url('admin.php')));
        }
        exit;
    }
}
