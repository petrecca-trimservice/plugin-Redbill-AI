<?php
$config = require __DIR__ . '/config-glovo.php';

$mysqli = new mysqli(
    $config['db_host'],
    $config['db_user'],
    $config['db_pass'],
    $config['db_name']
);

if ($mysqli->connect_errno) {
    die("Errore connessione: " . $mysqli->connect_error);
}

$table = $config['db_table'];

$res = $mysqli->query("SHOW TABLES LIKE '$table'");

if ($res && $res->num_rows > 0) {
    echo "OK: Tabella '$table' esiste!";
} else {
    echo "ERRORE: Tabella '$table' NON TROVATA!";
}
