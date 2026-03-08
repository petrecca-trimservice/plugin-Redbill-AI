-- ========================================================
-- SCRIPT SQL PER ASSEGNARE FATTURE AL NEGOZIO
-- Girarrosti Santa Rita - Via Vittorio Alfieri - Orbassano
-- ========================================================
-- Assegna le fatture che contengono "N9P1UXP"
-- nel numero fattura E che hanno come negozio attuale
-- "VIA PIETRO MICCA, 20" al negozio di Orbassano
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
    'Girarrosti Santa Rita - Via Vittorio Alfieri - Orbassano' AS negozio_nuovo,
    destinatario,
    data,
    importo_bonifico
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%N9P1UXP%'
    AND negozio = 'VIA PIETRO MICCA, 20'
ORDER BY data DESC;

-- ========================================================
-- STEP 2: Conta quante fatture verranno aggiornate
-- ========================================================

SELECT COUNT(*) AS totale_fatture_da_aggiornare
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%N9P1UXP%'
    AND negozio = 'VIA PIETRO MICCA, 20';

-- ========================================================
-- STEP 3: AGGIORNA IL NEGOZIO
-- ========================================================
-- ⚠️ ATTENZIONE: Questa query MODIFICA i dati!
-- Esegui solo dopo aver verificato con STEP 1 e STEP 2
-- ========================================================

UPDATE gsr_glovo_fatture
SET negozio = 'Girarrosti Santa Rita - Via Vittorio Alfieri - Orbassano'
WHERE n_fattura LIKE '%N9P1UXP%'
    AND negozio = 'VIA PIETRO MICCA, 20';

-- ========================================================
-- STEP 4: Verifica fatture con N9P1UXP ma negozio diverso
-- ========================================================
-- Controlla se ci sono fatture con "N9P1UXP" che NON hanno
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
WHERE n_fattura LIKE '%N9P1UXP%'
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
WHERE n_fattura LIKE '%N9P1UXP%'
    AND negozio = 'Girarrosti Santa Rita - Via Vittorio Alfieri - Orbassano'
ORDER BY data DESC;

-- ========================================================
-- STEP 6: Statistiche finali
-- ========================================================

SELECT
    'Fatture con N9P1UXP assegnate a Orbassano' AS descrizione,
    COUNT(*) AS totale
FROM gsr_glovo_fatture
WHERE n_fattura LIKE '%N9P1UXP%'
    AND negozio = 'Girarrosti Santa Rita - Via Vittorio Alfieri - Orbassano';

-- ========================================================
-- FINE
-- ========================================================
-- Dopo l'esecuzione:
-- - Solo le fatture con "N9P1UXP" nel numero fattura
--   E che avevano "VIA PIETRO MICCA, 20" come negozio
--   saranno assegnate al negozio di Orbassano
-- ========================================================
