<?php
/**
 * RBAI Tools Admin — Pagina Strumenti (super-admin + tenant)
 *
 * Esecuzione manuale di PDF extractor e CSV importer via AJAX,
 * con log in tempo reale tramite polling.
 *
 * @package Redbill_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RBAI_Tools_Admin {

    public function __construct() {
        add_action( 'wp_ajax_rbai_run_pdf_extractor', [ $this, 'ajax_run_pdf_extractor' ] );
        add_action( 'wp_ajax_rbai_run_csv_importer',  [ $this, 'ajax_run_csv_importer' ] );
        add_action( 'admin_enqueue_scripts',           [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'rbai' ) === false ) return;

        wp_enqueue_script(
            'rbai-tools',
            RBAI_PLUGIN_URL . 'assets/js/tools.js',
            [ 'jquery' ],
            RBAI_VERSION,
            true
        );

        wp_localize_script( 'rbai-tools', 'rbaiTools', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'rbai_tools' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Render pagine
    // -------------------------------------------------------------------------

    /**
     * Pagina strumenti per super-admin (selettore tenant + run entrambi).
     */
    public function render_superadmin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $tenants = RBAI_Tenant::get_all( 'active' );
        ?>
        <div class="wrap rbai-admin-wrap">
            <h1><?php esc_html_e( 'Strumenti Importazione', 'redbill-ai' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Esegui manualmente l\'estrazione PDF o l\'importazione CSV per un tenant.', 'redbill-ai' ); ?></p>

            <?php if ( empty( $tenants ) ) : ?>
                <p><?php esc_html_e( 'Nessun tenant attivo.', 'redbill-ai' ); ?></p>
            <?php else : ?>

            <div class="rbai-card">
                <table class="form-table">
                    <tr>
                        <th><label for="rbai-tenant-select"><?php esc_html_e( 'Seleziona Tenant', 'redbill-ai' ); ?></label></th>
                        <td>
                            <select id="rbai-tenant-select" style="min-width:200px;">
                                <?php foreach ( $tenants as $t ) : ?>
                                    <option value="<?php echo esc_attr( $t->get_id() ); ?>">
                                        <?php echo esc_html( $t->get_slug() . ' — ' . $t->get_display_name() ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php $this->render_tool_buttons( true ); ?>
            </div>

            <?php endif; ?>

            <?php $this->render_log_box(); ?>
        </div>
        <?php
    }

    /**
     * Pagina strumenti self-service per il tenant loggato.
     */
    public function render_tenant_page(): void {
        $tenant = RBAI_Tenant::current();
        if ( ! $tenant || ! $tenant->is_active() ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Accesso non autorizzato.', 'redbill-ai' ) . '</p></div>';
            return;
        }
        ?>
        <div class="wrap rbai-admin-wrap">
            <h1><?php esc_html_e( 'Strumenti Importazione', 'redbill-ai' ); ?></h1>
            <input type="hidden" id="rbai-tenant-select" value="<?php echo esc_attr( $tenant->get_id() ); ?>">

            <div class="rbai-card">
                <p class="description">
                    <?php printf(
                        esc_html__( 'Esegui manualmente PDF extractor o CSV importer per il tuo account (%s).', 'redbill-ai' ),
                        '<strong>' . esc_html( $tenant->get_slug() ) . '</strong>'
                    ); ?>
                </p>
                <?php $this->render_tool_buttons( false ); ?>
            </div>

            <?php $this->render_log_box(); ?>
        </div>
        <?php
    }

    private function render_tool_buttons( bool $is_admin ): void {
        ?>
        <div class="rbai-tools-actions" style="margin-top:16px;">

            <div class="rbai-tool-card">
                <h3><?php esc_html_e( 'Estrattore PDF', 'redbill-ai' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Elabora i file PDF nella cartella pdf/ e inserisce le fatture nel DB.', 'redbill-ai' ); ?></p>
                <button class="button button-primary" id="rbai-run-pdf" data-action="rbai_run_pdf_extractor">
                    <?php esc_html_e( '▶ Esegui PDF Extractor', 'redbill-ai' ); ?>
                </button>
            </div>

            <div class="rbai-tool-card" style="margin-top:20px;">
                <h3><?php esc_html_e( 'Importatore CSV', 'redbill-ai' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Importa i CSV di dettaglio ordini dalla cartella csv/.', 'redbill-ai' ); ?></p>
                <button class="button button-primary" id="rbai-run-csv" data-action="rbai_run_csv_importer">
                    <?php esc_html_e( '▶ Esegui CSV Importer', 'redbill-ai' ); ?>
                </button>
            </div>

        </div>
        <?php
    }

    private function render_log_box(): void {
        ?>
        <div class="rbai-card" id="rbai-log-box" style="margin-top:20px;display:none;">
            <h3><?php esc_html_e( 'Output Elaborazione', 'redbill-ai' ); ?></h3>
            <pre id="rbai-log-output" style="
                background:#1e1e1e;
                color:#d4d4d4;
                padding:16px;
                border-radius:4px;
                max-height:400px;
                overflow-y:auto;
                font-size:12px;
                line-height:1.6;
                white-space:pre-wrap;
                word-break:break-all;
            "></pre>
            <div id="rbai-log-stats" style="margin-top:12px;padding:12px;background:#f0f7ff;border-radius:4px;display:none;">
                <strong><?php esc_html_e( 'Riepilogo:', 'redbill-ai' ); ?></strong>
                <span id="rbai-log-stats-text"></span>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public function ajax_run_pdf_extractor(): void {
        check_ajax_referer( 'rbai_tools', 'nonce' );

        $tenant = $this->resolve_tenant();
        if ( ! $tenant ) {
            wp_send_json_error( [ 'message' => 'Tenant non autorizzato.' ], 403 );
        }

        @set_time_limit( 600 );

        $extractor = new RBAI_PDF_Extractor( $tenant );
        $result    = $extractor->run();

        wp_send_json_success( [
            'log'      => implode( "\n", $result['log'] ),
            'counters' => $result['counters'],
        ] );
    }

    public function ajax_run_csv_importer(): void {
        check_ajax_referer( 'rbai_tools', 'nonce' );

        $tenant = $this->resolve_tenant();
        if ( ! $tenant ) {
            wp_send_json_error( [ 'message' => 'Tenant non autorizzato.' ], 403 );
        }

        @set_time_limit( 600 );

        $importer = new RBAI_CSV_Importer( $tenant );
        $result   = $importer->run();

        wp_send_json_success( [
            'log'      => implode( "\n", $result['log'] ),
            'counters' => $result['counters'],
        ] );
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function resolve_tenant(): ?RBAI_Tenant {
        $tenant_id = intval( $_POST['tenant_id'] ?? 0 );

        if ( current_user_can( 'manage_options' ) && $tenant_id > 0 ) {
            return RBAI_Tenant::for_id( $tenant_id );
        }

        $tenant = RBAI_Tenant::current();
        if ( $tenant && $tenant->is_active() ) {
            return $tenant;
        }

        return null;
    }
}
