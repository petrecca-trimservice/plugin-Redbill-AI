# Export Prodotti CSV - Documentazione

## Descrizione

Questo modulo permette di esportare l'elenco completo di tutti i prodotti presenti nei dettagli ordini in formato CSV.

## Utilizzo

### Shortcode WordPress

Per visualizzare il pulsante di export in una pagina WordPress, inserisci lo shortcode:

```
[glovo_products_csv_export]
```

### Cosa viene esportato

Il file CSV contiene i seguenti dati per ogni prodotto:

- **Nome Prodotto**: Il nome del prodotto
- **Quantità Totale Venduta**: La somma di tutte le quantità vendute
- **Numero Ordini**: Il numero di ordini in cui compare il prodotto
- **Primo Ordine**: La data del primo ordine contenente il prodotto
- **Ultimo Ordine**: La data dell'ultimo ordine contenente il prodotto
- **Numero Negozi**: Il numero di negozi diversi che hanno venduto il prodotto

### Formato CSV

- **Delimitatore**: `;` (punto e virgola)
- **Encoding**: UTF-8 con BOM (compatibile con Excel)
- **Nome file**: `prodotti-glovo-YYYY-MM-DD-HHmmss.csv`

### Ordinamento

I prodotti sono ordinati per quantità totale venduta (dal più venduto al meno venduto).

## Requisiti Tecnici

- WordPress attivo
- Plugin Glovo Invoice Dashboard installato e attivato
- Accesso al database `dash_glovo` con tabelle:
  - `gsr_glovo_dettagli` (ordini)
  - `gsr_glovo_dettagli_items` (prodotti per ordine)

## Sicurezza

L'export è protetto da WordPress nonce per prevenire richieste non autorizzate.

## Esempio di utilizzo

1. Crea una nuova pagina WordPress
2. Inserisci lo shortcode `[glovo_products_csv_export]`
3. Pubblica la pagina
4. Gli utenti autorizzati potranno cliccare sul pulsante "Scarica Prodotti CSV" per scaricare il file

## File Coinvolti

- `/includes/class-products-csv-export.php` - Classe principale per l'export
- `/glovo-invoice-dashboard.php` - Registrazione shortcode e inizializzazione classe
