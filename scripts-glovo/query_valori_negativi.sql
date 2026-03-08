-- Query per verificare record con valori negativi
-- Esegui in phpMyAdmin sul database dash_glovo

-- 1. TUTTI I RECORD CON ALMENO UN VALORE NEGATIVO
SELECT
    id,
    file_pdf,
    n_fattura,
    data,
    CASE
        WHEN CAST(totale_fattura_riepilogo AS DECIMAL(10,2)) < 0 THEN CONCAT('totale_fattura_riepilogo: ', totale_fattura_riepilogo)
        WHEN CAST(promo_prodotti_partner AS DECIMAL(10,2)) < 0 THEN CONCAT('promo_prodotti_partner: ', promo_prodotti_partner)
        WHEN CAST(costo_incidenti_prodotti AS DECIMAL(10,2)) < 0 THEN CONCAT('costo_incidenti_prodotti: ', costo_incidenti_prodotti)
        WHEN CAST(tariffa_tempo_attesa AS DECIMAL(10,2)) < 0 THEN CONCAT('tariffa_tempo_attesa: ', tariffa_tempo_attesa)
        WHEN CAST(costo_annullamenti_servizio AS DECIMAL(10,2)) < 0 THEN CONCAT('costo_annullamenti_servizio: ', costo_annullamenti_servizio)
        WHEN CAST(consegna_gratuita_incidente AS DECIMAL(10,2)) < 0 THEN CONCAT('consegna_gratuita_incidente: ', consegna_gratuita_incidente)
        WHEN CAST(buoni_pasto AS DECIMAL(10,2)) < 0 THEN CONCAT('buoni_pasto: ', buoni_pasto)
        WHEN CAST(supplemento_ordine_glovo_prime AS DECIMAL(10,2)) < 0 THEN CONCAT('supplemento_ordine_glovo_prime: ', supplemento_ordine_glovo_prime)
        WHEN CAST(glovo_gia_pagati AS DECIMAL(10,2)) < 0 THEN CONCAT('glovo_gia_pagati: ', glovo_gia_pagati)
        WHEN CAST(debito_accumulato AS DECIMAL(10,2)) < 0 THEN CONCAT('debito_accumulato: ', debito_accumulato)
        WHEN CAST(importo_bonifico AS DECIMAL(10,2)) < 0 THEN CONCAT('importo_bonifico: ', importo_bonifico)
    END AS campo_negativo
FROM gsr_glovo_fatture
WHERE
    (totale_fattura_riepilogo IS NOT NULL AND CAST(totale_fattura_riepilogo AS DECIMAL(10,2)) < 0)
    OR (promo_prodotti_partner IS NOT NULL AND CAST(promo_prodotti_partner AS DECIMAL(10,2)) < 0)
    OR (costo_incidenti_prodotti IS NOT NULL AND CAST(costo_incidenti_prodotti AS DECIMAL(10,2)) < 0)
    OR (tariffa_tempo_attesa IS NOT NULL AND CAST(tariffa_tempo_attesa AS DECIMAL(10,2)) < 0)
    OR (costo_annullamenti_servizio IS NOT NULL AND CAST(costo_annullamenti_servizio AS DECIMAL(10,2)) < 0)
    OR (consegna_gratuita_incidente IS NOT NULL AND CAST(consegna_gratuita_incidente AS DECIMAL(10,2)) < 0)
    OR (buoni_pasto IS NOT NULL AND CAST(buoni_pasto AS DECIMAL(10,2)) < 0)
    OR (supplemento_ordine_glovo_prime IS NOT NULL AND CAST(supplemento_ordine_glovo_prime AS DECIMAL(10,2)) < 0)
    OR (glovo_gia_pagati IS NOT NULL AND CAST(glovo_gia_pagati AS DECIMAL(10,2)) < 0)
    OR (debito_accumulato IS NOT NULL AND CAST(debito_accumulato AS DECIMAL(10,2)) < 0)
    OR (importo_bonifico IS NOT NULL AND CAST(importo_bonifico AS DECIMAL(10,2)) < 0)
ORDER BY data DESC;


