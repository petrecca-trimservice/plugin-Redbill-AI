<?php
/**
 * Script web per testare l'estrazione di un PDF specifico
 * Accedi via browser: https://tuodominio.it/scripts-Glovo/test_estrazione_web.php?pdf=nome_file.pdf
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;

// Ottieni il nome del PDF da parametro GET
$pdfName = $_GET['pdf'] ?? '';

if (empty($pdfName)) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Test Estrazione PDF</title>
        <style>
            body { font-family: monospace; padding: 20px; background: #f5f5f5; }
            .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
            h1 { color: #333; }
            .form-group { margin: 20px 0; }
            input[type="text"] { width: 100%; padding: 10px; font-size: 14px; }
            button { padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer; font-size: 14px; }
            button:hover { background: #0056b3; }
            .recent-pdfs { margin-top: 20px; }
            .recent-pdfs a { display: block; padding: 8px; margin: 4px 0; background: #e9ecef; text-decoration: none; color: #333; border-radius: 4px; }
            .recent-pdfs a:hover { background: #dee2e6; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Test Estrazione PDF Glovo</h1>
            <div class="form-group">
                <form method="get">
                    <label>Nome file PDF (senza percorso):</label>
                    <input type="text" name="pdf" placeholder="es: I25025GF6I000001.pdf o 20260120-I26025GF6I000002.pdf" required>
                    <br><br>
                    <button type="submit">Analizza PDF</button>
                </form>
            </div>

            <div class="recent-pdfs">
                <h3>PDF recenti in processed/:</h3>
                <?php
                $pdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf');
                $processedDir = $pdfDir . '/processed';
                if (is_dir($processedDir)) {
                    $files = glob($processedDir . '/*.pdf');
                    usort($files, function($a, $b) {
                        return filemtime($b) - filemtime($a);
                    });
                    $files = array_slice($files, 0, 10);
                    foreach ($files as $file) {
                        $name = basename($file);
                        echo "<a href='?pdf=" . urlencode($name) . "'>$name</a>";
                    }
                } else {
                    echo "<p>Cartella processed/ non trovata</p>";
                }
                ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Cerca il PDF
$pdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf');
$possibiliPercorsi = [
    $pdfDir . '/' . $pdfName,
    $pdfDir . '/processed/' . $pdfName,
    $pdfDir . '/failed/' . $pdfName,
];

$pdfFile = null;
foreach ($possibiliPercorsi as $percorso) {
    if (file_exists($percorso)) {
        $pdfFile = $percorso;
        break;
    }
}

if (!$pdfFile) {
    die("<h1>Errore</h1><p>File non trovato: " . htmlspecialchars($pdfName) . "</p><p><a href='?'>Torna indietro</a></p>");
}

// Estrai testo
$pdfParser = new Parser();
$pdf = $pdfParser->parseFile($pdfFile);
$pages = $pdf->getPages();

if (!isset($pages[0])) {
    die("<h1>Errore</h1><p>Nessuna pagina trovata nel PDF</p>");
}

$text = $pages[0]->getText();

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test: <?php echo htmlspecialchars($pdfName); ?></title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #333; font-size: 20px; }
        h2 { color: #666; font-size: 16px; border-bottom: 2px solid #007bff; padding-bottom: 5px; margin-top: 30px; }
        .success { background: #d4edda; padding: 10px; border-left: 4px solid #28a745; margin: 10px 0; }
        .error { background: #f8d7da; padding: 10px; border-left: 4px solid #dc3545; margin: 10px 0; }
        .info { background: #d1ecf1; padding: 10px; border-left: 4px solid #17a2b8; margin: 10px 0; }
        .code { background: #f8f9fa; padding: 15px; border: 1px solid #dee2e6; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; font-size: 12px; }
        .hex { color: #6c757d; font-size: 11px; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        table td, table th { border: 1px solid #dee2e6; padding: 8px; text-align: left; }
        table th { background: #e9ecef; font-weight: bold; }
        .back-link { display: inline-block; margin: 20px 0; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px; }
        .back-link:hover { background: #5a6268; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Test Estrazione: <?php echo htmlspecialchars($pdfName); ?></h1>
        <p><strong>Percorso:</strong> <?php echo htmlspecialchars($pdfFile); ?></p>

        <h2>1. Test Regex: Importo del bonifico</h2>
        <?php
        $pattern = '/Importo del bonifico\s*(-?[\d\.,]+|-)\s*€/u';
        if (preg_match($pattern, $text, $m)) {
            echo '<div class="success">✅ MATCH TROVATO!</div>';
            echo '<table>';
            echo '<tr><th>Valore catturato</th><td>' . htmlspecialchars($m[1]) . '</td></tr>';
            echo '<tr><th>HEX</th><td class="hex">' . bin2hex($m[1]) . '</td></tr>';

            // Normalizza
            $val = trim($m[1]);
            if ($val === '' || $val === '-') {
                $normalized = 'NULL';
            } else {
                $val = str_replace(['€', ' ', '.'], '', $val);
                $val = str_replace(',', '.', $val);
                $normalized = $val;
            }
            echo '<tr><th>Dopo normalizzazione</th><td>' . htmlspecialchars($normalized) . '</td></tr>';
            echo '</table>';
        } else {
            echo '<div class="error">❌ NESSUN MATCH! Il regex non trova "Importo del bonifico"</div>';
        }
        ?>

        <h2>2. Testo intorno a "Importo del bonifico"</h2>
        <?php
        if (preg_match('/(.{0,200}Importo del bonifico.{0,200})/su', $text, $m)) {
            echo '<div class="code">' . htmlspecialchars($m[1]) . '</div>';
            echo '<p class="hex">HEX: ' . bin2hex($m[1]) . '</p>';
        } else {
            echo '<div class="error">❌ La stringa "Importo del bonifico" non è stata trovata nel testo!</div>';
        }
        ?>

        <h2>3. Test Varianti Regex</h2>
        <table>
            <tr><th>Variante</th><th>Risultato</th></tr>
            <?php
            $varianti = [
                'Attuale' => '/Importo del bonifico\s*(-?[\d\.,]+|-)\s*€/u',
                'Case insensitive' => '/Importo del bonifico\s*(-?[\d\.,]+|-)\s*€/ui',
                'Spazi flessibili' => '/Importo\s+del\s+bonifico\s*(-?[\d\.,]+|-)\s*€/u',
                'Con a capo' => '/Importo del bonifico[\s\n\r]*(-?[\d\.,]+|-)[\s\n\r]*€/u',
                'Molto flessibile' => '/Importo\s*del\s*bonifico\s*(-?[\d\.,]+|-)[\s\n\r]*€/u',
            ];

            foreach ($varianti as $nome => $pat) {
                if (preg_match($pat, $text, $m)) {
                    echo '<tr><td>' . htmlspecialchars($nome) . '</td><td style="color: green;">✅ MATCH: ' . htmlspecialchars($m[1]) . '</td></tr>';
                } else {
                    echo '<tr><td>' . htmlspecialchars($nome) . '</td><td style="color: red;">❌ NO MATCH</td></tr>';
                }
            }
            ?>
        </table>

        <h2>4. Testo completo PDF (primi 3000 caratteri)</h2>
        <div class="code"><?php echo htmlspecialchars(substr($text, 0, 3000)); ?>...</div>

        <a href="?" class="back-link">← Torna alla lista</a>
    </div>
</body>
</html>
<?php
