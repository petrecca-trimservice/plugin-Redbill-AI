# Sistema di Validazione e Alert per Estrazione Fatture Glovo

## Nuove Funzionalità Implementate

Questo documento descrive il sistema di validazione automatica aggiunto allo script `estrai_fatture_glovo.php` per rilevare problemi di estrazione e cambiamenti nel layout delle fatture PDF.

---

## 1. Validazione Campi Obbligatori

### Campi Controllati

Lo script verifica che i seguenti **15 campi critici** siano presenti:

**Identificativi (6 campi):**
- **n_fattura** - Numero fattura univoco (100% delle fatture)
- **data** - Data emissione (100%)
- **destinatario** - Intestatario (100%)
- **negozio** - Nome negozio (100%)
- **periodo_da** - Inizio periodo riferimento (100%)
- **periodo_a** - Fine periodo riferimento (100%)

**Sezione Tariffe (5 campi):**
- **commissioni** - Commissioni Glovo (100%)
- **marketing_visibilita** - Costi marketing/visibilità (93.8%)
- **subtotale** - Subtotale (100%)
- **iva_22** - IVA 22% (100%)
- **totale_fattura_iva_inclusa** - Totale IVA inclusa (100%)

**Sezione Riepilogo (4 campi):**
- **prodotti** - Vendita prodotti (100%)
- **totale_fattura_riepilogo** - Totale riepilogo (100%)
- **promo_prodotti_partner** - Promozioni partner (99.6%)
- **importo_bonifico** - Importo finale (98.5%)

> **Nota**: Le percentuali indicano la presenza di ogni campo nelle 466 fatture analizzate nel database.

### Validazioni Aggiuntive

- `n_fattura` deve avere almeno 8 caratteri
- `data` deve essere nel formato YYYY-MM-DD

### Comportamento

Se **anche solo uno** di questi campi manca:
- ❌ Il PDF **NON viene salvato** nel database né nel CSV
- 📁 Il PDF viene spostato in `failed/` invece di `processed/`
- 📝 L'errore viene registrato nel log
- 📧 Viene inviata una email di alert (se configurata)

---

## 2. Log degli Errori

### File di Log

Tutti gli errori di estrazione vengono registrati in:
```
estrazione_errori.log
```

### Formato del Log

```
================================================================================
[2026-01-21 10:30:45] ERRORE ESTRAZIONE
File PDF: fattura_ABC12345.pdf
--------------------------------------------------------------------------------
Errori rilevati:
  1. Campo 'n_fattura' mancante o vuoto
  2. Campo 'data' mancante o vuoto
--------------------------------------------------------------------------------
Dati estratti (parziali):
  - destinatario: KARMA S.R.L.
  - negozio: Milano
  - n_fattura: NULL
  - data: NULL
  - totale_fattura_iva_inclusa: 150.50
  ...
================================================================================
```

### Quando Controllare il Log

- Quando ricevi una email di alert
- Se noti che alcuni PDF sono stati spostati in `failed/`
- Periodicamente per monitorare la salute del sistema

---

## 3. Cartelle PDF

### Struttura Cartelle

```
pdf/
├── fattura1.pdf          ← PDF da elaborare
├── fattura2.pdf
├── processed/            ← PDF elaborati con successo
│   └── fattura1.pdf
└── failed/               ← PDF con errori di estrazione (NUOVO!)
    └── fattura2.pdf
```

### Cartella `failed/`

Contiene i PDF che **non sono passati la validazione**:
- Dati mancanti o incompleti
- Possibile cambio di layout da parte di Glovo
- Errori di parsing

**IMPORTANTE**: I PDF in `failed/` richiedono attenzione manuale!

---

## 4. Email di Notifica

### Configurazione Email

Modifica il file `config-glovo.php`:

**OPZIONE 1: Singola email**
```php
return [
    // ... altre configurazioni ...

    // Email per notifiche errori di estrazione
    'alert_email' => 'admin@esempio.com', // ← CAMBIA QUI!
];
```

**OPZIONE 2: Più email (array)**
```php
return [
    // ... altre configurazioni ...

    // Email per notifiche errori di estrazione
    'alert_email' => [
        'admin@esempio.com',
        'contabilita@esempio.com',
    ],
];
```

