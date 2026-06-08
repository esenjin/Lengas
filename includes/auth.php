<?php
const SESSION_LIFETIME = 7 * 24 * 60 * 60; // 7 jours en secondes

ini_set('session.gc_maxlifetime', SESSION_LIFETIME);

session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Vérifier si la session est expirée (inactivité > 7 jours)
if ($_SESSION['logged_in'] ?? false) {
    $last = $_SESSION['last_activity'] ?? 0;
    if (time() - $last > SESSION_LIFETIME) {
        // Session trop ancienne : on déconnecte
        session_unset();
        session_destroy();
        header('Location: login.php?expired=1');
        exit;
    }
    // Renouveler le délai à chaque requête
    $_SESSION['last_activity'] = time();
} else {
    header('Location: login.php');
    exit;
}
