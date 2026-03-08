<?php
// analizza_campi_db.php
// Script per analizzare quali campi sono sempre presenti nelle fatture

error_reporting(E_ALL);
ini_set('display_errors', 1);

// CONFIG DB
$config = require __DIR__ . '/config-glovo.php';

// CONNESSIONE DB
$mysqli = new mysqli(
    $config['db_host'],
    $config['db_user'],
    $config['db_pass'],
    $config['db_name']
);

if ($mysqli->connect_errno) {
    die("Errore DB: " . $mysqli->connect_error);
}

$mysqli->set_charset('utf8mb4');

$table = $config['db_table'];

// Conta totale fatture
$result = $mysqli->query("SELECT COUNT(*) as totale FROM `$table`");
$totale = $result->fetch_assoc()['totale'];

echo "========================================================\n";
echo "ANALISI CAMPI DATABASE - FATTURE GLOVO\n";
echo "========================================================\n";
echo "Totale fatture nel database: $totale\n";
echo "========================================================\n\n";

// Lista di tutti i campi da analizzare (escludo file_pdf che è sempre presente)
$campi = [
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
    'costo_incidenti_prodotti',
    'tariffa_tempo_attesa',
    'rimborsi_partner_senza_comm',
    'costo_annullamenti_servizio',
    'consegna_gratuita_incidente',
    'buoni_pasto',
    'supplemento_ordine_glovo_prime',
    'glovo_gia_pagati',
    'debito_accumulato',
    'importo_bonifico',
];

$statistiche = [];

foreach ($campi as $campo) {
    // Conta quanti record hanno questo campo NON NULL e NON vuoto
    $query = "SELECT
        COUNT(*) as totale,
        SUM(CASE WHEN `$campo` IS NOT NULL AND `$campo` != '' THEN 1 ELSE 0 END) as presenti,
        SUM(CASE WHEN `$campo` IS NULL OR `$campo` = '' THEN 1 ELSE 0 END) as mancanti
    FROM `$table`";

    $result = $mysqli->query($query);
    $row = $result->fetch_assoc();

    $presenti = (int)$row['presenti'];
    $mancanti = (int)$row['mancanti'];
    $percentuale = ($presenti / $totale) * 100;

    $statistiche[$campo] = [
        'presenti' => $presenti,
        'mancanti' => $mancanti,
        'percentuale' => $percentuale,
    ];
}

// Ordina per percentuale decrescente
uasort($statistiche, function($a, $b) {
    return $b['percentuale'] <=> $a['percentuale'];
});

// Mostra risultati
echo "STATISTICHE CAMPI:\n";
echo str_repeat('-', 80) . "\n";
printf("%-35s %10s %10s %12s\n", "CAMPO", "PRESENTI", "MANCANTI", "% PRESENTI");
echo str_repeat('-', 80) . "\n";

foreach ($statistiche as $campo => $stats) {
    $icona = '';
    if ($stats['percentuale'] == 100) {
        $icona = '✅'; // Sempre presente
    } elseif ($stats['percentuale'] >= 95) {
        $icona = '🟢'; // Quasi sempre (95%+)
    } elseif ($stats['percentuale'] >= 80) {
        $icona = '🟡'; // Spesso (80%+)
    } elseif ($stats['percentuale'] >= 50) {
        $icona = '🟠'; // Metà volte (50%+)
    } else {
        $icona = '🔴'; // Raramente (< 50%)
    }

    printf(
        "%-35s %9d  %9d  %10.1f%%  %s\n",
        $campo,
        $stats['presenti'],
        $stats['mancanti'],
        $stats['percentuale'],
        $icona
    );
}

echo str_repeat('-', 80) . "\n\n";

// RACCOMANDAZIONI
echo "========================================================\n";
echo "RACCOMANDAZIONI PER VALIDAZIONE\n";
echo "========================================================\n\n";

echo "✅ CAMPI SEMPRE PRESENTI (100%):\n";
echo "   (Questi DEVONO essere obbligatori)\n";
$sempre = array_filter($statistiche, fn($s) => $s['percentuale'] == 100);
if (empty($sempre)) {
    echo "   Nessun campo sempre presente al 100%\n";
} else {
    foreach ($sempre as $campo => $stats) {
        echo "   - $campo\n";
    }
}
echo "\n";

echo "🟢 CAMPI QUASI SEMPRE PRESENTI (95%+):\n";
echo "   (Consigliato includerli nella validazione)\n";
$quasi = array_filter($statistiche, fn($s) => $s['percentuale'] >= 95 && $s['percentuale'] < 100);
if (empty($quasi)) {
    echo "   Nessun campo in questa categoria\n";
} else {
    foreach ($quasi as $campo => $stats) {
        echo "   - $campo ({$stats['percentuale']}%)\n";
    }
}
echo "\n";

echo "🟡 CAMPI SPESSO PRESENTI (80-95%):\n";
echo "   (Da valutare caso per caso)\n";
$spesso = array_filter($statistiche, fn($s) => $s['percentuale'] >= 80 && $s['percentuale'] < 95);
if (empty($spesso)) {
    echo "   Nessun campo in questa categoria\n";
} else {
    foreach ($spesso as $campo => $stats) {
        echo "   - $campo ({$stats['percentuale']}%)\n";
    }
}
echo "\n";

echo "🔴 CAMPI OPZIONALI (< 80%):\n";
echo "   (NON includere nella validazione obbligatoria)\n";
$opzionali = array_filter($statistiche, fn($s) => $s['percentuale'] < 80);
if (empty($opzionali)) {
    echo "   Nessun campo in questa categoria\n";
} else {
    foreach ($opzionali as $campo => $stats) {
        echo "   - $campo ({$stats['percentuale']}%)\n";
    }
}
echo "\n";

// Genera codice suggerito per la validazione
echo "========================================================\n";
echo "CODICE SUGGERITO PER validaDatiEstratti()\n";
echo "========================================================\n";

$campiObbligatori = array_keys(array_filter($statistiche, fn($s) => $s['percentuale'] >= 95));

echo "\n";
echo "// Campi obbligatori basati su analisi database\n";
echo "\$campiObbligatori = [\n";
foreach ($campiObbligatori as $campo) {
    $perc = $statistiche[$campo]['percentuale'];
    echo "    '$campo',  // Presente nel {$perc}% delle fatture\n";
}
echo "];\n";
echo "\n";

$mysqli->close();

echo "========================================================\n";
echo "Analisi completata!\n";
echo "========================================================\n";
