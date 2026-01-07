<?php
require 'config.php';
session_start();

if (!($_SESSION['logged_in'] ?? false)) {
    header('Location: login.php');
    exit;
}

$data = load_data();

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
    $image = upload_image($_FILES['image'] ?? []);

    if ($image && $name && $author && $publisher && $categories) {
        $data[] = [
            'id' => generate_uuid(),
            'name' => $name,
            'author' => $author,
            'publisher' => $publisher,
            'categories' => explode(',', $categories),
            'image' => $image,
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
        header("Location: admin.php");
        exit;
    }
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
                }
            }
        } elseif (isset($_POST['add_multiple_volumes'])) {
            $start = (int)($_POST['start_volume'] ?? 0);
            $end = (int)($_POST['end_volume'] ?? 0);
            if ($start > 0 && $end >= $start) {
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
                    }
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
        header("Location: admin.php");
        exit;
    }
}

// Mettre à jour une série
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_series'])) {
    $series_id = $_POST['series_id'] ?? '';
    $name = trim($_POST['edit_name'] ?? '');
    $author = trim($_POST['edit_author'] ?? '');
    $publisher = trim($_POST['edit_publisher'] ?? '');
    $categories = trim($_POST['edit_categories'] ?? '');
    $remove_image = !empty($_POST['remove_image']);

    $series = find_series_by_id($data, $series_id);
    if ($series && $name && $author && $publisher && $categories) {
        $series_index = $series['index'];

        $data[$series_index]['name'] = $name;
        $data[$series_index]['author'] = $author;
        $data[$series_index]['publisher'] = $publisher;
        $data[$series_index]['categories'] = explode(',', $categories);

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

sort_series($data, $sort_by, $sort_order);

if ($search_term) {
    $data = array_filter($data, function($series) use ($search_term) {
        return stripos($series['name'], $search_term) !== false ||
               stripos($series['author'], $search_term) !== false ||
               stripos($series['publisher'], $search_term) !== false ||
               (isset($series['categories']) && stripos(implode(', ', $series['categories']), $search_term) !== false);
    });
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion de ma collection</title>
    <meta name="description" content="Lengas - Gestion de la collection de mangas d'Esenjin.">
    <link rel="icon" href="logo.png" type="image/png">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <h1>Gestion de ma collection</h1>

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
            <a href="index.php" class="button menu-button" target="_blank">Accueil ↗</a>
            <a href="stats.php" class="button menu-button" target="_blank">Statistiques ↗</a>
        </div>

        <!-- Modales -->
        <!-- Modale pour ajouter une série -->
        <div class="modal" id="add-series-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-add-series-modal">&times;</span>
                <h2>Ajouter une série</h2>
                <form method="post" enctype="multipart/form-data">
                    <input type="text" name="name" placeholder="Nom de la série" required>
                    <input type="text" name="author" placeholder="Auteur" required>
                    <input type="text" name="publisher" placeholder="Éditeur" required>
                    <input type="text" name="categories" placeholder="Catégories (séparées par des virgules)" required>
                    <input type="file" name="image" accept="image/*" required>
                    <button type="submit" name="add_series">Ajouter</button>
                </form>
            </div>
        </div>

        <!-- Modale pour ajouter un tome -->
        <div class="modal" id="add-volume-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-add-volume-modal">&times;</span>
                <h2>Ajouter un tome</h2>
                <form method="post">
                    <input type="text" id="series-search" class="series-search" placeholder="Rechercher une série...">
                    <div class="series-results" id="series-results">
                        <?php foreach ($data as $series): ?>
                            <div data-id="<?= $series['id'] ?>"><?= $series['name'] ?></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="series_id" id="selected-series-id" required>
                    <input type="number" name="volume_number" placeholder="Numéro du tome" min="1" required>
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
                    <input type="text" id="multiple-series-search" class="series-search" placeholder="Rechercher une série...">
                    <div class="series-results" id="multiple-series-results">
                        <?php foreach ($data as $series): ?>
                            <div data-id="<?= $series['id'] ?>"><?= $series['name'] ?></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="series_id" id="multiple-selected-series-id" required>
                    <div class="volume-range">
                        <input type="number" name="start_volume" placeholder="Numéro de début" min="1" required>
                        <span>à</span>
                        <input type="number" name="end_volume" placeholder="Numéro de fin" min="1" required>
                    </div>
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
                    <input type="text" name="edit_name" id="edit-series-name" placeholder="Nom de la série" required>
                    <input type="text" name="edit_author" id="edit-series-author" placeholder="Auteur" required>
                    <input type="text" name="edit_publisher" id="edit-series-publisher" placeholder="Éditeur" required>
                    <input type="text" name="edit_categories" id="edit-series-categories" placeholder="Catégories (séparées par des virgules)" required>
                    <div class="current-image-container">
                        <p>Image actuelle :</p>
                        <img id="current-series-image" src="" alt="Image actuelle" style="max-width: 100px; margin-bottom: 10px;">
                        <input type="checkbox" name="remove_image" id="remove-image-checkbox">
                        <label for="remove-image-checkbox">Supprimer l'image</label>
                    </div>
                    <input type="file" name="edit_image" id="edit-series-image" accept="image/*">
                    <button type="submit" name="update_series">Mettre à jour</button>
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
                            <p><strong>Tomes :</strong> <?= count($series['volumes']) ?></p>
                            <h3>Liste des tomes :</h3>
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
    </script>
    <script src="scripts/admin.js"></script>

</body>
</html>