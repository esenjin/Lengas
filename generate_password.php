<?php
// EFFACER CE FICHIER DE VOTRE SERVEUR APRÈS UTILISATION !
// Ce script génère le hash du mot de passe administrateur dans la base SQLite.
//
// Par sécurité, il ne fonctionne QUE :
//   - pour la toute première définition du mot de passe (installation neuve,
//     base sans mot de passe) ;
//   - ou, en cas d'oubli, si un fichier vide nommé RESET_PASSWORD a été déposé
//     dans le dossier bdd/ (ce qui prouve un accès au serveur). Ce fichier est
//     supprimé automatiquement après usage.
// Oublié sur le serveur, il ne permet donc plus à un visiteur de réinitialiser
// le mot de passe de l'administrateur. Pour changer un mot de passe connu,
// passez par la page « Options » de l'administration.

$password = 'mot_de_passe'; // <-- Remplace par ton mot de passe actuel

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$reset_flag = __DIR__ . '/bdd/RESET_PASSWORD';
$is_reset   = is_file($reset_flag);

if (load_password_hash() !== null && !$is_reset) {
    http_response_code(403);
    echo "Un mot de passe administrateur est déjà défini : ce script est désactivé. "
       . "Changez le mot de passe depuis la page « Options » de l'administration "
       . "(ou, en cas d'oubli, déposez un fichier vide nommé RESET_PASSWORD dans le dossier bdd/), "
       . "et SUPPRIMEZ CE FICHIER DU SERVEUR.";
    exit;
}

if ($password === 'mot_de_passe' || strlen($password) < 8) {
    http_response_code(400);
    echo "Éditez d'abord ce fichier pour y indiquer votre propre mot de passe (8 caractères minimum), puis rechargez cette page.";
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
save_password_hash($hash);

if ($is_reset) {
    // Réinitialisation : usage unique, et toutes les sessions ouvertes avec
    // l'ancien mot de passe sont fermées.
    @unlink($reset_flag);
    invalidate_other_sessions();
}

echo "Mot de passe enregistré avec succès dans la base SQLite. N'OUBLIE PAS DE SUPPRIMER CE FICHIER APRÈS UTILISATION !";
