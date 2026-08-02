<?php
require_once 'config.php';
register_session_handler();
session_start();

if (!($_SESSION['logged_in'] ?? false)) {
    // La redirection doit rester valide quelle que soit la profondeur de la
    // page qui a inclus ce fichier (racine, pages/, pages/outils/…). Comme
    // chaque page appelante fait un chdir() vers la racine du projet avant
    // d'inclure auth.php, le chemin de la requête HTTP (SCRIPT_NAME) permet
    // de calculer le bon préfixe relatif vers login.php, à la racine.
    $__script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $__depth      = 0;
    if (preg_match('#/pages/outils/?$#', $__script_dir)) {
        $__depth = 2;
    } elseif (preg_match('#/pages/?$#', $__script_dir)) {
        $__depth = 1;
    }
    header('Location: ' . str_repeat('../', $__depth) . 'login.php');
    exit;
}

// Fenêtre « 7 jours glissants » côté CLIENT.
// PHP n'émet le cookie de session qu'UNE seule fois (à la connexion) et ne le
// prolonge jamais de lui-même : sans intervention, il expire donc 7 jours après
// la connexion, quelle que soit l'activité. On le ré-émet ici à chaque requête
// authentifiée, avec un nouveau délai de 7 jours, pour qu'il glisse en phase
// avec last_active (mis à jour côté serveur par SqliteSessionHandler::write()).
// C'est aussi ce qui « répare » automatiquement un cookie Vestikan qui aurait
// été raccourci lors de l'aller-retour OAuth : dès la visite suivante, il est
// remis à 7 jours pleins.
refresh_session_cookie();
