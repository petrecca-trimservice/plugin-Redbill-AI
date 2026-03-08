<?php
/**
 * Frontend Interface
 *
 * Interfaccia frontend per caricamento file MSG con drag & drop
 *
 * @package MSG_Extractor
 * @since 8.0
 */

if (!defined('ABSPATH')) exit;

class MSG_Extractor_Frontend_V7 {

    private $upload_dir;
    private $pdf_dir;
    private $csv_dir;

    public function __construct() {
        add_shortcode('msg_uploader', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

        // Aumenta i limiti PHP per permettere più di 20 file
        @ini_set('max_file_uploads', '100');
        @ini_set('max_input_vars', '3000');
        @ini_set('post_max_size', '100M');
        @ini_set('upload_max_filesize', '50M');

        $u = wp_upload_dir();
        $this->upload_dir = $u['basedir'] . '/msg-extracted';
        $this->pdf_dir = $this->upload_dir . '/pdf';
        $this->csv_dir = $this->upload_dir . '/csv';
    }

    /**
     * Carica assets CSS/JS frontend
     */
    public function enqueue_frontend_assets() {
        if (!is_singular() && !is_page()) {
            return;
        }

        wp_enqueue_style(
            'msg-extractor-frontend',
            plugins_url('../assets/css/frontend.css', __FILE__),
            array(),
            '8.0'
        );

        wp_enqueue_script(
            'msg-extractor-frontend',
            plugins_url('../assets/js/frontend.js', __FILE__),
            array(),
            '8.0',
            true
        );
    }

    /**
     * Render shortcode
     */
    public function render_shortcode($atts) {
        ob_start();

        $result = null;
        if (isset($_POST['msg_frontend_submit']) && isset($_FILES['msg_files']) && wp_verify_nonce($_POST['msg_nonce'], 'msg_upload_action')) {
            $result = $this->process_files($_FILES['msg_files']);
        }

        ?>

        <div class="msg-pro-container">
            <!-- Intestazione Card -->
            <div class="msg-pro-header">
                <!-- Icona Box SVG -->
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:10px; color:#f39c12;">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                    <line x1="12" y1="22.08" x2="12" y2="12"></line>
                </svg>
                <span>MSG Allegati Extractor</span>
            </div>

            <!-- Contenuto Card -->
            <div class="msg-pro-body">

                <div class="msg-section-title">
                    <!-- Icona Busta SVG -->
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6a89cc" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px;">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                        <polyline points="22,6 12,13 2,6"></polyline>
                    </svg>
                    Carica File MSG
                </div>
                <p class="msg-description">Carica i file .msg di Outlook per estrarre automaticamente gli allegati PDF e CSV.</p>

                <form method="post" enctype="multipart/form-data" id="msg-form">
                    <?php wp_nonce_field('msg_upload_action', 'msg_nonce'); ?>

                    <div class="msg-dropzone" id="msg-drop-area">
                        <div class="msg-drop-content">
                            <!-- Icona Nuvola SVG -->
                            <div class="cloud-icon-wrap">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#a4b0be" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"></path>
                                </svg>
                            </div>

                            <h4 class="drop-title">Trascina qui i file MSG</h4>
                            <p class="drop-subtitle">oppure</p>

                            <button type="button" class="select-btn" id="btn-browse">Seleziona File (anche multipli)</button>
                            <p id="file-status" class="file-status-text"></p>
                        </div>

                        <input type="file" name="msg_files[]" id="msg-file-input" multiple accept=".msg" style="display:none;">
                    </div>

                    <div id="action-area" style="display:none; text-align:center; margin-top:20px;">
                        <button type="submit" name="msg_frontend_submit" class="process-btn">Estrai Allegati Ora</button>
                    </div>
                </form>
            </div>

            <!-- Info Limiti PHP -->
            <div class="msg-info-box">
                <div class="info-title">ℹ️ Limiti Server</div>
                <div class="info-row">Max file caricabili: <strong><?php echo ini_get('max_file_uploads'); ?></strong></div>
                <div class="info-row">Dimensione max upload: <strong><?php echo ini_get('upload_max_filesize'); ?></strong></div>
                <div class="info-row">Dimensione max POST: <strong><?php echo ini_get('post_max_size'); ?></strong></div>
            </div>

            <!-- Risultati -->
            <?php if ($result): ?>
            <div class="msg-result-box">
                <div class="result-header">✓ Elaborazione completata!</div>
                <div class="result-row">File elaborati: <strong><?php echo $result['total']; ?>/<?php echo $result['total']; ?></strong></div>
                <div class="result-row highlight">PDF estratti: <strong><?php echo $result['pdf']; ?></strong></div>
                <div class="result-row highlight">CSV estratti: <strong><?php echo $result['csv']; ?></strong></div>
            </div>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Logica di elaborazione con conteggi separati
     */
    private function process_files($files) {
        if (!file_exists($this->pdf_dir)) wp_mkdir_p($this->pdf_dir);
        if (!file_exists($this->csv_dir)) wp_mkdir_p($this->csv_dir);

        $count = count($files['name']);
        if ($count === 0 || empty($files['name'][0])) return null;

        $stats = ['total' => 0, 'pdf' => 0, 'csv' => 0];
        @set_time_limit(300);

        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] !== 0) continue;
            $stats['total']++;

            $tmp = $files['tmp_name'][$i];
            $parser = new MSG_Universal_Parser_V7($tmp);

            if (!empty($parser->attachments)) {
                foreach ($parser->attachments as $att) {
                    $name = $att['name'];
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $dest_dir = '';
                    $is_pdf = ($ext === 'pdf');
                    $is_csv = ($ext === 'csv');

                    if ($is_pdf) $dest_dir = $this->pdf_dir;
                    if ($is_csv) $dest_dir = $this->csv_dir;

                    if ($dest_dir) {
                        $clean_name = sanitize_file_name($name);
                        $final_path = $this->unique_filename($dest_dir, $clean_name);
                        if (file_put_contents($final_path, $att['data'])) {
                            if ($is_pdf) $stats['pdf']++;
                            if ($is_csv) $stats['csv']++;
                        }
                    }
                }
            }
        }
        return $stats;
    }

    /**
     * Genera nome file unico
     */
    private function unique_filename($dir, $filename) {
        $info = pathinfo($filename);
        $name = $info['filename'];
        $ext = isset($info['extension']) ? $info['extension'] : '';
        $path = $dir . '/' . $filename;
        $c = 1;
        while (file_exists($path)) {
            $path = $dir . '/' . $name . '-' . $c . ($ext ? '.' . $ext : '');
            $c++;
        }
        return $path;
    }
}
