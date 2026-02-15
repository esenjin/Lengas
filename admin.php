<?php
error_reporting(0);

require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require 'includes/anilist.php';
require 'fonctions/series.php';
require 'fonctions/volumes.php';
require 'fonctions/read.php';
require 'fonctions/wishlist.php';
require 'fonctions/loans.php';
require 'fonctions/options.php';
require 'fonctions/tools.php';

$data = load_data();
$options = load_options();

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page_admin = 9;
$offset = ($page - 1) * $per_page_admin;

// Récupérer les séries "lues ailleurs"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_read') {
    $read = load_read();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'read' => is_array($read) ? $read : []
    ]);
    exit;
}

// Gestion des actions pour les séries incomplètes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $response = ['success' => false];

    switch ($action) {
        case 'get_incomplete_series':
            try {
                $incomplete_series = get_incomplete_series($data);
                $response['success'] = true;
                $response['incomplete_series'] = $incomplete_series;
            } catch (Exception $e) {
                $response['success'] = false;
                $response['message'] = "Impossible de récupérer les séries incomplètes. Veuillez réessayer plus tard.";
            }
            break;

        case 'add_missing_volume':
            $series_id = $_POST['series_id'] ?? '';
            $volume_number = (int)($_POST['volume_number'] ?? 0);
            if ($series_id && $volume_number > 0) {
                $result = add_volume_to_series($data, $series_id, $volume_number, 'à lire', false, false);
                if ($result['success']) {
                    save_data($result['data']);
                    $response['success'] = true;
                } else {
                    $response['message'] = $result['message'];
                }
            }
            break;

        case 'add_all_missing_volumes':
            $series_id = $_POST['series_id'] ?? '';
            $missing_volumes = isset($_POST['missing_volumes']) ? explode(',', $_POST['missing_volumes']) : [];
            $missing_volumes = array_map('intval', $missing_volumes);
            if ($series_id && !empty($missing_volumes)) {
                $success = true;
                foreach ($missing_volumes as $volume) {
                    $result = add_volume_to_series($data, $series_id, $volume, 'à lire', false, false);
                    if (!$result['success']) {
                        $success = false;
                        break;
                    }
                }
                if ($success) {
                    save_data($data);
                    $response['success'] = true;
                }
            }
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Gestion des actions pour les séries
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_series'])) {
    $name = trim($_POST['name'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $publisher = trim($_POST['publisher'] ?? '');
    $other_contributors = trim($_POST['other_contributors'] ?? '');
    $categories = trim($_POST['categories'] ?? '');
    $genres = trim($_POST['genres'] ?? '');
    $anilist_id = trim($_POST['anilist_id'] ?? '');
    $mature = !empty($_POST['mature']);
    $favorite = !empty($_POST['favorite']);
    $volumes_count = (int)($_POST['volumes_count'] ?? 1);
    $volumes_status = $_POST['volumes_status'] ?? 'à lire';
    $all_collector = !empty($_POST['all_collector']);
    $last_volume = !empty($_POST['last_volume']);

    // Initialiser $image à null par défaut
    $image = null;
    $error_message = null;

    // Si une image est uploadée, essayer de la traiter
    if (!empty($_FILES['image']['name'])) {
        $image = upload_image($_FILES['image'], $error_message);
        if ($image === false) {
            $_SESSION['error_message'] = $error_message ?: "Erreur inconnue lors du téléversement de l'image.";
            // Ne pas bloquer l'ajout de la série si l'image échoue
        }
    }

    // Appeler add_series avec $image (qui peut être null)
    $result = add_series($data, $name, $author, $publisher, $other_contributors, $categories, $genres, $anilist_id, $mature, $favorite, $volumes_count, $volumes_status, $all_collector, $last_volume, $image);

    if ($result['success']) {
        save_data($result['data']);
        $_SESSION['success_message'] = $result['message'];
    } else {
        $_SESSION['error_message'] = $result['message'];
    }

    header("Location: admin.php");
    exit;
}

// Gestion des actions pour les tomes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_multiple_volumes'])) {
    $series_id = $_POST['series_id'] ?? '';
    $volumes_count = (int)($_POST['volumes_count'] ?? 0);
    $status = $_POST['status'] ?? 'à lire';
    $is_collector = isset($_POST['is_collector']) ? (bool)$_POST['is_collector'] : false;
    $is_last = isset($_POST['is_last']) ? (bool)$_POST['is_last'] : false;

    if ($volumes_count > 0) {
        $result = add_multiple_volumes_to_series($data, $series_id, $volumes_count, $status, $is_collector, $is_last);
        if ($result['success']) {
            save_data($result['data']);
        } else {
            $_SESSION['error_message'] = $result['message'];
        }
    }

    header("Location: admin.php");
    exit;
}

// Mettre à jour un tome
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_volume'])) {
    $series_id = $_POST['series_id'] ?? '';
    $volume_index = (int)($_POST['volume_index'] ?? 0);
    $status = $_POST['status'] ?? 'à lire';
    $is_collector = !empty($_POST['is_collector']);
    $is_last = !empty($_POST['is_last']);

    $result = update_volume($data, $series_id, $volume_index, $status, $is_collector, $is_last);
    if ($result['success']) {
        save_data($result['data']);
    }

    header("Location: admin.php");
    exit;
}

// Supprimer un tome
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_volume'])) {
    $series_id = $_POST['series_id'] ?? '';
    $volume_index = (int)($_POST['volume_index'] ?? 0);

    $result = delete_volume($data, $series_id, $volume_index);
    if ($result['success']) {
        save_data($result['data']);
        $_SESSION['success_message'] = "Tome supprimé avec succès";
    } else {
        $_SESSION['error_message'] = $result['message'];
    }

    header("Location: admin.php");
    exit;
}

