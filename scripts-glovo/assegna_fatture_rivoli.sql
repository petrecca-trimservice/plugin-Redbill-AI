-- ========================================================
-- SCRIPT SQL PER ASSEGNARE FATTURE AL NEGOZIO
-- Girarrosti Santa Rita - Rivoli
-- ========================================================
-- Assegna le fatture che contengono "68I4P69"
-- nel numero fattura E che hanno come negozio attuale
-- "VIA PIETRO MICCA, 20" al negozio di Rivoli
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
    'Girarrosti Santa Rita - Rivoli' AS negozio_nuovo,
    destinatario,
    data,
    importo_bonifico
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%68I4P69%'
    AND negozio = 'VIA PIETRO MICCA, 20'
ORDER BY data DESC;

-- ========================================================
-- STEP 2: Conta quante fatture verranno aggiornate
-- ========================================================

SELECT COUNT(*) AS totale_fatture_da_aggiornare
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%68I4P69%'
    AND negozio = 'VIA PIETRO MICCA, 20';

-- ========================================================
-- STEP 3: AGGIORNA IL NEGOZIO
-- ========================================================
-- ⚠️ ATTENZIONE: Questa query MODIFICA i dati!
-- Esegui solo dopo aver verificato con STEP 1 e STEP 2
-- ========================================================

UPDATE gsr_glovo_fatture
SET negozio = 'Girarrosti Santa Rita - Rivoli'
WHERE n_fattura LIKE '%68I4P69%'
    AND negozio = 'VIA PIETRO MICCA, 20';

-- ========================================================
-- STEP 4: Verifica fatture con 68I4P69 ma negozio diverso
-- ========================================================
-- Controlla se ci sono fatture con "68I4P69" che NON hanno
-- "VIA PIETRO MICCA, 20" come negozio (non verranno modificate)

SELECT
    id,
    file_pdf,
    n_fattura,
    negozio,
    destinatario,
    data,
    importo_bonifico
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%68I4P69%'
    AND negozio != 'VIA PIETRO MICCA, 20'
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
WHERE n_fattura LIKE '%68I4P69%'
    AND negozio = 'Girarrosti Santa Rita - Rivoli'
ORDER BY data DESC;

-- ========================================================
-- STEP 6: Statistiche finali
-- ========================================================

SELECT
    'Fatture con 68I4P69 assegnate a Rivoli' AS descrizione,
    COUNT(*) AS totale
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%68I4P69%'
    AND negozio = 'Girarrosti Santa Rita - Rivoli';

-- ========================================================
-- FINE
-- ========================================================
-- Dopo l'esecuzione:
-- - Solo le fatture con "68I4P69" nel numero fattura
--   E che avevano "VIA PIETRO MICCA, 20" come negozio
--   saranno assegnate al negozio di Rivoli
-- ========================================================
