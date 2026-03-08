<?php
/**
 * Script per importare manualmente un singolo PDF dalla cartella failed/
 * senza validare il campo 'commissioni'
 *
 * Uso da browser: https://tuodominio.it/scripts-Glovo/importa_pdf_senza_commissioni.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;

$config = require __DIR__ . '/config-glovo.php';

// Connessione DB
$mysqli = @new mysqli(
    $config['db_host'],
    $config['db_user'],
    $config['db_pass'],
    $config['db_name']
);

if ($mysqli->connect_errno) {
    die("Errore DB: " . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

$pdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf');
$failedDir = $pdfDir . '/failed';
$processedDir = $pdfDir . '/processed';

// Carica le funzioni di parsing dal main script
function normalizeEuroAmount($val) {
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '' || $val === '-') return null;
    $val = str_replace(['€', ' ', '.'], '', $val);
    return str_replace(',', '.', $val);
}

function sanitizeString($val) {
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '') return null;
    $val = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $val);
    $val = preg_replace('/\s+/', ' ', $val);
    $val = preg_replace('/\bS\.?R\.?L\.?/iu', 'S.R.L.', $val);
    $val = preg_replace('/\bS\.?R\.?L\.?S\.?/iu', 'S.R.L.S.', $val);
    $val = preg_replace('/\bS\.?P\.?A\.?/iu', 'S.P.A.', $val);
    $val = preg_replace('/\bS\.?A\.?S\.?/iu', 'S.A.S.', $val);
    $val = preg_replace('/\bS\.?N\.?C\.?/iu', 'S.N.C.', $val);
    return trim($val);
}

function normalizeNegozio($val) {
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '') return null;
    $mappings = [
        'Viale Coni Zugna, 43, 20144 Milano MI, Italia' => 'Girarrosti Santa Rita - Milano',
        '307, Corso Susa, 301/307, 10098 Rivoli TO, Italy' => 'Girarrosti Santa Rita - Rivoli',
        'Via Martiri della Libertà, 74, 10099 San Mauro Torinese TO, Italia' => 'Girarrosti Santa Rita - San Mauro',
        'Via S. Mauro, 1, 10036 Settimo Torinese TO, Italia' => 'Girarrosti Santa Rita - Settimo Torinese',
        'Via Vittorio Alfieri, 9, 10043 Orbassano TO, Italia' => 'Girarrosti Santa Rita - Via Vittorio Alfieri - Orbassano',
    ];
    if (isset($mappings[$val])) {
        return $mappings[$val];
    }
    return $val;
}

function parseDestinatario($text) {
    $result = ['destinatario' => null, 'negozio' => null];
    if (preg_match('/Foodinho Srl.*?\n(.*?)N\. fattura:/s', $text, $m)) {
        $block = trim($m[1]);
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $block))
        ));
        $result['destinatario'] = sanitizeString($lines[0] ?? null);
        $result['negozio']      = normalizeNegozio($lines[1] ?? null);
    }
    return $result;
}

function parseGlovoInvoice($text) {
    $data = [
        'destinatario' => null, 'negozio' => null, 'n_fattura' => null, 'data' => null,
        'periodo_da' => null, 'periodo_a' => null, 'commissioni' => null,
        'marketing_visibilita' => null, 'subtotale' => null, 'iva_22' => null,
        'totale_fattura_iva_inclusa' => null, 'prodotti' => null, 'servizio_consegna' => null,
        'totale_fattura_riepilogo' => null, 'promo_prodotti_partner' => null,
        'promo_consegna_partner' => null, 'costi_offerta_lampo' => null,
        'promo_lampo_partner' => null, 'costo_incidenti_prodotti' => null,
        'tariffa_tempo_attesa' => null,
        'rimborsi_partner_senza_comm' => null, 'costo_annullamenti_servizio' => null,
        'consegna_gratuita_incidente' => null, 'buoni_pasto' => null,
        'supplemento_ordine_glovo_prime' => null, 'glovo_gia_pagati' => null,
        'ordini_rimborsati_partner' => null, 'commissione_ordini_rimborsati' => null,
        'sconto_comm_ordini_buoni_pasto' => null,
        'debito_accumulato' => null, 'importo_bonifico' => null,
    ];

    $d = parseDestinatario($text);
    $data['destinatario'] = $d['destinatario'];
    $data['negozio'] = $d['negozio'];

    // Supporta formati: HDCKZB123456, IT-PF-3IR51KD-001/23, ecc.
    if (preg_match('/\b([A-Z0-9][A-Z0-9\-\/]{7,})\s+N\. fattura:/', $text, $m)) {
        $data['n_fattura'] = trim($m[1]);
    }
    if (preg_match('/Data:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/', $text, $m)) {
        $data['data'] = $m[1];
    }
    if (preg_match('/Servizio fornito da\s*([0-9]{4}-[0-9]{2}-[0-9]{2})\s*a\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/', $text, $m)) {
        $data['periodo_da'] = $m[1];
        $data['periodo_a'] = $m[2];
    }

    $patterns = [
        'commissioni' => '/Commissioni\s*([\d\.,]+)\s*€/u',
        'marketing_visibilita' => '/Marketing[\s\-]*visibilit[àáa]\s*([\d\.,]+)\s*€/ui',
        'subtotale' => '/Subtotale\s*([\d\.,]+)\s*€/u',
        'iva_22' => '/IVA\s*\(22\s*%\)\s*([\d\.,]+)\s*€/u',
        'totale_fattura_iva_inclusa' => '/Totale fattura\s*\(IVA inclusa\)\s*([\d\.,]+)\s*€/us',
        'prodotti' => '/\+\s*Prodotti\s*([\d\.,]+)\s*€/u',
        'servizio_consegna' => '/\+\s*Servizio di consegna\s*([\d\.,]+)\s*€/u',
        'totale_fattura_riepilogo' => '/-\s*Totale fattura\s*([-]?\d[\d\.,]*)\s*€/u',
        'promo_prodotti_partner' => '/-\s*Promozione sui prodotti a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
        'promo_consegna_partner' => '/-?\s*Promozione sulla consegna a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
        'costi_offerta_lampo' => '/-?\s*Costi per offerta lampo\s*([-]?\d[\d\.,]*)\s*€/u',
        'promo_lampo_partner' => '/-\s*Promozione lampo a carico del [Pp]artner\s*([-]?\d[\d\.,]*)\s*€/u',
        'costo_incidenti_prodotti' => '/-\s*Costo degli incidenti relativi ai prodotti\s*([-]?\d[\d\.,]*)\s*€/u',
        'tariffa_tempo_attesa' => '/-\s*Tariffa\s+per\s+tempo\s+di\s+attesa\s*([-]?\d[\d\.,]*)\s*€/ui',
        'rimborsi_partner_senza_comm' => '/\+\s*Rimborsi al partner senza costo commissione Glovo\s*([\d\.,]+)\s*€/u',
        'costo_annullamenti_servizio' => '/-\s*Costo degli annullamenti e degli incidenti relativi al servizio\s*([-]?\d[\d\.,]*)\s*€/u',
        'consegna_gratuita_incidente' => '/-\s*Consegna gratuita in seguito a incidente\s*([-]?\d[\d\.,]*)\s*€/u',
        'buoni_pasto' => '/-\s*Buoni\s+pasto\s*([-]?\d[\d\.,]*)\s*€/ui',
        'supplemento_ordine_glovo_prime' => '/-?\s*Supplemento per ordine con Glovo Prime\s*([-]?\d[\d\.,]*)\s*€/u',
        'glovo_gia_pagati' => '/-\s*Glovo già pagati\s*([-]?\d[\d\.,]*)\s*€/u',
        'ordini_rimborsati_partner'   => '/\+\s*Ordini rimborsati al partner\s*([\d\.,]+)\s*€/u',
        'commissione_ordini_rimborsati' => '/-?\s*Commissione Glovo sugli ordini rimborsati al partner\s*([-]?\d[\d\.,]*)\s*€/ui',
        'sconto_comm_ordini_buoni_pasto' => '/-?\s*Sconto commissione ordini buoni\s+pasto\s*([-]?\d[\d\.,]*)\s*€/ui',
        'debito_accumulato' => '/-\s*Debito accumulato\s*([-]?\d[\d\.,]*)\s*€/u',
        'importo_bonifico' => '/Importo del bonifico\s*(-?[\d\.,]+|-)\s*€/u',
    ];

    foreach ($patterns as $key => $regex) {
        if (preg_match($regex, $text, $m)) {
            $data[$key] = normalizeEuroAmount($m[1]);
        }
    }

    return $data;
}

// GET request - mostra form
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $failedFiles = is_dir($failedDir) ? glob($failedDir . '/*.pdf') : [];
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Importa PDF senza Commissioni</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
            .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
            h1 { color: #333; }
            .alert { padding: 15px; margin: 20px 0; border-radius: 4px; }
            .alert-info { background: #d1ecf1; border-left: 4px solid #17a2b8; }
            .alert-warning { background: #fff3cd; border-left: 4px solid #ffc107; }
            select, input { width: 100%; padding: 10px; margin: 10px 0; font-size: 14px; }
            button { padding: 12px 24px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
            button:hover { background: #218838; }
            .file-list { list-style: none; padding: 0; }
            .file-list li { padding: 8px; background: #f8f9fa; margin: 5px 0; border-radius: 4px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Importa PDF senza validazione Commissioni</h1>

            <div class="alert alert-info">
                <strong>Questo script importa un PDF dalla cartella failed/ ignorando la validazione del campo 'commissioni'.</strong><br>
                Utile per fatture che non contengono il campo commissioni (es. note di credito, fatture speciali).
            </div>

            <?php if (empty($failedFiles)): ?>
                <div class="alert alert-warning">
                    <strong>Nessun PDF trovato nella cartella failed/</strong>
                </div>
            <?php else: ?>
                <form method="post">
                    <label><strong>Seleziona il PDF da importare:</strong></label>
                    <select name="pdf_file" required>
                        <option value="">-- Seleziona un file --</option>
                        <?php foreach ($failedFiles as $file): ?>
                            <option value="<?php echo htmlspecialchars(basename($file)); ?>">
                                <?php echo htmlspecialchars(basename($file)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label><strong>Commissioni (opzionale - lascia vuoto se non presente nella fattura):</strong></label>
                    <input type="text" name="commissioni" placeholder="es: 150.00" />
                    <small style="display:block;margin-top:-8px;color:#666;">Lascia vuoto se la fattura non ha commissioni</small>

                    <button type="submit">Importa PDF</button>
                </form>

                <h3>PDF in failed/:</h3>
                <ul class="file-list">
                    <?php foreach ($failedFiles as $file): ?>
                        <li><?php echo htmlspecialchars(basename($file)); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// POST request - importa il PDF
$pdfFile = $_POST['pdf_file'] ?? '';
$commissioniManuale = trim($_POST['commissioni'] ?? '');

if (empty($pdfFile)) {
    die("Errore: Nessun file selezionato");
}

$pdfPath = $failedDir . '/' . $pdfFile;
if (!file_exists($pdfPath)) {
    die("Errore: File non trovato: $pdfFile");
}

// Parse PDF
$pdfParser = new Parser();
$pdf = $pdfParser->parseFile($pdfPath);
$pages = $pdf->getPages();

if (!isset($pages[0])) {
    die("Errore: Nessuna pagina nel PDF");
}

$text = $pages[0]->getText();
$data = parseGlovoInvoice($text);

// Sovrascrivi commissioni se fornito manualmente
if (!empty($commissioniManuale)) {
    $data['commissioni'] = normalizeEuroAmount($commissioniManuale);
}

// Prepara insert
$table = $config['db_table'];
$sql = "INSERT INTO `$table` (
    file_pdf, destinatario, negozio, n_fattura, data, periodo_da, periodo_a,
    commissioni, marketing_visibilita, subtotale, iva_22, totale_fattura_iva_inclusa,
    prodotti, servizio_consegna, totale_fattura_riepilogo, promo_prodotti_partner,
    promo_consegna_partner, costi_offerta_lampo, promo_lampo_partner,
    costo_incidenti_prodotti, tariffa_tempo_attesa, rimborsi_partner_senza_comm,
    costo_annullamenti_servizio, consegna_gratuita_incidente, buoni_pasto,
    supplemento_ordine_glovo_prime, glovo_gia_pagati,
    ordini_rimborsati_partner, commissione_ordini_rimborsati, sconto_comm_ordini_buoni_pasto,
    debito_accumulato, importo_bonifico
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("Errore prepare: " . $mysqli->error);
}

$stmt->bind_param(
    str_repeat('s', 32),
    $pdfFile,
    $data['destinatario'], $data['negozio'], $data['n_fattura'], $data['data'],
    $data['periodo_da'], $data['periodo_a'], $data['commissioni'],
    $data['marketing_visibilita'], $data['subtotale'], $data['iva_22'],
    $data['totale_fattura_iva_inclusa'], $data['prodotti'], $data['servizio_consegna'],
    $data['totale_fattura_riepilogo'], $data['promo_prodotti_partner'],
    $data['promo_consegna_partner'], $data['costi_offerta_lampo'],
    $data['promo_lampo_partner'], $data['costo_incidenti_prodotti'],
    $data['tariffa_tempo_attesa'],
    $data['rimborsi_partner_senza_comm'], $data['costo_annullamenti_servizio'],
    $data['consegna_gratuita_incidente'], $data['buoni_pasto'],
    $data['supplemento_ordine_glovo_prime'], $data['glovo_gia_pagati'],
    $data['ordini_rimborsati_partner'], $data['commissione_ordini_rimborsati'],
    $data['sconto_comm_ordini_buoni_pasto'],
    $data['debito_accumulato'], $data['importo_bonifico']
);

try {
    $stmt->execute();
    $insertedId = $mysqli->insert_id;

    // Sposta PDF in processed
    $destProcessed = $processedDir . '/' . $pdfFile;
    rename($pdfPath, $destProcessed);

    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Importazione Completata</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
            .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
            .success { background: #d4edda; padding: 20px; border-left: 4px solid #28a745; border-radius: 4px; }
            .info { background: #d1ecf1; padding: 15px; margin: 20px 0; border-radius: 4px; }
            table { border-collapse: collapse; width: 100%; margin: 20px 0; }
            table td, table th { border: 1px solid #dee2e6; padding: 10px; text-align: left; }
            table th { background: #e9ecef; font-weight: bold; width: 30%; }
            code { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
            .btn { display: inline-block; padding: 10px 20px; margin: 10px 5px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="success">
                <h1>Importazione Completata!</h1>
                <p>Il PDF e stato importato con successo nel database.</p>
            </div>

            <h2>Dati Inseriti</h2>
            <table>
                <tr><th>ID Record</th><td><strong><?php echo $insertedId; ?></strong></td></tr>
                <tr><th>File PDF</th><td><?php echo htmlspecialchars($pdfFile); ?></td></tr>
                <tr><th>N. Fattura</th><td><?php echo htmlspecialchars($data['n_fattura']); ?></td></tr>
                <tr><th>Data</th><td><?php echo htmlspecialchars($data['data']); ?></td></tr>
                <tr><th>Destinatario</th><td><?php echo htmlspecialchars($data['destinatario']); ?></td></tr>
                <tr><th>Negozio</th><td><?php echo htmlspecialchars($data['negozio']); ?></td></tr>
                <tr><th>Commissioni</th><td><strong style="color:<?php echo empty($data['commissioni']) ? 'orange' : 'green'; ?>">
                    <?php echo empty($data['commissioni']) ? 'NULL (fattura senza commissioni)' : htmlspecialchars($data['commissioni']) . ' EUR'; ?>
                </strong></td></tr>
                <tr><th>Subtotale</th><td><?php echo htmlspecialchars($data['subtotale'] ?? 'NULL'); ?></td></tr>
                <tr><th>IVA 22%</th><td><?php echo htmlspecialchars($data['iva_22'] ?? 'NULL'); ?></td></tr>
                <tr><th>Totale Fattura</th><td><?php echo htmlspecialchars($data['totale_fattura_iva_inclusa'] ?? 'NULL'); ?></td></tr>
                <tr><th>Importo Bonifico</th><td><?php echo htmlspecialchars($data['importo_bonifico'] ?? 'NULL'); ?></td></tr>
            </table>

            <div class="info">
                <p><strong>Nota:</strong> La fattura e stata importata senza il campo commissioni.
                Se necessario, puoi aggiornare il valore manualmente con questa query:</p>
                <code style="display:block;padding:15px;background:#1e1e1e;color:#d4d4d4;overflow-x:auto;">
UPDATE <?php echo $table; ?><br>
SET commissioni = 'VALORE_QUI'<br>
WHERE id = <?php echo $insertedId; ?>;
                </code>
            </div>

            <p>
                <a href="?" class="btn">Importa altro PDF</a>
                <a href="importa_pdf_manuale.php" class="btn" style="background:#6c757d;">Importa senza importo_bonifico</a>
            </p>
        </div>
    </body>
    </html>
    <?php

} catch (mysqli_sql_exception $e) {
    if ($e->getCode() == 1062) {
        die("Errore: Fattura gia esistente nel database (n_fattura duplicato)");
    } else {
        die("Errore DB: " . $e->getMessage());
    }
}

$stmt->close();
$mysqli->close();
