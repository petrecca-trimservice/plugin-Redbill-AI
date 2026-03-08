# Scripts Glovo Parser

Sistema di script PHP per l'estrazione e gestione automatica di dati dalle fatture e dagli ordini Glovo.

## Descrizione

Questo progetto fornisce strumenti per:
- Estrarre dati strutturati da fatture PDF Glovo
- Importare CSV con dettagli ordini nel database
- Gestire e rimuovere duplicati
- Analizzare pattern di estrazione e identificare problemi
- Backfill e correzione dati nel database
- Importazione manuale di PDF problematici
- Salvare i dati in formato CSV e database MySQL

## Requisiti

- PHP 7.4 o superiore
- MySQL/MariaDB
- Composer
- Estensione PHP: `pdo_mysql`, `mbstring`
- Accesso al filesystem per leggere PDF e CSV

## Installazione

1. Clona il repository:
```bash
git clone <repository-url>
cd scripts-Glovo
```

2. Installa le dipendenze con Composer:
```bash
composer install
```

3. Configura il database modificando `config-glovo.php`:
```php
return [
    'db_host'     => 'localhost',
    'db_name'     => 'dash_glovo',
    'db_user'     => 'tuo_utente',
    'db_pass'     => 'tua_password',
    'db_table'    => 'gsr_glovo_fatture',
    'alert_email' => 'admin@esempio.com',
];
```

## Struttura del Database

### Tabella: `gsr_glovo_fatture`
Contiene i dati estratti dalle fatture PDF:
- Informazioni destinatario e negozio
- Numero fattura e periodo di riferimento
- Commissioni, marketing, IVA
- Totali e riepilogo importi
- Prodotti, servizi, promozioni
- Rimborsi, incidenti, buoni pasto
- Promozione consegna, costi offerta lampo, promozione lampo

### Tabella: `gsr_glovo_dettagli`
Contiene i dettagli degli ordini importati da CSV:
- Timestamp notifica partner
- Descrizione ordini
- Note su allergie
- Dati negozio e pagamento
- Prezzi prodotti e commissioni
- Promozioni e incidenti

### Tabella: `gsr_glovo_dettagli_items`
Dettaglio singoli prodotti degli ordini:
- Riferimento a `gsr_glovo_dettagli`
- Nome prodotto
- Quantita

## Script Disponibili

### Script Principali

#### 1. estrai_fatture_glovo.php

Estrae dati dalle fatture PDF di Glovo con sistema di validazione e alert via email.

