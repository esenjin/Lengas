<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/cleanup.php — Outils de nettoyage
//
// Actions correctives proposées par la vérification d'intégrité :
// doublons collection/envies, images orphelines et fichiers interdits.
// ────────────────────────────────────────────────────────────────────────────

// Nettoyer les doublons
function clean_duplicates(): array {
    global $data;
    $wishlist = load_wishlist();
    $loans    = load_loans();
    $messages = [];

    $series_names   = array_map(fn($s) => strtolower($s['name']), $data);
    $wishlist_names = array_map(fn($s) => strtolower($s['name']), $wishlist);
    $duplicates     = array_intersect($series_names, $wishlist_names);

    if (!empty($duplicates)) {
        $new_wishlist = array_values(array_filter($wishlist, fn($item) => !in_array(strtolower($item['name']), $series_names)));
        save_wishlist($new_wishlist);
        $messages[] = "Doublons collection/envies nettoyés.";
    }

    $series_ids   = array_column($data, 'id');
    $deleted_loans = array_filter($loans, fn($loan) => !in_array($loan['series_id'], $series_ids));

    if (!empty($deleted_loans)) {
        $new_loans = array_values(array_filter($loans, fn($loan) => in_array($loan['series_id'], $series_ids)));
        save_loans($new_loans);
        $messages[] = "Prêts de séries supprimées nettoyés.";
    }

    return ['success' => true, 'message' => implode(' ', $messages) ?: 'Aucun doublon à nettoyer.'];
}

// Nettoyer les images orphelines
function clean_orphaned_images(): array {
    global $data;
    $uploaded_images = [];
    $used_images     = [];
    $deleted_images  = [];

    if (file_exists('uploads/') && is_dir('uploads/')) {
        foreach (scandir('uploads/') as $file) {
            if ($file !== '.' && $file !== '..' && !is_dir('uploads/' . $file)) {
                $uploaded_images[] = 'uploads/' . $file;
            }
        }
    }
    foreach ($data as $series) {
        if (!empty($series['image'])) $used_images[] = $series['image'];
    }

    // Ne jamais supprimer la photo de profil de l'admin.
    $options      = load_options();
    $admin_avatar = trim($options['admin_avatar'] ?? '');
    if ($admin_avatar !== '') $used_images[] = $admin_avatar;

    foreach (array_diff($uploaded_images, $used_images) as $image) {
        if (file_exists($image) && unlink($image)) $deleted_images[] = $image;
    }

    return [
        'success' => true,
        'message' => !empty($deleted_images)
            ? 'Images orphelines supprimées : ' . implode(', ', $deleted_images)
            : 'Aucune image orpheline à supprimer.',
    ];
}

// Supprimer les fichiers interdits
function clean_forbidden_files(): array {
    $forbidden_files = ['generate_password.php', 'migrate.php', 'fix_series_status.php'];
    $deleted_files   = [];
    $failed_files    = [];

    foreach ($forbidden_files as $file) {
        // CWD = racine du projet (chdir dans page-outils.php).
        $path = $file;
        if (file_exists($path)) {
            if (unlink($path)) {
                $deleted_files[] = $file;
            } else {
                $failed_files[] = $file;
            }
        }
    }

    if (!empty($failed_files)) {
        return [
            'success' => false,
            'message' => 'Impossible de supprimer : ' . implode(', ', $failed_files) . '. Vérifiez les permissions du fichier sur le serveur.',
        ];
    }

    return [
        'success' => true,
        'message' => !empty($deleted_files)
            ? 'Fichiers interdits supprimés : ' . implode(', ', $deleted_files)
            : 'Aucun fichier interdit à supprimer.',
    ];
}