-- 2. CONTEGGIO VALORI NEGATIVI PER CAMPO
SELECT
    'totale_fattura_riepilogo' AS campo,
    COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE totale_fattura_riepilogo IS NOT NULL AND CAST(totale_fattura_riepilogo AS DECIMAL(10,2)) < 0

UNION ALL

SELECT
    'promo_prodotti_partner' AS campo,
    COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE promo_prodotti_partner IS NOT NULL AND CAST(promo_prodotti_partner AS DECIMAL(10,2)) < 0

UNION ALL

SELECT
    'costo_incidenti_prodotti' AS campo,
    COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE costo_incidenti_prodotti IS NOT NULL AND CAST(costo_incidenti_prodotti AS DECIMAL(10,2)) < 0

UNION ALL

SELECT
    'tariffa_tempo_attesa' AS campo,
    COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE tariffa_tempo_attesa IS NOT NULL AND CAST(tariffa_tempo_attesa AS DECIMAL(10,2)) < 0

UNION ALL

SELECT
    'costo_annullamenti_servizio' AS campo,
    COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE costo_annullamenti_servizio IS NOT NULL AND CAST(costo_annullamenti_servizio AS DECIMAL(10,2)) < 0

UNION ALL

SELECT
    'consegna_gratuita_incidente' AS campo,
    COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE consegna_gratuita_incidente IS NOT NULL AND CAST(consegna_gratuita_incidente AS DECIMAL(10,2)) < 0

UNION ALL

SELECT
    'buoni_pasto' AS campo,
    COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE buoni_pasto IS NOT NULL AND CAST(buoni_pasto AS DECIMAL(10,2)) < 0

UNION ALL

SELECT
    'supplemento_ordine_glovo_prime' AS campo,
    COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE supplemento_ordine_glovo_prime IS NOT NULL AND CAST(supplemento_ordine_glovo_prime AS DECIMAL(10,2)) < 0

UNION ALL

SELECT
    'glovo_gia_pagati' AS campo,
    COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE glovo_gia_pagati IS NOT NULL AND CAST(glovo_gia_pagati AS DECIMAL(10,2)) < 0

UNION ALL

SELECT
    'debito_accumulato' AS campo,
    COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE debito_accumulato IS NOT NULL AND CAST(debito_accumulato AS DECIMAL(10,2)) < 0

UNION ALL

SELECT
    'importo_bonifico' AS campo,
    COUNT(*) AS totale_negativi
FROM gsr_glovo_fatture
WHERE importo_bonifico IS NOT NULL AND CAST(importo_bonifico AS DECIMAL(10,2)) < 0

ORDER BY totale_negativi DESC;


-- 3. DETTAGLIO COMPLETO IMPORTO_BONIFICO NEGATIVI (campo più critico)
SELECT
    id,
    file_pdf,
    n_fattura,
    data,
    destinatario,
    importo_bonifico,
    totale_fattura_riepilogo
FROM gsr_glovo_fatture
WHERE importo_bonifico IS NOT NULL
  AND CAST(importo_bonifico AS DECIMAL(10,2)) < 0
ORDER BY data DESC;


-- 4. STATISTICHE GENERALI
SELECT
    COUNT(*) AS totale_record,
    SUM(CASE WHEN importo_bonifico IS NOT NULL AND CAST(importo_bonifico AS DECIMAL(10,2)) < 0 THEN 1 ELSE 0 END) AS importo_bonifico_negativo,
    SUM(CASE WHEN importo_bonifico IS NULL THEN 1 ELSE 0 END) AS importo_bonifico_null,
    SUM(CASE WHEN importo_bonifico IS NOT NULL AND CAST(importo_bonifico AS DECIMAL(10,2)) > 0 THEN 1 ELSE 0 END) AS importo_bonifico_positivo,
    SUM(CASE WHEN importo_bonifico IS NOT NULL AND CAST(importo_bonifico AS DECIMAL(10,2)) = 0 THEN 1 ELSE 0 END) AS importo_bonifico_zero
FROM gsr_glovo_fatture;
