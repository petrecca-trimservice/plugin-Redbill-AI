-- ========================================================
-- SCRIPT SQL PER ASSEGNARE FATTURE AL NEGOZIO
-- Girarrosti Santa Rita - Milano
-- ========================================================
-- Assegna le fatture che contengono "HDCKZB"
-- nel numero fattura E che hanno come negozio attuale
-- "Viale Coni Zugna, 43" al negozio di Milano
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
    'Girarrosti Santa Rita - Milano' AS negozio_nuovo,
    destinatario,
    data,
    importo_bonifico
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%HDCKZB%'
    AND negozio = 'Viale Coni Zugna, 43'
ORDER BY data DESC;

-- ========================================================
-- STEP 2: Conta quante fatture verranno aggiornate
-- ========================================================

SELECT COUNT(*) AS totale_fatture_da_aggiornare
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%HDCKZB%'
    AND negozio = 'Viale Coni Zugna, 43';

-- ========================================================
-- STEP 3: AGGIORNA IL NEGOZIO
-- ========================================================
-- ⚠️ ATTENZIONE: Questa query MODIFICA i dati!
-- Esegui solo dopo aver verificato con STEP 1 e STEP 2
-- ========================================================

UPDATE gsr_glovo_fatture
SET negozio = 'Girarrosti Santa Rita - Milano'
WHERE n_fattura LIKE '%HDCKZB%'
    AND negozio = 'Viale Coni Zugna, 43';

-- ========================================================
-- STEP 4: Verifica fatture con HDCKZB ma negozio diverso
-- ========================================================
-- Controlla se ci sono fatture con "HDCKZB" che NON hanno
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
WHERE n_fattura LIKE '%HDCKZB%'
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
WHERE n_fattura LIKE '%HDCKZB%'
    AND negozio = 'Girarrosti Santa Rita - Milano'
ORDER BY data DESC;

-- ========================================================
-- STEP 6: Statistiche finali
-- ========================================================

SELECT
    'Fatture con HDCKZB assegnate a Milano' AS descrizione,
    COUNT(*) AS totale
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%HDCKZB%'
    AND negozio = 'Girarrosti Santa Rita - Milano';

-- ========================================================
-- FINE
-- ========================================================
-- Dopo l'esecuzione:
-- - Solo le fatture con "HDCKZB" nel numero fattura
--   E che avevano "Viale Coni Zugna, 43" come negozio
--   saranno assegnate al negozio di Milano
-- ========================================================
