<?php
// Fonction pour récupérer la dernière version depuis Gitea
function get_latest_version_from_gitea() {
    $url = "https://git.crystalyx.net/api/v1/repos/Esenjin_Asakha/Lengas/releases/latest";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Lengas-Version-Checker");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        if (isset($data['tag_name'])) {
            return ltrim($data['tag_name'], 'v');
        }
    }
    return null;
}

// Fonction pour vérifier l'intégrité du site
function check_site_integrity($data) {
    $results = [
        'file_existence' => [],
        'forbidden_files' => [],
        'permissions' => [],
        'duplicates' => [],
        'orphaned_images' => [],
        'version' => null,
        'site_info' => [],
    ];

    // 1. Vérifier l'existence de tous les fichiers/dossiers
    $required_files = [
        'index.php', 'admin.php', 'stats.php', 'config.php', 'login.php', 'logout.php',
        'assets/css/main.css', 'assets/js/public.js', 'assets/js/stats.js',
        'assets/js/admin/', 'includes/', 'fonctions/', 'uploads/', 'saves/', 'bdd/'
    ];

    // Vérification des fichiers/dossiers principaux
    foreach ($required_files as $file) {
        $results['file_existence'][$file] = file_exists($file);
    }

    // Vérification des fichiers CSS
    $required_css_files = [
        'assets/css/_admin.css', 'assets/css/_base.css', 'assets/css/_buttons.css',
        'assets/css/_forms.css', 'assets/css/_layout.css', 'assets/css/_modals.css',
        'assets/css/_public.css', 'assets/css/_responsive.css', 'assets/css/_series.css',
        'assets/css/_utils.css', 'assets/css/_variables.css'
    ];
    foreach ($required_css_files as $file) {
        $results['file_existence'][$file] = file_exists($file);
    }

    // Vérification des fichiers JS (admin)
    $required_js_files = [
        'assets/js/admin/series.js', 'assets/js/admin/volumes.js', 'assets/js/admin/wishlist.js',
        'assets/js/admin/loans.js', 'assets/js/admin/tools.js', 'assets/js/admin/autocomplete.js',
        'assets/js/admin/modals.js', 'assets/js/admin/pagination.js', 'assets/js/admin/main.js'
    ];
    foreach ($required_js_files as $file) {
        $results['file_existence'][$file] = file_exists($file);
    }

    // Vérification des fichiers JSON dans bdd/
    $required_bdd_files = [
        'bdd/data.json', 'bdd/list.json', 'bdd/loan.json', 'bdd/anilist.json', 'bdd/options.json', 'bdd/mdp.json'
    ];
    foreach ($required_bdd_files as $file) {
        $results['file_existence'][$file] = file_exists($file);
    }

    // 2. Vérifier l'absence de generate_password.php
    $results['forbidden_files']['generate_password.php'] = !file_exists('generate_password.php');

    // 3. Vérifier les permissions des dossiers/fichiers
    $results['permissions'] = [];
    $checks = [
        'uploads/' => '0774',
        'bdd/' => '0774',
        'saves/' => '0774',
        'bdd/data.json' => '0660',
        'bdd/list.json' => '0660',
        'bdd/loan.json' => '0660',
        'bdd/anilist.json' => '0660',
        'bdd/options.json' => '0660',
        'bdd/mdp.json' => '0660',
    ];
    foreach ($checks as $path => $expected) {
        if (file_exists($path)) {
            $current = substr(sprintf('%o', fileperms($path)), -4);
            $results['permissions'][$path] = [
                'current' => $current,
                'expected' => $expected,
                'ok' => ($current === $expected),
            ];
        } else {
            $results['permissions'][$path] = [
                'current' => 'N/A',
                'expected' => $expected,
                'ok' => false,
            ];
        }
    }

    // 4. Vérification des séries doublons (collection, envies, prêts)
    $wishlist = load_wishlist();
    $loans = load_loans();
    $series_names = array_map(function($s) { return strtolower($s['name']); }, $data);
    $wishlist_names = array_map(function($s) { return strtolower($s['name']); }, $wishlist);
    $loan_series_ids = array_unique(array_column($loans, 'series_id'));

    // Doublons collection/envies
    $results['duplicates']['collection_wishlist'] = array_intersect($series_names, $wishlist_names);

    // Doublons collection/prêts (séries supprimées mais encore en prêt)
    $results['duplicates']['deleted_loans'] = [];
    foreach ($loan_series_ids as $id) {
        $found = false;
        foreach ($data as $series) {
            if ($series['id'] === $id) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $results['duplicates']['deleted_loans'][] = $id;
        }
    }

    // 5. Vérification que toutes les images (dans uploads) soient attachées à une série
    $uploaded_images = [];
    $used_images = [];
    if (file_exists('uploads/') && is_dir('uploads/')) {
        $files = scandir('uploads/');
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && !is_dir('uploads/' . $file)) {
                $uploaded_images[] = 'uploads/' . $file;
            }
        }
    }
    foreach ($data as $series) {
        if (!empty($series['image'])) {
            $used_images[] = $series['image'];
        }
    }
    $results['orphaned_images'] = array_values(array_diff($uploaded_images, $used_images));

    // 6. Vérification de la version du site avec la dernière version Gitea
    $results['version'] = [
        'current' => SITE_VERSION,
        'latest' => get_latest_version_from_gitea(),
    ];

    // 7. Récupérer des informations sur le site
    $results['site_info'] = [
        'site_url' => get_site_url(),
        'uses_https' => uses_https(),
        'uploads_size' => get_uploads_size(),
        'max_upload_size' => get_max_upload_size(),
        'effective_max_upload_size' => get_effective_max_upload_size(),
        'server_info' => get_server_info(),
    ];

    return $results;
}

