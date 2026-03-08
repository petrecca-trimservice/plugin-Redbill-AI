-- ========================================================
-- QUERY SQL PER ANALIZZARE CAMPI FATTURE GLOVO
-- ========================================================
-- Esegui queste query in phpMyAdmin o MySQL per vedere
-- quali campi sono sempre presenti nelle fatture.
-- ========================================================

-- 1. Conta totale fatture
SELECT COUNT(*) as totale_fatture
FROM gsr_glovo_fatture;

-- ========================================================
-- 2. ANALISI DETTAGLIATA PER OGNI CAMPO
-- ========================================================
-- Questa query mostra per ogni campo:
-- - Quante fatture lo hanno valorizzato (NON NULL e NON vuoto)
-- - Quante fatture NON lo hanno
-- - Percentuale di presenza
-- ========================================================

SELECT
    'destinatario' AS campo,
    SUM(CASE WHEN destinatario IS NOT NULL AND destinatario != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN destinatario IS NULL OR destinatario = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN destinatario IS NOT NULL AND destinatario != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'negozio' AS campo,
    SUM(CASE WHEN negozio IS NOT NULL AND negozio != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN negozio IS NULL OR negozio = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN negozio IS NOT NULL AND negozio != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'n_fattura' AS campo,
    SUM(CASE WHEN n_fattura IS NOT NULL AND n_fattura != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN n_fattura IS NULL OR n_fattura = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN n_fattura IS NOT NULL AND n_fattura != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'data' AS campo,
    SUM(CASE WHEN data IS NOT NULL AND data != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN data IS NULL OR data = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN data IS NOT NULL AND data != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'periodo_da' AS campo,
    SUM(CASE WHEN periodo_da IS NOT NULL AND periodo_da != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN periodo_da IS NULL OR periodo_da = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN periodo_da IS NOT NULL AND periodo_da != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'periodo_a' AS campo,
    SUM(CASE WHEN periodo_a IS NOT NULL AND periodo_a != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN periodo_a IS NULL OR periodo_a = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN periodo_a IS NOT NULL AND periodo_a != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'commissioni' AS campo,
    SUM(CASE WHEN commissioni IS NOT NULL AND commissioni != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN commissioni IS NULL OR commissioni = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN commissioni IS NOT NULL AND commissioni != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'marketing_visibilita' AS campo,
    SUM(CASE WHEN marketing_visibilita IS NOT NULL AND marketing_visibilita != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN marketing_visibilita IS NULL OR marketing_visibilita = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN marketing_visibilita IS NOT NULL AND marketing_visibilita != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'subtotale' AS campo,
    SUM(CASE WHEN subtotale IS NOT NULL AND subtotale != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN subtotale IS NULL OR subtotale = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN subtotale IS NOT NULL AND subtotale != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'iva_22' AS campo,
    SUM(CASE WHEN iva_22 IS NOT NULL AND iva_22 != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN iva_22 IS NULL OR iva_22 = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN iva_22 IS NOT NULL AND iva_22 != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'totale_fattura_iva_inclusa' AS campo,
    SUM(CASE WHEN totale_fattura_iva_inclusa IS NOT NULL AND totale_fattura_iva_inclusa != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN totale_fattura_iva_inclusa IS NULL OR totale_fattura_iva_inclusa = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN totale_fattura_iva_inclusa IS NOT NULL AND totale_fattura_iva_inclusa != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'prodotti' AS campo,
    SUM(CASE WHEN prodotti IS NOT NULL AND prodotti != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN prodotti IS NULL OR prodotti = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN prodotti IS NOT NULL AND prodotti != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'servizio_consegna' AS campo,
    SUM(CASE WHEN servizio_consegna IS NOT NULL AND servizio_consegna != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN servizio_consegna IS NULL OR servizio_consegna = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN servizio_consegna IS NOT NULL AND servizio_consegna != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'totale_fattura_riepilogo' AS campo,
    SUM(CASE WHEN totale_fattura_riepilogo IS NOT NULL AND totale_fattura_riepilogo != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN totale_fattura_riepilogo IS NULL OR totale_fattura_riepilogo = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN totale_fattura_riepilogo IS NOT NULL AND totale_fattura_riepilogo != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'promo_prodotti_partner' AS campo,
    SUM(CASE WHEN promo_prodotti_partner IS NOT NULL AND promo_prodotti_partner != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN promo_prodotti_partner IS NULL OR promo_prodotti_partner = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN promo_prodotti_partner IS NOT NULL AND promo_prodotti_partner != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'costo_incidenti_prodotti' AS campo,
    SUM(CASE WHEN costo_incidenti_prodotti IS NOT NULL AND costo_incidenti_prodotti != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN costo_incidenti_prodotti IS NULL OR costo_incidenti_prodotti = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN costo_incidenti_prodotti IS NOT NULL AND costo_incidenti_prodotti != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'tariffa_tempo_attesa' AS campo,
    SUM(CASE WHEN tariffa_tempo_attesa IS NOT NULL AND tariffa_tempo_attesa != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN tariffa_tempo_attesa IS NULL OR tariffa_tempo_attesa = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN tariffa_tempo_attesa IS NOT NULL AND tariffa_tempo_attesa != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'rimborsi_partner_senza_comm' AS campo,
    SUM(CASE WHEN rimborsi_partner_senza_comm IS NOT NULL AND rimborsi_partner_senza_comm != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN rimborsi_partner_senza_comm IS NULL OR rimborsi_partner_senza_comm = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN rimborsi_partner_senza_comm IS NOT NULL AND rimborsi_partner_senza_comm != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'costo_annullamenti_servizio' AS campo,
    SUM(CASE WHEN costo_annullamenti_servizio IS NOT NULL AND costo_annullamenti_servizio != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN costo_annullamenti_servizio IS NULL OR costo_annullamenti_servizio = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN costo_annullamenti_servizio IS NOT NULL AND costo_annullamenti_servizio != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'consegna_gratuita_incidente' AS campo,
    SUM(CASE WHEN consegna_gratuita_incidente IS NOT NULL AND consegna_gratuita_incidente != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN consegna_gratuita_incidente IS NULL OR consegna_gratuita_incidente = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN consegna_gratuita_incidente IS NOT NULL AND consegna_gratuita_incidente != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'buoni_pasto' AS campo,
    SUM(CASE WHEN buoni_pasto IS NOT NULL AND buoni_pasto != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN buoni_pasto IS NULL OR buoni_pasto = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN buoni_pasto IS NOT NULL AND buoni_pasto != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'supplemento_ordine_glovo_prime' AS campo,
    SUM(CASE WHEN supplemento_ordine_glovo_prime IS NOT NULL AND supplemento_ordine_glovo_prime != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN supplemento_ordine_glovo_prime IS NULL OR supplemento_ordine_glovo_prime = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN supplemento_ordine_glovo_prime IS NOT NULL AND supplemento_ordine_glovo_prime != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'glovo_gia_pagati' AS campo,
    SUM(CASE WHEN glovo_gia_pagati IS NOT NULL AND glovo_gia_pagati != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN glovo_gia_pagati IS NULL OR glovo_gia_pagati = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN glovo_gia_pagati IS NOT NULL AND glovo_gia_pagati != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'debito_accumulato' AS campo,
    SUM(CASE WHEN debito_accumulato IS NOT NULL AND debito_accumulato != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN debito_accumulato IS NULL OR debito_accumulato = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN debito_accumulato IS NOT NULL AND debito_accumulato != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
UNION ALL
SELECT
    'importo_bonifico' AS campo,
    SUM(CASE WHEN importo_bonifico IS NOT NULL AND importo_bonifico != '' THEN 1 ELSE 0 END) AS presenti,
    SUM(CASE WHEN importo_bonifico IS NULL OR importo_bonifico = '' THEN 1 ELSE 0 END) AS mancanti,
    ROUND(SUM(CASE WHEN importo_bonifico IS NOT NULL AND importo_bonifico != '' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS percentuale_presenti
FROM gsr_glovo_fatture
ORDER BY percentuale_presenti DESC;

-- ========================================================
-- 3. QUERY SEMPLIFICATA (se la precedente è troppo lunga)
-- ========================================================
-- Esegui questa query per vedere rapidamente quali campi
-- hanno valori NULL o vuoti
-- ========================================================

SELECT
    SUM(CASE WHEN destinatario IS NULL OR destinatario = '' THEN 1 ELSE 0 END) AS dest_mancanti,
    SUM(CASE WHEN n_fattura IS NULL OR n_fattura = '' THEN 1 ELSE 0 END) AS nfatt_mancanti,
    SUM(CASE WHEN data IS NULL OR data = '' THEN 1 ELSE 0 END) AS data_mancanti,
    SUM(CASE WHEN commissioni IS NULL OR commissioni = '' THEN 1 ELSE 0 END) AS comm_mancanti,
    SUM(CASE WHEN subtotale IS NULL OR subtotale = '' THEN 1 ELSE 0 END) AS subt_mancanti,
    SUM(CASE WHEN iva_22 IS NULL OR iva_22 = '' THEN 1 ELSE 0 END) AS iva_mancanti,
    SUM(CASE WHEN totale_fattura_iva_inclusa IS NULL OR totale_fattura_iva_inclusa = '' THEN 1 ELSE 0 END) AS tot_mancanti,
    SUM(CASE WHEN importo_bonifico IS NULL OR importo_bonifico = '' THEN 1 ELSE 0 END) AS imp_mancanti
FROM gsr_glovo_fatture;

-- ========================================================
-- ISTRUZIONI:
-- ========================================================
-- 1. Apri phpMyAdmin
-- 2. Seleziona database 'dash_glovo'
-- 3. Vai nella tab 'SQL'
-- 4. Copia e incolla la query principale (la numero 2)
-- 5. Clicca 'Esegui'
-- 6. Inviami i risultati (screenshot o copia/incolla)
-- ========================================================
