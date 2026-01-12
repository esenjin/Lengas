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
        <link rel="icon" type="image/x-icon" href="favicon.ico">
        <link rel="stylesheet" href="styles.css">
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

// Filtrer les séries matures si l'option est activée
if ($options['hide_mature']) {
    $data = array_filter($data, function($series) {
        return !($series['mature'] ?? false);
    });
}

// Gestion du tri, filtre et recherche
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'asc';
$search_term = $_GET['search'] ?? '';

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
    <title><?= htmlspecialchars($options['index_page_title']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($options['site_description']) ?>">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="styles.css">
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
    </style>
</head>
<body>
    <div class="container">
        <h1><?= htmlspecialchars($options['index_page_title']) ?></h1>

        <!-- Menu d'actions -->
        <div class="public-menu">
            <?php if (!empty($options['custom_button_name']) && !empty($options['custom_button_url'])): ?>
                <a href="<?= htmlspecialchars($options['custom_button_url']) ?>" class="button" target="_blank"><?= htmlspecialchars($options['custom_button_name']) ?> ↗</a>
            <?php endif; ?>
            <a href="stats.php" class="button" target="_blank">Statistiques Lengas ↗</a>
        </div>

        <!-- Barre de filtres et recherche -->
        <div class="filters">
            <form method="get">
                <input type="text" name="search" placeholder="Rechercher une série, un auteur ou un éditeur..."
                       value="<?= htmlspecialchars($search_term ?? '') ?>">
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

        <!-- Liste des séries -->
        <div class="series-list">
            <?php if (empty($data)): ?>
                <p>Aucune série trouvée.</p>
            <?php else: ?>
                <?php foreach ($data as $series_index => $series): ?>
                <?php
                $total_volumes = count($series['volumes'] ?? []);
                $read_volumes = count(array_filter($series['volumes'] ?? [], fn($v) => $v['status'] === 'terminé'));
                ?>
                <div class="series-card <?= isset($series['mature']) && $series['mature'] ? 'mature' : '' ?>" data-series-index="<?= $series_index ?>">
                    <img class="series-image" src="<?= $series['image'] ?? '' ?>" alt="<?= $series['name'] ?? '' ?>">
                    <?php if (isset($series['mature']) && $series['mature']): ?>
                    <?php endif; ?>
                    <div class="series-info">
                        <h2><?= $series['name'] ?? '' ?></h2>
                        <p><strong>Auteur :</strong> <?= $series['author'] ?? '' ?></p>
                        <p><strong>Éditeur :</strong> <?= $series['publisher'] ?? '' ?></p>
                        <div class="series-stats">
                            <?= $total_volumes ?> tome<?= $total_volumes > 1 ? 's' : '' ?> possédé<?= $total_volumes > 1 ? 's' : '' ?>
                            (<?= $read_volumes ?> lu<?= $read_volumes > 1 ? 's' : '' ?>)
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
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
                            <p><strong>Catégories :</strong> <span id="modal-series-categories"></span></p>
                            <p><strong>Genres :</strong> <span id="modal-series-genres"></span></p>
                            <div class="series-stats" id="modal-series-stats"></div>
                        </div>
                    </div>
                    <h3>Liste des tomes :</h3>
                    <ul class="volumes-list" id="modal-volumes-list"></ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Données des séries pour JavaScript
        const seriesData = <?= json_encode($data) ?>;
    </script>
    <script src="scripts/public.js"></script>
</body>
</html>