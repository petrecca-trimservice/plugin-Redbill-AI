<?php
// estrai_fatture_glovo.php

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;

// CONFIG DB
$config = require __DIR__ . '/config-glovo.php';

// CARTELLE PDF
$pdfDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/pdf');
$outCsv = __DIR__ . '/fatture_glovo_estratte.csv';

if ($pdfDir === false) {
    die("Cartella PDF non trovata.\n");
}

// CARTELLA PROCESSED
$processedDir = $pdfDir . '/processed';
if (!is_dir($processedDir)) {
    mkdir($processedDir, 0775, true);
}

// CARTELLA FAILED (per PDF con errori di estrazione)
$failedDir = $pdfDir . '/failed';
if (!is_dir($failedDir)) {
    mkdir($failedDir, 0775, true);
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

// QUERY INSERT
$table = $config['db_table'];

$sql = "INSERT INTO `$table` (
    file_pdf,
    destinatario,
    negozio,
    n_fattura,
    data,
    periodo_da,
    periodo_a,
    commissioni,
    marketing_visibilita,
    subtotale,
    iva_22,
    totale_fattura_iva_inclusa,
    prodotti,
    servizio_consegna,
    totale_fattura_riepilogo,
    promo_prodotti_partner,
    promo_consegna_partner,
    costi_offerta_lampo,
    promo_lampo_partner,
    costo_incidenti_prodotti,
    tariffa_tempo_attesa,
    rimborsi_partner_senza_comm,
    costo_annullamenti_servizio,
    consegna_gratuita_incidente,
    buoni_pasto,
    supplemento_ordine_glovo_prime,
    glovo_gia_pagati,
    ordini_rimborsati_partner,
    commissione_ordini_rimborsati,
    sconto_comm_ordini_buoni_pasto,
    debito_accumulato,
    importo_bonifico
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("Errore prepare(): " . $mysqli->error);
}

// LISTA PDF
$pdfParser = new Parser();
$files = glob($pdfDir . '/*.pdf');

if (!$files) {
    die("Nessun PDF trovato.\n");
}

$totalePdfTrovati = count($files);

// CSV
$fh = fopen($outCsv, 'w');
if (!$fh) {
    die("Impossibile aprire il CSV.\n");
}

$headers = [
    'file_pdf',
    'destinatario',
    'negozio',
    'n_fattura',
    'data',
    'periodo_da',
    'periodo_a',
    'commissioni',
    'marketing_visibilita',
    'subtotale',
    'iva_22',
    'totale_fattura_iva_inclusa',
    'prodotti',
    'servizio_consegna',
    'totale_fattura_riepilogo',
    'promo_prodotti_partner',
    'promo_consegna_partner',
    'costi_offerta_lampo',
    'promo_lampo_partner',
    'costo_incidenti_prodotti',
    'tariffa_tempo_attesa',
    'rimborsi_partner_senza_comm',
    'costo_annullamenti_servizio',
    'consegna_gratuita_incidente',
    'buoni_pasto',
    'supplemento_ordine_glovo_prime',
    'glovo_gia_pagati',
    'ordini_rimborsati_partner',
    'commissione_ordini_rimborsati',
    'sconto_comm_ordini_buoni_pasto',
    'debito_accumulato',
    'importo_bonifico',
];
fputcsv($fh, $headers, ';', '"', '\\');

// NORMALIZZA €
function normalizeEuroAmount($val) {
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '' || $val === '-') return null;

    $val = str_replace(['€', ' ', '.'], '', $val);
    return str_replace(',', '.', $val);
}

// SANITIZZA STRINGHE (rimuove caratteri invisibili, normalizza spazi e sigle societarie)
function sanitizeString($val) {
    if ($val === null) return null;

    // Trim normale
    $val = trim($val);
    if ($val === '') return null;

    // Rimuove caratteri invisibili Unicode (zero-width spaces, non-breaking spaces, ecc.)
    $val = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $val);

    // Normalizza spazi multipli in uno solo
    $val = preg_replace('/\s+/', ' ', $val);

    // Normalizza sigle societarie AGGIUNGENDO i punti dove mancano
    $val = preg_replace('/\bS\.?R\.?L\.?/iu', 'S.R.L.', $val);
    $val = preg_replace('/\bS\.?R\.?L\.?S\.?/iu', 'S.R.L.S.', $val);
    $val = preg_replace('/\bS\.?P\.?A\.?/iu', 'S.P.A.', $val);
    $val = preg_replace('/\bS\.?A\.?S\.?/iu', 'S.A.S.', $val);
    $val = preg_replace('/\bS\.?N\.?C\.?/iu', 'S.N.C.', $val);

    return trim($val);
}

