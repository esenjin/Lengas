<?php
require 'config.php';
require 'anilist.php';
session_start();

if (!($_SESSION['logged_in'] ?? false)) {
    header('Location: login.php');
    exit;
}

$data = load_data();
$options = load_options();

// Gérer les actions pour les séries incomplètes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $response = ['success' => false];

    switch ($action) {
        case 'get_incomplete_series':
            $incomplete_series = get_incomplete_series($data);
            $response['success'] = true;
            $response['incomplete_series'] = $incomplete_series;
            break;

        case 'add_missing_volume':
            $series_id = $_POST['series_id'] ?? '';
            $volume_number = (int)($_POST['volume_number'] ?? 0);

            if ($series_id && $volume_number > 0) {
                $success = add_volume_to_series($data, $series_id, $volume_number);
                if ($success) {
                    save_data($data);
                    $response['success'] = true;
                }
            }
            break;

        case 'add_all_missing_volumes':
            $series_id = $_POST['series_id'] ?? '';
            $missing_volumes = isset($_POST['missing_volumes']) ? explode(',', $_POST['missing_volumes']) : [];
            $missing_volumes = array_map('intval', $missing_volumes);

            if ($series_id && !empty($missing_volumes)) {
                $success = add_all_missing_volumes_to_series($data, $series_id, $missing_volumes);
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

// Charger la liste d'envies depuis le fichier JSON
function load_wishlist() {
    if (file_exists('list.json')) {
        $wishlist = json_decode(file_get_contents('list.json'), true);
        return $wishlist ?: [];
    }
    return [];
}

// Sauvegarder la liste d'envies dans le fichier JSON
function save_wishlist($wishlist) {
    file_put_contents('list.json', json_encode($wishlist, JSON_PRETTY_PRINT));
}

// Charger la liste d'envies
$wishlist = load_wishlist();

// Ajouter une série à la liste d'envies
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_wishlist'])) {
    $name = trim($_POST['wishlist_name'] ?? '');
    $author = trim($_POST['wishlist_author'] ?? '');
    $publisher = trim($_POST['wishlist_publisher'] ?? '');

    // Vérifier si la série est déjà présente dans la liste d'envies
    $series_exists = false;
    foreach ($wishlist as $item) {
        if (strcasecmp($item['name'], $name) === 0) {
            $series_exists = true;
            break;
        }
    }

    if (!$series_exists && $name && $author && $publisher) {
        $wishlist[] = [
            'name' => $name,
            'author' => $author,
            'publisher' => $publisher
        ];
        save_wishlist($wishlist);
        echo json_encode(['success' => true, 'wishlist' => $wishlist]);
    } else {
        echo json_encode(['success' => false, 'message' => 'La série est déjà présente dans la liste d\'envies.']);
    }
    exit;
}

// Supprimer une série de la liste d'envies
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_from_wishlist'])) {
    $index = $_POST['index'] ?? 0;
    if (isset($wishlist[$index])) {
        array_splice($wishlist, $index, 1);
        save_wishlist($wishlist);
        echo json_encode(['success' => true, 'wishlist' => $wishlist]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Index invalide.']);
    }
    exit;
}

// Ajouter une série à la collection principale depuis la liste d'envies
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_from_wishlist'])) {
    $index = $_POST['index'] ?? 0;
    if (isset($wishlist[$index])) {
        $series = $wishlist[$index];
        $name = $series['name'];
        $author = $series['author'];
        $publisher = $series['publisher'];

        // Vérifier si une série avec le même nom existe déjà dans la collection principale
        $series_exists = false;
        foreach ($data as $existing_series) {
            if (strcasecmp($existing_series['name'], $name) === 0) {
                $series_exists = true;
                break;
            }
        }

        if (!$series_exists) {
            // Ajouter la série à la collection principale
            $data[] = [
                'id' => generate_uuid(),
                'name' => $name,
                'author' => $author,
                'publisher' => $publisher,
                'categories' => [''], // Catégorie par défaut, à modifier par l'utilisateur
                'image' => '', // Image par défaut, à modifier par l'utilisateur
                'volumes' => [
                    [
                        'number' => 1,
                        'status' => 'à lire',
                        'collector' => false,
                        'last' => false
                    ]
                ]
            ];
            save_data($data);

            // Supprimer la série de la liste d'envies
            array_splice($wishlist, $index, 1);
            save_wishlist($wishlist);
            echo json_encode(['success' => true, 'wishlist' => $wishlist]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Une série avec ce nom existe déjà dans votre collection.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Index invalide.']);
    }
    exit;
}

// Générer un UUID unique
function generate_uuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Ajouter une série
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_series'])) {
    $name = trim($_POST['name'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $publisher = trim($_POST['publisher'] ?? '');
    $categories = trim($_POST['categories'] ?? '');
    $genres = trim($_POST['genres'] ?? '');
    $image = upload_image($_FILES['image'] ?? []);
    $anilist_id = trim($_POST['anilist_id'] ?? '');
    $mature = !empty($_POST['mature']);

    // Vérifier si une série avec le même nom existe déjà
    $series_exists = false;
    foreach ($data as $series) {
        if (strcasecmp($series['name'], $name) === 0) {
            $series_exists = true;
            break;
        }
    }

    if ($series_exists) {
        $_SESSION['error_message'] = "Une série avec ce nom existe déjà.";
    } elseif ($image && $name && $author && $publisher && $categories) {
        $data[] = [
            'id' => generate_uuid(),
            'name' => $name,
            'author' => $author,
            'publisher' => $publisher,
            'categories' => explode(',', $categories),
            'genres' => explode(',', $genres),
            'image' => $image,
            'anilist_id' => $anilist_id,
            'mature' => $mature,
            'volumes' => [
                [
                    'number' => 1,
                    'status' => 'à lire',
                    'collector' => false,
                    'last' => false
                ]
            ]
        ];
        save_data($data);
        $_SESSION['success_message'] = "Série ajoutée avec succès";
    } else {
        $_SESSION['error_message'] = "Veuillez remplir tous les champs correctement.";
    }

    header("Location: admin.php");
    exit;
}

// Trouver une série par son ID
function find_series_by_id($data, $series_id) {
    foreach ($data as $index => $series) {
        if (isset($series['id']) && $series['id'] === $series_id) {
            return ['index' => $index, 'series' => $series];
        }
    }
    return null;
}

// Ajouter un ou plusieurs tomes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_volume']) || isset($_POST['add_multiple_volumes']))) {
    $series_id = $_POST['series_id'] ?? '';
    $status = $_POST['status'] ?? 'à lire';
    $is_collector = !empty($_POST['is_collector']);
    $is_last = !empty($_POST['is_last']);

    $series = find_series_by_id($data, $series_id);
    if ($series) {
        $series_index = $series['index'];

        if (isset($_POST['add_volume'])) {
            $volume_number = (int)($_POST['volume_number'] ?? 0);
            if ($volume_number > 0) {
                $volume_exists = false;
                foreach ($data[$series_index]['volumes'] as $volume) {
                    if ((int)$volume['number'] === $volume_number) {
                        $volume_exists = true;
                        break;
                    }
                }

                if (!$volume_exists) {
                    $data[$series_index]['volumes'][] = [
                        'number' => $volume_number,
                        'status' => $status,
                        'collector' => $is_collector,
                        'last' => $is_last
                    ];
                } else {
                    // Ajouter un message d'erreur pour indiquer que le tome existe déjà
                    $_SESSION['error_message'] = "Le tome $volume_number existe déjà.";
                }
            }
        } elseif (isset($_POST['add_multiple_volumes'])) {
            $start = (int)($_POST['start_volume'] ?? 0);
            $end = (int)($_POST['end_volume'] ?? 0);
            if ($start > 0 && $end >= $start) {
                $existing_volumes = [];
                for ($i = $start; $i <= $end; $i++) {
                    $volume_exists = false;
                    foreach ($data[$series_index]['volumes'] as $volume) {
                        if ((int)$volume['number'] === $i) {
                            $volume_exists = true;
                            break;
                        }
                    }

                    if (!$volume_exists) {
                        $data[$series_index]['volumes'][] = [
                            'number' => $i,
                            'status' => $status,
                            'collector' => $is_collector,
                            'last' => ($i == $end) ? $is_last : false
                        ];
                    } else {
                        $existing_volumes[] = $i;
                    }
                }
                if (!empty($existing_volumes)) {
                    $_SESSION['error_message'] = "Les tomes " . implode(', ', $existing_volumes) . " existent déjà.";
                }
            }
        }
        save_data($data);
        header("Location: admin.php");
        exit;
    }
}

// Mettre à jour un tome
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_volume'])) {
    $series_id = $_POST['series_id'] ?? '';
    $volume_index = (int)($_POST['volume_index'] ?? 0);
    $status = $_POST['status'] ?? 'à lire';
    $is_collector = !empty($_POST['is_collector']);
    $is_last = !empty($_POST['is_last']);

    $series = find_series_by_id($data, $series_id);
    if ($series && isset($data[$series['index']]['volumes'][$volume_index])) {
        $data[$series['index']]['volumes'][$volume_index] = [
            'number' => $data[$series['index']]['volumes'][$volume_index]['number'],
            'status' => $status,
            'collector' => $is_collector,
            'last' => $is_last
        ];
        save_data($data);
        header("Location: admin.php");
        exit;
    }
}

// Supprimer un tome
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_volume'])) {
    $series_id = $_POST['series_id'] ?? '';
    $volume_index = (int)($_POST['volume_index'] ?? 0);

    $series = find_series_by_id($data, $series_id);
    if ($series && isset($data[$series['index']]['volumes'][$volume_index])) {
        array_splice($data[$series['index']]['volumes'], $volume_index, 1);
        save_data($data);
        $_SESSION['success_message'] = "Tome supprimé avec succès";
    } else {
        $_SESSION['error_message'] = "Série ou volume introuvable";
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
    $categories = trim($_POST['edit_categories'] ?? '');
    $genres = trim($_POST['edit_genres'] ?? '');
    $anilist_id = trim($_POST['edit_anilist_id'] ?? '');
    $mature = !empty($_POST['edit_mature']);
    $remove_image = !empty($_POST['remove_image']);

    $series = find_series_by_id($data, $series_id);
    if ($series && $name && $author && $publisher && $categories) {
        $series_index = $series['index'];

        $data[$series_index]['name'] = $name;
        $data[$series_index]['author'] = $author;
        $data[$series_index]['publisher'] = $publisher;
        $data[$series_index]['categories'] = explode(',', $categories);
        $data[$series_index]['genres'] = explode(',', $genres);
        $data[$series_index]['anilist_id'] = $anilist_id;
        $data[$series_index]['mature'] = $mature;

        if ($remove_image) {
            if (file_exists($data[$series_index]['image'])) {
                unlink($data[$series_index]['image']);
            }
            $data[$series_index]['image'] = '';
        }

        if (!empty($_FILES['edit_image']['name'])) {
            if (!empty($data[$series_index]['image']) && file_exists($data[$series_index]['image'])) {
                unlink($data[$series_index]['image']);
            }
            $new_image = upload_image($_FILES['edit_image']);
            if ($new_image) {
                $data[$series_index]['image'] = $new_image;
            }
        }

        save_data($data);
        header("Location: admin.php");
        exit;
    }
}

// Supprimer une série
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_series'])) {
    $series_id = $_POST['series_id'] ?? '';

    $series = find_series_by_id($data, $series_id);
    if ($series) {
        $series_index = $series['index'];
        $image_path = $data[$series_index]['image'];
        if (file_exists($image_path)) {
            unlink($image_path);
        }

        array_splice($data, $series_index, 1);
        save_data($data);
        echo "OK";
        exit;
    }
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

    $admin_password = trim($_POST['admin_password'] ?? '');

    if ($options['site_name'] && $options['site_description'] && $options['index_page_title'] && $options['admin_page_title'] && $options['stats_page_title']) {
        // Mettre à jour le mot de passe uniquement s'il n'est pas vide
        if (!empty($admin_password)) {
            // Limiter les caractères autorisés pour le mot de passe
            if (!preg_match('/^[a-zA-Z0-9_!@#$%^&*()\-+=\[\]{};:\'"\\|,.<>\/?]+$/', $admin_password)) {
                $_SESSION['error_message'] = "Le mot de passe contient des caractères non autorisés.";
                header("Location: admin.php");
                exit;
            }

            // Mettre à jour le mot de passe dans config.php
            $config_content = file_get_contents('config.php');
            $admin_password = addslashes($admin_password);
            $config_content = preg_replace("/define\('ADMIN_PASSWORD', '.*?'\)/", "define('ADMIN_PASSWORD', '$admin_password')", $config_content);
            file_put_contents('config.php', $config_content);
        }

        save_options($options);
        $_SESSION['success_message'] = "Options mises à jour avec succès";
    } else {
        $_SESSION['error_message'] = "Veuillez remplir tous les champs obligatoires.";
    }

    header("Location: admin.php");
    exit;
}

