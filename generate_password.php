<?php
// EFFACER CE FICHIER DE VOTRE SERVEUR APRÈS UTILISATION !
// Ce script génère le hash du mot de passe administrateur dans la base SQLite.

$password = 'mot_de_passe'; // <-- Remplace par ton mot de passe actuel

require_once __DIR__ . '/config.php';

$hash = password_hash($password, PASSWORD_DEFAULT);
save_password_hash($hash);

echo "Mot de passe enregistré avec succès dans la base SQLite. N'OUBLIE PAS DE SUPPRIMER CE FICHIER APRÈS UTILISATION !";