// NORMALIZZA NEGOZIO (converte indirizzi in nomi di negozio)
function normalizeNegozio($val) {
    if ($val === null) return null;

    // Trim iniziale
    $val = trim($val);
    if ($val === '') return null;

    // Mappa indirizzi → nomi negozio
    $mappings = [
        'Viale Coni Zugna, 43, 20144 Milano MI, Italia' => 'Girarrosti Santa Rita - Milano',
        '307, Corso Susa, 301/307, 10098 Rivoli TO, Italy' => 'Girarrosti Santa Rita - Rivoli',
        'Via Martiri della Libertà, 74, 10099 San Mauro Torinese TO, Italia' => 'Girarrosti Santa Rita - San Mauro',
        'Via S. Mauro, 1, 10036 Settimo Torinese TO, Italia' => 'Girarrosti Santa Rita - Settimo Torinese',
        'Via Vittorio Alfieri, 9, 10043 Orbassano TO, Italia' => 'Girarrosti Santa Rita - Via Vittorio Alfieri - Orbassano',
    ];

    // Controlla se l'indirizzo è nella mappa
    if (isset($mappings[$val])) {
        return $mappings[$val];
    }

    // Altrimenti ritorna il valore originale (trimmed)
    return $val;
}

// PARSE DESTINATARIO (riga1 = destinatario, riga2 = negozio)
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

// PARSE FATTURA
function parseGlovoInvoice($text) {

    $data = [
        'destinatario' => null,
        'negozio' => null,
        'n_fattura' => null,
        'data' => null,
        'periodo_da' => null,
        'periodo_a' => null,
        'commissioni' => null,
        'marketing_visibilita' => null,
        'subtotale' => null,
        'iva_22' => null,
        'totale_fattura_iva_inclusa' => null,
        'prodotti' => null,
        'servizio_consegna' => null,
        'totale_fattura_riepilogo' => null,
        'promo_prodotti_partner' => null,
        'promo_consegna_partner' => null,
        'costi_offerta_lampo' => null,
        'promo_lampo_partner' => null,
        'costo_incidenti_prodotti' => null,
        'tariffa_tempo_attesa' => null,
        'rimborsi_partner_senza_comm' => null,
        'costo_annullamenti_servizio' => null,
        'consegna_gratuita_incidente' => null,
        'buoni_pasto' => null,
        'supplemento_ordine_glovo_prime' => null,
        'glovo_gia_pagati' => null,
        'ordini_rimborsati_partner' => null,
        'commissione_ordini_rimborsati' => null,
        'sconto_comm_ordini_buoni_pasto' => null,
        'debito_accumulato' => null,
        'importo_bonifico' => null,
    ];

    // DESTINATARIO + NEGOZIO
    $d = parseDestinatario($text);
    $data['destinatario'] = $d['destinatario'];
    $data['negozio']      = $d['negozio'];

    // N. fattura (prima dell'etichetta "N. fattura:")
    // Supporta formati: HDCKZB123456, IT-PF-3IR51KD-001/23, ecc.
    if (preg_match('/\b([A-Z0-9][A-Z0-9\-\/]{7,})\s+N\. fattura:/', $text, $m)) {
        $data['n_fattura'] = trim($m[1]);
    }

    // DATA
    if (preg_match('/Data:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/', $text, $m)) {
        $data['data'] = $m[1];
    }

    // PERIODO
    if (preg_match('/Servizio fornito da\s*([0-9]{4}-[0-9]{2}-[0-9]{2})\s*a\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/', $text, $m)) {
        $data['periodo_da'] = $m[1];
        $data['periodo_a']  = $m[2];
    }

    // IMPORTI (corretti per +Prodotti, -Totale fattura, ecc.)
    $patterns = [
        'commissioni'                 => '/Commissioni\s*([\d\.,]+)\s*€/u',
        'marketing_visibilita'        => '/Marketing[\s\-]*visibilit[àáa]\s*([\d\.,]+)\s*€/ui',
        'subtotale'                   => '/Subtotale\s*([\d\.,]+)\s*€/u',
        'iva_22'                      => '/IVA\s*\(22\s*%\)\s*([\d\.,]+)\s*€/u',
        'totale_fattura_iva_inclusa'  => '/Totale fattura\s*\(IVA inclusa\)\s*([\d\.,]+)\s*€/us',

        // QUI IL FIX: opzionale lo spazio dopo + / -
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

// VALIDAZIONE CAMPI OBBLIGATORI
function validaDatiEstratti($data, $pdfFile) {
    // Campi obbligatori basati su analisi 466 fatture database
    // Tutti i campi al 100% + campi quasi sempre presenti (>98%)
    $campiObbligatori = [
        // Identificativi (100%)
        'n_fattura',                    // 100% - Numero fattura univoco
        'data',                         // 100% - Data emissione
        'destinatario',                 // 100% - Intestatario
        'negozio',                      // 100% - Nome negozio
        'periodo_da',                   // 100% - Inizio periodo
        'periodo_a',                    // 100% - Fine periodo

        // Sezione tariffe principale (100%)
        'commissioni',                  // 100% - Commissioni Glovo
        'subtotale',                    // 100% - Subtotale
        'iva_22',                       // 100% - IVA 22%
        'totale_fattura_iva_inclusa',   // 100% - Totale IVA inclusa

        // Sezione riepilogo (100% + >98%)
        'prodotti',                     // 100% - Vendita prodotti
        'totale_fattura_riepilogo',     // 100% - Totale riepilogo
        'promo_prodotti_partner',       //  99.6% - Promozioni partner
        'importo_bonifico'              //  98.5% - Importo finale
    ];

    $errori = [];

    // Controlla ogni campo obbligatorio
    foreach ($campiObbligatori as $campo) {
        if (empty($data[$campo]) || $data[$campo] === null || $data[$campo] === '') {
            $errori[] = "Campo '$campo' mancante o vuoto";
        }
    }

    // Validazioni aggiuntive
    // Verifica che n_fattura abbia almeno 8 caratteri
    if (!empty($data['n_fattura']) && strlen($data['n_fattura']) < 8) {
        $errori[] = "Campo 'n_fattura' troppo corto (< 8 caratteri): '{$data['n_fattura']}'";
    }

    // Verifica che la data sia nel formato corretto
    if (!empty($data['data'])) {
        $datePattern = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($datePattern, $data['data'])) {
            $errori[] = "Campo 'data' in formato non valido: '{$data['data']}'";
        }
    }

    return $errori;
}

// RILEVA IMPORTI € NEL PDF NON COPERTI DA NESSUN PATTERN
// Restituisce array di righe "orfane" (testo + importo non estratto)
function trovaImportiNonMappati($text, $datiEstratti) {
    // Costruisce l'insieme dei valori numerici già estratti (assoluti, per confronto)
    $valoriMappati = [];
    foreach ($datiEstratti as $val) {
        if ($val === null || $val === '') continue;
        $n = (float) str_replace(',', '.', $val);
        if ($n == 0) continue;
        $abs = abs($n);
        // Stessa formattazione che usa il PDF (es. 1.234,56 oppure 234,56)
        $valoriMappati[] = number_format($abs, 2, ',', '.');
        $valoriMappati[] = number_format($abs, 2, ',', '');
    }
    $valoriMappati = array_unique($valoriMappati);

    // Linee da ignorare: intestazioni, label pure senza importo aggiuntivo
    $righeIgnorate = [
        'Importo del bonifico',   // già mappato separatamente
        'IVA (22 %)',             // già mappato
        'Totale fattura (IVA',    // già mappato
        'Subtotale',              // già mappato
        'Commissioni',            // già mappato
    ];

    $orfani = [];
    // Cerca righe con formato numero+€ (es. "Nuova voce 45,00 €")
    if (!preg_match_all('/^(.{5,100}?)([\d]{1,3}(?:\.\d{3})*,\d{2})\s*€/um', $text, $matches, PREG_SET_ORDER)) {
        return $orfani;
    }

    foreach ($matches as $match) {
        $rigaCompleta = trim($match[0]);
        $importo      = $match[2];

        // Salta se l'importo è già in uno dei campi estratti.
        // Confronto senza segno: $valoriMappati usa abs(), qui rimuoviamo
        // l'eventuale '-' catturato in modo da gestire importi negativi.
        $importoNorm = ltrim($importo, '-');
        if (in_array($importoNorm, $valoriMappati, true)) continue;

        // Salta righe di intestazione note
        $salta = false;
        foreach ($righeIgnorate as $label) {
            if (mb_stripos($rigaCompleta, $label) !== false) { $salta = true; break; }
        }
        if ($salta) continue;

        // Salta righe troppo corte (solo un numero) o che sono date
        if (strlen($rigaCompleta) < 10) continue;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $rigaCompleta)) continue;

        $orfani[] = $rigaCompleta;
    }

    // Deduplica (stessa riga può apparire più volte per riepilogo/totali)
    return array_values(array_unique($orfani));
}

