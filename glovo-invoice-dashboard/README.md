# Glovo Invoice Dashboard Plugin

Plugin WordPress per visualizzare e analizzare le fatture Glovo con filtri avanzati, KPI e dashboard interattiva.

## 🚀 Caratteristiche

### Tabella Fatture
- Visualizzazione completa di tutte le fatture in formato tabella
- Filtri per destinatario, negozio, data fattura e periodo
- Esportazione dati in formato CSV
- Visualizzazione dettagli fattura in modale
- Design responsive

### Dashboard KPI
- KPI cards con metriche principali
- Indicatori dettagliati per ricavi, costi e fiscale
- Grafici interattivi (fatturato per mese e per negozio)
- Analisi percentuale delle voci principali
- Filtri applicabili a tutti i dati

## 📦 Installazione

1. Copia la cartella `glovo-invoice-dashboard` nella directory `wp-content/plugins/` del tuo WordPress
2. Vai nella sezione "Plugin" del pannello di amministrazione WordPress
3. Attiva il plugin "Glovo Invoice Dashboard"

## 🗄️ Configurazione Database

**IMPORTANTE:** Il plugin utilizza un database separato da WordPress per le fatture Glovo.

### Configurazione Automatica

Il plugin **cerca automaticamente** il file `config-glovo.php` nei seguenti percorsi:
- `httpdocs/scripts-glovo/config-glovo.php`
- `scripts-glovo/config-glovo.php`
- Altre posizioni comuni

**Se il file `config-glovo.php` esiste**, il plugin lo userà automaticamente! ✅

### Configurazione Manuale (Fallback)

Se `config-glovo.php` non viene trovato, modifica `config-db.php` nella cartella del plugin:

```php
<?php
return array(
    'db_host' => 'localhost',
    'db_name' => 'dash_glovo',           // Nome del database
    'db_user' => 'tuo_utente',           // Username database
    'db_pass' => 'tua_password',         // Password database
    'db_table' => 'gsr_glovo_fatture',   // Nome tabella fatture
    'db_charset' => 'utf8mb4'
);
```

### Formato config-glovo.php

Il file deve ritornare un array con questa struttura:
```php
<?php
return [
    'db_host' => 'localhost',
    'db_name' => 'dash_glovo',
    'db_user' => 'username',
    'db_pass' => 'password',
    'db_table' => 'gsr_glovo_fatture',
];
```

### Tabella Database Esistente

Il plugin si connette alla tabella **`gsr_glovo_fatture`** nel database **`dash_glovo`**.

**Non è necessario creare la tabella** - il plugin si collega alla tabella esistente.

### Struttura Tabella (Riferimento)

La tabella deve contenere i seguenti campi:
- `destinatario`, `negozio`, `n_fattura`, `data`, `periodo_da`, `periodo_a`
- `commissioni`, `marketing_visibilita`, `subtotale`, `iva_22`, `totale_fattura_iva_inclusa`
- `prodotti`, `servizio_consegna`, `totale_fattura_riepilogo`
- `promo_prodotti_partner`, `costo_incidenti_prodotti`, `tariffa_tempo_attesa`
- `rimborsi_partner_senza_comm`, `costo_annullamenti_servizio`, `consegna_gratuita_incidente`
- `buoni_pasto`, `glovo_gia_pagati`, `debito_accumulato`, `importo_bonifico`

Vedi `database.sql` per lo schema completo (solo a scopo di riferimento).

## 📝 Utilizzo

### Istruzioni Rapide

1. **Copia il plugin** in `wp-content/plugins/`
2. **Configurazione Database:**
   - ✅ Se hai già `config-glovo.php` sul server → **Nessuna configurazione necessaria!**
   - ⚙️ Altrimenti, configura `config-db.php` (vedi "Configurazione Database" sopra)
3. **Attiva il plugin** dal pannello WordPress
4. **Inserisci gli shortcode** nelle pagine

### Shortcode Tabella Fatture

Per visualizzare la tabella fatture, inserisci questo shortcode in una pagina o post:

```
[glovo_invoice_table]
```

Funzionalità:
- Filtra per destinatario, negozio, date
- Visualizza dettagli completi di ogni fattura
- Esporta dati in CSV
- Ordinamento e ricerca

### Shortcode Dashboard KPI

Per visualizzare la dashboard con KPI e grafici, inserisci:

```
[glovo_invoice_dashboard]
```

Funzionalità:
- KPI principali (fatturato, numero fatture, media, bonifici)
- Indicatori dettagliati per categorie
- Grafici mensili e per negozio
- Analisi percentuale

## ⚙️ Estrazione Dati e Corrispondenze

Il plugin non gestisce direttamente l'estrazione dei dati dai file PDF delle fatture Glovo. Questa operazione è demandata a uno script esterno (solitamente situato in `scripts-glovo/`) che legge i PDF, interpreta i dati tramite espressioni regolari (regex) e popola la tabella del database `gsr_glovo_fatture`.

Di seguito è riportata una tabella che mappa i dati come appaiono tipicamente in una fattura PDF con le rispettive colonne nel database del plugin.

| Campo nel PDF (Ipotesi) | Colonna Database | Descrizione |
| :--- | :--- | :--- |
| **Dati Anagrafici** | | |
| Nome del Ristorante/Partner | `destinatario` | Il nome legale del partner a cui è intestata la fattura. |
| Nome del Negozio/Sede | `negozio` | Il nome commerciale della specifica sede o negozio. |
| **Riferimenti Fattura** | | |
| Numero Fattura | `n_fattura` | L'identificativo univoco del documento fiscale. |
| Data Fattura | `data` | La data di emissione ufficiale della fattura. |
| Periodo Dal | `periodo_da` | La data di inizio del periodo di competenza dei servizi. |
| Periodo Al | `periodo_a` | La data di fine del periodo di competenza. |
| **Importi Principali** | | |
| Totale Prodotti Venduti | `prodotti` | Il valore totale dei prodotti venduti tramite Glovo. |
| Subtotale / Imponibile | `subtotale` | L'importo totale prima dell'applicazione dell'IVA. |
| IVA (es. 22%) | `iva_22` | L'ammontare dell'Imposta sul Valore Aggiunto calcolata. |
| Totale Fattura | `totale_fattura_iva_inclusa` | L'importo finale della fattura, comprensivo di IVA. |
| Importo del Bonifico | `importo_bonifico` | L'importo netto che viene trasferito al partner. |
| **Costi e Commissioni Glovo** | | |
| Commissione Glovo | `commissioni` | La commissione trattenuta da Glovo sul valore dei prodotti. |
| Marketing e Visibilità | `marketing_visibilita` | Costi per servizi promozionali e di marketing sulla piattaforma. |
| Servizio di Consegna | `servizio_consegna` | Il costo addebitato per il servizio di consegna. |
| Supplemento Glovo Prime | `supplemento_ordine_glovo_prime` | Supplemento applicato agli ordini effettuati tramite Glovo Prime. |
| **Costi Aggiuntivi e Rettifiche** | | |
| Promo a carico Partner | `promo_prodotti_partner` | Sconti e promozioni finanziati direttamente dal partner. |
| Costi per Incidenti | `costo_incidenti_prodotti` | Addebiti relativi a problemi o incidenti con gli ordini. |
| Tariffa Tempo di Attesa | `tariffa_tempo_attesa` | Costo supplementare per tempi di attesa prolungati. |
| Annullamenti | `costo_annullamenti_servizio` | Costi derivanti da ordini annullati. |
| Consegna Gratuita (Incidente) | `consegna_gratuita_incidente` | Costo della consegna gratuita offerta a seguito di un incidente. |
| **Rimborsi e Note di Credito** | | |
| Rimborsi al Partner | `rimborsi_partner_senza_comm`| Rimborsi effettuati al partner per vari motivi. |
| **Altre Voci** | | |
| Buoni Pasto / Ticket | `buoni_pasto` | Il valore dei buoni pasto utilizzati per i pagamenti. |
| Debito Accumulato | `debito_accumulato` | Eventuale debito residuo da periodi precedenti. |


