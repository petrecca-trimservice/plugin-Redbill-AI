<?php
/**
 * test_pattern_fix.php
 *
 * Script di test per verificare i pattern regex contro esempi reali
 * dalle voci non mappate identificate nell'analisi.
 *
 * Uso: php test_pattern_fix.php
 *      oppure accesso web
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ========================================
// ESEMPI REALI DAL REPORT
// ========================================

$testCases = [
    'promo_consegna_partner' => [
        'description' => 'Promozione sulla consegna a carico del partner',
        'examples' => [
            'Promozione sulla consegna a carico del partner 2,45 €',
            'Promozione sulla consegna a carico del partner 34,35 €',
            'Promozione sulla consegna a carico del partner 39,00 €',
            'Promozione sulla consegna a carico del partner 3,21 €',
        ],
        'pattern_old' => '/-\s*Promozione sulla consegna a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
        'pattern_new' => '/-?\s*Promozione sulla consegna a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
        'expected_values' => ['2.45', '34.35', '39.00', '3.21'],
    ],

    'marketing_visibilita' => [
        'description' => 'Marketing-visibilità',
        'examples' => [
            'Marketing-visibilità 174,18 €',
            'Marketing-visibilità 189,49 €',
            'Marketing-visibilità 185,81 €',
            'Marketing - visibilità 200,00 €', // Variante con spazio
            'Marketing-visibilitá 150,00 €', // Variante con á
        ],
        'pattern_old' => '/Marketing-visibilità\s*([\d\.,]+)\s*€/u',
        'pattern_new' => '/Marketing[\s\-]*visibilit[àáa]\s*([\d\.,]+)\s*€/ui',
        'expected_values' => ['174.18', '189.49', '185.81', '200.00', '150.00'],
    ],

    'tariffa_tempo_attesa' => [
        'description' => 'Tariffa per tempo di attesa',
        'examples' => [
            '- Tariffa per tempo di attesa -1,25 €',
            '- Tariffa per tempo di attesa -1,25 €',
            '- Tariffa per tempo di attesa -2,75 €',
            '- Tariffa  per  tempo  di  attesa -1,00 €', // Spazi multipli
            '-Tariffa per tempo di attesa -1,50 €', // Senza spazio dopo -
        ],
        'pattern_old' => '/-\s*Tariffa per tempo di attesa\s*([-]?\d[\d\.,]*)\s*€/u',
        'pattern_new' => '/-\s*Tariffa\s+per\s+tempo\s+di\s+attesa\s*([-]?\d[\d\.,]*)\s*€/ui',
        'expected_values' => ['1.25', '1.25', '2.75', '1.00', '1.50'],
    ],

    'buoni_pasto' => [
        'description' => 'Buoni pasto',
        'examples' => [
            '- Buoni pasto -80,00 €',
            '- Buoni pasto -144,08 €',
            '- Buoni pasto -271,60 €',
            '- Buoni  pasto -100,00 €', // Spazi multipli
            '-Buoni pasto -50,00 €', // Senza spazio dopo -
        ],
        'pattern_old' => '/-\s*Buoni pasto\s*([-]?\d[\d\.,]*)\s*€/u',
        'pattern_new' => '/-\s*Buoni\s+pasto\s*([-]?\d[\d\.,]*)\s*€/ui',
        'expected_values' => ['80.00', '144.08', '271.60', '100.00', '50.00'],
    ],
];

// ========================================
// FUNZIONE DI NORMALIZZAZIONE
// ========================================

function normalizeEuroAmount($val) {
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '' || $val === '-') return null;
    // Rimuovi segno negativo se presente
    $val = ltrim($val, '-');
    $val = str_replace(['€', ' ', '.'], '', $val);
    return str_replace(',', '.', $val);
}

// ========================================
// FUNZIONE DI TEST
// ========================================

function testPattern($fieldName, $testCase) {
    $results = [
        'field' => $fieldName,
        'description' => $testCase['description'],
        'old_pattern_results' => [],
        'new_pattern_results' => [],
        'old_success_count' => 0,
        'new_success_count' => 0,
        'total_tests' => count($testCase['examples']),
    ];

    foreach ($testCase['examples'] as $index => $example) {
        $expectedValue = $testCase['expected_values'][$index] ?? null;

        // Test pattern vecchio
        $oldMatch = preg_match($testCase['pattern_old'], $example, $oldMatches);
        $oldValue = $oldMatch ? normalizeEuroAmount($oldMatches[1] ?? null) : null;
        $oldSuccess = ($oldMatch && $oldValue == $expectedValue);

        if ($oldSuccess) {
            $results['old_success_count']++;
        }

        $results['old_pattern_results'][] = [
            'example' => $example,
            'matched' => $oldMatch ? 'SI' : 'NO',
            'value' => $oldValue,
            'expected' => $expectedValue,
            'success' => $oldSuccess,
        ];

        // Test pattern nuovo
        $newMatch = preg_match($testCase['pattern_new'], $example, $newMatches);
        $newValue = $newMatch ? normalizeEuroAmount($newMatches[1] ?? null) : null;
        $newSuccess = ($newMatch && $newValue == $expectedValue);

        if ($newSuccess) {
            $results['new_success_count']++;
        }

        $results['new_pattern_results'][] = [
            'example' => $example,
            'matched' => $newMatch ? 'SI' : 'NO',
            'value' => $newValue,
            'expected' => $expectedValue,
            'success' => $newSuccess,
        ];
    }

    $results['old_success_rate'] = ($results['total_tests'] > 0)
        ? round(($results['old_success_count'] / $results['total_tests']) * 100, 1)
        : 0;
    $results['new_success_rate'] = ($results['total_tests'] > 0)
        ? round(($results['new_success_count'] / $results['total_tests']) * 100, 1)
        : 0;
    $results['improvement'] = $results['new_success_rate'] - $results['old_success_rate'];

    return $results;
}

// ========================================
// ESECUZIONE TEST
// ========================================

$allResults = [];
foreach ($testCases as $fieldName => $testCase) {
    $allResults[$fieldName] = testPattern($fieldName, $testCase);
}

// ========================================
// OUTPUT
// ========================================

$isCLI = php_sapi_name() === 'cli';

if ($isCLI) {
    // Output CLI
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║         TEST PATTERN FIX - FATTURE GLOVO                         ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

    foreach ($allResults as $fieldName => $result) {
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "Campo: {$result['field']}\n";
        echo "Descrizione: {$result['description']}\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        echo "📊 RISULTATI:\n";
        echo "   Pattern Vecchio: {$result['old_success_count']}/{$result['total_tests']} ({$result['old_success_rate']}%)\n";
        echo "   Pattern Nuovo:   {$result['new_success_count']}/{$result['total_tests']} ({$result['new_success_rate']}%)\n";

        if ($result['improvement'] > 0) {
            echo "   ✅ Miglioramento: +{$result['improvement']}%\n\n";
        } elseif ($result['improvement'] < 0) {
            echo "   ⚠️ Peggioramento: {$result['improvement']}%\n\n";
        } else {
            echo "   ➖ Nessun cambiamento\n\n";
        }

        echo "📋 DETTAGLIO TEST:\n";
        foreach ($result['old_pattern_results'] as $index => $oldTest) {
            $newTest = $result['new_pattern_results'][$index];

            echo "   Test #" . ($index + 1) . ": {$oldTest['example']}\n";
            echo "      Vecchio: " . ($oldTest['success'] ? '✅' : '❌') . " Match={$oldTest['matched']}, Valore={$oldTest['value']}, Atteso={$oldTest['expected']}\n";
            echo "      Nuovo:   " . ($newTest['success'] ? '✅' : '❌') . " Match={$newTest['matched']}, Valore={$newTest['value']}, Atteso={$newTest['expected']}\n";
            echo "\n";
        }
    }

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "RIEPILOGO COMPLESSIVO\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $totalOldSuccess = array_sum(array_column($allResults, 'old_success_count'));
    $totalNewSuccess = array_sum(array_column($allResults, 'new_success_count'));
    $totalTests = array_sum(array_column($allResults, 'total_tests'));

    echo "Totale test eseguiti: $totalTests\n";
    echo "Pattern vecchi: $totalOldSuccess/$totalTests (" . round(($totalOldSuccess / $totalTests) * 100, 1) . "%)\n";
    echo "Pattern nuovi:  $totalNewSuccess/$totalTests (" . round(($totalNewSuccess / $totalTests) * 100, 1) . "%)\n";
    echo "\n";

    foreach ($allResults as $result) {
        if ($result['improvement'] > 0) {
            echo "✅ {$result['field']}: +{$result['improvement']}% di miglioramento\n";
        }
    }

} else {
    // Output HTML
    ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Pattern Fix - Fatture Glovo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 1200px;
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
        .section {
            padding: 30px 40px;
            border-bottom: 1px solid #eee;
        }
        .section:last-child {
            border-bottom: none;
        }
        .field-name {
            font-size: 1.5em;
            color: #667eea;
            margin-bottom: 10px;
            font-weight: bold;
        }
        .description {
            color: #666;
            margin-bottom: 20px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 0.9em;
            color: #666;
        }
        .improvement-positive {
            color: #28a745;
        }
        .improvement-negative {
            color: #dc3545;
        }
        .improvement-neutral {
            color: #6c757d;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .match-yes {
            color: #28a745;
            font-weight: bold;
        }
        .match-no {
            color: #dc3545;
            font-weight: bold;
        }
        .success-icon {
            font-size: 1.2em;
        }
        .pattern-box {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            overflow-x: auto;
        }
        .summary {
            background: #e8f4ff;
            border-left: 4px solid #2196F3;
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .summary h3 {
            color: #1976D2;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🧪 Test Pattern Fix</h1>
            <p>Verifica dei pattern regex su esempi reali dalle fatture Glovo</p>
        </div>

        <?php foreach ($allResults as $result): ?>
        <div class="section">
            <div class="field-name"><?php echo htmlspecialchars($result['field']); ?></div>
            <div class="description"><?php echo htmlspecialchars($result['description']); ?></div>

            <div class="stats">
                <div class="stat-box">
                    <div class="stat-value"><?php echo $result['old_success_rate']; ?>%</div>
                    <div class="stat-label">Pattern Vecchio</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo $result['new_success_rate']; ?>%</div>
                    <div class="stat-label">Pattern Nuovo</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value <?php
                        echo $result['improvement'] > 0 ? 'improvement-positive' :
                            ($result['improvement'] < 0 ? 'improvement-negative' : 'improvement-neutral');
                    ?>">
                        <?php echo ($result['improvement'] > 0 ? '+' : '') . $result['improvement']; ?>%
                    </div>
                    <div class="stat-label">Miglioramento</div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Esempio</th>
                        <th>Vecchio</th>
                        <th>Nuovo</th>
                        <th>Valore Atteso</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($result['old_pattern_results'] as $index => $oldTest):
                        $newTest = $result['new_pattern_results'][$index];
                    ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><code><?php echo htmlspecialchars($oldTest['example']); ?></code></td>
                        <td>
                            <span class="success-icon"><?php echo $oldTest['success'] ? '✅' : '❌'; ?></span>
                            <span class="<?php echo $oldTest['matched'] === 'SI' ? 'match-yes' : 'match-no'; ?>">
                                <?php echo $oldTest['matched']; ?>
                            </span>
                            (<?php echo $oldTest['value'] ?? 'null'; ?>)
                        </td>
                        <td>
                            <span class="success-icon"><?php echo $newTest['success'] ? '✅' : '❌'; ?></span>
                            <span class="<?php echo $newTest['matched'] === 'SI' ? 'match-yes' : 'match-no'; ?>">
                                <?php echo $newTest['matched']; ?>
                            </span>
                            (<?php echo $newTest['value'] ?? 'null'; ?>)
                        </td>
                        <td><?php echo $oldTest['expected']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <details>
                <summary style="cursor: pointer; font-weight: 600; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                    📝 Mostra Pattern (click per espandere)
                </summary>
                <div class="pattern-box">
                    <strong>Pattern Vecchio:</strong><br>
                    <?php echo htmlspecialchars($testCases[$result['field']]['pattern_old']); ?>
                </div>
                <div class="pattern-box">
                    <strong>Pattern Nuovo:</strong><br>
                    <?php echo htmlspecialchars($testCases[$result['field']]['pattern_new']); ?>
                </div>
            </details>
        </div>
        <?php endforeach; ?>

        <div class="section">
            <h2>📊 Riepilogo Complessivo</h2>
            <?php
            $totalOldSuccess = array_sum(array_column($allResults, 'old_success_count'));
            $totalNewSuccess = array_sum(array_column($allResults, 'new_success_count'));
            $totalTests = array_sum(array_column($allResults, 'total_tests'));
            ?>
            <div class="summary">
                <h3>Risultati Totali</h3>
                <p><strong>Test eseguiti:</strong> <?php echo $totalTests; ?></p>
                <p><strong>Pattern vecchi:</strong> <?php echo $totalOldSuccess; ?>/<?php echo $totalTests; ?>
                   (<?php echo round(($totalOldSuccess / $totalTests) * 100, 1); ?>%)</p>
                <p><strong>Pattern nuovi:</strong> <?php echo $totalNewSuccess; ?>/<?php echo $totalTests; ?>
                   (<?php echo round(($totalNewSuccess / $totalTests) * 100, 1); ?>%)</p>

                <h3 style="margin-top: 20px;">Campi Migliorati</h3>
                <ul style="margin-top: 10px;">
                    <?php foreach ($allResults as $result): ?>
                        <?php if ($result['improvement'] > 0): ?>
                            <li style="color: #28a745; margin: 5px 0;">
                                <strong><?php echo htmlspecialchars($result['field']); ?>:</strong>
                                +<?php echo $result['improvement']; ?>% di miglioramento
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
    <?php
}
