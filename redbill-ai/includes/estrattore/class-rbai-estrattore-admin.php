<?php
/**
 * RBAI Estrattore Admin — Configurazione IMAP per-tenant
 *
 * Ogni tenant può configurare il proprio account IMAP, testare la connessione
 * e avviare l'elaborazione manuale dall'area "Il Mio Account".
 *
 * Super-admin può vedere e sovrascrivere la config di qualsiasi tenant.
 *
 * @package Redbill_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RBAI_Estrattore_Admin {

    public function __construct() {
        add_action( 'admin_post_rbai_save_imap_config',   [ $this, 'handle_save_config' ] );
        add_action( 'admin_post_rbai_test_imap',          [ $this, 'handle_test_connection' ] );
        add_action( 'admin_post_rbai_process_emails',     [ $this, 'handle_process_emails' ] );
        add_action( 'admin_post_rbai_reset_email_uids',   [ $this, 'handle_reset_uids' ] );
    }

    // -------------------------------------------------------------------------
    // Render pagina IMAP self-service (tenant)
    // -------------------------------------------------------------------------

    /**
     * Renderizza la pagina di configurazione IMAP per un tenant.
     * Chiamata da RBAI_Plugin (menu "Il Mio Account → Impostazioni IMAP").
     * Può essere usata anche da un super-admin con ?tenant_id=X
     */
    public function render_imap_page(): void {
        $tenant = $this->resolve_tenant_for_admin();
        if ( ! $tenant ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Nessun account tenant associato.', 'redbill-ai' ) . '</p></div>';
            return;
        }

        $config = $tenant->get_email_config();
        if ( ! empty( $config['password'] ) ) {
            $config['password'] = ''; // Non esporre mai la password
        }

        $reader          = new RBAI_Email_Reader( $tenant );
        $processed_count = $reader->get_processed_uids_count();

        $notice = $this->get_notice_html();

        ?>
        <div class="wrap rbai-admin-wrap">
            <h1><?php esc_html_e( 'Impostazioni IMAP', 'redbill-ai' ); ?></h1>
            <?php echo $notice; ?>

            <?php if ( ! $reader->is_imap_available() ) : ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e( 'L\'estensione PHP IMAP non è disponibile. Contatta il supporto.', 'redbill-ai' ); ?></p>
                </div>
            <?php else : ?>

            <div class="rbai-admin-grid">

                <!-- Configurazione IMAP -->
                <div class="rbai-card">
                    <h2><?php esc_html_e( '⚙️ Configurazione Account Email', 'redbill-ai' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Inserisci i dati IMAP dell\'account email da cui estrarre automaticamente gli allegati.', 'redbill-ai' ); ?></p>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="rbai_save_imap_config">
                        <input type="hidden" name="tenant_id" value="<?php echo esc_attr( $tenant->get_id() ); ?>">
                        <?php wp_nonce_field( 'rbai_save_imap_' . $tenant->get_id(), 'rbai_nonce' ); ?>

                        <table class="form-table">
                            <tr>
                                <th><label for="imap_server"><?php esc_html_e( 'Server IMAP', 'redbill-ai' ); ?></label></th>
                                <td>
                                    <input type="text" id="imap_server" name="imap[server]"
                                           value="<?php echo esc_attr( $config['server'] ?? '' ); ?>"
                                           class="regular-text" placeholder="imap.gmail.com" required>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="imap_port"><?php esc_html_e( 'Porta', 'redbill-ai' ); ?></label></th>
                                <td>
                                    <input type="number" id="imap_port" name="imap[port]"
                                           value="<?php echo esc_attr( $config['port'] ?? '993' ); ?>"
                                           class="small-text" required>
                                    <p class="description"><?php esc_html_e( 'Solitamente 993 per IMAP con SSL', 'redbill-ai' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="imap_username"><?php esc_html_e( 'Username / Email', 'redbill-ai' ); ?></label></th>
                                <td>
                                    <input type="email" id="imap_username" name="imap[username]"
                                           value="<?php echo esc_attr( $config['username'] ?? '' ); ?>"
                                           class="regular-text" placeholder="tuo@email.com" required>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="imap_password"><?php esc_html_e( 'Password', 'redbill-ai' ); ?></label></th>
                                <td>
                                    <input type="password" id="imap_password" name="imap[password]"
                                           value="" class="regular-text"
                                           placeholder="<?php esc_attr_e( 'Lascia vuoto per mantenere la password attuale', 'redbill-ai' ); ?>">
                                    <p class="description"><?php esc_html_e( 'Per Gmail usa "App Password" dalle impostazioni di sicurezza', 'redbill-ai' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Opzioni', 'redbill-ai' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="imap[ssl]" value="1"
                                               <?php checked( ! empty( $config['ssl'] ) ); ?>>
                                        <?php esc_html_e( 'Usa SSL/TLS (raccomandato)', 'redbill-ai' ); ?>
                                    </label><br>
                                    <label>
                                        <input type="checkbox" name="imap[mark_as_read]" value="1"
                                               <?php checked( ! empty( $config['mark_as_read'] ) ); ?>>
                                        <?php esc_html_e( 'Marca email come lette dopo l\'elaborazione', 'redbill-ai' ); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <hr style="margin:24px 0;border-top:1px solid #ddd;">
                        <h3><?php esc_html_e( '⏱️ Frequenza Controllo Automatico', 'redbill-ai' ); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th><label for="cron_frequency"><?php esc_html_e( 'Frequenza', 'redbill-ai' ); ?></label></th>
                                <td>
                                    <select id="cron_frequency" name="imap[cron_frequency]">
                                        <?php
                                        $current_freq = $config['cron_frequency'] ?? 'rbai_every_15min';
                                        $frequencies  = [
                                            'disabled'          => __( 'Disabilitato (solo manuale)', 'redbill-ai' ),
                                            'rbai_every_5min'   => __( 'Ogni 5 minuti', 'redbill-ai' ),
                                            'rbai_every_15min'  => __( 'Ogni 15 minuti', 'redbill-ai' ),
                                            'rbai_every_30min'  => __( 'Ogni 30 minuti', 'redbill-ai' ),
                                            'hourly'            => __( 'Ogni ora', 'redbill-ai' ),
                                            'twicedaily'        => __( 'Due volte al giorno', 'redbill-ai' ),
                                            'daily'             => __( 'Una volta al giorno', 'redbill-ai' ),
                                        ];
                                        foreach ( $frequencies as $val => $label ) {
                                            printf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr( $val ),
                                                selected( $current_freq, $val, false ),
                                                esc_html( $label )
                                            );
                                        }
                                        ?>
                                    </select>
                                    <?php
                                    $hook      = 'rbai_email_check_' . $tenant->get_id();
                                    $next_run  = wp_next_scheduled( $hook );
                                    if ( $next_run ) {
                                        $next_fmt = date_i18n( 'd/m/Y H:i:s', $next_run + (int) ( get_option( 'gmt_offset' ) * 3600 ) );
                                        echo '<p class="description" style="color:green;">' . sprintf( esc_html__( 'Prossimo controllo: %s', 'redbill-ai' ), '<strong>' . esc_html( $next_fmt ) . '</strong>' ) . '</p>';
                                    } else {
                                        echo '<p class="description">' . esc_html__( 'Il controllo automatico non è attivo.', 'redbill-ai' ) . '</p>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="cron_batch_limit"><?php esc_html_e( 'Email per ciclo', 'redbill-ai' ); ?></label></th>
                                <td>
                                    <input type="number" id="cron_batch_limit" name="imap[cron_batch_limit]"
                                           value="<?php echo esc_attr( $config['cron_batch_limit'] ?? '50' ); ?>"
                                           class="small-text" min="1" max="200">
                                </td>
                            </tr>
                        </table>

                        <hr style="margin:24px 0;border-top:1px solid #ddd;">
                        <h3><?php esc_html_e( '🔒 Filtri Email', 'redbill-ai' ); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th><label for="trusted_senders"><?php esc_html_e( 'Mittenti Attendibili', 'redbill-ai' ); ?></label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="imap[enable_sender_filter]" value="1"
                                               <?php checked( ! empty( $config['enable_sender_filter'] ) ); ?>>
                                        <?php esc_html_e( 'Abilita filtro mittenti', 'redbill-ai' ); ?>
                                    </label>
                                    <br><br>
                                    <textarea id="trusted_senders" name="imap[trusted_senders]" rows="4"
                                              class="large-text code"
                                              placeholder="mittente1@email.com&#10;mittente2@email.com"><?php
                                        if ( ! empty( $config['trusted_senders'] ) ) {
                                            echo esc_textarea( implode( "\n", $config['trusted_senders'] ) );
                                        }
                                    ?></textarea>
                                    <p class="description"><?php esc_html_e( 'Un indirizzo per riga.', 'redbill-ai' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="subject_keywords"><?php esc_html_e( 'Parole Chiave Oggetto', 'redbill-ai' ); ?></label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="imap[enable_subject_filter]" value="1"
                                               <?php checked( ! empty( $config['enable_subject_filter'] ) ); ?>>
                                        <?php esc_html_e( 'Abilita filtro oggetto', 'redbill-ai' ); ?>
                                    </label>
                                    <br><br>
                                    <input type="text" id="subject_keywords" name="imap[subject_keywords]"
                                           value="<?php echo esc_attr( ! empty( $config['subject_keywords'] ) ? implode( ', ', $config['subject_keywords'] ) : '' ); ?>"
                                           class="regular-text" placeholder="fattura, ordine, documento">
                                    <p class="description"><?php esc_html_e( 'Parole separate da virgola.', 'redbill-ai' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="allowed_extensions"><?php esc_html_e( 'Estensioni Ammesse', 'redbill-ai' ); ?></label></th>
                                <td>
                                    <input type="text" id="allowed_extensions" name="imap[allowed_extensions]"
                                           value="<?php echo esc_attr( ! empty( $config['allowed_extensions'] ) ? implode( ', ', $config['allowed_extensions'] ) : 'pdf, csv' ); ?>"
                                           class="regular-text" placeholder="pdf, csv">
                                </td>
                            </tr>
                        </table>

                        <hr style="margin:24px 0;border-top:1px solid #ddd;">
                        <h3><?php esc_html_e( '📬 Notifiche Report', 'redbill-ai' ); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e( 'Destinatari', 'redbill-ai' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="imap[enable_report]" value="1"
                                               <?php checked( ! empty( $config['enable_report'] ) ); ?>>
                                        <?php esc_html_e( 'Invia report via email dopo ogni elaborazione', 'redbill-ai' ); ?>
                                    </label>
                                    <br><br>
                                    <textarea name="imap[report_recipients]" rows="3"
                                              class="large-text code"
                                              placeholder="admin@azienda.com"><?php
                                        if ( ! empty( $config['report_recipients'] ) ) {
                                            echo esc_textarea( implode( "\n", $config['report_recipients'] ) );
                                        }
                                    ?></textarea>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button( __( 'Salva Configurazione', 'redbill-ai' ) ); ?>
                    </form>
                </div>

                <!-- Test & Elaborazione -->
                <?php if ( ! empty( $config['server'] ) ) : ?>
                <div class="rbai-card">
                    <h2><?php esc_html_e( '🧪 Test & Elaborazione', 'redbill-ai' ); ?></h2>
                    <div class="rbai-actions">

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                            <input type="hidden" name="action" value="rbai_test_imap">
                            <input type="hidden" name="tenant_id" value="<?php echo esc_attr( $tenant->get_id() ); ?>">
                            <?php wp_nonce_field( 'rbai_test_imap_' . $tenant->get_id(), 'rbai_nonce' ); ?>
                            <button type="submit" class="button button-secondary">
                                <?php esc_html_e( '🔌 Testa Connessione', 'redbill-ai' ); ?>
                            </button>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:10px;">
                            <input type="hidden" name="action" value="rbai_process_emails">
                            <input type="hidden" name="tenant_id" value="<?php echo esc_attr( $tenant->get_id() ); ?>">
                            <?php wp_nonce_field( 'rbai_process_emails_' . $tenant->get_id(), 'rbai_nonce' ); ?>
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e( '📧 Leggi Email ed Estrai Allegati', 'redbill-ai' ); ?>
                            </button>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:10px;">
                            <input type="hidden" name="action" value="rbai_reset_email_uids">
                            <input type="hidden" name="tenant_id" value="<?php echo esc_attr( $tenant->get_id() ); ?>">
                            <?php wp_nonce_field( 'rbai_reset_email_uids_' . $tenant->get_id(), 'rbai_nonce' ); ?>
                            <button type="submit" class="button"
                                    onclick="return confirm('<?php esc_attr_e( 'Reset lista email elaborate?', 'redbill-ai' ); ?>');">
                                <?php esc_html_e( '🔄 Reset Email Elaborate', 'redbill-ai' ); ?>
                            </button>
                        </form>
                    </div>

                    <p class="description" style="margin-top:12px;">
                        <?php printf(
                            esc_html__( 'Email già elaborate in memoria: %s', 'redbill-ai' ),
                            '<strong>' . (int) $processed_count . '</strong>'
                        ); ?>
                    </p>
                </div>
                <?php endif; ?>

            </div><!-- .rbai-admin-grid -->

            <?php endif; // imap available ?>
        </div><!-- .wrap -->
        <?php
    }

    // -------------------------------------------------------------------------
    // Handlers admin-post
    // -------------------------------------------------------------------------

    public function handle_save_config(): void {
        $tenant_id = intval( $_POST['tenant_id'] ?? 0 );
        check_admin_referer( 'rbai_save_imap_' . $tenant_id, 'rbai_nonce' );

        $tenant = $this->get_tenant_for_action( $tenant_id );
        if ( ! $tenant ) wp_die( 'Accesso non autorizzato', 403 );

        $input  = $_POST['imap'] ?? [];
        $config = $this->sanitize_config( $input, $tenant->get_email_config() );

        // Rischedula cron se frequenza cambiata
        $old_freq = $tenant->get_email_config()['cron_frequency'] ?? 'disabled';
        if ( $config['cron_frequency'] !== $old_freq ) {
            $hook = 'rbai_email_check_' . $tenant->get_id();
            wp_clear_scheduled_hook( $hook );
            if ( $config['cron_frequency'] !== 'disabled' ) {
                wp_schedule_event( time(), $config['cron_frequency'], $hook );
            }
        }

        $tenant->save_email_config( $config );

        $this->redirect_back( $tenant, [ 'rbai_saved' => '1' ] );
    }

    public function handle_test_connection(): void {
        $tenant_id = intval( $_POST['tenant_id'] ?? 0 );
        check_admin_referer( 'rbai_test_imap_' . $tenant_id, 'rbai_nonce' );

        $tenant = $this->get_tenant_for_action( $tenant_id );
        if ( ! $tenant ) wp_die( 'Accesso non autorizzato', 403 );

        $config = $tenant->get_email_config();
        $reader = new RBAI_Email_Reader( $tenant );
        $result = $reader->test_connection(
            $config['server']   ?? '',
            intval( $config['port'] ?? 993 ),
            $config['username'] ?? '',
            $config['password'] ?? '',
            ! empty( $config['ssl'] )
        );

        if ( $result['success'] ) {
            $this->redirect_back( $tenant, [ 'rbai_test' => 'ok', 'test_msg' => urlencode( $result['message'] ) ] );
        } else {
            $this->redirect_back( $tenant, [ 'rbai_test' => 'error', 'error_msg' => urlencode( $result['error'] ) ] );
        }
    }

    public function handle_process_emails(): void {
        $tenant_id = intval( $_POST['tenant_id'] ?? 0 );
        check_admin_referer( 'rbai_process_emails_' . $tenant_id, 'rbai_nonce' );

        $tenant = $this->get_tenant_for_action( $tenant_id );
        if ( ! $tenant ) wp_die( 'Accesso non autorizzato', 403 );

        $config = $tenant->get_email_config();
        $reader = new RBAI_Email_Reader( $tenant );

        $connect = $reader->connect(
            $config['server']   ?? '',
            intval( $config['port'] ?? 993 ),
            $config['username'] ?? '',
            $config['password'] ?? '',
            ! empty( $config['ssl'] )
        );

        if ( ! $connect['success'] ) {
            $this->redirect_back( $tenant, [ 'rbai_process' => 'error', 'error_msg' => urlencode( $connect['error'] ) ] );
        }

        $filters = [
            'trusted_senders'       => $config['trusted_senders']       ?? [],
            'allowed_extensions'    => $config['allowed_extensions']    ?? [ 'pdf', 'csv' ],
            'subject_keywords'      => $config['subject_keywords']       ?? [],
            'enable_sender_filter'  => ! empty( $config['enable_sender_filter'] ),
            'enable_subject_filter' => ! empty( $config['enable_subject_filter'] ),
        ];

        $result = $reader->process_emails(
            ! empty( $config['mark_as_read'] ),
            $filters,
            intval( $config['cron_batch_limit'] ?? 50 )
        );

        if ( $result['success'] && ! empty( $config['enable_report'] ) && ! empty( $config['report_recipients'] ) ) {
            $reader->send_report( $result['stats'], $config['report_recipients'] );
        }

        if ( $result['success'] ) {
            $this->redirect_back( $tenant, [
                'rbai_process'  => 'ok',
                'emails'        => $result['stats']['emails'],
                'pdf'           => $result['stats']['pdf'],
                'csv'           => $result['stats']['csv'],
                'total'         => $result['stats']['total_attachments'],
                'diag_msg'      => urlencode( $result['message'] ?? '' ),
            ] );
        } else {
            $this->redirect_back( $tenant, [ 'rbai_process' => 'error', 'error_msg' => urlencode( $result['error'] ) ] );
        }
    }

    public function handle_reset_uids(): void {
        $tenant_id = intval( $_POST['tenant_id'] ?? 0 );
        check_admin_referer( 'rbai_reset_email_uids_' . $tenant_id, 'rbai_nonce' );

        $tenant = $this->get_tenant_for_action( $tenant_id );
        if ( ! $tenant ) wp_die( 'Accesso non autorizzato', 403 );

        $reader = new RBAI_Email_Reader( $tenant );
        $reader->reset_processed_uids();

        $this->redirect_back( $tenant, [ 'rbai_reset' => '1' ] );
    }

    // -------------------------------------------------------------------------
    // Helper privati
    // -------------------------------------------------------------------------

    private function get_notice_html(): string {
        if ( isset( $_GET['rbai_saved'] ) ) {
            return '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Configurazione salvata con successo!', 'redbill-ai' ) . '</p></div>';
        }
        if ( isset( $_GET['rbai_reset'] ) ) {
            return '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Lista email elaborate resettata.', 'redbill-ai' ) . '</p></div>';
        }
        if ( isset( $_GET['rbai_test'] ) ) {
            if ( $_GET['rbai_test'] === 'ok' ) {
                $msg = isset( $_GET['test_msg'] ) ? esc_html( urldecode( $_GET['test_msg'] ) ) : esc_html__( 'Connessione riuscita!', 'redbill-ai' );
                return '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
            } else {
                $msg = isset( $_GET['error_msg'] ) ? esc_html( urldecode( $_GET['error_msg'] ) ) : esc_html__( 'Test fallito.', 'redbill-ai' );
                return '<div class="notice notice-error is-dismissible"><p>' . $msg . '</p></div>';
            }
        }
        if ( isset( $_GET['rbai_process'] ) ) {
            if ( $_GET['rbai_process'] === 'ok' ) {
                $html  = '<p><strong>' . esc_html__( 'Elaborazione completata!', 'redbill-ai' ) . '</strong></p>';
                $html .= '<p>' . sprintf(
                    esc_html__( 'Email: %d | PDF: %d | CSV: %d | Allegati: %d', 'redbill-ai' ),
                    intval( $_GET['emails'] ?? 0 ),
                    intval( $_GET['pdf']    ?? 0 ),
                    intval( $_GET['csv']    ?? 0 ),
                    intval( $_GET['total']  ?? 0 )
                ) . '</p>';
                if ( ! empty( $_GET['diag_msg'] ) ) {
                    $html .= '<p style="color:#856404;">' . esc_html( urldecode( $_GET['diag_msg'] ) ) . '</p>';
                }
                return '<div class="notice notice-success is-dismissible">' . $html . '</div>';
            } else {
                $msg = isset( $_GET['error_msg'] ) ? esc_html( urldecode( $_GET['error_msg'] ) ) : esc_html__( 'Elaborazione fallita.', 'redbill-ai' );
                return '<div class="notice notice-error is-dismissible"><p>' . $msg . '</p></div>';
            }
        }
        return '';
    }

    /**
     * Risolve il tenant per la pagina admin.
     * Super-admin può usare ?tenant_id=X, altrimenti usa il tenant corrente.
     */
    private function resolve_tenant_for_admin(): ?RBAI_Tenant {
        if ( current_user_can( 'manage_options' ) && ! empty( $_GET['tenant_id'] ) ) {
            return RBAI_Tenant::for_id( intval( $_GET['tenant_id'] ) );
        }
        return RBAI_Tenant::current();
    }

    /**
     * Verifica che l'utente abbia il diritto di agire sul tenant specificato.
     */
    private function get_tenant_for_action( int $tenant_id ): ?RBAI_Tenant {
        if ( current_user_can( 'manage_options' ) ) {
            return RBAI_Tenant::for_id( $tenant_id );
        }
        $tenant = RBAI_Tenant::current();
        if ( $tenant && $tenant->get_id() === $tenant_id ) {
            return $tenant;
        }
        return null;
    }

    private function redirect_back( RBAI_Tenant $tenant, array $args ): void {
        $page = current_user_can( 'manage_options' ) ? 'rbai-tenant-imap' : 'rbai-my-imap';
        $url  = add_query_arg(
            array_merge( [ 'page' => $page ], $args ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    private function sanitize_config( array $input, array $existing ): array {
        $cfg = [];

        $cfg['server']   = sanitize_text_field( $input['server'] ?? '' );
        $cfg['port']     = intval( $input['port'] ?? 993 );
        $cfg['username'] = sanitize_email( $input['username'] ?? '' );

        // Password: aggiorna solo se fornita, altrimenti mantieni quella cifrata esistente
        if ( ! empty( $input['password'] ) ) {
            $cfg['password'] = rbai_encrypt( sanitize_text_field( $input['password'] ) );
        } else {
            $cfg['password'] = $existing['password'] ?? '';
        }

        $cfg['ssl']          = ! empty( $input['ssl'] );
        $cfg['mark_as_read'] = ! empty( $input['mark_as_read'] );

        // Filtri
        $cfg['enable_sender_filter']  = ! empty( $input['enable_sender_filter'] );
        $cfg['enable_subject_filter'] = ! empty( $input['enable_subject_filter'] );

        $senders = array_filter( array_map( 'trim', explode( "\n", $input['trusted_senders'] ?? '' ) ) );
        $cfg['trusted_senders'] = array_values( array_filter( array_map( 'sanitize_email', $senders ), 'is_email' ) );

        $keywords = array_filter( array_map( 'trim', explode( ',', $input['subject_keywords'] ?? '' ) ) );
        $cfg['subject_keywords'] = array_values( array_map( 'sanitize_text_field', $keywords ) );

        $extensions = array_filter( array_map( fn( $e ) => ltrim( trim( strtolower( $e ) ), '.' ), explode( ',', $input['allowed_extensions'] ?? 'pdf, csv' ) ) );
        $cfg['allowed_extensions'] = ! empty( $extensions ) ? array_values( $extensions ) : [ 'pdf', 'csv' ];

        // Cron
        $valid_freqs = [ 'disabled', 'rbai_every_5min', 'rbai_every_15min', 'rbai_every_30min', 'hourly', 'twicedaily', 'daily' ];
        $cfg['cron_frequency']  = in_array( $input['cron_frequency'] ?? '', $valid_freqs, true ) ? $input['cron_frequency'] : 'rbai_every_15min';
        $cfg['cron_batch_limit']= max( 1, min( 200, intval( $input['cron_batch_limit'] ?? 50 ) ) );

        // Report
        $cfg['enable_report']    = ! empty( $input['enable_report'] );
        $recipients = array_filter( array_map( 'trim', explode( "\n", $input['report_recipients'] ?? '' ) ) );
        $cfg['report_recipients'] = array_values( array_filter( array_map( 'sanitize_email', $recipients ), 'is_email' ) );

        return $cfg;
    }
}