## 📊 Campi Database - Descrizione Dettagliata

Questa sezione descrive tutti i campi della tabella `gsr_glovo_fatture` e il loro utilizzo nelle formule della dashboard.

### Campi Anagrafici e Identificativi

| Campo | Tipo | Descrizione | Uso nelle Formule |
|-------|------|-------------|-------------------|
| **id** | INT | Identificativo univoco della fattura | Chiave primaria, usato per COUNT e identificazione |
| **destinatario** | VARCHAR | Nome legale del partner/ristorante | Filtro, raggruppamenti per destinatario |
| **negozio** | VARCHAR | Nome commerciale del negozio/sede | Filtro, raggruppamenti per negozio, analisi impatto per negozio |
| **n_fattura** | VARCHAR | Numero identificativo fattura | Visualizzazione, riferimento documento |
| **data** | DATE | Data emissione fattura | Filtro temporale, raggruppamento per mese nei grafici |
| **periodo_da** | DATE | Inizio periodo competenza | Filtro periodo di riferimento servizi |
| **periodo_a** | DATE | Fine periodo competenza | Filtro periodo di riferimento servizi |

### Campi Importi Principali

| Campo | Tipo | Descrizione | Uso nelle Formule |
|-------|------|-------------|-------------------|
| **prodotti** | DECIMAL(10,2) | Valore totale prodotti venduti | **BASE DI CALCOLO**: denominatore per tutte le percentuali, KPI principale, usato in: `Σ(prodotti)`, calcolo media per fattura, base per % impatto |
| **subtotale** | DECIMAL(10,2) | Imponibile (totale senza IVA) | Totale fiscale pre-IVA: `Σ(subtotale)`, verifica: `subtotale + iva_22 = totale_fattura_iva_inclusa` |
| **iva_22** | DECIMAL(10,2) | Imposta Valore Aggiunto 22% | Indicatore fiscale: `Σ(iva_22)`, calcolo % IVA su prodotti: `(iva_22 / prodotti) × 100` |
| **totale_fattura_iva_inclusa** | DECIMAL(10,2) | Totale fattura comprensivo IVA | Totale fatturato: `Σ(totale_fattura_iva_inclusa)`, KPI fatturato complessivo |
| **importo_bonifico** | DECIMAL(10,2) | Importo netto trasferito al partner | KPI bonifici totali: `Σ(importo_bonifico)`, rappresenta l'incasso effettivo del partner |

### Campi Commissioni e Costi Glovo

| Campo | Tipo | Descrizione | Uso nelle Formule |
|-------|------|-------------|-------------------|
| **commissioni** | DECIMAL(10,2) | Commissione Glovo su vendite | **COMPONENTE IMPATTO GLOVO**: `Σ(commissioni)`, parte di `impatto_glovo = commissioni + marketing + supplemento_prime` |
| **marketing_visibilita** | DECIMAL(10,2) | Costi servizi marketing/promo | **COMPONENTE IMPATTO GLOVO**: `Σ(marketing_visibilita)`, parte di `impatto_glovo`, % su prodotti: `(marketing / prodotti) × 100` |
| **servizio_consegna** | DECIMAL(10,2) | Costo servizio delivery | Indicatore costi: `Σ(servizio_consegna)`, % su prodotti: `(servizio_consegna / prodotti) × 100` |
| **supplemento_ordine_glovo_prime** | DECIMAL(10,2) | Supplemento per ordini Glovo Prime | **COMPONENTE IMPATTO GLOVO**: `Σ(supplemento_prime)`, parte di `impatto_glovo`, KPI dedicato |

### Campi Costi Aggiuntivi e Rettifiche

| Campo | Tipo | Descrizione | Uso nelle Formule |
|-------|------|-------------|-------------------|
| **promo_prodotti_partner** | DECIMAL(10,2) | Sconti/promo finanziati dal partner | **COMPONENTE IMPATTO+PROMO**: `Σ(promo_partner)`, usato in `impatto_glovo_promo = impatto_glovo + promo_partner`, KPI promozioni |
| **costo_incidenti_prodotti** | DECIMAL(10,2) | Addebiti per problemi/incidenti ordini | Indicatore costi: `Σ(costo_incidenti)`, % su prodotti, KPI incidenti |
| **tariffa_tempo_attesa** | DECIMAL(10,2) | Supplemento per tempi attesa prolungati | Indicatore ricavi extra: `Σ(tariffa_attesa)`, % su prodotti, KPI tariffa attesa |
| **costo_annullamenti_servizio** | DECIMAL(10,2) | Costi da ordini annullati | Indicatore costi: `Σ(costo_annullamenti)`, % su prodotti |
| **consegna_gratuita_incidente** | DECIMAL(10,2) | Costo consegna gratuita post-incidente | Indicatore costi: `Σ(consegna_gratuita)`, % su prodotti |

### Campi Rimborsi e Altro

| Campo | Tipo | Descrizione | Uso nelle Formule |
|-------|------|-------------|-------------------|
| **rimborsi_partner_senza_comm** | DECIMAL(10,2) | Rimborsi al partner (senza commissioni) | Indicatore rimborsi: `Σ(rimborsi)`, % su prodotti |
| **buoni_pasto** | DECIMAL(10,2) | Valore buoni pasto utilizzati | KPI buoni pasto: `Σ(buoni_pasto)`, % su prodotti |
| **glovo_gia_pagati** | DECIMAL(10,2) | Importi già corrisposti da Glovo | KPI Glovo già pagati: `Σ(glovo_gia_pagati)`, % su prodotti |
| **debito_accumulato** | DECIMAL(10,2) | Debito residuo da periodi precedenti | Indicatore fiscale: `Σ(debito_accumulato)`, monitoraggio crediti/debiti |

### Gerarchia Importi e Relazioni

