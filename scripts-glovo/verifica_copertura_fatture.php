<?php
/**
 * verifica_copertura_fatture.php
 *
 * Verifica che TUTTI i dati presenti nelle fatture PDF siano stati
 * correttamente estratti e salvati nel database.
 *
 * Funziona sia per fatture già elaborate (PDF in processed/) sia
 * per qualsiasi cartella indicata manualmente.
 *
 * Uso CLI:
 *   php verifica_copertura_fatture.php                  # scansiona processed/
 *   php verifica_copertura_fatture.php --invia-email     # invia report per email
 *   php verifica_copertura_fatture.php --dir=/path/pdf   # cartella custom
 *
 * Uso web: accedere via browser (mostra report HTML interattivo)
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(600);

require __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;

$config = require __DIR__ . '/config-glovo.php';

// ========================================
// PARAMETRI
// ========================================

$isCli = php_sapi_name() === 'cli';
$inviaEmail = false;
$pdfDirCustom = null;

if ($isCli) {
    foreach ($argv as $arg) {
        if ($arg === '--invia-email') $inviaEmail = true;
        if (strpos($arg, '--dir=') === 0) $pdfDirCustom = substr($arg, 6);
    }
    $verificaNegozio = !in_array('--no-negozio', $argv);
} else {
    $inviaEmail = isset($_GET['invia_email']);
    $pdfDirCustom = $_GET['dir'] ?? null;
    $verificaNegozio = ($_GET['negozio'] ?? '1') !== '0';
}

$defaultPdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf/processed');
$pdfDir = $pdfDirCustom ? realpath($pdfDirCustom) : $defaultPdfDir;

if (!$pdfDir || !is_dir($pdfDir)) {
    $msg = "Cartella PDF non trovata: " . ($pdfDirCustom ?? $defaultPdfDir);
    die($isCli ? "$msg\n" : "<p>$msg</p>");
}

// ========================================
// CAMPI DA VERIFICARE (tutti i 31 campi dati)
// ========================================

// Campi obbligatori: devono essere sempre presenti
$CAMPI_OBBLIGATORI = [
    'n_fattura', 'data', 'destinatario', 'negozio',
    'periodo_da', 'periodo_a', 'commissioni', 'subtotale',
    'iva_22', 'totale_fattura_iva_inclusa', 'prodotti',
    'totale_fattura_riepilogo', 'promo_prodotti_partner', 'importo_bonifico',
];

// Campi opzionali: presenti solo in alcune fatture
$CAMPI_OPZIONALI = [
    'marketing_visibilita', 'servizio_consegna', 'promo_consegna_partner',
    'costi_offerta_lampo', 'promo_lampo_partner', 'costo_incidenti_prodotti',
    'tariffa_tempo_attesa', 'rimborsi_partner_senza_comm',
    'costo_annullamenti_servizio', 'consegna_gratuita_incidente',
    'buoni_pasto', 'supplemento_ordine_glovo_prime',
    'glovo_gia_pagati', 'ordini_rimborsati_partner',
    'commissione_ordini_rimborsati', 'sconto_comm_ordini_buoni_pasto',
    'debito_accumulato',
];

$TUTTI_I_CAMPI = array_merge($CAMPI_OBBLIGATORI, $CAMPI_OPZIONALI);

// ========================================
// FUNZIONI DI PARSING (identiche a estrai_fatture_glovo.php)
// ========================================

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
        'Viale Coni Zugna, 43, 20144 Milano MI, Italia'                    => 'Girarrosti Santa Rita - Milano',
        '307, Corso Susa, 301/307, 10098 Rivoli TO, Italy'                 => 'Girarrosti Santa Rita - Rivoli',
        'Via Martiri della Libertà, 74, 10099 San Mauro Torinese TO, Italia' => 'Girarrosti Santa Rita - San Mauro',
        'Via S. Mauro, 1, 10036 Settimo Torinese TO, Italia'               => 'Girarrosti Santa Rita - Settimo Torinese',
        'Via Vittorio Alfieri, 9, 10043 Orbassano TO, Italia'              => 'Girarrosti Santa Rita - Via Vittorio Alfieri - Orbassano',
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
    $data = array_fill_keys([
        'destinatario', 'negozio', 'n_fattura', 'data', 'periodo_da', 'periodo_a',
        'commissioni', 'marketing_visibilita', 'subtotale', 'iva_22',
        'totale_fattura_iva_inclusa', 'prodotti', 'servizio_consegna',
        'totale_fattura_riepilogo', 'promo_prodotti_partner', 'promo_consegna_partner',
        'costi_offerta_lampo', 'promo_lampo_partner', 'costo_incidenti_prodotti',
        'tariffa_tempo_attesa', 'rimborsi_partner_senza_comm', 'costo_annullamenti_servizio',
        'consegna_gratuita_incidente', 'buoni_pasto', 'supplemento_ordine_glovo_prime',
        'glovo_gia_pagati', 'ordini_rimborsati_partner', 'commissione_ordini_rimborsati',
        'sconto_comm_ordini_buoni_pasto', 'debito_accumulato', 'importo_bonifico',
    ], null);

    $d = parseDestinatario($text);
    $data['destinatario'] = $d['destinatario'];
    $data['negozio']      = $d['negozio'];

    if (preg_match('/\b([A-Z0-9][A-Z0-9\-\/]{7,})\s+N\. fattura:/', $text, $m))
        $data['n_fattura'] = trim($m[1]);

    if (preg_match('/Data:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/', $text, $m))
        $data['data'] = $m[1];

    if (preg_match('/Servizio fornito da\s*([0-9]{4}-[0-9]{2}-[0-9]{2})\s*a\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/', $text, $m)) {
        $data['periodo_da'] = $m[1];
        $data['periodo_a']  = $m[2];
    }

    $patterns = [
        'commissioni'                 => '/Commissioni\s*([\d\.,]+)\s*€/u',
        'marketing_visibilita'        => '/Marketing[\s\-]*visibilit[àáa]\s*([\d\.,]+)\s*€/ui',
        'subtotale'                   => '/Subtotale\s*([\d\.,]+)\s*€/u',
        'iva_22'                      => '/IVA\s*\(22\s*%\)\s*([\d\.,]+)\s*€/u',
        'totale_fattura_iva_inclusa'  => '/Totale fattura\s*\(IVA inclusa\)\s*([\d\.,]+)\s*€/us',
        'prodotti'                    => '/\+\s*Prodotti\s*([\d\.,]+)\s*€/u',
        'servizio_consegna'           => '/\+\s*Servizio di consegna\s*([\d\.,]+)\s*€/u',
        'totale_fattura_riepilogo'    => '/-\s*Totale fattura\s*([-]?\d[\d\.,]*)\s*€/u',
        'promo_prodotti_partner'      => '/-\s*Promozione sui prodotti a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
        'promo_consegna_partner'      => '/-?\s*Promozione sulla consegna a carico del partner\s*([-]?\d[\d\.,]*)\s*€/u',
        'costi_offerta_lampo'         => '/-?\s*Costi per offerta lampo\s*([-]?\d[\d\.,]*)\s*€/u',
        'promo_lampo_partner'         => '/-\s*Promozione lampo a carico del [Pp]artner\s*([-]?\d[\d\.,]*)\s*€/u',
        'costo_incidenti_prodotti'    => '/-\s*Costo degli incidenti relativi ai prodotti\s*([-]?\d[\d\.,]*)\s*€/u',
        'tariffa_tempo_attesa'        => '/-\s*Tariffa\s+per\s+tempo\s+di\s+attesa\s*([-]?\d[\d\.,]*)\s*€/ui',
        'rimborsi_partner_senza_comm' => '/\+\s*Rimborsi al partner senza costo commissione Glovo\s*([\d\.,]+)\s*€/u',
        'costo_annullamenti_servizio' => '/-\s*Costo degli annullamenti e degli incidenti relativi al servizio\s*([-]?\d[\d\.,]*)\s*€/u',
        'consegna_gratuita_incidente' => '/-\s*Consegna gratuita in seguito a incidente\s*([-]?\d[\d\.,]*)\s*€/u',
        'buoni_pasto'                 => '/-\s*Buoni\s+pasto\s*([-]?\d[\d\.,]*)\s*€/ui',
        'supplemento_ordine_glovo_prime' => '/-?\s*Supplemento per ordine con Glovo Prime\s*([-]?\d[\d\.,]*)\s*€/u',
        'glovo_gia_pagati'            => '/-\s*Glovo già pagati\s*([-]?\d[\d\.,]*)\s*€/u',
        'ordini_rimborsati_partner'   => '/\+\s*Ordini rimborsati al partner\s*([\d\.,]+)\s*€/u',
        'commissione_ordini_rimborsati' => '/-?\s*Commissione Glovo sugli ordini rimborsati al partner\s*([-]?\d[\d\.,]*)\s*€/ui',
        'sconto_comm_ordini_buoni_pasto' => '/-?\s*Sconto commissione ordini buoni\s+pasto\s*([-]?\d[\d\.,]*)\s*€/ui',
        'debito_accumulato'           => '/-\s*Debito accumulato\s*([-]?\d[\d\.,]*)\s*€/u',
        'importo_bonifico'            => '/Importo del bonifico\s*(-?[\d\.,]+|-)\s*€/u',
    ];

    foreach ($patterns as $key => $regex) {
        if (preg_match($regex, $text, $m)) {
            $data[$key] = normalizeEuroAmount($m[1]);
        }
    }

    return $data;
}

/**
 * Trova righe con importi € non coperte da nessun pattern.
 * Restituisce array di righe "orfane".
 */
