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
    $result = add_series($data, $name, $author, $publisher, $other_contributors, $categories, $genres, $mangaupdates_url, $babelio_url, $mature, $favorite, $volumes_count, $volumes_status, $all_collector, $last_volume, $image, $status, $read_elsewhere, $reading_abandoned);

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

    $result = update_series($data, $series_id, $name, $author, $other_contributors, $publisher, $categories, $genres, $mangaupdates_url, $babelio_url, $mature, $favorite, $remove_image, $new_volumes_count, $new_volumes_status, $new_volumes_collector, $new_volumes_last, $new_image, $new_status, $edit_read_elsewhere, $edit_reading_abandoned);
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
    $options['hide_reviews'] = !empty($_POST['hide_reviews']);

    // ── Babengas (facultatif) ────────────────────────────────────────────────
    // L'URL est normalisée sans barre oblique finale, comme attendu par le
    // service. Une clé laissée vide dans le formulaire conserve l'ancienne :
    // elle est affichée masquée, on ne veut pas l'effacer par mégarde.
    $options['babengas_url']     = rtrim(trim($_POST['babengas_url'] ?? ''), '/');
    $options['babengas_enabled'] = !empty($_POST['babengas_enabled']);

    $babengas_key_in = trim($_POST['babengas_key'] ?? '');
    if ($babengas_key_in !== '') {
        $options['babengas_key'] = $babengas_key_in;
    }

    // ── Thème du site (validé contre les fichiers _variables-*.css présents) ──
    $theme_key = strtolower(trim($_POST['theme'] ?? 'dark'));
    $options['theme'] = theme_exists($theme_key) ? $theme_key : 'dark';
    $options['admin_pseudo'] = trim($_POST['admin_pseudo'] ?? '');
    $options['custom_button_name'] = trim($_POST['custom_button_name'] ?? '');
    $options['custom_button_url'] = trim($_POST['custom_button_url'] ?? '');
    $options['custom_button_name2'] = trim($_POST['custom_button_name2'] ?? '');
    $options['custom_button_url2'] = trim($_POST['custom_button_url2'] ?? '');
    $options['custom_button_name3']   = trim($_POST['custom_button_name3'] ?? '');
    $options['custom_button_url3']    = trim($_POST['custom_button_url3'] ?? '');

    // ── Icônes des liens personnalisés (validées contre le jeu autorisé) ──
    require_once 'includes/custom_icons.php';
    $allowed_icon_keys = array_keys(custom_link_icons());
    foreach (['', '2', '3'] as $suffix) {
        $key = $_POST["custom_button_icon$suffix"] ?? 'link';
        $options["custom_button_icon$suffix"] = in_array($key, $allowed_icon_keys, true) ? $key : 'link';
    }

    // ── Section "Statistiques" : valeurs de repli globales + par catégorie ──
    $norm_num = function ($v) {
        $v = str_replace(',', '.', trim((string) $v));
        return ($v === '' || !is_numeric($v)) ? '' : (string) (float) $v;
    };

    $options['stats_default_minutes']         = $norm_num($_POST['stats_default_minutes']         ?? '');
    $options['stats_default_value']           = $norm_num($_POST['stats_default_value']           ?? '');
    $options['stats_default_value_collector'] = $norm_num($_POST['stats_default_value_collector'] ?? '');
    if ($options['stats_default_minutes'] === '')         $options['stats_default_minutes']         = '40';
    if ($options['stats_default_value'] === '')           $options['stats_default_value']           = '7';
    if ($options['stats_default_value_collector'] === '') $options['stats_default_value_collector'] = '15';

    $cat_settings = [];
    if (!empty($_POST['stats_cat']) && is_array($_POST['stats_cat'])) {
        foreach ($_POST['stats_cat'] as $cat_name => $fields) {
            $cat_name = trim((string) $cat_name);
            if ($cat_name === '') continue;
            $minutes = $norm_num($fields['minutes'] ?? '');
            $value   = $norm_num($fields['value']   ?? '');
            $valuec  = $norm_num($fields['value_collector'] ?? '');
            // N'enregistrer que si au moins un champ est renseigné
            if ($minutes === '' && $value === '' && $valuec === '') continue;
            $cat_settings[$cat_name] = [
                'minutes'         => $minutes,
                'value'           => $value,
                'value_collector' => $valuec,
            ];
        }
    }
    $options['stats_category_settings'] = json_encode($cat_settings, JSON_UNESCAPED_UNICODE);

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
            $logo_path = __DIR__ . '/assets/img/logo.png';

            // Supprimer l'ancien logo.png s'il existe
            if (file_exists($logo_path)) {
                if (!unlink($logo_path)) {
                    $_SESSION['error_message'] = "Impossible de supprimer l'ancien logo. Vérifiez les permissions.";
                    header("Location: " . $_SERVER['REQUEST_URI']);
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

    header("Location: " . $_SERVER['REQUEST_URI']);
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
            ? ' title="' . htmlspecialchars(implode("\n", $tooltip_lines), ENT_QUOTES) . '"'
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
            <button id="open-options-modal"></button>
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
                    <p>Statut de lecture de la série :</p>
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
                    <input type="text" name="babelio_url" placeholder="https://www.babelio.com/serie/nom-de-la-serie/12345 (facultatif)" autocomplete="off">
                    <p class="hint"><a tabindex="0" data-hint="L'URL Babelio permet de connaître le nombre de tomes réellement parus en France, via le service Babengas (onglet « Vérification Babelio » de la page Outils). Sur babelio.com, ouvrez la fiche SÉRIE (adresse en /serie/…) et non celle d'un tome, puis copiez l'URL complète.">À quoi ça sert ? Où la trouver ?</a></p>
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
                    <input type="text" name="edit_babelio_url" id="edit-series-babelio-url" placeholder="https://www.babelio.com/serie/nom-de-la-serie/12345" autocomplete="off">
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
                    <p>Statut de lecture de la série :</p>
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

                    <h3 class="options-section-title">Titres et descriptions</h3>

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

                    <label for="admin-pseudo">Pseudo de l'admin</label>
                    <input type="text" name="admin_pseudo" id="admin-pseudo" placeholder="Ex : Esenjin" value="<?= htmlspecialchars($options['admin_pseudo'] ?? '') ?>">
                    <p class="hint">Utilisé pour créditer les critiques auprès des visiteurs.</p>

                    <h3 class="options-section-title">Liens personnalisés</h3>
                    <p class="hint">Ces liens apparaissent dans le menu latéral des pages publiques (accueil et statistiques). Choisissez une icône pour chacun.</p>

                    <?php
                    $icon_labels = custom_link_icon_labels();
                    $icon_map    = custom_link_icons();
                    for ($__i = 1; $__i <= 3; $__i++):
                        $__s        = $__i === 1 ? '' : $__i;
                        $__name_val = htmlspecialchars($options["custom_button_name$__s"] ?? '');
                        $__url_val  = htmlspecialchars($options["custom_button_url$__s"]  ?? '');
                        $__icon_val = $options["custom_button_icon$__s"] ?? 'link';
                    ?>
                        <label for="custom-button-name<?= $__s ?>">Nom du bouton personnalisé (<?= $__i ?>)</label>
                        <input type="text" name="custom_button_name<?= $__s ?>" id="custom-button-name<?= $__s ?>" placeholder="Nom du bouton" value="<?= $__name_val ?>">

                        <label for="custom-button-url<?= $__s ?>">URL du bouton personnalisé (<?= $__i ?>)</label>
                        <input type="text" name="custom_button_url<?= $__s ?>" id="custom-button-url<?= $__s ?>" placeholder="URL du bouton" value="<?= $__url_val ?>">

                        <label for="custom-button-icon<?= $__s ?>">Icône du bouton (<?= $__i ?>)</label>
                        <div class="custom-icon-field">
                            <img class="custom-icon-preview" id="custom-icon-preview<?= $__s ?>"
                                 src="https://api.iconify.design/<?= str_replace(':', '/', custom_link_icon_name($__icon_val)) ?>.svg?color=%234ade80"
                                 width="22" height="22" alt="">
                            <select name="custom_button_icon<?= $__s ?>" id="custom-button-icon<?= $__s ?>"
                                    class="custom-icon-select"
                                    data-icon-map='<?= htmlspecialchars(json_encode($icon_map), ENT_QUOTES) ?>'
                                    data-preview="custom-icon-preview<?= $__s ?>">
                                <?php foreach ($icon_labels as $__key => $__lbl): ?>
                                    <option value="<?= $__key ?>" <?= $__icon_val === $__key ? 'selected' : '' ?>><?= htmlspecialchars($__lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="hint">Laisser le nom ou l'URL vide pour masquer le bouton.</p>
                    <?php endfor; ?>
                    <script>
                    (function() {
                        document.querySelectorAll('.custom-icon-select').forEach(function(sel) {
                            var map = {};
                            try { map = JSON.parse(sel.dataset.iconMap || '{}'); } catch (e) {}
                            var preview = document.getElementById(sel.dataset.preview);
                            sel.addEventListener('change', function() {
                                if (!preview) return;
                                var iconName = (map[sel.value] || 'mdi:link-variant').replace(':', '/');
                                preview.src = 'https://api.iconify.design/' + iconName + '.svg?color=%234ade80';
                            });
                        });
                    })();
                    </script>

                    <!-- ══ STATISTIQUES ══════════════════════════════════════ -->
                    <h3 class="options-section-title">Statistiques</h3>
                    <p class="hint">Réglez le temps de lecture moyen et la valeur moyenne d'un tome, par catégorie. Ces valeurs alimentent la page de statistiques (temps de lecture et valeur de la collection).</p>

                    <?php
                    // Réglages courants
                    $stats_cat_settings = [];
                    if (!empty($options['stats_category_settings'])) {
                        $decoded = json_decode($options['stats_category_settings'], true);
                        if (is_array($decoded)) $stats_cat_settings = $decoded;
                    }

                    // Liste des catégories présentes en collection
                    $all_categories = [];
                    foreach ($data as $___s) {
                        foreach (($___s['categories'] ?? []) as $___c) {
                            $___c = trim((string) $___c);
                            if ($___c !== '' && !in_array($___c, $all_categories, true)) {
                                $all_categories[] = $___c;
                            }
                        }
                    }
                    // Inclure aussi les catégories déjà réglées mais absentes de la collection
                    foreach (array_keys($stats_cat_settings) as $___c) {
                        if (!in_array($___c, $all_categories, true)) $all_categories[] = $___c;
                    }
                    sort($all_categories, SORT_NATURAL | SORT_FLAG_CASE);
                    ?>

                    <div class="stats-defaults">
                        <label>Valeurs par défaut (catégories non renseignées)</label>
                        <div class="stats-cat-row stats-cat-head">
                            <span class="stats-cat-name">Par défaut</span>
                            <input type="number" step="any" min="0" name="stats_default_minutes" placeholder="Min/tome" value="<?= htmlspecialchars($options['stats_default_minutes'] ?? '40') ?>">
                            <input type="number" step="any" min="0" name="stats_default_value" placeholder="€ normal" value="<?= htmlspecialchars($options['stats_default_value'] ?? '7') ?>">
                            <input type="number" step="any" min="0" name="stats_default_value_collector" placeholder="€ collector" value="<?= htmlspecialchars($options['stats_default_value_collector'] ?? '15') ?>">
                        </div>
                    </div>

                    <?php if (empty($all_categories)): ?>
                        <p class="hint">Aucune catégorie dans votre collection pour le moment.</p>
                    <?php else: ?>
                        <div class="stats-cat-row stats-cat-labels">
                            <span class="stats-cat-name">Catégorie</span>
                            <span>Min/tome</span>
                            <span>€ normal</span>
                            <span>€ collector</span>
                        </div>
                        <div class="stats-cat-list">
                            <?php foreach ($all_categories as $cat):
                                $cfg = $stats_cat_settings[$cat] ?? ['minutes' => '', 'value' => '', 'value_collector' => ''];
                                $cat_attr = htmlspecialchars($cat); ?>
                                <div class="stats-cat-row">
                                    <span class="stats-cat-name" title="<?= $cat_attr ?>"><?= $cat_attr ?></span>
                                    <input type="number" step="any" min="0" name="stats_cat[<?= $cat_attr ?>][minutes]"         placeholder="<?= htmlspecialchars($options['stats_default_minutes'] ?? '40') ?>"         value="<?= htmlspecialchars($cfg['minutes'] ?? '') ?>">
                                    <input type="number" step="any" min="0" name="stats_cat[<?= $cat_attr ?>][value]"           placeholder="<?= htmlspecialchars($options['stats_default_value'] ?? '7') ?>"           value="<?= htmlspecialchars($cfg['value'] ?? '') ?>">
                                    <input type="number" step="any" min="0" name="stats_cat[<?= $cat_attr ?>][value_collector]" placeholder="<?= htmlspecialchars($options['stats_default_value_collector'] ?? '15') ?>" value="<?= htmlspecialchars($cfg['value_collector'] ?? '') ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="hint">Laissez un champ vide pour utiliser la valeur par défaut. Les séries à plusieurs catégories utilisent la moyenne de leurs catégories.</p>
                    <?php endif; ?>

                    <!-- ══ VIGNETTE ══════════════════════════════════════════ -->
                    <h3 class="options-section-title">Vignette</h3>

                    <div class="form-group">
                        <label for="default_logo">Remplacer la vignette par défaut :</label>
                        <input type="file" id="default_logo" name="default_logo" accept="image/png">
                        <p class="hint">L'image téléversée remplacera le fichier logo.png actuel (PNG obligatoire).</p>
                        <p class="hint">Vignette par défaut actuelle :</p>
                        <?php if (file_exists('assets/img/logo.png')): ?>
                            <div>
                                <img src="assets/img/logo.png?v=<?= time() ?>" alt="Logo actuel" style="max-width: 100px; max-height: 100px;">
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ══ THÈMES ════════════════════════════════════════════ -->
                    <h3 class="options-section-title">Thèmes</h3>
                    <p class="hint">Choisissez l'apparence du site. Le thème « Sombre » est appliqué par défaut. Pour ajouter un thème personnalisé, déposez un fichier <code>assets/css/_variables-&lt;nom&gt;.css</code> : il apparaîtra automatiquement dans cette liste.</p>

                    <?php
                    $themes_list  = list_themes();
                    $current_theme = current_theme_key($options);
                    ?>
                    <label for="theme-select">Thème du site</label>
                    <select name="theme" id="theme-select" class="theme-select">
                        <?php foreach ($themes_list as $__t): ?>
                            <option value="<?= htmlspecialchars($__t['key']) ?>" <?= $current_theme === $__t['key'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($__t['label']) ?><?= $__t['custom'] ? ' — personnalisé' : ' — de base' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">
                        Les thèmes marqués « de base » sont fournis avec Lengas
                        (<code>_variables.css</code> et <code>_variables-light.css</code>) ;
                        ceux marqués « personnalisé » proviennent de vos propres fichiers.
                    </p>

                    <!-- ══ VISIBILITÉ ════════════════════════════════════════ -->
                    <h3 class="options-section-title">Visibilité</h3>

                    <label>
                        <input type="checkbox" name="private_mode" <?= $options['private_mode'] ? 'checked' : '' ?>> Mode privé
                    </label>
                    <p class="hint">Votre bibliothèque ne sera pas visible publiquement.</p>

                    <label>
                        <input type="checkbox" name="hide_mature" <?= $options['hide_mature'] ? 'checked' : '' ?>> Masquer les séries matures
                    </label>
                    <p class="hint">Vos séries matures ne seront pas visibles au public.</p>

                    <label>
                        <input type="checkbox" name="hide_reviews" <?= !empty($options['hide_reviews']) ? 'checked' : '' ?>> Cacher les critiques
                    </label>
                    <p class="hint">Vos critiques ne seront pas visibles au public.</p>

                    <!-- ══ BABENGAS ══════════════════════════════════════════ -->
                    <h3 class="options-section-title">Babengas (Babelio)</h3>
                    <p class="hint">
                        Babengas est un microservice à héberger chez vous (Docker, IP résidentielle)
                        qui interroge Babelio pour connaître le nombre de tomes <strong>réellement
                        parus en France</strong>. Il complète MangaUpdates, dont le décompte VF est
                        souvent absent. Laissez ces champs vides pour désactiver la fonctionnalité :
                        Lengas reste 100 % fonctionnel.
                    </p>

                    <label for="babengas-url">URL du service</label>
                    <input type="text" name="babengas_url" id="babengas-url"
                           placeholder="https://babengas.mondomaine.fr"
                           value="<?= htmlspecialchars($options['babengas_url'] ?? '') ?>"
                           autocomplete="off">
                    <p class="hint">Sans barre oblique finale. Le HTTPS n'est pas optionnel : la clé circule dans un en-tête à chaque appel.</p>

                    <label for="babengas-key">Clé partagée</label>
                    <input type="password" name="babengas_key" id="babengas-key"
                           placeholder="<?= !empty($options['babengas_key']) ? 'Clé enregistrée — laisser vide pour ne pas modifier' : 'Valeur de BABENGAS_KEY dans le fichier .env' ?>"
                           autocomplete="off">

                    <label>
                        <input type="checkbox" name="babengas_enabled" <?= !empty($options['babengas_enabled']) ? 'checked' : '' ?>> Activer la vérification via Babengas
                    </label>

                    <?php if (function_exists('babengas_enabled') && babengas_enabled()): ?>
                        <?php $bg_state = babengas_check_service(); ?>
                        <?php if ($bg_state['ok']): ?>
                            <p class="hint"><span class="ok">●</span> Service joignable<?= $bg_state['version'] !== '' ? ' — version ' . htmlspecialchars($bg_state['version']) : '' ?><?= $bg_state['actif'] ? '' : ' (traitement en pause côté Babengas)' ?>.</p>
                        <?php else: ?>
                            <p class="hint"><span class="warn">●</span> Service injoignable : <?= htmlspecialchars($bg_state['error']) ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="hint"><span class="warn">●</span> Vérification via Babengas : <strong>inactive</strong>.</p>
                    <?php endif; ?>

                    <!-- ══ MOT DE PASSE ══════════════════════════════════════ -->
                    <h3 class="options-section-title">Mot de passe</h3>

                    <label for="admin-password">Mot de passe admin</label>
                    <input type="password" name="admin_password" id="admin-password" placeholder="Mot de passe admin">
                    <p class="hint">Laisser vide pour ne pas modifier.</p>

                    <?php if (function_exists('vestikan_enabled') && vestikan_enabled()): ?>
                        <p class="hint"><span class="ok">●</span> Connexion via Vestikan : <strong>active</strong>.</p>
                    <?php else: ?>
                        <p class="hint"><span class="warn">●</span> Connexion via Vestikan : <strong>inactive</strong>. Déposez les fichiers Vestikan et <code>includes/vestikan-config.php</code> pour l'activer.</p>
                    <?php endif; ?>

                    <button type="submit" name="update_options" class="button button-opt">Mettre à jour</button>
                    <p style="visibility: hidden;">_</p>
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