```
RICAVI PRODOTTI
└─ prodotti (BASE DI CALCOLO PER %)
   └─ Commissioni Glovo
      ├─ commissioni ────────────┐
      ├─ marketing_visibilita ───┤─→ IMPATTO GLOVO NOMINALE
      └─ supplemento_prime ──────┘
         └─ promo_partner ─────────→ IMPATTO GLOVO + PROMO

TOTALE FATTURA
├─ subtotale (imponibile)
├─ iva_22
└─ totale_fattura_iva_inclusa

BONIFICO PARTNER
└─ importo_bonifico = totale_fattura - tutti_i_costi + crediti/rimborsi
```

### Campo "prodotti" - Il Centro del Sistema

Il campo **`prodotti`** è il più importante perché:

1. **Base di calcolo percentuali**: Tutte le percentuali di impatto sono calcolate su questo valore
2. **KPI principale**: "Totale Prodotti" è il primo KPI visualizzato
3. **Denominatore universale**: Usato in tutte le formule: `(costo / prodotti) × 100`
4. **Base impatto reale**: La riduzione 15% si applica a questo: `prodotti × 0.15`
5. **Raggruppamenti**: Somma per mese, per negozio nei grafici

**Esempio calcolo con prodotti = 1.000€:**
- Commissioni 250€ → `(250/1000) × 100 = 25%`
- Marketing 30€ → `(30/1000) × 100 = 3%`
- Impatto Glovo 280€ → `(280/1000) × 100 = 28%`

## 📊 Tabella Riepilogativa - Tutte le Formule Dashboard Fatture

### Sezione 1: KPI Cards (10 Cards Principali + 2 Alert)

| # | KPI Card | Formula Completa | Campi Usati | Descrizione Output |
|---|----------|------------------|-------------|-------------------|
| 1 | **Totale Prodotti** | `Σ(abs(prodotti))` per tutte le fatture filtrate | `prodotti` | Es: "15.420,50 €" - Valore totale prodotti venduti |
| 2 | **Numero Fatture** | `COUNT(*)` | - | Es: "24" - Conteggio fatture |
| 3 | **Media per Fattura** | `Σ(abs(prodotti)) / COUNT(*)` | `prodotti` | Es: "642,52 €" - Media valore prodotti per fattura |
| 4 | **Bonifici Totali** | `Σ(importo_bonifico)` | `importo_bonifico` | Es: "12.350,00 €" - Somma algebrica bonifici (tiene conto del segno) |
| 5 | **Promozioni Partner** | `Σ(abs(promo_prodotti_partner))` | `promo_prodotti_partner` | Es: "1.250,00 €" - Totale sconti partner |
| 6 | **Costo Incidenti** | `Σ(abs(costo_incidenti_prodotti))` | `costo_incidenti_prodotti` | Es: "320,50 €" - Totale costi incidenti |
| 7 | **Tariffa Attesa** | `Σ(abs(tariffa_tempo_attesa))` | `tariffa_tempo_attesa` | Es: "45,00 €" - Totale tariffe attesa |
| 8 | **Buoni Pasto** | `Σ(abs(buoni_pasto))` | `buoni_pasto` | Es: "890,00 €" - Totale buoni pasto |
| 9 | **Supplemento Glovo Prime** | `Σ(abs(supplemento_ordine_glovo_prime))` | `supplemento_ordine_glovo_prime` | Es: "180,00 €" - Totale supplementi Prime |
| 10 | **Glovo Già Pagati** | `Σ(abs(glovo_gia_pagati))` | `glovo_gia_pagati` | Es: "2.100,00 €" - Importi già pagati |
| 11 | **🚨 Fatture Critiche** | `COUNT(f WHERE (commissioni+marketing+suppl_prime)/prodotti > 0.28)` | `commissioni`, `marketing_visibilita`, `supplemento_ordine_glovo_prime`, `prodotti` | Es: "3" - Numero fatture > 28% |
| 12 | **👁️ Richiede Attenzione** | `COUNT(f WHERE (commissioni+marketing+suppl_prime)/prodotti >= 0.25 AND <= 0.28)` | Come sopra | Es: "5" - Numero fatture 25-28% |

### Sezione 2: Indicatori Dettagliati (3 Gruppi)

| Gruppo | Indicatore | Formula | Campi | Output Esempio |
|--------|-----------|---------|-------|----------------|
| **RICAVI** | Commissioni | `Σ(abs(commissioni))` | `commissioni` | "3.850,00 €" |
| | Servizio Consegna | `Σ(abs(servizio_consegna))` | `servizio_consegna` | "1.200,00 €" |
| | Marketing e Visibilità | `Σ(abs(marketing_visibilita))` | `marketing_visibilita` | "520,00 €" |
| **COSTI** | Costi Incidenti | `Σ(abs(costo_incidenti_prodotti))` | `costo_incidenti_prodotti` | "320,50 €" |
| | Rimborsi Partner | `Σ(abs(rimborsi_partner_senza_comm))` | `rimborsi_partner_senza_comm` | "150,00 €" |
| | Buoni Pasto | `Σ(abs(buoni_pasto))` | `buoni_pasto` | "890,00 €" |
| **FISCALE** | IVA 22% | `Σ(abs(iva_22))` | `iva_22` | "3.392,51 €" |
| | Debito Accumulato | `Σ(abs(debito_accumulato))` | `debito_accumulato` | "-250,00 €" (può essere negativo) |

### Sezione 3: Impatto Glovo - Formule Principali

| Calcolo | Formula Step-by-Step | Campi | Tipo | Output Esempio |
|---------|---------------------|-------|------|----------------|
| **Impatto Glovo Nominale** | `impatto = Σ(commissioni) + Σ(marketing) + Σ(supplemento_prime)` | `commissioni`, `marketing_visibilita`, `supplemento_ordine_glovo_prime` | Valore € | "4.550,00 €" |
| **% Impatto Nominale** | `% = (impatto_glovo / Σ(prodotti)) × 100` | `prodotti` + sopra | Percentuale | "29,52%" |
| **Impatto Glovo + Promo** | `impatto_promo = impatto_glovo + Σ(promo_partner)` | `promo_prodotti_partner` + sopra | Valore € | "5.800,00 €" |
| **% Impatto + Promo** | `% = (impatto_promo / Σ(prodotti)) × 100` | `prodotti` + sopra | Percentuale | "37,62%" |
| **Riduzione 15%** | `riduzione = Σ(prodotti) × 0.15` | `prodotti` | Valore € | "2.313,08 €" |
| **Impatto Glovo Reale** | `impatto_real = impatto_glovo - riduzione_15%` | Calcolati sopra | Valore € | "2.236,92 €" |
| **% Impatto Reale** | `% = (impatto_real / Σ(prodotti)) × 100` | `prodotti` + sopra | Percentuale | "14,52%" |
| **Impatto Reale + Promo** | `impatto_real_promo = impatto_promo - riduzione_15%` | Calcolati sopra | Valore € | "3.486,92 €" |
| **% Impatto Reale + Promo** | `% = (impatto_real_promo / Σ(prodotti)) × 100` | `prodotti` + sopra | Percentuale | "22,62%" |

