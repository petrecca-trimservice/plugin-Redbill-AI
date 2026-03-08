/**
 * Redbill AI — Frontend JS
 * Drag & drop upload per il shortcode [msg_uploader]
 */
(function () {
    'use strict';

    var dropArea  = document.getElementById('msg-drop-area');
    var fileInput = document.getElementById('msg-file-input');
    var btnBrowse = document.getElementById('btn-browse');
    var fileStatus = document.getElementById('file-status');
    var actionArea = document.getElementById('action-area');

    if (!dropArea || !fileInput) return;

    // Apri file picker al click del bottone
    if (btnBrowse) {
        btnBrowse.addEventListener('click', function () {
            fileInput.click();
        });
    }

    // File scelti dal file picker
    fileInput.addEventListener('change', function () {
        updateStatus(this.files);
    });

    // Drag over
    ['dragenter', 'dragover'].forEach(function (evt) {
        dropArea.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropArea.classList.add('drag-over');
        });
    });

    // Drag leave
    ['dragleave', 'drop'].forEach(function (evt) {
        dropArea.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropArea.classList.remove('drag-over');
        });
    });

    // Drop
    dropArea.addEventListener('drop', function (e) {
        var dt = e.dataTransfer;
        if (!dt || !dt.files || dt.files.length === 0) return;

        // Assegna i file al input (compat. moderna)
        try {
            var dataTransfer = new DataTransfer();
            Array.prototype.forEach.call(dt.files, function (f) {
                dataTransfer.items.add(f);
            });
            fileInput.files = dataTransfer.files;
        } catch (ex) {
            // Fallback: no DataTransfer API
        }

        updateStatus(dt.files);
    });

    function updateStatus(files) {
        if (!files || files.length === 0) return;

        var msgFiles = [];
        Array.prototype.forEach.call(files, function (f) {
            if (f.name.toLowerCase().endsWith('.msg')) {
                msgFiles.push(f.name);
            }
        });

        if (msgFiles.length === 0) {
            fileStatus.textContent = 'Nessun file .msg rilevato.';
            if (actionArea) actionArea.style.display = 'none';
            return;
        }

        fileStatus.textContent = msgFiles.length + ' file .msg selezionato/i: ' + msgFiles.slice(0, 5).join(', ') + (msgFiles.length > 5 ? '...' : '');
        if (actionArea) actionArea.style.display = 'block';
    }
})();