function trovaBoniEuroNonMappati($text, $datiEstratti) {
    $orfani = [];
    if (!preg_match_all('/^.{0,80}[\d\.,]+\s*€.{0,40}$/um', $text, $matches)) {
        return $orfani;
    }

    // Costruisce un set di valori estratti per confronto veloce
    $valoriEstratti = [];
    foreach ($datiEstratti as $val) {
        if ($val !== null && is_numeric($val)) {
            // Formato con virgola come in PDF
            $valoriEstratti[] = number_format((float)$val, 2, ',', '.');
            $valoriEstratti[] = number_format((float)$val, 2, ',', '');
        }
    }

    foreach ($matches[0] as $riga) {
        $riga = trim($riga);
        if (strlen($riga) < 5) continue;

        // Estrai l'importo numerico dalla riga (incluso eventuale segno negativo)
        if (!preg_match('/(-?[\d\.,]+)\s*€/', $riga, $m)) continue;
        $importoRiga = $m[1];

        // Controlla se questo importo è già coperto da un campo estratto
        $coperto = false;
        foreach ($valoriEstratti as $valEstratto) {
            if ($importoRiga === $valEstratto || str_replace('.', '', $importoRiga) === str_replace('.', '', $valEstratto)) {
                $coperto = true;
                break;
            }
        }

        if (!$coperto) {
            $orfani[] = $riga;
        }
    }

    return $orfani;
}

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
    die("Errore DB: " . $e->getMessage() . "\n");
}

