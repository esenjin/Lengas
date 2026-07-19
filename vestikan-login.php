<?php
/**
 * Démarre la connexion « Se connecter avec Vestikan ».
 * Le SDK génère le state anti-CSRF et redirige vers l'IdP ; on ne revient pas ici.
 */
require 'config.php';
require 'includes/vestikan.php';

// Sécurité : si le SSO n'est pas configuré, on renvoie sur le login classique.
if (!vestikan_enabled()) {
    header('Location: login.php');
    exit;
}

// Le SDK a besoin de la session du site pour mémoriser le state entre
// l'aller (begin) et le retour (callback). On réutilise le handler SQLite
// de Lengas pour rester cohérent avec le reste de l'application.
register_session_handler();

// Après connexion, on veut atterrir sur l'admin.
vestikan_client()->begin('admin.php'); // redirige vers Vestikan ; ne revient pas
