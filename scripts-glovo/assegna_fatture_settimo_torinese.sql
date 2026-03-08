-- ========================================================
-- SCRIPT SQL PER ASSEGNARE FATTURE AL NEGOZIO
-- Girarrosti Santa Rita - Settimo Torinese
-- ========================================================
-- Assegna le fatture che contengono "2XVRHFU"
-- nel numero fattura E che hanno come negozio attuale
-- "Via Pietro Micca, 20" al negozio di Settimo Torinese
-- ========================================================

-- IMPORTANTE: Esegui questo script in phpMyAdmin
-- Database: dash_glovo
-- Tabella: gsr_glovo_fatture

-- ========================================================
-- STEP 1: Verifica quali fatture verranno modificate
-- ========================================================
-- Esegui questa query per vedere quali record verranno modificati
-- (NON modifica nulla, solo visualizza)

SELECT
    id,
    file_pdf,
    n_fattura,
    negozio AS negozio_attuale,
    'Girarrosti Santa Rita - Settimo Torinese' AS negozio_nuovo,
    destinatario,
    data,
    importo_bonifico
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%2XVRHFU%'
    AND negozio = 'Via Pietro Micca, 20'
ORDER BY data DESC;

-- ========================================================
-- STEP 2: Conta quante fatture verranno aggiornate
-- ========================================================

SELECT COUNT(*) AS totale_fatture_da_aggiornare
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%2XVRHFU%'
    AND negozio = 'Via Pietro Micca, 20';

-- ========================================================
-- STEP 3: AGGIORNA IL NEGOZIO
-- ========================================================
-- ⚠️ ATTENZIONE: Questa query MODIFICA i dati!
-- Esegui solo dopo aver verificato con STEP 1 e STEP 2
-- ========================================================

UPDATE gsr_glovo_fatture
SET negozio = 'Girarrosti Santa Rita - Settimo Torinese'
WHERE n_fattura LIKE '%2XVRHFU%'
    AND negozio = 'Via Pietro Micca, 20';

-- ========================================================
-- STEP 4: Verifica fatture con 2XVRHFU ma negozio diverso
-- ========================================================
-- Controlla se ci sono fatture con "2XVRHFU" che NON hanno
-- "Via Pietro Micca, 20" come negozio (non verranno modificate)

SELECT
    id,
    file_pdf,
    n_fattura,
    negozio,
    destinatario,
    data,
    importo_bonifico
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%2XVRHFU%'
    AND negozio != 'Via Pietro Micca, 20'
ORDER BY data DESC;

-- ========================================================
-- STEP 5: Verifica che le modifiche siano corrette
-- ========================================================

SELECT
    id,
    file_pdf,
    n_fattura,
    negozio,
    destinatario,
    data,
    importo_bonifico
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%2XVRHFU%'
    AND negozio = 'Girarrosti Santa Rita - Settimo Torinese'
ORDER BY data DESC;

-- ========================================================
-- STEP 6: Statistiche finali
-- ========================================================

SELECT
    'Fatture con 2XVRHFU assegnate a Settimo Torinese' AS descrizione,
    COUNT(*) AS totale
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%2XVRHFU%'
    AND negozio = 'Girarrosti Santa Rita - Settimo Torinese';

-- ========================================================
-- FINE
-- ========================================================
-- Dopo l'esecuzione:
-- - Solo le fatture con "2XVRHFU" nel numero fattura
--   E che avevano "Via Pietro Micca, 20" come negozio
--   saranno assegnate al negozio di Settimo Torinese
-- ========================================================
