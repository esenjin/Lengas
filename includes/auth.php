<?php
require_once 'config.php';
register_session_handler();
session_start();

if (!($_SESSION['logged_in'] ?? false)) {
    header('Location: login.php');
    exit;
}
// Le renouvellement du délai est automatique : SqliteSessionHandler::write()
// met à jour last_active à chaque requête via session_write_close() implicite.