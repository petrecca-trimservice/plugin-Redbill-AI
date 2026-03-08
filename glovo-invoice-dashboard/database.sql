-- Glovo Invoice Dashboard - Database Schema
--
-- NOTA: Questo file è solo a scopo di RIFERIMENTO
-- La tabella gsr_glovo_fatture dovrebbe già esistere nel database dash_glovo
-- NON è necessario eseguire questo script

CREATE TABLE IF NOT EXISTS `gsr_glovo_fatture` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_pdf` varchar(255) DEFAULT NULL COMMENT 'Nome file PDF originale',
  `destinatario` varchar(255) DEFAULT NULL COMMENT 'Nome destinatario fattura',
  `negozio` varchar(255) DEFAULT NULL COMMENT 'Nome del negozio',
  `n_fattura` varchar(100) DEFAULT NULL COMMENT 'Numero fattura',
  `data` date DEFAULT NULL COMMENT 'Data emissione fattura',
  `periodo_da` date DEFAULT NULL COMMENT 'Inizio periodo di riferimento',
  `periodo_a` date DEFAULT NULL COMMENT 'Fine periodo di riferimento',
  `commissioni` decimal(10,2) DEFAULT 0.00 COMMENT 'Commissioni Glovo',
  `marketing_visibilita` decimal(10,2) DEFAULT 0.00 COMMENT 'Costi marketing e visibilità',
  `subtotale` decimal(10,2) DEFAULT 0.00 COMMENT 'Subtotale senza IVA',
  `iva_22` decimal(10,2) DEFAULT 0.00 COMMENT 'IVA al 22%',
  `totale_fattura_iva_inclusa` decimal(10,2) DEFAULT 0.00 COMMENT 'Totale fattura con IVA',
  `prodotti` text DEFAULT NULL COMMENT 'Descrizione prodotti',
  `servizio_consegna` decimal(10,2) DEFAULT 0.00 COMMENT 'Costo servizio consegna',
  `totale_fattura_riepilogo` decimal(10,2) DEFAULT 0.00 COMMENT 'Totale riepilogo',
  `promo_prodotti_partner` decimal(10,2) DEFAULT 0.00 COMMENT 'Promozioni prodotti partner',
  `costo_incidenti_prodotti` decimal(10,2) DEFAULT 0.00 COMMENT 'Costi incidenti prodotti',
  `tariffa_tempo_attesa` decimal(10,2) DEFAULT 0.00 COMMENT 'Tariffa tempo attesa',
  `rimborsi_partner_senza_comm` decimal(10,2) DEFAULT 0.00 COMMENT 'Rimborsi partner senza commissione',
  `costo_annullamenti_servizio` decimal(10,2) DEFAULT 0.00 COMMENT 'Costo annullamenti servizio',
  `consegna_gratuita_incidente` decimal(10,2) DEFAULT 0.00 COMMENT 'Consegna gratuita per incidente',
  `buoni_pasto` decimal(10,2) DEFAULT 0.00 COMMENT 'Buoni pasto',
  `supplemento_ordine_glovo_prime` decimal(10,2) DEFAULT 0.00 COMMENT 'Supplemento per ordine con Glovo Prime',
  `promo_consegna_partner` decimal(10,2) DEFAULT 0.00 COMMENT 'Promozione sulla consegna a carico del partner',
  `costi_offerta_lampo` decimal(10,2) DEFAULT 0.00 COMMENT 'Costi per offerta lampo',
  `promo_lampo_partner` decimal(10,2) DEFAULT 0.00 COMMENT 'Promozione lampo a carico del partner',
  `glovo_gia_pagati` decimal(10,2) DEFAULT 0.00 COMMENT 'Importi già pagati da Glovo',
  `debito_accumulato` decimal(10,2) DEFAULT 0.00 COMMENT 'Debito accumulato',
  `importo_bonifico` decimal(10,2) DEFAULT 0.00 COMMENT 'Importo bonifico finale',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data creazione record',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Data ultimo aggiornamento',
  PRIMARY KEY (`id`),
  KEY `idx_destinatario` (`destinatario`),
  KEY `idx_negozio` (`negozio`),
  KEY `idx_data` (`data`),
  KEY `idx_periodo` (`periodo_da`, `periodo_a`),
  KEY `idx_n_fattura` (`n_fattura`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabella fatture Glovo';

-- Esempio di inserimento dati
-- Decommentare e modificare per inserire dati di test

/*
INSERT INTO `gsr_glovo_fatture` (
    `file_pdf`,
    `destinatario`,
    `negozio`,
    `n_fattura`,
    `data`,
    `periodo_da`,
    `periodo_a`,
    `commissioni`,
    `marketing_visibilita`,
    `subtotale`,
    `iva_22`,
    `totale_fattura_iva_inclusa`,
    `prodotti`,
    `servizio_consegna`,
    `totale_fattura_riepilogo`,
    `promo_prodotti_partner`,
    `costo_incidenti_prodotti`,
    `tariffa_tempo_attesa`,
    `rimborsi_partner_senza_comm`,
    `costo_annullamenti_servizio`,
    `consegna_gratuita_incidente`,
    `buoni_pasto`,
    `supplemento_ordine_glovo_prime`,
    `promo_consegna_partner`,
    `costi_offerta_lampo`,
    `promo_lampo_partner`,
    `glovo_gia_pagati`,
    `debito_accumulato`,
    `importo_bonifico`
) VALUES (
    'fattura_esempio_2024_001.pdf',
    'Ristorante Test',
    'Sede Centrale',
    'FAT-2024-001',
    '2024-01-15',
    '2024-01-01',
    '2024-01-14',
    150.00,
    50.00,
    800.00,
    176.00,
    976.00,
    'Pizza, Pasta, Bevande',
    100.00,
    976.00,
    20.00,
    15.00,
    10.00,
    25.00,
    5.00,
    0.00,
    30.00,
    0.00,
    0.00,
    0.00,
    50.00,
    0.00,
    876.00
);
*/

-- Note:
-- 1. Questo schema è fornito solo come RIFERIMENTO della struttura tabella
-- 2. La tabella gsr_glovo_fatture dovrebbe già esistere nel database dash_glovo
-- 3. Gli indici sono ottimizzati per le query di filtro più comuni
-- 4. I campi decimal(10,2) supportano importi fino a 99.999.999,99
-- 5. I campi created_at e updated_at sono utili per tracciare le modifiche
