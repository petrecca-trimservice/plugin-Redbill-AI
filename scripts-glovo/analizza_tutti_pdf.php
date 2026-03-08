<?php
/**
 * analizza_tutti_pdf.php
 *
 * Analisi completa di tutti i PDF delle fatture Glovo:
 * - Testa tutti i pattern esistenti
 * - Confronta con database
 * - Identifica voci non mappate
 * - Genera report HTML interattivo
 *
 * Uso: php analizza_tutti_pdf.php [pdf_directory]
 *      oppure accesso web per vedere il report
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(600); // 10 minuti max

require __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;

// CONFIG
$config = require __DIR__ . '/config-glovo.php';

// PATH PDF
$defaultPdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf/processed');
$pdfDir = $argv[1] ?? $_GET['pdf_dir'] ?? $defaultPdfDir;

if (!$pdfDir || !is_dir($pdfDir)) {
    die("⚠️ Directory PDF non trovata: $pdfDir\n\nUso: php analizza_tutti_pdf.php [pdf_directory]\n");
}

// OUTPUT
$outputDir = __DIR__ . '/analisi-output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

$reportFile = $outputDir . '/report_' . date('Y-m-d_His') . '.html';
$csvFile = $outputDir . '/dettaglio_' . date('Y-m-d_His') . '.csv';

// ========================================
// FUNZIONI DI UTILITÀ
// ========================================

function normalizeEuroAmount($val) {
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '' || $val === '-') return null;
    $val = str_replace(['€', ' ', '.'], '', $val);
    return str_replace(',', '.', $val);
}

function sanitizeString($str) {
    if (!$str) return null;
    $str = preg_replace('/\s+/', ' ', $str);
    return trim($str) ?: null;
}

// ========================================
// PATTERN DI ESTRAZIONE
// ========================================

$patterns = [
    'n_fattura'                   => '/\b([A-Z0-9][A-Z0-9\-\/]{7,})\s+N\. fattura:/',
    'data'                        => '/Data:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/',
    'periodo_da'                  => '/Servizio fornito da\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/',
    'periodo_a'                   => '/a\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/',
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

// ========================================
// CONNESSIONE DATABASE
// ========================================

try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("❌ Errore connessione DB: " . $e->getMessage() . "\n");
}

// ========================================
// SCANSIONE PDF
// ========================================

echo "🔍 Scansione PDF in: $pdfDir\n";
$pdfFiles = glob($pdfDir . '/*.pdf');
$totalFiles = count($pdfFiles);

if ($totalFiles === 0) {
    die("⚠️ Nessun PDF trovato in $pdfDir\n");
}

echo "📄 Trovati $totalFiles PDF da analizzare\n";
echo "⏳ Inizio analisi...\n\n";

$parser = new Parser();
$results = [];
$unmappedLines = [];
$stats = [
    'total_pdfs' => $totalFiles,
    'processed' => 0,
    'errors' => 0,
    'pattern_stats' => [],
];

// Inizializza statistiche pattern
foreach ($patterns as $key => $pattern) {
    $stats['pattern_stats'][$key] = [
        'matched' => 0,
        'not_matched' => 0,
        'with_minus' => 0,
        'without_minus' => 0,
        'values' => [],
    ];
}

// Progress bar
$startTime = time();
$lastProgress = 0;

foreach ($pdfFiles as $index => $pdfPath) {
    $fileName = basename($pdfPath);

    // Progress
    $progress = (int)(($index / $totalFiles) * 100);
    if ($progress > $lastProgress && $progress % 5 === 0) {
        $elapsed = time() - $startTime;
        $eta = $elapsed > 0 ? (int)(($elapsed / $index) * ($totalFiles - $index)) : 0;
        echo sprintf("⏳ Progresso: %d%% (%d/%d) - ETA: %ds\n", $progress, $index, $totalFiles, $eta);
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

        // Test ogni pattern
        $pdfResult = [
            'file' => $fileName,
            'matches' => [],
            'raw_lines' => [],
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $value = $m[1] ?? null;
                $normalizedValue = in_array($key, ['n_fattura', 'data', 'periodo_da', 'periodo_a'])
                    ? $value
                    : normalizeEuroAmount($value);

                $pdfResult['matches'][$key] = [
                    'matched' => true,
                    'value' => $normalizedValue,
                    'raw' => $m[0] ?? null,
                ];

                $stats['pattern_stats'][$key]['matched']++;
                $stats['pattern_stats'][$key]['values'][] = $normalizedValue;

                // Analisi segno +/-
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
                $pdfResult['matches'][$key] = [
                    'matched' => false,
                    'value' => null,
                    'raw' => null,
                ];
                $stats['pattern_stats'][$key]['not_matched']++;
            }
        }

        // Trova righe con € non mappate
        $euroLines = [];
        if (preg_match_all('/^.*?[\d\.,]+\s*€.*$/um', $text, $matches)) {
            foreach ($matches[0] as $line) {
                $line = trim($line);
                // Verifica se è già matchata da un pattern
                $alreadyMatched = false;
                foreach ($pdfResult['matches'] as $matchData) {
                    if ($matchData['matched'] && $matchData['raw'] && stripos($line, str_replace(['+', '-'], '', trim($matchData['raw']))) !== false) {
                        $alreadyMatched = true;
                        break;
                    }
                }

                if (!$alreadyMatched && strlen($line) > 10 && strlen($line) < 200) {
                    $euroLines[] = $line;

                    // Normalizza la riga per raggruppamento
                    $normalized = preg_replace('/[\d\.,]+/', 'NUM', $line);
                    $normalized = preg_replace('/[0-9]{4}-[0-9]{2}-[0-9]{2}/', 'DATE', $normalized);

                    if (!isset($unmappedLines[$normalized])) {
                        $unmappedLines[$normalized] = [
                            'pattern' => $normalized,
                            'examples' => [],
                            'count' => 0,
                        ];
                    }

                    if (count($unmappedLines[$normalized]['examples']) < 5) {
                        $unmappedLines[$normalized]['examples'][] = $line;
                    }
                    $unmappedLines[$normalized]['count']++;
                }
            }
        }

        $pdfResult['unmapped_lines'] = $euroLines;
        $results[] = $pdfResult;

    } catch (Exception $e) {
        $stats['errors']++;
        echo "  ⚠️ Errore in $fileName: " . $e->getMessage() . "\n";
    }
}

echo "\n✅ Analisi completata!\n";
echo "📊 PDF processati: {$stats['processed']}/{$stats['total_pdfs']}\n";
echo "❌ Errori: {$stats['errors']}\n\n";

// ========================================
// CONFRONTO CON DATABASE
// ========================================

echo "🔍 Confronto con database...\n";

$dbComparison = [];
$stmt = $pdo->query("SELECT file_pdf, n_fattura, commissioni, promo_consegna_partner, costi_offerta_lampo, promo_lampo_partner FROM {$config['db_table']}");
$dbRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "📊 Record in DB: " . count($dbRecords) . "\n";

foreach ($dbRecords as $dbRow) {
    $fileName = $dbRow['file_pdf'];

    // Trova il corrispondente nei risultati PDF
    $pdfResult = null;
    foreach ($results as $r) {
        if ($r['file'] === $fileName) {
            $pdfResult = $r;
            break;
        }
    }

    if (!$pdfResult) {
        $dbComparison[] = [
            'file' => $fileName,
            'status' => 'PDF_NOT_FOUND',
            'message' => 'Record in DB ma PDF non trovato nella directory analizzata',
        ];
        continue;
    }

    // Confronta campi chiave
    $discrepancies = [];

    foreach (['commissioni', 'promo_consegna_partner', 'costi_offerta_lampo', 'promo_lampo_partner'] as $field) {
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
        } elseif ($dbValue !== null && !$pdfMatched) {
            $discrepancies[] = [
                'field' => $field,
                'issue' => 'IN_DB_BUT_NOT_MATCHED',
                'db_value' => $dbValue,
                'pdf_value' => null,
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

echo "🔍 Discrepanze trovate: " . count($dbComparison) . "\n\n";

// ========================================
// GENERA REPORT HTML
// ========================================

echo "📝 Generazione report HTML...\n";

// Ordina voci non mappate per frequenza
uasort($unmappedLines, function($a, $b) {
    return $b['count'] - $a['count'];
});

$html = generateHTMLReport($stats, $unmappedLines, $dbComparison, $patterns, $results);
file_put_contents($reportFile, $html);

echo "✅ Report salvato: $reportFile\n";

// ========================================
// GENERA CSV DETTAGLIATO
// ========================================

echo "📝 Generazione CSV dettagliato...\n";

$csv = fopen($csvFile, 'w');
fputcsv($csv, ['File PDF', 'Campo', 'Matched', 'Valore', 'Riga Raw']);

foreach ($results as $result) {
    foreach ($result['matches'] as $field => $matchData) {
        fputcsv($csv, [
            $result['file'],
            $field,
            $matchData['matched'] ? 'SI' : 'NO',
            $matchData['value'] ?? '',
            $matchData['raw'] ?? '',
        ]);
    }
}

fclose($csv);
echo "✅ CSV salvato: $csvFile\n\n";

echo "🎉 Analisi completata con successo!\n";
echo "🌐 Apri il report: $reportFile\n";

// ========================================
// FUNZIONE GENERAZIONE HTML
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
        .filter-buttons {
            margin: 20px 0;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .filter-btn {
            padding: 10px 20px;
            border: 2px solid #667eea;
            background: white;
            color: #667eea;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        .filter-btn:hover, .filter-btn.active {
            background: #667eea;
            color: white;
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
            <?php foreach ($dbComparison as $comparison): ?>
            <div class="discrepancy-card">
                <strong>📄 <?php echo htmlspecialchars($comparison['file']); ?></strong>
                <br>
                <?php if ($comparison['status'] === 'PDF_NOT_FOUND'): ?>
                    <span class="badge badge-warning">PDF Non Trovato</span>
                    <p style="margin-top: 10px;"><?php echo $comparison['message']; ?></p>
                <?php else: ?>
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
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
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

    <script>
        // Scroll smooth per tabelle lunghe
        document.querySelectorAll('.scroll-table').forEach(table => {
            table.addEventListener('scroll', function() {
                if (this.scrollTop > 50) {
                    this.style.boxShadow = 'inset 0 10px 10px -10px rgba(0,0,0,0.2)';
                } else {
                    this.style.boxShadow = 'none';
                }
            });
        });
    </script>
</body>
</html>
    <?php
    return ob_get_clean();
}