// Mettre à jour une série
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_series'])) {
    $series_id = $_POST['series_id'] ?? '';
    $name = trim($_POST['edit_name'] ?? '');
    $author = trim($_POST['edit_author'] ?? '');
    $publisher = trim($_POST['edit_publisher'] ?? '');
    $other_contributors = trim($_POST['edit_other_contributors'] ?? '');
    $categories = trim($_POST['edit_categories'] ?? '');
    $genres = trim($_POST['edit_genres'] ?? '');
    $anilist_id = trim($_POST['edit_anilist_id'] ?? '');
    $mature = !empty($_POST['edit_mature']);
    $favorite = !empty($_POST['edit_favorite']);
    $remove_image = !empty($_POST['remove_image']);
    $new_volumes_count = (int)($_POST['new_volumes_count'] ?? 0);
    $new_volumes_status = $_POST['new_volumes_status'] ?? 'à lire';
    $new_volumes_collector = !empty($_POST['new_volumes_collector']);
    $new_volumes_last = !empty($_POST['new_volumes_last']);

    $new_image = null;
    if (!empty($_FILES['edit_image']['name'])) {
        $error_message = null;
        $new_image = upload_image($_FILES['edit_image'], $error_message);
        if ($new_image === false) {
            $_SESSION['error_message'] = $error_message ?: "Erreur inconnue lors du téléversement de l'image.";
            header("Location: admin.php");
            exit;
        }
    }

    $result = update_series($data, $series_id, $name, $author, $other_contributors, $publisher, $categories, $genres, $anilist_id, $mature, $favorite, $remove_image, $new_volumes_count, $new_volumes_status, $new_volumes_collector, $new_volumes_last, $new_image);
    if ($result['success']) {
        save_data($result['data']);
    }

    header("Location: admin.php");
    exit;
}

// Supprimer une série
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_series'])) {
    $series_id = $_POST['series_id'] ?? '';
    $result = delete_series($data, $series_id);
    if ($result['success']) {
        save_data($result['data']);
        echo "OK";
    } else {
        echo $result['message'];
    }
    exit;
}

// Mettre à jour les options du site
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_options'])) {
    $options = load_options();
    $options['site_name'] = trim($_POST['site_name'] ?? '');
    $options['site_description'] = trim($_POST['site_description'] ?? '');
    $options['index_page_title'] = trim($_POST['index_page_title'] ?? '');
    $options['admin_page_title'] = trim($_POST['admin_page_title'] ?? '');
    $options['stats_page_title'] = trim($_POST['stats_page_title'] ?? '');
    $options['private_mode'] = !empty($_POST['private_mode']);
    $options['hide_mature'] = !empty($_POST['hide_mature']);
    $options['custom_button_name'] = trim($_POST['custom_button_name'] ?? '');
    $options['custom_button_url'] = trim($_POST['custom_button_url'] ?? '');
    $options['custom_button_name2'] = trim($_POST['custom_button_name2'] ?? '');
    $options['custom_button_url2'] = trim($_POST['custom_button_url2'] ?? '');
    $options['custom_button_name3'] = trim($_POST['custom_button_name3'] ?? '');
    $options['custom_button_url3'] = trim($_POST['custom_button_url3'] ?? '');

    $admin_password = trim($_POST['admin_password'] ?? '');

    // Gestion du remplacement de logo.png
    if (!empty($_FILES['default_logo']['name'])) {
        $uploaded_image = $_FILES['default_logo'];
        $allowed_types = ['image/png'];
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($file_info, $uploaded_image['tmp_name']);

        // Vérification du type MIME
        if (!in_array($mime_type, $allowed_types)) {
            $_SESSION['error_message'] = "Seuls le PNG est autorisés pour le logo.";
        } else {
            // Chemin absolu vers logo.png
            $logo_path = __DIR__ . '/logo.png';

            // Supprimer l'ancien logo.png s'il existe
            if (file_exists($logo_path)) {
                if (!unlink($logo_path)) {
                    $_SESSION['error_message'] = "Impossible de supprimer l'ancien logo. Vérifiez les permissions.";
                    header("Location: admin.php");
                    exit;
                }
            }

            // Déplacer le nouveau fichier
            if (move_uploaded_file($uploaded_image['tmp_name'], $logo_path)) {
                $_SESSION['success_message'] = "Le logo par défaut a été mis à jour avec succès.";
            } else {
                $_SESSION['error_message'] = "Erreur lors du déplacement du fichier. Vérifiez les permissions du dossier.";
            }
        }
    }

    // Mise à jour des autres options (sans toucher à default_image)
    $result = update_options($options, $admin_password);
    if ($result['success']) {
        $_SESSION['success_message'] = $result['message'];
    } else {
        $_SESSION['error_message'] = $result['message'];
    }

    header("Location: admin.php");
    exit;
}