// Fonction pour obtenir l'URL du site
function get_site_url() {
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
}

// Fonction pour vérifier si le site utilise HTTPS
function uses_https() {
    return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
}

// Fonction pour obtenir la taille du dossier uploads/
function get_uploads_size() {
    $size = 0;
    if (file_exists('uploads/') && is_dir('uploads/')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('uploads/'));
        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    }
    return format_size($size);
}

// Fonction pour obtenir la taille maximale des fichiers téléversés
function get_max_upload_size() {
    return ini_get('upload_max_filesize');
}

// Fonction pour obtenir la taille de fichier effective maximale
function get_effective_max_upload_size() {
    $max_upload = parse_size(ini_get('upload_max_filesize'));
    $max_post = parse_size(ini_get('post_max_size'));
    $memory_limit = parse_size(ini_get('memory_limit'));
    return min($max_upload, $max_post, $memory_limit);
}

// Fonction pour obtenir des informations sur le serveur
function get_server_info() {
    return [
        'server_architecture' => php_uname('m'),
        'server_software' => $_SERVER['SERVER_SOFTWARE'],
        'php_version' => phpversion(),
        'max_execution_time' => ini_get('max_execution_time'),
        'memory_limit' => ini_get('memory_limit'),
    ];
}

// Fonction pour convertir la taille en octets
function parse_size($size) {
    $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
    $size = preg_replace('/[^0-9\\.]/', '', $size);
    if ($unit) {
        return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
    } else {
        return round($size);
    }
}

// Fonction pour formater la taille en octets lisible
function format_size($bytes) {
    if ($bytes >= 1073741824) {
        $bytes = number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        $bytes = number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        $bytes = number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        $bytes = $bytes . ' bytes';
    } elseif ($bytes == 1) {
        $bytes = $bytes . ' byte';
    } else {
        $bytes = '0 bytes';
    }
    return $bytes;
}

