-- Conta record con importo_bonifico NULL
SELECT COUNT(*) AS totale_null
FROM gsr_glovo_fatture
WHERE importo_bonifico IS NULL;

-- Mostra le fatture più recenti con importo_bonifico NULL
SELECT
    id,
    n_fattura,
    data,
    file_pdf,
    importo_bonifico
FROM gsr_glovo_fatture
WHERE importo_bonifico IS NULL
ORDER BY data DESC
LIMIT 20;

-- Statistiche generali importo_bonifico
SELECT
    COUNT(*) AS totale_fatture,
    SUM(CASE WHEN importo_bonifico IS NULL THEN 1 ELSE 0 END) AS null_count,
    SUM(CASE WHEN importo_bonifico IS NOT NULL THEN 1 ELSE 0 END) AS not_null_count,
    ROUND(SUM(CASE WHEN importo_bonifico IS NULL THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) AS percentuale_null
FROM gsr_glovo_fatture;