### Sezione 4: Analisi Per Singola Fattura

| Campo Calcolato | Formula | Campi Input | Uso | Output Esempio |
|-----------------|---------|-------------|-----|----------------|
| **Impatto Glovo Fattura** | `commissioni + marketing + supplemento_prime` | 3 campi | Tabella dettaglio | "290,00 €" |
| **% Impatto Fattura** | `(impatto / prodotti) × 100` | `prodotti` | Classificazione allerta | "29,00%" |
| **Impatto + Promo Fattura** | `impatto + promo_partner` | 4 campi | Tabella dettaglio | "340,00 €" |
| **% Impatto + Promo** | `(impatto_promo / prodotti) × 100` | `prodotti` | Tabella dettaglio | "34,00%" |
| **Livello Allerta** | `IF % > 28 THEN 'critico' ELSE IF % >= 25 THEN 'attenzione' ELSE 'normale'` | Calcolato sopra | Badge colorato | "critico" 🔴 |

### Sezione 5: Analisi Percentuale Voci (13 Voci)

| # | Voce | Formula % | Campi | Visualizzazione | Esempio Output |
|---|------|-----------|-------|-----------------|----------------|
| 1 | Commissioni Glovo | `(Σ(commissioni) / Σ(prodotti)) × 100` | `commissioni`, `prodotti` | Barra + % | "25,00% - 3.850€" |
| 2 | Marketing e Visibilità | `(Σ(marketing) / Σ(prodotti)) × 100` | `marketing_visibilita`, `prodotti` | Barra + % | "3,37% - 520€" |
| 3 | Servizio Consegna | `(Σ(servizio) / Σ(prodotti)) × 100` | `servizio_consegna`, `prodotti` | Barra + % | "7,78% - 1.200€" |
| 4 | Buoni Pasto | `(Σ(buoni) / Σ(prodotti)) × 100` | `buoni_pasto`, `prodotti` | Barra + % | "5,77% - 890€" |
| 5 | Costi Incidenti | `(Σ(incidenti) / Σ(prodotti)) × 100` | `costo_incidenti_prodotti`, `prodotti` | Barra + % | "2,08% - 320,50€" |
| 6 | Rimborsi Partner | `(Σ(rimborsi) / Σ(prodotti)) × 100` | `rimborsi_partner_senza_comm`, `prodotti` | Barra + % | "0,97% - 150€" |
| 7 | Promo Partner | `(Σ(promo) / Σ(prodotti)) × 100` | `promo_prodotti_partner`, `prodotti` | Barra + % | "8,11% - 1.250€" |
| 8 | Tariffa Attesa | `(Σ(tariffa) / Σ(prodotti)) × 100` | `tariffa_tempo_attesa`, `prodotti` | Barra + % | "0,29% - 45€" |
| 9 | Costo Annullamenti | `(Σ(annullamenti) / Σ(prodotti)) × 100` | `costo_annullamenti_servizio`, `prodotti` | Barra + % | "0,52% - 80€" |
| 10 | Consegna Gratuita | `(Σ(consegna_gratis) / Σ(prodotti)) × 100` | `consegna_gratuita_incidente`, `prodotti` | Barra + % | "0,32% - 50€" |
| 11 | Supplemento Prime | `(Σ(supplemento) / Σ(prodotti)) × 100` | `supplemento_ordine_glovo_prime`, `prodotti` | Barra + % | "1,17% - 180€" |
| 12 | Glovo Già Pagati | `(Σ(gia_pagati) / Σ(prodotti)) × 100` | `glovo_gia_pagati`, `prodotti` | Barra + % | "13,62% - 2.100€" |
| 13 | IVA 22% | `(Σ(iva) / Σ(prodotti)) × 100` | `iva_22`, `prodotti` | Barra + % | "22,00% - 3.392,51€" |

### Sezione 6: Impatto Glovo per Negozio

| Metrica | Formula per Negozio | Raggruppamento | Output Esempio |
|---------|-------------------|----------------|----------------|
| **Prodotti Negozio** | `Σ(prodotti WHERE negozio = X)` | `GROUP BY negozio` | "Negozio A: 5.000€" |
| **Commissioni Negozio** | `Σ(commissioni WHERE negozio = X)` | `GROUP BY negozio` | "1.250€" |
| **Marketing Negozio** | `Σ(marketing WHERE negozio = X)` | `GROUP BY negozio` | "180€" |
| **Supplemento Negozio** | `Σ(supplemento_prime WHERE negozio = X)` | `GROUP BY negozio` | "60€" |
| **Promo Negozio** | `Σ(promo_partner WHERE negozio = X)` | `GROUP BY negozio` | "400€" |
| **Impatto Nominale Neg.** | `comm + marketing + suppl` | Per negozio | "1.490€" |
| **Riduzione 15% Neg.** | `prodotti_negozio × 0.15` | Per negozio | "750€" |
| **Impatto Reale Neg.** | `impatto_nominale - riduzione_15%` | Per negozio | "740€" |
| **% Impatto Reale Neg.** | `(impatto_real / prodotti_negozio) × 100` | Per negozio | "14,80%" |
| **Impatto Real+Promo Neg.** | `impatto_real + promo` | Per negozio | "1.140€" |
| **% Imp. Real+Promo Neg.** | `(impatto_real_promo / prodotti_negozio) × 100` | Per negozio | "22,80%" |
| **Livello Allerta Negozio** | `IF % >= 28 THEN 'critico' ELSE IF % >= 25 THEN 'attenzione' ELSE 'normale'` | Per negozio | "normale" 🟢 |

### Sezione 7: Grafici

| Grafico | Tipo | Asse X | Asse Y (Formula) | Query GROUP BY |
|---------|------|--------|------------------|----------------|
| **Prodotti per Mese** | Bar | Mese (YYYY-MM) | `Σ(prodotti)` | `DATE_FORMAT(data, '%Y-%m')` |
| **Prodotti per Negozio** | Bar | Nome negozio | `Σ(prodotti)` | `negozio` con `LIMIT 10` |
| **Impatto Nominale** | Pie | "Impatto Glovo" / "Resto" | `impatto_glovo` vs `prodotti - impatto` | - |
| **Impatto Nom. + Promo** | Pie | "Impatto+Promo" / "Resto" | `impatto_promo` vs `prodotti - impatto_promo` | - |
| **Impatto Reale** | Pie | "Impatto Reale" / "Resto" | `impatto_real` vs `prodotti - impatto_real` | - |
| **Impatto Real + Promo** | Pie | "Impatto Real+Promo" / "Resto" | `impatto_real_promo` vs `prodotti - impatto_real_promo` | - |

### Note Importanti sulle Formule

