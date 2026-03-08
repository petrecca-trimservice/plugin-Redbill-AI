<?php
/**
 * Script web per spostare i PDF dalla cartella processed/ a pdf/ per riprocessarli
 * Usa i dati dal database per sapere quali file spostare
 *
 * Accedi via browser: https://tuodominio.it/scripts-Glovo/sposta_pdf_per_riprocessare.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Cartelle PDF
$pdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf');
$processedDir = $pdfDir . '/processed';

if (!is_dir($processedDir)) {
    die("Cartella processed/ non trovata: $processedDir");
}

// Query per trovare fatture con importo_bonifico NULL
$sql = "SELECT id, n_fattura, data, file_pdf, importo_bonifico
        FROM gsr_glovo_fatture
        WHERE importo_bonifico IS NULL
        ORDER BY data DESC";

$result = $mysqli->query($sql);

if (!$result) {
    die("Errore query: " . $mysqli->error);
}

$fatture = [];
while ($row = $result->fetch_assoc()) {
    $fatture[] = $row;
}

// Se è stato richiesto di spostare
$azione = $_GET['azione'] ?? '';
$spostati = [];
$errori = [];
$nonTrovati = [];

if ($azione === 'sposta' && isset($_POST['conferma']) && $_POST['conferma'] === 'SI') {
    foreach ($fatture as $fattura) {
        $nomeFile = $fattura['file_pdf'];
        $sorgente = $processedDir . '/' . $nomeFile;
        $destinazione = $pdfDir . '/' . $nomeFile;

        if (!file_exists($sorgente)) {
            $nonTrovati[] = $nomeFile;
            continue;
        }

        if (rename($sorgente, $destinazione)) {
            $spostati[] = $nomeFile;
        } else {
            $errori[] = $nomeFile;
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Sposta PDF per Riprocessare</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #333; }
        h2 { color: #666; border-bottom: 2px solid #007bff; padding-bottom: 5px; margin-top: 30px; }
        .alert { padding: 15px; margin: 15px 0; border-radius: 4px; }
        .alert-info { background: #d1ecf1; border-left: 4px solid #17a2b8; color: #0c5460; }
        .alert-warning { background: #fff3cd; border-left: 4px solid #ffc107; color: #856404; }
        .alert-success { background: #d4edda; border-left: 4px solid #28a745; color: #155724; }
        .alert-danger { background: #f8d7da; border-left: 4px solid #dc3545; color: #721c24; }
        table { border-collapse: collapse; width: 100%; margin: 15px 0; font-size: 13px; }
        table td, table th { border: 1px solid #dee2e6; padding: 8px; text-align: left; }
        table th { background: #e9ecef; font-weight: bold; }
        .btn { display: inline-block; padding: 12px 24px; margin: 10px 5px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; text-decoration: none; }
        .btn-primary { background: #007bff; color: white; }
        .btn-primary:hover { background: #0056b3; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .steps { background: #e9ecef; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .steps ol { margin: 10px 0; padding-left: 20px; }
        .steps li { margin: 8px 0; }
        code { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Riprocessa Fatture con importo_bonifico NULL</h1>

        <?php if ($azione === 'sposta' && isset($_POST['conferma'])): ?>
            <h2>Risultato Spostamento</h2>

            <?php if (!empty($spostati)): ?>
            <div class="alert alert-success">
                <strong>✅ Spostati con successo: <?php echo count($spostati); ?> file</strong>
                <ul>
                    <?php foreach ($spostati as $file): ?>
                        <li><?php echo htmlspecialchars($file); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($nonTrovati)): ?>
            <div class="alert alert-warning">
                <strong>⚠️ Non trovati in processed/: <?php echo count($nonTrovati); ?> file</strong>
                <ul>
                    <?php foreach ($nonTrovati as $file): ?>
                        <li><?php echo htmlspecialchars($file); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p><em>Questi file potrebbero essere già stati spostati o cancellati</em></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($errori)): ?>
            <div class="alert alert-danger">
                <strong>❌ Errori durante lo spostamento: <?php echo count($errori); ?> file</strong>
                <ul>
                    <?php foreach ($errori as $file): ?>
                        <li><?php echo htmlspecialchars($file); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="steps">
                <h3>📋 Prossimi passi:</h3>
                <ol>
                    <li>Vai in phpMyAdmin ed esegui la query in <code>cancella_fatture_null.sql</code> per cancellare dal database le fatture con importo_bonifico NULL</li>
                    <li>Esegui lo script <code>estrai_fatture_glovo.php</code> per riprocessare i PDF spostati</li>
                    <li>Verifica che ora le fatture abbiano importo_bonifico valorizzato correttamente</li>
                </ol>
            </div>

            <a href="?" class="btn btn-secondary">← Torna indietro</a>

        <?php else: ?>

            <div class="alert alert-info">
                <strong>ℹ️ Informazioni</strong><br>
                Questo script sposta i file PDF dalla cartella <code>processed/</code> alla cartella <code>pdf/</code>
                in modo che possano essere riprocessati con il regex corretto per l'importo_bonifico.
            </div>

            <h2>Fatture con importo_bonifico NULL</h2>

            <?php if (empty($fatture)): ?>
                <div class="alert alert-success">
                    <strong>✅ Nessuna fattura con importo_bonifico NULL</strong><br>
                    Tutte le fatture hanno l'importo_bonifico valorizzato correttamente!
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <strong>⚠️ Trovate <?php echo count($fatture); ?> fatture con importo_bonifico NULL</strong>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>N. Fattura</th>
                            <th>Data</th>
                            <th>File PDF</th>
                            <th>Stato File</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fatture as $fattura):
                            $sorgente = $processedDir . '/' . $fattura['file_pdf'];
                            $exists = file_exists($sorgente);
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($fattura['id']); ?></td>
                            <td><?php echo htmlspecialchars($fattura['n_fattura']); ?></td>
                            <td><?php echo htmlspecialchars($fattura['data']); ?></td>
                            <td><?php echo htmlspecialchars($fattura['file_pdf']); ?></td>
                            <td><?php echo $exists ? '✅ Presente' : '❌ Non trovato'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="steps">
                    <h3>📋 Procedura completa:</h3>
                    <ol>
                        <li><strong>Clicca "Sposta i PDF"</strong> qui sotto per spostare i file da <code>processed/</code> a <code>pdf/</code></li>
                        <li>Vai in <strong>phpMyAdmin</strong> e esegui la query DELETE in <code>cancella_fatture_null.sql</code></li>
                        <li>Esegui lo script <code>estrai_fatture_glovo.php</code> (da Plesk o manualmente)</li>
                        <li>Verifica che le fatture ora abbiano <code>importo_bonifico</code> valorizzato</li>
                    </ol>
                </div>

                <form method="post" action="?azione=sposta" onsubmit="return confirm('Confermi di voler spostare <?php echo count($fatture); ?> file PDF da processed/ a pdf/ per riprocessarli?');">
                    <input type="hidden" name="conferma" value="SI">
                    <button type="submit" class="btn btn-danger">
                        🔄 Sposta i <?php echo count($fatture); ?> PDF per riprocessarli
                    </button>
                </form>

                <div class="alert alert-warning" style="margin-top: 20px;">
                    <strong>⚠️ ATTENZIONE:</strong> Prima di procedere assicurati di aver fatto un backup del database!
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</body>
</html>
<?php
$mysqli->close();
?>