// Nettoyer les doublons
function clean_duplicates() {
    global $data;
    $wishlist = load_wishlist();
    $loans = load_loans();
    $messages = [];

    // Nettoyer les doublons collection/envies
    $series_names = array_map(function($s) { return strtolower($s['name']); }, $data);
    $wishlist_names = array_map(function($s) { return strtolower($s['name']); }, $wishlist);
    $duplicates = array_intersect($series_names, $wishlist_names);

    if (!empty($duplicates)) {
        $new_wishlist = array_filter($wishlist, function($item) use ($series_names) {
            return !in_array(strtolower($item['name']), $series_names);
        });
        save_wishlist(array_values($new_wishlist));
        $messages[] = "Doublons collection/envies nettoyés.";
    }

    // Nettoyer les prêts de séries supprimées
    $series_ids = array_column($data, 'id');
    $deleted_loans = array_filter($loans, function($loan) use ($series_ids) {
        return !in_array($loan['series_id'], $series_ids);
    });

    if (!empty($deleted_loans)) {
        $new_loans = array_filter($loans, function($loan) use ($series_ids) {
            return in_array($loan['series_id'], $series_ids);
        });
        save_loans(array_values($new_loans));
        $messages[] = "Prêts de séries supprimées nettoyés.";
    }

    return [
        'success' => true,
        'message' => implode(' ', $messages) ?: 'Aucun doublon à nettoyer.',
    ];
}

// Nettoyer les images orphelines
function clean_orphaned_images() {
    global $data;
    $uploaded_images = [];
    $used_images = [];
    $deleted_images = [];

    if (file_exists('uploads/') && is_dir('uploads/')) {
        $files = scandir('uploads/');
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && !is_dir('uploads/' . $file)) {
                $uploaded_images[] = 'uploads/' . $file;
            }
        }
    }

    foreach ($data as $series) {
        if (!empty($series['image'])) {
            $used_images[] = $series['image'];
        }
    }

    $orphaned_images = array_diff($uploaded_images, $used_images);
    foreach ($orphaned_images as $image) {
        if (file_exists($image) && unlink($image)) {
            $deleted_images[] = $image;
        }
    }

    return [
        'success' => true,
        'message' => !empty($deleted_images) ?
            'Images orphelines supprimées : ' . implode(', ', $deleted_images) :
            'Aucune image orpheline à supprimer.',
    ];
}

// Supprimer les fichiers interdits
function clean_forbidden_files() {
    $forbidden_files = ['generate_password.php'];
    $deleted_files = [];

    foreach ($forbidden_files as $file) {
        if (file_exists($file) && unlink($file)) {
            $deleted_files[] = $file;
        }
    }

    return [
        'success' => true,
        'message' => !empty($deleted_files) ?
            'Fichiers interdits supprimés : ' . implode(', ', $deleted_files) :
            'Aucun fichier interdit à supprimer.',
    ];
}

// Gestion des sauvegardes
function create_backup() {
    $backup_dir = 'saves';
    // Vérifier si le dossier existe, sinon essayer de le créer
    if (!file_exists($backup_dir)) {
        $old_umask = umask(0);
        $success = mkdir($backup_dir, 0774, true);
        umask($old_umask);

        if (!$success) {
            return ['success' => false, 'message' => "Impossible de créer le dossier 'saves/'. Veuillez vérifier les permissions du dossier parent ou créer le dossier manuellement."];
        }
    }

    // Vérifier que le dossier est accessible en écriture
    if (!is_writable($backup_dir)) {
        return ['success' => false, 'message' => "Le dossier 'saves/' n'est pas accessible en écriture. Veuillez vérifier les permissions."];
    }

    // Suite du code pour créer la sauvegarde...
    $timestamp = time();
    $backup_name = "save_$timestamp.zip";
    $backup_path = "$backup_dir/$backup_name";

    $zip = new ZipArchive();
    if ($zip->open($backup_path, ZipArchive::CREATE) === TRUE) {
        // Ajouter les fichiers JSON
        $files_to_backup = [
            'bdd/data.json',
            'bdd/list.json',
            'bdd/loan.json',
            'bdd/options.json'
        ];

        foreach ($files_to_backup as $file) {
            if (file_exists($file)) {
                $zip->addFile($file, basename($file));
            }
        }

        // Ajouter le dossier uploads/ et ses images
        $uploads_dir = 'uploads/';
        if (file_exists($uploads_dir) && is_dir($uploads_dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploads_dir),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $file_path = $file->getRealPath();
                    $relative_path = substr($file_path, strlen(realpath($uploads_dir)) + 1);
                    $zip->addFile($file_path, 'uploads/' . $relative_path);
                }
            }
        }

        $zip->close();
        return ['success' => true, 'message' => 'Sauvegarde créée avec succès.'];
    } else {
        return ['success' => false, 'message' => 'Impossible de créer la sauvegarde.'];
    }
}