1. **abs()**: Tutti i valori vengono convertiti in assoluto con `abs(floatval())` per evitare valori negativi
2. **Σ**: Indica sommatoria su tutte le fatture che passano i filtri attivi
3. **Arrotondamento**: Tutti gli importi a 2 decimali, percentuali a 2 decimali
4. **Filtri**: Tutte le formule si applicano solo alle fatture filtrate (destinatario, negozio, date, periodo)
5. **Zero handling**: Voci con valore 0 vengono nascoste automaticamente nelle analisi percentuali
6. **Ordinamento**: Analisi percentuali ordinate per valore decrescente

## 📊 Calcoli e Formule Dashboard

Questa sezione documenta tutti i calcoli utilizzati per generare i KPI e gli indicatori della dashboard.

### KPI Cards Principali

| KPI | Formula | Campo Database | Descrizione |
|-----|---------|----------------|-------------|
| **Totale Prodotti** | `Σ(prodotti)` | `prodotti` | Somma del valore totale dei prodotti venduti |
| **Numero Fatture** | `COUNT(*)` | - | Numero totale di fatture nel periodo |
| **Media per Fattura** | `totale_prodotti / numero_fatture` | Calcolato | Valore medio prodotti per singola fattura |
| **Bonifici Totali** | `Σ(importo_bonifico)` | `importo_bonifico` | Somma di tutti gli importi bonifici |
| **Promozioni Partner** | `Σ(promo_prodotti_partner)` | `promo_prodotti_partner` | Sconti a carico del partner |
| **Costo Incidenti** | `Σ(costo_incidenti_prodotti)` | `costo_incidenti_prodotti` | Incidenti sui prodotti |
| **Tariffa Attesa** | `Σ(tariffa_tempo_attesa)` | `tariffa_tempo_attesa` | Tempo di attesa |
| **Buoni Pasto** | `Σ(buoni_pasto)` | `buoni_pasto` | Valore buoni pasto |
| **Supplemento Glovo Prime** | `Σ(supplemento_ordine_glovo_prime)` | `supplemento_ordine_glovo_prime` | Supplemento per ordini Glovo Prime |
| **Glovo Già Pagati** | `Σ(glovo_gia_pagati)` | `glovo_gia_pagati` | Importi già corrisposti da Glovo |

### Totali per Categoria

| Indicatore | Formula | Campo Database |
|------------|---------|----------------|
| **Totale Fatturato** | `Σ(totale_fattura_iva_inclusa)` | `totale_fattura_iva_inclusa` |
| **Totale Subtotale (Imponibile)** | `Σ(subtotale)` | `subtotale` |
| **Totale Commissioni** | `Σ(commissioni)` | `commissioni` |
| **Totale IVA** | `Σ(iva_22)` | `iva_22` |
| **Totale Marketing** | `Σ(marketing_visibilita)` | `marketing_visibilita` |
| **Totale Servizio Consegna** | `Σ(servizio_consegna)` | `servizio_consegna` |
| **Totale Costi Incidenti** | `Σ(costo_incidenti_prodotti)` | `costo_incidenti_prodotti` |
| **Totale Rimborsi** | `Σ(rimborsi_partner_senza_comm)` | `rimborsi_partner_senza_comm` |
| **Totale Buoni Pasto** | `Σ(buoni_pasto)` | `buoni_pasto` |
| **Totale Promo Partner** | `Σ(promo_prodotti_partner)` | `promo_prodotti_partner` |
| **Totale Tariffa Attesa** | `Σ(tariffa_tempo_attesa)` | `tariffa_tempo_attesa` |
| **Totale Costo Annullamenti** | `Σ(costo_annullamenti_servizio)` | `costo_annullamenti_servizio` |
| **Totale Consegna Gratuita** | `Σ(consegna_gratuita_incidente)` | `consegna_gratuita_incidente` |
| **Totale Supplemento Glovo Prime** | `Σ(supplemento_ordine_glovo_prime)` | `supplemento_ordine_glovo_prime` |
| **Totale Glovo Già Pagati** | `Σ(glovo_gia_pagati)` | `glovo_gia_pagati` |
| **Debito Totale** | `Σ(debito_accumulato)` | `debito_accumulato` |

### Calcolo Impatto Glovo

**Base di calcolo:** `Totale Prodotti` (campo `prodotti`)

| Indicatore | Formula | Descrizione |
|------------|---------|-------------|
| **Impatto Glovo** | `commissioni + marketing_visibilita + supplemento_ordine_glovo_prime` | Costo diretto Glovo sul partner |
| **% Impatto Glovo** | `(impatto_glovo / totale_prodotti) × 100` | Percentuale impatto sul valore prodotti |
| **Impatto Glovo + Promo** | `commissioni + marketing_visibilita + supplemento_ordine_glovo_prime + promo_prodotti_partner` | Include anche sconti partner |
| **% Impatto Glovo + Promo** | `(impatto_glovo_promo / totale_prodotti) × 100` | Percentuale impatto totale |

### Livelli di Allerta per Fatture

Il sistema classifica automaticamente ogni fattura in base alla percentuale di Impatto Glovo:

| Livello | Soglia | Badge | Descrizione |
|---------|--------|-------|-------------|
| **Critico** | > 28% | 🔴 Rosso | Richiede attenzione immediata |
| **Attenzione** | 25-28% | 🟠 Arancione | Da monitorare |
| **Normale** | < 25% | 🟢 Verde | Nella norma |

**Formula per singola fattura:**
```
impatto_glovo = commissioni + marketing_visibilita + supplemento_ordine_glovo_prime
percentuale_impatto = (impatto_glovo / prodotti) × 100

SE percentuale_impatto > 28% → CRITICO
SE percentuale_impatto >= 25% E <= 28% → ATTENZIONE
SE percentuale_impatto < 25% → NORMALE
```

### Box Alert Dashboard

| Alert | Calcolo | Descrizione |
|-------|---------|-------------|
| **Fatture Critiche** | `COUNT(fatture con % impatto > 28%)` | Numero fatture con impatto critico |
| **Richiede Attenzione** | `COUNT(fatture con % impatto 25-28%)` | Numero fatture da monitorare |

### Sezione "Impatto Voci sul Totale Prodotti"

**Base di calcolo:** `Totale Prodotti`

Ogni voce mostra:
- Valore in euro
- Percentuale sul totale prodotti: `(valore_voce / totale_prodotti) × 100`

Voci analizzate:
- Commissioni Glovo
- Marketing e Visibilità
- Servizio Consegna
- Buoni Pasto
- Costi Incidenti Prodotti
- Rimborsi Partner
- Promozioni Prodotti Partner
- Tariffa Tempo Attesa
- Costo Annullamenti Servizio
- Consegna Gratuita Incidenti
- Supplemento Glovo Prime
- Glovo Già Pagati
- IVA 22%

### Sezione "Analisi Percentuale - Vista Comparativa"

**Base di calcolo:** `Totale Prodotti`

