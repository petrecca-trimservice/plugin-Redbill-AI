<?php
/**
 * Backfill: riprocessa i PDF in processed/ per estrarre i 3 nuovi campi
 * e aggiornare i record esistenti in DB via UPDATE su n_fattura.
 *
 * Campi aggiornati:
 *   - ordini_rimborsati_partner        (+ Ordini rimborsati al partner)
 *   - commissione_ordini_rimborsati    (- Commissione Glovo sugli ordini rimborsati al partner)
 *   - sconto_comm_ordini_buoni_pasto   (- Sconto commissione ordini buoni pasto)
 *
 * Uso web:
 *   backfill_campi_rimborsati.php             -> dry-run (anteprima)
 *   backfill_campi_rimborsati.php  [Esegui]   -> esegue UPDATE in DB
 *
 * Uso CLI:
 *   php backfill_campi_rimborsati.php              # esegue il backfill
 *   php backfill_campi_rimborsati.php --dry-run    # simula senza scrivere in DB
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;

$config = require __DIR__ . '/config-glovo.php';

// Rileva modalità (web o CLI)
$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    $dryRun = in_array('--dry-run', $argv);
} else {
    // Web: dry-run di default, esegui solo con POST confirm=1
    $dryRun = !($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === '1');
}

// CARTELLA PROCESSED
$pdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf');
if ($pdfDir === false) {
    die("Cartella PDF non trovata.");
}
$processedDir = $pdfDir . '/processed';
if (!is_dir($processedDir)) {
    die("Cartella processed/ non trovata.");
}

// CONNESSIONE DB
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

// NORMALIZZA €
function normalizeEuroAmount($val) {
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '' || $val === '-') return null;
    $val = str_replace(['€', ' ', '.'], '', $val);
    return str_replace(',', '.', $val);
}

// ESTRAI SOLO I 3 NUOVI CAMPI + n_fattura (per la chiave di UPDATE)
function estraiNuoviCampi($text) {
    $data = [
        'n_fattura'                    => null,
        'ordini_rimborsati_partner'    => null,
        'commissione_ordini_rimborsati' => null,
        'sconto_comm_ordini_buoni_pasto' => null,
    ];

    if (preg_match('/\b([A-Z0-9][A-Z0-9\-\/]{7,})\s+N\. fattura:/', $text, $m)) {
        $data['n_fattura'] = trim($m[1]);
    }

    $patterns = [
        'ordini_rimborsati_partner'    => '/\+\s*Ordini rimborsati al partner\s*([\d\.,]+)\s*€/u',
        'commissione_ordini_rimborsati' => '/-?\s*Commissione Glovo sugli ordini rimborsati al partner\s*([-]?\d[\d\.,]*)\s*€/ui',
        'sconto_comm_ordini_buoni_pasto' => '/-?\s*Sconto commissione ordini buoni\s+pasto\s*([-]?\d[\d\.,]*)\s*€/ui',
    ];

    foreach ($patterns as $key => $regex) {
        if (preg_match($regex, $text, $m)) {
            $data[$key] = normalizeEuroAmount($m[1]);
        }
    }

    return $data;
}

// PREPARE UPDATE
$table = $config['db_table'];
$sqlUpdate = "UPDATE `$table`
    SET ordini_rimborsati_partner = ?,
        commissione_ordini_rimborsati = ?,
        sconto_comm_ordini_buoni_pasto = ?
    WHERE n_fattura = ?";

$stmt = $mysqli->prepare($sqlUpdate);
if (!$stmt) {
    die("Errore prepare(): " . $mysqli->error);
}

// LISTA PDF
$pdfParser = new Parser();
$files = glob($processedDir . '/*.pdf');

if (!$files) {
    die("Nessun PDF trovato in processed/.");
}

$totale = count($files);

// ELABORAZIONE
$aggiornati = 0;
$conValori = 0;
$nonTrovati = 0;
$errori = 0;
$saltati = 0;
$righeLog = []; // per output web

foreach ($files as $i => $file) {
    $fileName = basename($file);
    $num = $i + 1;

    try {
        $pdf = $pdfParser->parseFile($file);
        $pages = $pdf->getPages();
        if (!isset($pages[0])) {
            $saltati++;
            continue;
        }

        $text = $pages[0]->getText();
        $data = estraiNuoviCampi($text);

        if (empty($data['n_fattura'])) {
            $saltati++;
            continue;
        }

        $haValori = ($data['ordini_rimborsati_partner'] !== null
                  || $data['commissione_ordini_rimborsati'] !== null
                  || $data['sconto_comm_ordini_buoni_pasto'] !== null);

        if ($haValori) {
            $conValori++;
            $valori = [];
            if ($data['ordini_rimborsati_partner'] !== null)    $valori[] = "ordini_rimb = {$data['ordini_rimborsati_partner']}";
            if ($data['commissione_ordini_rimborsati'] !== null) $valori[] = "comm_rimb = {$data['commissione_ordini_rimborsati']}";
            if ($data['sconto_comm_ordini_buoni_pasto'] !== null) $valori[] = "sconto_bp = {$data['sconto_comm_ordini_buoni_pasto']}";

            $righeLog[] = [
                'num'     => $num,
                'file'    => $fileName,
                'fattura' => $data['n_fattura'],
                'valori'  => implode(', ', $valori),
                'data'    => $data,
            ];
        }

        if (!$dryRun) {
            $stmt->bind_param(
                'ssss',
                $data['ordini_rimborsati_partner'],
                $data['commissione_ordini_rimborsati'],
                $data['sconto_comm_ordini_buoni_pasto'],
                $data['n_fattura']
            );
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $aggiornati++;
            } elseif ($stmt->affected_rows === 0) {
                $nonTrovati++;
            }
        }

    } catch (Exception $e) {
        $errori++;
        $righeLog[] = [
            'num'     => $num,
            'file'    => $fileName,
            'fattura' => '-',
            'valori'  => 'ERRORE: ' . $e->getMessage(),
            'data'    => null,
        ];
    }
}

$stmt->close();
$mysqli->close();

// === OUTPUT ===

if ($isCli) {
    // --- CLI ---
    if ($dryRun) {
        echo "=== DRY-RUN ===\n\n";
    }
    echo "PDF trovati: $totale\n\n";
    foreach ($righeLog as $r) {
        echo "  [{$r['num']}/$totale] {$r['file']} ({$r['fattura']}) -> {$r['valori']}\n";
    }
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "Totale PDF analizzati:        $totale\n";
    echo "PDF con almeno 1 nuovo campo: $conValori\n";
    if (!$dryRun) {
        echo "Record DB aggiornati:         $aggiornati\n";
        echo "Non trovati/invariati in DB:   $nonTrovati\n";
    }
    echo "Saltati:                      $saltati\n";
    echo "Errori:                       $errori\n";
    echo str_repeat('=', 60) . "\n";
} else {
    // --- WEB ---
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Backfill campi rimborsati fatture Glovo</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
            .container { max-width: 1100px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            h1 { margin-top: 0; }
            .mode-dry { background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 4px; margin-bottom: 20px; }
            .mode-exec { background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 4px; margin-bottom: 20px; }
            .stats { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px; }
            .stat { background: #e9ecef; padding: 15px 20px; border-radius: 6px; text-align: center; min-width: 140px; }
            .stat .num { font-size: 28px; font-weight: bold; color: #333; }
            .stat .label { font-size: 13px; color: #666; margin-top: 4px; }
            .stat.highlight { background: #d1ecf1; }
            .stat.success { background: #d4edda; }
            table { border-collapse: collapse; width: 100%; margin: 20px 0; font-size: 14px; }
            table th, table td { border: 1px solid #dee2e6; padding: 8px 12px; text-align: left; }
            table th { background: #343a40; color: white; position: sticky; top: 0; }
            table tr:nth-child(even) { background: #f8f9fa; }
            table tr:hover { background: #e2e6ea; }
            .val { font-family: monospace; color: #d63384; }
            .btn-exec { background: #28a745; color: white; padding: 12px 30px; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; margin-top: 10px; }
            .btn-exec:hover { background: #218838; }
            .warn { color: #856404; font-weight: bold; }
            .no-results { background: #f8f9fa; padding: 30px; text-align: center; color: #666; border-radius: 6px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Backfill campi rimborsati e sconto buoni pasto</h1>
            <p style="color:#666;">Campi: <code>ordini_rimborsati_partner</code>, <code>commissione_ordini_rimborsati</code>, <code>sconto_comm_ordini_buoni_pasto</code></p>

            <?php if ($dryRun): ?>
                <div class="mode-dry">
                    <strong>ANTEPRIMA (dry-run)</strong> — Nessuna modifica al database. Revisiona i risultati e clicca "Esegui" per applicare.
                </div>
            <?php else: ?>
                <div class="mode-exec">
                    <strong>ESEGUITO</strong> — Le modifiche sono state applicate al database.
                </div>
            <?php endif; ?>

            <div class="stats">
                <div class="stat">
                    <div class="num"><?= $totale ?></div>
                    <div class="label">PDF analizzati</div>
                </div>
                <div class="stat highlight">
                    <div class="num"><?= $conValori ?></div>
                    <div class="label">Con nuovi campi</div>
                </div>
                <?php if (!$dryRun): ?>
                <div class="stat success">
                    <div class="num"><?= $aggiornati ?></div>
                    <div class="label">DB aggiornati</div>
                </div>
                <div class="stat">
                    <div class="num"><?= $nonTrovati ?></div>
                    <div class="label">Non trovati / invariati</div>
                </div>
                <?php endif; ?>
                <div class="stat">
                    <div class="num"><?= $saltati ?></div>
                    <div class="label">Saltati</div>
                </div>
                <?php if ($errori > 0): ?>
                <div class="stat" style="background:#f8d7da;">
                    <div class="num"><?= $errori ?></div>
                    <div class="label">Errori</div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($righeLog)): ?>
                <h2>PDF con valori trovati (<?= $conValori ?>)</h2>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>File PDF</th>
                            <th>N. Fattura</th>
                            <th>ordini_rimborsati_partner</th>
                            <th>commissione_ordini_rimborsati</th>
                            <th>sconto_comm_ordini_buoni_pasto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($righeLog as $r):
                            $d = $r['data'];
                        ?>
                        <tr>
                            <td><?= $r['num'] ?></td>
                            <td><?= htmlspecialchars($r['file']) ?></td>
                            <td><?= htmlspecialchars($r['fattura']) ?></td>
                            <td class="val"><?= $d ? ($d['ordini_rimborsati_partner'] ?? '-') : '-' ?></td>
                            <td class="val"><?= $d ? ($d['commissione_ordini_rimborsati'] ?? '-') : '-' ?></td>
                            <td class="val"><?= $d ? ($d['sconto_comm_ordini_buoni_pasto'] ?? '-') : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-results">
                    Nessun PDF contiene valori per i 3 nuovi campi.
                </div>
            <?php endif; ?>

            <?php if ($dryRun && $conValori > 0): ?>
                <form method="POST">
                    <input type="hidden" name="confirm" value="1">
                    <p class="warn">Confermando, verranno aggiornati i record nel database per i <?= $conValori ?> PDF sopra elencati.</p>
                    <button type="submit" class="btn-exec">Esegui backfill (<?= $conValori ?> PDF)</button>
                </form>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}
