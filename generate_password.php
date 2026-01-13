<?php
// EFFACER CE FICHIER DE VOTRE SERVEUR APRÈS UTILISATION !
// Ce script génère le fichier mdp.json avec le hash du mot de passe administrateur
$password = 'mot_de_passe'; // <-- Remplace par ton mot de passe actuel
$hash = password_hash($password, PASSWORD_DEFAULT);
$password_data = ['admin_password_hash' => $hash];
file_put_contents('bdd/mdp.json', json_encode($password_data, JSON_PRETTY_PRINT));
echo "Fichier mdp.json créé avec succès. N'OUBLIE PAS DE SUPPRIMER CE FICHIER APRÈS UTILISATION !";
?>