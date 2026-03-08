<?php
// test_pdf_singolo.php - Script per testare l'estrazione di un singolo PDF

require __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;

if ($argc < 2) {
    die("Uso: php test_pdf_singolo.php <nome_file.pdf>\n");
}

$fileName = $argv[1];
$pdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf');

// Cerca il file in tutte le cartelle
$possibiliPercorsi = [
    $pdfDir . '/' . $fileName,
    $pdfDir . '/processed/' . $fileName,
    $pdfDir . '/failed/' . $fileName,
];

$pdfFile = null;
foreach ($possibiliPercorsi as $percorso) {
    if (file_exists($percorso)) {
        $pdfFile = $percorso;
        break;
    }
}

if (!$pdfFile) {
    die("File non trovato in nessuna cartella: $fileName\n");
}

echo "========================================\n";
echo "FILE: $fileName\n";
echo "PERCORSO: $pdfFile\n";
echo "========================================\n\n";

$pdfParser = new Parser();
$pdf = $pdfParser->parseFile($pdfFile);
$pages = $pdf->getPages();

if (!isset($pages[0])) {
    die("Nessuna pagina trovata nel PDF\n");
}

$text = $pages[0]->getText();

// Mostra il testo grezzo intorno a "Importo del bonifico"
echo "TESTO GREZZO INTORNO A 'Importo del bonifico':\n";
echo str_repeat('-', 80) . "\n";
if (preg_match('/(.{0,100}Importo del bonifico.{0,100})/s', $text, $m)) {
    echo $m[1] . "\n";
    echo str_repeat('-', 80) . "\n";
    echo "HEX: " . bin2hex($m[1]) . "\n";
} else {
    echo "ATTENZIONE: 'Importo del bonifico' non trovato nel testo!\n";
}
echo "\n";

// Test del regex attuale
echo "TEST REGEX ATTUALE:\n";
echo str_repeat('-', 80) . "\n";
$pattern = '/Importo del bonifico\s*(-?[\d\.,]+|-)\s*€/u';
if (preg_match($pattern, $text, $m)) {
    echo "✅ MATCH TROVATO!\n";
    echo "Valore catturato: '{$m[1]}'\n";
    echo "HEX: " . bin2hex($m[1]) . "\n";

    // Applica normalizzazione
    $val = trim($m[1]);
    if ($val === '' || $val === '-') {
        echo "Dopo normalizzazione: NULL (era solo '-')\n";
    } else {
        $val = str_replace(['€', ' ', '.'], '', $val);
        $val = str_replace(',', '.', $val);
        echo "Dopo normalizzazione: '$val'\n";
    }
} else {
    echo "❌ NESSUN MATCH!\n";
    echo "Il regex non ha trovato corrispondenze.\n";
}
echo "\n";

// Test varianti del regex
echo "TEST VARIANTI REGEX:\n";
echo str_repeat('-', 80) . "\n";

$varianti = [
    'Con spazi opzionali' => '/Importo\s+del\s+bonifico\s*(-?[\d\.,]+|-)\s*€/u',
    'Case insensitive' => '/Importo del bonifico\s*(-?[\d\.,]+|-)\s*€/ui',
    'Con a capo opzionale' => '/Importo del bonifico[\s\n]*(-?[\d\.,]+|-)[\s\n]*€/u',
    'Più flessibile' => '/Importo\s*del\s*bonifico\s*(-?[\d\.,]+|-)\s*€/u',
];

foreach ($varianti as $nome => $pattern) {
    if (preg_match($pattern, $text, $m)) {
        echo "✅ $nome: MATCH → '{$m[1]}'\n";
    } else {
        echo "❌ $nome: NESSUN MATCH\n";
    }
}

echo "\n";
echo "========================================\n";
echo "TESTO COMPLETO DEL PDF (prime 2000 char):\n";
echo "========================================\n";
echo substr($text, 0, 2000) . "\n...\n";
