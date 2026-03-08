-- Script per cancellare dal DB le fatture con importo_bonifico NULL
-- Da eseguire SOLO dopo aver spostato i PDF da processed/ a pdf/

-- 1. PRIMA: Mostra quali fatture verranno cancellate (CONTROLLO)
SELECT
    id,
    n_fattura,
    data,
    file_pdf,
    importo_bonifico
FROM gsr_glovo_fatture
WHERE importo_bonifico IS NULL
ORDER BY data DESC;

-- 2. Conta quante sono
SELECT COUNT(*) AS fatture_da_cancellare
FROM gsr_glovo_fatture
WHERE importo_bonifico IS NULL;

-- 3. DOPO IL CONTROLLO: Cancella le fatture con importo_bonifico NULL
-- ATTENZIONE: Decommenta questa riga solo dopo aver verificato i risultati sopra!
-- DELETE FROM gsr_glovo_fatture WHERE importo_bonifico IS NULL;
