-- Mostra tutti i valori di importo_bonifico che iniziano con il segno meno
SELECT
    id,
    n_fattura,
    data,
    importo_bonifico,
    LENGTH(importo_bonifico) AS lunghezza,
    ASCII(SUBSTRING(importo_bonifico, 1, 1)) AS primo_carattere_ascii
FROM gsr_glovo_fatture
WHERE importo_bonifico LIKE '-%'
ORDER BY data DESC
LIMIT 20;

-- Conta record con importo_bonifico che inizia con '-'
SELECT COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE importo_bonifico LIKE '-%';