// Lister les sauvegardes
function list_backups() {
    $backup_dir = 'saves';
    $backups = [];
    if (file_exists($backup_dir)) {
        $files = scandir($backup_dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                $timestamp = str_replace(['save_', '.zip'], '', $file);
                $date = date('d/m/Y H:i', $timestamp);
                $backups[] = [
                    'name' => $file,
                    'date' => $date,
                    'timestamp' => $timestamp
                ];
            }
        }
        usort($backups, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
    }
    return ['success' => true, 'backups' => $backups];
}

// Supprimer une sauvegarde
function delete_backup($backup_file) {
    if (!empty($backup_file) && file_exists("saves/$backup_file")) {
        unlink("saves/$backup_file");
        return ['success' => true, 'message' => 'Sauvegarde supprimée avec succès.'];
    } else {
        return ['success' => false, 'message' => 'Fichier de sauvegarde introuvable.'];
    }
}

// Générer les notifications pour une série
function generate_notifications($volumes, $anilist_volumes = null) {
    $notifications = [];
    if (empty($volumes)) {
        return $notifications;
    }

    $numbers = array_map(function($v) { return $v['number']; }, $volumes);
    $min = min($numbers);
    $max = max($numbers);
    $last_volumes = array_filter($volumes, function($v) { return !empty($v['last']); });

    // Vérifier les tomes manquants
    $missing = [];
    for ($i = $min; $i <= $max; $i++) {
        if (!in_array($i, $numbers)) {
            $missing[] = $i;
        }
    }
    if (!empty($missing)) {
        if (count($missing) == 1) {
            $notifications[] = "Attention, le tome " . implode(', ', $missing) . " est manquant.";
        } else {
            $notifications[] = "Attention, les tomes " . implode(', ', $missing) . " sont manquants.";
        }
    }

    // Vérifier si le tome étiqueté comme dernier est correct
    if (!empty($last_volumes)) {
        $last_numbers = array_map(function($v) { return $v['number']; }, $last_volumes);
        $actual_last = $max;
        foreach ($last_numbers as $num) {
            if ($num != $actual_last) {
                $notifications[] = "Attention, le tome tagué dernier ($num) est incorrect.";
            }
        }
        if (count($last_volumes) > 1) {
            $notifications[] = "Attention, plusieurs tomes sont tagués comme dernier (" . implode(', ', $last_numbers) . ").";
        }
    }

    // Vérifier si la bibliothèque a plus de tomes que sur Anilist
    if ($anilist_volumes !== null && $max > $anilist_volumes) {
        $notifications[] = "Attention, votre série contient plus de tomes que ce qui est indiqué sur Anilist.";
    }

    // Vérifier si la série est complète selon Anilist mais qu'il y a des tomes manquants
    if ($anilist_volumes !== null && $max < $anilist_volumes) {
        $missing = range($max + 1, $anilist_volumes);
        if (count($missing) == 1) {
            $notifications[] = "Attention, il manque le tome " . implode(', ', $missing) . " pour compléter cette série.";
        } else {
            $notifications[] = "Attention, il manque les tomes " . implode(', ', $missing) . " pour compléter cette série.";
        }
    }

    // Vérifier si le nombre de tomes est égal à Anilist mais que le dernier tome n'est pas tagué comme tel
    if ($anilist_volumes !== null && $max == $anilist_volumes && empty($last_volumes)) {
        $notifications[] = "Attention, cette série semble complète mais le dernier tome n'est pas tagué comme tel.";
    }

    return $notifications;
}