// Carica tutti i record DB indicizzati per file_pdf e n_fattura
$table = $config['db_table'];
$stmtDb = $pdo->query("SELECT * FROM `$table`");
$dbRecords = [];
$dbRecordsByNFattura = [];
foreach ($stmtDb->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dbRecords[$row['file_pdf']] = $row;
    if (!empty($row['n_fattura'])) {
        $dbRecordsByNFattura[$row['n_fattura']] = $row;
    }
}

// ========================================
// SCANSIONE E ANALISI PDF
// ========================================

$pdfParser = new Parser();
$pdfFiles  = glob($pdfDir . '/*.pdf') ?: [];
$totPdf    = count($pdfFiles);

if ($isCli) {
    echo "Verifica copertura dati fatture Glovo\n";
    echo str_repeat('=', 60) . "\n";
    echo "Cartella: $pdfDir\n";
    echo "PDF trovati: $totPdf\n";
    echo "Record in DB: " . count($dbRecords) . "\n";
    echo str_repeat('=', 60) . "\n\n";
}

// Struttura risultati
$risultati = []; // per ogni PDF: [ 'file', 'status', 'anomalie', 'copertura', 'db_record', 'orfani' ]

// Statistiche globali copertura per campo
$statCampo = [];
foreach ($TUTTI_I_CAMPI as $campo) {
    $statCampo[$campo] = ['pdf_presenti' => 0, 'pdf_assenti' => 0, 'db_null_ma_pdf_ok' => 0, 'discrepanze_valore' => 0];
}

$contatori = [
    'pdf_analizzati'   => 0,
    'pdf_errore'       => 0,       // impossibile leggere il PDF
    'in_db'            => 0,       // PDF con record in DB
    'non_in_db'        => 0,       // PDF senza record in DB
    'con_anomalie'     => 0,       // almeno un campo mancante o discrepante
    'orfani_trovati'   => 0,       // PDF con righe € non mappate
    'perfetti'         => 0,       // nessuna anomalia
];

foreach ($pdfFiles as $idx => $pdfPath) {
    $fileName = basename($pdfPath);

    if ($isCli && $totPdf > 10) {
        $perc = (int)(($idx / $totPdf) * 100);
        if ($perc % 10 === 0 && ($idx === 0 || (int)((($idx - 1) / $totPdf) * 100) !== $perc)) {
            echo "  Progresso: {$perc}% ({$idx}/{$totPdf})\n";
        }
    }

    $risultato = [
        'file'      => $fileName,
        'status'    => 'ok',        // ok | errore_pdf | non_in_db
        'anomalie'  => [],          // [ ['tipo', 'campo', 'valore_pdf', 'valore_db'], ... ]
        'copertura' => [],          // campo => ['pdf' => val, 'db' => val]
        'db_record' => null,
        'orfani'    => [],
    ];

    // Estrai testo PDF
    try {
        $pdf   = $pdfParser->parseFile($pdfPath);
        $pages = $pdf->getPages();
        if (empty($pages)) throw new Exception("Nessuna pagina");
        $text = $pages[0]->getText();
        if (empty($text)) throw new Exception("Testo vuoto");
    } catch (Exception $e) {
        $risultato['status'] = 'errore_pdf';
        $risultato['anomalie'][] = ['tipo' => 'ERRORE_PDF', 'messaggio' => $e->getMessage()];
        $risultati[] = $risultato;
        $contatori['pdf_errore']++;
        continue;
    }

    $contatori['pdf_analizzati']++;

    // Estrai dati dal PDF
    $datiPdf = parseGlovoInvoice($text);

    // Trova record DB (prima per file_pdf, poi per n_fattura)
    $dbRow = $dbRecords[$fileName] ?? null;
    if ($dbRow === null && !empty($datiPdf['n_fattura'])) {
        $dbRow = $dbRecordsByNFattura[$datiPdf['n_fattura']] ?? null;
    }

    if ($dbRow === null) {
        $risultato['status'] = 'non_in_db';
        $risultato['anomalie'][] = ['tipo' => 'NON_IN_DB', 'messaggio' => 'Nessun record trovato nel database'];
        $risultati[] = $risultato;
        $contatori['non_in_db']++;
        $contatori['con_anomalie']++;
        continue;
    }

    $risultato['db_record'] = [
        'id'         => $dbRow['id'] ?? null,
        'n_fattura'  => $dbRow['n_fattura'] ?? null,
        'file_db'    => $dbRow['file_pdf'] ?? null,
    ];
    $contatori['in_db']++;

    // Confronta campo per campo
    $haAnomalia = false;
    $campiDaVerificare = $verificaNegozio
        ? $TUTTI_I_CAMPI
        : array_diff($TUTTI_I_CAMPI, ['destinatario', 'negozio']);
    foreach ($campiDaVerificare as $campo) {
        $valPdf = $datiPdf[$campo] ?? null;
        $valDb  = isset($dbRow[$campo]) && $dbRow[$campo] !== '' ? $dbRow[$campo] : null;

        // Normalizza per confronto: rimuovi trailing zeros (3.00 == 3)
        $valPdfNorm = ($valPdf !== null) ? rtrim(rtrim($valPdf, '0'), '.') : null;
        $valDbNorm  = ($valDb !== null)  ? rtrim(rtrim((string)$valDb, '0'), '.') : null;

        $risultato['copertura'][$campo] = ['pdf' => $valPdf, 'db' => $valDb];

        if ($valPdf !== null) {
            $statCampo[$campo]['pdf_presenti']++;
        } else {
            $statCampo[$campo]['pdf_assenti']++;
        }

        // ANOMALIA 1: campo presente nel PDF ma NULL nel DB (perdita dato)
        if ($valPdf !== null && $valDb === null) {
            $risultato['anomalie'][] = [
                'tipo'      => 'DATO_PERSO',
                'campo'     => $campo,
                'valore_pdf' => $valPdf,
                'valore_db'  => null,
                'obbligatorio' => in_array($campo, $CAMPI_OBBLIGATORI),
            ];
            $statCampo[$campo]['db_null_ma_pdf_ok']++;
            $haAnomalia = true;
        }

        // ANOMALIA 2: valore diverso tra PDF e DB (possibile problema di estrazione pregressa)
        if ($valPdf !== null && $valDb !== null && $valPdfNorm !== $valDbNorm) {
            $risultato['anomalie'][] = [
                'tipo'      => 'VALORE_DIVERSO',
                'campo'     => $campo,
                'valore_pdf' => $valPdf,
                'valore_db'  => $valDb,
                'obbligatorio' => in_array($campo, $CAMPI_OBBLIGATORI),
            ];
            $statCampo[$campo]['discrepanze_valore']++;
            $haAnomalia = true;
        }
    }

    // Cerca importi € nel PDF non coperti da nessun pattern
    $orfani = trovaBoniEuroNonMappati($text, $datiPdf);
    if (!empty($orfani)) {
        $risultato['orfani'] = $orfani;
        $contatori['orfani_trovati']++;
    }

    if ($haAnomalia) {
        $risultato['status'] = 'anomalia';
        $contatori['con_anomalie']++;
    } else {
        $contatori['perfetti']++;
    }

    $risultati[] = $risultato;
}

