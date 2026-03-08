/**
 * Frontend JavaScript - MSG Extractor
 * Gestione drag & drop per caricamento file MSG
 *
 * @package MSG_Extractor
 * @since 8.0
 */

document.addEventListener('DOMContentLoaded', function() {
    const dropZone = document.getElementById('msg-drop-area');
    const fileInput = document.getElementById('msg-file-input');
    const browseBtn = document.getElementById('btn-browse');
    const statusText = document.getElementById('file-status');
    const actionArea = document.getElementById('action-area');

    if (!dropZone || !fileInput || !browseBtn) {
        return; // Elementi non trovati (shortcode non presente)
    }

    // Apre il selettore file cliccando sul bottone o sulla zona
    browseBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        fileInput.click();
    });

    dropZone.addEventListener('click', () => fileInput.click());

    // Drag & Drop visual feedback
    ['dragenter', 'dragover'].forEach(evt => {
        dropZone.addEventListener(evt, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('drag-over');
        });
    });

    ['dragleave', 'drop'].forEach(evt => {
        dropZone.addEventListener(evt, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('drag-over');
        });
    });

    // Handle Drop
    dropZone.addEventListener('drop', (e) => {
        const files = e.dataTransfer.files;
        fileInput.files = files;
        updateUI(files);
    });

    // Handle Selection
    fileInput.addEventListener('change', function() {
        updateUI(this.files);
    });

    /**
     * Aggiorna UI dopo selezione file
     */
    function updateUI(files) {
        if (files.length > 0) {
            let text = files.length === 1
                ? "1 file selezionato: " + files[0].name
                : files.length + " file selezionati pronti.";
            statusText.textContent = text;
            statusText.style.display = 'block';
            actionArea.style.display = 'block';
        }
    }
});