// Gestion du tri, filtre et recherche
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'asc';
$search_term = $_GET['search'] ?? '';

function sort_series(&$data, $sort_by, $sort_order) {
    usort($data, function($a, $b) use ($sort_by, $sort_order) {
        if ($sort_by === 'volumes') {
            return $sort_order === 'asc'
                ? count($a['volumes']) - count($b['volumes'])
                : count($b['volumes']) - count($a['volumes']);
        } elseif ($sort_by === 'categories') {
            $a_categories = implode(', ', $a['categories'] ?? []);
            $b_categories = implode(', ', $b['categories'] ?? []);
            return $sort_order === 'asc'
                ? strcasecmp($a_categories, $b_categories)
                : strcasecmp($b_categories, $a_categories);
        } else {
            return $sort_order === 'asc'
                ? strcasecmp($a[$sort_by], $b[$sort_by])
                : strcasecmp($b[$sort_by], $a[$sort_by]);
        }
    });
}

// Fonction pour trier les tomes par numéro
function sort_volumes(&$volumes) {
    usort($volumes, function($a, $b) {
        return $a['number'] - $b['number'];
    });
}

// Fonction pour générer les notifications
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
        if (!empty($last_volumes)) {
            $notifications[] = "Attention, cette série est marquée comme terminée mais il semble y avoir plus de tomes sur Anilist.";
        }
    }

    // Vérifier si le nombre de tomes est égal à Anilist mais que le dernier tome n'est pas tagué comme tel
    if ($anilist_volumes !== null && $max == $anilist_volumes && empty($last_volumes)) {
        $notifications[] = "Attention, cette série semble complète mais le dernier tome n'est pas tagué comme tel.";
    }

    return $notifications;
}