// Conta PDFs in DB senza PDF nella cartella
$pdfFilesSet = array_flip(array_map('basename', $pdfFiles));
$dbSenzaPdf  = [];
foreach ($dbRecords as $filePdf => $row) {
    if (!isset($pdfFilesSet[$filePdf])) {
        $dbSenzaPdf[] = ['file' => $filePdf, 'n_fattura' => $row['n_fattura'] ?? '-', 'data' => $row['data'] ?? '-'];
    }
}

// ========================================
// OUTPUT CLI
// ========================================

if ($isCli) {
    echo "\nRISULTATI\n";
    echo str_repeat('=', 60) . "\n";
    echo "PDF analizzati:         {$contatori['pdf_analizzati']}\n";
    echo "  Perfetti (nessuna anomalia): {$contatori['perfetti']}\n";
    echo "  Con anomalie:         {$contatori['con_anomalie']}\n";
    echo "  Senza record DB:      {$contatori['non_in_db']}\n";
    echo "  Con importi non mappati: {$contatori['orfani_trovati']}\n";
    echo "PDF illeggibili:        {$contatori['pdf_errore']}\n";
    echo "Record DB senza PDF:    " . count($dbSenzaPdf) . "\n";
    echo str_repeat('=', 60) . "\n\n";

    // Mostra anomalie dettagliate
    $anomalie_gravi = array_filter($risultati, fn($r) => !empty($r['anomalie']) && $r['status'] !== 'errore_pdf');
    if (empty($anomalie_gravi)) {
        echo "Nessuna anomalia rilevata. Tutti i dati sono correttamente censiti nel DB.\n\n";
    } else {
        echo "ANOMALIE RILEVATE:\n";
        echo str_repeat('-', 60) . "\n";
        foreach ($anomalie_gravi as $r) {
            echo "\n  PDF: {$r['file']}\n";
            foreach ($r['anomalie'] as $a) {
                if ($a['tipo'] === 'NON_IN_DB') {
                    echo "    [NON IN DB] Fattura non trovata nel database\n";
                } elseif ($a['tipo'] === 'DATO_PERSO') {
                    $obl = $a['obbligatorio'] ? ' [OBBLIGATORIO]' : '';
                    echo "    [DATO PERSO{$obl}] {$a['campo']}: PDF={$a['valore_pdf']} → DB=NULL\n";
                } elseif ($a['tipo'] === 'VALORE_DIVERSO') {
                    $obl = $a['obbligatorio'] ? ' [OBBLIGATORIO]' : '';
                    echo "    [VALORE DIVERSO{$obl}] {$a['campo']}: PDF={$a['valore_pdf']} vs DB={$a['valore_db']}\n";
                }
            }
            if (!empty($r['orfani'])) {
                echo "    [IMPORTI NON MAPPATI]\n";
                foreach (array_slice($r['orfani'], 0, 3) as $riga) {
                    echo "      → $riga\n";
                }
            }
        }
        echo "\n";
    }

    // Importi non mappati per PDF
    $conOrfaniCli = array_filter($risultati, fn($r) => !empty($r['orfani']));
    if (!empty($conOrfaniCli)) {
        echo "IMPORTI NON MAPPATI DA NESSUN PATTERN:\n";
        echo str_repeat('-', 60) . "\n";
        $riepilogoCli = [];
        foreach ($conOrfaniCli as $r) {
            $nFatt = $r['db_record']['n_fattura'] ?? '-';
            $data  = $r['db_record']['data'] ?? '-';
            echo "\n  PDF: {$r['file']}  (n.fattura: $nFatt, data: $data)\n";
            foreach ($r['orfani'] as $riga) {
                echo "    → $riga\n";
                if (!preg_match('/(-?[\d\.,]+)\s*€/', $riga, $m)) continue;
                $importoNum = (float)str_replace(',', '.', str_replace('.', '', $m[1]));
                $descr = trim(preg_replace('/-?[\d\.,]+\s*€.*$/', '', $riga), " \t-");
                if ($descr === '') $descr = '(importo senza descrizione)';
                if (!isset($riepilogoCli[$descr])) {
                    $riepilogoCli[$descr] = ['count' => 0, 'totale' => 0.0];
                }
                $riepilogoCli[$descr]['count']++;
                $riepilogoCli[$descr]['totale'] += $importoNum;
            }
        }
        echo "\n";

        // Riepilogo per voce
        if (!empty($riepilogoCli)) {
            uasort($riepilogoCli, fn($a, $b) => $b['count'] <=> $a['count']);
            echo "RIEPILOGO VOCI NON MAPPATE:\n";
            echo str_repeat('-', 60) . "\n";
            printf("  %-42s %6s %12s\n", 'Descrizione voce', 'Num.', 'Totale €');
            echo "  " . str_repeat('-', 58) . "\n";
            foreach ($riepilogoCli as $descr => $dati) {
                $totFmt = number_format($dati['totale'], 2, ',', '.');
                printf("  %-42s %6d %12s\n",
                    mb_substr($descr, 0, 42), $dati['count'], $totFmt . ' €');
            }
            echo "\n";
        }
    }

    // Statistiche per campo
    echo "COPERTURA PER CAMPO\n";
    echo str_repeat('-', 60) . "\n";
    printf("  %-40s %6s %6s %6s\n", 'Campo', '% PDF', 'Persi', 'Disc.');
    echo "  " . str_repeat('-', 58) . "\n";
    foreach ($TUTTI_I_CAMPI as $campo) {
        $s = $statCampo[$campo];
        $tot = $s['pdf_presenti'] + $s['pdf_assenti'];
        $perc = $tot > 0 ? round($s['pdf_presenti'] / $tot * 100) : 0;
        $obl = in_array($campo, $CAMPI_OBBLIGATORI) ? '*' : ' ';
        $alert = ($s['db_null_ma_pdf_ok'] > 0 || $s['discrepanze_valore'] > 0) ? ' <--' : '';
        printf("  %s%-39s %5d%% %6d %6d%s\n",
            $obl, $campo, $perc,
            $s['db_null_ma_pdf_ok'], $s['discrepanze_valore'],
            $alert
        );
    }
    echo "  (* = campo obbligatorio, Persi = in PDF ma NULL in DB, Disc. = valori diversi)\n\n";
}

