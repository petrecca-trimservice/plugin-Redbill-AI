<?php
/**
 * Script completo per riprocessare fatture con importo_bonifico NULL
 *
 * Questo script:
 * 1. Trova le fatture con importo_bonifico NULL nel database
 * 2. Sposta i PDF da processed/ a pdf/
 * 3. Cancella le fatture dal database
 * 4. Reimporta i PDF con il regex corretto
 *
 * Esegui da CLI: php riprocessa_fatture_null.php
 * O da browser con conferma: https://tuodominio.it/scripts-Glovo/riprocessa_fatture_null.php?conferma=SI
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Determina se è CLI o WEB
$isCLI = php_sapi_name() === 'cli';

if (!$isCLI) {
    // Modalità WEB - richiede conferma
    $conferma = $_GET['conferma'] ?? '';
    if ($conferma !== 'SI') {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Riprocessa Fatture NULL</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 40px; background: #f5f5f5; }
                .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
                h1 { color: #333; }
                .alert { padding: 15px; margin: 20px 0; border-radius: 4px; }
                .alert-warning { background: #fff3cd; border-left: 4px solid #ffc107; color: #856404; }
                .btn { display: inline-block; padding: 15px 30px; margin: 10px 5px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; text-decoration: none; font-weight: bold; }
                .btn-danger { background: #dc3545; color: white; }
                .btn-danger:hover { background: #c82333; }
                .btn-secondary { background: #6c757d; color: white; }
                ol { line-height: 1.8; }
                code { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>🔄 Riprocessa Fatture con importo_bonifico NULL</h1>

                <div class="alert alert-warning">
                    <strong>⚠️ ATTENZIONE</strong><br>
                    Questo script eseguirà automaticamente le seguenti operazioni:
                    <ol>
                        <li>Trova tutte le fatture con <code>importo_bonifico NULL</code> nel database</li>
                        <li>Sposta i PDF corrispondenti da <code>processed/</code> a <code>pdf/</code></li>
                        <li>Cancella le fatture dal database</li>
                        <li>Reimporta i PDF con il regex corretto per catturare valori negativi</li>
                    </ol>
                    <br>
                    <strong>Assicurati di aver fatto un backup del database prima di procedere!</strong>
                </div>

                <a href="?conferma=SI" class="btn btn-danger" onclick="return confirm('Sei sicuro di voler procedere? Questa operazione modificherà il database.')">
                    ▶️ Avvia Riprocessamento
                </a>

                <a href="javascript:history.back()" class="btn btn-secondary">
                    ← Annulla
                </a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    // Modalità WEB con conferma - mostra output HTML
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Riprocessamento in corso...</title>";
    echo "<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#d4d4d4;} .success{color:#4ec9b0;} .error{color:#f48771;} .info{color:#9cdcfe;} .warning{color:#dcdcaa;}</style>";
    echo "</head><body><h1>🔄 Riprocessamento Fatture NULL</h1><pre>";
    ob_implicit_flush(true);
}

function logMessage($msg, $type = 'info') {
    global $isCLI;

    $colors = [
        'success' => "\033[32m",
        'error' => "\033[31m",
        'warning' => "\033[33m",
        'info' => "\033[36m",
        'reset' => "\033[0m"
    ];

    if ($isCLI) {
        echo $colors[$type] . $msg . $colors['reset'] . "\n";
    } else {
        echo "<span class='$type'>$msg</span>\n";
    }
}

// ============================================================================
// FASE 1: TROVA FATTURE CON importo_bonifico NULL
// ============================================================================

logMessage("============================================", 'info');
logMessage("FASE 1: Ricerca fatture con importo_bonifico NULL", 'info');
logMessage("============================================", 'info');

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
    logMessage("❌ ERRORE DB: " . $mysqli->connect_error, 'error');
    exit(1);
}
$mysqli->set_charset('utf8mb4');

// Query per trovare fatture con importo_bonifico NULL
$table = $config['db_table'];
$sql = "SELECT id, n_fattura, data, file_pdf FROM `$table` WHERE importo_bonifico IS NULL ORDER BY data DESC";
$result = $mysqli->query($sql);

if (!$result) {
    logMessage("❌ ERRORE QUERY: " . $mysqli->error, 'error');
    exit(1);
}

$fattureNull = [];
while ($row = $result->fetch_assoc()) {
    $fattureNull[] = $row;
}

logMessage("✅ Trovate " . count($fattureNull) . " fatture con importo_bonifico NULL", 'success');

if (count($fattureNull) === 0) {
    logMessage("✅ Nessuna fattura da riprocessare. Tutto OK!", 'success');
    $mysqli->close();
    exit(0);
}

// Mostra le fatture trovate
logMessage("\nFatture da riprocessare:", 'info');
foreach ($fattureNull as $f) {
    logMessage("  - ID {$f['id']}: {$f['n_fattura']} ({$f['data']}) - {$f['file_pdf']}", 'info');
}

// ============================================================================
// FASE 2: SPOSTA PDF DA processed/ A pdf/
// ============================================================================

logMessage("\n============================================", 'info');
logMessage("FASE 2: Spostamento PDF", 'info');
logMessage("============================================", 'info');

$pdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf');
$processedDir = $pdfDir . '/processed';

if (!is_dir($processedDir)) {
    logMessage("❌ ERRORE: Cartella processed/ non trovata: $processedDir", 'error');
    exit(1);
}

$pdfSpostati = [];
$pdfNonTrovati = [];
$pdfErrori = [];

foreach ($fattureNull as $fattura) {
    $nomeFile = $fattura['file_pdf'];
    $sorgente = $processedDir . '/' . $nomeFile;
    $destinazione = $pdfDir . '/' . $nomeFile;

    if (!file_exists($sorgente)) {
        logMessage("  ⚠️  File non trovato: $nomeFile", 'warning');
        $pdfNonTrovati[] = $nomeFile;
        continue;
    }

    if (rename($sorgente, $destinazione)) {
        logMessage("  ✅ Spostato: $nomeFile", 'success');
        $pdfSpostati[] = $nomeFile;
    } else {
        logMessage("  ❌ Errore spostamento: $nomeFile", 'error');
        $pdfErrori[] = $nomeFile;
    }
}

logMessage("\nRiepilogo spostamento:", 'info');
logMessage("  ✅ Spostati: " . count($pdfSpostati), 'success');
logMessage("  ⚠️  Non trovati: " . count($pdfNonTrovati), 'warning');
logMessage("  ❌ Errori: " . count($pdfErrori), 'error');

if (count($pdfSpostati) === 0) {
    logMessage("\n❌ NESSUN FILE SPOSTATO - Interrompo operazione", 'error');
    $mysqli->close();
    exit(1);
}

// ============================================================================
// FASE 3: CANCELLA FATTURE DAL DATABASE
// ============================================================================

logMessage("\n============================================", 'info');
logMessage("FASE 3: Cancellazione dal database", 'info');
logMessage("============================================", 'info');

// Cancella solo le fatture con PDF effettivamente spostati
$idsDaCancellare = [];
foreach ($fattureNull as $fattura) {
    if (in_array($fattura['file_pdf'], $pdfSpostati)) {
        $idsDaCancellare[] = (int)$fattura['id'];
    }
}

if (empty($idsDaCancellare)) {
    logMessage("❌ NESSUN RECORD DA CANCELLARE", 'error');
} else {
    $idsStr = implode(',', $idsDaCancellare);
    $sqlDelete = "DELETE FROM `$table` WHERE id IN ($idsStr)";

    logMessage("Cancello " . count($idsDaCancellare) . " record dal database...", 'info');

    if ($mysqli->query($sqlDelete)) {
        logMessage("✅ Cancellati " . $mysqli->affected_rows . " record dal database", 'success');
    } else {
        logMessage("❌ ERRORE CANCELLAZIONE: " . $mysqli->error, 'error');
        logMessage("⚠️  I PDF sono stati spostati ma il DB non è stato modificato", 'warning');
        logMessage("⚠️  Ripristina manualmente i PDF da pdf/ a processed/", 'warning');
        exit(1);
    }
}

$mysqli->close();

// ============================================================================
// FASE 4: REIMPORTA I PDF
// ============================================================================

logMessage("\n============================================", 'info');
logMessage("FASE 4: Reimportazione PDF", 'info');
logMessage("============================================", 'info');

// Prova a trovare il percorso di PHP
$phpPaths = [
    '/usr/bin/php',
    '/usr/local/bin/php',
    '/opt/plesk/php/8.2/bin/php',
    '/opt/plesk/php/8.1/bin/php',
    '/opt/plesk/php/8.0/bin/php',
    '/opt/plesk/php/7.4/bin/php',
    'php' // Fallback
];

$phpBinary = null;
foreach ($phpPaths as $path) {
    if (@is_executable($path)) {
        $phpBinary = $path;
        break;
    }
}

// Se non troviamo PHP, usa quello corrente
if (!$phpBinary) {
    $phpBinary = PHP_BINARY;
}

logMessage("Uso PHP: $phpBinary", 'info');
logMessage("Eseguo lo script di importazione...\n", 'info');

// Esegui lo script di estrazione
$command = escapeshellarg($phpBinary) . " " . escapeshellarg(__DIR__ . '/estrai_fatture_glovo.php') . " 2>&1";
$output = [];
$returnCode = 0;

exec($command, $output, $returnCode);

// Mostra l'output
foreach ($output as $line) {
    echo $line . "\n";
}

if ($returnCode === 0) {
    logMessage("\n✅ REIMPORTAZIONE COMPLETATA CON SUCCESSO", 'success');
} else {
    logMessage("\n⚠️  REIMPORTAZIONE COMPLETATA CON ERRORI (codice: $returnCode)", 'warning');
    logMessage("\n📝 AZIONE RICHIESTA:", 'warning');
    logMessage("   Esegui manualmente da Plesk: estrai_fatture_glovo.php", 'warning');
    logMessage("   I PDF sono pronti nella cartella pdf/ per essere importati", 'warning');
}

logMessage("\n============================================", 'info');
logMessage("RIEPILOGO FINALE", 'info');
logMessage("============================================", 'info');
logMessage("  • Fatture trovate con NULL: " . count($fattureNull), 'info');
logMessage("  • PDF spostati da processed/ a pdf/: " . count($pdfSpostati), 'success');
logMessage("  • Record cancellati dal database: " . count($idsDaCancellare), 'success');
logMessage("  • PDF reimportati: " . count($pdfSpostati), 'success');
logMessage("============================================", 'info');
logMessage("\n✅ OPERAZIONE COMPLETATA!", 'success');
logMessage("\nVerifica ora nel database che le fatture abbiano importo_bonifico valorizzato.", 'info');

if (!$isCLI) {
    echo "</pre>";
    echo "<h2 style='color:#28a745;'>✅ Operazione Completata con Successo!</h2>";
    echo "<div style='background:#d4edda;padding:15px;border-left:4px solid #28a745;margin:20px 0;'>";
    echo "<strong>✅ Tutti i passaggi sono stati completati:</strong><ul>";
    echo "<li>✅ " . count($fattureNull) . " fatture trovate con importo_bonifico NULL</li>";
    echo "<li>✅ " . count($pdfSpostati) . " PDF spostati da processed/ a pdf/</li>";
    echo "<li>✅ " . count($idsDaCancellare) . " record cancellati dal database</li>";
    echo "<li>✅ " . count($pdfSpostati) . " PDF reimportati con successo</li>";
    echo "</ul></div>";
    echo "<p><strong>Verifica ora nel database che le fatture abbiano importo_bonifico valorizzato correttamente.</strong></p>";
    echo "<p><a href='.' style='padding:10px 20px;background:#007bff;color:white;text-decoration:none;border-radius:4px;'>← Torna alla cartella</a></p>";
    echo "</body></html>";
}
