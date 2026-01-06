<?php
require 'config.php';
$data = load_data();

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
    <title>Lengas - La mangathèque d'Esenjin !</title>
    <meta name="description" content="Lengas - Gestion de la collection de mangas d'Esenjin.">
    <link rel="icon" href="logo.png" type="image/png">
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
        <h1>Lengas - La mangathèque d'Esenjin</h1>

        <!-- Menu d'actions -->
        <div class="public-menu">
            <a href="https://esenjin.xyz" class="button" target="_blank">Blog d'Esenjin ↗</a>
            <a href="https://www.mangacollec.com/user/esenjin/collection" class="button" target="_blank">Profil Mangacollec ↗</a>
            <a href="stats.php" class="button" target="_blank">Statistiques Lengas ↗</a>
        </div>

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

        <!-- Liste des séries -->
        <div class="series-list">
            <?php if (empty($data)): ?>
                <p>Aucune série trouvée.</p>
            <?php else: ?>
                <?php foreach ($data as $series_index => $series): ?>
                    <?php
                    $total_volumes = count($series['volumes']);
                    $read_volumes = count(array_filter($series['volumes'], fn($v) => $v['status'] === 'terminé'));
                    ?>
                    <div class="series-card" data-series-index="<?= $series_index ?>">
                        <img class="series-image" src="<?= $series['image'] ?>" alt="<?= $series['name'] ?>">
                        <div class="series-info">
                            <h2><?= $series['name'] ?></h2>
                            <p><strong>Auteur :</strong> <?= $series['author'] ?></p>
                            <p><strong>Éditeur :</strong> <?= $series['publisher'] ?></p>
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

        // Gestion des cartes cliquables
        document.querySelectorAll('.series-card').forEach(card => {
            card.addEventListener('click', function() {
                const seriesIndex = this.dataset.seriesIndex;
                const series = seriesData[seriesIndex];

                // Remplir la modale avec les données de la série
                document.getElementById('modal-series-title').textContent = series.name;
                document.getElementById('modal-series-image').src = series.image;
                document.getElementById('modal-series-author').textContent = series.author;
                document.getElementById('modal-series-publisher').textContent = series.publisher;
                document.getElementById('modal-series-categories').textContent = series.categories ? series.categories.join(', ') : '';

                // Calculer les stats
                const totalVolumes = series.volumes.length;
                const readVolumes = series.volumes.filter(v => v.status === 'terminé').length;
                document.getElementById('modal-series-stats').innerHTML =
                    `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} ` +
                    `(${readVolumes} lu${readVolumes > 1 ? 's' : ''})`;

                // Remplir la liste des tomes
                const volumesList = document.getElementById('modal-volumes-list');
                volumesList.innerHTML = '';

                series.volumes.forEach(volume => {
                    const li = document.createElement('li');
                    li.className = `status-${volume.status.replace(' ', '-')} ${volume.collector ? 'volume-collector' : ''} ${volume.last ? 'volume-last' : ''}`;
                    li.textContent = volume.number;
                    volumesList.appendChild(li);
                });

                // Afficher la modale
                document.getElementById('series-detail-modal').classList.add('modal-active');
            });
        });

        // Fermeture de la modale
        document.getElementById('close-series-detail-modal').addEventListener('click', function() {
            document.getElementById('series-detail-modal').classList.remove('modal-active');
        });

        // Fermeture de la modale en cliquant à l'extérieur
        window.addEventListener('click', (e) => {
            const modal = document.getElementById('series-detail-modal');
            if (e.target === modal) {
                modal.classList.remove('modal-active');
            }
        });
    </script>
</body>
</html>