// ========================================
// GENERA REPORT EMAIL (testo)
// ========================================

function generaTestoReport($contatori, $risultati, $statCampo, $dbSenzaPdf, $CAMPI_OBBLIGATORI, $TUTTI_I_CAMPI, $pdfDir) {
    $haProblemi = $contatori['con_anomalie'] > 0 || count($dbSenzaPdf) > 0;
    $statusIcon = $haProblemi ? 'ATTENZIONE' : 'OK';

    $txt  = "VERIFICA COPERTURA DATI FATTURE GLOVO\n";
    $txt .= str_repeat('=', 60) . "\n";
    $txt .= "Data/ora: " . date('Y-m-d H:i:s') . "\n";
    $txt .= "Stato: $statusIcon\n";
    $txt .= "Cartella analizzata: $pdfDir\n\n";

    $txt .= str_repeat('-', 60) . "\n";
    $txt .= "SOMMARIO\n";
    $txt .= str_repeat('-', 60) . "\n";
    $txt .= "PDF analizzati:              {$contatori['pdf_analizzati']}\n";
    $txt .= "  Perfetti (100% copertura): {$contatori['perfetti']}\n";
    $txt .= "  Con anomalie:              {$contatori['con_anomalie']}\n";
    $txt .= "  Senza record DB:           {$contatori['non_in_db']}\n";
    $txt .= "  Con importi non mappati:   {$contatori['orfani_trovati']}\n";
    $txt .= "PDF illeggibili:             {$contatori['pdf_errore']}\n";
    $txt .= "Record DB senza PDF locale:  " . count($dbSenzaPdf) . "\n";
    $txt .= str_repeat('-', 60) . "\n\n";

    // Anomalie dettagliate
    $anomalie_gravi = array_filter($risultati, fn($r) => !empty($r['anomalie']));
    if (!empty($anomalie_gravi)) {
        $txt .= "ANOMALIE RILEVATE\n";
        $txt .= str_repeat('-', 60) . "\n";
        foreach ($anomalie_gravi as $r) {
            $txt .= "\nPDF: {$r['file']}\n";
            foreach ($r['anomalie'] as $a) {
                if ($a['tipo'] === 'NON_IN_DB') {
                    $txt .= "  [NON IN DB] Fattura non trovata nel database\n";
                } elseif ($a['tipo'] === 'DATO_PERSO') {
                    $obl = $a['obbligatorio'] ? ' [OBBL]' : '';
                    $txt .= "  [DATO PERSO{$obl}] {$a['campo']}: PDF={$a['valore_pdf']} - DB=NULL\n";
                } elseif ($a['tipo'] === 'VALORE_DIVERSO') {
                    $obl = $a['obbligatorio'] ? ' [OBBL]' : '';
                    $txt .= "  [VALORE DIVERSO{$obl}] {$a['campo']}: PDF={$a['valore_pdf']} vs DB={$a['valore_db']}\n";
                }
            }
            if (!empty($r['orfani'])) {
                $txt .= "  [IMPORTI NON MAPPATI nel PDF]:\n";
                foreach (array_slice($r['orfani'], 0, 5) as $riga) {
                    $txt .= "    -> $riga\n";
                }
            }
        }
        $txt .= "\n";
    } else {
        $txt .= "Nessuna anomalia rilevata.\n\n";
    }

    // Copertura per campo (solo campi con problemi)
    $campiConProblemi = array_filter($TUTTI_I_CAMPI, fn($c) =>
        $statCampo[$c]['db_null_ma_pdf_ok'] > 0 || $statCampo[$c]['discrepanze_valore'] > 0
    );
    if (!empty($campiConProblemi)) {
        $txt .= "CAMPI CON PROBLEMI DI COPERTURA\n";
        $txt .= str_repeat('-', 60) . "\n";
        foreach ($campiConProblemi as $campo) {
            $s = $statCampo[$campo];
            $obl = in_array($campo, $CAMPI_OBBLIGATORI) ? ' [OBBL]' : '';
            $txt .= "  $campo$obl:\n";
            if ($s['db_null_ma_pdf_ok'] > 0) $txt .= "    - Dati persi (PDF->DB): {$s['db_null_ma_pdf_ok']} fatture\n";
            if ($s['discrepanze_valore'] > 0) $txt .= "    - Valori discordanti:    {$s['discrepanze_valore']} fatture\n";
        }
        $txt .= "\n";
    }

    if (!empty($dbSenzaPdf)) {
        $txt .= "RECORD DB SENZA PDF LOCALE\n";
        $txt .= str_repeat('-', 60) . "\n";
        foreach (array_slice($dbSenzaPdf, 0, 20) as $row) {
            $txt .= "  {$row['file']} (n.fattura: {$row['n_fattura']}, data: {$row['data']})\n";
        }
        if (count($dbSenzaPdf) > 20) $txt .= "  ... e altri " . (count($dbSenzaPdf) - 20) . "\n";
        $txt .= "\n";
    }

    $txt .= str_repeat('=', 60) . "\n";
    $txt .= "Report automatico - Sistema fatture Glovo\n";

    return $txt;
}