// LOGGING DEGLI ERRORI
function logErrore($pdfFile, $errori, $data = null) {
    $logFile = __DIR__ . '/estrazione_errori.log';

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "\n" . str_repeat('=', 80) . "\n";
    $logEntry .= "[$timestamp] ERRORE ESTRAZIONE\n";
    $logEntry .= "File PDF: $pdfFile\n";
    $logEntry .= str_repeat('-', 80) . "\n";

    // Elenca gli errori
    $logEntry .= "Errori rilevati:\n";
    foreach ($errori as $i => $errore) {
        $logEntry .= "  " . ($i + 1) . ". $errore\n";
    }

    // Se disponibili, mostra i dati estratti (per debug)
    if ($data !== null) {
        $logEntry .= str_repeat('-', 80) . "\n";
        $logEntry .= "Dati estratti (parziali):\n";
        foreach ($data as $campo => $valore) {
            $valoreTroncato = $valore === null ? 'NULL' : (strlen($valore) > 50 ? substr($valore, 0, 47) . '...' : $valore);
            $logEntry .= "  - $campo: $valoreTroncato\n";
        }
    }

    $logEntry .= str_repeat('=', 80) . "\n";

    // Scrivi nel log file
    file_put_contents($logFile, $logEntry, FILE_APPEND);

    return $logFile;
}

