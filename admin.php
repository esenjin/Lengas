<?php


require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require 'includes/mangaupdates.php';
require 'fonctions/series.php';
require 'fonctions/volumes.php';
require 'fonctions/wishlist.php';
require 'fonctions/loans.php';
require 'fonctions/options.php';
require 'fonctions/tools.php';
require 'fonctions/unread.php';

$data = load_data();
$options = load_options();

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page_admin = 9;
$offset = ($page - 1) * $per_page_admin;

// Récupérer les séries non lues
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_unread_series') {
    $unread_series = get_unread_series($data);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'unread_series' => $unread_series
    ]);
    exit;
}


// ── Endpoint SSE : analyse des séries incomplètes avec progression ────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'incomplete_series_stream') {
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
    while (ob_get_level()) ob_end_flush();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $sse = function(string $event, array $payload): void {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload) . "\n\n";
        flush();
    };

    $incomplete_series        = [];
    $series_with_more_volumes = [];
    $no_reference_series      = [];
    $failed_series            = [];

    $total          = count($data);
    $current        = 0;
    $force_uncached = isset($_GET['force_uncached']) && $_GET['force_uncached'] === '1';

    // Les volumes MangaUpdates sont récupérés à la volée dans la boucle ci-dessous.
    // Le cache SQLite (24h) évite de re-solliciter l'API à chaque analyse.
    // Avec force_uncached=1, seules les séries sans cache récent sont rechargées depuis l'API.

    foreach ($data as $series) {
        $current++;
        $sse('progress', [
            'current' => $current,
            'total'   => $total,
            'name'    => $series['name'],
        ]);

        $url = $series['mangaupdates_url'] ?? '';

        // Aucune référence disponible
        if ($url === '') {
            $no_reference_series[] = ['id' => $series['id'], 'name' => $series['name'], 'author' => $series['author'] ?? '', 'read_elsewhere' => !empty($series['read_elsewhere'])];
            continue;
        }

        // URL présente mais invalide
        $id = mangaupdates_get_id_from_url($url);
        if ($id === null) {
            $failed_series[] = [
                'id'             => $series['id'],
                'name'           => $series['name'],
                'author'         => $series['author'] ?? '',
                'ref'            => 'mangaupdates',
                'reason'         => 'URL MangaUpdates invalide',
                'has_mu_url'     => false, // URL présente mais invalide : on propose l'ajout
                'read_elsewhere' => !empty($series['read_elsewhere']),
            ];
            continue;
        }

        // Référence : MangaUpdates
        // force_uncached : on force le rechargement uniquement si la série n'a pas
        // de cache récent (< 24h), pour ne pas re-appeler les fiches déjà fraîches.
        $force_this = false;
        if ($force_uncached) {
            $cached_check = mangaupdates_get_cached_status($id, 86400);
            $force_this   = ($cached_check === null); // pas de cache valide → forcer
        }
        $info = mangaupdates_get_volumes($id, $force_this);
        if ($info === null) {
            // Échec de récupération : réseau ou service indisponible
            $failed_series[] = [
                'id'             => $series['id'],
                'name'           => $series['name'],
                'author'         => $series['author'] ?? '',
                'ref'            => 'mangaupdates',
                'reason'         => 'Erreur de récupération MangaUpdates (réseau ou service indisponible)',
                'has_mu_url'     => true, // URL valide : pas besoin du bouton Ajouter
                'read_elsewhere' => !empty($series['read_elsewhere']),
            ];
            continue;
        }

        $av = $info['volumes'];
        if ($av === null || (int)$av <= 0) {
            // Fiche trouvée mais sans nombre de tomes renseigné
            $failed_series[] = [
                'id'             => $series['id'],
                'name'           => $series['name'],
                'author'         => $series['author'] ?? '',
                'ref'            => 'mangaupdates',
                'reason'         => 'Nombre de tomes non renseigné sur MangaUpdates',
                'has_mu_url'     => true, // URL valide : pas besoin du bouton Ajouter
                'read_elsewhere' => !empty($series['read_elsewhere']),
            ];
            continue;
        }

        $ref_volumes = (int)$av;
        $owned_volumes               = count($series['volumes']);
        $series['ref_volumes_source'] = 'mangaupdates';
        $series['ref_volumes']        = $ref_volumes;
        $series['ref_status']         = $info['status']    ?? null;
        $series['ref_completed']      = $info['completed'] ?? false;
        $series['ref_country']        = $info['country']   ?? '';

        if ($owned_volumes < $ref_volumes) {
            $missing = [];
            for ($i = $owned_volumes + 1; $i <= $ref_volumes; $i++) $missing[] = $i;
            $series['missing_volumes'] = $missing;
            $incomplete_series[] = $series;
        } elseif ($owned_volumes > $ref_volumes) {
            $series['has_more_volumes'] = true;
            $series['missing_volumes']  = [];
            $series_with_more_volumes[] = $series;
        }
    }

    $incomplete = array_merge($incomplete_series, $series_with_more_volumes);
    foreach ($incomplete as &$s) {
        if (!isset($s['missing_volumes'])) $s['missing_volumes'] = [];
    }

    $sse('done', [
        'success'             => true,
        'incomplete_series'   => $incomplete,
        'no_reference_series' => $no_reference_series,
        'failed_series'       => $failed_series,
    ]);
    exit;
}

