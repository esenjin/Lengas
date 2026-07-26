<?php
/**
 * Démarre la connexion « Se connecter avec Vestikan ».
 * Le SDK génère le state anti-CSRF et redirige vers l'IdP ; on ne revient pas ici.
 */
require __DIR__ . '/../config.php';
// Rétablit le dossier de travail à la racine du projet : ce script vit dans
// vestikan/ mais config.php et get_db() résolvent leurs chemins (bdd/, uploads/…)
// depuis la racine.
chdir(__DIR__ . '/..');

require __DIR__ . '/vestikan.php';

// Sécurité : si le SSO n'est pas configuré, on renvoie sur le login classique.
if (!vestikan_enabled()) {
    header('Location: ../login.php');
    exit;
}

// Le SDK a besoin de la session du site pour mémoriser le state entre
// l'aller (begin) et le retour (callback). On réutilise le handler SQLite
// de Lengas pour rester cohérent avec le reste de l'application.
register_session_handler();

// Après connexion, on veut atterrir sur l'admin.
vestikan_client()->begin('../admin.php'); // redirige vers Vestikan ; ne revient pas
