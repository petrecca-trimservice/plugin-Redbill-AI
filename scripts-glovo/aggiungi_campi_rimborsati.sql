-- Migrazione: aggiunta 3 nuovi campi per ordini rimborsati e sconto buoni pasto
-- Data: 2026-02-23
--
-- Nuovi campi aggiunti:
--   - ordini_rimborsati_partner        : "+ Ordini rimborsati al partner"           (voce attiva)
--   - commissione_ordini_rimborsati    : "- Commissione Glovo sugli ordini rimborsati al partner" (commissione)
--   - sconto_comm_ordini_buoni_pasto   : "- Sconto commissione ordini buoni pasto"  (sconto commissione)

ALTER TABLE `gsr_glovo_fatture`
    ADD COLUMN `ordini_rimborsati_partner`     DECIMAL(10,2) DEFAULT NULL COMMENT 'Ordini rimborsati al partner (voce attiva)',
    ADD COLUMN `commissione_ordini_rimborsati` DECIMAL(10,2) DEFAULT NULL COMMENT 'Commissione Glovo sugli ordini rimborsati al partner',
    ADD COLUMN `sconto_comm_ordini_buoni_pasto` DECIMAL(10,2) DEFAULT NULL COMMENT 'Sconto commissione ordini buoni pasto';