// ── Endpoint SSE : association MangaUpdates avec progression ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'mu_associate_stream') {
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
    @set_time_limit(0);
    while (ob_get_level()) ob_end_flush();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $sse = function(string $event, array $payload): void {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload) . "\n\n";
        flush();
    };

    // Séries sans URL MangaUpdates
    $targets = array_values(array_filter($data, function ($s) {
        return empty($s['mangaupdates_url']);
    }));
    $total        = count($targets);
    $current      = 0;
    $with_results = 0;
    $no_results   = [];

    foreach ($targets as $series) {
        $current++;
        $sse('progress', [
            'current' => $current,
            'total'   => $total,
            'name'    => $series['name'],
        ]);

        $candidates = mangaupdates_associate_candidates($series['name'], $series['author'] ?? '', 5);

        if (!empty($candidates)) {
            $with_results++;
            $sse('match', [
                'series' => [
                    'id'      => $series['id'],
                    'name'    => $series['name'],
                    'author'  => $series['author'] ?? '',
                    'results' => $candidates,
                ],
            ]);
        } else {
            $no_results[] = $series['name'];
        }

        usleep(120000); // ~120 ms entre séries
    }

    $sse('done', [
        'success'      => true,
        'total'        => $total,
        'with_results' => $with_results,
        'no_results'   => $no_results,
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
                $result = get_incomplete_series($data);
                $response['success']             = true;
                $response['incomplete_series']   = $result['incomplete'];
                $response['no_reference_series'] = $result['no_reference'];
                $response['failed_series']       = $result['failed'];
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

        case 'mark_first_unread_as_read':
            $series_id = $_POST['series_id'] ?? '';
            if ($series_id) {
                $result = mark_first_unread_volume_as_read($data, $series_id);
                if ($result['success']) {
                    save_data($result['data']);
                    $response['success'] = true;
                } else {
                    $response['message'] = $result['message'];
                }
            } else {
                $response['message'] = 'ID de série invalide.';
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
    $mangaupdates_url = trim($_POST['mangaupdates_url'] ?? '');
    $mature = !empty($_POST['mature']);
    $favorite = !empty($_POST['favorite']);
    $volumes_count = (int)($_POST['volumes_count'] ?? 1);
    $volumes_status = $_POST['volumes_status'] ?? 'à lire';
    $all_collector = !empty($_POST['all_collector']);
    $last_volume = !empty($_POST['last_volume']);
    $status        = $_POST['series_status'] ?? 'en cours';
    $read_elsewhere = !empty($_POST['read_elsewhere']);

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
    $result = add_series($data, $name, $author, $publisher, $other_contributors, $categories, $genres, $mangaupdates_url, $mature, $favorite, $volumes_count, $volumes_status, $all_collector, $last_volume, $image, $status, $read_elsewhere);

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

    $result = update_volume($data, $series_id, $volume_index, $status, $is_collector, $is_last);
    if ($result['success']) {
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
    $mature = !empty($_POST['edit_mature']);
    $favorite = !empty($_POST['edit_favorite']);
    $remove_image = !empty($_POST['remove_image']);
    $new_volumes_count = (int)($_POST['new_volumes_count'] ?? 0);
    $new_volumes_status = $_POST['new_volumes_status'] ?? 'à lire';
    $new_volumes_collector = !empty($_POST['new_volumes_collector']);
    $new_volumes_last = !empty($_POST['new_volumes_last']);
    $new_status         = $_POST['series_status'] ?? null;
    $edit_read_elsewhere = !empty($_POST['edit_read_elsewhere']);

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

    $result = update_series($data, $series_id, $name, $author, $other_contributors, $publisher, $categories, $genres, $mangaupdates_url, $mature, $favorite, $remove_image, $new_volumes_count, $new_volumes_status, $new_volumes_collector, $new_volumes_last, $new_image, $new_status, $edit_read_elsewhere);
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
    $options['custom_button_name'] = trim($_POST['custom_button_name'] ?? '');
    $options['custom_button_url'] = trim($_POST['custom_button_url'] ?? '');
    $options['custom_button_name2'] = trim($_POST['custom_button_name2'] ?? '');
    $options['custom_button_url2'] = trim($_POST['custom_button_url2'] ?? '');
    $options['custom_button_name3']   = trim($_POST['custom_button_name3'] ?? '');
    $options['custom_button_url3']    = trim($_POST['custom_button_url3'] ?? '');

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

        case 'mu_associate_save':
            // Enregistre les URL validées. Format attendu : associations[series_id] = url
            $assoc = $_POST['associations'] ?? [];
            if (!is_array($assoc)) $assoc = [];
            $saved = 0;
            $warm_ids = [];
            foreach ($data as &$series) {
                if (!isset($assoc[$series['id']])) continue;
                $url   = trim((string)$assoc[$series['id']]);
                $mu_id = $url !== '' ? mangaupdates_get_id_from_url($url) : null;
                if ($mu_id !== null) {
                    $series['mangaupdates_url'] = $url;
                    $warm_ids[] = $mu_id;
                    $saved++;
                }
            }
            unset($series);
            if ($saved > 0) {
                save_data($data);
                // Réchauffer le cache des séries nouvellement associées
                foreach ($warm_ids as $wid) { @mangaupdates_get_volumes($wid); }
            }
            $response = ['success' => true, 'saved' => $saved];
            break;

        case 'check_coherence':
            $issues = check_collection_coherence($data);
            $response = ['success' => true, 'issues' => $issues];
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
    if ($status_filter !== '') {
        $filtered_data = array_filter($filtered_data, function($series) use ($status_filter) {
            if ($status_filter === 'mature') {
                return !empty($series['mature']);
            }
            if ($status_filter === 'non_mature') {
                return empty($series['mature']);
            }
            if ($status_filter === 'favorite') {
                return !empty($series['favorite']);
            }
            if ($status_filter === 'read_elsewhere') {
                return !empty($series['read_elsewhere']);
            }
            if ($status_filter === 'reading_in_progress') {
                // Au moins 1 tome "à lire" ou "en cours"
                foreach ($series['volumes'] ?? [] as $volume) {
                    if ($volume['status'] === 'à lire' || $volume['status'] === 'en cours') {
                        return true;
                    }
                }
                return false;
            }
            if ($status_filter === 'reading_completed') {
                // Tous les tomes sont "terminé" (et il y en a au moins 1)
                $volumes = $series['volumes'] ?? [];
                if (empty($volumes)) return false;
                foreach ($volumes as $volume) {
                    if ($volume['status'] !== 'terminé') {
                        return false;
                    }
                }
                return true;
            }
            // Même logique que le JS
            $status = 'en cours';
            if (!empty($series['volumes'])) {
                foreach ($series['volumes'] as $volume) {
                    if (!empty($volume['last'])) {
                        $status = 'terminée';
                        break;
                    }
                }
            }
            if ($status === 'en cours' && !empty($series['status'])) {
                $status = $series['status'];
            }
            return $status === $status_filter;
        });
    }
    sort_series($filtered_data, $sort_by, $sort_order);

    $offset = ($page - 1) * $per_page;
    $paginated_data = array_slice($filtered_data, $offset, $per_page);

    // En mode "light", on ne renvoie que les métadonnées
    if ($light_mode) {
        $light_series = array_map(function($series) {
            // Détermine le statut de la série
            $status = 'en cours';
            if (isset($series['volumes']) && is_array($series['volumes'])) {
                foreach ($series['volumes'] as $volume) {
                    if (!empty($volume['last'])) {
                        $status = 'terminée';
                        break;
                    }
                }
            }
            if (isset($series['status'])) {
                $status = $series['status'];
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
                'mangaupdates_url'           => $series['mangaupdates_url'] ?? '',
                'read_elsewhere'             => (bool)($series['read_elsewhere'] ?? false),
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
            <a href="admin.php" class="logout-button" title="Recharger la page">
                <img src="https://api.iconify.design/mdi/refresh.svg?color=white" alt="Recharger la page" width="24" height="24">
            </a>
            <a href="logout.php" class="logout-button" title="Déconnexion">
                <img src="https://api.iconify.design/mdi/logout.svg?color=white" alt="Déconnexion" width="24" height="24">
            </a>
        </div>
        <h1><?= htmlspecialchars($options['admin_page_title']) ?></h1>

        <!-- Barre de filtres et recherche -->
        <div class="filters">
            <form method="get">
                <input type="text" name="search" autocomplete="off" id="search-all" placeholder="Rechercher une série, un auteur, un éditeur, etc.."
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
                    <select name="status_filter" id="status-filter">
                        <option value="">Tous les statuts</option>
                        <option value="en cours">Publication en cours ▶️</option>
                        <option value="terminée">Publication terminée ✅</option>
                        <option value="en pause">Publication en pause ⏳</option>
                        <option value="abandonnée">Publication abandonnée ⛔</option>
                        <option value="mature">Contenu mature 🔞</option>
                        <option value="non_mature">Contenu non mature 👐</option>
                        <option value="favorite">Mes favoris ❤️</option>
                        <option value="reading_in_progress">Lecture en cours 📖</option>
                        <option value="reading_completed">Lecture terminée ✔️</option>
                        <option value="read_elsewhere">Lues ailleurs 📚</option>
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
            <button id="open-coherences-modal" class="button button-aos">Incohérences</button>
            <button id="open-unread-modal" class="button button-aos">Séries à lire</button>
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
                    <p>Statut de la série :</p>
                    <select name="series_status" id="add-series-status" required>
                        <option value="en cours">En cours ▶️</option>
                        <option value="terminée">Terminée ✅</option>
                        <option value="en pause">En pause ⏳</option>
                        <option value="abandonnée">Abandonnée ⛔</option>
                    </select>
                    <p>URL MangaUpdates :</p>
                    <input type="text" name="mangaupdates_url" placeholder="https://www.mangaupdates.com/series/xxxxxxx/nom-de-la-serie (facultatif)" autocomplete="off">
                    <p class="hint"><a tabindex="0" data-hint="L'URL MangaUpdates sert à détecter les tomes manquants des séries terminées (outil « Séries incomplètes »). Sur mangaupdates.com, ouvrez la fiche de votre série puis copiez l'URL complète. L'outil « Associer MangaUpdates » (modale Outils) peut aussi remplir ce champ automatiquement.">À quoi ça sert ? Où la trouver ?</a></p>
                    <label>
                        <input type="checkbox" name="mature"> Contenu mature 🔞
                    </label>
                    <label>
                        <input type="checkbox" name="favorite"> Série favorite ❤️
                    </label>
                    <label>
                        <input type="checkbox" name="read_elsewhere" id="add-series-read-elsewhere"> Lue ailleurs 📚
                    </label>
                    <p class="hint">Cochez si vous avez lu cette série sans la posséder (chez un ami, en bibliothèque, revendue, etc.).</p>
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
                <p>Cet outil vous permet de trouver les séries pour lesquelles il vous manque des tomes, en comparant votre collection aux données de l'API MangaUpdates.</p>
                <br>
                <p class="hint">⚠️ Limitations : MangaUpdates fournit le nombre de tomes aussi bien pour les séries <strong>terminées</strong> que pour celles <strong>en cours de publication</strong>. En revanche, le décompte se base principalement sur l'édition d'origine (VO) et non sur l'édition française (VF) : un écart est donc possible. Lengas privilégie automatiquement le décompte français lorsque MangaUpdates l'indique (ex. « 8 Volumes (Complete, France) »). Renseignez l'URL MangaUpdates de chaque série — via le champ dédié (ajout / modification) ou l'outil « Associer MangaUpdates » de la modale Outils.</p>
                <button id="search-incomplete-series" class="button">Rechercher les séries incomplètes</button>
                <button id="force-incomplete-search" class="button button-opt" title="Interroge MangaUpdates pour les séries sans cache récent (ignore les résultats mis en cache il y a moins de 24 h)">Forcer la recherche (non analysées)</button>
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
                    <p>URL MangaUpdates (facultatif) :</p>
                    <input type="text" name="edit_mangaupdates_url" id="edit-series-mangaupdates-url" placeholder="https://www.mangaupdates.com/series/xxxxxxx/nom-de-la-serie" autocomplete="off">
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
                    <p>Statut de la série :</p>
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
                        <input type="checkbox" name="edit_read_elsewhere" id="edit-series-read-elsewhere"> Lue ailleurs 📚
                    </label>
                    <p class="hint">Cochez si vous avez lu cette série sans la posséder (chez un ami, en bibliothèque, revendue, etc.).</p>
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

        <!-- Modale "À lire" -->
        <div id="unread-modal" class="modal">
            <div class="modal-content">
                <span class="close-modal" id="close-unread-modal">&times;</span>
                <h2>Séries à lire</h2>
                <p>Cette section vous permet de garder une trace des séries que vous possédez mais que vous n'avez pas encore terminé de lire.</p>
                <br>
                <div id="unread-list" class="unread-list"></div>
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
                    </div>
                    <div class="wishlist-list" id="wishlist-list">
                        <?php foreach (load_wishlist() as $index => $item): ?>
                            <div class="wishlist-item" data-index="<?= $index ?>">
                                <div class="wishlist-item-info">
                                    <span class="wishlist-series-name"><?= $item['name'] ?></span>
                                    <span class="wishlist-series-author"><?= $item['author'] ?></span>
                                    <span class="wishlist-series-publisher"><?= $item['publisher'] ?></span>
                                </div>    
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

                    <h3 class="options-section-title">Liens personnalisés</h3>

                    <label for="custom-button-name">Nom du bouton personnalisé (1)</label>
                    <input type="text" name="custom_button_name" id="custom-button-name" placeholder="Nom du bouton" value="<?= htmlspecialchars($options['custom_button_name'] ?? '') ?>">

                    <label for="custom-button-url">URL du bouton personnalisé (1)</label>
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

                    <h3 class="options-section-title">Autres options</h3>

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
                    <p class="hint">Vos séries matures ne seront pas visibles au public.</p>

                    <div class="form-group">
                        <br>
                        <label for="default_logo">Remplacer la vignette par défaut :</label>
                        <input type="file" id="default_logo" name="default_logo" accept="image/png">
                        <p class="hint">L'image téléversée remplacera le fichier logo.png actuel (PNG obligatoire).</p>
                        <p class="hint">Vignette par défaut actuelle :</p>
                        <?php if (file_exists('assets/img/logo.png')): ?>
                            <div>
                                <img src="assets/img/logo.png?v=<?= time() ?>" alt="Logo actuel" style="max-width: 100px; max-height: 100px;">
                            </div>
                        <?php endif; ?>
                        <br>
                    </div>

                    <button type="submit" name="update_options" class="button button-opt">Mettre à jour</button>
                    <p style="visibility: hidden;">_</p>
                </form>
            </div>
        </div>

        <!-- Modale pour les incohérences -->
        <div class="modal" id="coherences-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-coherences-modal">&times;</span>
                <h2>Incohérences de la collection</h2>
                <p>Vérification des incohérences internes de vos séries. Cet outil exploite aussi le statut de publication MangaUpdates mis en cache — lancez d'abord l'outil « Séries incomplètes » pour le remplir.</p>
                <div id="coherences-results">
                    <!-- Résultats chargés dynamiquement -->
                </div>
            </div>
        </div>

        <!-- Modale pour les outils -->
        <!-- Outils -->
        <div class="modal" id="tools-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-tools-modal">&times;</span>
                <h2>Outils</h2>

                <div class="tools-tabs" role="tablist">
                    <button type="button" class="tools-tab tools-tab--active" data-tab="backups">Sauvegardes</button>
                    <button type="button" class="tools-tab" data-tab="associate">Association MangaUpdates</button>
                    <button type="button" class="tools-tab" data-tab="integrity">Vérification d'intégrité</button>
                </div>

                <!-- Onglet : Sauvegardes -->
                <div class="tools-tab-panel tools-tab-panel--active" data-tab-panel="backups">
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

                <!-- Onglet : Association MangaUpdates -->
                <div class="tools-tab-panel" data-tab-panel="associate">
                    <div class="tools-section">
                        <h3>Associer MangaUpdates</h3>
                        <p>Recherche automatiquement une fiche MangaUpdates pour chaque série sans URL renseignée (titre + auteur), puis vous laisse valider la bonne correspondance avant l'enregistrement. Selon le nombre de séries, l'opération peut prendre quelques minutes.</p>
                        <button id="mu-associate-btn" class="button button-opt">
                            <span id="mu-associate-text">Rechercher les correspondances</span>
                            <span id="mu-associate-spinner" class="spinner" style="display: none;"></span>
                        </button>
                        <div id="mu-associate-progress"></div>
                        <div id="mu-associate-results"></div>
                    </div>
                </div>

                <!-- Onglet : Vérification d'intégrité -->
                <div class="tools-tab-panel" data-tab-panel="integrity">
                    <div class="tools-section">
                        <h3>Vérification d'intégrité</h3>
                        <p>Vérifie l'intégrité de votre site et de vos données (fichiers, permissions, structure de la base, API MangaUpdates…).</p>
                        <button id="check-integrity-btn" class="button button-oas">
                            <span id="check-integrity-text">Vérifier l'intégrité</span>
                            <span id="check-integrity-spinner" class="spinner" style="display: none;"></span>
                        </button>
                        <div id="integrity-results-container"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modale : ajouter une URL MangaUpdates (depuis l'outil des tomes manquants) -->
        <div class="modal" id="add-mu-url-modal">
            <div class="modal-content modal-content--narrow">
                <span class="close-modal" id="close-add-mu-url-modal">&times;</span>
                <h2>Ajouter une URL MangaUpdates</h2>
                <p id="add-mu-url-series-name" class="add-mu-url-series-name"></p>
                <input type="hidden" id="add-mu-url-series-id">
                <input type="text" id="add-mu-url-input" placeholder="https://www.mangaupdates.com/series/xxxxxxx/nom-de-la-serie" autocomplete="off">
                <p class="hint">Collez l'URL de la fiche MangaUpdates de cette série.</p>
                <div class="modal-actions">
                    <button id="save-add-mu-url-btn" class="button button-ats">Enregistrer</button>
                </div>
                <p id="add-mu-url-feedback" class="add-mu-url-feedback"></p>
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
        $series_with_status = array_map(function($series) {
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
    <script src="assets/js/admin/wishlist.js"></script>
    <script src="assets/js/admin/loans.js"></script>
    <script src="assets/js/admin/tools.js"></script>
    <script src="assets/js/admin/pagination.js"></script>
    <script src="assets/js/admin/main.js"></script>
    <script src="assets/js/admin/unread.js"></script>

</body>
</html>