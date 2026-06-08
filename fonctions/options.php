<?php
// Mettre à jour les options du site
function update_options(array $options, ?string $admin_password = null, $default_image = null): array {
    if ($default_image !== null) {
        $options['default_image'] = $default_image;
    }

    if (
        empty($options['site_name']) ||
        empty($options['site_description']) ||
        empty($options['index_page_title']) ||
        empty($options['admin_page_title']) ||
        empty($options['stats_page_title'])
    ) {
        return ['success' => false, 'message' => 'Veuillez remplir tous les champs obligatoires.'];
    }

    if (!empty($admin_password)) {
        if (!preg_match('/^[a-zA-Z0-9_!@#$%^&*()\-+=\[\]{};:\'"\\|,.<>\/?]+$/', $admin_password)) {
            return ['success' => false, 'message' => 'Le mot de passe contient des caractères non autorisés.'];
        }
        $password_hash = password_hash($admin_password, PASSWORD_DEFAULT);
        save_password_hash($password_hash);
    }

    save_options($options);
    return ['success' => true, 'message' => 'Options mises à jour avec succès'];
}
