-- Conta record con importo_bonifico negativo
SELECT COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE importo_bonifico IS NOT NULL
  AND CAST(importo_bonifico AS DECIMAL(10,2)) < 0;
