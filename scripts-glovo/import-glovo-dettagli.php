<?php
/**
 * import-glovo-dettagli.php
 *
 * Importa TUTTI i CSV nella cartella:
 *   wp-content/uploads/msg-extracted/csv/
 *
 * Crea tabelle:
 *   gsr_glovo_dettagli
 *   gsr_glovo_dettagli_items
 *
 * Parsa il campo Description -> prodotti singoli
 * Estrae eventuale "ALLERGIE: ...." in un campo separato allergie_note
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Imposta timezone per evitare problemi di parsing date
date_default_timezone_set('Europe/Rome');

// -----------------------------------------------------------------------------
// 1) CONFIGURAZIONE DB (da config-glovo.php)
// -----------------------------------------------------------------------------

$config = require __DIR__ . '/config-glovo.php';

$host   = $config['db_host'];
$dbname = $config['db_name'];
$user   = $config['db_user'];
$pass   = $config['db_pass'];

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Errore connessione DB: " . $e->getMessage());
}

// -----------------------------------------------------------------------------
// 2) Percorso dei CSV
// -----------------------------------------------------------------------------

$csvDir = realpath(__DIR__ . '/../wp-content/uploads/msg-extracted/csv');

if ($csvDir === false) {
    die("Errore: cartella CSV non trovata.");
}

echo "Cartella CSV: {$csvDir}\n";

$csvFiles = glob($csvDir . '/*.csv');

if (!$csvFiles) {
    die("Nessun CSV trovato nella cartella.");
}

echo "Trovati " . count($csvFiles) . " file CSV\n";

// CARTELLA PROCESSED
$processedDir = $csvDir . '/processed';
if (!is_dir($processedDir)) {
    mkdir($processedDir, 0775, true);
}

// -----------------------------------------------------------------------------
// 3) CREAZIONE TABELLE (qui includo anche la colonna allergie_note)
// -----------------------------------------------------------------------------

$sqlDettagli = <<<SQL
CREATE TABLE IF NOT EXISTS gsr_glovo_dettagli (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    csv_filename VARCHAR(255),   -- <== NUOVO CAMPO per tracciare il file CSV
    notification_partner_time DATETIME NOT NULL,
    description TEXT,
    allergie_note TEXT,          -- <== NUOVO CAMPO

    store_name VARCHAR(255),
    store_address VARCHAR(255),
    payment_method VARCHAR(50),

    price_of_products DECIMAL(10,2),
    product_promotion_paid_by_partner DECIMAL(10,2),
    flash_offer_promotion_paid_by_partner DECIMAL(10,2),
    charged_to_partner_base DECIMAL(10,2),
    glovo_platform_fee DECIMAL(10,2),
    total_charged_to_partner DECIMAL(10,2),
    total_charged_to_partner_percentage DECIMAL(5,2),

    delivery_promotion_paid_by_partner DECIMAL(10,2),
    refunds_incidents DECIMAL(10,2),
    products_paid_in_cash DECIMAL(10,2),
    delivery_price_paid_in_cash DECIMAL(10,2),
    meal_vouchers_discounts DECIMAL(10,2),
    incidents_to_pay_partner DECIMAL(10,2),
    product_with_incidents DECIMAL(10,2),
    incidents_glovo_platform_fee DECIMAL(10,2),
    wait_time_fee DECIMAL(10,2),
    wait_time_fee_refund DECIMAL(10,2),
    prime_order_vendor_fee DECIMAL(10,2),
    flash_deals_fee DECIMAL(10,2),

    KEY idx_store_time (store_name, notification_partner_time),
    UNIQUE KEY unique_order (store_name, notification_partner_time, description(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

$sqlItems = <<<SQL
CREATE TABLE IF NOT EXISTS gsr_glovo_dettagli_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    dettaglio_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,

    FOREIGN KEY (dettaglio_id)
        REFERENCES gsr_glovo_dettagli(id)
        ON DELETE CASCADE,

    KEY idx_product (product_name),
    KEY idx_dettaglio (dettaglio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

$pdo->exec($sqlDettagli);
$pdo->exec($sqlItems);

// -----------------------------------------------------------------------------
// 4) FUNZIONI DI SUPPORTO
// -----------------------------------------------------------------------------

function csvToDecimalOrNull(?string $val)
{
    if ($val === null) return null;
    $val = trim($val);
    if ($val === '') return null;
    $val = str_replace(',', '.', $val);
    return (float)$val;
}

function convertNotificationTime(?string $val): ?string
{
    if (!$val) return null;
    $val = trim($val);
    if ($val === '') return null;

    // Il CSV ha formato Y-m-d ma il sistema lo legge come Y-d-m
    // Quindi usiamo Y-d-m per compensare
    $dt = DateTime::createFromFormat('Y-d-m H:i', $val);
    if ($dt !== false) {
        return $dt->format('Y-m-d H:i:s');
    }
    
    // Prova anche con i secondi
    $dt = DateTime::createFromFormat('Y-d-m H:i:s', $val);
    if ($dt !== false) {
        return $dt->format('Y-m-d H:i:s');
    }
    
    return null;
}

/**
 * Parsifica il campo Description in un array di [quantity, product_name].
 * (già fixato per UTF-8)
 *
 * Gestisce anche il caso speciale del "BOX PATATE MITICHE" che appare come:
 * ", Aggiungi BOX PATATE MITICHE (così non si schiacciano!)"
 */
