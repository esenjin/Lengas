<?php
// Mettre à jour les options du site
function update_options($options, $admin_password = null, $default_image = null) {
    if ($default_image !== null) {
        $options['default_image'] = $default_image;
    }
    
    if (empty($options['site_name']) || empty($options['site_description']) || empty($options['index_page_title']) || empty($options['admin_page_title']) || empty($options['stats_page_title'])) {
        return ['success' => false, 'message' => 'Veuillez remplir tous les champs obligatoires.'];
    }

    // Mettre à jour le mot de passe uniquement s'il n'est pas vide
    if (!empty($admin_password)) {
        // Limiter les caractères autorisés pour le mot de passe
        if (!preg_match('/^[a-zA-Z0-9_!@#$%^&*()\-+=\[\]{};:\'"\\|,.<>\/?]+$/', $admin_password)) {
            return ['success' => false, 'message' => 'Le mot de passe contient des caractères non autorisés.'];
        }

        // Hasher le mot de passe
        $password_hash = password_hash($admin_password, PASSWORD_DEFAULT);

        // Sauvegarder le hash dans bdd/mdp.json
        $password_data = ['admin_password_hash' => $password_hash];
        file_put_contents(PASSWORD_FILE, json_encode($password_data, JSON_PRETTY_PRINT));
    }

    save_options($options);
    return ['success' => true, 'message' => 'Options mises à jour avec succès'];
}