**NOTA**:
- Puoi usare sia una singola email (stringa) che più email (array)
- Se lasci `'tua@email.com'` o se il campo è vuoto, le email **non verranno inviate** (ma il log verrà comunque scritto)
- Con più email, ognuna riceverà una copia dell'alert

### Tipologie di Email

Il sistema invia **DUE tipi di email** al termine di ogni esecuzione:

#### 1. Email Errori Raggruppati (solo se ci sono errori)
Inviata **UNA SOLA VOLTA** al termine dell'elaborazione con la lista di **TUTTI** i PDF che hanno fallito la validazione:

```
Oggetto: ⚠️ Errori validazione fatture Glovo - 3 PDF falliti

ATTENZIONE: Errori durante l'estrazione dei dati dalle fatture PDF.

Data/ora: 2026-01-22 10:30:45
Totale PDF con errori: 3

======================================================================

PDF #1: fattura_ABC12345.pdf
----------------------------------------------------------------------
Errori rilevati:
  1. Campo 'marketing_visibilita' mancante o vuoto
  2. Campo 'promo_prodotti_partner' mancante o vuoto

PDF #2: fattura_XYZ98765.pdf
----------------------------------------------------------------------
Errori rilevati:
  1. Campo 'commissioni' mancante o vuoto
  2. Campo 'subtotale' mancante o vuoto
  3. Campo 'iva_22' mancante o vuoto

PDF #3: fattura_LMN54321.pdf
----------------------------------------------------------------------
Errori rilevati:
  1. Campo 'importo_bonifico' mancante o vuoto

======================================================================

POSSIBILE CAUSA:
• Cambio del layout delle fatture PDF da parte di Glovo
• Fatture con formato diverso dal solito

AZIONE RICHIESTA:
1. Verificare i PDF nella cartella: failed/
2. Controllare il log dettagliato: estrazione_errori.log
3. Se necessario, aggiornare i pattern regex di estrazione

FILE SPOSTATI IN 'failed/':
• fattura_ABC12345.pdf
• fattura_XYZ98765.pdf
• fattura_LMN54321.pdf
```

#### 2. Email Riassuntiva Finale (sempre)
Inviata al termine di ogni esecuzione dello script con il riepilogo completo:

```
Oggetto: ✅ Riepilogo elaborazione fatture Glovo - 2026-01-21 10:35

RIEPILOGO ELABORAZIONE FATTURE GLOVO
============================================================

Data/ora: 2026-01-21 10:35:42
Stato: Elaborazione completata

------------------------------------------------------------
STATISTICHE
------------------------------------------------------------
Totale PDF trovati:         48
✅ Processati con successo: 45
ℹ️  Duplicati (saltati):     3
❌ Validazione fallita:     0
❌ Errori database:         0
------------------------------------------------------------

✅ Elaborazione completata senza errori

------------------------------------------------------------
FILE GENERATI
------------------------------------------------------------
• CSV: fatture_glovo_estratte.csv

------------------------------------------------------------
CARTELLE
------------------------------------------------------------
• PDF processati: pdf/processed/ (48 file)

============================================================
Questo è un messaggio automatico dal sistema di estrazione fatture Glovo.
```

**Esempio con errori:**
```
Oggetto: ⚠️ Riepilogo elaborazione fatture Glovo - 2026-01-21 10:35

[...]
Stato: Elaborazione con errori

Totale PDF trovati:         48
✅ Processati con successo: 43
ℹ️  Duplicati (saltati):     3
❌ Validazione fallita:     2
❌ Errori database:         0

⚠️  ATTENZIONE - AZIONI RICHIESTE:

• 2 PDF con errori di validazione
  → Controlla cartella: failed/
  → Controlla log: estrazione_errori.log
  → Possibile cambio layout PDF da parte di Glovo
[...]
```

---

## 5. Output dello Script

### Output Esempio (Successo)

```
Elaboro: fattura_ABC12345.pdf
  ✅ Validazione superata - Dati completi
  ✅ Spostato in processed/
```

### Output Esempio (Duplicato)