function parseDescription(string $description): array
{
    $pattern = '/(\d+)\s*x\s*/u';
    $items = [];

    // REGOLA SPECIALE: BOX PATATE MITICHE
    // Cerca pattern: ", Aggiungi BOX PATATE MITICHE..." o "- Aggiungi BOX PATATE MITICHE..."
    $boxPatateMitiche = null;
    if (preg_match('/[,\-]\s*Aggiungi\s+BOX\s+PATATE\s+MITICHE\s*\([^)]+\)/iu', $description, $boxMatch)) {
        $boxPatateMitiche = trim($boxMatch[0], ', -'); // Rimuove virgola, trattino e spazi
        // Rimuove dalla descrizione per il parsing normale
        $description = str_replace($boxMatch[0], '', $description);
    }

    if (!preg_match_all($pattern, $description, $matches, PREG_OFFSET_CAPTURE)) {
        // Se non ci sono pattern normali ma c'è il BOX, aggiungilo comunque
        if ($boxPatateMitiche) {
            $items[] = [
                'quantity'     => 1,
                'product_name' => 'BOX PATATE MITICHE',
            ];
        }
        return $items;
    }

    $fullLen = strlen($description); // byte length
    $numMatches = count($matches[0]);

    for ($i = 0; $i < $numMatches; $i++) {
        $qty = (int)$matches[1][$i][0];

        $matchStart = $matches[0][$i][1];
        $matchLen   = strlen($matches[0][$i][0]); // in byte

        $nameStart = $matchStart + $matchLen;

        if ($i + 1 < $numMatches) {
            $nextMatchStart = $matches[0][$i + 1][1];
            $nameLen = $nextMatchStart - $nameStart;
        } else {
            $nameLen = $fullLen - $nameStart;
        }

        $productName = trim(substr($description, $nameStart, $nameLen));

        if ($productName === '') {
            continue;
        }

        $items[] = [
            'quantity'     => $qty,
            'product_name' => $productName,
        ];
    }

    // Aggiungi il BOX PATATE MITICHE alla fine se presente
    if ($boxPatateMitiche) {
        $items[] = [
            'quantity'     => 1,
            'product_name' => 'BOX PATATE MITICHE',
        ];
    }

    return $items;
}

/**
 * Estrae eventuale "ALLERGIE: ...." dalla description.
 * Ritorna [description_pulita, allergie_note]
 */
function splitDescriptionAndAllergie(string $description): array
{
    $allergieNote = null;
    $clean = $description;

    // Cerco pattern tipo "ALLERGIE: qualcosa" verso la fine della stringa
    if (preg_match('/ALLERGIE\s*:\s*(.+)$/iu', $description, $m)) {
        $allergieNote = trim($m[1]);          // parte dopo i due punti
        $pos = strpos($description, $m[0]);   // posizione dove inizia "ALLERGIE:..."

        if ($pos !== false) {
            $clean = trim(substr($description, 0, $pos)); // tutto prima delle allergie
        }
    }

    return [$clean, $allergieNote];
}

/**
 * Ottiene il valore di una colonna dal CSV, gestendo colonne mancanti.
 * Se la colonna non esiste, restituisce null senza errori.
 */
function getColumnValue(array $row, array $index, string $columnName): ?string
{
    if (!isset($index[$columnName])) {
        return null;
    }
    return $row[$index[$columnName]] ?? null;
}

// -----------------------------------------------------------------------------
// 5) PREPARAZIONE QUERY
// -----------------------------------------------------------------------------