| Voce | Formula |
|------|---------|
| Percentuale Commissioni | `(totale_commissioni / totale_prodotti) × 100` |
| Percentuale Marketing | `(totale_marketing / totale_prodotti) × 100` |
| Percentuale Servizio Consegna | `(totale_servizio_consegna / totale_prodotti) × 100` |
| Percentuale Buoni Pasto | `(totale_buoni_pasto / totale_prodotti) × 100` |
| Percentuale Costi Incidenti | `(totale_costi_incidenti / totale_prodotti) × 100` |
| Percentuale Rimborsi | `(totale_rimborsi / totale_prodotti) × 100` |
| Percentuale IVA | `(totale_iva / totale_prodotti) × 100` |

### Tabella "Dettaglio Impatto Glovo per Fattura"

Per ogni fattura viene calcolato:

| Campo | Formula |
|-------|---------|
| **Impatto Glovo** | `commissioni + marketing_visibilita + supplemento_ordine_glovo_prime` (€) |
| **% Impatto** | `(impatto_glovo / prodotti) × 100` |
| **Glovo + Promo** | `commissioni + marketing_visibilita + supplemento_ordine_glovo_prime + promo_prodotti_partner` (€) |
| **% Impatto + Promo** | `(impatto_glovo_promo / prodotti) × 100` |
| **Livello Allerta** | Critico / Attenzione / Normale (basato su % Impatto) |

La tabella è filtrabile per:
- Tutti i livelli
- Solo Critiche (> 28%)
- Solo Attenzione (25-28%)
- Solo Normali (< 25%)

### Grafici

| Grafico | Tipo | Calcolo | Descrizione |
|---------|------|---------|-------------|
| **Totale Prodotti per Mese** | Barre | `Σ(prodotti)` per ogni mese | Andamento mensile del valore prodotti |
| **Totale Prodotti per Negozio** | Barre | `Σ(prodotti)` per ogni negozio (Top 10) | Negozi con maggior valore prodotti |
| **Impatto Nominale** | Torta | Impatto Glovo Nominale vs Resto | Percentuale costi Glovo nominali sul totale |
| **Impatto Nominale + Promo** | Torta | Impatto + Promo vs Resto | Include promozioni partner (nominale) |
| **Impatto Reale** | Torta | Impatto Glovo Reale vs Resto | Percentuale costi Glovo reali (con riduzione 15%) |
| **Impatto Reale + Promo** | Torta | Impatto Reale + Promo vs Resto | Include promozioni partner (reale) |

### Indicatori Dettagliati

**Ricavi:**
- Commissioni: `Σ(commissioni)`
- Servizio Consegna: `Σ(servizio_consegna)`
- Marketing e Visibilità: `Σ(marketing_visibilita)`

**Costi e Rimborsi:**
- Costi Incidenti: `Σ(costo_incidenti_prodotti)`
- Rimborsi Partner: `Σ(rimborsi_partner_senza_comm)`
- Buoni Pasto: `Σ(buoni_pasto)`

**Fiscale:**
- IVA 22%: `Σ(iva_22)`
- Debito Accumulato: `Σ(debito_accumulato)`

### Note sui Calcoli

1. **Σ** indica la somma di tutti i valori del campo per le fatture filtrate
2. Tutti gli importi sono arrotondati a **2 decimali**
3. Le percentuali sono calcolate con **2 decimali**
4. Le voci con valore **zero vengono nascoste** automaticamente nelle sezioni di impatto
5. I risultati vengono **ordinati per valore decrescente** nelle analisi
6. I calcoli si applicano **solo alle fatture filtrate** (se sono attivi dei filtri)
7. Gli importi negativi vengono convertiti in **valori assoluti** usando `abs()`

### Esempio Pratico di Calcolo Impatto

**Dati fattura esempio:**
- Prodotti: 1.000,00 €
- Commissioni: 250,00 €
- Marketing: 30,00 €
- Supplemento Glovo Prime: 10,00 €
- Promo Partner: 50,00 €

**Calcoli:**
```
Impatto Glovo = 250 + 30 + 10 = 290,00 €
% Impatto = (290 / 1.000) × 100 = 29,00%
→ Livello: CRITICO (> 28%)

Impatto Glovo + Promo = 250 + 30 + 10 + 50 = 340,00 €
% Impatto + Promo = (340 / 1.000) × 100 = 34,00%
→ Livello: CRITICO (> 28%)
```

### Impatto Nominale vs Impatto Reale

La dashboard presenta due tipologie di analisi dell'impatto Glovo:

#### Impatto Nominale

L'**Impatto Nominale** rappresenta il costo effettivo addebitato da Glovo come appare nelle fatture, senza alcuna rettifica.

**Formula:**
```
Impatto Nominale = commissioni + marketing_visibilita + supplemento_ordine_glovo_prime
```

**Percentuale:**
```
% Impatto Nominale = (Impatto Nominale / Totale Prodotti) × 100
```

#### Impatto Reale

L'**Impatto Reale** applica una riduzione del 15% sui costi Glovo per riflettere eventuali benefici fiscali, rimborsi o altre rettifiche che riducono l'impatto effettivo sui margini del partner.

**Formula:**
```
Riduzione 15% = Totale Prodotti × 0.15
Impatto Reale = Impatto Nominale - Riduzione 15%
```

**Percentuale:**
```
% Impatto Reale = (Impatto Reale / Totale Prodotti) × 100
```

**Nota:** La riduzione del 15% viene applicata al Totale Prodotti e poi sottratta dall'Impatto Nominale per calcolare l'Impatto Reale.

#### Impatto con Promozioni Partner

Entrambe le analisi (Nominale e Reale) possono includere le Promozioni Partner:

**Impatto Nominale con Promo:**
```
Impatto Nominale + Promo = Impatto Nominale + promo_prodotti_partner
```

**Impatto Reale con Promo:**
```
Impatto Reale + Promo = Impatto Nominale + Promo - Riduzione 15%
```

#### Esempio Pratico Impatto Reale

Utilizzando i dati dell'esempio precedente:
- Totale Prodotti: 1.000,00 €
- Impatto Nominale: 290,00 €

**Calcolo Impatto Reale:**
```
Riduzione 15% = 1.000 × 0.15 = 150,00 €
Impatto Reale = 290 - 150 = 140,00 €
% Impatto Reale = (140 / 1.000) × 100 = 14,00%
→ Livello: NORMALE (< 25%)
```

**Con Promozioni Partner:**
```
Impatto Nominale + Promo = 340,00 €
Impatto Reale + Promo = 340 - 150 = 190,00 €
% Impatto Reale + Promo = (190 / 1.000) × 100 = 19,00%
→ Livello: NORMALE (< 25%)
```

### Colori e Indicatori Visivi