// Gestion des actions pour la liste d'envies
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_wishlist'])) {
    $name = trim($_POST['wishlist_name'] ?? '');
    $author = trim($_POST['wishlist_author'] ?? '');
    $publisher = trim($_POST['wishlist_publisher'] ?? '');

    $wishlist = load_wishlist();
    $result = add_to_wishlist($wishlist, $name, $author, $publisher);
    if ($result['success']) {
        save_wishlist($result['wishlist']);
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Supprimer une série de la liste d'envies
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_from_wishlist'])) {
    $index = $_POST['index'] ?? 0;
    $wishlist = load_wishlist();
    $result = remove_from_wishlist($wishlist, $index);
    if ($result['success']) {
        save_wishlist($result['wishlist']);
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Ajouter une série à la collection principale depuis la liste d'envies
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_from_wishlist'])) {
    $index = $_POST['index'] ?? 0;
    $wishlist = load_wishlist();
    $result = add_from_wishlist($data, $wishlist, $index);
    if ($result['success']) {
        save_data($result['data']);
        save_wishlist($result['wishlist']);
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Gestion des actions pour les prêts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loan_action'])) {
    $response = ['success' => false];
    $action = $_POST['loan_action'];

    switch ($action) {
        case 'add_single_loan':
            $series_id = $_POST['series_id'] ?? '';
            $volume_number = (int)($_POST['volume_number'] ?? 0);
            $borrower_name = trim($_POST['borrower_name'] ?? '');

            if ($series_id && $volume_number > 0 && $borrower_name) {
                $response = add_loan($data, $series_id, $volume_number, $borrower_name);
            } else {
                $response['message'] = 'La série sélectionnée n\'existe pas dans votre base. Veuillez vérifier votre sélection.';
            }
            break;

        case 'add_multiple_loans':
            $series_id = $_POST['series_id'] ?? '';
            $start_volume = (int)($_POST['start_volume'] ?? 0);
            $end_volume = (int)($_POST['end_volume'] ?? 0);
            $borrower_name = trim($_POST['borrower_name'] ?? '');

            if ($series_id && $start_volume > 0 && $end_volume >= $start_volume && $borrower_name) {
                $response = add_multiple_loans($data, $series_id, $start_volume, $end_volume, $borrower_name);
            }
            break;

        case 'remove_loan':
            $series_id = $_POST['series_id'] ?? '';
            $volume_number = (int)($_POST['volume_number'] ?? 0);

            if ($series_id && $volume_number > 0) {
                $response['success'] = remove_loan($series_id, $volume_number);
            }
            break;

        case 'remove_all_loans':
            $series_id = $_POST['series_id'] ?? '';
            if ($series_id) {
                $response['success'] = remove_all_loans($series_id);
            }
            break;

        case 'get_loans':
            $loans_by_series = get_loans_by_series($data);
            $response['success'] = true;
            $response['loans'] = $loans_by_series;
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Gestion des actions de sauvegarde
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup_action'])) {
    $action = $_POST['backup_action'];
    $response = ['success' => false, 'message' => ''];

    switch ($action) {
        case 'create_backup':
            $response = create_backup();
            break;

        case 'delete_backup':
            $backup_file = $_POST['backup_file'] ?? '';
            $response = delete_backup($backup_file);
            break;

        case 'list_backups':
            $response = list_backups();
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Gestion des actions de nettoyage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tool_action'])) {
    $response = ['success' => false, 'message' => 'Action inconnue.'];

    switch ($_POST['tool_action']) {
        case 'check_integrity':
            $integrity_results = check_site_integrity($data);
            $response = ['success' => true, 'results' => $integrity_results];
            break;

        case 'clean_duplicates':
            $response = clean_duplicates();
            break;

        case 'clean_orphaned_images':
            $response = clean_orphaned_images();
            break;

        case 'clean_forbidden_files':
            $response = clean_forbidden_files();
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Gestion de la pagination des séries
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_paginated_series'])) {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 9;
    $search_term = $_GET['search'] ?? '';
    $sort_by = $_GET['sort_by'] ?? 'name';
    $sort_order = $_GET['sort_order'] ?? 'asc';
    $light_mode = isset($_GET['light']) && $_GET['light'] === 'true';

    $filtered_data = $data;
    if ($search_term) {
        $normalized_search = normalize_string($search_term);
        $filtered_data = array_filter($filtered_data, function($series) use ($normalized_search) {
            return strpos(normalize_string($series['name'] ?? ''), $normalized_search) !== false ||
                strpos(normalize_string($series['author'] ?? ''), $normalized_search) !== false ||
                strpos(normalize_string($series['publisher'] ?? ''), $normalized_search) !== false ||
                (isset($series['other_contributors']) && strpos(normalize_string(implode(', ', $series['other_contributors'])), $normalized_search) !== false) ||
                (isset($series['categories']) && strpos(normalize_string(implode(', ', $series['categories'])), $normalized_search) !== false) ||
                (isset($series['genres']) && strpos(normalize_string(implode(', ', $series['genres'])), $normalized_search) !== false);
        });
    }
    sort_series($filtered_data, $sort_by, $sort_order);

    $offset = ($page - 1) * $per_page;
    $paginated_data = array_slice($filtered_data, $offset, $per_page);

    // En mode "light", on ne renvoie que les métadonnées
    if ($light_mode) {
        $light_series = array_map(function($series) {
            return [
                'id' => $series['id'],
                'name' => $series['name'],
                'author' => $series['author'],
                'publisher' => $series['publisher'],
                'other_contributors' => $series['other_contributors'] ?? [],
                'categories' => $series['categories'] ?? [],
                'genres' => $series['genres'] ?? [],
                'image' => $series['image'] ?? 'logo.png',
                'volumes_count' => count($series['volumes']),
                'favorite' => $series['favorite'] ?? false,
                'mature' => $series['mature'] ?? false,
                'has_anilist_id' => !empty($series['anilist_id']),
            ];
        }, $paginated_data);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'series' => array_values($light_series),
            'has_more' => ($offset + $per_page) < count($filtered_data)
        ]);
        exit;
    }
}

// Gestion de la récupération des tomes d'une série
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_series_volumes'])) {
    $series_id = $_GET['series_id'] ?? '';

    $series = null;
    foreach ($data as $key => $s) {
        if ($s['id'] === $series_id) {
            $series = $s;
            break;
        }
    }

    if (!$series) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Série introuvable.']);
        exit;
    }

    // Calcul des notifications
    $anilist_volumes = null;
    if (!empty($series['anilist_id'])) {
        $anilist_volumes = get_series_volumes_from_anilist($series['anilist_id']);
    }
    $notifications = generate_notifications($series['volumes'], $anilist_volumes);

    // Générer le HTML des tomes
    $volumes_html = '<ul class="volumes-list">';
    foreach ($series['volumes'] as $volume_index => $volume) {
        $volumes_html .= sprintf(
            '<li class="status-%s%s%s" data-series-id="%s" data-volume-index="%d">%d</li>',
            str_replace(' ', '-', strtolower($volume['status'])),
            !empty($volume['collector']) ? ' volume-collector' : '',
            !empty($volume['last']) ? ' volume-last' : '',
            $series_id,
            $volume_index,
            $volume['number']
        );
    }
    $volumes_html .= '</ul>';

    // Ajouter les notifications si nécessaire
    if (!empty($notifications)) {
        $volumes_html = '<div class="issues-list"><span class="warning-icon">⚠️</span><span class="issues-text">' . implode(' ', $notifications) . '</span></div>' . $volumes_html;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'volumes_html' => $volumes_html,
        'notifications' => $notifications
    ]);
    exit;
}

// Gestion des suggestions pour l'auto-complétion
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_suggestions'])) {
    $field = $_GET['field'] ?? '';
    $term = trim($_GET['term'] ?? '');
    $normalizedTerm = normalize_string($term);
    $suggestions = [];

    if (in_array($field, ['author', 'publisher', 'other_contributors', 'categories', 'genres'])) {
        foreach ($data as $series) {
            if (isset($series[$field])) {
                // Si le champ est un tableau (autres contributeurs, genres, catégories)
                if (is_array($series[$field])) {
                    foreach ($series[$field] as $value) {
                        $normalizedValue = normalize_string($value);
                        if (str_contains($normalizedValue, $normalizedTerm) && !in_array($value, $suggestions)) {
                            $suggestions[] = $value;
                        }
                    }
                }
                // Si le champ est une chaîne (auteur, éditeur)
                else {
                    $value = $series[$field];
                    $normalizedValue = normalize_string($value);
                    if (str_contains($normalizedValue, $normalizedTerm) && !in_array($value, $suggestions)) {
                        $suggestions[] = $value;
                    }
                }
            }
        }
    }

    // Supprime les doublons
    $suggestions = array_unique($suggestions);
    header('Content-Type: application/json');
    echo json_encode(array_values($suggestions));
    exit;
}

// Gestion du tri et de la recherche
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'asc';
$search_term = $_GET['search'] ?? '';

$filtered_data = $data;

sort_series($filtered_data, $sort_by, $sort_order);

