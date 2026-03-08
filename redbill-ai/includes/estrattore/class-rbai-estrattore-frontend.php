<?php
/**
 * RBAI Estrattore Frontend — Shortcode [msg_uploader]
 *
 * Interfaccia drag & drop per caricare file .msg e estrarne
 * gli allegati PDF/CSV nella directory del tenant corrente.
 *
 * @package Redbill_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RBAI_Estrattore_Frontend {

    public function __construct() {
        add_shortcode( 'msg_uploader', [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Aumenta limiti PHP per upload multipli
        @ini_set( 'max_file_uploads',   '100' );
        @ini_set( 'max_input_vars',     '3000' );
        @ini_set( 'post_max_size',      '100M' );
        @ini_set( 'upload_max_filesize','50M' );
    }

    public function enqueue_assets(): void {
        if ( ! is_singular() && ! is_page() ) return;

        wp_enqueue_style(
            'rbai-frontend',
            RBAI_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            RBAI_VERSION
        );

        wp_enqueue_script(
            'rbai-frontend',
            RBAI_PLUGIN_URL . 'assets/js/frontend.js',
            [],
            RBAI_VERSION,
            true
        );
    }

    public function render_shortcode( $atts ): string {
        $tenant = RBAI_Tenant::current();

        if ( ! $tenant ) {
            return '<p class="rbai-notice">' . esc_html__( 'Effettua il login per accedere a questa funzione.', 'redbill-ai' ) . '</p>';
        }

        if ( ! $tenant->is_active() ) {
            return '<p class="rbai-notice rbai-notice--warning">' . esc_html__( 'Account sospeso o in attesa di approvazione.', 'redbill-ai' ) . '</p>';
        }

        $result = null;

        if (
            isset( $_POST['msg_frontend_submit'], $_FILES['msg_files'] ) &&
            wp_verify_nonce( $_POST['msg_nonce'] ?? '', 'rbai_msg_upload_' . $tenant->get_id() )
        ) {
            $result = $this->process_files( $_FILES['msg_files'], $tenant );
        }

        ob_start();
        ?>
        <div class="msg-pro-container">
            <div class="msg-pro-header">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" style="margin-right:10px;color:#f39c12;">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                    <line x1="12" y1="22.08" x2="12" y2="12"/>
                </svg>
                <span><?php esc_html_e( 'MSG Allegati Extractor', 'redbill-ai' ); ?></span>
            </div>

            <div class="msg-pro-body">
                <div class="msg-section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6a89cc" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px;">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                    </svg>
                    <?php esc_html_e( 'Carica File MSG', 'redbill-ai' ); ?>
                </div>
                <p class="msg-description"><?php esc_html_e( 'Carica i file .msg di Outlook per estrarre automaticamente gli allegati PDF e CSV.', 'redbill-ai' ); ?></p>

                <form method="post" enctype="multipart/form-data" id="msg-form">
                    <?php wp_nonce_field( 'rbai_msg_upload_' . $tenant->get_id(), 'msg_nonce' ); ?>

                    <div class="msg-dropzone" id="msg-drop-area">
                        <div class="msg-drop-content">
                            <div class="cloud-icon-wrap">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#a4b0be"
                                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>
                                </svg>
                            </div>
                            <h4 class="drop-title"><?php esc_html_e( 'Trascina qui i file MSG', 'redbill-ai' ); ?></h4>
                            <p class="drop-subtitle"><?php esc_html_e( 'oppure', 'redbill-ai' ); ?></p>
                            <button type="button" class="select-btn" id="btn-browse">
                                <?php esc_html_e( 'Seleziona File (anche multipli)', 'redbill-ai' ); ?>
                            </button>
                            <p id="file-status" class="file-status-text"></p>
                        </div>
                        <input type="file" name="msg_files[]" id="msg-file-input" multiple accept=".msg" style="display:none;">
                    </div>

                    <div id="action-area" style="display:none;text-align:center;margin-top:20px;">
                        <button type="submit" name="msg_frontend_submit" class="process-btn">
                            <?php esc_html_e( 'Estrai Allegati Ora', 'redbill-ai' ); ?>
                        </button>
                    </div>
                </form>
            </div>

            <div class="msg-info-box">
                <div class="info-title"><?php esc_html_e( 'ℹ️ Limiti Server', 'redbill-ai' ); ?></div>
                <div class="info-row"><?php esc_html_e( 'Max file caricabili:', 'redbill-ai' ); ?> <strong><?php echo esc_html( ini_get( 'max_file_uploads' ) ); ?></strong></div>
                <div class="info-row"><?php esc_html_e( 'Dimensione max upload:', 'redbill-ai' ); ?> <strong><?php echo esc_html( ini_get( 'upload_max_filesize' ) ); ?></strong></div>
                <div class="info-row"><?php esc_html_e( 'Dimensione max POST:', 'redbill-ai' ); ?> <strong><?php echo esc_html( ini_get( 'post_max_size' ) ); ?></strong></div>
            </div>

            <?php if ( $result ) : ?>
            <div class="msg-result-box">
                <div class="result-header">&#10003; <?php esc_html_e( 'Elaborazione completata!', 'redbill-ai' ); ?></div>
                <div class="result-row"><?php esc_html_e( 'File elaborati:', 'redbill-ai' ); ?> <strong><?php echo (int) $result['total']; ?></strong></div>
                <div class="result-row highlight"><?php esc_html_e( 'PDF estratti:', 'redbill-ai' ); ?> <strong><?php echo (int) $result['pdf']; ?></strong></div>
                <div class="result-row highlight"><?php esc_html_e( 'CSV estratti:', 'redbill-ai' ); ?> <strong><?php echo (int) $result['csv']; ?></strong></div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function process_files( array $files, RBAI_Tenant $tenant ): ?array {
        $base_dir = $tenant->get_upload_dir();
        $pdf_dir  = $base_dir . 'pdf/';
        $csv_dir  = $base_dir . 'csv/';

        wp_mkdir_p( $pdf_dir );
        wp_mkdir_p( $csv_dir );

        $count = count( $files['name'] );
        if ( $count === 0 || empty( $files['name'][0] ) ) return null;

        $stats = [ 'total' => 0, 'pdf' => 0, 'csv' => 0 ];
        @set_time_limit( 300 );

        for ( $i = 0; $i < $count; $i++ ) {
            if ( $files['error'][ $i ] !== UPLOAD_ERR_OK ) continue;
            $stats['total']++;

            $tmp    = $files['tmp_name'][ $i ];
            $parser = new MSG_Universal_Parser_V7( $tmp );

            if ( ! empty( $parser->attachments ) ) {
                foreach ( $parser->attachments as $att ) {
                    $ext      = strtolower( pathinfo( $att['name'], PATHINFO_EXTENSION ) );
                    $dest_dir = '';

                    if ( $ext === 'pdf' ) $dest_dir = $pdf_dir;
                    if ( $ext === 'csv' ) $dest_dir = $csv_dir;
                    if ( ! $dest_dir ) continue;

                    $clean     = sanitize_file_name( $att['name'] );
                    $dest_path = $this->unique_filename( $dest_dir, $clean );

                    if ( file_put_contents( $dest_path, $att['data'] ) ) {
                        if ( $ext === 'pdf' ) $stats['pdf']++;
                        if ( $ext === 'csv' ) $stats['csv']++;
                    }
                }
            }
        }

        return $stats;
    }

    private function unique_filename( string $dir, string $filename ): string {
        $info = pathinfo( $filename );
        $name = $info['filename'];
        $ext  = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
        $path = $dir . $filename;
        $c    = 1;
        while ( file_exists( $path ) ) {
            $path = $dir . $name . '-' . $c . $ext;
            $c++;
        }
        return $path;
    }
}
