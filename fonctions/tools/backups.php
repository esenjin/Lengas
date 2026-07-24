<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/backups.php — Outil « Sauvegardes »
//
// Création, listage et suppression des archives ZIP (base SQLite + images),
// ainsi que l'export JSON complet des données.
// ────────────────────────────────────────────────────────────────────────────

// Gestion des sauvegardes — sauvegarde maintenant le fichier SQLite
function create_backup(): array {
    $backup_dir = 'saves';
    if (!file_exists($backup_dir)) {
        $old_umask = umask(0);
        $success   = mkdir($backup_dir, 0774, true);
        umask($old_umask);
        if (!$success) {
            return ['success' => false, 'message' => "Impossible de créer le dossier 'saves/'. Veuillez vérifier les permissions."];
        }
    }

    if (!is_writable($backup_dir)) {
        return ['success' => false, 'message' => "Le dossier 'saves/' n'est pas accessible en écriture."];
    }

    $timestamp   = time();
    $backup_name = "save_$timestamp.zip";
    $backup_path = "$backup_dir/$backup_name";

    $zip = new ZipArchive();
    if ($zip->open($backup_path, ZipArchive::CREATE) === TRUE) {
        // Ajouter le fichier SQLite
        if (file_exists(DB_FILE)) {
            $zip->addFile(DB_FILE, 'bdd/lengas.db');
        }

        // Ajouter le dossier uploads/
        $uploads_dir = 'uploads/';
        if (file_exists($uploads_dir) && is_dir($uploads_dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploads_dir),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $file_path     = $file->getRealPath();
                    $relative_path = substr($file_path, strlen(realpath($uploads_dir)) + 1);
                    $zip->addFile($file_path, 'uploads/' . $relative_path);
                }
            }
        }

        $zip->close();
        return ['success' => true, 'message' => 'Sauvegarde créée avec succès.'];
    }

    return ['success' => false, 'message' => 'Impossible de créer la sauvegarde.'];
}

// Lister les sauvegardes
function list_backups(): array {
    $backup_dir = 'saves';
    $backups    = [];
    if (file_exists($backup_dir)) {
        foreach (scandir($backup_dir) as $file) {
            if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                $timestamp = (int)str_replace(['save_', '.zip'], '', $file);
                $date      = date('d/m/Y H:i', $timestamp);
                $backups[] = ['name' => $file, 'date' => $date, 'timestamp' => $timestamp];
            }
        }
        usort($backups, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    }
    return ['success' => true, 'backups' => $backups];
}

// Supprimer une sauvegarde
function delete_backup(string $backup_file): array {
    if (!empty($backup_file) && file_exists("saves/$backup_file")) {
        unlink("saves/$backup_file");
        return ['success' => true, 'message' => 'Sauvegarde supprimée avec succès.'];
    }
    return ['success' => false, 'message' => 'Fichier de sauvegarde introuvable.'];
}

// ──────────────────────────────────────────────────────────────────────────────
// Export JSON de la base (collection + envies + prêts + lues ailleurs + options)
//
// Retourne un tableau structuré prêt à être encodé en JSON. Cet export est
// destiné à la portabilité / lecture externe (il ne remplace pas la sauvegarde
// ZIP qui, elle, contient aussi le fichier SQLite et les images).
// ──────────────────────────────────────────────────────────────────────────────
function build_json_export(): array {
    $collection = function_exists('load_data')     ? load_data()     : [];
    $wishlist   = function_exists('load_wishlist') ? load_wishlist() : [];
    $loans      = function_exists('load_loans')    ? load_loans()    : [];
    $read       = function_exists('load_read')     ? load_read()     : [];

    // Options (on retire le hash de mot de passe par prudence — il n'y est pas
    // stocké ici, mais on nettoie tout de même les clés sensibles éventuelles).
    $options = function_exists('load_options') ? load_options() : [];
    unset($options['password'], $options['password_hash'], $options['hash']);

    return [
        'meta' => [
            'application' => 'Lengas',
            'version'     => defined('SITE_VERSION') ? SITE_VERSION : null,
            'exported_at' => date('c'),
            'format'      => 'lengas-json-export/1',
        ],
        'collection'     => $collection,
        'wishlist'       => $wishlist,
        'loans'          => $loans,
        'read_elsewhere' => $read,
        'options'        => $options,
    ];
}