$insertDettagli = $pdo->prepare(<<<SQL
INSERT INTO gsr_glovo_dettagli (
    csv_filename,
    notification_partner_time,
    description,
    allergie_note,
    store_name,
    store_address,
    payment_method,
    price_of_products,
    product_promotion_paid_by_partner,
    flash_offer_promotion_paid_by_partner,
    charged_to_partner_base,
    glovo_platform_fee,
    total_charged_to_partner,
    total_charged_to_partner_percentage,
    delivery_promotion_paid_by_partner,
    refunds_incidents,
    products_paid_in_cash,
    delivery_price_paid_in_cash,
    meal_vouchers_discounts,
    incidents_to_pay_partner,
    product_with_incidents,
    incidents_glovo_platform_fee,
    wait_time_fee,
    wait_time_fee_refund,
    prime_order_vendor_fee,
    flash_deals_fee
) VALUES (
    :csv_filename,
    :notification_partner_time,
    :description,
    :allergie_note,
    :store_name,
    :store_address,
    :payment_method,
    :price_of_products,
    :product_promotion_paid_by_partner,
    :flash_offer_promotion_paid_by_partner,
    :charged_to_partner_base,
    :glovo_platform_fee,
    :total_charged_to_partner,
    :total_charged_to_partner_percentage,
    :delivery_promotion_paid_by_partner,
    :refunds_incidents,
    :products_paid_in_cash,
    :delivery_price_paid_in_cash,
    :meal_vouchers_discounts,
    :incidents_to_pay_partner,
    :product_with_incidents,
    :incidents_glovo_platform_fee,
    :wait_time_fee,
    :wait_time_fee_refund,
    :prime_order_vendor_fee,
    :flash_deals_fee
)
SQL);

$insertItem = $pdo->prepare(<<<SQL
INSERT INTO gsr_glovo_dettagli_items (
    dettaglio_id,
    product_name,
    quantity
) VALUES (
    :dettaglio_id,
    :product_name,
    :quantity
)
SQL);

// -----------------------------------------------------------------------------
// 6) IMPORTA TUTTI I CSV
// -----------------------------------------------------------------------------

// Colonne obbligatorie: solo quelle usate nell'UNIQUE KEY per identificare l'ordine
$requiredCols = [
    'Glovo Code',
    'Notification Partner Time',
    'Description',
    'Store Name',
];

// Tutte le altre colonne sono opzionali (possono essere NULL):
// - Store Address
// - Payment Method
// - Price of Products
// - Product Promotion Paid by Partner
// - Flash Offer Promotion Paid by Partner
// - Charged to Partner Base
// - Glovo platform fee
// - Total Charged to Partner
// - Total Charged to Partner Percentage
// - Delivery promotion paid by partner
// - Refunds (Incidents)
// - Products paid in cash
// - Delivery Price paid in cash
// - Meal vouchers discounts
// - Incidents to pay partner
// - Product with Incidents
// - Incidents Glovo Platform Fee
// - Wait Time Fee
// - Wait Time Fee Refund
// - Prime Order Vendor Fee
// - Flash Deals Fee
// - Child Store Address Id