// ========================================
// INVIO EMAIL
// ========================================

function inviaEmailVerifica($testo, $contatori, $config) {
    if (empty($config['alert_email'])) return false;

    $emails = is_array($config['alert_email']) ? $config['alert_email'] : [$config['alert_email']];
    $emails = array_filter($emails, fn($e) => !empty($e) && $e !== 'tua@email.com');
    if (empty($emails)) return false;

    $haProblemi = $contatori['con_anomalie'] > 0;
    $icon = $haProblemi ? 'ATTENZIONE' : 'OK';
    $subject = "[$icon] Verifica copertura fatture Glovo - " . date('Y-m-d');

    $headers  = "From: noreply@girarrostisantarita.it\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = 0;
    foreach ($emails as $email) {
        if (mail(trim($email), $subject, $testo, $headers)) $sent++;
    }
    return $sent > 0;
}

if ($inviaEmail || (!$isCli && isset($_GET['invia_email']))) {
    $testoReport = generaTestoReport($contatori, $risultati, $statCampo, $dbSenzaPdf, $CAMPI_OBBLIGATORI, $TUTTI_I_CAMPI, $pdfDir);
    $ok = inviaEmailVerifica($testoReport, $contatori, $config);
    if ($isCli) echo ($ok ? "Email inviata.\n" : "Email non inviata (controlla config).\n");
}

// ========================================
// OUTPUT WEB (HTML)
// ========================================

