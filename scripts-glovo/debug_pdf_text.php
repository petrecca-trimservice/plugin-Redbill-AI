<?php
/**
 * Debug: mostra il testo grezzo estratto da un PDF Glovo
 *
 * Uso web: debug_pdf_text.php                  -> lista PDF in processed/
 *          debug_pdf_text.php?file=nome.pdf     -> analizza il PDF
 *          debug_pdf_text.php?search=FATTURA    -> cerca per n. fattura
 *
 * Uso CLI: php debug_pdf_text.php <file.pdf>
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;

$config = require __DIR__ . '/config-glovo.php';

$isCli = (php_sapi_name() === 'cli');

// CARTELLA PROCESSED
$pdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf');
$processedDir = $pdfDir ? $pdfDir . '/processed' : null;

if ($isCli) {
    // --- CLI (invariato) ---
    if ($argc < 2) {
        echo "Uso: php debug_pdf_text.php <file.pdf>\n";
        exit(1);
    }
    $file = $argv[1];
    if (!file_exists($file)) {
        echo "File non trovato: $file\n";
        exit(1);
    }

    $parser = new Parser();
    $pdf = $parser->parseFile($file);
    $pages = $pdf->getPages();
    if (!isset($pages[0])) {
        echo "Nessuna pagina nel PDF\n";
        exit(1);
    }

    $text = $pages[0]->getText();
    echo "=== TESTO GREZZO ESTRATTO (pagina 1) ===\n\n";
    echo $text;
    echo "\n\n=== FINE TESTO ===\n\n";

    $keywords = ['offerta lampo', 'Promozione lampo', 'Promozione sulla consegna',
                  'Promozione sui prodotti', 'consegna a carico', 'lampo a carico'];
    echo "=== RICERCA KEYWORD (case-insensitive) ===\n\n";
    foreach ($keywords as $kw) {
        if (preg_match('/.*' . preg_quote($kw, '/') . '.*/iu', $text, $m)) {
            echo "TROVATO [$kw]: " . trim($m[0]) . "\n";
        } else {
            echo "NON TROVATO [$kw]\n";
        }
    }
    exit(0);
}

// --- WEB ---

// Funzione per analizzare un PDF e restituire testo + keyword trovate
function analizzaPdf($filePath) {
    $parser = new Parser();
    $pdf = $parser->parseFile($filePath);
    $pages = $pdf->getPages();
    if (!isset($pages[0])) {
        return ['error' => 'Nessuna pagina nel PDF'];
    }

    $text = $pages[0]->getText();

    // Cerca n_fattura
    $nFattura = null;
    if (preg_match('/\b([A-Z0-9][A-Z0-9\-\/]{7,})\s+N\. fattura:/', $text, $m)) {
        $nFattura = trim($m[1]);
    }

    // Keyword da cercare
    $keywords = [
        'Promozione sulla consegna a carico',
        'Promozione sui prodotti a carico',
        'Costi per offerta lampo',
        'Promozione lampo a carico',
        'Supplemento per ordine con Glovo Prime',
        'Consegna gratuita in seguito a incidente',
        'Buoni pasto',
    ];

    $found = [];
    foreach ($keywords as $kw) {
        if (preg_match('/.*' . preg_quote($kw, '/') . '.*/iu', $text, $m)) {
            $found[$kw] = trim($m[0]);
        }
    }

    return [
        'text' => $text,
        'n_fattura' => $nFattura,
        'keywords' => $found,
    ];
}

// Parametri
$selectedFile = $_GET['file'] ?? '';
$searchTerm = trim($_GET['search'] ?? '');
$result = null;
$searchResult = null;

// Se c'è un termine di ricerca, cerca il PDF per n_fattura
if ($searchTerm !== '' && $processedDir && is_dir($processedDir)) {
    $allPdfs = glob($processedDir . '/*.pdf');
    $parser = new Parser();
    foreach ($allPdfs as $pdfPath) {
        try {
            $pdf = $parser->parseFile($pdfPath);
            $pages = $pdf->getPages();
            if (!isset($pages[0])) continue;
            $text = $pages[0]->getText();
            if (stripos($text, $searchTerm) !== false) {
                $selectedFile = basename($pdfPath);
                $searchResult = "Trovato in: $selectedFile";
                break;
            }
        } catch (Exception $e) {
            continue;
        }
    }
    if (!$selectedFile) {
        $searchResult = "Nessun PDF trovato con '$searchTerm'";
    }
}

