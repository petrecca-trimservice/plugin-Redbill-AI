# Redbill AI — Guida di Installazione e Configurazione

> Plugin WordPress multi-tenant SaaS per la gestione automatizzata delle fatture Glovo.
> Versione: **1.0.0** — Autore: Trimservice AI

---

## Indice

1. [Requisiti di Sistema](#1-requisiti-di-sistema)
2. [Installazione](#2-installazione)
3. [Configurazione Piattaforma (Super-Admin)](#3-configurazione-piattaforma-super-admin)
4. [Creazione del Primo Tenant](#4-creazione-del-primo-tenant)
5. [Configurazione IMAP per Tenant](#5-configurazione-imap-per-tenant)
6. [Importazione Dati](#6-importazione-dati)
7. [Setup Dashboard (Shortcode)](#7-setup-dashboard-shortcode)
8. [Verifica Funzionamento](#8-verifica-funzionamento)
9. [Troubleshooting](#9-troubleshooting)
10. [Struttura Directory di Riferimento](#10-struttura-directory-di-riferimento)

---

## 1. Requisiti di Sistema

### Server

| Requisito | Versione minima | Note |
|-----------|----------------|------|
| PHP | **8.0** o superiore | Testato con 8.1, 8.2 |
| WordPress | **5.9** o superiore | |
| MySQL / MariaDB | 5.7 / 10.3 | Richiede utente con privilegi root per provisioning |

### Estensioni PHP obbligatorie

```bash
# Verifica estensioni installate
php -m | grep -E "imap|openssl|mbstring|pdo_mysql|mysqli|json|zip"
```

| Estensione | Utilizzo |
|-----------|---------|
| `openssl` | Cifratura AES-256-CBC credenziali |
| `imap` | Lettura email IMAP automatica |
| `mbstring` | Elaborazione testo UTF-8 |
| `mysqli` | Connessione DB tenant |
| `pdo_mysql` | Importazione CSV |
| `json` | Serializzazione configurazioni |

> ⚠️ **Estensione IMAP:** Su alcuni hosting condivisi l'estensione IMAP non è abilitata di default.
> Contatta il tuo provider o usa: `sudo apt install php8.1-imap && sudo systemctl restart apache2`

### Permessi MySQL

L'utente MySQL usato per il provisioning deve avere i seguenti privilegi:

```sql
-- Esegui come root MySQL
GRANT CREATE, DROP, CREATE USER, DROP USER, GRANT OPTION ON *.*
    TO 'tuo_utente_root'@'localhost';
FLUSH PRIVILEGES;
```

### Librerie Frontend (CDN automatico)

Caricate automaticamente dal plugin — non richiedono installazione:
- **Chart.js** 4.4.1 (grafici dashboard)
- **Select2** 4.1.0 (filtri avanzati)
- **Marked.js** 12.0.0 (rendering analisi Gemini AI)
- **html-docx-js** 0.3.1 (export Word)

---

## 2. Installazione

### Step 1 — Copia i file del plugin

**Opzione A — ZIP da WordPress Admin:**

1. Comprimi la cartella `redbill-ai/` in un archivio `.zip`
2. Vai su **Admin WordPress → Plugin → Aggiungi Nuovo → Carica Plugin**
3. Carica il file ZIP e clicca **Installa ora**

**Opzione B — Upload manuale via FTP/SSH:**

```bash
# Dal tuo computer locale
scp -r ./redbill-ai/ utente@server:/var/www/html/wp-content/plugins/
```

**Opzione C — Git clone diretto sul server:**

```bash
cd /var/www/html/wp-content/plugins/
git clone https://github.com/petrecca-trimservice/plugin-Redbill-AI.git temp-rbai
cp -r temp-rbai/redbill-ai ./redbill-ai
rm -rf temp-rbai
```

---

### Step 2 — Installa le dipendenze Composer

Il plugin richiede **smalot/pdfparser** per l'estrazione testo da PDF.

```bash
# Entra nella directory del plugin
cd /var/www/html/wp-content/plugins/redbill-ai/

# Installa dipendenze (richiede Composer installato)
composer install --no-dev --optimize-autoloader
```

> ⚠️ **Composer non installato?** Installa con:
> ```bash
> curl -sS https://getcomposer.org/installer | php
> sudo mv composer.phar /usr/local/bin/composer
> ```

Verifica che la cartella `vendor/` sia stata creata:

```bash
ls -la /var/www/html/wp-content/plugins/redbill-ai/vendor/
# Deve mostrare: autoload.php, smalot/, composer/
```

---

### Step 3 — Attiva il plugin da WordPress Admin

1. Vai su **Admin WordPress → Plugin**
2. Trova **Redbill AI** nella lista
3. Clicca **Attiva**

All'attivazione il plugin esegue automaticamente:
- ✅ Creazione tabella `wp_rbai_tenants` nel database WordPress
- ✅ Creazione cartella `/wp-content/uploads/rbai/` con protezione `.htaccess`
- ✅ Impostazione opzioni di default in `wp_options`

> Dopo l'attivazione dovresti vedere nel menu admin la voce **Redbill AI**.

---

## 3. Configurazione Piattaforma (Super-Admin)

Vai su **Admin WordPress → Redbill AI → Impostazioni**

> ⚠️ Questa pagina è visibile solo agli utenti con ruolo **Amministratore** (manage_options).

---

### Step 4 — Configura le credenziali MySQL root

Nella sezione **"Database MySQL — Provisioning Tenant"**:

| Campo | Valore di esempio | Descrizione |
|-------|------------------|-------------|
| **Host MySQL** | `localhost` | Host del server MySQL (di solito localhost) |
| **Utente MySQL** | `root` o `admin_rbai` | Utente con privilegi CREATE DATABASE/USER |
| **Password MySQL** | `••••••••` | Cifrata automaticamente con AES-256-CBC |

> 💡 **Consiglio sicurezza:** Anziché usare `root`, crea un utente dedicato:
> ```sql
> CREATE USER 'rbai_provisioner'@'localhost' IDENTIFIED BY 'password_sicura';
> GRANT CREATE, DROP, CREATE USER, DROP USER, GRANT OPTION ON *.*
>     TO 'rbai_provisioner'@'localhost';
> FLUSH PRIVILEGES;
> ```

Clicca **"Testa Connessione"** per verificare che le credenziali funzionino prima di salvare.

---

### Step 5 — Configura la Gemini API Key (opzionale)

Nella sezione **"Intelligenza Artificiale Gemini"**:

| Campo | Descrizione |
|-------|-------------|
| **Gemini API Key** | Chiave API Google Gemini per analisi AI delle fatture |

> 📌 La chiave Gemini è **richiesta solo per i piani Pro ed Enterprise**.
> I tenant con piano Basic non vedranno le funzionalità AI.

Per ottenere la chiave:
1. Vai su [Google AI Studio](https://aistudio.google.com/app/apikey)
2. Crea un progetto e genera una API Key
3. Incolla la chiave nel campo (viene cifrata al salvataggio)

---

### Step 6 — Imposta i parametri di registrazione

Nella sezione **"Registrazione e Piani"**:

| Campo | Opzioni | Default | Descrizione |
|-------|---------|---------|-------------|
| **Auto-approvazione** | Abilitato / Disabilitato | Disabilitato | Se abilitato, i nuovi tenant vengono approvati e provisionati automaticamente |
| **Piano di default** | basic / pro / enterprise | basic | Piano assegnato ai nuovi tenant |

Clicca **"Salva Impostazioni"**.

---

## 4. Creazione del Primo Tenant

### Step 7 — Crea l'utente WordPress

Ogni tenant deve avere un **utente WordPress** dedicato.

1. Vai su **Admin WordPress → Utenti → Aggiungi Nuovo**
2. Compila:
   - **Nome utente:** es. `mario_pizzeria` (sarà usato come base per lo slug tenant)
   - **Email:** email del cliente
   - **Ruolo:** `Sottoscrittore` (il plugin gestisce i permessi in modo autonomo)
3. Clicca **Aggiungi Utente**

---

### Step 8 — Crea e provisiona il tenant

1. Vai su **Admin WordPress → Redbill AI → Tenant**
2. Nella sezione **"Crea Tenant Manuale"**:

   | Campo | Valore |
   |-------|--------|
   | **Utente WordPress** | Seleziona `mario_pizzeria` dal menu a tendina |
   | **Piano** | Seleziona `basic`, `pro` o `enterprise` |

3. Clicca **"Crea e Provisiona"**

Il sistema eseguirà automaticamente:

```
✅ Genera slug univoco (es. "mario-pizzeria")
✅ Crea database MySQL: rbai_mario-pizzeria
✅ Crea utente MySQL: rbai_mario_pi (password casuale 24 char)
✅ Crea tabelle: gsr_glovo_fatture, gsr_glovo_dettagli,
                 gsr_glovo_dettagli_items, indice_UID_mail
✅ Crea cartelle upload:
   /wp-content/uploads/rbai/mario-pizzeria/pdf/
   /wp-content/uploads/rbai/mario-pizzeria/pdf/processed/
   /wp-content/uploads/rbai/mario-pizzeria/pdf/failed/
   /wp-content/uploads/rbai/mario-pizzeria/csv/
✅ Registra tenant in wp_rbai_tenants (status: active)
✅ Schedula cron email check
```

---

### Step 9 — Verifica il provisioning

Nella tabella **Redbill AI → Tenant** dovresti vedere il nuovo tenant con:

| Colonna | Valore atteso |
|---------|--------------|
| Status | 🟢 **active** |
| DB Provisioned | ✅ Sì |
| Piano | basic / pro / enterprise |

Per verificare il database creato:

```bash
# Via MySQL CLI
mysql -u root -p -e "SHOW DATABASES LIKE 'rbai_%';"
# Output: rbai_mario-pizzeria

mysql -u root -p rbai_mario-pizzeria -e "SHOW TABLES;"
# Output: gsr_glovo_fatture, gsr_glovo_dettagli, gsr_glovo_dettagli_items, indice_UID_mail
```

---

## 5. Configurazione IMAP per Tenant

Il tenant può configurare autonomamente il proprio account email IMAP.
Il super-admin può farlo anche dalla pagina Tenant (link "Configura IMAP").

### Step 10 — Accedi alla pagina IMAP

**Come super-admin:**
Vai su **Redbill AI → Tenant** → clicca **"Impostazioni IMAP"** accanto al tenant

**Come tenant (utente loggato):**
Vai su **Il Mio Account → Impostazioni IMAP**

---

### Step 11 — Inserisci la configurazione IMAP

#### Sezione: Account Email

| Campo | Gmail | Outlook/Office 365 | Yahoo |
|-------|-------|--------------------|-------|
| **Server IMAP** | `imap.gmail.com` | `outlook.office365.com` | `imap.mail.yahoo.com` |
| **Porta** | `993` | `993` | `993` |
| **Username** | `tuo@gmail.com` | `tuo@outlook.com` | `tuo@yahoo.com` |
| **Password** | App Password | Password account | App Password |
| **SSL/TLS** | ✅ Abilitato | ✅ Abilitato | ✅ Abilitato |

> ⚠️ **Gmail — App Password obbligatoria:**
> Con la verifica in 2 passaggi attiva, devi usare un'App Password:
> 1. Vai su [Account Google → Sicurezza](https://myaccount.google.com/security)
> 2. Sezione "Accesso a Google" → "Password per le app"
> 3. Crea una password per "Posta" + "Altro dispositivo"
> 4. Usa questa password nel plugin (non la password Google normale)

#### Sezione: Frequenza Controllo Automatico

| Opzione | Intervallo | Utilizzo consigliato |
|---------|-----------|---------------------|
| `Disabilitato` | — | Solo elaborazione manuale |
| `Ogni 5 minuti` | 300s | Alta frequenza (server dedicato) |
| `Ogni 15 minuti` | 900s | **Bilanciato (consigliato)** |
| `Ogni 30 minuti` | 1800s | Bassa frequenza (hosting condiviso) |
| `Ogni ora` | 3600s | Minimo carico server |

**Email per ciclo:** Numero massimo di email elaborate ogni controllo.
Default: `50` — riduci a `20-30` se il server va in timeout.

#### Sezione: Filtri Email (opzionale)

| Campo | Descrizione | Esempio |
|-------|-------------|---------|
| **Mittenti attendibili** | Elabora solo email da questi mittenti | `noreply@glovo.com` |
| **Parole chiave oggetto** | Filtra per parole nell'oggetto email | `fattura, invoice` |
| **Estensioni ammesse** | Tipi di allegati da scaricare | `pdf, csv` |

---

### Step 12 — Testa la connessione

Clicca il bottone **"🔌 Testa Connessione"**.

Risposta attesa:
```
✅ Connessione riuscita! Email in INBOX: 1234 totali, 56 non lette.
```

In caso di errore:
```
❌ Connessione fallita: [AUTHENTICATIONFAILED] Invalid credentials
→ Verificare username/password (per Gmail usa App Password)
```

Clicca **"Salva Configurazione"** dopo il test positivo.

---

## 6. Importazione Dati

### Step 13 — Carica i file PDF delle fatture

**Opzione A — Upload manuale via shortcode:**

Aggiungi il shortcode `[msg_uploader]` in una pagina WordPress.
Il tenant può caricare file `.msg` di Outlook → il plugin estrae automaticamente i PDF allegati.

**Opzione B — Upload diretto via FTP/SSH:**

```bash
# Copia i PDF direttamente nella cartella del tenant
scp fatture/*.pdf utente@server:/var/www/html/wp-content/uploads/rbai/mario-pizzeria/pdf/
```

---

### Step 14 — Esegui il PDF Extractor

1. Vai su **Admin WordPress → Redbill AI → Strumenti**
   *(oppure il tenant: **Il Mio Account → Strumenti Importazione**)*
2. Seleziona il tenant (solo per super-admin)
3. Clicca **"▶ Esegui PDF Extractor"**

Il log in tempo reale mostrerà:

```
Elaboro: fattura_mario_2024_01.pdf
  ✅ Validazione superata - Dati completi
  OK — inserito nel DB.
  ✅ Spostato in processed/
Elaboro: fattura_mario_2024_02.pdf
  Duplicato — già presente nel DB.
  ✅ Spostato in processed/
==================================================
RIEPILOGO:
OK: 12
Duplicati: 3
Validazione fallita: 0
Errori DB: 0
```

I PDF vengono spostati automaticamente in:
- `/pdf/processed/` → elaborati con successo o duplicati
- `/pdf/failed/` → validazione fallita (campi obbligatori mancanti)

> ⚠️ **PDF con validazione fallita:** Glovo potrebbe aver cambiato il formato delle fatture.
> I PDF falliti vengono conservati in `failed/` per analisi manuale.

---

### Step 15 — Carica i file CSV degli ordini

```bash
# Copia i CSV di dettaglio ordini
scp ordini/*.csv utente@server:/var/www/html/wp-content/uploads/rbai/mario-pizzeria/csv/
```

---

### Step 16 — Esegui il CSV Importer

1. Dalla stessa pagina **Strumenti**, clicca **"▶ Esegui CSV Importer"**

Il log mostrerà:

```
Elaboro CSV: ordini_mario_2024_01.csv
  OK — inserite: 245 righe, duplicati: 0
Elaboro CSV: ordini_mario_2024_02.csv
  OK — inserite: 312 righe, duplicati: 5
==================================================
RIEPILOGO:
CSV processati: 2
Righe inserite: 557
Items inseriti: 1890
Duplicati: 5
Errori: 0
```

I CSV elaborati vengono spostati in `/csv/processed/`.

---

## 7. Setup Dashboard (Shortcode)

Crea le pagine WordPress che il tenant utilizzerà per visualizzare i propri dati.

### Step 17 — Crea le pagine con gli shortcode

Vai su **Admin WordPress → Pagine → Aggiungi Nuova** e crea una pagina per ogni shortcode.

> 💡 Puoi creare una pagina con più shortcode, oppure pagine separate.

| Pagina consigliata | Shortcode da inserire | Descrizione |
|-------------------|----------------------|-------------|
| Dashboard Principale | `[glovo_invoice_dashboard]` | KPI cards, grafici mensili, alert |
| Tabella Fatture | `[glovo_invoice_table]` | Tabella con filtri, dettaglio, PDF viewer |
| Dettagli Ordini | `[glovo_details_report]` | Report per negozio |
| Analytics Ordini | `[glovo_orders_dashboard]` | Dashboard ordini giornalieri/mensili |
| Analisi Costi | `[glovo_sales_costs_dashboard]` | Costi vs ricavi + analisi Gemini AI |
| Export CSV | `[glovo_products_csv_export]` | Bottone export prodotti |
| Carica File MSG | `[msg_uploader]` | Upload drag & drop file .msg / PDF |

**Esempio contenuto pagina "Dashboard Principale":**

```
[glovo_invoice_dashboard]
```

> ⚠️ Tutti gli shortcode mostrano automaticamente un messaggio di login
> se l'utente non è autenticato, e un messaggio di account sospeso
> se lo stato del tenant non è `active`.

---

### Step 18 — Imposta la visibilità delle pagine

Per limitare l'accesso alle sole pagine dashboard ai tenant:

1. Imposta ogni pagina come **Privata** (visibile solo agli utenti loggati)
2. Oppure usa un plugin come **WP User Manager** o **Restrict Content Pro** per gestire l'accesso per ruolo

> 💡 Il plugin gestisce internamente l'isolamento dati per tenant.
> Non è necessario nascondere le pagine: anche se un tenant B accedesse
> alla pagina di tenant A, vedrebbe solo i propri dati (o nessun dato).

---

## 8. Verifica Funzionamento

### Checklist post-installazione

```
□ Plugin attivato senza errori PHP nel log
□ Menu "Redbill AI" visibile in wp-admin
□ Tabella wp_rbai_tenants presente nel DB WordPress
□ Cartella /wp-content/uploads/rbai/ creata con .htaccess
□ Credenziali MySQL root salvate e testate
□ Almeno un tenant creato con status "active"
□ Database rbai_<slug> presente in MySQL
□ Le 4 tabelle create nel database tenant
□ Configurazione IMAP salvata e testata
□ PDF extractor ha elaborato almeno un PDF con successo
□ CSV importer ha importato almeno un CSV
□ Gli shortcode mostrano i dati del tenant loggato
□ I filtri AJAX funzionano nella tabella fatture
□ Il cron WP-Cron è attivo (wp_next_scheduled)
```

### Verifica WP-Cron

Per verificare che il cron per-tenant sia schedulato:

```bash
# Via WP-CLI
wp cron event list | grep rbai

# Output atteso:
# rbai_email_check_1    rbai_every_15min    2024-01-15 14:30:00
```

> ⚠️ **WP-Cron su hosting condiviso:** WordPress usa un cron pseudo-casuale (si attiva con le visite).
> Per ambienti di produzione è consigliabile disabilitare il cron WP e usare un cron di sistema:
>
> In `wp-config.php`:
> ```php
> define('DISABLE_WP_CRON', true);
> ```
>
> Poi aggiunge a crontab:
> ```bash
> */5 * * * * curl -s https://tuodominio.com/wp-cron.php?doing_wp_cron > /dev/null
> ```

---

## 9. Troubleshooting

### Errore: "Credenziali MySQL root non configurate"

**Causa:** La pagina Impostazioni non è stata compilata.
**Soluzione:**
1. Vai su **Redbill AI → Impostazioni**
2. Compila host, utente e password MySQL
3. Usa "Testa Connessione" per verificare

---

### Errore: "Access denied for user ... to database"

**Causa:** L'utente MySQL non ha i permessi necessari.
**Soluzione:**
```sql
GRANT ALL PRIVILEGES ON *.* TO 'tuo_utente'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

---

### Errore: "Estensione PHP IMAP non disponibile"

**Causa:** L'estensione PHP `imap` non è installata.
**Soluzione (Debian/Ubuntu):**
```bash
sudo apt install php8.1-imap
sudo systemctl restart apache2   # o nginx + php-fpm
```

**Soluzione (cPanel):**
Vai su cPanel → Seleziona versione PHP → Estensioni → Abilita `imap`

---

### Errore IMAP: "AUTHENTICATIONFAILED"

**Causa:** Credenziali errate o mancanza App Password.
**Soluzione:**
- **Gmail:** Usa **App Password** (non la password Google normale)
- **Outlook:** Verifica che l'accesso IMAP sia abilitato nelle impostazioni dell'account
- **Provider generico:** Verifica porta (993 con SSL, 143 senza)

---

### PDF extractor: validazione fallita per tutti i PDF

**Causa:** Il formato delle fatture Glovo potrebbe essere cambiato.
**Soluzione:**
1. Controlla i PDF nella cartella `pdf/failed/`
2. Controlla il log PHP (`/wp-content/debug.log` se `WP_DEBUG_LOG = true`)
3. Apri un PDF manualmente e verifica che contenga le voci standard Glovo
4. Se Glovo ha cambiato il formato, contatta il supporto Trimservice

---

### Dashboard shortcode non mostra dati

**Causa:** Possibili problemi: tenant non loggato, tenant sospeso, DB vuoto.
**Diagnosi:**
1. Verifica che l'utente sia loggato con le credenziali del tenant
2. Verifica che il tenant sia in stato **active** (Redbill AI → Tenant)
3. Verifica che ci siano dati nel database: `SELECT COUNT(*) FROM gsr_glovo_fatture;`
4. Apri la console del browser e controlla errori AJAX (tasto F12 → Network)

---

### Filtri AJAX non funzionano

**Causa:** Conflitto JavaScript o problema nonce.
**Soluzione:**
1. Verifica che non ci siano plugin che disabilitano jQuery
2. Controlla la console browser per errori JS
3. Verifica che la pagina abbia caricato `dashboard.js` (controlla il sorgente HTML)

---

### Errore: "vendor/autoload.php not found"

**Causa:** `composer install` non è stato eseguito.
**Soluzione:**
```bash
cd /var/www/html/wp-content/plugins/redbill-ai/
composer install --no-dev
```

---

## 10. Struttura Directory di Riferimento

```
wp-content/
├── plugins/
│   └── redbill-ai/
│       ├── redbill-ai.php              ← Entry point plugin
│       ├── uninstall.php               ← Pulizia dati alla disinstallazione
│       ├── composer.json               ← Dipendenze PHP
│       ├── vendor/                     ← Librerie Composer (smalot/pdfparser)
│       ├── INSTALL.md                  ← Questa guida
│       │
│       ├── includes/
│       │   ├── functions.php           ← Helper globali (encrypt, guards)
│       │   ├── class-redbill-ai.php    ← Loader principale
│       │   ├── class-redbill-installer.php  ← Activation hook, tabelle WP
│       │   ├── class-redbill-settings.php   ← Pagina impostazioni admin
│       │   │
│       │   ├── saas/
│       │   │   ├── class-rbai-tenant.php           ← Modello tenant
│       │   │   ├── class-rbai-tenant-provisioner.php ← Crea/elimina DB tenant
│       │   │   ├── class-rbai-tenant-manager.php   ← Admin UI gestione tenant
│       │   │   └── class-rbai-billing.php          ← Feature gates per piano
│       │   │
│       │   ├── dashboard/
│       │   │   ├── class-rbai-invoice-database.php ← Query DB fatture
│       │   │   ├── class-rbai-invoice-table.php    ← [glovo_invoice_table]
│       │   │   ├── class-rbai-invoice-dashboard.php ← [glovo_invoice_dashboard]
│       │   │   ├── class-rbai-details-report.php   ← [glovo_details_report]
│       │   │   ├── class-rbai-orders-dashboard.php ← [glovo_orders_dashboard]
│       │   │   ├── class-rbai-sales-costs.php      ← [glovo_sales_costs_dashboard]
│       │   │   ├── class-rbai-products-csv-export.php ← [glovo_products_csv_export]
│       │   │   └── class-rbai-email-analysis.php   ← Analisi Gemini email
│       │   │
│       │   ├── estrattore/
│       │   │   ├── class-rbai-msg-parser.php       ← Parser OLE .msg
│       │   │   ├── class-rbai-email-reader.php     ← IMAP reader tenant-aware
│       │   │   ├── class-rbai-estrattore-frontend.php ← [msg_uploader]
│       │   │   └── class-rbai-estrattore-admin.php ← Config IMAP self-service
│       │   │
│       │   └── tools/
│       │       ├── class-rbai-pdf-extractor.php    ← Estrae dati da PDF fatture
│       │       ├── class-rbai-csv-importer.php     ← Importa CSV ordini
│       │       └── class-rbai-tools-admin.php      ← UI + AJAX log strumenti
│       │
│       ├── assets/
│       │   ├── css/
│       │   │   ├── admin.css           ← Stili wp-admin
│       │   │   ├── frontend.css        ← Stili drag & drop MSG
│       │   │   └── dashboard.css       ← Stili shortcode dashboard
│       │   └── js/
│       │       ├── frontend.js         ← Upload drag & drop
│       │       ├── dashboard.js        ← Filtri AJAX, Chart.js, Gemini
│       │       └── tools.js            ← Polling log esecuzione
│       │
│       └── prompts/
│           ├── gemini-analysis-prompt.txt    ← Prompt analisi mensile
│           └── gemini-comparison-prompt.txt  ← Prompt confronto periodi
│
└── uploads/
    └── rbai/
        └── mario-pizzeria/             ← Una cartella per ogni tenant
            ├── .htaccess               ← Protegge accesso diretto
            ├── pdf/
            │   ├── *.pdf               ← PDF in attesa di elaborazione
            │   ├── processed/          ← PDF elaborati con successo
            │   └── failed/             ← PDF con errori di estrazione
            └── csv/
                ├── *.csv               ← CSV in attesa di importazione
                └── processed/          ← CSV importati con successo
```

---

## Piani e Funzionalità

| Funzionalità | Basic | Pro | Enterprise |
|-------------|-------|-----|-----------|
| Dashboard fatture | ✅ | ✅ | ✅ |
| Tabella fatture con filtri | ✅ | ✅ | ✅ |
| Export CSV | ✅ | ✅ | ✅ |
| PDF extractor | ✅ | ✅ | ✅ |
| IMAP automatico | ✅ | ✅ | ✅ |
| Analisi Gemini AI | ❌ | ✅ | ✅ |
| Analisi email Gemini | ❌ | ✅ | ✅ |
| Limite fatture/mese | 500 | Illimitato | Illimitato |
| Supporto prioritario | ❌ | ❌ | ✅ |

---

## Ciclo di Vita Tenant

```
Utente WP creato
       │
       ▼
Super-admin crea tenant manuale  ──OR──  Auto-registrazione (se auto_approve = ON)
       │                                           │
       ▼                                           ▼
status: pending                            status: active
       │                                           │
       ▼ (super-admin approva)                     │
RBAI_Tenant_Provisioner::provision()  ◄────────────┘
       │
       ├── Crea database MySQL rbai_<slug>
       ├── Crea utente MySQL rbai_<slug_12char>
       ├── Crea 4 tabelle nel DB tenant
       ├── Crea cartelle upload
       └── Schedula cron email
       │
       ▼
status: active ──────────────────────────────────────────┐
       │                                                 │
       ▼ (super-admin sospende)                          │
status: suspended → cron rimosso                         │
       │                                                 │
       ▼ (super-admin riattiva)                          │
status: active ◄─────────────────────────────────────────┘
       │
       ▼ (super-admin elimina)
RBAI_Tenant_Provisioner::deprovision()
       ├── DROP DATABASE rbai_<slug>
       ├── DROP USER rbai_<slug>
       ├── Elimina cartelle upload
       └── Elimina record wp_rbai_tenants
```

---

*Documento generato automaticamente — Redbill AI v1.0.0*
*Repository: https://github.com/petrecca-trimservice/plugin-Redbill-AI*