```
Elaboro: fattura_ABC12345.pdf
  ✅ Validazione superata - Dati completi
  ℹ️  Duplicato (n_fattura già esistente), salto inserimento DB e CSV.
  ✅ Spostato in processed/
```

**Nota**: Le fatture duplicate NON vengono salvate né nel database né nel CSV, ma vengono comunque spostate in `processed/` perché già elaborate in precedenza.

### Output Esempio (Errore)

```
Elaboro: fattura_XYZ98765.pdf
  ❌ VALIDAZIONE FALLITA - Dati incompleti o mancanti!
     - Campo 'n_fattura' mancante o vuoto
     - Campo 'data' mancante o vuoto
  📝 Errore registrato in: estrazione_errori.log
  📁 PDF spostato in: failed/fattura_XYZ98765.pdf
  ⏭️  Salto inserimento DB e CSV per questo file.
  📧 Errori saranno inclusi nell'email riassuntiva finale
```

### Riepilogo Finale con Invio Email

```
============================================================
RIEPILOGO ELABORAZIONE
============================================================
✅ Processati con successo: 43
❌ Validazione fallita:     2
ℹ️  Duplicati:               3
❌ Errori database:         0
============================================================
⚠️  ATTENZIONE: 2 PDF con errori di estrazione!
   Controlla la cartella 'failed/' e il file 'estrazione_errori.log'
============================================================

📄 CSV generato: /home/user/scripts-Glovo/fatture_glovo_estratte.csv
✅ Elaborazione completata.

📧 Invio email errori raggruppati...
   ✅ Email errori inviata a: admin@esempio.com, contabilita@esempio.com
   (2 PDF con errori)
   ⏳ Attesa 3 secondi prima della email riassuntiva...

📧 Invio email riassuntiva finale...
   ✅ Email riassuntiva inviata a: admin@esempio.com, contabilita@esempio.com
```

**Nota**: Le email vengono inviate con un ritardo di 3 secondi tra l'una e l'altra per evitare rate limiting del server mail.

---

## 6. Cosa Fare in Caso di Errori

### Scenario: Ricevi Email di Alert

1. **Controlla il PDF in `failed/`**
   - Apri manualmente il PDF problematico
   - Verifica se il layout è diverso dal solito

2. **Controlla il log `estrazione_errori.log`**
   - Guarda quali campi non sono stati estratti
   - Confronta con i dati visibili nel PDF

3. **Se il layout di Glovo è cambiato:**
   - Contattami per aggiornare i pattern regex in `estrai_fatture_glovo.php`
   - I pattern sono nelle righe 232-272

4. **Se è un problema temporaneo:**
   - Il PDF potrebbe essere corrotto
   - Richiedi una nuova copia della fattura a Glovo

---

## 7. Vantaggi del Sistema

### Prima (Senza Validazione)

❌ Dati NULL salvati silenziosamente nel database
❌ Scopri i problemi dopo giorni/settimane
❌ Devi controllare manualmente ogni fattura
❌ Rischio di perdere dati importanti

### Ora (Con Validazione)

✅ Errori rilevati **immediatamente**
✅ **1 email raggruppata** con tutti gli errori (no spam)
✅ Email riassuntiva finale sempre inviata
✅ Log dettagliato degli errori
✅ PDF problematici isolati in `failed/`
✅ Zero dati incompleti nel database

---

## 8. Manutenzione

### Controlli Periodici Consigliati

- **Giornaliero**: Controlla se ci sono PDF in `failed/`
- **Settimanale**: Rivedi il file `estrazione_errori.log`
- **Mensile**: Verifica che l'email sia correttamente configurata

### Pulizia

```bash
# Pulisci il log vecchio (opzionale)
> estrazione_errori.log

# Sposta PDF risolti da failed/ a PDF principale per ri-elaborazione
mv failed/fattura_risolta.pdf ../pdf/
```

---

## 9. Supporto Tecnico

Se riscontri problemi o hai domande:

1. Controlla prima `estrazione_errori.log`
2. Verifica la configurazione email in `config-glovo.php`
3. Controlla che le cartelle `processed/` e `failed/` esistano e siano scrivibili

---

**Versione**: 1.0
**Data**: 2026-01-21
**Autore**: Sistema di validazione automatica