if ($search_term) {
    $normalized_search = normalize_string($search_term);
    $filtered_data = array_filter($filtered_data, function($series) use ($normalized_search) {
        return strpos(normalize_string($series['name'] ?? ''), $normalized_search) !== false ||
               strpos(normalize_string($series['author'] ?? ''), $normalized_search) !== false ||
               strpos(normalize_string($series['publisher'] ?? ''), $normalized_search) !== false ||
               (isset($series['other_contributors']) && strpos(normalize_string(implode(', ', $series['other_contributors'])), $normalized_search) !== false) ||
               (isset($series['categories']) && strpos(normalize_string(implode(', ', $series['categories'])), $normalized_search) !== false) ||
               (isset($series['genres']) && strpos(normalize_string(implode(', ', $series['genres'])), $normalized_search) !== false);
    });
}

// Gestion de la récupération des séries en cours
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_current_series'])) {
    $current_series = [];
    foreach ($data as $series) {
        $has_last_volume = false;
        foreach ($series['volumes'] as $volume) {
            if (isset($volume['last']) && $volume['last']) {
                $has_last_volume = true;
                break;
            }
        }
        if (!$has_last_volume && !empty($series['volumes'])) {
            $last_volume = end($series['volumes']);
            $current_series[] = [
                'id' => $series['id'],
                'name' => $series['name'],
                'last_volume' => $last_volume['number'],
                'last_volume_added_at' => $last_volume['added_at'] ?? 'Inconnue'
            ];
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'series' => $current_series]);
    exit;
}

