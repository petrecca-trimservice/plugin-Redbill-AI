# Estrattore Email Glovo v8.0

Plugin WordPress per l'estrazione automatica di allegati PDF e CSV da email Glovo (file MSG e IMAP).

## Funzionalita

### 1. Caricamento File MSG (Drag & Drop)
- Interfaccia moderna con drag & drop
- Supporto upload multiplo (fino a 100 file)
- Parser nativo formato OLE/MSG
- Estrazione automatica allegati PDF e CSV

### 2. Lettura Automatica Email IMAP
- Connessione a qualsiasi server IMAP (Gmail, Outlook, Yahoo, ecc.)
- Lettura email non lette
- Estrazione automatica allegati
- Opzione per marcare email come lette

### 3. Filtri Avanzati
- **Filtro mittenti**: elabora solo email da indirizzi specifici
- **Filtro oggetto**: elabora solo email con parole chiave nell'oggetto
- **Filtro estensioni**: scarica solo tipi di file specificati (default: PDF, CSV)

## Installazione

1. Caricare la cartella del plugin in `/wp-content/plugins/`
2. Attivare il plugin dal menu "Plugin" di WordPress
3. Configurare le impostazioni email da "Estrattore Glovo" nel menu admin

## Requisiti

- WordPress 5.0+
- PHP 7.4+
- Estensione PHP IMAP (per la lettura automatica email)

## Configurazione IMAP

### Gmail
- Server: `imap.gmail.com`
- Porta: `993`
- SSL: Si
- Password: Usare "App Password" (Impostazioni Google > Sicurezza > Verifica in 2 passaggi > App Password)

### Outlook / Office 365
- Server: `outlook.office365.com`
- Porta: `993`
- SSL: Si

### Yahoo
- Server: `imap.mail.yahoo.com`
- Porta: `993`
- SSL: Si

## Utilizzo

### Shortcode
Inserire lo shortcode in qualsiasi pagina o post:
```
[msg_uploader]
```

### Directory Allegati
Gli allegati estratti vengono salvati in:
- PDF: `/wp-content/uploads/msg-extracted/pdf/`
- CSV: `/wp-content/uploads/msg-extracted/csv/`

## Struttura File

```
msg-extractor/
├── wp-msg-extractor.php      # File principale plugin
├── .user.ini                  # Configurazione PHP
├── README.md                  # Documentazione
├── assets/
│   ├── css/
│   │   ├── admin.css         # Stili pannello admin
│   │   └── frontend.css      # Stili interfaccia utente
│   └── js/
│       └── frontend.js       # JavaScript drag & drop
└── includes/
    ├── class-admin.php       # Gestione pannello admin
    ├── class-email-reader.php # Lettore IMAP
    ├── class-frontend.php    # Interfaccia frontend
    └── class-msg-parser.php  # Parser file MSG
```

## Sicurezza

- Protezione CSRF con WordPress nonce
- Sanitizzazione input utente
- Escaping output HTML
- Validazione email e estensioni file

## Changelog

### v8.0
- Interfaccia grafica moderna
- Supporto filtri avanzati (mittente, oggetto, estensione)
- Statistiche dettagliate elaborazione
- Fix gestione file senza estensione
- Ottimizzazione lettura header IMAP

## Autore

Trimservice AI

## Licenza

Proprietario - Tutti i diritti riservati