sort_series($data, $sort_by, $sort_order);

if ($search_term) {
    $data = array_filter($data, function($series) use ($search_term) {
        return stripos($series['name'], $search_term) !== false ||
               stripos($series['author'], $search_term) !== false ||
               stripos($series['publisher'], $search_term) !== false ||
               (isset($series['categories']) && stripos(implode(', ', $series['categories']), $search_term) !== false) ||
               (isset($series['genres']) && stripos(implode(', ', $series['genres']), $search_term) !== false);
    });
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
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div id="error-message" class="error-message">
            <?= $_SESSION['error_message'] ?>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div id="success-message" class="success-message">
            <?= $_SESSION['success_message'] ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="container">
        <h1><?= htmlspecialchars($options['admin_page_title']) ?></h1>

        <!-- Barre de filtres et recherche -->
        <div class="filters">
            <form method="get">
                <input type="text" name="search" placeholder="Rechercher une série, un auteur ou un éditeur..."
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

        <!-- Menu d'actions -->
        <div class="admin-menu">
            <button id="open-add-series-modal">Ajouter une série</button>
            <button id="open-add-volume-modal">Ajouter un tome</button>
            <button id="open-add-multiple-volumes-modal">Ajouter plusieurs tomes</button>
            <button id="open-incomplete-series-modal" class="button button-otl">Séries incomplètes</button>
            <button id="open-wishlist-modal" class="button button-otl">Liste d'envies</button>
            <button id="open-options-modal" class="button button-opt">Options</button>
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
                    <input type="text" name="name" id="add-series-name" placeholder="Nom de la série" required>
                    <p>Auteur :</p>
                    <input type="text" name="author" id="add-series-author" placeholder="Auteur" required>
                    <p>Éditeur :</p>
                    <input type="text" name="publisher" id="add-series-publisher" placeholder="Éditeur" required>
                    <p>Catégories :</p>
                    <input type="text" name="categories" placeholder="Catégories (séparées par des virgules)" required>
                    <p>Genres :</p>
                    <input type="text" name="genres" placeholder="Genres (séparés par des virgules)">
                    <p>ID Anilist (facultatif) :</p>
                    <input type="text" name="anilist_id" placeholder="ID Anilist (facultatif)">
                    <p class="hint"><a tabindex="0" data-hint="L'ID Anilist est utilisé pour trouver les tomes manquants des sériées terminées, plus d'infos dans l'outil « Séries incomplètes ». Pour trouver cet identifiant, rendez-vous sur anilist.co, recherchez votre série et accédez à sa fiche, l'ID est la suite de chiffres avant le nom dans l'url.">À quoi ça sert ? Où le trouver ?</a>.</p>
                    <label>
                        <input type="checkbox" name="mature"> Contenu mature
                    </label>
                    <p>Vignette :</p>
                    <input type="file" name="image" accept="image/*" required>
                    <button type="submit" name="add_series">Ajouter</button>
                </form>
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
                <button id="search-incomplete-series" class="button button-otl">Rechercher les séries incomplètes</button>
                <div id="incomplete-series-results">
                    <!-- Les résultats seront affichés ici -->
                </div>
            </div>
        </div>

        <!-- Modale pour ajouter un tome -->
        <div class="modal" id="add-volume-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-add-volume-modal">&times;</span>
                <h2>Ajouter un tome</h2>
                <form method="post">
                    <p>Choisir une série :</p>
                    <input type="text" id="series-search" class="series-search" placeholder="Rechercher une série...">
                    <div class="series-results" id="series-results">
                        <?php foreach ($data as $series): ?>
                            <div data-id="<?= $series['id'] ?>"><?= $series['name'] ?></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="series_id" id="selected-series-id" required>
                    <p>Numéro du tome à ajouter :</p>
                    <input type="number" name="volume_number" placeholder="Numéro du tome" min="1" required>
                    <p>Statut du tome :</p>
                    <select name="status" required>
                        <option value="à lire">À lire</option>
                        <option value="en cours">En cours</option>
                        <option value="terminé">Terminé</option>
                    </select>
                    <label>
                        <input type="checkbox" name="is_collector"> Collector
                    </label>
                    <label>
                        <input type="checkbox" name="is_last"> Dernier tome
                    </label>
                    <button type="submit" name="add_volume">Ajouter</button>
                </form>
            </div>
        </div>

        <!-- Modale pour ajouter plusieurs tomes -->
        <div class="modal" id="add-multiple-volumes-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-add-multiple-volumes-modal">&times;</span>
                <h2>Ajouter plusieurs tomes</h2>
                <form method="post">
                    <p>Choisir une série :</p>
                    <input type="text" id="multiple-series-search" class="series-search" placeholder="Rechercher une série...">
                    <div class="series-results" id="multiple-series-results">
                        <?php foreach ($data as $series): ?>
                            <div data-id="<?= $series['id'] ?>"><?= $series['name'] ?></div>
                        <?php endforeach; ?>
                    </div>
                    <p>Tomes à ajouter :</p>
                    <input type="hidden" name="series_id" id="multiple-selected-series-id" required>
                    <div class="volume-range">
                        <input type="number" name="start_volume" placeholder="Numéro de début" min="1" required>
                        <span>à</span>
                        <input type="number" name="end_volume" placeholder="Numéro de fin" min="1" required>
                    </div>
                    <p>Statut des tomes :</p>
                    <select name="status" required>
                        <option value="à lire">À lire</option>
                        <option value="en cours">En cours</option>
                        <option value="terminé">Terminé</option>
                    </select>
                    <label>
                        <input type="checkbox" name="is_collector"> Collector
                    </label>
                    <p class="hint">Tous seront tagués ainsi.</p>
                    <label>
                        <input type="checkbox" name="is_last"> Dernier tome
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
                        <input type="checkbox" name="is_collector"> Collector
                    </label>
                    <label>
                        <input type="checkbox" name="is_last"> Dernier tome
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
                    <input type="text" name="edit_name" id="edit-series-name" placeholder="Nom de la série" required>
                    <p>Auteur :</p>
                    <input type="text" name="edit_author" id="edit-series-author" placeholder="Auteur" required>
                    <p>Éditeur :</p>
                    <input type="text" name="edit_publisher" id="edit-series-publisher" placeholder="Éditeur" required>
                    <p>Catégories :</p>
                    <input type="text" name="edit_categories" id="edit-series-categories" placeholder="Catégories (séparées par des virgules)" required>
                    <p>Genres :</p>
                    <input type="text" name="edit_genres" id="edit-series-genres" placeholder="Genres (séparés par des virgules)">
                    <p>ID Anilist (facultatif) :</p>
                    <input type="text" name="edit_anilist_id" id="edit-series-anilist-id" placeholder="ID Anilist (facultatif)">
                    <label>
                        <input type="checkbox" name="edit_mature" id="edit-series-mature"> Contenu mature
                    </label>
                    <div class="current-image-container">
                        <p>Vignette actuelle :</p>
                        <img id="current-series-image" src="" alt="Image actuelle" style="max-width: 100px; margin-bottom: 10px;">
                        <input type="checkbox" name="remove_image" id="remove-image-checkbox">
                        <label for="remove-image-checkbox">Supprimer l'image</label>
                    </div>
                    <input type="file" name="edit_image" id="edit-series-image" accept="image/*">
                    <button type="submit" name="update_series">Mettre à jour</button>
                </form>
            </div>
        </div>

        <!-- Modale pour la liste d'envies -->
        <div class="modal" id="wishlist-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-wishlist-modal">&times;</span>
                <h2>Liste d'envies</h2>
                <div class="wishlist-container">
                    <div class="wishlist-header">
                        <input type="text" id="wishlist-name" placeholder="Nom de la série" required>
                        <input type="text" id="wishlist-author" placeholder="Auteur" required>
                        <input type="text" id="wishlist-publisher" placeholder="Éditeur" required>
                        <button id="add-to-wishlist-btn" class="button button-otl">Ajouter à la liste</button>
                    </div>
                    <div class="wishlist-list" id="wishlist-list">
                        <?php foreach ($wishlist as $index => $item): ?>
                            <div class="wishlist-item" data-index="<?= $index ?>">
                                <span class="wishlist-series-name"><?= $item['name'] ?></span>
                                <span class="wishlist-series-author"><?= $item['author'] ?></span>
                                <span class="wishlist-series-publisher"><?= $item['publisher'] ?></span>
                                <div class="wishlist-item-actions">
                                    <button class="add-from-wishlist-btn" data-index="<?= $index ?>">+</button>
                                    <button class="remove-from-wishlist-btn" data-index="<?= $index ?>">x</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modale pour les options du site -->
        <div class="modal" id="options-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-options-modal">&times;</span>
                <h2>Options du site</h2>
                <p class="hint">Site en version <?= SITE_VERSION ?>. <a href="<?= URL_GITEA ?>" target="_blank">Accéder au dépôt Gitéa</a>.</p>
                <form id="options-form" method="post">
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

                    <label for="admin-password">Mot de passe admin</label>
                    <input type="password" name="admin_password" id="admin-password" placeholder="Mot de passe admin">
                    <p class="hint">Laisser vide pour ne pas modifier.</p>

                    <label>
                        <input type="checkbox" name="private_mode" <?= load_options()['private_mode'] ? 'checked' : '' ?>> Mode privé
                    </label>
                    <p class="hint">Votre bibliothèque ne sera pas visible publiquement.</p>

                    <label>
                        <input type="checkbox" name="hide_mature" <?= load_options()['hide_mature'] ? 'checked' : '' ?>> Masquer les séries matures
                    </label>

                    <button type="submit" name="update_options" class="button button-opt">Mettre à jour</button>
                    <p style="visibility: hidden;">_</p>
                    <p class="hint">Merci de recharger la page après l'application des modifications, afin d'actualiser les champs des paramètres ci-dessus.</p>
                </form>
            </div>
        </div>

        <!-- Liste des séries -->
        <div class="series-list">
            <?php if (empty($data)): ?>
                <p>Aucune série trouvée.</p>
            <?php else: ?>
                <?php foreach ($data as $series): ?>
                    <?php if (empty($series['volumes'])) continue; ?>
                    <div class="series-card">
                        <img class="series-image" src="<?= $series['image'] ?>" alt="<?= $series['name'] ?>">
                        <div class="series-info">
                            <div class="series-header">
                                <h2><?= $series['name'] ?></h2>
                                <div class="series-actions">
                                    <button class="edit-series-btn" data-series-id="<?= $series['id'] ?>">Modifier</button>
                                    <button class="delete-series-btn" data-series-id="<?= $series['id'] ?>">Supprimer</button>
                                </div>
                            </div>
                            <p><strong>Auteur :</strong> <?= $series['author'] ?></p>
                            <p><strong>Éditeur :</strong> <?= $series['publisher'] ?></p>
                            <p><strong>Catégories :</strong> <?= isset($series['categories']) ? implode(', ', $series['categories']) : '' ?></p>
                            <p><strong>Genres :</strong> <?= isset($series['genres']) ? implode(', ', $series['genres']) : '' ?></p>
                            <p><strong>ID Anilist :</strong> 
                                <?php if (isset($series['anilist_id']) && !empty($series['anilist_id'])): ?>
                                    <a href="https://anilist.co/manga/<?= htmlspecialchars($series['anilist_id']) ?>" target="_blank"><?= htmlspecialchars($series['anilist_id']) ?></a>
                                <?php else: ?>
                                    Non défini
                                <?php endif; ?>
                            </p>
                            <p><strong>Tomes :</strong> <?= count($series['volumes']) ?></p>
                            <h3>Liste des tomes :</h3>
                            <?php
                            // Trier les tomes par numéro
                            sort_volumes($series['volumes']);

                            // Générer les notifications
                            $notifications = generate_notifications($series['volumes']);
                            $anilist_volumes = null;
                            if (isset($series['anilist_id']) && !empty($series['anilist_id'])) {
                                $anilist_volumes = get_series_volumes_from_anilist($series['anilist_id']);
                            }
                            $notifications = generate_notifications($series['volumes'], $anilist_volumes);

                            // Afficher les notifications si nécessaire
                            if (!empty($notifications)) {
                                echo '<div class="issues-list">';
                                echo '<span class="warning-icon">!</span>';
                                echo '<span class="issues-text">' . implode(' ', $notifications) . '</span>';
                                echo '</div>';
                            }
                            ?>
                            <ul class="volumes-list">
                                <?php foreach ($series['volumes'] as $volume_index => $volume): ?>
                                    <li class="<?= 'status-' . str_replace(' ', '-', strtolower($volume['status'])) .
                                        (!empty($volume['collector']) ? ' volume-collector' : '') .
                                        (!empty($volume['last']) ? ' volume-last' : '') ?>"
                                        data-series-id="<?= $series['id'] ?>"
                                        data-volume-index="<?= $volume_index ?>">
                                        <?= $volume['number'] ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Données des séries pour JavaScript
        const seriesData = <?= json_encode($data) ?>;
        const wishlistData = <?= json_encode($wishlist) ?>;
    </script>
    <script src="scripts/admin.js"></script>

</body>
</html>