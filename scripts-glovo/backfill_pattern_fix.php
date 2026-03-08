<?php
/**
 * backfill_pattern_fix.php
 *
 * Backfill SICURO: aggiorna SOLO i campi NULL dopo il fix dei pattern regex.
 *
 * Campi gestiti:
 *   - promo_consegna_partner (0% → 100% success)
 *   - marketing_visibilita (78.7% → 100% success)
 *   - tariffa_tempo_attesa (80% → 100% success)
 *   - buoni_pasto (80% → 100% success)
 *
 * SICUREZZA:
 *   - UPDATE condizionale: modifica SOLO se il campo è NULL
 *   - Processa SOLO i PDF con campi NULL identificati
 *   - Dry-run mode per preview prima dell'esecuzione
 *   - Log dettagliato delle modifiche
 *
 * Uso web:
 *   backfill_pattern_fix.php             -> dry-run (anteprima)
 *   backfill_pattern_fix.php [Esegui]    -> esegue UPDATE in DB
 *
 * Uso CLI:
 *   php backfill_pattern_fix.php              # esegue il backfill
 *   php backfill_pattern_fix.php --dry-run    # simula senza scrivere in DB
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(600);

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

// PATTERN CORRETTI (dopo il fix)
$patterns = [
    'promo_consegna_partner'  => '/-?\s*Promozione sulla consegna a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
    'marketing_visibilita'    => '/Marketing[\s\-]*visibilit[àáa]\s*([\d\.,]+)\s*€/ui',
    'tariffa_tempo_attesa'    => '/-\s*Tariffa\s+per\s+tempo\s+di\s+attesa\s*([-]?\d[\d\.,]*)\s*€/ui',
    'buoni_pasto'             => '/-\s*Buoni\s+pasto\s*([-]?\d[\d\.,]*)\s*€/ui',
];

// ESTRAI N. FATTURA + CAMPI DA AGGIORNARE
function estraiCampi($text, $patterns) {
    $data = ['n_fattura' => null];

    // N. fattura per la chiave di UPDATE
    if (preg_match('/\b([A-Z0-9][A-Z0-9\-\/]{7,})\s+N\. fattura:/', $text, $m)) {
        $data['n_fattura'] = trim($m[1]);
    }

    // Campi con pattern corretti
    foreach ($patterns as $key => $regex) {
        if (preg_match($regex, $text, $m)) {
            $data[$key] = normalizeEuroAmount($m[1]);
        } else {
            $data[$key] = null;
        }
    }

    return $data;
}

// FASE 1: IDENTIFICA RECORD CON CAMPI NULL
$table = $config['db_table'];
$sqlSelect = "SELECT file_pdf, n_fattura,
                     promo_consegna_partner,
                     marketing_visibilita,
                     tariffa_tempo_attesa,
                     buoni_pasto
              FROM `$table`
              WHERE promo_consegna_partner IS NULL
                 OR marketing_visibilita IS NULL
                 OR tariffa_tempo_attesa IS NULL
                 OR buoni_pasto IS NULL
              ORDER BY n_fattura";

$result = $mysqli->query($sqlSelect);
if (!$result) {
    die("Errore query: " . $mysqli->error);
}

$recordsDaAggiornare = [];
while ($row = $result->fetch_assoc()) {
    $recordsDaAggiornare[$row['n_fattura']] = [
        'file_pdf' => $row['file_pdf'],
        'n_fattura' => $row['n_fattura'],
        'promo_consegna_partner_old' => $row['promo_consegna_partner'],
        'marketing_visibilita_old' => $row['marketing_visibilita'],
        'tariffa_tempo_attesa_old' => $row['tariffa_tempo_attesa'],
        'buoni_pasto_old' => $row['buoni_pasto'],
    ];
}

$totaleRecords = count($recordsDaAggiornare);

if ($totaleRecords === 0) {
    $msg = "Nessun record con campi NULL da aggiornare. Tutti i dati sono già completi!";
    if ($isCli) {
        echo "$msg\n";
    } else {
        echo "<!DOCTYPE html><html><body><h2>$msg</h2></body></html>";
    }
    exit;
}

// FASE 2: RIPROCESSAMENTO PDF
$pdfParser = new Parser();
$aggiornati = [];
$errori = [];
$nessunCampoNuovo = 0;

foreach ($recordsDaAggiornare as $nFattura => $record) {
    $fileName = $record['file_pdf'];
    $filePath = $processedDir . '/' . $fileName;

    if (!file_exists($filePath)) {
        $errori[] = [
            'file' => $fileName,
            'fattura' => $nFattura,
            'errore' => 'PDF non trovato in processed/',
        ];
        continue;
    }

    try {
        $pdf = $pdfParser->parseFile($filePath);
        $pages = $pdf->getPages();
        if (!isset($pages[0])) {
            $errori[] = [
                'file' => $fileName,
                'fattura' => $nFattura,
                'errore' => 'PDF vuoto o senza pagine',
            ];
            continue;
        }

        $text = $pages[0]->getText();
        $dataEstratta = estraiCampi($text, $patterns);

        if ($dataEstratta['n_fattura'] !== $nFattura) {
            $errori[] = [
                'file' => $fileName,
                'fattura' => $nFattura,
                'errore' => 'N. fattura non corrisponde (PDF: ' . $dataEstratta['n_fattura'] . ')',
            ];
            continue;
        }

        // Verifica se ci sono nuovi valori da aggiungere
        $campiNuovi = [];
        foreach (['promo_consegna_partner', 'marketing_visibilita', 'tariffa_tempo_attesa', 'buoni_pasto'] as $campo) {
            $vecchioValore = $record[$campo . '_old'];
            $nuovoValore = $dataEstratta[$campo];

            // Aggiungi SOLO se il vecchio è NULL e il nuovo NON è NULL
            if ($vecchioValore === null && $nuovoValore !== null) {
                $campiNuovi[$campo] = $nuovoValore;
            }
        }

        if (empty($campiNuovi)) {
            $nessunCampoNuovo++;
            continue;
        }

        // FASE 3: UPDATE CONDIZIONALE (solo se non in dry-run)
        if (!$dryRun) {
            $setClauses = [];
            $params = [];
            $types = '';

            foreach ($campiNuovi as $campo => $valore) {
                $setClauses[] = "`$campo` = CASE WHEN `$campo` IS NULL THEN ? ELSE `$campo` END";
                $params[] = $valore;
                $types .= 's';
            }

            // N. fattura per WHERE
            $params[] = $nFattura;
            $types .= 's';

            $sqlUpdate = "UPDATE `$table` SET " . implode(', ', $setClauses) . " WHERE n_fattura = ?";

            $stmt = $mysqli->prepare($sqlUpdate);
            if (!$stmt) {
                $errori[] = [
                    'file' => $fileName,
                    'fattura' => $nFattura,
                    'errore' => 'Errore prepare: ' . $mysqli->error,
                ];
                continue;
            }

            $stmt->bind_param($types, ...$params);
            $stmt->execute();

            if ($stmt->error) {
                $errori[] = [
                    'file' => $fileName,
                    'fattura' => $nFattura,
                    'errore' => 'Errore execute: ' . $stmt->error,
                ];
                continue;
            }
        }

        $aggiornati[] = [
            'file' => $fileName,
            'fattura' => $nFattura,
            'campi_nuovi' => $campiNuovi,
        ];

    } catch (Exception $e) {
        $errori[] = [
            'file' => $fileName,
            'fattura' => $nFattura,
            'errore' => $e->getMessage(),
        ];
    }
}

// OUTPUT
$totaleAggiornati = count($aggiornati);
$totaleErrori = count($errori);

if ($isCli) {
    // OUTPUT CLI
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║     BACKFILL PATTERN FIX - " . ($dryRun ? "DRY-RUN" : "ESECUZIONE REALE") . "                      ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

    echo "📊 STATISTICHE:\n";
    echo "   Record con campi NULL: $totaleRecords\n";
    echo "   Record aggiornabili:   $totaleAggiornati\n";
    echo "   Senza nuovi valori:    $nessunCampoNuovo\n";
    echo "   Errori:                $totaleErrori\n\n";

    if ($totaleAggiornati > 0) {
        echo "✅ RECORD AGGIORNATI:\n";
        foreach (array_slice($aggiornati, 0, 10) as $rec) {
            echo "   📄 {$rec['fattura']}: ";
            $campi = [];
            foreach ($rec['campi_nuovi'] as $campo => $valore) {
                $campi[] = "$campo=$valore";
            }
            echo implode(', ', $campi) . "\n";
        }
        if ($totaleAggiornati > 10) {
            echo "   ... e altri " . ($totaleAggiornati - 10) . " record\n";
        }
        echo "\n";
    }

    if ($totaleErrori > 0) {
        echo "❌ ERRORI:\n";
        foreach (array_slice($errori, 0, 5) as $err) {
            echo "   📄 {$err['fattura']}: {$err['errore']}\n";
        }
        if ($totaleErrori > 5) {
            echo "   ... e altri " . ($totaleErrori - 5) . " errori\n";
        }
        echo "\n";
    }

    if ($dryRun) {
        echo "⚠️  MODALITÀ DRY-RUN: Nessuna modifica è stata effettuata al database.\n";
        echo "    Esegui senza --dry-run per applicare le modifiche.\n";
    } else {
        echo "✅ BACKFILL COMPLETATO CON SUCCESSO!\n";
    }

} else {
    // OUTPUT HTML
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Backfill Pattern Fix</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                padding: 20px;
            }
            .container {
                max-width: 1000px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                overflow: hidden;
            }
            .header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 40px;
                text-align: center;
            }
            .header h1 {
                font-size: 2.5em;
                margin-bottom: 10px;
            }
            .content {
                padding: 40px;
            }
            .stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
                margin: 20px 0;
            }
            .stat-box {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                text-align: center;
            }
            .stat-value {
                font-size: 2em;
                font-weight: bold;
                color: #667eea;
                margin-bottom: 5px;
            }
            .stat-label {
                font-size: 0.9em;
                color: #666;
            }
            .record-list {
                margin: 20px 0;
            }
            .record-item {
                background: #f8f9fa;
                padding: 15px;
                margin: 10px 0;
                border-radius: 8px;
                border-left: 4px solid #28a745;
            }
            .record-item.error {
                border-left-color: #dc3545;
            }
            .btn {
                display: inline-block;
                padding: 14px 28px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 600;
                border: none;
                cursor: pointer;
                margin-top: 20px;
            }
            .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            }
            .warning {
                background: #fff3cd;
                border-left: 4px solid #ffc107;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
            }
            .success {
                background: #d4edda;
                border-left: 4px solid #28a745;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>🔄 Backfill Pattern Fix</h1>
                <p><?php echo $dryRun ? 'Modalità DRY-RUN (Anteprima)' : 'Esecuzione Completata'; ?></p>
            </div>

            <div class="content">
                <div class="stats">
                    <div class="stat-box">
                        <div class="stat-value"><?php echo $totaleRecords; ?></div>
                        <div class="stat-label">Record con NULL</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?php echo $totaleAggiornati; ?></div>
                        <div class="stat-label">Aggiornabili</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?php echo $nessunCampoNuovo; ?></div>
                        <div class="stat-label">Senza nuovi valori</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?php echo $totaleErrori; ?></div>
                        <div class="stat-label">Errori</div>
                    </div>
                </div>

                <?php if ($dryRun): ?>
                <div class="warning">
                    <strong>⚠️ MODALITÀ DRY-RUN</strong><br>
                    Questa è un'anteprima. Nessuna modifica è stata effettuata al database.
                    Per eseguire il backfill reale, clicca il pulsante sotto.
                </div>
                <?php else: ?>
                <div class="success">
                    <strong>✅ BACKFILL COMPLETATO</strong><br>
                    <?php echo $totaleAggiornati; ?> record sono stati aggiornati con successo nel database.
                </div>
                <?php endif; ?>

                <?php if ($totaleAggiornati > 0): ?>
                <h2>✅ Record Aggiornati (primi 20)</h2>
                <div class="record-list">
                    <?php foreach (array_slice($aggiornati, 0, 20) as $rec): ?>
                    <div class="record-item">
                        <strong>📄 <?php echo htmlspecialchars($rec['fattura']); ?></strong><br>
                        <small>
                            <?php
                            $campi = [];
                            foreach ($rec['campi_nuovi'] as $campo => $valore) {
                                $campi[] = "$campo = $valore";
                            }
                            echo htmlspecialchars(implode(' | ', $campi));
                            ?>
                        </small>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($totaleAggiornati > 20): ?>
                        <p style="text-align: center; color: #666; margin-top: 10px;">
                            ... e altri <?php echo $totaleAggiornati - 20; ?> record
                        </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($totaleErrori > 0): ?>
                <h2>❌ Errori (primi 10)</h2>
                <div class="record-list">
                    <?php foreach (array_slice($errori, 0, 10) as $err): ?>
                    <div class="record-item error">
                        <strong>📄 <?php echo htmlspecialchars($err['fattura']); ?></strong><br>
                        <small><?php echo htmlspecialchars($err['errore']); ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div style="text-align: center; margin-top: 30px;">
                    <?php if ($dryRun): ?>
                    <form method="POST" onsubmit="return confirm('Confermi di voler eseguire il backfill su <?php echo $totaleAggiornati; ?> record?');">
                        <input type="hidden" name="confirm" value="1">
                        <button type="submit" class="btn">🚀 Esegui Backfill Reale</button>
                    </form>
                    <?php endif; ?>
                    <a href="?" class="btn" style="background: #6c757d; margin-left: 10px;">↻ Ricarica Pagina</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}
