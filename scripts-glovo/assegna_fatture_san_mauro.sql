-- ========================================================
-- SCRIPT SQL PER ASSEGNARE FATTURE AL NEGOZIO
-- Girarrosti Santa Rita - San Mauro
-- ========================================================
-- Assegna le fatture che contengono "ZXXSM2I"
-- nel numero fattura E che hanno come negozio attuale
-- "Viale Coni Zugna, 43" al negozio di San Mauro
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
    'Girarrosti Santa Rita - San Mauro' AS negozio_nuovo,
    destinatario,
    data,
    importo_bonifico
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%ZXXSM2I%'
    AND negozio = 'Viale Coni Zugna, 43'
ORDER BY data DESC;

-- ========================================================
-- STEP 2: Conta quante fatture verranno aggiornate
-- ========================================================

SELECT COUNT(*) AS totale_fatture_da_aggiornare
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%ZXXSM2I%'
    AND negozio = 'Viale Coni Zugna, 43';

-- ========================================================
-- STEP 3: AGGIORNA IL NEGOZIO
-- ========================================================
-- ⚠️ ATTENZIONE: Questa query MODIFICA i dati!
-- Esegui solo dopo aver verificato con STEP 1 e STEP 2
-- ========================================================

UPDATE gsr_glovo_fatture
SET negozio = 'Girarrosti Santa Rita - San Mauro'
WHERE n_fattura LIKE '%ZXXSM2I%'
    AND negozio = 'Viale Coni Zugna, 43';

-- ========================================================
-- STEP 4: Verifica fatture con ZXXSM2I ma negozio diverso
-- ========================================================
-- Controlla se ci sono fatture con "ZXXSM2I" che NON hanno
-- "Viale Coni Zugna, 43" come negozio (non verranno modificate)

SELECT
    id,
    file_pdf,
    n_fattura,
    negozio,
    destinatario,
    data,
    importo_bonifico
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%ZXXSM2I%'
    AND negozio != 'Viale Coni Zugna, 43'
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
WHERE n_fattura LIKE '%ZXXSM2I%'
    AND negozio = 'Girarrosti Santa Rita - San Mauro'
ORDER BY data DESC;

-- ========================================================
-- STEP 6: Statistiche finali
-- ========================================================

SELECT
    'Fatture con ZXXSM2I assegnate a San Mauro' AS descrizione,
    COUNT(*) AS totale
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%ZXXSM2I%'
    AND negozio = 'Girarrosti Santa Rita - San Mauro';

-- ========================================================
-- FINE
-- ========================================================
-- Dopo l'esecuzione:
-- - Solo le fatture con "ZXXSM2I" nel numero fattura
--   E che avevano "Viale Coni Zugna, 43" come negozio
--   saranno assegnate al negozio di San Mauro
-- ========================================================
