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

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page_admin = 9;
$offset = ($page - 1) * $per_page_admin;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_paginated_series'])) {
    // Récupère les paramètres de pagination et de recherche
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 9;
    $search_term = $_GET['search'] ?? '';
    $sort_by = $_GET['sort_by'] ?? 'name';
    $sort_order = $_GET['sort_order'] ?? 'asc';

    // Applique le tri et la recherche aux données
    $filtered_data = $data;
    if (!empty($search_term)) {
        $filtered_data = array_filter($filtered_data, function($series) use ($search_term) {
            return stripos($series['name'], $search_term) !== false ||
                   stripos($series['author'], $search_term) !== false ||
                   stripos($series['publisher'], $search_term) !== false ||
                   (isset($series['categories']) && stripos(implode(', ', $series['categories']), $search_term) !== false) ||
                   (isset($series['genres']) && stripos(implode(', ', $series['genres']), $search_term) !== false);
        });
    }
    sort_series($filtered_data, $sort_by, $sort_order);

    // Génère les notifications pour chaque série
    foreach ($filtered_data as &$series) {
        $anilist_volumes = null;
        if (isset($series['anilist_id']) && !empty($series['anilist_id'])) {
            $anilist_volumes = get_series_volumes_from_anilist($series['anilist_id']);
        }
        $series['notifications'] = generate_notifications($series['volumes'], $anilist_volumes);
    }

    // Paginer les résultats filtrés
    $offset = ($page - 1) * $per_page;
    $paginated_data = array_slice($filtered_data, $offset, $per_page);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'series' => array_values($paginated_data),
        'has_more' => ($offset + $per_page) < count($filtered_data)
    ]);
    exit;
}

// Gérer les actions pour les séries incomplètes
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
    if (file_exists('bdd/list.json')) {
        $wishlist = json_decode(file_get_contents('bdd/list.json'), true);
        return $wishlist ?: [];
    }
    return [];
}