| Elemento | Colore | Uso |
|----------|--------|-----|
| Verde (#00A082) | Primary | KPI positivi, pulsanti, grafici |
| Giallo (#FFC244) | Secondary | Grafici, evidenziazioni |
| Rosso (#DC3545) | Danger | Alert critici, valori negativi |
| Arancione (#FFC107) | Warning | Alert attenzione |
| Verde (#28A745) | Success | Valori normali, badge positivi |

## 🎨 Personalizzazione

### Stili CSS

Puoi personalizzare i colori modificando le variabili CSS in `/assets/css/style.css`:

```css
:root {
    --gid-primary: #00A082;    /* Colore principale */
    --gid-secondary: #FFC244;  /* Colore secondario */
    --gid-success: #28A745;    /* Verde */
    --gid-info: #17A2B8;       /* Azzurro */
    --gid-warning: #FFC107;    /* Arancione */
    --gid-danger: #DC3545;     /* Rosso */
}
```

### Grafici

Il plugin utilizza Chart.js per i grafici. Per abilitare i grafici, aggiungi Chart.js al tuo tema:

```html
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
```

O installalo tramite npm/yarn e includilo nel bundle del tuo tema.

## 🔧 Requisiti

- WordPress 5.0 o superiore
- PHP 7.2 o superiore
- MySQL 5.6 o superiore
- jQuery (incluso in WordPress)
- Chart.js (opzionale, per i grafici)

## 📊 Campi Database

### Campi Principali
- **destinatario**: Nome del destinatario della fattura
- **negozio**: Nome del negozio
- **n_fattura**: Numero della fattura
- **data**: Data di emissione della fattura
- **periodo_da / periodo_a**: Periodo di riferimento

### Importi
- **subtotale**: Subtotale senza IVA
- **iva_22**: Importo IVA al 22%
- **totale_fattura_iva_inclusa**: Totale fattura con IVA

### Commissioni e Servizi
- **commissioni**: Commissioni Glovo
- **marketing_visibilita**: Costi marketing
- **servizio_consegna**: Costi servizio consegna
- **supplemento_ordine_glovo_prime**: Supplemento per ordini Glovo Prime
- **tariffa_tempo_attesa**: Tariffa per tempo di attesa

### Costi e Rimborsi
- **costo_incidenti_prodotti**: Costi per incidenti
- **rimborsi_partner_senza_comm**: Rimborsi ai partner
- **costo_annullamenti_servizio**: Costi per annullamenti
- **consegna_gratuita_incidente**: Consegne gratuite per incidenti
- **buoni_pasto**: Buoni pasto

### Altri
- **prodotti**: Descrizione prodotti
- **glovo_gia_pagati**: Importi già pagati da Glovo
- **debito_accumulato**: Debito accumulato
- **importo_bonifico**: Importo del bonifico finale

## 🐛 Troubleshooting

### La tabella non mostra dati
1. Verifica che il nome della tabella sia corretto in `class-invoice-database.php`
2. Controlla che la tabella contenga dati
3. Verifica i permessi del database

### I filtri non funzionano
1. Assicurati che jQuery sia caricato
2. Controlla la console del browser per errori JavaScript
3. Verifica che l'AJAX funzioni correttamente

### I grafici non appaiono
1. Assicurati che Chart.js sia caricato
2. Verifica che ci siano dati nel database
3. Controlla la console per errori

## 📄 Licenza

GPL v2 or later

## 👨‍💻 Supporto

Per problemi o richieste di funzionalità, apri una issue su GitHub.

## 📊 Dashboard Ordini - Calcoli e Formule

La Dashboard Ordini (`[glovo_orders_dashboard]`) fornisce analisi dettagliate sugli ordini Glovo tramite connessione diretta al database `dash_glovo`.

### Tabelle Database Utilizzate

| Tabella | Descrizione |
|---------|-------------|
| `gsr_glovo_dettagli` | Tabella principale con i dettagli degli ordini |
| `gsr_glovo_dettagli_items` | Tabella con i singoli prodotti di ogni ordine (JOIN con dettagli) |

**Relazione:** `gsr_glovo_dettagli_items.dettaglio_id = gsr_glovo_dettagli.id`

### KPI Dashboard Ordini

| KPI | Formula SQL | Campo Database | Descrizione |
|-----|-------------|----------------|-------------|
| **Totale Ordini** | `COUNT(d.id)` | `gsr_glovo_dettagli.id` | Numero totale ordini nel periodo filtrato |
| **Valore Prodotti** | `SUM(d.price_of_products)` | `price_of_products` | Somma del valore totale dei prodotti venduti |
| **Prodotti Venduti** | `SUM(i.quantity)` | `quantity` (JOIN items) | Quantità totale di prodotti venduti |
| **Valore Medio Ordine** | `total_products_value / total_orders` | Calcolato | Valore medio prodotti per ordine |
| **Prodotti per Ordine** | `total_products_qty / total_orders` | Calcolato | Media quantità prodotti per ordine |
| **Totale Addebitato** | `SUM(d.total_charged_to_partner)` | `total_charged_to_partner` | Importo totale addebitato al partner (non mostrato di default) |

### Analisi Temporali

| Analisi | Query SQL | Calcolo | Descrizione |
|---------|-----------|---------|-------------|
| **Distribuzione Oraria** | `GROUP BY HOUR(notification_partner_time)` | `COUNT(*)` per ogni ora | Numero ordini per fascia oraria (0-23) |
| **Vendite Giornaliere** | `GROUP BY DATE(notification_partner_time)` | `SUM(price_of_products)` | Valore prodotti per ogni giorno |
| **Vendite Settimanali** | `GROUP BY WEEKDAY(notification_partner_time)` | `SUM(price_of_products)` | Valore prodotti per giorno settimana (Lun-Dom) |
| **Vendite Mensili** | `GROUP BY DATE_FORMAT(..., '%Y-%m')` | `SUM(price_of_products)` | Valore prodotti per mese (es: "Gen 2024") |
| **Settimana del Mese** | `GROUP BY CEIL(DAY(...) / 7)` | `SUM(price_of_products)` | Valore prodotti per settimana del mese (1ª-5ª) |

**Nota WEEKDAY:**
- 0 = Lunedì
- 1 = Martedì
- 2 = Mercoledì
- 3 = Giovedì
- 4 = Venerdì
- 5 = Sabato
- 6 = Domenica

### Analisi Top Performers

| Analisi | Query SQL | Limite | Ordinamento |
|---------|-----------|--------|-------------|
| **Top 10 Negozi** | `GROUP BY store_name` | `LIMIT 10` | `ORDER BY SUM(price_of_products) DESC` |
| **Top 10 Prodotti** | `GROUP BY product_name` (JOIN items) | `LIMIT 10` | `ORDER BY SUM(quantity) DESC` |

**Formula Top Negozi:**
```sql
SELECT store_name, SUM(price_of_products) as total_value
FROM gsr_glovo_dettagli
GROUP BY store_name
ORDER BY total_value DESC
LIMIT 10
```

**Formula Top Prodotti:**
```sql
SELECT i.product_name, SUM(i.quantity) as total_qty
FROM gsr_glovo_dettagli d
INNER JOIN gsr_glovo_dettagli_items i ON i.dettaglio_id = d.id
GROUP BY i.product_name
ORDER BY total_qty DESC
LIMIT 10
```

### Analisi Negozi vs Media

Questa analisi confronta le vendite di ogni negozio con la media generale.

| Metrica | Formula | Descrizione |
|---------|---------|-------------|
| **Vendite per Negozio** | `SUM(price_of_products)` per negozio | Valore totale prodotti per negozio |
| **Media Generale** | `Σ(vendite_tutti_negozi) / COUNT(negozi)` | Media del valore prodotti tra tutti i negozi |
| **Sopra/Sotto Media** | Confronto visivo | Verde se >= media, Rosso se < media |

**Visualizzazione Grafico:**
- Barre verdi (#00A082) per negozi sopra la media
- Barre rosse (#FF6384) per negozi sotto la media
- Linea arancione tratteggiata (#FFA500) che indica la media generale

### Filtri Dashboard Ordini

| Filtro | Campo Database | Query WHERE | Descrizione |
|--------|----------------|-------------|-------------|
| **Data da** | `notification_partner_time` | `>= 'YYYY-MM-DD 00:00:00'` | Filtra ordini dalla data specificata |
| **Data a** | `notification_partner_time` | `<= 'YYYY-MM-DD 23:59:59'` | Filtra ordini fino alla data specificata |
| **Store** | `store_name` | `TRIM(store_name) = TRIM(?)` | Filtra per negozio specifico |
| **Metodo Pagamento** | `payment_method` | `payment_method = ?` | Filtra per metodo pagamento |

**Nota:** I filtri utilizzano `TRIM()` per gestire correttamente spazi iniziali/finali nei nomi dei negozi.

### Grafici Dashboard Ordini

| Grafico | Tipo | Dati X | Dati Y | Descrizione |
|---------|------|--------|--------|-------------|
| **Top 10 Negozi** | Bar (orizzontale) | Nome negozio | Valore prodotti (€) | Negozi con maggior fatturato |
| **Top 10 Prodotti** | Bar (orizzontale) | Nome prodotto | Quantità venduta | Prodotti più venduti |
| **Distribuzione Oraria** | Line | Ora (00:00-23:00) | Numero ordini | Picchi di ordinazioni |
| **Vendite Giornaliere** | Line | Data | Valore prodotti (€) | Andamento vendite giornaliere |
| **Vendite Settimanali** | Bar | Giorno settimana | Valore prodotti (€) | Performance per giorno settimana |
| **Vendite Mensili** | Bar | Mese/Anno | Valore prodotti (€) | Trend mensile vendite |
| **Settimana del Mese** | Bar | Settimana (1ª-5ª) | Valore prodotti (€) | Performance per settimana del mese |
| **Negozi vs Media** | Bar + Line | Nome negozio | Valore prodotti (€) | Confronto negozi con media generale |

### Impatto Glovo Reale per Negozio

Questa sezione combina i dati delle fatture con l'analisi per negozio.

**Database utilizzato:** `gsr_glovo_fatture` (tabella fatture)

| Metrica | Formula | Descrizione |
|---------|---------|-------------|
| **Totale Prodotti per Negozio** | `Σ(prodotti)` raggruppato per `negozio` | Somma valore prodotti per negozio |
| **Commissioni per Negozio** | `Σ(commissioni)` per negozio | Totale commissioni Glovo |
| **Marketing per Negozio** | `Σ(marketing_visibilita)` per negozio | Totale costi marketing |
| **Supplemento Prime per Negozio** | `Σ(supplemento_ordine_glovo_prime)` per negozio | Totale supplementi Glovo Prime |
| **Promo Partner per Negozio** | `Σ(promo_prodotti_partner)` per negozio | Totale promozioni a carico partner |

**Calcolo Impatto per Negozio:**

```
Impatto Glovo Nominale = commissioni + marketing + supplemento_prime

Riduzione 15% = totale_prodotti × 0.15

Impatto Glovo Reale = Impatto Nominale - Riduzione 15%

Impatto Glovo Reale + Promo = Impatto Reale + promo_partner

% Impatto Reale = (Impatto Glovo Reale / totale_prodotti) × 100

% Impatto Reale + Promo = (Impatto Glovo Reale + Promo / totale_prodotti) × 100
```

**Livelli di Allerta per Negozio:**

| Percentuale | Colore | Classificazione |
|-------------|--------|-----------------|
| **≥ 28%** | Rosso (danger) | Critico |
| **25-27.99%** | Arancione (warning) | Attenzione |
| **< 25%** | Verde (success) | Normale |

**Visualizzazione:**
- Doppia barra per ogni negozio:
  1. Impatto Glovo Reale
  2. Impatto Glovo Reale + Promozioni Partner
- Ordinamento per percentuale impatto reale decrescente (dal più alto al più basso)

### Shortcode Dashboard Ordini

```
[glovo_orders_dashboard]
```

**Funzionalità:**
- Visualizza KPI principali ordini
- Grafici interattivi per analisi temporali e prodotti
- Filtri per data, negozio e metodo pagamento
- Comparazione negozi vs media
- Analisi distribuzione oraria

**Nota:** Questa dashboard richiede la tabella `gsr_glovo_dettagli` e `gsr_glovo_dettagli_items` nel database `dash_glovo`.

### Calcolo Settimana del Mese

La formula `CEIL(DAY(notification_partner_time) / 7)` calcola la settimana del mese:

| Giorni del Mese | Settimana |
|-----------------|-----------|
| 1-7 | 1ª Settimana |
| 8-14 | 2ª Settimana |
| 15-21 | 3ª Settimana |
| 22-28 | 4ª Settimana |
| 29-31 | 5ª Settimana |

**Esempio:**
- Giorno 5 del mese: `CEIL(5/7) = CEIL(0.71) = 1` → 1ª Settimana
- Giorno 15 del mese: `CEIL(15/7) = CEIL(2.14) = 3` → 3ª Settimana
- Giorno 30 del mese: `CEIL(30/7) = CEIL(4.28) = 5` → 5ª Settimana

### Connessione Database

La Dashboard Ordini utilizza una **connessione mysqli separata** al database `dash_glovo`, indipendente dal database WordPress.

**Configurazione:**
- Stesso file di configurazione della Dashboard Fatture (`config-glovo.php`)
- Connessione diretta alle tabelle `gsr_glovo_dettagli` e `gsr_glovo_dettagli_items`

**Gestione Connessione:**
```php
// Creazione connessione
$connection = new mysqli($host, $user, $pass, $dbname);
$connection->set_charset('utf8mb4');

// Chiusura automatica nel distruttore della classe
public function __destruct() {
    $this->close_connection();
}
```

## 🔄 Changelog

### 1.2.1
- Aggiunta Dashboard Ordini con analisi avanzate
- Grafici per distribuzione temporale e top performers
- Analisi negozi vs media generale
- Export CSV prodotti venduti
- Documentazione completa formule e calcoli

### 1.0.0
- Release iniziale
- Tabella fatture con filtri
- Dashboard KPI
- Export CSV
- Grafici interattivi
- Design responsive