// Analizza il PDF selezionato
if ($selectedFile !== '') {
    $filePath = $processedDir . '/' . $selectedFile;
    if (file_exists($filePath)) {
        try {
            $result = analizzaPdf($filePath);
        } catch (Exception $e) {
            $result = ['error' => $e->getMessage()];
        }
    } else {
        $result = ['error' => "File non trovato: $selectedFile"];
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Debug PDF - Testo Estratto</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1100px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { margin-top: 0; }
        .search-box { display: flex; gap: 10px; margin-bottom: 20px; }
        .search-box input[type=text] { flex: 1; padding: 10px 14px; border: 2px solid #dee2e6; border-radius: 6px; font-size: 15px; }
        .search-box button { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 6px; font-size: 15px; cursor: pointer; }
        .search-box button:hover { background: #0056b3; }
        .search-result { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .search-found { background: #d4edda; color: #155724; }
        .search-notfound { background: #f8d7da; color: #721c24; }
        .info { background: #d1ecf1; padding: 12px; border-radius: 4px; margin-bottom: 15px; }
        .kw-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .kw-table th, .kw-table td { border: 1px solid #dee2e6; padding: 8px 12px; text-align: left; }
        .kw-table th { background: #343a40; color: white; }
        .kw-found { background: #d4edda; }
        .kw-missing { background: #f8d7da; }
        .raw-text { background: #1e1e1e; color: #d4d4d4; padding: 20px; border-radius: 6px; font-family: monospace; font-size: 13px; white-space: pre-wrap; word-break: break-word; max-height: 500px; overflow-y: auto; margin-top: 15px; }
        .highlight { background: #ffc107; color: #000; padding: 1px 3px; border-radius: 2px; }
        .error { background: #f8d7da; padding: 15px; border-radius: 4px; color: #721c24; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Debug PDF - Testo Estratto</h1>

        <form method="GET">
            <div class="search-box">
                <input type="text" name="search" placeholder="Cerca per N. Fattura (es. I25A9SPAKH000002)" value="<?= htmlspecialchars($searchTerm) ?>">
                <button type="submit">Cerca</button>
            </div>
        </form>

        <?php if ($searchResult): ?>
            <div class="search-result <?= $selectedFile ? 'search-found' : 'search-notfound' ?>">
                <?= htmlspecialchars($searchResult) ?>
            </div>
        <?php endif; ?>

        <?php if ($result && isset($result['error'])): ?>
            <div class="error"><?= htmlspecialchars($result['error']) ?></div>

        <?php elseif ($result): ?>
            <div class="info">
                <strong>File:</strong> <?= htmlspecialchars($selectedFile) ?> &nbsp;|&nbsp;
                <strong>N. Fattura:</strong> <?= htmlspecialchars($result['n_fattura'] ?? 'N/A') ?>
            </div>

            <h2>Keyword trovate nel PDF</h2>
            <table class="kw-table">
                <thead>
                    <tr><th>Keyword</th><th>Riga trovata nel PDF</th></tr>
                </thead>
                <tbody>
                    <?php
                    $allKeywords = [
                        'Promozione sulla consegna a carico',
                        'Promozione sui prodotti a carico',
                        'Costi per offerta lampo',
                        'Promozione lampo a carico',
                        'Supplemento per ordine con Glovo Prime',
                        'Consegna gratuita in seguito a incidente',
                        'Buoni pasto',
                    ];
                    foreach ($allKeywords as $kw):
                        $found = isset($result['keywords'][$kw]);
                    ?>
                    <tr class="<?= $found ? 'kw-found' : 'kw-missing' ?>">
                        <td><?= htmlspecialchars($kw) ?></td>
                        <td><?= $found ? htmlspecialchars($result['keywords'][$kw]) : '<em>non trovato</em>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Testo grezzo estratto (pagina 1)</h2>
            <div class="raw-text"><?= htmlspecialchars($result['text']) ?></div>

        <?php else: ?>
            <div class="info">
                Inserisci un numero fattura per cercare il PDF corrispondente in <code>processed/</code>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