// Sauvegarder la liste d'envies dans le fichier JSON
function save_wishlist($wishlist) {
    file_put_contents('bdd/list.json', json_encode($wishlist, JSON_PRETTY_PRINT));
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
    $anilist_id = trim($_POST['anilist_id'] ?? '');
    $mature = !empty($_POST['mature']);
    $favorite = !empty($_POST['favorite']);
    $volumes_count = (int)($_POST['volumes_count'] ?? 1);
    $volumes_status = $_POST['volumes_status'] ?? 'à lire';
    $all_collector = !empty($_POST['all_collector']);
    $last_volume = !empty($_POST['last_volume']);

    // Vérifier si une série avec le même nom existe déjà
    $series_exists = false;
    foreach ($data as $series) {
        if (strcasecmp($series['name'], $name) === 0) {
            $series_exists = true;
            break;
        }
    }

    // Vérifier l'upload de l'image
    $error_message = null;
    $image = upload_image($_FILES['image'] ?? [], $error_message);

    if ($image === false) {
        echo $error_message;
        exit;
    }


    if ($series_exists) {
        $_SESSION['error_message'] = "Une série avec ce nom existe déjà.";
    } elseif ($image === false) {
        $_SESSION['error_message'] = $error_message ?: "Erreur inconnue lors du téléversement de l'image.";
    } elseif (empty($name) || empty($author) || empty($publisher) || empty($categories)) {
        $_SESSION['error_message'] = "Veuillez remplir tous les champs obligatoires.";
    } else {
        $volumes = [];
        for ($i = 1; $i <= $volumes_count; $i++) {
            $volumes[] = [
                'number' => $i,
                'status' => $volumes_status,
                'collector' => $all_collector,
                'last' => ($last_volume && $i == $volumes_count)
            ];
        }

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
            'favorite' => $favorite,
            'volumes' => $volumes
        ];
        save_data($data);
        $_SESSION['success_message'] = "Série ajoutée avec succès.";
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
    $favorite = !empty($_POST['edit_favorite']);
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
        $data[$series_index]['favorite'] = $favorite;

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

            // Hasher le mot de passe
            $password_hash = password_hash($admin_password, PASSWORD_DEFAULT);

            // Sauvegarder le hash dans bdd/mdp.json
            $password_data = ['admin_password_hash' => $password_hash];
            file_put_contents(PASSWORD_FILE, json_encode($password_data, JSON_PRETTY_PRINT));
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

// Fonction pour obtenir les valeurs uniques d'un champ spécifique
function get_unique_values($data, $field) {
    $values = [];
    foreach ($data as $series) {
        if (isset($series[$field])) {
            if (is_array($series[$field])) {
                foreach ($series[$field] as $value) {
                    $value = trim($value);
                    if (!empty($value) && !in_array($value, $values, true)) {
                        $values[] = $value;
                    }
                }
            } else {
                $value = trim($series[$field]);
                if (!empty($value) && !in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
        }
    }
    return $values;
}

// Gérer les suggestions pour l'auto-complétion
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_suggestions'])) {
    $field = $_GET['field'] ?? '';
    $term = strtolower($_GET['term'] ?? '');
    $suggestions = [];

    if (in_array($field, ['author', 'publisher', 'categories', 'genres'])) {
        $values = get_unique_values($data, $field);
        foreach ($values as $value) {
            if (strpos(strtolower($value), $term) !== false) {
                $suggestions[] = $value;
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode($suggestions);
    exit;
}

// Charger les données de prêt
function load_loans() {
    if (file_exists('bdd/loan.json')) {
        $loans = json_decode(file_get_contents('bdd/loan.json'), true);
        return $loans ?: [];
    }
    return [];
}

// Sauvegarder les données de prêt
function save_loans($loans) {
    file_put_contents('bdd/loan.json', json_encode($loans, JSON_PRETTY_PRINT));
}

// Ajouter un prêt (un seul tome)
function add_loan($data, $series_id, $volume_number, $borrower_name) {
    $loans = load_loans();

    // Vérifier si la série existe
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return [
            'success' => false,
            'error' => 'series_not_found',
            'message' => 'La série sélectionnée n\'existe pas dans votre base. Veuillez vérifier votre sélection.'
        ];
    }

    // Vérifier si le tome est possédé
    if (!is_volume_owned($data, $series_id, $volume_number)) {
        return [
            'success' => false,
            'error' => 'volume_not_owned',
            'message' => 'Vous ne possédez pas le tome ' . $volume_number . ' de cette série.'
        ];
    }

    // Vérifier si le tome est déjà en prêt
    foreach ($loans as $loan) {
        if ($loan['series_id'] === $series_id && $loan['volume_number'] == $volume_number) {
            return [
                'success' => false,
                'error' => 'volume_already_loaned',
                'message' => 'Le tome ' . $volume_number . ' est déjà en prêt.'
            ];
        }
    }

    $loans[] = [
        'series_id' => $series_id,
        'volume_number' => $volume_number,
        'borrower_name' => $borrower_name,
        'loan_date' => date('Y-m-d H:i:s')
    ];
    save_loans($loans);
    return ['success' => true];
}

// Ajouter un prêt (plusieurs tomes)
function add_multiple_loans($data, $series_id, $start_volume, $end_volume, $borrower_name) {
    $loans = load_loans();

    // Vérifier si la série existe
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['success' => false, 'error' => 'series_not_found', 'message' => 'La série sélectionnée n\'existe pas dans votre base. Veuillez vérifier votre sélection.'];
    }

    // Vérifier si tous les tomes sont possédés
    $ownership_check = are_volumes_owned($data, $series_id, $start_volume, $end_volume);
    if (!$ownership_check['owned']) {
        return ['success' => false, 'error' => 'volumes_not_owned', 'message' => 'Vous ne possédez pas tous les tomes sélectionnés. Tomes manquants : ' . implode(', ', $ownership_check['missing_volumes']), 'missing_volumes' => $ownership_check['missing_volumes']];
    }

    // Vérifier si certains tomes sont déjà en prêt
    $already_loaned = [];
    for ($i = $start_volume; $i <= $end_volume; $i++) {
        foreach ($loans as $loan) {
            if ($loan['series_id'] === $series_id && $loan['volume_number'] == $i) {
                $already_loaned[] = $i;
                break;
            }
        }
    }

    if (!empty($already_loaned)) {
        return [
            'success' => false,
            'error' => 'volumes_already_loaned',
            'message' => 'Les tomes ' . implode(', ', $already_loaned) . ' sont déjà en prêt.',
            'already_loaned' => $already_loaned
        ];
    }

    for ($i = $start_volume; $i <= $end_volume; $i++) {
        $loans[] = [
            'series_id' => $series_id,
            'volume_number' => $i,
            'borrower_name' => $borrower_name,
            'loan_date' => date('Y-m-d H:i:s')
        ];
    }
    save_loans($loans);
    return ['success' => true];
}

// Supprimer un prêt
function remove_loan($series_id, $volume_number) {
    $loans = load_loans();
    foreach ($loans as $index => $loan) {
        if ($loan['series_id'] === $series_id && $loan['volume_number'] == $volume_number) {
            array_splice($loans, $index, 1);
            save_loans($loans);
            return true;
        }
    }
    return false;
}

// Récupérer les prêts par série (y compris les séries supprimées)
function get_loans_by_series($data) {
    $loans = load_loans();
    $result = [];
    $series_ids = [];

    // Récupérer tous les IDs de séries existantes
    foreach ($data as $series) {
        $series_ids[] = $series['id'];
    }

    // Grouper les prêts par série
    $loans_by_series = [];
    foreach ($loans as $loan) {
        $series_id = $loan['series_id'];
        if (!isset($loans_by_series[$series_id])) {
            $loans_by_series[$series_id] = [];
        }
        $loans_by_series[$series_id][] = $loan;
    }

    // Créer le résultat
    foreach ($loans_by_series as $series_id => $loans) {
        $series = find_series_by_id($data, $series_id);
        $result[] = [
            'series' => $series ? $series['series'] : null,
            'loans' => $loans,
            'series_exists' => $series !== null
        ];
    }

    return $result;
}

// Gestion des actions pour les prêts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loan_action'])) {
    $response = ['success' => false];
    $action = $_POST['loan_action'];
    $data = load_data();

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

            // Vérifier que la série existe avant de continuer
            $series = find_series_by_id($data, $series_id);
            if (!$series) {
                $response = ['success' => false, 'error' => 'series_not_found', 'message' => 'La série sélectionnée n\'existe pas dans votre base. Veuillez vérifier votre sélection.'];
                break;
            }

            if ($series_id && $start_volume > 0 && $end_volume >= $start_volume && $borrower_name) {
                $response = add_multiple_loans($data, $series_id, $start_volume, $end_volume, $borrower_name);
            }
            break;

        case 'add_single_loan':
            $series_id = $_POST['series_id'] ?? '';
            $volume_number = (int)($_POST['volume_number'] ?? 0);
            $borrower_name = trim($_POST['borrower_name'] ?? '');

            if ($series_id && $volume_number > 0 && $borrower_name) {
                add_loan($series_id, $volume_number, $borrower_name);
                $response['success'] = true;
            }
            break;

        case 'add_multiple_loans':
            $series_id = $_POST['series_id'] ?? '';
            $start_volume = (int)($_POST['start_volume'] ?? 0);
            $end_volume = (int)($_POST['end_volume'] ?? 0);
            $borrower_name = trim($_POST['borrower_name'] ?? '');

            if ($series_id && $start_volume > 0 && $end_volume >= $start_volume && $borrower_name) {
                add_multiple_loans($series_id, $start_volume, $end_volume, $borrower_name);
                $response['success'] = true;
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

// Supprimer tous les prêts d'une série
function remove_all_loans($series_id) {
    $loans = load_loans();
    $loans = array_filter($loans, function($loan) use ($series_id) {
        return $loan['series_id'] !== $series_id;
    });
    save_loans(array_values($loans));
    return true;
}

// Vérifier si un tome est possédé
function is_volume_owned($data, $series_id, $volume_number) {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return false;
    }
    foreach ($series['series']['volumes'] as $volume) {
        if ($volume['number'] == $volume_number) {
            return true;
        }
    }
    return false;
}

// Vérifier si plusieurs tomes sont possédés
function are_volumes_owned($data, $series_id, $start_volume, $end_volume) {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['owned' => false, 'error' => 'series_not_found'];
    }

    $missing_volumes = [];
    for ($i = $start_volume; $i <= $end_volume; $i++) {
        if (!is_volume_owned($data, $series_id, $i)) {
            $missing_volumes[] = $i;
        }
    }

    if (empty($missing_volumes)) {
        return ['owned' => true];
    } else {
        return ['owned' => false, 'missing_volumes' => $missing_volumes];
    }
}

// Gestion des sauvegardes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup_action'])) {
    $action = $_POST['backup_action'];
    $response = ['success' => false, 'message' => ''];

    switch ($action) {
        case 'create_backup':
            $backup_dir = 'saves';
            if (!file_exists($backup_dir)) {
                mkdir($backup_dir, 0777, true);
            }

            $timestamp = time();
            $backup_name = "save_$timestamp.zip";
            $backup_path = "$backup_dir/$backup_name";

            $zip = new ZipArchive();
            if ($zip->open($backup_path, ZipArchive::CREATE) === TRUE) {
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

                $zip->close();
                $response['success'] = true;
                $response['message'] = 'Sauvegarde créée avec succès.';
            } else {
                $response['message'] = 'Impossible de créer la sauvegarde.';
            }
            break;

        case 'delete_backup':
            $backup_file = $_POST['backup_file'] ?? '';
            if (!empty($backup_file) && file_exists("saves/$backup_file")) {
                unlink("saves/$backup_file");
                $response['success'] = true;
                $response['message'] = 'Sauvegarde supprimée avec succès.';
            } else {
                $response['message'] = 'Fichier de sauvegarde introuvable.';
            }
            break;

        case 'list_backups':
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
            $response['success'] = true;
            $response['backups'] = $backups;
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

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

        <!-- Menu d'actions -->
        <div class="admin-menu">
            <button id="open-add-series-modal">Ajouter une série</button>
            <button id="open-add-volume-modal" style="display: none;">Ajouter un tome</button>
            <button id="open-add-multiple-volumes-modal">Ajouter des tomes</button>
            <button id="open-incomplete-series-modal" class="button button-otl">Séries incomplètes</button>
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
                    <input type="text" name="name" id="add-series-name" placeholder="Nom de la série" autocomplete="off" required>
                    <p>Auteur :</p>
                    <input type="text" name="author" id="add-series-author" placeholder="Auteur" autocomplete="off" required>
                    <p>Éditeur :</p>
                    <input type="text" name="publisher" id="add-series-publisher" placeholder="Éditeur" autocomplete="off" required>
                    <p>Catégories :</p>
                    <input type="text" name="categories" id="add-series-categories" placeholder="Catégories (séparées par des virgules)" autocomplete="off" required>
                    <p>Genres :</p>
                    <input type="text" name="genres" id="add-series-genres" placeholder="Genres (séparés par des virgules)" autocomplete="off">
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
                    <p>ID Anilist (facultatif) :</p>
                    <input type="text" name="anilist_id" placeholder="ID Anilist (facultatif)" autocomplete="off">
                    <p class="hint"><a tabindex="0" data-hint="L'ID Anilist est utilisé pour trouver les tomes manquants des sériées terminées, plus d'infos dans l'outil « Séries incomplètes ». Pour trouver cet identifiant, rendez-vous sur anilist.co, recherchez votre série et accédez à sa fiche, l'ID est la suite de chiffres avant le nom dans l'url.">À quoi ça sert ? Où le trouver ?</a>.</p>
                    <label>
                        <input type="checkbox" name="mature"> Contenu mature 🔞
                    </label>
                    <label>
                        <input type="checkbox" name="favorite"> Série favorite ❤️
                    </label>
                    <p>Vignette :</p>
                    <input type="file" name="image" accept="image/jpeg, image/jpg, image/png, image/gif, image/webp" required>
                    <p class="hint">Extensions autorisées : jpeg, jpg, png, gif et webp. Poids maximum : 5 Mo.</p>
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
                    <input type="text" id="series-search" class="series-search" placeholder="Rechercher une série..." autocomplete="off">
                    <div class="series-results" id="series-results">
                        <?php foreach ($data as $series): ?>
                            <div data-id="<?= $series['id'] ?>"><?= $series['name'] ?></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="series_id" id="selected-series-id" required>
                    <p>Numéro du tome à ajouter :</p>
                    <input type="number" inputmode="numeric" name="volume_number" placeholder="Numéro du tome" min="1" autocomplete="off" required>
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
                <h2>Ajouter des tomes</h2>
                <form method="post">
                    <p>Choisir une série :</p>
                    <input type="text" id="multiple-series-search" class="series-search" placeholder="Rechercher une série..." autocomplete="off">
                    <div class="series-results" id="multiple-series-results">
                        <?php foreach ($data as $series): ?>
                            <div data-id="<?= $series['id'] ?>"><?= $series['name'] ?></div>
                        <?php endforeach; ?>
                    </div>
                    <p>Tomes à ajouter :</p>
                    <input type="hidden" name="series_id" id="multiple-selected-series-id" required>
                    <div class="volume-range">
                        <input type="number" inputmode="numeric" name="start_volume" placeholder="Numéro de début" min="1" autocomplete="off" required>
                        <span>à</span>
                        <input type="number" inputmode="numeric" name="end_volume" placeholder="Numéro de fin" min="1" autocomplete="off" required>
                    </div>
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
                    <p>Catégories :</p>
                    <input type="text" name="edit_categories" id="edit-series-categories" placeholder="Catégories (séparées par des virgules)" autocomplete="off" required>
                    <p>Genres :</p>
                    <input type="text" name="edit_genres" id="edit-series-genres" placeholder="Genres (séparés par des virgules)" autocomplete="off">
                    <p>ID Anilist (facultatif) :</p>
                    <input type="text" name="edit_anilist_id" id="edit-series-anilist-id" placeholder="ID Anilist (facultatif)" autocomplete="off">
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
                        <button type="submit" class="button button-otl">Ajouter</button>
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
                        <button type="submit" class="button button-otl">Ajouter</button>
                    </form>
                </div>

                <!-- Liste des prêts -->
                <div class="loan-section">
                    <h3>Liste des livres prêtés</h3>
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

        <!-- Modale pour les outils de sauvegarde -->
        <div class="modal" id="tools-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-tools-modal">&times;</span>
                <h2>Outils de sauvegarde</h2>
                <p>Vous pouvez ici sauvegarder vos données.</p>

                <div class="tools-section">
                    <h3>Créer une sauvegarde</h3>
                    <p>Crée une archive de vos données actuelles.</p>
                    <button id="create-backup-btn" class="button button-opt">Créer une sauvegarde</button>
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
            <?php if (empty($data)): ?>
                <p>Aucune série trouvée.</p>
            <?php else: ?>
                <?php
                // Applique la recherche et le tri AVANT la pagination
                $filtered_data = $data;
                if (!empty($search_term)) {
                    $filtered_data = array_filter($filtered_data, function($series) use ($search_term) {
                        return stripos($series['name'], $search_term) !== false ||
                            stripos($series['author'], $search_term) !== false ||
                            stripos($series['publisher'], $search_term) !== false ||
                            (isset($series['categories']) && stripos(implode(', ', $series['categories']), $search_term) !== false) ||
                            (isset($series['genres']) && stripos(implode(', ', $series['genres']), $search_term) !== false);
                    });
                }
                sort_series($filtered_data, $sort_by, $sort_order);

                // Affiche les 9 premières séries filtrées
                $paginated_data = array_slice($filtered_data, 0, 9);
                foreach ($paginated_data as $series):
                    if (empty($series['volumes'])) continue;
                ?>
                    <div class="series-card <?= isset($series['favorite']) && $series['favorite'] ? 'favorite' : '' ?>">
                        <img class="series-image" src="<?= $series['image'] ?>" alt="<?= $series['name'] ?>" loading="lazy">
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
                            <?php if (!empty($series['mature'])): ?>
                                <span class="mature-badge">🔞 Mature</span>
                            <?php endif; ?>
                            <h3>Liste des tomes :</h3>
                            <?php
                            // Générer les notifications
                            $anilist_volumes = null;
                            if (isset($series['anilist_id']) && !empty($series['anilist_id'])) {
                                $anilist_volumes = get_series_volumes_from_anilist($series['anilist_id']);
                            }
                            $notifications = generate_notifications($series['volumes'], $anilist_volumes);

                            // Afficher les notifications si nécessaire
                            if (!empty($notifications)) {
                                echo '<div class="issues-list">';
                                echo '<span class="warning-icon">⚠️</span>';
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
        <div class="loading-spinner" id="loading-spinner">
            <p>Chargement en cours...</p>
        </div>

    </div>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <script>
        // Données des séries pour JavaScript
        const seriesData = <?= json_encode($data) ?>;
        const wishlistData = <?= json_encode($wishlist) ?>;
    </script>
    <script src="scripts/admin.js"></script>

</body>
</html>