foreach ($csvFiles as $csvFile) {

    echo "\n---\nImport CSV: " . basename($csvFile) . "\n";

    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        echo "Impossibile aprire: " . basename($csvFile) . "\n";
        continue;
    }

    $header = fgetcsv($handle, 0, ',', '"');
    if (!$header) {
        echo "Header non valido in: " . basename($csvFile) . "\n";
        fclose($handle);
        continue;
    }

    if (!empty($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }

    $index = [];
    foreach ($header as $i => $colName) {
        $index[trim($colName)] = $i;
    }

    foreach ($requiredCols as $col) {
        if (!array_key_exists($col, $index)) {
            echo "Colonna mancante nel CSV (" . basename($csvFile) . "): {$col}\n";
            fclose($handle);
            continue 2;
        }
    }

    $pdo->beginTransaction();
    $rows = 0;
    $skipped = 0;

    while ($row = fgetcsv($handle, 0, ',', '"')) {

        if (count($row) <= 1 && trim(implode('', $row)) === '') {
            continue;
        }

        $descriptionRaw = getColumnValue($row, $index, 'Description') ?? '';

        // 👇 NUOVO: separo la parte ALLERGIE dalla descrizione vera dei prodotti
        list($descriptionClean, $allergieNote) = splitDescriptionAndAllergie($descriptionRaw);

        try {
            $csvFileName = basename($csvFile);
            echo "DEBUG: Importo da file: {$csvFileName}\n";

            $insertDettagli->execute([
                ':csv_filename'                        => $csvFileName,
                ':notification_partner_time'           => convertNotificationTime(getColumnValue($row, $index, 'Notification Partner Time')),
                ':description'                         => $descriptionClean,   // SOLO parte prodotti
                ':allergie_note'                       => $allergieNote,       // SOLO ALLERGIE
                ':store_name'                          => getColumnValue($row, $index, 'Store Name'),
                ':store_address'                       => getColumnValue($row, $index, 'Store Address'),
                ':payment_method'                      => getColumnValue($row, $index, 'Payment Method'),
                ':price_of_products'                   => csvToDecimalOrNull(getColumnValue($row, $index, 'Price of Products')),
                ':product_promotion_paid_by_partner'   => csvToDecimalOrNull(getColumnValue($row, $index, 'Product Promotion Paid by Partner')),
                ':flash_offer_promotion_paid_by_partner'=> csvToDecimalOrNull(getColumnValue($row, $index, 'Flash Offer Promotion Paid by Partner')),
                ':charged_to_partner_base'             => csvToDecimalOrNull(getColumnValue($row, $index, 'Charged to Partner Base')),
                ':glovo_platform_fee'                  => csvToDecimalOrNull(getColumnValue($row, $index, 'Glovo platform fee')),
                ':total_charged_to_partner'            => csvToDecimalOrNull(getColumnValue($row, $index, 'Total Charged to Partner')),
                ':total_charged_to_partner_percentage' => csvToDecimalOrNull(getColumnValue($row, $index, 'Total Charged to Partner Percentage')),
                ':delivery_promotion_paid_by_partner'  => csvToDecimalOrNull(getColumnValue($row, $index, 'Delivery promotion paid by partner')),
                ':refunds_incidents'                   => csvToDecimalOrNull(getColumnValue($row, $index, 'Refunds (Incidents)')),
                ':products_paid_in_cash'               => csvToDecimalOrNull(getColumnValue($row, $index, 'Products paid in cash')),
                ':delivery_price_paid_in_cash'         => csvToDecimalOrNull(getColumnValue($row, $index, 'Delivery Price paid in cash')),
                ':meal_vouchers_discounts'             => csvToDecimalOrNull(getColumnValue($row, $index, 'Meal vouchers discounts')),
                ':incidents_to_pay_partner'            => csvToDecimalOrNull(getColumnValue($row, $index, 'Incidents to pay partner')),
                ':product_with_incidents'              => csvToDecimalOrNull(getColumnValue($row, $index, 'Product with Incidents')),
                ':incidents_glovo_platform_fee'        => csvToDecimalOrNull(getColumnValue($row, $index, 'Incidents Glovo Platform Fee')),
                ':wait_time_fee'                       => csvToDecimalOrNull(getColumnValue($row, $index, 'Wait Time Fee')),
                ':wait_time_fee_refund'                => csvToDecimalOrNull(getColumnValue($row, $index, 'Wait Time Fee Refund')),
                ':prime_order_vendor_fee'              => csvToDecimalOrNull(getColumnValue($row, $index, 'Prime Order Vendor Fee')),
                ':flash_deals_fee'                     => csvToDecimalOrNull(getColumnValue($row, $index, 'Flash Deals Fee')),
            ]);

            $dettaglioId = $pdo->lastInsertId();

            // 👇 Anche il parsing prodotti ora usa SOLO la parte "pulita"
            $items = parseDescription($descriptionClean);

            foreach ($items as $item) {
                $insertItem->execute([
                    ':dettaglio_id' => $dettaglioId,
                    ':product_name' => $item['product_name'],
                    ':quantity'     => $item['quantity'],
                ]);
            }

            $rows++;

        } catch (PDOException $e) {
            // 1062 = duplicate entry (UNIQUE constraint)
            if ($e->getCode() == 23000) {
                $skipped++;
                continue;
            } else {
                // Altro errore, rollback e rilancia
                $pdo->rollBack();
                throw $e;
            }
        }
    }

    $pdo->commit();
    fclose($handle);

    echo "Importate {$rows} righe da " . basename($csvFile);
    if ($skipped > 0) {
        echo " ({$skipped} duplicati saltati)";
    }
    echo "\n";

    // SPOSTA CSV IN processed
    $dest = $processedDir . '/' . basename($csvFile);
    if (!rename($csvFile, $dest)) {
        echo "  ATTENZIONE: impossibile spostare il file.\n";
    } else {
        echo "  Spostato in processed.\n";
    }
}

echo "\n\n✔ IMPORT COMPLETATO ✔\n";

