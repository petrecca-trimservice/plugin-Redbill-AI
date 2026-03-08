-- Controlla la fattura specifica I25025GF6I000001
SELECT
    id,
    file_pdf,
    n_fattura,
    data,
    importo_bonifico,
    HEX(importo_bonifico) AS hex_value,
    LENGTH(importo_bonifico) AS lunghezza,
    ASCII(SUBSTRING(importo_bonifico, 1, 1)) AS primo_char_ascii
FROM gsr_glovo_fatture
WHERE n_fattura = 'I25025GF6I000001';

-- Vedi tutte le fatture più recenti con i loro importo_bonifico
SELECT
    n_fattura,
    data,
    importo_bonifico,
    LENGTH(importo_bonifico) AS lunghezza
FROM gsr_glovo_fatture
ORDER BY data DESC
LIMIT 10;