**Funzionalita:**
- Legge tutti i PDF dalla cartella `../wp-content/uploads/msg-extracted/pdf`
- Parsifica le fatture estraendo tutti i campi rilevanti
- Valida 15 campi obbligatori prima dell'inserimento
- Salva i dati nel database (tabella configurata in `config-glovo.php`)
- Genera un CSV di output: `fatture_glovo_estratte.csv`
- Sposta i PDF processati nella sottocartella `processed/`
- Sposta i PDF con errori nella sottocartella `failed/`
- Registra errori in `estrazione_errori.log`
- Invia email di alert in caso di errori e riepilogo finale
- Gestisce automaticamente i duplicati (salta l'inserimento se il numero fattura esiste gia)

**Utilizzo:**
```bash
php estrai_fatture_glovo.php
```

**Output:**
- CSV: `fatture_glovo_estratte.csv`
- Record nel database
- PDF spostati in `processed/` o `failed/`
- Log errori in `estrazione_errori.log`
- Email di notifica (se configurata)

**Campi estratti:**
- Destinatario e negozio
- Numero fattura, data, periodo
- Commissioni, marketing, IVA
- Totali fattura
- Prodotti, servizi, promozioni
- Rimborsi, incidenti, supplementi
- Promozione consegna partner, costi offerta lampo, promozione lampo partner
- Importo finale bonifico

Per maggiori dettagli sul sistema di validazione, consultare `VALIDAZIONE_README.md`.

#### 2. import-glovo-dettagli.php

Importa i CSV con i dettagli degli ordini Glovo.

**Funzionalita:**
- Legge tutti i CSV dalla cartella `../wp-content/uploads/msg-extracted/csv`
- Crea automaticamente le tabelle `gsr_glovo_dettagli` e `gsr_glovo_dettagli_items`
- Parsifica il campo Description per estrarre i singoli prodotti (formato: "2x Pizza, 1x Coca Cola")
- Gestisce le note allergie separate dalla descrizione prodotti
- Sposta i CSV processati nella sottocartella `processed/`
- Gestisce automaticamente i duplicati (salta record gia esistenti)

**Utilizzo:**
```bash
php import-glovo-dettagli.php
```

**Output:**
- Record nelle tabelle `gsr_glovo_dettagli` e `gsr_glovo_dettagli_items`
- CSV spostati in `../wp-content/uploads/msg-extracted/csv/processed/`

**Note:**
- Il campo "Notification Partner Time" viene convertito dal formato `Y-d-m H:i` a `Y-m-d H:i:s`
- Le allergie vengono estratte dal pattern `ALLERGIE: ...` e salvate separatamente nella colonna `allergie_note`

**Regole speciali di parsing prodotti:**
- Pattern standard: `quantita x nome_prodotto` (es. "2x Pizza Margherita")
- **BOX PATATE MITICHE**: Riconosciuto automaticamente quando appare come:
  - `, Aggiungi BOX PATATE MITICHE (cosi non si schiacciano!)`
  - `- Aggiungi BOX PATATE MITICHE (cosi non si schiacciano!)`
  - Viene estratto e salvato come prodotto con quantita 1

#### 3. fix-duplicati-glovo.php

Trova e rimuove duplicati dalla tabella `gsr_glovo_dettagli`.

**Funzionalita:**
- Verifica la presenza di duplicati basandosi su: store_name, notification_partner_time, description
- Mostra un report dettagliato dei duplicati trovati
- Richiede conferma prima di procedere con la rimozione
- Mantiene solo il primo record (ID piu basso) di ogni gruppo duplicato
- Rimuove i record correlati dalla tabella `gsr_glovo_dettagli_items`
- Aggiunge un UNIQUE constraint per prevenire futuri duplicati

**Utilizzo:**
```bash
php fix-duplicati-glovo.php
```

---

### Script di Analisi e Debug

#### 4. analizza_tutti_pdf.php

Analisi completa di tutti i PDF delle fatture con confronto database.

**Funzionalita:**
- Testa tutti i pattern regex di estrazione su ogni PDF
- Confronta i dati estratti con quelli presenti nel database
- Identifica voci con importo in euro non ancora mappate nei pattern
- Genera report HTML interattivo e CSV dettagliato nella cartella `analisi-output/`

**Utilizzo:**
```bash
php analizza_tutti_pdf.php [pdf_directory]
```

**Output:**
- Report HTML in `analisi-output/report_YYYY-MM-DD_HHmmss.html`
- CSV dettagliato in `analisi-output/dettaglio_YYYY-MM-DD_HHmmss.csv`

#### 5. analizza_pdf_web.php

Interfaccia web per l'analisi PDF. Stesse funzionalita di `analizza_tutti_pdf.php` ma con interfaccia grafica nel browser, progresso in tempo reale e archivio dei report precedenti.

**Utilizzo:** Accedi via browser.

#### 6. analizza_campi_db.php

Analizza la completezza dei campi nel database.

**Funzionalita:**
- Conta quante fatture hanno ogni campo valorizzato
- Mostra percentuali di presenza per ciascun campo
- Fornisce raccomandazioni per la validazione (campi obbligatori vs opzionali)
- Genera codice suggerito per la funzione `validaDatiEstratti()`

**Utilizzo:**
```bash
php analizza_campi_db.php
```

#### 7. debug_pdf_text.php

Debug del testo grezzo estratto da un PDF.

**Funzionalita:**
- Mostra il testo raw estratto dalla prima pagina di un PDF
- Cerca keyword specifiche (promozioni, offerte lampo, buoni pasto, ecc.)
- Supporta ricerca per numero fattura (web)

**Utilizzo:**
```bash
# CLI
php debug_pdf_text.php <file.pdf>
```
Oppure via browser: `debug_pdf_text.php?file=nome.pdf` o `debug_pdf_text.php?search=NUMERO_FATTURA`

#### 8. test_estrazione_web.php

Interfaccia web per testare l'estrazione di un singolo PDF.

**Funzionalita:**
- Testa il regex per `importo_bonifico` su un PDF specifico
- Mostra il testo grezzo intorno alla keyword
- Testa varianti del regex (case insensitive, spazi flessibili, ecc.)
- Lista i PDF recenti in `processed/`

**Utilizzo:** Accedi via browser con `test_estrazione_web.php?pdf=nome_file.pdf`

#### 9. test_pdf_singolo.php

Test CLI dell'estrazione di un singolo PDF con focus su `importo_bonifico`.

**Utilizzo:**
```bash
php test_pdf_singolo.php <nome_file.pdf>
```

#### 10. test_pattern_fix.php

Verifica i pattern regex corretti contro esempi reali.

**Funzionalita:**
- Testa pattern vecchi vs nuovi su esempi dalle fatture
- Mostra percentuali di successo e miglioramento per ogni campo
- Copre: `promo_consegna_partner`, `marketing_visibilita`, `tariffa_tempo_attesa`, `buoni_pasto`

**Utilizzo:**
```bash
php test_pattern_fix.php
```
Oppure accesso via browser.

#### 11. test_db.php

Script di test per verificare la connessione al database.

**Utilizzo:**
```bash
php test_db.php
```

---

### Script di Backfill e Correzione Dati

#### 12. backfill_nuovi_campi.php

Backfill per aggiornare i record DB con 3 nuovi campi estratti dai PDF gia processati.

**Campi aggiornati:**
- `promo_consegna_partner`
- `costi_offerta_lampo`
- `promo_lampo_partner`

**Utilizzo:**
```bash
# CLI
php backfill_nuovi_campi.php              # esegue il backfill
php backfill_nuovi_campi.php --dry-run    # simula senza scrivere in DB
```
Oppure via browser (dry-run di default, esecuzione con conferma).

#### 13. backfill_pattern_fix.php

Backfill sicuro che aggiorna SOLO i campi NULL dopo la correzione dei pattern regex.

**Campi gestiti:**
- `promo_consegna_partner`
- `marketing_visibilita`
- `tariffa_tempo_attesa`
- `buoni_pasto`

**Sicurezza:** UPDATE condizionale (modifica solo se il campo e NULL).

**Utilizzo:**
```bash
# CLI
php backfill_pattern_fix.php              # esegue il backfill
php backfill_pattern_fix.php --dry-run    # simula senza scrivere in DB
```
Oppure via browser (dry-run di default, esecuzione con conferma).

---

### Script di Importazione Manuale

#### 14. importa_pdf_manuale.php

Importa un singolo PDF dalla cartella `failed/` senza validare il campo `importo_bonifico`.

**Utilizzo:** Accedi via browser, seleziona il PDF e opzionalmente inserisci l'importo del bonifico manualmente.

#### 15. importa_pdf_senza_commissioni.php

Importa un singolo PDF dalla cartella `failed/` senza validare il campo `commissioni`.

**Utilizzo:** Accedi via browser. Utile per fatture che non contengono commissioni (es. note di credito).

#### 16. importa_pdf_senza_commissioni_cli.php

Versione CLI dello script di importazione senza validazione commissioni.

**Utilizzo:**
```bash
php importa_pdf_senza_commissioni_cli.php                         # Lista PDF disponibili
php importa_pdf_senza_commissioni_cli.php nome_file.pdf           # Importa il PDF
php importa_pdf_senza_commissioni_cli.php nome_file.pdf 150.00    # Importa con commissioni manuali
```

---

### Script di Riprocessamento

#### 17. riprocessa_fatture_null.php

Riprocessamento automatico completo delle fatture con `importo_bonifico` NULL.

**Fasi:**
1. Trova fatture con `importo_bonifico` NULL nel database
2. Sposta i PDF da `processed/` a `pdf/`
3. Cancella le fatture dal database
4. Reimporta i PDF con il regex corretto

**Utilizzo:**
```bash
php riprocessa_fatture_null.php
```
Oppure via browser con conferma: `riprocessa_fatture_null.php?conferma=SI`

#### 18. sposta_pdf_per_riprocessare.php

Interfaccia web per spostare i PDF dalla cartella `processed/` a `pdf/` per riprocessarli.

**Funzionalita:**
- Identifica fatture con `importo_bonifico` NULL
- Mostra stato dei file PDF corrispondenti
- Sposta i file con conferma
- Indica i passi successivi (cancellazione DB e reimportazione)

**Utilizzo:** Accedi via browser.

---

### Query SQL

#### Assegnazione fatture ai negozi

| File | Descrizione |
|------|-------------|
| `assegna_fatture_milano.sql` | Assegna fatture con "HDCKZB" al negozio Milano |
| `assegna_fatture_orbassano.sql` | Assegna fatture con "N9P1UXP" al negozio Orbassano |
| `assegna_fatture_rivoli.sql` | Assegna fatture con "68I4P69" al negozio Rivoli |
| `assegna_fatture_san_mauro.sql` | Assegna fatture con "ZXXSM2I" al negozio San Mauro |
| `assegna_fatture_settimo_torinese.sql` | Assegna fatture con "2XVRHFU" al negozio Settimo Torinese |

#### Manutenzione dati

| File | Descrizione |
|------|-------------|
| `cancella_fatture_null.sql` | Cancella fatture con `importo_bonifico` NULL |
| `fix_destinatari_db.sql` | Normalizza nomi destinatari (S.R.L., S.P.A., ecc.) |

#### Query di analisi

| File | Descrizione |
|------|-------------|
| `query_analisi_campi.sql` | Analisi completezza di tutti i campi del database |
| `query_fattura_specifica.sql` | Recupera dettagli di una fattura specifica |
| `query_importo_bonifico_check.sql` | Verifica importi bonifico negativi |
| `query_importo_bonifico_negativo.sql` | Conta fatture con bonifico negativo |
| `query_importo_bonifico_null.sql` | Conta e mostra fatture con bonifico NULL |
| `query_valori_negativi.sql` | Verifica valori negativi in tutti i campi numerici |

---

## Struttura Cartelle

```
scripts-Glovo/
├── composer.json                          # Dipendenze del progetto
├── composer.lock
├── config-glovo.php                       # Configurazione database e email
├── README.md                              # Questa documentazione
├── VALIDAZIONE_README.md                  # Documentazione sistema di validazione
│
├── # Script principali
├── estrai_fatture_glovo.php               # Estrazione fatture PDF
├── import-glovo-dettagli.php              # Import CSV ordini
├── fix-duplicati-glovo.php                # Gestione duplicati
│
├── # Analisi e debug
├── analizza_tutti_pdf.php                 # Analisi completa PDF (CLI)
├── analizza_pdf_web.php                   # Analisi completa PDF (Web)
├── analizza_campi_db.php                  # Analisi completezza campi DB
├── debug_pdf_text.php                     # Debug testo estratto da PDF
├── test_estrazione_web.php                # Test estrazione singolo PDF (Web)
├── test_pdf_singolo.php                   # Test estrazione singolo PDF (CLI)
├── test_pattern_fix.php                   # Test pattern regex corretti
├── test_db.php                            # Test connessione DB
│
├── # Backfill e correzione dati
├── backfill_nuovi_campi.php               # Backfill 3 nuovi campi
├── backfill_pattern_fix.php               # Backfill pattern corretti
│
├── # Importazione manuale
├── importa_pdf_manuale.php                # Import manuale senza validazione bonifico
├── importa_pdf_senza_commissioni.php      # Import manuale senza validazione commissioni (Web)
├── importa_pdf_senza_commissioni_cli.php  # Import manuale senza validazione commissioni (CLI)
│
├── # Riprocessamento
├── riprocessa_fatture_null.php            # Riprocessa fatture con bonifico NULL
├── sposta_pdf_per_riprocessare.php        # Sposta PDF per riprocessamento (Web)
│
├── # Query SQL
├── assegna_fatture_*.sql                  # Assegnazione fatture ai negozi
├── cancella_fatture_null.sql              # Cancellazione fatture con NULL
├── fix_destinatari_db.sql                 # Normalizzazione destinatari
├── query_*.sql                            # Query di analisi e verifica
│
├── # Output
├── fatture_glovo_estratte.csv             # Output CSV fatture
├── analisi-output/                        # Report HTML e CSV analisi
├── estrazione_errori.log                  # Log errori estrazione
│
└── vendor/                                # Dipendenze Composer

Cartelle dati (esterne):
../wp-content/uploads/msg-extracted/
├── pdf/                       # Fatture PDF da processare
│   ├── processed/             # PDF elaborati con successo
│   └── failed/                # PDF con errori di validazione
└── csv/                       # CSV ordini da importare
    └── processed/             # CSV gia elaborati
```

## Flusso di Lavoro Tipico

1. **Posiziona i file da processare:**
   - Fatture PDF -> `../wp-content/uploads/msg-extracted/pdf/`
   - CSV ordini -> `../wp-content/uploads/msg-extracted/csv/`

2. **Estrai le fatture:**
   ```bash
   php estrai_fatture_glovo.php
   ```

3. **Importa i dettagli ordini:**
   ```bash
   php import-glovo-dettagli.php
   ```

4. **Se necessario, pulisci duplicati:**
   ```bash
   php fix-duplicati-glovo.php
   ```

5. **Se ci sono PDF in `failed/`, importali manualmente:**
   - Accedi a `importa_pdf_manuale.php` via browser
   - Oppure usa `importa_pdf_senza_commissioni_cli.php` da CLI

6. **Per analizzare i pattern di estrazione:**
   ```bash
   php analizza_tutti_pdf.php
   ```

## Gestione Errori

Gli script gestiscono automaticamente:
- **Duplicati:** Vengono saltati senza bloccare l'elaborazione
- **PDF non validi:** Vengono spostati in `failed/` con log dettagliato
- **CSV mal formattati:** Vengono segnalati e saltati
- **Connessione DB:** Errori chiari se la connessione fallisce
- **Email di alert:** Notifica automatica in caso di errori di validazione
- **Log errori:** Registrazione dettagliata in `estrazione_errori.log`

## Sicurezza

**IMPORTANTE:** Il file `config-glovo.php` contiene credenziali sensibili:
- NON committarlo in repository pubblici
- Aggiungi `config-glovo.php` al `.gitignore`
- Usa permessi file restrittivi: `chmod 600 config-glovo.php`

Esempio `.gitignore`:
```
config-glovo.php
vendor/
*.csv
estrazione_errori.log
analisi-output/
```

## Troubleshooting

### Errore: "Cartella PDF non trovata"
Verifica che esista la cartella `../wp-content/uploads/msg-extracted/pdf/`

### Errore: "Errore DB: Access denied"
Controlla le credenziali in `config-glovo.php`

### Encoding dei caratteri strani
Gli script usano UTF-8. Verifica che:
- Il database sia configurato con charset `utf8mb4`
- I file CSV abbiano encoding UTF-8

### Duplicati non vengono rimossi
Esegui prima lo script `fix-duplicati-glovo.php` per pulire i dati esistenti

### PDF finiscono in `failed/`
1. Controlla `estrazione_errori.log` per vedere quali campi mancano
2. Apri il PDF manualmente per verificare il layout
3. Usa `debug_pdf_text.php` per vedere il testo estratto
4. Se necessario, importa manualmente con `importa_pdf_manuale.php`

### Campi NULL nel database dopo importazione
1. Usa `analizza_campi_db.php` per identificare i campi problematici
2. Usa `backfill_pattern_fix.php` per aggiornare i campi NULL
3. Se il problema riguarda `importo_bonifico`, usa `riprocessa_fatture_null.php`

## Dipendenze

- **smalot/pdfparser** (^0.18): Libreria per il parsing dei PDF

## Documentazione Aggiuntiva

- `VALIDAZIONE_README.md`: Documentazione dettagliata del sistema di validazione e alert email