// INVIO EMAIL CON LISTA ERRORI RAGGRUPPATI
function inviaEmailErroriRaggruppati($pdfConErrori, $config) {
    // Verifica se alert_email è configurato
    if (empty($config['alert_email'])) {
        return false;
    }

    // Normalizza alert_email in array (supporta sia stringa che array)
    $emails = is_array($config['alert_email'])
        ? $config['alert_email']
        : [$config['alert_email']];

    // Rimuove email placeholder non configurate
    $emails = array_filter($emails, function($email) {
        return !empty($email) && $email !== 'tua@email.com';
    });

    if (empty($emails) || empty($pdfConErrori)) {
        // Nessuna email valida configurata o nessun errore da segnalare
        return false;
    }

    // Prepara il messaggio
    $totalePdfErrori = count($pdfConErrori);
    $subject = "⚠️ Errori validazione fatture Glovo - {$totalePdfErrori} PDF falliti";

    $message = "ATTENZIONE: Errori durante l'estrazione dei dati dalle fatture PDF.\n\n";
    $message .= "Data/ora: " . date('Y-m-d H:i:s') . "\n";
    $message .= "Totale PDF con errori: {$totalePdfErrori}\n\n";
    $message .= str_repeat('=', 70) . "\n\n";

    // Lista dettagliata di ogni PDF con errori
    foreach ($pdfConErrori as $i => $pdfErrore) {
        $message .= "PDF #" . ($i + 1) . ": {$pdfErrore['file']}\n";
        $message .= str_repeat('-', 70) . "\n";
        $message .= "Errori rilevati:\n";
        foreach ($pdfErrore['errori'] as $j => $errore) {
            $message .= "  " . ($j + 1) . ". $errore\n";
        }
        $message .= "\n";
    }

    $message .= str_repeat('=', 70) . "\n\n";
    $message .= "POSSIBILE CAUSA:\n";
    $message .= "• Cambio del layout delle fatture PDF da parte di Glovo\n";
    $message .= "• Fatture con formato diverso dal solito\n\n";

    $message .= "AZIONE RICHIESTA:\n";
    $message .= "1. Verificare i PDF nella cartella: failed/\n";
    $message .= "2. Controllare il log dettagliato: estrazione_errori.log\n";
    $message .= "3. Se necessario, aggiornare i pattern regex di estrazione\n\n";

    $message .= "FILE SPOSTATI IN 'failed/':\n";
    foreach ($pdfConErrori as $pdfErrore) {
        $message .= "• {$pdfErrore['file']}\n";
    }
    $message .= "\n";

    $message .= str_repeat('=', 70) . "\n";
    $message .= "Questo è un messaggio automatico dal sistema di estrazione fatture Glovo.\n";

    $headers = "From: noreply@girarrostisantarita.it\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    // Invia email a tutti gli indirizzi configurati
    $successCount = 0;
    foreach ($emails as $email) {
        $email = trim($email);
        if (mail($email, $subject, $message, $headers)) {
            $successCount++;
        }
    }

    // Ritorna true se almeno una email è stata inviata
    return $successCount > 0;
}

