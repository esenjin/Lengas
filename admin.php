<?php
require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require_once 'includes/status_filter.php';
require 'includes/mangaupdates.php';
require_once 'includes/babengas.php';
require 'fonctions/series.php';
require 'fonctions/volumes.php';
require 'fonctions/wishlist.php';
require 'fonctions/loans.php';
require 'fonctions/read.php';
require 'fonctions/options.php';
require 'fonctions/tools.php';
require 'fonctions/reviews.php';
require 'includes/custom_icons.php';
require 'includes/themes.php';
require_once 'includes/vestikan.php';

$data = load_data();
$options = load_options();

// Ensemble des IDs de séries possédant une critique (pour badges / filtre)
$review_series_ids = array_flip(get_review_series_ids());

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page_admin = 9;
$offset = ($page - 1) * $per_page_admin;

// Gestion des actions pour les séries
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_series'])) {
    $name = trim($_POST['name'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $publisher = trim($_POST['publisher'] ?? '');
    $other_contributors = trim($_POST['other_contributors'] ?? '');
    $categories = trim($_POST['categories'] ?? '');
    $genres = trim($_POST['genres'] ?? '');
    $mangaupdates_url = trim($_POST['mangaupdates_url'] ?? '');
    $babelio_url = trim($_POST['babelio_url'] ?? '');
    $mature = !empty($_POST['mature']);
    $favorite = !empty($_POST['favorite']);
    $volumes_count = (int)($_POST['volumes_count'] ?? 1);
    $volumes_status = $_POST['volumes_status'] ?? 'à lire';
    $all_collector = !empty($_POST['all_collector']);
    $last_volume = !empty($_POST['last_volume']);
    $status        = $_POST['series_status'] ?? 'en cours';
    $read_elsewhere = !empty($_POST['read_elsewhere']);
    $reading_abandoned = !empty($_POST['reading_abandoned']);
    $rating = sanitize_rating($_POST['rating'] ?? '');

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
    $result = add_series($data, $name, $author, $publisher, $other_contributors, $categories, $genres, $mangaupdates_url, $babelio_url, $mature, $favorite, $volumes_count, $volumes_status, $all_collector, $last_volume, $image, $status, $read_elsewhere, $reading_abandoned, $rating);

    if ($result['success']) {
        save_data($result['data']);
        // Réchauffer le cache MangaUpdates pour la nouvelle série
        if ($mangaupdates_url !== '') {
            $mu_id = mangaupdates_get_id_from_url($mangaupdates_url);
            if ($mu_id !== null) @mangaupdates_get_volumes($mu_id, true);
        }
        $_SESSION['success_message'] = $result['message'];
    } else {
        $_SESSION['error_message'] = $result['message'];
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
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

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// Mettre à jour un tome
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_volume'])) {
    $series_id = $_POST['series_id'] ?? '';
    $volume_index = (int)($_POST['volume_index'] ?? 0);
    $status = $_POST['status'] ?? 'à lire';
    $is_collector = !empty($_POST['is_collector']);
    $is_last = !empty($_POST['is_last']);
    $read_at = trim($_POST['read_at'] ?? '');
    // Validation basique du format de date (évite d'enregistrer une valeur invalide)
    if ($read_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $read_at)) {
        $read_at = null;
    }

    $result = update_volume($data, $series_id, $volume_index, $status, $is_collector, $is_last, $read_at);
    if ($result['success']) {
        // Option : propager le statut de lecture à tous les tomes de la série.
        if (!empty($_POST['apply_status_all'])) {
            $batch = apply_status_to_all_volumes($result['data'], $series_id, $status, $read_at);
            if ($batch['success']) {
                $result['data'] = $batch['data'];
            }
        }
        save_data($result['data']);
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
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

    header("Location: " . $_SERVER['REQUEST_URI']);
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
    $mangaupdates_url = trim($_POST['edit_mangaupdates_url'] ?? '');
    $babelio_url = trim($_POST['edit_babelio_url'] ?? '');
    $mature = !empty($_POST['edit_mature']);
    $favorite = !empty($_POST['edit_favorite']);
    $remove_image = !empty($_POST['remove_image']);
    $new_volumes_count = (int)($_POST['new_volumes_count'] ?? 0);
    $new_volumes_status = $_POST['new_volumes_status'] ?? 'à lire';
    $new_volumes_collector = !empty($_POST['new_volumes_collector']);
    $new_volumes_last = !empty($_POST['new_volumes_last']);
    $new_status         = $_POST['series_status'] ?? null;
    $edit_read_elsewhere = !empty($_POST['edit_read_elsewhere']);
    $edit_reading_abandoned = !empty($_POST['edit_reading_abandoned']);
    $edit_rating = sanitize_rating($_POST['edit_rating'] ?? '');

    $new_image = null;
    if (!empty($_FILES['edit_image']['name'])) {
        $error_message = null;
        $new_image = upload_image($_FILES['edit_image'], $error_message);
        if ($new_image === false) {
            $_SESSION['error_message'] = $error_message ?: "Erreur inconnue lors du téléversement de l'image.";
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }

    $result = update_series($data, $series_id, $name, $author, $other_contributors, $publisher, $categories, $genres, $mangaupdates_url, $babelio_url, $mature, $favorite, $remove_image, $new_volumes_count, $new_volumes_status, $new_volumes_collector, $new_volumes_last, $new_image, $new_status, $edit_read_elsewhere, $edit_reading_abandoned, $edit_rating);
    if ($result['success']) {
        save_data($result['data']);
        // Réchauffer le cache MangaUpdates pour la série modifiée
        if ($mangaupdates_url !== '') {
            $mu_id = mangaupdates_get_id_from_url($mangaupdates_url);
            if ($mu_id !== null) @mangaupdates_get_volumes($mu_id, true);
        }
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// Supprimer une série
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_series'])) {
    $series_id = $_POST['series_id'] ?? '';
    $result = delete_series($data, $series_id);
    if ($result['success']) {
        save_data($result['data']);
        $_SESSION['success_message'] = $result['message'];
        echo "OK";
    } else {
        $_SESSION['error_message'] = $result['message'];
        echo $result['message'];
    }
    exit;
}

// Note : la mise à jour des options du site est désormais gérée par la page
// dédiée « Options » (page-options.php), à l'image de la page « Outils ».

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

// Gestion de la pagination des séries
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_paginated_series'])) {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 9;
    $search_term = $_GET['search'] ?? '';
    $sort_by = $_GET['sort_by'] ?? 'name';
    $sort_order = $_GET['sort_order'] ?? 'asc';
    $light_mode = isset($_GET['light']) && $_GET['light'] === 'true';
    $status_filter = $_GET['status_filter'] ?? '';
    $status_mode   = $_GET['status_mode'] ?? 'or';

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
    $filtered_data = apply_status_filter(
        $filtered_data,
        $status_filter,
        $status_mode,
        function($series) use ($review_series_ids) {
            return isset($review_series_ids[$series['id']]);
        }
    );
    sort_series($filtered_data, $sort_by, $sort_order);

    $offset = ($page - 1) * $per_page;
    $paginated_data = array_slice($filtered_data, $offset, $per_page);

    // En mode "light", on ne renvoie que les métadonnées
    if ($light_mode) {
        $light_series = array_map(function($series) use ($review_series_ids) {
            // Détermine le statut de publication
            $status = 'en cours';
            $has_last = false;
            if (isset($series['volumes']) && is_array($series['volumes'])) {
                foreach ($series['volumes'] as $volume) {
                    if (!empty($volume['last'])) {
                        $has_last = true;
                        $status = 'terminée';
                        break;
                    }
                }
            }
            if (isset($series['status'])) {
                $status = $series['status'];
            }

            // Calcule le statut de lecture
            $reading_status = 'not_started';
            if (!empty($series['reading_abandoned'])) {
                $reading_status = 'abandoned';
            } else {
                $read_count = 0;
                $total_count = 0;
                foreach ($series['volumes'] ?? [] as $volume) {
                    $total_count++;
                    if ($volume['status'] === 'terminé') $read_count++;
                }
                if ($total_count > 0 && $read_count === $total_count && $has_last) {
                    $reading_status = 'completed';
                } elseif ($read_count > 0 && !$has_last) {
                    $reading_status = 'in_progress';
                } elseif ($read_count > 0) {
                    // Des tomes lus mais publication terminée sans tous avoir lu
                    $reading_status = 'in_progress';
                }
            }

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
                'status' => $status,
                'reading_status' => $reading_status,
                'mangaupdates_url'           => $series['mangaupdates_url'] ?? '',
                'babelio_url'                => $series['babelio_url'] ?? '',
                'read_elsewhere'             => (bool)($series['read_elsewhere'] ?? false),
                'reading_abandoned'          => (bool)($series['reading_abandoned'] ?? false),
                'rating'                     => $series['rating'] ?? '',
                'has_review'                 => isset($review_series_ids[$series['id']]),
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

    // Notifications via MangaUpdates
    $ref_volumes = null;
    if (!empty($series['mangaupdates_url'])) {
        $mu_id = mangaupdates_get_id_from_url($series['mangaupdates_url']);
        if ($mu_id !== null) {
            $mu = mangaupdates_get_volumes($mu_id);
            if ($mu !== null && $mu['volumes'] !== null && (int)$mu['volumes'] > 0) {
                $ref_volumes = (int)$mu['volumes'];
            }
        }
    }
    $notifications = generate_notifications($series['volumes'], $ref_volumes);

    // Charger les prêts pour cette série
    $all_loans = load_loans();
    $loaned_volumes = [];
    foreach ($all_loans as $loan) {
        if ($loan['series_id'] === $series_id) {
            $loaned_volumes[$loan['volume_number']] = $loan['borrower_name'];
        }
    }

    // Générer le HTML des tomes
    $volumes_html = '<ul class="volumes-list">';
    foreach ($series['volumes'] as $volume_index => $volume) {
        $is_loaned = isset($loaned_volumes[$volume['number']]);

        // Construire l'infobulle (survol)
        $tooltip_lines = [];

        $format_date = function ($d) {
            if (empty($d)) return '';
            $ts = strtotime($d);
            return $ts ? date('d/m/Y', $ts) : '';
        };

        $added_at = $format_date($volume['added_at'] ?? '');
        if ($added_at !== '') {
            $tooltip_lines[] = "Date d'ajout à la collection : $added_at";
        }

        if (($volume['status'] ?? '') === 'terminé') {
            $read_at = $format_date($volume['read_at'] ?? '');
            if ($read_at !== '') {
                $tooltip_lines[] = "Date de lecture : $read_at";
            }
        }

        if (!empty($volume['collector'])) {
            $tooltip_lines[] = 'Tome collector !';
        }

        if (!empty($volume['last'])) {
            $tooltip_lines[] = 'Dernier tome de la série !';
        }

        if ($is_loaned) {
            $tooltip_lines[] = 'Prêté à ' . $loaned_volumes[$volume['number']];
        }

        $title_attr = !empty($tooltip_lines)
            ? ' data-title="' . htmlspecialchars(implode("\n", $tooltip_lines), ENT_QUOTES) . '"'
            : '';

        $volumes_html .= sprintf(
            '<li class="status-%s%s%s%s"%s data-series-id="%s" data-volume-index="%d">%d%s</li>',
            str_replace(' ', '-', strtolower($volume['status'])),
            !empty($volume['collector']) ? ' volume-collector' : '',
            !empty($volume['last']) ? ' volume-last' : '',
            $is_loaned ? ' volume-loaned' : '',
            $title_attr,
            $series_id,
            $volume_index,
            $volume['number'],
            $is_loaned ? '<span class="volume-loan-badge" aria-label="En prêt">🤝</span>' : ''
        );
    }
    // Bouton d'ajout rapide : ouvre la modale « Ajouter des tomes » avec la série
    // pré-sélectionnée. On le masque si le dernier tome de la liste est marqué comme
    // « dernier tome de la série » (collection réputée complète).
    $volumes = $series['volumes'];
    $last_volume = !empty($volumes) ? end($volumes) : null;
    $series_is_complete = $last_volume !== null && !empty($last_volume['last']);
    if (!$series_is_complete) {
        $volumes_html .= sprintf(
            '<li class="volume-add-btn" data-series-id="%s" title="Ajouter des tomes à cette série" aria-label="Ajouter des tomes">+</li>',
            htmlspecialchars($series_id, ENT_QUOTES)
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

    if (in_array($field, ['name', 'author', 'publisher', 'other_contributors', 'categories', 'genres'])) {
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
$status_filter = $_GET['status_filter'] ?? '';
$status_mode = $_GET['status_mode'] ?? 'or';

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


?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($options['admin_page_title']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($options['site_description']) ?>">
    <meta property="og:image" content="assets/img/logo.png">
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
    <?= theme_link_tag($options) ?>
</head>
<body class="with-sidebar">
    <?php include 'includes/sidebar.php'; ?>

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
        <h1><?= htmlspecialchars($options['admin_page_title']) ?></h1>

        <!-- Barre de filtres et recherche -->
        <div class="filters">
            <form method="get">
                <div class="search-row">
                    <input type="text" name="search" autocomplete="off" id="search-all" placeholder="Rechercher une série, un auteur, un éditeur, etc.."
                           value="<?= htmlspecialchars($search_term) ?>">
                    <button type="submit">Appliquer</button>
                </div>
                <div class="sort-options">
                    <select name="sort_by" id="sort-by">
                        <option value="name" <?= $sort_by === 'name' ? 'selected' : '' ?>>Trier par nom</option>
                        <option value="author" <?= $sort_by === 'author' ? 'selected' : '' ?>>Trier par auteur</option>
                        <option value="publisher" <?= $sort_by === 'publisher' ? 'selected' : '' ?>>Trier par éditeur</option>
                        <option value="categories" <?= $sort_by === 'categories' ? 'selected' : '' ?>>Trier par catégories</option>
                        <option value="volumes" <?= $sort_by === 'volumes' ? 'selected' : '' ?>>Trier par nombre de tomes</option>
                        <option value="added_at" <?= $sort_by === 'added_at' ? 'selected' : '' ?>>Trier par date d'ajout</option>
                        <option value="read_at" <?= $sort_by === 'read_at' ? 'selected' : '' ?>>Trier par date de lecture</option>
                    </select>
                    <select name="sort_order" id="sort-order">
                        <option value="asc" <?= $sort_order === 'asc' ? 'selected' : '' ?>>Ascendant</option>
                        <option value="desc" <?= $sort_order === 'desc' ? 'selected' : '' ?>>Descendant</option>
                    </select>
                    <?php render_status_filter($status_filter, $status_mode, true); ?>
                </div>
            </form>
        </div>

        <!-- Boutons déclencheurs de modales (cachés — crochet JS uniquement) -->
        <div id="modal-triggers" style="display:none">
            <button id="open-add-series-modal"></button>
            <button id="open-add-multiple-volumes-modal"></button>
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
                    <p>Statut de publication de la série :</p>
                    <select name="series_status" id="add-series-status" required>
                        <option value="en cours">En cours ▶️</option>
                        <option value="terminée">Terminée ✅</option>
                        <option value="en pause">En pause ⏳</option>
                        <option value="abandonnée">Abandonnée ⛔</option>
                    </select>
                    <p>URL MangaUpdates :</p>
                    <input type="text" name="mangaupdates_url" placeholder="https://www.mangaupdates.com/series/xxxxxxx/nom-de-la-serie (facultatif)" autocomplete="off">
                    <p class="hint"><a tabindex="0" data-hint="L'URL MangaUpdates sert à détecter les tomes manquants des séries terminées (outil « Séries incomplètes »). Sur mangaupdates.com, ouvrez la fiche de votre série puis copiez l'URL complète. L'outil « Associer MangaUpdates » (modale Outils) peut aussi remplir ce champ automatiquement.">À quoi ça sert ? Où la trouver ?</a></p>
                    <p>URL Babelio :</p>
                    <input type="text" name="babelio_url" placeholder="https://www.babelio.com/serie/… (ou …/livres/… pour un one-shot)" autocomplete="off">
                    <p class="hint"><a tabindex="0" data-hint="L'URL Babelio permet de connaître le nombre de tomes réellement parus en France, via le service Babengas (onglet « Vérification Babelio » de la page Outils). Sur babelio.com, ouvrez la fiche SÉRIE (adresse en /serie/…) et copiez l'URL complète. Pour un one-shot (un seul tome, sans fiche série), collez l'adresse de la fiche du tome (/livres/…).">À quoi ça sert ? Où la trouver ?</a></p>
                    <label>
                        <input type="checkbox" name="mature"> Contenu mature 🔞
                    </label>
                    <label>
                        <input type="checkbox" name="favorite"> Série favorite ❤️
                    </label>
                    <label>
                        <input type="checkbox" name="read_elsewhere" id="add-series-read-elsewhere"> Lue ailleurs 📖
                    </label>
                    <p class="hint">Cochez si vous avez lu cette série sans la posséder (chez un ami, en bibliothèque, revendue, etc.).</p>
                    <label>
                        <input type="checkbox" name="reading_abandoned" id="add-series-reading-abandoned"> Lecture abandonnée 📕
                    </label>
                    <p class="hint">Cochez si vous avez arrêté de lire cette série.</p>
                    <p>Notation (facultatif) :</p>
                    <select name="rating" id="add-series-rating">
                        <option value="">Aucune note ➖</option>
                        <option value="apprecie">J'ai apprécié ☺️</option>
                        <option value="mitige">Mi-figue mi-raisin 😑</option>
                        <option value="deteste">Je n'ai pas aimé 😠</option>
                    </select>
                    <p>Vignette :</p>
                    <input type="file" name="image" accept="image/jpeg, image/jpg, image/png, image/gif, image/webp">
                    <p class="hint">Extensions autorisées : jpeg, jpg, png, gif et webp. Poids maximum : 5 Mo.</p>
                    <input type="hidden" id="add-volume-series-id" name="series_id">
                    <button type="submit" name="add_series">Ajouter</button>
                </form>
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
                    <select name="status" id="edit-volume-status" required>
                        <option value="à lire">À lire</option>
                        <option value="en cours">En cours</option>
                        <option value="terminé">Terminé</option>
                    </select>
                    <label id="edit-volume-read-at-label" class="volume-read-at-label">
                        Date de lecture
                        <input type="date" name="read_at" id="edit-volume-read-at">
                    </label>
                    <label>
                        <input type="checkbox" name="apply_status_all" id="edit-volume-apply-status-all"> Appliquer ce statut de lecture à tous les tomes de la série 📚
                    </label>
                    <p class="hint">Le statut (et, le cas échéant, la date de lecture) sera copié sur tous les tomes de la série. Les tags collector / dernier tome ne sont pas affectés.</p>
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
                    <p>URL MangaUpdates (facultatif) :</p>
                    <input type="text" name="edit_mangaupdates_url" id="edit-series-mangaupdates-url" placeholder="https://www.mangaupdates.com/series/xxxxxxx/nom-de-la-serie" autocomplete="off">
                    <p>URL Babelio (facultatif) :</p>
                    <input type="text" name="edit_babelio_url" id="edit-series-babelio-url" placeholder="https://www.babelio.com/serie/… (ou …/livres/… pour un one-shot)" autocomplete="off">
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
                    <p>Statut de publication de la série :</p>
                    <select name="series_status" id="edit-series-status" required>
                        <option value="en cours">En cours ▶️</option>
                        <option value="terminée">Terminée ✅</option>
                        <option value="en pause">En pause ⏳</option>
                        <option value="abandonnée">Abandonnée ⛔</option>
                    </select>
                    <label>
                        <input type="checkbox" name="edit_mature" id="edit-series-mature"> Contenu mature 🔞
                    </label>
                    <label>
                        <input type="checkbox" name="edit_favorite" id="edit-series-favorite" <?= isset($series['favorite']) && $series['favorite'] ? 'checked' : '' ?>> Série favorite ❤️
                    </label>
                    <label>
                        <input type="checkbox" name="edit_read_elsewhere" id="edit-series-read-elsewhere"> Lue ailleurs 📖
                    </label>
                    <p class="hint">Cochez si vous avez lu cette série sans la posséder (chez un ami, en bibliothèque, revendue, etc.).</p>
                    <label>
                        <input type="checkbox" name="edit_reading_abandoned" id="edit-series-reading-abandoned"> Lecture abandonnée 📕
                    </label>
                    <p class="hint">Cochez si vous avez arrêté de lire cette série.</p>
                    <p>Notation (facultatif) :</p>
                    <select name="edit_rating" id="edit-series-rating">
                        <option value="">Aucune note ➖</option>
                        <option value="apprecie">J'ai apprécié ☺️</option>
                        <option value="mitige">Mi-figue mi-raisin 😑</option>
                        <option value="deteste">Je n'ai pas aimé 😠</option>
                    </select>
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

    <?php
        $series_with_status = array_map(function($series) use ($review_series_ids) {
            $status = $series['status'] ?? 'en cours';
            if (empty($series['status'])) {
                foreach ($series['volumes'] as $volume) {
                    if (!empty($volume['last'])) {
                        $status = 'terminée';
                        break;
                    }
                }
            }
            $series['status'] = $status;
            $series['has_review'] = isset($review_series_ids[$series['id']]);
            return $series;
        }, array_values($filtered_data));
    ?>
    <script>
        window.seriesData = <?= json_encode($series_with_status) ?>;
    </script>
    <script src="assets/js/admin/modals.js"></script>
    <script src="assets/js/admin/autocomplete.js"></script>
    <script src="assets/js/admin/series.js"></script>
    <script src="assets/js/admin/volumes.js"></script>
    <script src="assets/js/admin/pagination.js"></script>
    <script src="assets/js/admin/main.js"></script>

</body>
</html>