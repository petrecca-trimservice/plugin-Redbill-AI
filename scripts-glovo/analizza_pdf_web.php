<?php
/**
 * analizza_pdf_web.php
 *
 * Interfaccia web per analizzare tutti i PDF delle fatture Glovo
 * - Configurazione directory PDF
 * - Avvio analisi
 * - Visualizzazione report
 * - Lista report precedenti
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(600);

require __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;

$config = require __DIR__ . '/config-glovo.php';

// PATH DEFAULT
$defaultPdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf/processed');
$outputDir = __DIR__ . '/analisi-output';

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

// AZIONI
$azione = $_GET['azione'] ?? $_POST['azione'] ?? 'home';

// ========================================
// ESEGUI ANALISI
// ========================================

if ($azione === 'esegui' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdfDir = $_POST['pdf_dir'] ?? $defaultPdfDir;

    if (!is_dir($pdfDir)) {
        $errore = "Directory non trovata: $pdfDir";
    } else {
        // Reindirizza a pagina di esecuzione
        header("Location: ?azione=analizza&pdf_dir=" . urlencode($pdfDir));
        exit;
    }
}

// ========================================
// PAGINA DI ANALISI CON OUTPUT IN TEMPO REALE
// ========================================

if ($azione === 'analizza') {
    $pdfDir = $_GET['pdf_dir'] ?? $defaultPdfDir;

    if (!is_dir($pdfDir)) {
        die("⚠️ Directory non trovata: $pdfDir");
    }

    // Output buffering off per vedere il progresso
    if (ob_get_level()) ob_end_clean();

    header('Content-Type: text/html; charset=utf-8');

    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Analisi in Corso - Fatture Glovo</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                padding: 20px;
                color: #333;
            }
            .container {
                max-width: 900px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                padding: 40px;
            }
            h1 {
                color: #667eea;
                margin-bottom: 20px;
                font-size: 2em;
            }
            .progress-box {
                background: #f8f9fa;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
                font-family: 'Courier New', monospace;
                font-size: 14px;
                line-height: 1.6;
                max-height: 500px;
                overflow-y: auto;
            }
            .log-line {
                margin: 5px 0;
                padding: 5px;
            }
            .log-info { color: #0066cc; }
            .log-success { color: #28a745; font-weight: bold; }
            .log-warning { color: #ffc107; }
            .log-error { color: #dc3545; font-weight: bold; }
            .spinner {
                border: 4px solid #f3f3f3;
                border-top: 4px solid #667eea;
                border-radius: 50%;
                width: 40px;
                height: 40px;
                animation: spin 1s linear infinite;
                display: inline-block;
                margin-right: 10px;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .status-bar {
                display: flex;
                align-items: center;
                padding: 20px;
                background: #e8f4ff;
                border-radius: 8px;
                margin-bottom: 20px;
            }
            .btn {
                display: inline-block;
                padding: 12px 24px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 600;
                margin-top: 20px;
                border: none;
                cursor: pointer;
            }
            .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            }
            .btn-secondary {
                background: #6c757d;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔍 Analisi Fatture Glovo in Corso...</h1>

            <div class="status-bar">
                <div class="spinner"></div>
                <div>
                    <strong>Elaborazione in corso</strong><br>
                    <small>Attendere il completamento dell'analisi...</small>
                </div>
            </div>

            <div class="progress-box" id="output">
    <?php
    flush();

    // Esegui l'analisi con output in tempo reale
    echo "<div class='log-line log-info'>📁 Directory: " . htmlspecialchars($pdfDir) . "</div>\n";
    flush();

    $pdfFiles = glob($pdfDir . '/*.pdf');
    $totalFiles = count($pdfFiles);

    if ($totalFiles === 0) {
        echo "<div class='log-line log-error'>❌ Nessun PDF trovato nella directory</div>\n";
        echo "</div></div></body></html>";
        exit;
    }

    echo "<div class='log-line log-info'>📊 Trovati $totalFiles PDF da analizzare</div>\n";
    echo "<div class='log-line log-info'>⏳ Avvio analisi...</div>\n\n";
    flush();

    // Include le funzioni necessarie
    function normalizeEuroAmount($val) {
        if ($val === null) return null;
        $val = trim($val);
        if ($val === '' || $val === '-') return null;
        $val = str_replace(['€', ' ', '.'], '', $val);
        return str_replace(',', '.', $val);
    }

    // Pattern di estrazione
    $patterns = [
        'n_fattura'                   => '/\b([A-Z0-9][A-Z0-9\-\/]{7,})\s+N\. fattura:/',
        'data'                        => '/Data:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/',
        'commissioni'                 => '/Commissioni\s*([\d\.,]+)\s*€/u',
        'marketing_visibilita'        => '/Marketing-visibilità\s*([\d\.,]+)\s*€/u',
        'subtotale'                   => '/Subtotale\s*([\d\.,]+)\s*€/u',
        'iva_22'                      => '/IVA\s*\(22\s*%\)\s*([\d\.,]+)\s*€/u',
        'totale_fattura_iva_inclusa'  => '/Totale fattura\s*\(IVA inclusa\)\s*([\d\.,]+)\s*€/us',
        'prodotti'                    => '/\+\s*Prodotti\s*([\d\.,]+)\s*€/u',
        'servizio_consegna'           => '/\+\s*Servizio di consegna\s*([\d\.,]+)\s*€/u',
        'totale_fattura_riepilogo'    => '/-\s*Totale fattura\s*([-]?\d[\d\.,]*)\s*€/u',
        'promo_prodotti_partner'      => '/-\s*Promozione sui prodotti a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
        'promo_consegna_partner'      => '/-\s*Promozione sulla consegna a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
        'costi_offerta_lampo'         => '/-?\s*Costi per offerta lampo\s*([-]?\d[\d\.,]*)\s*€/u',
        'promo_lampo_partner'         => '/-\s*Promozione lampo a carico del [Pp]artner\s*([-]?\d[\d\.,]*)\s*€/u',
        'costo_incidenti_prodotti'    => '/-\s*Costo degli incidenti relativi ai prodotti\s*([-]?\d[\d\.,]*)\s*€/u',
        'tariffa_tempo_attesa'        => '/-\s*Tariffa per tempo di attesa\s*([-]?\d[\d\.,]*)\s*€/u',
        'rimborsi_partner_senza_comm' => '/\+\s*Rimborsi al partner senza costo commissione Glovo\s*([\d\.,]+)\s*€/u',
        'costo_annullamenti_servizio' => '/-\s*Costo degli annullamenti e degli incidenti relativi al servizio\s*([-]?\d[\d\.,]*)\s*€/u',
        'consegna_gratuita_incidente' => '/-\s*Consegna gratuita in seguito a incidente\s*([-]?\d[\d\.,]*)\s*€/u',
        'buoni_pasto'                 => '/-\s*Buoni pasto\s*([-]?\d[\d\.,]*)\s*€/u',
        'supplemento_ordine_glovo_prime' => '/-?\s*Supplemento per ordine con Glovo Prime\s*([-]?\d[\d\.,]*)\s*€/u',
        'glovo_gia_pagati'            => '/-\s*Glovo già pagati\s*([-]?\d[\d\.,]*)\s*€/u',
        'debito_accumulato'           => '/-\s*Debito accumulato\s*([-]?\d[\d\.,]*)\s*€/u',
        'importo_bonifico'            => '/Importo del bonifico\s*(-?[\d\.,]+|-)\s*€/u',
    ];

    // Database connection
    try {
        $pdo = new PDO(
            "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
            $config['db_user'],
            $config['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        echo "<div class='log-line log-error'>❌ Errore DB: " . htmlspecialchars($e->getMessage()) . "</div>\n";
        flush();
        exit;
    }

    $parser = new Parser();
    $results = [];
    $unmappedLines = [];
    $stats = [
        'total_pdfs' => $totalFiles,
        'processed' => 0,
        'errors' => 0,
        'pattern_stats' => [],
    ];

    foreach ($patterns as $key => $pattern) {
        $stats['pattern_stats'][$key] = [
            'matched' => 0,
            'not_matched' => 0,
            'with_minus' => 0,
            'without_minus' => 0,
            'values' => [],
        ];
    }

    $startTime = time();
    $lastProgress = 0;

    foreach ($pdfFiles as $index => $pdfPath) {
        $fileName = basename($pdfPath);

        $progress = (int)(($index / $totalFiles) * 100);
        if ($progress > $lastProgress && $progress % 10 === 0) {
            $elapsed = time() - $startTime;
            $eta = $elapsed > 0 ? (int)(($elapsed / max($index, 1)) * ($totalFiles - $index)) : 0;
            echo "<div class='log-line log-info'>⏳ Progresso: {$progress}% ({$index}/{$totalFiles}) - ETA: {$eta}s</div>\n";
            flush();
            $lastProgress = $progress;
        }

        try {
            // Estrai testo SOLO PRIMA PAGINA del PDF
            $pdf = $parser->parseFile($pdfPath);
            $pages = $pdf->getPages();

            if (empty($pages)) {
                $stats['errors']++;
                continue;
            }

            $firstPage = $pages[0];
            $text = $firstPage->getText();

            if (empty($text)) {
                $stats['errors']++;
                continue;
            }

            $stats['processed']++;

            $pdfResult = [
                'file' => $fileName,
                'matches' => [],
            ];

            foreach ($patterns as $key => $pattern) {
                if (preg_match($pattern, $text, $m)) {
                    $value = $m[1] ?? null;
                    $normalizedValue = in_array($key, ['n_fattura', 'data'])
                        ? $value
                        : normalizeEuroAmount($value);

                    $pdfResult['matches'][$key] = [
                        'matched' => true,
                        'value' => $normalizedValue,
                        'raw' => $m[0] ?? null,
                    ];

                    $stats['pattern_stats'][$key]['matched']++;
                    $stats['pattern_stats'][$key]['values'][] = $normalizedValue;

                    if (preg_match('/^[-+]/', $m[0])) {
                        if (strpos($m[0], '-') === 0) {
                            $stats['pattern_stats'][$key]['with_minus']++;
                        } else {
                            $stats['pattern_stats'][$key]['without_minus']++;
                        }
                    } else {
                        $stats['pattern_stats'][$key]['without_minus']++;
                    }
                } else {
                    $pdfResult['matches'][$key] = ['matched' => false, 'value' => null, 'raw' => null];
                    $stats['pattern_stats'][$key]['not_matched']++;
                }
            }

            // Trova righe non mappate
            if (preg_match_all('/^.*?[\d\.,]+\s*€.*$/um', $text, $matches)) {
                foreach ($matches[0] as $line) {
                    $line = trim($line);
                    $alreadyMatched = false;
                    foreach ($pdfResult['matches'] as $matchData) {
                        if ($matchData['matched'] && $matchData['raw'] && stripos($line, str_replace(['+', '-'], '', trim($matchData['raw']))) !== false) {
                            $alreadyMatched = true;
                            break;
                        }
                    }

                    if (!$alreadyMatched && strlen($line) > 10 && strlen($line) < 200) {
                        $normalized = preg_replace('/[\d\.,]+/', 'NUM', $line);
                        $normalized = preg_replace('/[0-9]{4}-[0-9]{2}-[0-9]{2}/', 'DATE', $normalized);

                        if (!isset($unmappedLines[$normalized])) {
                            $unmappedLines[$normalized] = [
                                'pattern' => $normalized,
                                'examples' => [],
                                'count' => 0,
                            ];
                        }

                        if (count($unmappedLines[$normalized]['examples']) < 3) {
                            $unmappedLines[$normalized]['examples'][] = $line;
                        }
                        $unmappedLines[$normalized]['count']++;
                    }
                }
            }

            $results[] = $pdfResult;

        } catch (Exception $e) {
            $stats['errors']++;
        }
    }

    echo "<div class='log-line log-success'>✅ Analisi completata!</div>\n";
    echo "<div class='log-line log-info'>📊 PDF processati: {$stats['processed']}/{$stats['total_pdfs']}</div>\n";
    echo "<div class='log-line log-info'>❌ Errori: {$stats['errors']}</div>\n";
    flush();

    // Confronto DB
    echo "<div class='log-line log-info'>🔍 Confronto con database...</div>\n";
    flush();

    $dbComparison = [];
    $stmt = $pdo->query("SELECT file_pdf, promo_consegna_partner, costi_offerta_lampo FROM {$config['db_table']}");
    $dbRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dbRecords as $dbRow) {
        $fileName = $dbRow['file_pdf'];
        $pdfResult = null;
        foreach ($results as $r) {
            if ($r['file'] === $fileName) {
                $pdfResult = $r;
                break;
            }
        }

        if (!$pdfResult) continue;

        $discrepancies = [];
        foreach (['promo_consegna_partner', 'costi_offerta_lampo'] as $field) {
            $dbValue = $dbRow[$field];
            $pdfMatched = $pdfResult['matches'][$field]['matched'] ?? false;
            $pdfValue = $pdfResult['matches'][$field]['value'] ?? null;

            if ($dbValue === null && $pdfMatched) {
                $discrepancies[] = [
                    'field' => $field,
                    'issue' => 'NULL_IN_DB_BUT_FOUND',
                    'db_value' => null,
                    'pdf_value' => $pdfValue,
                ];
            }
        }

        if (!empty($discrepancies)) {
            $dbComparison[] = [
                'file' => $fileName,
                'status' => 'DISCREPANCY',
                'discrepancies' => $discrepancies,
            ];
        }
    }

    echo "<div class='log-line log-info'>🔍 Discrepanze trovate: " . count($dbComparison) . "</div>\n";
    flush();

    // Genera report
    echo "<div class='log-line log-info'>📝 Generazione report HTML...</div>\n";
    flush();

    uasort($unmappedLines, function($a, $b) {
        return $b['count'] - $a['count'];
    });

    // Salva report
    $reportFile = $outputDir . '/report_' . date('Y-m-d_His') . '.html';
    $html = generateHTMLReport($stats, $unmappedLines, $dbComparison, $patterns, $results);
    file_put_contents($reportFile, $html);

    $reportUrl = basename($reportFile);

    echo "<div class='log-line log-success'>✅ Report generato con successo!</div>\n";
    echo "</div>\n";

    echo "<div style='text-align: center; margin-top: 30px;'>";
    echo "<a href='analisi-output/{$reportUrl}' class='btn' target='_blank'>📊 Visualizza Report Completo</a> ";
    echo "<a href='?azione=home' class='btn btn-secondary'>🏠 Torna alla Home</a>";
    echo "</div>";

    echo "</div></body></html>";

    exit;
}

// ========================================
// HOME PAGE
// ========================================
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analisi Fatture Glovo PDF</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
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
        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }
        .content {
            padding: 40px;
        }
        .card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
        }
        .card h2 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.5em;
        }
        .card p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1em;
            transition: border-color 0.2s;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn {
            display: inline-block;
            padding: 14px 28px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.1em;
            border: none;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-full {
            width: 100%;
            text-align: center;
        }
        .info-box {
            background: #e8f4ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .info-box strong {
            color: #1976D2;
        }
        ul.features {
            list-style: none;
            padding: 0;
        }
        ul.features li {
            padding: 10px 0;
            padding-left: 30px;
            position: relative;
        }
        ul.features li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #28a745;
            font-weight: bold;
            font-size: 1.2em;
        }
        .reports-list {
            margin-top: 20px;
        }
        .report-item {
            background: white;
            padding: 15px;
            margin: 10px 0;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .report-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .report-name {
            font-weight: 600;
            color: #333;
        }
        .report-date {
            color: #666;
            font-size: 0.9em;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Analisi Fatture Glovo PDF</h1>
            <p>Strumento completo per analizzare pattern di estrazione e confronto con database</p>
        </div>

        <div class="content">
            <?php if (isset($errore)): ?>
            <div class="alert alert-error">
                <strong>❌ Errore:</strong> <?php echo htmlspecialchars($errore); ?>
            </div>
            <?php endif; ?>

            <div class="card">
                <h2>📋 Cosa fa questo strumento</h2>
                <ul class="features">
                    <li>Analizza tutti i PDF delle fatture Glovo nella directory specificata</li>
                    <li>Testa ogni pattern di estrazione esistente su tutti i PDF</li>
                    <li>Identifica pattern con problemi (es: segno - obbligatorio ma assente)</li>
                    <li>Trova voci con € non ancora mappate nei pattern</li>
                    <li>Confronta i dati estratti dai PDF con quelli già presenti nel database</li>
                    <li>Genera report HTML interattivo con statistiche e suggerimenti</li>
                </ul>
            </div>

            <div class="card">
                <h2>⚙️ Configura ed Esegui Analisi</h2>

                <form method="POST" action="?azione=esegui">
                    <div class="form-group">
                        <label for="pdf_dir">Directory PDF da Analizzare</label>
                        <input
                            type="text"
                            id="pdf_dir"
                            name="pdf_dir"
                            value="<?php echo htmlspecialchars($defaultPdfDir ?: ''); ?>"
                            placeholder="/percorso/ai/pdf"
                            required
                        >
                        <small style="color: #666; display: block; margin-top: 5px;">
                            Default: ../wp-content/uploads/msg-extracted/pdf/processed
                        </small>
                    </div>

                    <div class="info-box">
                        <strong>ℹ️ Nota:</strong> L'analisi può richiedere diversi minuti per grandi quantità di PDF.
                        Il progresso verrà visualizzato in tempo reale.
                    </div>

                    <button type="submit" class="btn btn-full">
                        🚀 Avvia Analisi
                    </button>
                </form>
            </div>

            <div class="card">
                <h2>📊 Report Precedenti</h2>
                <?php
                $reports = glob($outputDir . '/report_*.html');
                if (empty($reports)):
                ?>
                    <p style="color: #999; text-align: center; padding: 20px;">
                        Nessun report generato ancora. Avvia un'analisi per creare il primo report.
                    </p>
                <?php else:
                    rsort($reports); // Più recenti prima
                ?>
                    <div class="reports-list">
                        <?php foreach (array_slice($reports, 0, 10) as $report):
                            $reportName = basename($report);
                            $reportDate = filemtime($report);
                        ?>
                        <div class="report-item">
                            <div>
                                <div class="report-name">📄 <?php echo htmlspecialchars($reportName); ?></div>
                                <div class="report-date">Generato: <?php echo date('d/m/Y H:i:s', $reportDate); ?></div>
                            </div>
                            <a href="analisi-output/<?php echo htmlspecialchars($reportName); ?>" class="btn" target="_blank">
                                Visualizza
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

<?php
// ========================================
// FUNZIONE GENERAZIONE HTML REPORT
// ========================================

function generateHTMLReport($stats, $unmappedLines, $dbComparison, $patterns, $results) {
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analisi Fatture Glovo - Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 1400px;
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
        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 40px;
            background: #f8f9fa;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .stat-value {
            font-size: 2.5em;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }
        .stat-label {
            font-size: 0.9em;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .section {
            padding: 40px;
            border-bottom: 1px solid #eee;
        }
        .section:last-child {
            border-bottom: none;
        }
        .section h2 {
            font-size: 1.8em;
            margin-bottom: 25px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section h2::before {
            content: '';
            width: 4px;
            height: 30px;
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
            border-radius: 2px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 0.95em;
        }
        th {
            background: #667eea;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .progress-bar {
            width: 100%;
            height: 25px;
            background: #e9ecef;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            margin: 10px 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.85em;
            transition: width 0.3s ease;
        }
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        .unmapped-item {
            background: #f8f9fa;
            padding: 15px;
            margin: 10px 0;
            border-radius: 8px;
            border-left: 4px solid #ffc107;
        }
        .unmapped-item strong {
            color: #667eea;
        }
        .examples {
            margin-top: 10px;
            padding-left: 20px;
            color: #666;
            font-size: 0.9em;
        }
        .discrepancy-card {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 10px 0;
            border-radius: 8px;
        }
        .code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }
        .suggestions {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
        }
        .suggestions h3 {
            color: #155724;
            margin-bottom: 15px;
        }
        .suggestions ul {
            margin-left: 20px;
        }
        .suggestions li {
            margin: 8px 0;
            color: #155724;
        }
        .scroll-table {
            max-height: 600px;
            overflow-y: auto;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Analisi Fatture Glovo</h1>
            <p>Report generato il <?php echo date('d/m/Y H:i:s'); ?></p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_pdfs']; ?></div>
                <div class="stat-label">PDF Totali</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['processed']; ?></div>
                <div class="stat-label">Processati</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['errors']; ?></div>
                <div class="stat-label">Errori</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($unmappedLines); ?></div>
                <div class="stat-label">Voci Non Mappate</div>
            </div>
        </div>

        <div class="section">
            <h2>📈 Statistiche Pattern di Estrazione</h2>
            <div class="scroll-table">
                <table>
                    <thead>
                        <tr>
                            <th>Campo</th>
                            <th>Match</th>
                            <th>Non Match</th>
                            <th>Success Rate</th>
                            <th>Con Segno -</th>
                            <th>Senza Segno -</th>
                            <th>Stato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['pattern_stats'] as $field => $data):
                            $total = $data['matched'] + $data['not_matched'];
                            $successRate = $total > 0 ? ($data['matched'] / $total * 100) : 0;
                            $hasIssue = ($data['without_minus'] > 0 && strpos($patterns[$field], '/-\s*') !== false);
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($field); ?></strong></td>
                            <td><?php echo $data['matched']; ?></td>
                            <td><?php echo $data['not_matched']; ?></td>
                            <td>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $successRate; ?>%">
                                        <?php echo number_format($successRate, 1); ?>%
                                    </div>
                                </div>
                            </td>
                            <td><?php echo $data['with_minus']; ?></td>
                            <td><?php echo $data['without_minus']; ?></td>
                            <td>
                                <?php if ($hasIssue): ?>
                                    <span class="badge badge-danger">⚠️ Problema Segno</span>
                                <?php elseif ($successRate < 50): ?>
                                    <span class="badge badge-warning">Basso</span>
                                <?php elseif ($successRate < 90): ?>
                                    <span class="badge badge-info">Medio</span>
                                <?php else: ?>
                                    <span class="badge badge-success">✓ OK</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section">
            <h2>🔧 Suggerimenti per Fix</h2>
            <div class="suggestions">
                <h3>Pattern con Problemi di Segno (-) da Correggere:</h3>
                <ul>
                    <?php
                    $patternsToFix = [];
                    foreach ($stats['pattern_stats'] as $field => $data):
                        if ($data['without_minus'] > 0 && strpos($patterns[$field], '/-\s*') !== false):
                            $patternsToFix[] = $field;
                    ?>
                        <li>
                            <strong><?php echo htmlspecialchars($field); ?></strong>:
                            <?php echo $data['without_minus']; ?> occorrenze SENZA segno meno,
                            ma il pattern richiede <span class="code">/-\s*</span>
                            <br>→ <em>Suggerimento: Cambiare in <span class="code">/-?\s*</span> per renderlo opzionale</em>
                        </li>
                    <?php
                        endif;
                    endforeach;

                    if (empty($patternsToFix)):
                    ?>
                        <li>✅ Nessun problema rilevato con i segni +/-</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div class="section">
            <h2>🔍 Voci Non Mappate (Top 20)</h2>
            <p style="color: #666; margin-bottom: 20px;">
                Righe contenenti € che non corrispondono a nessun pattern esistente, ordinate per frequenza.
            </p>
            <?php
            $count = 0;
            foreach ($unmappedLines as $lineData):
                if ($count >= 20) break;
                $count++;
            ?>
            <div class="unmapped-item">
                <strong>Occorrenze: <?php echo $lineData['count']; ?></strong>
                <br>
                Pattern: <span class="code"><?php echo htmlspecialchars($lineData['pattern']); ?></span>
                <div class="examples">
                    <strong>Esempi:</strong>
                    <?php foreach ($lineData['examples'] as $example): ?>
                        <br>→ <?php echo htmlspecialchars($example); ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (count($dbComparison) > 0): ?>
        <div class="section">
            <h2>🆚 Confronto Database vs PDF</h2>
            <p style="color: #666; margin-bottom: 20px;">
                Discrepanze tra dati estratti dai PDF e dati presenti nel database.
            </p>
            <?php foreach (array_slice($dbComparison, 0, 50) as $comparison): ?>
            <div class="discrepancy-card">
                <strong>📄 <?php echo htmlspecialchars($comparison['file']); ?></strong>
                <br>
                <span class="badge badge-warning">Discrepanza</span>
                <ul style="margin-top: 10px;">
                    <?php foreach ($comparison['discrepancies'] as $disc): ?>
                    <li>
                        <strong><?php echo htmlspecialchars($disc['field']); ?></strong>:
                        <?php if ($disc['issue'] === 'NULL_IN_DB_BUT_FOUND'): ?>
                            NULL in DB ma trovato nel PDF (valore: <?php echo htmlspecialchars($disc['pdf_value']); ?>)
                        <?php else: ?>
                            Presente in DB (<?php echo htmlspecialchars($disc['db_value']); ?>) ma non trovato nel PDF
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
            <?php if (count($dbComparison) > 50): ?>
                <p style="text-align: center; color: #666; margin-top: 20px;">
                    ... e altre <?php echo count($dbComparison) - 50; ?> discrepanze
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="section" style="text-align: center; padding: 60px 40px; background: #f8f9fa;">
            <h2 style="margin-bottom: 20px;">🎉 Analisi Completata</h2>
            <p style="font-size: 1.1em; color: #666; margin-bottom: 20px;">
                Report generato con successo. Usa i dati sopra per identificare e correggere i problemi di estrazione.
            </p>
            <p style="color: #999;">
                Script by Claude Code · <?php echo date('Y'); ?>
            </p>
        </div>
    </div>
</body>
</html>
    <?php
    return ob_get_clean();
}