// INVIO EMAIL RIASSUNTIVA FINALE
function inviaEmailRiepilogo($contatori, $totalePdf, $config, $pdfConVociNonMappate = []) {
    // Verifica se alert_email è configurato
    if (empty($config['alert_email'])) {
        return false;
    }

    // Normalizza alert_email in array (supporta sia stringa che array)
    $emails = is_array($config['alert_email'])
        ? $config['alert_email']
        : [$config['alert_email']];

    // Rimuove email placeholder non configurate
    $emails = array_filter($emails, function($email) {
        return !empty($email) && $email !== 'tua@email.com';
    });

    if (empty($emails)) {
        // Nessuna email valida configurata
        return false;
    }

    // Determina lo stato generale dell'elaborazione
    $haErrori = $contatori['validazione_fallita'] > 0
             || $contatori['errori_db'] > 0
             || $contatori['importi_non_mappati'] > 0;
    $statusIcon = $haErrori ? '⚠️' : '✅';
    $statusText = $haErrori ? 'con errori' : 'completata';

    // Prepara il messaggio
    $subject = "{$statusIcon} Riepilogo elaborazione fatture Glovo - " . date('Y-m-d H:i');

    $message = "RIEPILOGO ELABORAZIONE FATTURE GLOVO\n";
    $message .= str_repeat('=', 60) . "\n\n";
    $message .= "Data/ora: " . date('Y-m-d H:i:s') . "\n";
    $message .= "Stato: Elaborazione {$statusText}\n\n";

    $message .= str_repeat('-', 60) . "\n";
    $message .= "STATISTICHE\n";
    $message .= str_repeat('-', 60) . "\n";
    $message .= "Totale PDF trovati:         {$totalePdf}\n";
    $message .= "✅ Processati con successo: {$contatori['processati_ok']}\n";
    $message .= "ℹ️  Duplicati (saltati):     {$contatori['duplicati']}\n";
    $message .= "❌ Validazione fallita:     {$contatori['validazione_fallita']}\n";
    $message .= "❌ Errori database:         {$contatori['errori_db']}\n";
    $message .= "⚠️  Voci non mappate:        {$contatori['importi_non_mappati']} PDF\n";
    $message .= str_repeat('-', 60) . "\n\n";

    // Sezione copertura campi (solo se almeno un PDF è stato inserito)
    if ($contatori['processati_ok'] > 0) {
        $campiEstratti  = $contatori['campi_estratti_totale'];
        $campiPossibili = $contatori['campi_totale_possibili'];
        $percGlobale    = $campiPossibili > 0 ? round($campiEstratti / $campiPossibili * 100, 1) : 0;
        $pieni          = $contatori['pdf_copertura_piena'];
        $totOk          = $contatori['processati_ok'];

        $message .= str_repeat('-', 60) . "\n";
        $message .= "COPERTURA DATI ESTRATTI\n";
        $message .= str_repeat('-', 60) . "\n";
        $message .= "Copertura globale campi:    {$percGlobale}% ({$campiEstratti}/{$campiPossibili})\n";
        $message .= "PDF con copertura 100%:     {$pieni}/{$totOk}\n";

        // Campi che erano NULL in almeno una fattura
        $campiNullDettaglio = $contatori['campi_null_dettaglio'] ?? [];
        if (!empty($campiNullDettaglio)) {
            arsort($campiNullDettaglio);
            $message .= "\nCampi assenti in almeno una fattura (opzionali o da verificare):\n";
            foreach ($campiNullDettaglio as $campo => $volte) {
                $message .= "  - $campo: assente in $volte fattura/e\n";
            }
        } else {
            $message .= "✅ Tutti i 31 campi estratti in ogni fattura processata\n";
        }
        $message .= str_repeat('-', 60) . "\n\n";
    }

    // Sezione voci non mappate (nuovo campo potenziale in Glovo)
    if (!empty($pdfConVociNonMappate)) {
        $message .= str_repeat('-', 60) . "\n";
        $message .= "⚠️  VOCI € NON MAPPATE DA NESSUN PATTERN\n";
        $message .= str_repeat('-', 60) . "\n";
        $message .= "Rilevate " . count($pdfConVociNonMappate) . " fattura/e con importi non riconosciuti.\n";
        $message .= "Potrebbe indicare un NUOVO CAMPO introdotto da Glovo.\n\n";
        foreach ($pdfConVociNonMappate as $item) {
            $message .= "PDF: {$item['file']}\n";
            foreach ($item['voci'] as $riga) {
                $message .= "  → $riga\n";
            }
            $message .= "\n";
        }

        // Tabella riepilogativa per voce
        $riepilogoVoci = [];
        foreach ($pdfConVociNonMappate as $item) {
            foreach ($item['voci'] as $riga) {
                if (!preg_match('/(-?[\d\.,]+)\s*€/', $riga, $m)) continue;
                $importoNum = (float)str_replace(',', '.', str_replace('.', '', $m[1]));
                $descr = trim(preg_replace('/-?[\d\.,]+\s*€.*$/', '', $riga), " \t-");
                if ($descr === '') $descr = '(importo senza descrizione)';
                if (!isset($riepilogoVoci[$descr])) {
                    $riepilogoVoci[$descr] = ['count' => 0, 'totale' => 0.0];
                }
                $riepilogoVoci[$descr]['count']++;
                $riepilogoVoci[$descr]['totale'] += $importoNum;
            }
        }
        if (!empty($riepilogoVoci)) {
            uasort($riepilogoVoci, fn($a, $b) => $b['count'] <=> $a['count']);
            $message .= "RIEPILOGO PER VOCE:\n";
            $message .= sprintf("  %-42s %6s %12s\n", 'Descrizione voce', 'Num.', 'Totale €');
            $message .= "  " . str_repeat('-', 58) . "\n";
            foreach ($riepilogoVoci as $descr => $dati) {
                $totFmt = number_format($dati['totale'], 2, ',', '.');
                $message .= sprintf("  %-42s %6d %12s\n",
                    mb_substr($descr, 0, 42), $dati['count'], $totFmt . ' €');
            }
            $message .= "\n";
        }

        $message .= "AZIONE RICHIESTA:\n";
        $message .= "Verificare le righe sopra e aggiungere il pattern mancante\n";
        $message .= "in estrai_fatture_glovo.php se si tratta di un nuovo campo.\n";
        $message .= str_repeat('-', 60) . "\n\n";
    }

    // Sezione avvisi
    if ($haErrori) {
        $message .= "⚠️  ATTENZIONE - AZIONI RICHIESTE:\n\n";

        if ($contatori['validazione_fallita'] > 0) {
            $message .= "• {$contatori['validazione_fallita']} PDF con errori di validazione\n";
            $message .= "  → Controlla cartella: failed/\n";
            $message .= "  → Controlla log: estrazione_errori.log\n";
            $message .= "  → Possibile cambio layout PDF da parte di Glovo\n\n";
        }

        if ($contatori['errori_db'] > 0) {
            $message .= "• {$contatori['errori_db']} errori di database\n";
            $message .= "  → Verifica connessione database\n";
            $message .= "  → I PDF con errori DB sono rimasti nella cartella pdf/\n\n";
        }

        if ($contatori['importi_non_mappati'] > 0) {
            $message .= "• {$contatori['importi_non_mappati']} PDF con importi non riconosciuti\n";
            $message .= "  → Vedi sezione 'VOCI NON MAPPATE' sopra\n";
            $message .= "  → Aggiornare i pattern regex se Glovo ha introdotto nuovi campi\n\n";
        }
    } else {
        $message .= "✅ Elaborazione completata senza errori\n\n";
    }

    // Informazioni aggiuntive
    $message .= str_repeat('-', 60) . "\n";
    $message .= "FILE GENERATI\n";
    $message .= str_repeat('-', 60) . "\n";
    $message .= "• CSV: fatture_glovo_estratte.csv\n";
    if ($contatori['validazione_fallita'] > 0) {
        $message .= "• Log errori: estrazione_errori.log\n";
    }
    $message .= "\n";

    $message .= str_repeat('-', 60) . "\n";
    $message .= "CARTELLE\n";
    $message .= str_repeat('-', 60) . "\n";
    $message .= "• PDF processati: pdf/processed/ ({$contatori['processati_ok']} + {$contatori['duplicati']} file)\n";
    if ($contatori['validazione_fallita'] > 0) {
        $message .= "• PDF falliti: pdf/failed/ ({$contatori['validazione_fallita']} file)\n";
    }
    $message .= "\n";

    $message .= str_repeat('=', 60) . "\n";
    $message .= "Questo è un messaggio automatico dal sistema di estrazione fatture Glovo.\n";

    $headers = "From: noreply@girarrostisantarita.it\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    // Invia email a tutti gli indirizzi configurati
    $successCount = 0;
    foreach ($emails as $email) {
        $email = trim($email);
        if (mail($email, $subject, $message, $headers)) {
            $successCount++;
        }
    }

    // Ritorna true se almeno una email è stata inviata
    return $successCount > 0;
}

