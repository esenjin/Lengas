<?php
require 'config.php';
$data = load_data();
$options = load_options();

// Vérifier si le mode privé est activé
if ($options['private_mode']) {
    // Afficher un message informatif avec la structure HTML complète
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($options['index_page_title']) ?></title>
        <meta name="description" content="<?= htmlspecialchars($options['site_description']) ?>">
        <meta property="og:image" content="logo.png">
        <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
        <link rel="stylesheet" href="assets/css/main.css">
    </head>
    <body>
        <div class="container">
            <h1><?= htmlspecialchars($options['index_page_title']) ?></h1>
            <p>Le site est en mode privé. La bibliothèque n'est pas accessible au public.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Fonction pour normaliser une chaîne de caractères
function normalize_string($str) {
    $str = mb_strtolower($str, 'UTF-8');
    $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    $str = preg_replace('/[^a-z0-9\s\-]/', '', $str);
    return $str;
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page_public = 12;
$offset = ($page - 1) * $per_page_public;


// Endpoint pour la pagination infinie
if (isset($_GET['get_paginated_series'])) {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 12;
    $search_term = $_GET['search'] ?? '';
    $sort_by = $_GET['sort_by'] ?? 'name';
    $sort_order = $_GET['sort_order'] ?? 'asc';
    $status_filter = $_GET['status_filter'] ?? '';

    // Applique la recherche et le tri à chaque requête
    $filtered_data = $data;
    if (!empty($search_term)) {
        $normalized_search = normalize_string($search_term);
        $filtered_data = array_filter($data, function($series) use ($normalized_search) {
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
            if ($status_filter === 'reading_not_started') {
                if (!empty($series['reading_abandoned'])) return false;
                foreach ($series['volumes'] ?? [] as $volume) {
                    if ($volume['status'] === 'terminé') return false;
                }
                return true;
            }
            if ($status_filter === 'reading_in_progress') {
                if (!empty($series['reading_abandoned'])) return false;
                $has_read = false;
                $is_pub_finished = false;
                foreach ($series['volumes'] ?? [] as $volume) {
                    if ($volume['status'] === 'terminé') $has_read = true;
                    if (!empty($volume['last'])) $is_pub_finished = true;
                }
                return $has_read && !$is_pub_finished;
            }
            if ($status_filter === 'reading_completed') {
                if (!empty($series['reading_abandoned'])) return false;
                $volumes = $series['volumes'] ?? [];
                if (empty($volumes)) return false;
                $has_last = false;
                foreach ($volumes as $volume) {
                    if ($volume['status'] !== 'terminé') return false;
                    if (!empty($volume['last'])) $has_last = true;
                }
                return $has_last;
            }
            if ($status_filter === 'reading_abandoned') {
                return !empty($series['reading_abandoned']);
            }
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

    // Trie les résultats filtrés
    sort_series($filtered_data, $sort_by, $sort_order);

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

// ── Endpoint : suggestions d'autocomplétion pour la barre de recherche ──────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_suggestions'])) {
    $all_data = load_data();
    $field = $_GET['field'] ?? '';
    $term  = trim($_GET['term'] ?? '');
    $normalizedTerm = normalize_string($term);
    $suggestions = [];

    if (in_array($field, ['name', 'author', 'publisher', 'other_contributors', 'categories', 'genres'])) {
        foreach ($all_data as $series) {
            if (!isset($series[$field])) continue;
            $values = is_array($series[$field]) ? $series[$field] : [$series[$field]];
            foreach ($values as $value) {
                $value = trim((string)$value);
                if ($value === '') continue;
                if (str_contains(normalize_string($value), $normalizedTerm) && !in_array($value, $suggestions)) {
                    $suggestions[] = $value;
                }
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode(array_values(array_unique($suggestions)));
    exit;
}

// Filtrer les séries matures si l'option est activée
if ($options['hide_mature']) {
    $data = array_filter($data, function($series) {
        return !($series['mature'] ?? false);
    });
}

// Vérifier si le mode privé est activé
if ($options['private_mode']) {
    // Afficher un message informatif avec la structure HTML complète
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($options['index_page_title']) ?></title>
        <meta name="description" content="<?= htmlspecialchars($options['site_description']) ?>">
        <meta property="og:image" content="logo.png">
        <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
        <link rel="stylesheet" href="assets/css/main.css">
    </head>
    <body>
        <div class="container">
            <h1><?= htmlspecialchars($options['index_page_title']) ?></h1>
            <p>Le site est en mode privé. La bibliothèque n'est pas accessible au public.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Gestion du tri, filtre et recherche
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'asc';
$search_term = $_GET['search'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';

function sort_series(&$data, $sort_by, $sort_order) {
    usort($data, function($a, $b) use ($sort_by, $sort_order) {
        // Vérifier si les clés existent avant de les utiliser
        if ($sort_by === 'volumes') {
            $a_volumes = count($a['volumes'] ?? []);
            $b_volumes = count($b['volumes'] ?? []);
            return $sort_order === 'asc' ? $a_volumes - $b_volumes : $b_volumes - $a_volumes;
        } elseif ($sort_by === 'categories') {
            $a_categories = implode(', ', $a['categories'] ?? []);
            $b_categories = implode(', ', $b['categories'] ?? []);
            return $sort_order === 'asc' ? strcasecmp($a_categories, $b_categories) : strcasecmp($b_categories, $a_categories);
        } else {
            $a_value = $a[$sort_by] ?? '';
            $b_value = $b[$sort_by] ?? '';
            return $sort_order === 'asc' ? strcasecmp($a_value, $b_value) : strcasecmp($b_value, $a_value);
        }
    });
}

sort_series($data, $sort_by, $sort_order);

// Appliquer le filtre de recherche
if (!empty($search_term)) {
    $normalized_search = normalize_string($search_term);
    $data = array_filter($data, function($series) use ($normalized_search) {
        return strpos(normalize_string($series['name'] ?? ''), $normalized_search) !== false ||
               strpos(normalize_string($series['author'] ?? ''), $normalized_search) !== false ||
               strpos(normalize_string($series['publisher'] ?? ''), $normalized_search) !== false ||
               (isset($series['other_contributors']) && strpos(normalize_string(implode(', ', $series['other_contributors'])), $normalized_search) !== false) ||
               (isset($series['categories']) && strpos(normalize_string(implode(', ', $series['categories'])), $normalized_search) !== false) ||
               (isset($series['genres']) && strpos(normalize_string(implode(', ', $series['genres'])), $normalized_search) !== false);
    });
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
    <title><?= htmlspecialchars($options['index_page_title']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($options['site_description']) ?>">
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
    <style>
        /* Style pour les cartes cliquables */
        .series-card {
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .series-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.3);
        }

        /* Style pour le pied de page */
        .footer {
            justify-content: center;
            display: flex;
            padding-top: 20px;
            }
    </style>
</head>
<body>
    <div class="container">
        <div class="logout-container">
            <a href="admin.php" class="logout-button" title="Administration">
                <img src="https://api.iconify.design/mdi/lock.svg?color=white" alt="Administration" width="18" height="18">
            </a>
            <button class="logout-button" id="open-legend-modal" title="Légende" style="background:none;border:none;cursor:pointer;display:inline-flex;align-items:center;padding:4px 6px;">
                <img src="https://api.iconify.design/mdi/information-outline.svg?color=white" alt="Légende" width="18" height="18">
            </button>
        </div>
        <h1><?= htmlspecialchars($options['index_page_title']) ?></h1>

        <!-- Bouton Menu Mobile -->
        <button class="mobile-menu-button" id="mobile-menu-button">☰ Menu</button>

        <!-- Menu d'actions -->
        <div class="public-menu" id="public-menu">
            <?php if (!empty($options['custom_button_name']) && !empty($options['custom_button_url'])): ?>
                <a href="<?= htmlspecialchars($options['custom_button_url']) ?>" class="button button-otl" target="_blank"><?= htmlspecialchars($options['custom_button_name']) ?> ↗</a>
            <?php endif; ?>
            <?php if (!empty($options['custom_button_name2']) && !empty($options['custom_button_url2'])): ?>
                <a href="<?= htmlspecialchars($options['custom_button_url2']) ?>" class="button button-otl" target="_blank"><?= htmlspecialchars($options['custom_button_name2']) ?> ↗</a>
            <?php endif; ?>
            <?php if (!empty($options['custom_button_name3']) && !empty($options['custom_button_url3'])): ?>
                <a href="<?= htmlspecialchars($options['custom_button_url3']) ?>" class="button button-otl" target="_blank"><?= htmlspecialchars($options['custom_button_name3']) ?> ↗</a>
            <?php endif; ?>
            <a href="stats.php" class="button" target="_blank">Statistiques Lengas ↗</a>
        </div>

        <!-- Barre de filtres et recherche -->
        <div class="filters">
            <form method="get">
                <input type="text" name="search" id="search-index" placeholder="Rechercher une série, un auteur ou un éditeur..."
                       value="<?= htmlspecialchars($search_term ?? '') ?>" autocomplete="off">
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
                        <option value="en cours" <?= $status_filter === 'en cours' ? 'selected' : '' ?>>Publication en cours ▶️</option>
                        <option value="terminée" <?= $status_filter === 'terminée' ? 'selected' : '' ?>>Publication terminée ✅</option>
                        <option value="en pause" <?= $status_filter === 'en pause' ? 'selected' : '' ?>>Publication en pause ⏳</option>
                        <option value="abandonnée" <?= $status_filter === 'abandonnée' ? 'selected' : '' ?>>Publication abandonnée ⛔</option>
                        <option value="mature" <?= $status_filter === 'mature' ? 'selected' : '' ?>>Contenu mature 🔞</option>
                        <option value="non_mature" <?= $status_filter === 'non_mature' ? 'selected' : '' ?>>Contenu non mature 👐</option>
                        <option value="favorite" <?= $status_filter === 'favorite' ? 'selected' : '' ?>>Mes favoris ❤️</option>
                        <option value="reading_not_started" <?= $status_filter === 'reading_not_started' ? 'selected' : '' ?>>Lecture à débuter 📖</option>
                        <option value="reading_in_progress" <?= $status_filter === 'reading_in_progress' ? 'selected' : '' ?>>Lecture en cours 📘</option>
                        <option value="reading_completed" <?= $status_filter === 'reading_completed' ? 'selected' : '' ?>>Lecture terminée 📗</option>
                        <option value="reading_abandoned" <?= $status_filter === 'reading_abandoned' ? 'selected' : '' ?>>Lecture abandonnée 📕</option>
                        <option value="read_elsewhere" <?= $status_filter === 'read_elsewhere' ? 'selected' : '' ?>>Lues ailleurs 📚</option>
                    </select>
                </div>
                <button type="submit">Appliquer</button>

                <?php if ($options['hide_mature']): ?>
                    <p style="color: var(--status-mature);">🔞 Les séries matures sont masquées.</p>
                <?php endif; ?>
            </form>
        </div>

        <!-- Liste des séries -->
        <div class="series-list" id="series-list">
            <?php if (empty($data)): ?>
                <p>Aucune série trouvée.</p>
            <?php else: ?>
                <?php
                // Applique la pagination initiale (12 premières séries)
                $paginated_data = array_slice($data, 0, $per_page_public);
                foreach ($paginated_data as $series_index => $series):
                    $total_volumes = count($series['volumes'] ?? []);
                    $read_volumes = count(array_filter($series['volumes'] ?? [], fn($v) => $v['status'] === 'terminé'));
                ?>
                    <div class="series-card <?= isset($series['mature']) && $series['mature'] ? 'mature' : '' ?> <?= isset($series['favorite']) && $series['favorite'] ? 'favorite' : '' ?>" data-series-index="<?= $series_index ?>">
                        <img class="series-image" src="<?= !empty($series['image']) && file_exists($series['image']) ? $series['image'] : 'assets/img/logo.png' ?>" alt="<?= $series['name'] ?? '' ?>" loading="lazy">
                        <div class="series-info">
                            <h2><?= $series['name'] ?? '' ?></h2>
                            <p><strong>Auteur :</strong> <?= $series['author'] ?? '' ?></p>
                            <p><strong>Éditeur :</strong> <?= $series['publisher'] ?? '' ?></p>
                            <div class="series-stats">
                                <?php if (empty($series['read_elsewhere'])): ?>
                                    <?= $total_volumes ?> tome<?= $total_volumes > 1 ? 's' : '' ?> possédé<?= $total_volumes > 1 ? 's' : '' ?>
                                    (<?= $read_volumes ?> lu<?= $read_volumes > 1 ? 's' : '' ?>)
                                <?php else: ?>
                                    <?= $read_volumes ?> tome<?= $read_volumes > 1 ? 's' : '' ?> lu<?= $read_volumes > 1 ? 's' : '' ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="loading-spinner" id="loading-spinner">
            <p>Chargement en cours...</p>
        </div>

        <!-- Modale pour afficher les détails d'une série -->
        <div class="modal" id="series-detail-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-series-detail-modal">&times;</span>
                <h2 id="modal-series-title"></h2>
                <div id="modal-series-content" class="modal-scrollable-content">
                    <div class="modal-series-header">
                        <img id="modal-series-image" src="" alt="Image de la série" class="series-image">
                        <div class="modal-series-info">
                            <p><strong>Auteur :</strong> <span id="modal-series-author"></span></p>
                            <p><strong>Éditeur :</strong> <span id="modal-series-publisher"></span></p>
                            <p><strong>Autres contributeurs :</strong> <span id="modal-series-other-contributors"></span></p>
                            <p><strong>Catégories :</strong> <span id="modal-series-categories"></span></p>
                            <p><strong>Genres :</strong> <span id="modal-series-genres"></span></p>
                            <div class="series-stats" id="modal-series-stats"></div>
                            <div class="series-badges" id="modal-series-badges"></div>
                        </div>
                    </div>
                    <h3>Liste des tomes :</h3>
                    <ul class="volumes-list" id="modal-volumes-list"></ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Modale pour la légende -->
    <div class="modal" id="legend-modal">
        <div class="modal-content">
            <span class="close-modal" id="close-legend-modal">&times;</span>
            <h2>Légende du site</h2>
            <div class="legend-content">
                <div class="legend-item">
                    <img src="assets/img/logo.png" alt="Contenu mature" class="mature-thumbnail"><br>
                    <span>Vignette floutée : Contenu mature</span>
                </div><br>
                <div class="legend-item">
                    <div class="legend-sample status-terminé"></div>
                    <span>Tome bleu : Fini</span>
                </div><br>
                <div class="legend-item">
                    <div class="legend-sample status-en-cours"></div>
                    <span>Tome violet : En cours</span>
                </div><br>
                <div class="legend-item">
                    <div class="legend-sample status-à-lire"></div>
                    <span>Tome rose : À lire</span>
                </div><br>
                <div class="legend-item">
                    <div class="legend-icon">⭐</div>
                    <span>Étoile : Collector</span>
                </div><br>
                <div class="legend-item">
                    <div class="legend-icon last-icon">✅</div>
                    <span>Cochette verte : Dernier tome</span>
                </div><br>
                <div class="legend-item">
                    <div class="legend-sample favorite-border"></div>
                    <span>Contour doré : Série favorite</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Pied de page -->
    <footer class="footer">
        <?php
            $latest_version = get_latest_version_from_gitea();
            $current_version = SITE_VERSION;
            $version_class = '';
            $version_tooltip = '';
        ?>
        <container>
        <p class="hint <?= $version_class ?>" data-tooltip="<?= htmlspecialchars($version_tooltip) ?>">
            <?= htmlspecialchars($options['site_name']) ?> - Site en version <?= $current_version ?>.
            <a href="<?= URL_GITEA ?>" target="_blank">Accéder au dépôt Gitéa</a>.
        </p>
        </container>
    </footer>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <script>
        // Données des séries pour JavaScript
        let seriesData = <?= json_encode(array_values($data)) ?>;
    </script>
    <script src="assets/js/admin/main.js"></script>
    <script src="assets/js/public.js"></script>
</body>
</html>