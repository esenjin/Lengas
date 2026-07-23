<?php
require_once 'config.php';
register_session_handler();
session_start();

if (!($_SESSION['logged_in'] ?? false)) {
    header('Location: login.php');
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
