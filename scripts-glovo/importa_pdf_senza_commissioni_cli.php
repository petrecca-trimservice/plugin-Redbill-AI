#!/usr/bin/env php
<?php
/**
 * Script CLI per importare un PDF dalla cartella failed/ senza validare 'commissioni'
 *
 * Uso:
 *   php importa_pdf_senza_commissioni_cli.php                     # Lista PDF disponibili
 *   php importa_pdf_senza_commissioni_cli.php nome_file.pdf       # Importa il PDF
 *   php importa_pdf_senza_commissioni_cli.php nome_file.pdf 150.00  # Importa con commissioni manuali
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (php_sapi_name() !== 'cli') {
    die("Questo script deve essere eseguito da CLI\n");
}

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
    die("Errore DB: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

$pdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf');
$failedDir = $pdfDir . '/failed';
$processedDir = $pdfDir . '/processed';

// Funzioni di parsing
function normalizeEuroAmount($val) {
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '' || $val === '-') return null;
    $val = str_replace(['€', ' ', '.'], '', $val);
    return str_replace(',', '.', $val);
}

function sanitizeString($val) {
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '') return null;
    $val = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $val);
    $val = preg_replace('/\s+/', ' ', $val);
    $val = preg_replace('/\bS\.?R\.?L\.?/iu', 'S.R.L.', $val);
    $val = preg_replace('/\bS\.?R\.?L\.?S\.?/iu', 'S.R.L.S.', $val);
    $val = preg_replace('/\bS\.?P\.?A\.?/iu', 'S.P.A.', $val);
    $val = preg_replace('/\bS\.?A\.?S\.?/iu', 'S.A.S.', $val);
    $val = preg_replace('/\bS\.?N\.?C\.?/iu', 'S.N.C.', $val);
    return trim($val);
}

function normalizeNegozio($val) {
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '') return null;
    $mappings = [
        'Viale Coni Zugna, 43, 20144 Milano MI, Italia' => 'Girarrosti Santa Rita - Milano',
        '307, Corso Susa, 301/307, 10098 Rivoli TO, Italy' => 'Girarrosti Santa Rita - Rivoli',
        'Via Martiri della Libertà, 74, 10099 San Mauro Torinese TO, Italia' => 'Girarrosti Santa Rita - San Mauro',
        'Via S. Mauro, 1, 10036 Settimo Torinese TO, Italia' => 'Girarrosti Santa Rita - Settimo Torinese',
        'Via Vittorio Alfieri, 9, 10043 Orbassano TO, Italia' => 'Girarrosti Santa Rita - Via Vittorio Alfieri - Orbassano',
    ];
    return $mappings[$val] ?? $val;
}

function parseDestinatario($text) {
    $result = ['destinatario' => null, 'negozio' => null];
    if (preg_match('/Foodinho Srl.*?\n(.*?)N\. fattura:/s', $text, $m)) {
        $block = trim($m[1]);
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $block))
        ));
        $result['destinatario'] = sanitizeString($lines[0] ?? null);
        $result['negozio']      = normalizeNegozio($lines[1] ?? null);
    }
    return $result;
}

function parseGlovoInvoice($text) {
    $data = [
        'destinatario' => null, 'negozio' => null, 'n_fattura' => null, 'data' => null,
        'periodo_da' => null, 'periodo_a' => null, 'commissioni' => null,
        'marketing_visibilita' => null, 'subtotale' => null, 'iva_22' => null,
        'totale_fattura_iva_inclusa' => null, 'prodotti' => null, 'servizio_consegna' => null,
        'totale_fattura_riepilogo' => null, 'promo_prodotti_partner' => null,
        'promo_consegna_partner' => null, 'costi_offerta_lampo' => null,
        'promo_lampo_partner' => null, 'costo_incidenti_prodotti' => null,
        'tariffa_tempo_attesa' => null,
        'rimborsi_partner_senza_comm' => null, 'costo_annullamenti_servizio' => null,
        'consegna_gratuita_incidente' => null, 'buoni_pasto' => null,
        'supplemento_ordine_glovo_prime' => null, 'glovo_gia_pagati' => null,
        'ordini_rimborsati_partner' => null, 'commissione_ordini_rimborsati' => null,
        'sconto_comm_ordini_buoni_pasto' => null,
        'debito_accumulato' => null, 'importo_bonifico' => null,
    ];

    $d = parseDestinatario($text);
    $data['destinatario'] = $d['destinatario'];
    $data['negozio'] = $d['negozio'];

    if (preg_match('/\b([A-Z0-9][A-Z0-9\-\/]{7,})\s+N\. fattura:/', $text, $m)) {
        $data['n_fattura'] = trim($m[1]);
    }
    if (preg_match('/Data:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/', $text, $m)) {
        $data['data'] = $m[1];
    }
    if (preg_match('/Servizio fornito da\s*([0-9]{4}-[0-9]{2}-[0-9]{2})\s*a\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/', $text, $m)) {
        $data['periodo_da'] = $m[1];
        $data['periodo_a'] = $m[2];
    }

    $patterns = [
        'commissioni' => '/Commissioni\s*([\d\.,]+)\s*€/u',
        'marketing_visibilita' => '/Marketing[\s\-]*visibilit[àáa]\s*([\d\.,]+)\s*€/ui',
        'subtotale' => '/Subtotale\s*([\d\.,]+)\s*€/u',
        'iva_22' => '/IVA\s*\(22\s*%\)\s*([\d\.,]+)\s*€/u',
        'totale_fattura_iva_inclusa' => '/Totale fattura\s*\(IVA inclusa\)\s*([\d\.,]+)\s*€/us',
        'prodotti' => '/\+\s*Prodotti\s*([\d\.,]+)\s*€/u',
        'servizio_consegna' => '/\+\s*Servizio di consegna\s*([\d\.,]+)\s*€/u',
        'totale_fattura_riepilogo' => '/-\s*Totale fattura\s*([-]?\d[\d\.,]*)\s*€/u',
        'promo_prodotti_partner' => '/-\s*Promozione sui prodotti a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
        'promo_consegna_partner' => '/-?\s*Promozione sulla consegna a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
        'costi_offerta_lampo' => '/-?\s*Costi per offerta lampo\s*([-]?\d[\d\.,]*)\s*€/u',
        'promo_lampo_partner' => '/-\s*Promozione lampo a carico del [Pp]artner\s*([-]?\d[\d\.,]*)\s*€/u',
        'costo_incidenti_prodotti' => '/-\s*Costo degli incidenti relativi ai prodotti\s*([-]?\d[\d\.,]*)\s*€/u',
        'tariffa_tempo_attesa' => '/-\s*Tariffa\s+per\s+tempo\s+di\s+attesa\s*([-]?\d[\d\.,]*)\s*€/ui',
        'rimborsi_partner_senza_comm' => '/\+\s*Rimborsi al partner senza costo commissione Glovo\s*([\d\.,]+)\s*€/u',
        'costo_annullamenti_servizio' => '/-\s*Costo degli annullamenti e degli incidenti relativi al servizio\s*([-]?\d[\d\.,]*)\s*€/u',
        'consegna_gratuita_incidente' => '/-\s*Consegna gratuita in seguito a incidente\s*([-]?\d[\d\.,]*)\s*€/u',
        'buoni_pasto' => '/-\s*Buoni\s+pasto\s*([-]?\d[\d\.,]*)\s*€/ui',
        'supplemento_ordine_glovo_prime' => '/-?\s*Supplemento per ordine con Glovo Prime\s*([-]?\d[\d\.,]*)\s*€/u',
        'glovo_gia_pagati' => '/-\s*Glovo già pagati\s*([-]?\d[\d\.,]*)\s*€/u',
        'ordini_rimborsati_partner'   => '/\+\s*Ordini rimborsati al partner\s*([\d\.,]+)\s*€/u',
        'commissione_ordini_rimborsati' => '/-?\s*Commissione Glovo sugli ordini rimborsati al partner\s*([-]?\d[\d\.,]*)\s*€/ui',
        'sconto_comm_ordini_buoni_pasto' => '/-?\s*Sconto commissione ordini buoni\s+pasto\s*([-]?\d[\d\.,]*)\s*€/ui',
        'debito_accumulato' => '/-\s*Debito accumulato\s*([-]?\d[\d\.,]*)\s*€/u',
        'importo_bonifico' => '/Importo del bonifico\s*(-?[\d\.,]+|-)\s*€/u',
    ];

    foreach ($patterns as $key => $regex) {
        if (preg_match($regex, $text, $m)) {
            $data[$key] = normalizeEuroAmount($m[1]);
        }
    }

    return $data;
}

// ============ MAIN ============

echo "\n";
echo "===========================================\n";
echo "  IMPORTA PDF SENZA COMMISSIONI (CLI)\n";
echo "===========================================\n\n";

// Nessun argomento: mostra lista PDF
if ($argc < 2) {
    $failedFiles = is_dir($failedDir) ? glob($failedDir . '/*.pdf') : [];

    if (empty($failedFiles)) {
        echo "Nessun PDF trovato in failed/\n\n";
        exit(0);
    }

    echo "PDF disponibili in failed/:\n";
    echo "-------------------------------------------\n";
    foreach ($failedFiles as $i => $file) {
        echo "  " . ($i + 1) . ". " . basename($file) . "\n";
    }
    echo "-------------------------------------------\n\n";
    echo "Uso:\n";
    echo "  php " . basename($argv[0]) . " <nome_file.pdf> [commissioni]\n\n";
    echo "Esempi:\n";
    echo "  php " . basename($argv[0]) . " fattura_123.pdf\n";
    echo "  php " . basename($argv[0]) . " fattura_123.pdf 150.00\n\n";
    exit(0);
}

// Argomento fornito: importa il PDF
$pdfFile = $argv[1];
$commissioniManuale = $argv[2] ?? null;

$pdfPath = $failedDir . '/' . $pdfFile;
if (!file_exists($pdfPath)) {
    echo "ERRORE: File non trovato: $pdfFile\n";
    echo "Path cercato: $pdfPath\n\n";
    exit(1);
}

echo "Parsing PDF: $pdfFile\n";

// Parse PDF
$pdfParser = new Parser();
$pdf = $pdfParser->parseFile($pdfPath);
$pages = $pdf->getPages();

if (!isset($pages[0])) {
    echo "ERRORE: Nessuna pagina nel PDF\n";
    exit(1);
}

$text = $pages[0]->getText();
$data = parseGlovoInvoice($text);

// Sovrascrivi commissioni se fornito manualmente
if ($commissioniManuale !== null) {
    $data['commissioni'] = normalizeEuroAmount($commissioniManuale);
    echo "Commissioni impostate manualmente: {$data['commissioni']}\n";
}

echo "\nDati estratti:\n";
echo "-------------------------------------------\n";
echo "  N. Fattura:    " . ($data['n_fattura'] ?? 'NULL') . "\n";
echo "  Data:          " . ($data['data'] ?? 'NULL') . "\n";
echo "  Destinatario:  " . ($data['destinatario'] ?? 'NULL') . "\n";
echo "  Negozio:       " . ($data['negozio'] ?? 'NULL') . "\n";
echo "  Commissioni:   " . ($data['commissioni'] ?? 'NULL (non presente)') . "\n";
echo "  Subtotale:     " . ($data['subtotale'] ?? 'NULL') . "\n";
echo "  IVA 22%:       " . ($data['iva_22'] ?? 'NULL') . "\n";
echo "  Totale:        " . ($data['totale_fattura_iva_inclusa'] ?? 'NULL') . "\n";
echo "  Bonifico:      " . ($data['importo_bonifico'] ?? 'NULL') . "\n";
echo "-------------------------------------------\n\n";

// Prepara insert
$table = $config['db_table'];
$sql = "INSERT INTO `$table` (
    file_pdf, destinatario, negozio, n_fattura, data, periodo_da, periodo_a,
    commissioni, marketing_visibilita, subtotale, iva_22, totale_fattura_iva_inclusa,
    prodotti, servizio_consegna, totale_fattura_riepilogo, promo_prodotti_partner,
    promo_consegna_partner, costi_offerta_lampo, promo_lampo_partner,
    costo_incidenti_prodotti, tariffa_tempo_attesa, rimborsi_partner_senza_comm,
    costo_annullamenti_servizio, consegna_gratuita_incidente, buoni_pasto,
    supplemento_ordine_glovo_prime, glovo_gia_pagati,
    ordini_rimborsati_partner, commissione_ordini_rimborsati, sconto_comm_ordini_buoni_pasto,
    debito_accumulato, importo_bonifico
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo "ERRORE prepare: " . $mysqli->error . "\n";
    exit(1);
}

$stmt->bind_param(
    str_repeat('s', 32),
    $pdfFile,
    $data['destinatario'], $data['negozio'], $data['n_fattura'], $data['data'],
    $data['periodo_da'], $data['periodo_a'], $data['commissioni'],
    $data['marketing_visibilita'], $data['subtotale'], $data['iva_22'],
    $data['totale_fattura_iva_inclusa'], $data['prodotti'], $data['servizio_consegna'],
    $data['totale_fattura_riepilogo'], $data['promo_prodotti_partner'],
    $data['promo_consegna_partner'], $data['costi_offerta_lampo'],
    $data['promo_lampo_partner'], $data['costo_incidenti_prodotti'],
    $data['tariffa_tempo_attesa'],
    $data['rimborsi_partner_senza_comm'], $data['costo_annullamenti_servizio'],
    $data['consegna_gratuita_incidente'], $data['buoni_pasto'],
    $data['supplemento_ordine_glovo_prime'], $data['glovo_gia_pagati'],
    $data['ordini_rimborsati_partner'], $data['commissione_ordini_rimborsati'],
    $data['sconto_comm_ordini_buoni_pasto'],
    $data['debito_accumulato'], $data['importo_bonifico']
);

try {
    $stmt->execute();
    $insertedId = $mysqli->insert_id;

    // Sposta PDF in processed
    $destProcessed = $processedDir . '/' . $pdfFile;
    if (rename($pdfPath, $destProcessed)) {
        echo "PDF spostato in processed/\n";
    } else {
        echo "ATTENZIONE: Impossibile spostare il PDF in processed/\n";
    }

    echo "\n";
    echo "===========================================\n";
    echo "  IMPORTAZIONE COMPLETATA!\n";
    echo "===========================================\n";
    echo "  ID Record: $insertedId\n";
    echo "  File: $pdfFile\n";
    echo "  N. Fattura: {$data['n_fattura']}\n";
    echo "===========================================\n\n";

    if (empty($data['commissioni'])) {
        echo "NOTA: Fattura importata senza commissioni.\n";
        echo "Per aggiornare manualmente:\n\n";
        echo "  UPDATE $table SET commissioni = 'VALORE' WHERE id = $insertedId;\n\n";
    }

} catch (mysqli_sql_exception $e) {
    if ($e->getCode() == 1062) {
        echo "ERRORE: Fattura gia esistente (n_fattura duplicato)\n";
        exit(1);
    } else {
        echo "ERRORE DB: " . $e->getMessage() . "\n";
        exit(1);
    }
}

$stmt->close();
$mysqli->close();