// INVIO EMAIL DI NOTIFICA
function inviaEmailAlert($pdfFile, $errori, $config) {
    // Verifica se alert_email è configurato
    if (empty($config['alert_email'])) {
        return false;
    }

    // Normalizza alert_email in array (supporta sia stringa che array)
    $emails = is_array($config['alert_email'])
        ? $config['alert_email']
        : [$config['alert_email']];

    // Rimuove email placeholder non configurate
    $emails = array_filter($emails, function($email) {
        return !empty($email) && $email !== 'tua@email.com';
    });

    if (empty($emails)) {
        // Nessuna email valida configurata
        return false;
    }

    // Prepara il messaggio
    $subject = '⚠️ Errore estrazione fattura Glovo - ' . basename($pdfFile);

    $message = "ATTENZIONE: Errore durante l'estrazione dei dati dalla fattura PDF.\n\n";
    $message .= "File PDF: " . basename($pdfFile) . "\n";
    $message .= "Data/ora: " . date('Y-m-d H:i:s') . "\n\n";
    $message .= "Errori rilevati:\n";
    foreach ($errori as $i => $errore) {
        $message .= ($i + 1) . ". $errore\n";
    }
    $message .= "\n";
    $message .= "POSSIBILE CAUSA: Cambio del layout delle fatture PDF da parte di Glovo.\n\n";
    $message .= "AZIONE RICHIESTA:\n";
    $message .= "1. Verificare il file PDF in: failed/" . basename($pdfFile) . "\n";
    $message .= "2. Controllare il log dettagliato in: estrazione_errori.log\n";
    $message .= "3. Se necessario, aggiornare i pattern regex di estrazione\n\n";
    $message .= "---\n";
    $message .= "Questo è un messaggio automatico dal sistema di estrazione fatture Glovo.\n";

    $headers = "From: noreply@girarrostisantarita.it\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    // Invia email a tutti gli indirizzi configurati
    $successCount = 0;
    foreach ($emails as $email) {
        $email = trim($email);
        if (mail($email, $subject, $message, $headers)) {
            $successCount++;
        }
    }

    // Ritorna true se almeno una email è stata inviata
    return $successCount > 0;
}

// CAMPI DATI (escluso file_pdf) usati per il calcolo copertura
$CAMPI_DATI_COPERTURA = [
    'destinatario', 'negozio', 'n_fattura', 'data', 'periodo_da', 'periodo_a',
    'commissioni', 'marketing_visibilita', 'subtotale', 'iva_22',
    'totale_fattura_iva_inclusa', 'prodotti', 'servizio_consegna',
    'totale_fattura_riepilogo', 'promo_prodotti_partner', 'promo_consegna_partner',
    'costi_offerta_lampo', 'promo_lampo_partner', 'costo_incidenti_prodotti',
    'tariffa_tempo_attesa', 'rimborsi_partner_senza_comm', 'costo_annullamenti_servizio',
    'consegna_gratuita_incidente', 'buoni_pasto', 'supplemento_ordine_glovo_prime',
    'glovo_gia_pagati', 'ordini_rimborsati_partner', 'commissione_ordini_rimborsati',
    'sconto_comm_ordini_buoni_pasto', 'debito_accumulato', 'importo_bonifico',
];

