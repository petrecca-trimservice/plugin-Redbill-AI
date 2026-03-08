<?php

// Config DB per l'estrazione fatture Glovo
// Metti qui i dati del DB che hai creato

return [
    'db_host'  => 'localhost',
    'db_name'  => 'dash_glovo',
    'db_user'  => 'dev_gsr_glovo',
    'db_pass'  => 'huy!s%iiI05E5nKx',
    'db_table' => 'gsr_glovo_fatture', // o il nome che hai scelto

    // Email per notifiche errori di estrazione
    // Supporta sia una singola email (stringa) che più email (array)

    // OPZIONE 1: Singola email
    'alert_email' => 'admin@esempio.com',

    // OPZIONE 2: Più email (array)
    // 'alert_email' => [
    //     'admin@esempio.com',
    //     'contabilita@esempio.com',
    // ],
];
