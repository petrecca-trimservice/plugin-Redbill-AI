<?php
/**
 * Configurazione Database Glovo
 *
 * Questo file contiene le credenziali per accedere al database delle fatture Glovo.
 * Il database è separato dal database di WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'db_host' => 'localhost',
    'db_name' => 'dash_glovo',
    'db_user' => 'your_db_user',      // Modifica con il tuo username
    'db_pass' => 'your_db_password',  // Modifica con la tua password
    'db_table' => 'gsr_glovo_fatture',
    'db_charset' => 'utf8mb4'
);