// --- CICLO PDF ---
$contatori = [
    'processati_ok'      => 0,
    'validazione_fallita' => 0,
    'duplicati'          => 0,
    'errori_db'          => 0,
    // Copertura campi (per email riepilogo)
    'campi_estratti_totale'  => 0,   // somma campi non-null estratti da tutti i PDF inseriti
    'campi_totale_possibili' => 0,   // 28 * numero PDF inseriti con successo
    'pdf_copertura_piena'    => 0,   // PDF dove tutti i 28 campi sono stati estratti
    'campi_null_dettaglio'   => [],  // campo => quante volte era NULL all'estrazione
    'importi_non_mappati'    => 0,   // PDF con voci € non coperte da nessun pattern
];

// Array per raccogliere PDF con errori (da inviare in un'unica email)
$pdfConErrori = [];

// Array per raccogliere PDF con importi € non mappati da nessun pattern
$pdfConVociNonMappate = [];

foreach ($files as $file) {

    $fileName = basename($file);
    echo "Elaboro: $fileName\n";

    $pdf   = $pdfParser->parseFile($file);
    $pages = $pdf->getPages();
    if (!isset($pages[0])) {
        echo "  Nessuna prima pagina, salto.\n";
        continue;
    }

    $text = $pages[0]->getText();
    $data = parseGlovoInvoice($text);

    // ========== RILEVAMENTO IMPORTI NON MAPPATI ==========
    $vociOrfane = trovaImportiNonMappati($text, $data);
    if (!empty($vociOrfane)) {
        echo "  ⚠️  Trovati " . count($vociOrfane) . " importo/i € non mappato/i da nessun pattern!\n";
        foreach ($vociOrfane as $riga) {
            echo "     → $riga\n";
        }
        $pdfConVociNonMappate[] = ['file' => $fileName, 'voci' => $vociOrfane];
        $contatori['importi_non_mappati']++;
    }

    // ========== VALIDAZIONE DATI ESTRATTI ==========
    $errori = validaDatiEstratti($data, $fileName);

    if (!empty($errori)) {
        // ❌ VALIDAZIONE FALLITA
        echo "  ❌ VALIDAZIONE FALLITA - Dati incompleti o mancanti!\n";
        foreach ($errori as $errore) {
            echo "     - $errore\n";
        }

        // 1. Scrivi nel log degli errori
        $logFile = logErrore($fileName, $errori, $data);
        echo "  📝 Errore registrato in: " . basename($logFile) . "\n";

        // 2. Raccogli gli errori (verranno inviati in un'unica email alla fine)
        $pdfConErrori[] = [
            'file' => $fileName,
            'errori' => $errori
        ];

        // 3. Sposta il PDF in cartella 'failed'
        $destFailed = $failedDir . '/' . $fileName;
        if (!rename($file, $destFailed)) {
            echo "  ⚠️  ATTENZIONE: impossibile spostare il file in failed/\n";
        } else {
            echo "  📁 PDF spostato in: failed/" . $fileName . "\n";
        }

        // 4. NON salvare nel database né nel CSV
        echo "  ⏭️  Salto inserimento DB e CSV per questo file.\n";
        echo "  📧 Errori saranno inclusi nell'email riassuntiva finale\n\n";
        $contatori['validazione_fallita']++;
        continue; // Vai al prossimo PDF
    }

    // ========== VALIDAZIONE OK - PROCEDI CON SALVATAGGIO ==========
    echo "  ✅ Validazione superata - Dati completi\n";

    // DB con gestione duplicati via eccezione
    $dbInserito = false;
    try {
        $stmt->bind_param(
            str_repeat('s', 32),
            $fileName,
            $data['destinatario'],
            $data['negozio'],
            $data['n_fattura'],
            $data['data'],
            $data['periodo_da'],
            $data['periodo_a'],
            $data['commissioni'],
            $data['marketing_visibilita'],
            $data['subtotale'],
            $data['iva_22'],
            $data['totale_fattura_iva_inclusa'],
            $data['prodotti'],
            $data['servizio_consegna'],
            $data['totale_fattura_riepilogo'],
            $data['promo_prodotti_partner'],
            $data['promo_consegna_partner'],
            $data['costi_offerta_lampo'],
            $data['promo_lampo_partner'],
            $data['costo_incidenti_prodotti'],
            $data['tariffa_tempo_attesa'],
            $data['rimborsi_partner_senza_comm'],
            $data['costo_annullamenti_servizio'],
            $data['consegna_gratuita_incidente'],
            $data['buoni_pasto'],
            $data['supplemento_ordine_glovo_prime'],
            $data['glovo_gia_pagati'],
            $data['ordini_rimborsati_partner'],
            $data['commissione_ordini_rimborsati'],
            $data['sconto_comm_ordini_buoni_pasto'],
            $data['debito_accumulato'],
            $data['importo_bonifico']
        );

        $stmt->execute();
        $dbInserito = true;

        // ---- TRACKING COPERTURA CAMPI ----
        $campiPresenti = 0;
        $campiNulli    = 0;
        foreach ($CAMPI_DATI_COPERTURA as $campo) {
            if (isset($data[$campo]) && $data[$campo] !== null && $data[$campo] !== '') {
                $campiPresenti++;
            } else {
                $campiNulli++;
                $contatori['campi_null_dettaglio'][$campo] = ($contatori['campi_null_dettaglio'][$campo] ?? 0) + 1;
            }
        }
        $totCampi = count($CAMPI_DATI_COPERTURA);
        $contatori['campi_estratti_totale']  += $campiPresenti;
        $contatori['campi_totale_possibili'] += $totCampi;
        if ($campiNulli === 0) {
            $contatori['pdf_copertura_piena']++;
        }
        $percCopertura = round($campiPresenti / $totCampi * 100);
        echo "  📊 Copertura campi: {$campiPresenti}/{$totCampi} ({$percCopertura}%)\n";
        // ----------------------------------

        // Salva nel CSV solo se inserimento DB ha successo
        $row = array_merge([$fileName], array_values($data));
        fputcsv($fh, $row, ';', '"', '\\');

    } catch (mysqli_sql_exception $e) {

        // 1062 = duplicate entry (UNIQUE n_fattura)
        if ($e->getCode() == 1062) {
            echo "  ℹ️  Duplicato (n_fattura già esistente), salto inserimento DB e CSV.\n";
            $contatori['duplicati']++;
        } else {
            echo "  ❌ ERRORE DB: " . $e->getMessage() . "\n";
            $contatori['errori_db']++;
            // non spostiamo il PDF, così lo puoi controllare
            continue;
        }
    }

    // SPOSTA PDF IN processed (sia per successo che per duplicati)
    $dest = $processedDir . '/' . $fileName;
    if (!rename($file, $dest)) {
        echo "  ⚠️  ATTENZIONE: impossibile spostare il file.\n";
    } else {
        echo "  ✅ Spostato in processed/\n";
        // Incrementa contatore solo se effettivamente inserito nel DB
        if ($dbInserito) {
            $contatori['processati_ok']++;
        }
    }
}