if (!$isCli):
    $haProblemi = $contatori['con_anomalie'] > 0 || count($dbSenzaPdf) > 0;
    $statusColor = $haProblemi ? '#dc3545' : '#28a745';
    $statusLabel = $haProblemi ? 'ATTENZIONE - Anomalie rilevate' : 'OK - Tutti i dati coperti';
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verifica Copertura Fatture Glovo</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#f4f6f9; color:#333; }
.header { background:linear-gradient(135deg,#1a237e,#283593); color:white; padding:40px; text-align:center; }
.header h1 { font-size:2em; margin-bottom:8px; }
.status-banner { background:<?php echo $statusColor; ?>; color:white; text-align:center; padding:14px; font-size:1.1em; font-weight:bold; }
.container { max-width:1200px; margin:30px auto; padding:0 20px; }
.card { background:white; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,0.1); margin-bottom:24px; overflow:hidden; }
.card-title { background:#283593; color:white; padding:14px 20px; font-size:1.1em; font-weight:600; }
.card-body { padding:20px; }
.stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:24px; }
.stat { background:white; border-radius:10px; padding:20px; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,0.1); }
.stat-num { font-size:2.2em; font-weight:bold; color:#1a237e; }
.stat-lbl { font-size:0.8em; color:#666; margin-top:4px; text-transform:uppercase; letter-spacing:1px; }
table { width:100%; border-collapse:collapse; font-size:0.9em; }
th { background:#e8eaf6; padding:10px 12px; text-align:left; font-weight:600; color:#1a237e; }
td { padding:9px 12px; border-bottom:1px solid #eee; }
tr:hover td { background:#f5f5f5; }
.badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:0.8em; font-weight:600; }
.badge-ok   { background:#d4edda; color:#155724; }
.badge-warn { background:#fff3cd; color:#856404; }
.badge-err  { background:#f8d7da; color:#721c24; }
.badge-info { background:#cce5ff; color:#004085; }
.progress { background:#e9ecef; border-radius:8px; height:18px; overflow:hidden; }
.progress-bar { height:100%; background:linear-gradient(90deg,#1a237e,#3949ab); color:white; font-size:0.75em; display:flex; align-items:center; justify-content:center; }
.anomalia-block { border-left:4px solid #dc3545; background:#fff5f5; padding:12px 16px; margin:8px 0; border-radius:4px; }
.anomalia-block.warn { border-color:#ffc107; background:#fffdf0; }
.orfano { color:#856404; font-size:0.85em; font-family:monospace; }
.btn { display:inline-block; padding:10px 20px; background:#1a237e; color:white; border-radius:6px; text-decoration:none; font-weight:600; margin:4px; }
.btn:hover { background:#283593; }
</style>
</head>
<body>

<div class="header">
    <h1>Verifica Copertura Dati Fatture Glovo</h1>
    <p><?php echo date('d/m/Y H:i:s'); ?> &bull; <?php echo htmlspecialchars($pdfDir); ?></p>
</div>

<div class="status-banner"><?php echo $statusLabel; ?></div>

<div class="container">

    <form method="get" style="text-align:right;margin-bottom:16px;display:flex;align-items:center;justify-content:flex-end;gap:16px;flex-wrap:wrap;">
        <?php if ($pdfDirCustom): ?>
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($pdfDirCustom); ?>">
        <?php endif; ?>
        <label style="display:flex;align-items:center;gap:6px;font-size:0.9em;cursor:pointer;background:white;padding:8px 14px;border-radius:6px;box-shadow:0 2px 6px rgba(0,0,0,0.1);">
            <input type="checkbox" name="negozio" value="0"
                <?php echo !$verificaNegozio ? 'checked' : ''; ?>
                onchange="this.form.submit()">
            Ignora verifica negozio/destinatario
        </label>
        <a class="btn" href="?invia_email=1<?php echo !$verificaNegozio ? '&negozio=0' : ''; ?><?php echo $pdfDirCustom ? '&dir='.urlencode($pdfDirCustom) : ''; ?>">Invia Report per Email</a>
        <a class="btn" href="?<?php echo !$verificaNegozio ? 'negozio=0' : ''; ?><?php echo $pdfDirCustom ? ($verificaNegozio ? '' : '&').'dir='.urlencode($pdfDirCustom) : ''; ?>">Aggiorna</a>
    </form>

    <div class="stats-grid">
        <div class="stat"><div class="stat-num"><?php echo $contatori['pdf_analizzati']; ?></div><div class="stat-lbl">PDF Analizzati</div></div>
        <div class="stat"><div class="stat-num" style="color:#28a745"><?php echo $contatori['perfetti']; ?></div><div class="stat-lbl">Perfetti</div></div>
        <div class="stat"><div class="stat-num" style="color:<?php echo $contatori['con_anomalie'] > 0 ? '#dc3545' : '#28a745'; ?>"><?php echo $contatori['con_anomalie']; ?></div><div class="stat-lbl">Con Anomalie</div></div>
        <div class="stat"><div class="stat-num" style="color:<?php echo $contatori['non_in_db'] > 0 ? '#dc3545' : '#28a745'; ?>"><?php echo $contatori['non_in_db']; ?></div><div class="stat-lbl">Senza DB</div></div>
        <div class="stat"><div class="stat-num" style="color:<?php echo $contatori['orfani_trovati'] > 0 ? '#ffc107' : '#28a745'; ?>"><?php echo $contatori['orfani_trovati']; ?></div><div class="stat-lbl">Importi Non Mappati</div></div>
        <div class="stat"><div class="stat-num"><?php echo count($dbSenzaPdf); ?></div><div class="stat-lbl">DB Senza PDF Locale</div></div>
    </div>

    <!-- Copertura per campo -->
    <div class="card">
        <div class="card-title">Copertura per Campo</div>
        <div class="card-body">
        <table>
            <thead>
                <tr>
                    <th>Campo</th>
                    <th>Tipo</th>
                    <th>Presenza in PDF</th>
                    <th>Dati Persi (PDF→DB)</th>
                    <th>Valori Discordanti</th>
                    <th>Stato</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($TUTTI_I_CAMPI as $campo):
                $s = $statCampo[$campo];
                $tot = $s['pdf_presenti'] + $s['pdf_assenti'];
                $perc = $tot > 0 ? round($s['pdf_presenti'] / $tot * 100) : 0;
                $obl = in_array($campo, $CAMPI_OBBLIGATORI);
                $haIssue = $s['db_null_ma_pdf_ok'] > 0 || $s['discrepanze_valore'] > 0;
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($campo); ?></strong></td>
                <td><?php echo $obl ? '<span class="badge badge-info">Obbligatorio</span>' : '<span class="badge" style="background:#eee;color:#555">Opzionale</span>'; ?></td>
                <td>
                    <div class="progress">
                        <div class="progress-bar" style="width:<?php echo $perc; ?>%"><?php echo $perc; ?>%</div>
                    </div>
                    <small style="color:#666"><?php echo $s['pdf_presenti']; ?>/<?php echo $tot; ?> fatture</small>
                </td>
                <td><?php echo $s['db_null_ma_pdf_ok'] > 0 ? "<span class=\"badge badge-err\">{$s['db_null_ma_pdf_ok']}</span>" : '<span style="color:#28a745">0</span>'; ?></td>
                <td><?php echo $s['discrepanze_valore'] > 0 ? "<span class=\"badge badge-warn\">{$s['discrepanze_valore']}</span>" : '<span style="color:#28a745">0</span>'; ?></td>
                <td>
                    <?php if ($haIssue): ?>
                        <span class="badge badge-err">Problemi</span>
                    <?php elseif ($obl && $perc < 100): ?>
                        <span class="badge badge-warn">Incompleto</span>
                    <?php else: ?>
                        <span class="badge badge-ok">OK</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Anomalie per PDF -->
    <?php
    $conAnomalie = array_filter($risultati, fn($r) => !empty($r['anomalie']));
    if (!empty($conAnomalie)):
    ?>
    <div class="card">
        <div class="card-title">Anomalie per PDF (<?php echo count($conAnomalie); ?>)</div>
        <div class="card-body">
        <?php foreach ($conAnomalie as $r): ?>
            <div style="margin-bottom:20px;">
                <strong><?php echo htmlspecialchars($r['file']); ?></strong>
                <?php if ($r['status'] === 'non_in_db'): ?>
                    <span class="badge badge-err" style="margin-left:8px">NON IN DB</span>
                <?php elseif ($r['status'] === 'anomalia'): ?>
                    <span class="badge badge-warn" style="margin-left:8px">ANOMALIA</span>
                <?php endif; ?>
                <?php if (!empty($r['db_record'])): ?>
                    <small style="color:#666;margin-left:8px">n.fattura: <?php echo htmlspecialchars($r['db_record']['n_fattura'] ?? '-'); ?></small>
                <?php endif; ?>

                <?php foreach ($r['anomalie'] as $a): ?>
                    <?php if ($a['tipo'] === 'DATO_PERSO'): ?>
                        <div class="anomalia-block">
                            <strong>DATO PERSO<?php echo $a['obbligatorio'] ? ' [OBBL]' : ''; ?>:</strong>
                            <?php echo htmlspecialchars($a['campo']); ?> &rarr;
                            PDF: <code><?php echo htmlspecialchars($a['valore_pdf']); ?></code>,
                            DB: <code>NULL</code>
                        </div>
                    <?php elseif ($a['tipo'] === 'VALORE_DIVERSO'): ?>
                        <div class="anomalia-block warn">
                            <strong>VALORE DIVERSO<?php echo $a['obbligatorio'] ? ' [OBBL]' : ''; ?>:</strong>
                            <?php echo htmlspecialchars($a['campo']); ?> &rarr;
                            PDF: <code><?php echo htmlspecialchars($a['valore_pdf']); ?></code>,
                            DB: <code><?php echo htmlspecialchars($a['valore_db']); ?></code>
                        </div>
                    <?php elseif ($a['tipo'] === 'NON_IN_DB'): ?>
                        <div class="anomalia-block">
                            <strong>Nessun record nel database per questa fattura</strong>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if (!empty($r['orfani'])): ?>
                    <div class="anomalia-block warn">
                        <strong>Importi € non mappati da nessun pattern:</strong>
                        <?php foreach (array_slice($r['orfani'], 0, 5) as $riga): ?>
                            <div class="orfano">&rarr; <?php echo htmlspecialchars($riga); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $conOrfani = array_filter($risultati, fn($r) => !empty($r['orfani']));
    if (!empty($conOrfani)):
    ?>
    <div class="card">
        <div class="card-title" style="background:#856404;">Importi non mappati da nessun pattern (<?php echo count($conOrfani); ?> fatture)</div>
        <div class="card-body">
        <?php foreach ($conOrfani as $r): ?>
            <div style="margin-bottom:16px;">
                <strong><?php echo htmlspecialchars($r['file']); ?></strong>
                <?php if (!empty($r['db_record']['n_fattura'])): ?>
                    <small style="color:#666;margin-left:8px">n.fattura: <?php echo htmlspecialchars($r['db_record']['n_fattura']); ?></small>
                <?php endif; ?>
                <?php if (!empty($r['db_record']['data'])): ?>
                    <small style="color:#666;margin-left:4px">data: <?php echo htmlspecialchars($r['db_record']['data']); ?></small>
                <?php endif; ?>
                <div class="anomalia-block warn" style="margin-top:6px;">
                    <?php foreach ($r['orfani'] as $riga): ?>
                        <div class="orfano">&rarr; <?php echo htmlspecialchars($riga); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php
        // Aggregazione riepilogativa per voce
        $riepilogoOrfani = [];
        foreach ($conOrfani as $r) {
            foreach ($r['orfani'] as $riga) {
                if (!preg_match('/(-?[\d\.,]+)\s*€/', $riga, $m)) continue;
                $importoNum = (float)str_replace(',', '.', str_replace('.', '', $m[1]));
                $descr = trim(preg_replace('/-?[\d\.,]+\s*€.*$/', '', $riga), " \t-");
                if ($descr === '') $descr = '(importo senza descrizione)';
                if (!isset($riepilogoOrfani[$descr])) {
                    $riepilogoOrfani[$descr] = ['count' => 0, 'totale' => 0.0];
                }
                $riepilogoOrfani[$descr]['count']++;
                $riepilogoOrfani[$descr]['totale'] += $importoNum;
            }
        }
        uasort($riepilogoOrfani, fn($a, $b) => $b['count'] <=> $a['count']);
        ?>
        <hr style="margin:20px 0;border:none;border-top:1px solid #eee;">
        <h3 style="margin-bottom:12px;color:#856404;font-size:1em;">Riepilogo per voce</h3>
        <table>
            <thead>
                <tr>
                    <th>Descrizione voce</th>
                    <th style="text-align:center;">Ricorrenze</th>
                    <th style="text-align:right;">Totale €</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($riepilogoOrfani as $descr => $dati): ?>
                <tr>
                    <td><?php echo htmlspecialchars($descr); ?></td>
                    <td style="text-align:center;"><?php echo $dati['count']; ?></td>
                    <td style="text-align:right;font-family:monospace;"><?php echo number_format($dati['totale'], 2, ',', '.'); ?> €</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($dbSenzaPdf)): ?>
    <div class="card">
        <div class="card-title">Record DB senza PDF locale (<?php echo count($dbSenzaPdf); ?>)</div>
        <div class="card-body">
        <p style="color:#666;margin-bottom:12px">Record presenti nel DB ma il PDF non è nella cartella analizzata (potrebbe essere in un'altra posizione).</p>
        <table>
            <thead><tr><th>File PDF</th><th>N. Fattura</th><th>Data</th></tr></thead>
            <tbody>
            <?php foreach ($dbSenzaPdf as $row): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['file']); ?></td>
                <td><?php echo htmlspecialchars($row['n_fattura']); ?></td>
                <td><?php echo htmlspecialchars($row['data']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /container -->
</body>
</html>
<?php
endif; // !$isCli
