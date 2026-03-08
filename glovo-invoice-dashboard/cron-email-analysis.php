<?php
/**
 * Cron Email Analysis — Script standalone per Plesk Azioni Programmate.
 *
 * Utilizzo in Plesk:
 *   Tipo: Esegui uno script PHP
 *   Percorso: /path/to/wp-content/plugins/glovo-invoice-dashboard/cron-email-analysis.php
 *   Frequenza: ogni giorno
 *
 * Lo script fa il bootstrap di WordPress, controlla se ci sono nuove fatture
 * nel database e, se sì, invia l'analisi comparativa via email.
 *
 * Può essere eseguito anche da CLI:
 *   php cron-email-analysis.php
 */

// Impedisci accesso diretto via browser
if ( php_sapi_name() !== 'cli' && ! isset( $_SERVER['PLESK_CLI'] ) ) {
    // Consenti esecuzione anche da Plesk scheduler che potrebbe usare CGI
    // ma blocca accesso diretto via URL per sicurezza
    if ( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], 'cron-email-analysis' ) !== false ) {
        // Verifica se c'è un token di sicurezza
        $expected_token = defined( 'GID_CRON_TOKEN' ) ? GID_CRON_TOKEN : '';
        $provided_token = isset( $_GET['token'] ) ? $_GET['token'] : '';
        if ( $expected_token === '' || $provided_token !== $expected_token ) {
            http_response_code( 403 );
            die( 'Accesso non consentito.' );
        }
    }
}

// Bootstrap WordPress
$wp_load_path = find_wp_load( __DIR__ );
if ( ! $wp_load_path ) {
    echo "ERRORE: impossibile trovare wp-load.php\n";
    exit( 1 );
}

require_once $wp_load_path;

// Esegui il check
echo "[" . date( 'Y-m-d H:i:s' ) . "] GID Email Analysis Cron - Inizio\n";

if ( ! class_exists( 'GID_Email_Analysis' ) ) {
    echo "ERRORE: classe GID_Email_Analysis non trovata. Verificare che il plugin sia attivo.\n";
    exit( 1 );
}

$result = GID_Email_Analysis::check_new_invoices();

echo "[" . date( 'Y-m-d H:i:s' ) . "] Risultato: " . ( $result['success'] ? 'OK' : 'SKIP' ) . " — " . $result['message'] . "\n";
echo "[" . date( 'Y-m-d H:i:s' ) . "] GID Email Analysis Cron - Fine\n";

exit( $result['success'] ? 0 : 0 ); // Exit 0 anche se non ci sono novità (non è un errore)

// -------------------------------------------------------------------------
// Helper: cerca wp-load.php risalendo le directory
// -------------------------------------------------------------------------
function find_wp_load( $start_dir ) {
    $dir = $start_dir;
    for ( $i = 0; $i < 10; $i++ ) {
        $path = $dir . '/wp-load.php';
        if ( file_exists( $path ) ) {
            return $path;
        }
        $parent = dirname( $dir );
        if ( $parent === $dir ) {
            break; // Raggiunta la root del filesystem
        }
        $dir = $parent;
    }
    return false;
}