// ========== RIEPILOGO FINALE ==========
echo "\n" . str_repeat('=', 60) . "\n";
echo "RIEPILOGO ELABORAZIONE\n";
echo str_repeat('=', 60) . "\n";
echo "✅ Processati con successo: {$contatori['processati_ok']}\n";
echo "❌ Validazione fallita:     {$contatori['validazione_fallita']}\n";
echo "ℹ️  Duplicati:               {$contatori['duplicati']}\n";
echo "❌ Errori database:         {$contatori['errori_db']}\n";
echo str_repeat('-', 60) . "\n";

if ($contatori['processati_ok'] > 0) {
    $percGlobale = $contatori['campi_totale_possibili'] > 0
        ? round($contatori['campi_estratti_totale'] / $contatori['campi_totale_possibili'] * 100, 1)
        : 0;
    echo "📊 Copertura campi:         {$percGlobale}%";
    echo " ({$contatori['campi_estratti_totale']}/{$contatori['campi_totale_possibili']})\n";
    echo "   PDF a copertura 100%:   {$contatori['pdf_copertura_piena']}/{$contatori['processati_ok']}\n";
}

echo str_repeat('=', 60) . "\n";

if ($contatori['validazione_fallita'] > 0) {
    echo "⚠️  ATTENZIONE: {$contatori['validazione_fallita']} PDF con errori di estrazione!\n";
    echo "   Controlla la cartella 'failed/' e il file 'estrazione_errori.log'\n";
    echo str_repeat('=', 60) . "\n";
}

fclose($fh);
$stmt->close();
$mysqli->close();

echo "\n📄 CSV generato: $outCsv\n";
echo "✅ Elaborazione completata.\n";

// ========== INVIO EMAIL ==========
$emailsConfig = is_array($config['alert_email'])
    ? implode(', ', $config['alert_email'])
    : $config['alert_email'];

// 1. Invia email con lista errori raggruppati (se ci sono errori)
if (!empty($pdfConErrori)) {
    echo "\n📧 Invio email errori raggruppati...\n";
    if (inviaEmailErroriRaggruppati($pdfConErrori, $config)) {
        echo "   ✅ Email errori inviata a: {$emailsConfig}\n";
        echo "   ({$contatori['validazione_fallita']} PDF con errori)\n";

        // Pausa di 3 secondi per evitare rate limiting
        echo "   ⏳ Attesa 3 secondi prima della email riassuntiva...\n";
        sleep(3);
    } else {
        echo "   ⚠️  Email errori non inviata\n";
    }
}

// 2. Invia email riassuntiva finale (sempre)
echo "\n📧 Invio email riassuntiva finale...\n";
if (inviaEmailRiepilogo($contatori, $totalePdfTrovati, $config, $pdfConVociNonMappate)) {
    echo "   ✅ Email riassuntiva inviata a: {$emailsConfig}\n";
} else {
    echo "   ℹ️  Email riassuntiva non inviata (configurare 'alert_email' in config-glovo.php)\n";
}