// Éditer une série de la liste d'envies
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_wishlist'])) {
    $index = (int)($_POST['index'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $publisher = trim($_POST['publisher'] ?? '');

    $wishlist = load_wishlist();
    $result = edit_wishlist_item($wishlist, $index, $name, $author, $publisher);
    if ($result['success']) {
        save_wishlist($result['wishlist']);
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Gestion des actions pour "lues ailleurs"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_read'])) {
    $name = trim($_POST['read_name'] ?? '');
    $author = trim($_POST['read_author'] ?? '');
    $publisher = trim($_POST['read_publisher'] ?? '');
    $volumes_read = (int)($_POST['read_volumes'] ?? 0);
    $status = $_POST['read_status'] ?? 'terminé';

    $read = load_read();
    $result = add_to_read($read, $name, $author, $publisher, $volumes_read, $status);
    if ($result['success']) {
        save_read($result['read']);
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Supprimer une série de "lues ailleurs"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_from_read'])) {
    $index = $_POST['index'] ?? 0;
    $read = load_read();
    $result = remove_from_read($read, $index);
    if ($result['success']) {
        save_read($result['read']);
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Ajouter une série de "lues ailleurs" à la collection principale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_from_read'])) {
    $index = $_POST['index'] ?? 0;
    $read = load_read();
    $result = add_from_read($data, $read, $index);
    if ($result['success']) {
        save_data($result['data']);
        save_read($result['read']);
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Déplacer une série vers "Lues ailleurs"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_to_read'])) {
    $series_id = $_POST['series_id'] ?? '';
    $read = load_read();
    $result = move_series_to_read($data, $read, $series_id);
    if ($result['success']) {
        save_data($result['data']);
        save_read($result['read']);
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Éditer une série de "lues ailleurs"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_read'])) {
    $index = (int)($_POST['index'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $publisher = trim($_POST['publisher'] ?? '');
    $volumes_read = (int)($_POST['volumes_read'] ?? 0);
    $status = $_POST['status'] ?? 'terminé';

    $read = load_read();
    $result = edit_read_item($read, $index, $name, $author, $publisher, $volumes_read, $status);
    if ($result['success']) {
        save_read($result['read']);
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($options['admin_page_title']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($options['site_description']) ?>">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div id="error-message" class="error-message">
            <?= $_SESSION['error_message'] ?>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success_message'])): ?>
        <?php
        $message = $_SESSION['success_message'];
        $is_warning = (strpos($message, 'attention') !== false);
        ?>
        <div class="alert <?php echo $is_warning ? 'alert-warning' : 'alert-success'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="container">
        <div class="logout-container">
            <a href="logout.php" class="logout-button" title="Déconnexion">
                <img src="https://api.iconify.design/mdi/logout.svg?color=white" alt="Déconnexion" width="24" height="24">
            </a>
        </div>
        <h1><?= htmlspecialchars($options['admin_page_title']) ?></h1>

        <!-- Barre de filtres et recherche -->
        <div class="filters">
            <form method="get">
                <input type="text" name="search" autocomplete="off" placeholder="Rechercher une série, un auteur ou un éditeur..."
                       value="<?= htmlspecialchars($search_term) ?>">
                <div class="sort-options">
                    <select name="sort_by">
                        <option value="name" <?= $sort_by === 'name' ? 'selected' : '' ?>>Trier par nom</option>
                        <option value="author" <?= $sort_by === 'author' ? 'selected' : '' ?>>Trier par auteur</option>
                        <option value="publisher" <?= $sort_by === 'publisher' ? 'selected' : '' ?>>Trier par éditeur</option>
                        <option value="categories" <?= $sort_by === 'categories' ? 'selected' : '' ?>>Trier par catégories</option>
                        <option value="volumes" <?= $sort_by === 'volumes' ? 'selected' : '' ?>>Trier par nombre de tomes</option>
                    </select>
                    <select name="sort_order">
                        <option value="asc" <?= $sort_order === 'asc' ? 'selected' : '' ?>>Ascendant</option>
                        <option value="desc" <?= $sort_order === 'desc' ? 'selected' : '' ?>>Descendant</option>
                    </select>
                </div>
                <button type="submit">Appliquer</button>
            </form>
        </div>

        <!-- Bouton Menu Mobile -->
        <button class="mobile-menu-button" id="mobile-menu-button">☰ Menu</button>

        <!-- Menu d'actions -->
        <div class="admin-menu" id="admin-menu">
            <button id="open-add-series-modal" class="button button-ats">Ajouter une série</button>
            <button id="open-add-multiple-volumes-modal" class="button button-ats">Ajouter des tomes</button>
            <button id="open-current-series-modal" class="button button-aos">Séries en cours</button>
            <button id="open-incomplete-series-modal" class="button button-aos">Séries incomplètes</button>
            <button id="open-read-modal" class="button button-otl">Lues ailleurs</button>
            <button id="open-loan-modal" class="button button-otl">Livres prêtés</button>
            <button id="open-wishlist-modal" class="button button-otl">Liste d'envies</button>
            <button id="open-options-modal" class="button button-opt">Options</button>
            <button id="open-tools-modal" class="button button-opt">Outils</button>
            <a href="index.php" class="button button-ext" target="_blank">Accueil ↗</a>
            <a href="stats.php" class="button button-ext" target="_blank">Statistiques ↗</a>
        </div>

        <!-- Modales -->
        <!-- Modale pour ajouter une série -->
        <div class="modal" id="add-series-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-add-series-modal">&times;</span>
                <h2>Ajouter une série</h2>
                <form method="post" enctype="multipart/form-data">
                    <p>Nom :</p>
                    <input type="text" name="name" id="add-series-name" placeholder="Nom de la série (obligatoire)" autocomplete="off" required>
                    <p>Auteur :</p>
                    <input type="text" name="author" id="add-series-author" placeholder="Nom de l'auteur (obligatoire)" autocomplete="off" required>
                    <p>Éditeur :</p>
                    <input type="text" name="publisher" id="add-series-publisher" placeholder="Nom de l'éditeur (obligatoire)" autocomplete="off" required>
                    <p>Autres contributeurs :</p>
                    <input type="text" name="other_contributors" id="add-series-other-contributors" placeholder="Autres contributeurs (séparés par des virgules) (facultatif)" autocomplete="off">
                    <p>Catégories :</p>
                    <input type="text" name="categories" id="add-series-categories" placeholder="Catégories (séparées par des virgules) (obligatoire)" autocomplete="off" required>
                    <p>Genres :</p>
                    <input type="text" name="genres" id="add-series-genres" placeholder="Genres (séparés par des virgules) (facultatif)" autocomplete="off">
                    <p>Nombre de tomes à créer :</p>
                    <input type="number" name="volumes_count" id="volumes_count" placeholder="Nombre de tomes" min="1" value="1" autocomplete="off">
                    <p>Statut des tomes :</p>
                    <select name="volumes_status" id="volumes_status" required>
                        <option value="à lire">À lire</option>
                        <option value="en cours">En cours</option>
                        <option value="terminé">Terminé</option>
                    </select>
                    <label>
                        <input type="checkbox" name="all_collector"> Tous en collector ⭐
                    </label>
                    <label>
                        <input type="checkbox" name="last_volume"> Série terminée ✅
                    </label>
                    <p class="hint">Le tome final sera tagué comme dernier de la série.</p>
                    <p>ID Anilist :</p>
                    <input type="text" name="anilist_id" placeholder="ID Anilist (facultatif)" autocomplete="off">
                    <p class="hint"><a tabindex="0" data-hint="L'ID Anilist est utilisé pour trouver les tomes manquants des sériées terminées, plus d'infos dans l'outil « Séries incomplètes ». Pour trouver cet identifiant, rendez-vous sur anilist.co, recherchez votre série et accédez à sa fiche, l'ID est la suite de chiffres avant le nom dans l'url.">À quoi ça sert ? Où le trouver ?</a>.</p>
                    <label>
                        <input type="checkbox" name="mature"> Contenu mature 🔞
                    </label>
                    <label>
                        <input type="checkbox" name="favorite"> Série favorite ❤️
                    </label>
                    <p>Vignette :</p>
                    <input type="file" name="image" accept="image/jpeg, image/jpg, image/png, image/gif, image/webp">
                    <p class="hint">Extensions autorisées : jpeg, jpg, png, gif et webp. Poids maximum : 5 Mo.</p>
                    <input type="hidden" id="add-volume-series-id" name="series_id">
                    <button type="submit" name="add_series">Ajouter</button>
                </form>
            </div>
        </div>

        <!-- Modale pour les séries en cours -->
        <div class="modal" id="current-series-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-current-series-modal">&times;</span>
                <h2>Séries en cours de publication</h2>
                <p>Voici la liste de vos séries en cours de publication (sans le tag "dernier tome").</p>
                <div id="current-series-list">
                    <!-- La liste sera remplie par JavaScript -->
                </div>
            </div>
        </div>

        <!-- Modale pour les séries incomplètes -->
        <div class="modal" id="incomplete-series-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-incomplete-series-modal">&times;</span>
                <h2>Séries incomplètes</h2>
                <p>Cet outil vous permet de trouver les séries qui son terminées, pour lesquelles il vous manque des tomes.</p>
                <p>Attention, nous utilisons l'API d'Anilist, qui se base sur les dates des publications japonaises uniquement, il peut y avoir un décalage de sortie avec la France.</p>
                <p>Merci de noter qu'à cause des limitations de l'API d'Anilist, nous ne pouvons pas identifier les tomes manquants des séries en cours de publication.</p>
                <button id="search-incomplete-series" class="button">Rechercher les séries incomplètes</button>
                <div id="incomplete-series-results">
                    <!-- Les résultats seront affichés ici -->
                </div>
            </div>
        </div>

        <!-- Modale pour ajouter plusieurs tomes -->
        <div class="modal" id="add-multiple-volumes-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-add-multiple-volumes-modal">&times;</span>
                <h2>Ajouter des tomes</h2>
                <form method="post">
                    <p>Choisir une série :</p>
                    <input type="text" id="multiple-series-search" class="series-search" placeholder="Rechercher une série..." autocomplete="off">
                    <div class="series-results" id="multiple-series-results">
                        <?php foreach ($data as $series): ?>
                            <div data-id="<?= $series['id'] ?>"><?= $series['name'] ?></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="series_id" id="multiple-selected-series-id" required>
                    <p>Nombre de tomes à ajouter :</p>
                    <input type="number" name="volumes_count" id="volumes_count" placeholder="Nombre de tomes" min="1" value="1" autocomplete="off">
                    <p>Statut des tomes :</p>
                    <select name="status" required>
                        <option value="à lire">À lire</option>
                        <option value="en cours">En cours</option>
                        <option value="terminé">Terminé</option>
                    </select>
                    <label>
                        <input type="checkbox" name="is_collector"> Collector ⭐
                    </label>
                    <p class="hint">Tous seront tagués ainsi.</p>
                    <label>
                        <input type="checkbox" name="is_last"> Dernier tome ✅
                    </label>
                    <p class="hint">Seul le dernier sera tagué comme tel.</p>
                    <button type="submit" name="add_multiple_volumes">Ajouter</button>
                </form>
            </div>
        </div>

        <!-- Modale pour éditer un tome -->
        <div class="modal" id="edit-volume-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-edit-volume-modal">&times;</span>
                <h2>Éditer le tome</h2>
                <form method="post">
                    <input type="hidden" name="series_id" id="edit-series-id">
                    <input type="hidden" name="volume_index" id="edit-volume-index">
                    <p id="edit-volume-number-display" class="volume-number-display"></p>
                    <select name="status" required>
                        <option value="à lire">À lire</option>
                        <option value="en cours">En cours</option>
                        <option value="terminé">Terminé</option>
                    </select>
                    <label>
                        <input type="checkbox" name="is_collector"> Collector ⭐
                    </label>
                    <label>
                        <input type="checkbox" name="is_last"> Dernier tome ✅
                    </label>
                    <div class="modal-actions">
                        <button type="submit" name="update_volume">Mettre à jour</button>
                        <button type="button" id="delete-volume-btn" class="delete-btn">Supprimer ce tome</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modale pour modifier une série -->
        <div class="modal" id="edit-series-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-edit-series-modal">&times;</span>
                <h2>Modifier la série</h2>
                <form method="post" enctype="multipart/form-data" id="edit-series-form">
                    <input type="hidden" name="series_id" id="edit-series-id-input">
                    <p>Nom :</p>
                    <input type="text" name="edit_name" id="edit-series-name" placeholder="Nom de la série" autocomplete="off" required>
                    <p>Auteur :</p>
                    <input type="text" name="edit_author" id="edit-series-author" placeholder="Auteur" autocomplete="off" required>
                    <p>Éditeur :</p>
                    <input type="text" name="edit_publisher" id="edit-series-publisher" placeholder="Éditeur" autocomplete="off" required>
                    <p>Autres contributeurs :</p>
                    <input type="text" name="edit_other_contributors" id="edit-series-other-contributors" placeholder="Autres contributeurs (séparés par des virgules) (facultatif)" autocomplete="off">
                    <p>Catégories :</p>
                    <input type="text" name="edit_categories" id="edit-series-categories" placeholder="Catégories (séparées par des virgules)" autocomplete="off" required>
                    <p>Genres :</p>
                    <input type="text" name="edit_genres" id="edit-series-genres" placeholder="Genres (séparés par des virgules)" autocomplete="off">
                    <p>ID Anilist (facultatif) :</p>
                    <input type="text" name="edit_anilist_id" id="edit-series-anilist-id" placeholder="ID Anilist (facultatif)" autocomplete="off">
                    <p>Nombre de nouveaux tomes à créer :</p>
                    <input type="number" name="new_volumes_count" id="edit-series-new-volumes-count" placeholder="Nombre de nouveaux tomes" min="0" value="0" autocomplete="off">
                    <p>Statut des nouveaux tomes :</p>
                    <select name="new_volumes_status" id="edit-series-new-volumes-status">
                        <option value="à lire">À lire</option>
                        <option value="en cours">En cours</option>
                        <option value="terminé">Terminé</option>
                    </select>
                    <label>
                        <input type="checkbox" name="new_volumes_collector"> Tous en collector ⭐
                    </label>
                    <label>
                        <input type="checkbox" name="new_volumes_last"> Série terminée ✅
                    </label>
                    <p class="hint">Le dernier sera tagué comme le tome final de la série.</p>
                    <label>
                        <input type="checkbox" name="edit_mature" id="edit-series-mature"> Contenu mature 🔞
                    </label>
                    <label>
                        <input type="checkbox" name="edit_favorite" id="edit-series-favorite" <?= isset($series['favorite']) && $series['favorite'] ? 'checked' : '' ?>> Série favorite ❤️
                    </label>
                    <div class="current-image-container">
                        <p>Vignette actuelle :</p>
                        <img id="current-series-image" src="" alt="Image actuelle" style="max-width: 100px; margin-bottom: 10px;">
                        <input type="checkbox" name="remove_image" id="remove-image-checkbox">
                        <label for="remove-image-checkbox">Supprimer l'image</label>
                    </div>
                    <input type="file" name="edit_image" id="edit-series-image" accept="image/jpeg, image/jpg, image/png, image/gif, image/webp">
                    <p class="hint">Extensions autorisées : jpeg, jpg, png, gif et webp. Poids maximum : 5 Mo.</p>
                    <button type="submit" name="update_series">Mettre à jour</button>
                </form>
            </div>
        </div>

        <!-- Modale pour "Lues ailleurs" -->
        <div class="modal" id="read-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-read-modal">&times;</span>
                <h2>Séries lues ailleurs</h2>
                <p>Cette section vous permet de garder une trace des séries que vous avez lues mais que vous ne possédez pas dans votre collection (lues chez un ami/en bibliothèque, revendues depuis, etc.).</p>
                <form id="add-to-read-form">
                    <h3>Ajouter une série</h3>
                    <p>Nom :</p>
                    <input type="text" id="read-name" placeholder="Nom de la série" required>
                    <p>Auteur :</p>
                    <input type="text" id="read-author" placeholder="Nom de l'auteur" required>
                    <p>Éditeur :</p>
                    <input type="text" id="read-publisher" placeholder="Nom de l'éditeur" required>
                    <p>Nombre de tomes lus :</p>
                    <input type="number" id="read-volumes" placeholder="Nombre de tomes lus" min="1" required>
                    <p>Statut :</p>
                    <select id="read-status" required>
                        <option value="terminé">Terminé</option>
                        <option value="en cours">En cours</option>
                    </select>
                    <button type="button" id="add-to-read-btn">Ajouter</button>
                </form>
                <br>
                <div class="search-container">
                    <input type="text" id="read-search" placeholder="Rechercher...">
                </div>
                <div id="read-list"></div>
            </div>
        </div>

        <!-- Modale pour éditer une série "lue ailleurs" -->
        <div class="modal" id="edit-read-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-edit-read-modal">&times;</span>
                <h2>Éditer une série</h2>
                <form id="edit-read-form">
                    <input type="hidden" id="edit-read-index">
                    <p>Nom :</p>
                    <input type="text" id="edit-read-name" required>
                    <p>Auteur :</p>
                    <input type="text" id="edit-read-author" required>
                    <p>Éditeur :</p>
                    <input type="text" id="edit-read-publisher" required>
                    <p>Nombre de tomes lus :</p>
                    <input type="number" id="edit-read-volumes" min="1" required>
                    <p>Statut :</p>
                    <select id="edit-read-status" required>
                        <option value="terminé">Terminé</option>
                        <option value="en cours">En cours</option>
                    </select>
                    <button type="submit">Enregistrer</button>
                </form>
            </div>
        </div>

        <!-- Modale pour les livres prêtés -->
        <div class="modal" id="loan-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-loan-modal">&times;</span>
                <h2>Livres prêtés</h2>

                <!-- Ajouter un prêt (un seul tome) -->
                <div class="loan-section">
                    <h3>Ajouter un tome prêté</h3>
                    <form id="add-single-loan-form">
                        <p>Choisir une série :</p>
                        <input type="text" id="loan-series-search" class="series-search" placeholder="Rechercher une série..." autocomplete="off">
                        <div class="series-results" id="loan-series-results">
                            <?php foreach ($data as $series): ?>
                                <div data-id="<?= $series['id'] ?>"><?= $series['name'] ?></div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="series_id" id="loan-selected-series-id" required>
                        <p>Numéro du tome :</p>
                        <input type="number" inputmode="numeric" name="volume_number" placeholder="Numéro du tome" min="1" autocomplete="off" required>
                        <p>Nom de l'emprunteur :</p>
                        <input type="text" name="borrower_name" placeholder="Nom de l'emprunteur" autocomplete="off" required>
                        <button type="submit" class="button">Ajouter</button>
                    </form>
                </div>

                <!-- Ajouter un prêt (plusieurs tomes) -->
                <div class="loan-section">
                    <h3>Ajouter des tomes prêtés en lot</h3>
                    <form id="add-multiple-loans-form">
                        <p>Choisir une série :</p>
                        <input type="text" id="multiple-loan-series-search" class="series-search" placeholder="Rechercher une série..." autocomplete="off">
                        <div class="series-results" id="multiple-loan-series-results">
                            <?php foreach ($data as $series): ?>
                                <div data-id="<?= $series['id'] ?>"><?= $series['name'] ?></div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="series_id" id="multiple-loan-selected-series-id" required>
                        <p>Plage de tomes :</p>
                        <div class="volume-range">
                            <input type="number" inputmode="numeric" name="start_volume" placeholder="Numéro de début" min="1" autocomplete="off" required>
                            <span>à</span>
                            <input type="number" inputmode="numeric" name="end_volume" placeholder="Numéro de fin" min="1" autocomplete="off" required>
                        </div>
                        <p>Nom de l'emprunteur :</p>
                        <input type="text" name="borrower_name" placeholder="Nom de l'emprunteur" autocomplete="off" required>
                        <button type="submit" class="button">Ajouter</button>
                    </form>
                </div>

                <!-- Liste des prêts -->
                <div class="loan-section">
                    <h3>Liste des livres prêtés</h3>
                    <input type="text" id="loan-search" placeholder="Rechercher une série ou un emprunteur..." autocomplete="off">
                    <div id="loan-list">
                        <!-- Les prêts seront affichés ici -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Modale pour la liste d'envies -->
        <div class="modal" id="wishlist-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-wishlist-modal">&times;</span>
                <h2>Liste d'envies</h2>
                <div class="wishlist-container">
                    <div class="wishlist-header">
                        <input type="text" id="wishlist-name" placeholder="Nom de la série" autocomplete="off" required>
                        <input type="text" id="wishlist-author" placeholder="Auteur" autocomplete="off" required>
                        <input type="text" id="wishlist-publisher" placeholder="Éditeur" autocomplete="off" required>
                        <button id="add-to-wishlist-btn" class="button">Ajouter à la liste</button>
                        <input type="text" id="wishlist-search" placeholder="Rechercher une série, un auteur ou un éditeur..." autocomplete="off">
                        <div class="wishlist-item">
                            <span class="wishlist-series-name">Nom de la série</span>
                            <span class="wishlist-series-author">Auteur</span>
                            <span class="wishlist-series-publisher">Éditeur</span>
                        </div>
                    </div>
                    <div class="wishlist-list" id="wishlist-list">
                        <?php foreach (load_wishlist() as $index => $item): ?>
                            <div class="wishlist-item" data-index="<?= $index ?>">
                                <span class="wishlist-series-name"><?= $item['name'] ?></span>
                                <span class="wishlist-series-author"><?= $item['author'] ?></span>
                                <span class="wishlist-series-publisher"><?= $item['publisher'] ?></span>
                                <div class="wishlist-item-actions">
                                    <button class="add-from-wishlist-btn" data-index="<?= $index ?>">+</button>
                                    <button class="edit-wishlist-btn" data-index="<?= $index ?>">...</button>
                                    <button class="remove-from-wishlist-btn" data-index="<?= $index ?>">x</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Modale pour éditer une série de la liste d'envies -->
        <div class="modal" id="edit-wishlist-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-edit-wishlist-modal">&times;</span>
                <h2>Éditer une série de la liste d'envies</h2>
                <form id="edit-wishlist-form">
                    <input type="hidden" id="edit-wishlist-index">
                    <p>Nom :</p>
                    <input type="text" id="edit-wishlist-name" placeholder="Nom de la série" autocomplete="off" required>
                    <p>Auteur :</p>
                    <input type="text" id="edit-wishlist-author" placeholder="Nom de l'auteur" autocomplete="off" required>
                    <p>Éditeur :</p>
                    <input type="text" id="edit-wishlist-publisher" placeholder="Nom de l'éditeur" autocomplete="off" required>
                    <button type="submit" class="button">Mettre à jour</button>
                </form>
            </div>
        </div>

        <!-- Modale pour les options du site -->
        <div class="modal" id="options-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-options-modal">&times;</span>
                <h2>Options du site</h2>
                <?php
                $latest_version = get_latest_version_from_gitea();
                $current_version = SITE_VERSION;
                $version_class = '';
                $version_tooltip = '';
                if ($latest_version && version_compare($current_version, $latest_version, '<')) {
                    $version_class = 'version-outdated';
                    $version_tooltip = "Une nouvelle version ($latest_version) est disponible ! Il est recommandé de mettre à jour.";
                }
                ?>
                <p class="hint <?= $version_class ?>" data-tooltip="<?= htmlspecialchars($version_tooltip) ?>">
                    Site en version <?= $current_version ?>.
                    <a href="<?= URL_GITEA ?>" target="_blank">Accéder au dépôt Gitéa</a>.
                </p>
                <form id="options-form" method="post" enctype="multipart/form-data">
                    <label for="site-name">Nom du site</label>
                    <input type="text" name="site_name" id="site-name" placeholder="Nom du site" value="<?= htmlspecialchars($options['site_name']) ?>" required>

                    <label for="site-description">Description du site</label>
                    <input type="text" name="site_description" id="site-description" placeholder="Description du site" value="<?= htmlspecialchars($options['site_description']) ?>" required>

                    <label for="index-page-title">Titre de la page d'accueil</label>
                    <input type="text" name="index_page_title" id="index-page-title" placeholder="Titre de la page d'accueil" value="<?= htmlspecialchars($options['index_page_title']) ?>" required>

                    <label for="admin-page-title">Titre de la page d'administration</label>
                    <input type="text" name="admin_page_title" id="admin-page-title" placeholder="Titre de la page d'administration" value="<?= htmlspecialchars($options['admin_page_title']) ?>" required>

                    <label for="stats-page-title">Titre de la page de statistiques</label>
                    <input type="text" name="stats_page_title" id="stats-page-title" placeholder="Titre de la page de statistiques" value="<?= htmlspecialchars($options['stats_page_title']) ?>" required>

                    <label for="custom-button-name">Nom du bouton personnalisé</label>
                    <input type="text" name="custom_button_name" id="custom-button-name" placeholder="Nom du bouton" value="<?= htmlspecialchars($options['custom_button_name'] ?? '') ?>">

                    <label for="custom-button-url">URL du bouton personnalisé</label>
                    <input type="text" name="custom_button_url" id="custom-button-url" placeholder="URL du bouton" value="<?= htmlspecialchars($options['custom_button_url'] ?? '') ?>">
                    <p class="hint">Laisser vide pour masquer le bouton.</p>

                    <label for="custom-button-name2">Nom du bouton personnalisé (2)</label>
                    <input type="text" name="custom_button_name2" id="custom-button-name2" placeholder="Nom du bouton" value="<?= htmlspecialchars($options['custom_button_name2'] ?? '') ?>">

                    <label for="custom-button-url2">URL du bouton personnalisé (2)</label>
                    <input type="text" name="custom_button_url2" id="custom-button-url2" placeholder="URL du bouton" value="<?= htmlspecialchars($options['custom_button_url2'] ?? '') ?>">
                    <p class="hint">Laisser vide pour masquer le bouton.</p>

                    <label for="custom-button-name3">Nom du bouton personnalisé (3)</label>
                    <input type="text" name="custom_button_name3" id="custom-button-name3" placeholder="Nom du bouton" value="<?= htmlspecialchars($options['custom_button_name3'] ?? '') ?>">

                    <label for="custom-button-url3">URL du bouton personnalisé (3)</label>
                    <input type="text" name="custom_button_url3" id="custom-button-url3" placeholder="URL du bouton" value="<?= htmlspecialchars($options['custom_button_url3'] ?? '') ?>">
                    <p class="hint">Laisser vide pour masquer le bouton.</p>

                    <label for="admin-password">Mot de passe admin</label>
                    <input type="password" name="admin_password" id="admin-password" placeholder="Mot de passe admin">
                    <p class="hint">Laisser vide pour ne pas modifier.</p>

                    <label>
                        <input type="checkbox" name="private_mode" <?= $options['private_mode'] ? 'checked' : '' ?>> Mode privé
                    </label>
                    <p class="hint">Votre bibliothèque ne sera pas visible publiquement.</p>

                    <label>
                        <input type="checkbox" name="hide_mature" <?= $options['hide_mature'] ? 'checked' : '' ?>> Masquer les séries matures
                    </label>

                    <div class="form-group">
                        <br>
                        <label for="default_logo">Remplacer la vignette par défaut :</label>
                        <input type="file" id="default_logo" name="default_logo" accept="image/png">
                        <p class="hint">L'image uploadée remplacera le fichier logo.png actuel (PNG obligatoire).</p>
                        <p class="hint">Vignette par défaut actuelle :</p>
                        <?php if (file_exists('logo.png')): ?>
                            <div>
                                <img src="logo.png?v=<?= time() ?>" alt="Logo actuel" style="max-width: 100px; max-height: 100px;">
                            </div>
                        <?php endif; ?>
                        <br>
                    </div>

                    <button type="submit" name="update_options" class="button button-opt">Mettre à jour</button>
                    <p style="visibility: hidden;">_</p>
                </form>
            </div>
        </div>

        <!-- Modale pour les outils -->
        <!-- Sauvegardes -->
        <div class="modal" id="tools-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-tools-modal">&times;</span>
                <h2>Outils de sauvegarde</h2>
                <p>Vous pouvez ici sauvegarder vos données. Les fichiers concernés sont : la base de données de votre bibliothèque et leurs images, la liste de vos envies, la liste de vos prêts, ainsi que les options du site.</p>

                <div class="tools-section">
                    <h3>Créer une sauvegarde</h3>
                    <p>Crée une archive de vos données actuelles.</p>
                    <button id="create-backup-btn" class="button button-opt">
                        <span id="create-backup-text">Créer une sauvegarde</span>
                        <span id="create-backup-spinner" class="spinner" style="display: none;"></span>
                    </button>
                </div>

                <div class="tools-section">
                    <h3>Liste des sauvegardes</h3>
                    <p>Vous pouvez télécharger ou supprimer vos sauvegardes.</p>
                    <div id="backups-list">
                        <!-- Les sauvegardes seront affichées ici -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Modale pour les alertes personnalisées -->
        <div class="modal" id="custom-alert-modal">
            <div class="modal-content">
                <h2 id="custom-alert-title">Avertissement</h2>
                <p id="custom-alert-message"></p>
                <button id="custom-alert-ok" class="button">OK</button>
            </div>
        </div>

        <!-- Modale pour les confirmations personnalisées -->
        <div class="modal" id="custom-confirm-modal">
            <div class="modal-content">
                <h2 id="custom-confirm-title">Confirmation</h2>
                <p id="custom-confirm-message"></p>
                <div class="modal-actions">
                    <button id="custom-confirm-ok" class="button">OK</button>
                    <button id="custom-confirm-cancel" class="button">Annuler</button>
                </div>
            </div>
        </div>

        <!-- Liste des séries -->
         <div class="series-list" id="series-list">
            <!-- Le contenu sera chargé dynamiquement par JavaScript -->
            <?php if (empty($data)): ?>
                <p>Aucune série trouvée.</p>
            <?php endif; ?>
        </div>
        <div class="loading-spinner" id="loading-spinner">
            <p>Chargement en cours...</p>
        </div>

    </div>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <script>
        window.seriesData = <?= json_encode($filtered_data) ?>;
    </script>
    <script src="assets/js/admin/modals.js"></script>
    <script src="assets/js/admin/autocomplete.js"></script>
    <script src="assets/js/admin/series.js"></script>
    <script src="assets/js/admin/volumes.js"></script>
    <script src="assets/js/admin/wishlist.js"></script>
    <script src="assets/js/admin/loans.js"></script>
    <script src="assets/js/admin/tools.js"></script>
    <script src="assets/js/admin/pagination.js"></script>
    <script src="assets/js/admin/main.js"></script>
    <script src="assets/js/admin/read.js"></script>

</body>
</html>