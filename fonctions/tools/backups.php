<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/backups.php — Outil « Sauvegardes »
//
// Création, listage et suppression des archives ZIP (base SQLite + images),
// ainsi que l'export JSON complet des données.
// ────────────────────────────────────────────────────────────────────────────

// Formate une taille en octets en chaîne lisible (o, Ko, Mo, Go, To)
function format_backup_size(int $bytes): string {
    if ($bytes <= 0) return '0 o';
    $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $power = (int)floor(log($bytes, 1024));
    $power = min($power, count($units) - 1);
    $value = $bytes / (1024 ** $power);
    $decimals = ($power === 0) ? 0 : 1;
    return number_format($value, $decimals, ',', ' ') . ' ' . $units[$power];
}

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
    $backup_dir  = 'saves';
    $backups     = [];
    $total_bytes = 0;
    if (file_exists($backup_dir)) {
        foreach (scandir($backup_dir) as $file) {
            if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                $timestamp = (int)str_replace(['save_', '.zip'], '', $file);
                $date      = date('d/m/Y H:i', $timestamp);
                $path      = "$backup_dir/$file";

                // Taille du fichier ZIP
                $size_bytes   = @filesize($path);
                $size_bytes   = ($size_bytes !== false) ? (int)$size_bytes : 0;
                $total_bytes += $size_bytes;

                // Nombre de fichiers contenus dans l'archive (si lisible)
                $file_count = null;
                $zip = new ZipArchive();
                if ($zip->open($path) === TRUE) {
                    $file_count = $zip->numFiles;
                    $zip->close();
                }

                $backups[] = [
                    'name'       => $file,
                    'date'       => $date,
                    'timestamp'  => $timestamp,
                    'size_bytes' => $size_bytes,
                    'size'       => format_backup_size($size_bytes),
                    'file_count' => $file_count,
                ];
            }
        }
        usort($backups, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    }
    return [
        'success'           => true,
        'backups'           => $backups,
        'total_bytes'       => $total_bytes,
        'total_size'        => format_backup_size($total_bytes),
        'count'             => count($backups),
    ];
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
//
// V4 (bloc 11) : aucune adaptation nécessaire ici. load_data() renvoie déjà
// tous les champs animés (studios, format, titres alternatifs, éditions
// physiques…) au même titre que les champs mangas, et load_wishlist() renvoie
// déjà son propre type et anilist_id. La sauvegarde ZIP, elle, couvre les
// nouvelles tables (series_editions, anilist_cache) via le fichier SQLite
// entier, et les vignettes Anilist via le dossier uploads/ entier : les deux
// sont déjà exhaustifs par construction, sans liste de champs à tenir à jour.
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
