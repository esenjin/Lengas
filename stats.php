<?php
require 'config.php';
require 'fonctions/read.php';
$data = load_data();
$options = load_options();
$read = load_read();

$total_series = count($data);
$total_volumes = array_sum(array_map(function($series) {
    return count($series['volumes']);
}, $data));

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
        <link rel="icon" type="image/x-icon" href="favicon.ico">
        <link rel="stylesheet" href="assets/css/main.css">
    </head>
    <body>
        <div class="container">
            <h1><?= INDEX_PAGE_TITLE ?></h1>
            <p>Le site est en mode privé. Les statistiques ne sont pas accessibles au public.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Fonction pour convertir les minutes en format lisible
function convertMinutesToReadableTime($minutes) {
    $hours = floor($minutes / 60);
    $remainingMinutes = $minutes % 60;
    $days = floor($hours / 24);
    $remainingHours = $hours % 24;
    $parts = [];
    if ($days > 0) {
        $parts[] = "$days jour" . ($days > 1 ? "s" : "");
    }
    if ($remainingHours > 0) {
        $parts[] = "$remainingHours heure" . ($remainingHours > 1 ? "s" : "");
    }
    if ($remainingMinutes > 0) {
        $parts[] = "$remainingMinutes minute" . ($remainingMinutes > 1 ? "s" : "");
    }
    return implode(' et ', $parts);
}

$status_counts = [
    'à lire' => 0,
    'en cours' => 0,
    'terminé' => 0
];

$collector_counts = 0;
$completed_series = 0;
$complete_series = 0;

// Initialiser des tableaux pour suivre les auteurs et éditeurs uniques
$unique_authors = [];
$unique_publishers = [];

foreach ($data as $series) {
    $has_last_volume = false;
    $last_volume_completed = false;

    // Ajouter l'auteur et l'éditeur aux tableaux s'ils ne sont pas déjà présents
    $author = $series['author'];
    $publisher = $series['publisher'];

    if (!in_array($author, $unique_authors)) {
        $unique_authors[] = $author;
    }

    if (!in_array($publisher, $unique_publishers)) {
        $unique_publishers[] = $publisher;
    }

    foreach ($series['volumes'] as $volume) {
        $status_counts[$volume['status']]++;
        if (!empty($volume['collector'])) $collector_counts++;

        if (!empty($volume['last'])) {
            $has_last_volume = true;
            if ($volume['status'] === 'terminé') {
                $last_volume_completed = true;
            }
        }
    }

    if ($has_last_volume) {
        $complete_series++;
    }

    if ($has_last_volume && $last_volume_completed) {
        $completed_series++;
    }
}

// Calcul des pourcentages
$percentages = [
    'à lire' => $total_volumes > 0 ? round(($status_counts['à lire'] / $total_volumes) * 100) : 0,
    'en cours' => $total_volumes > 0 ? round(($status_counts['en cours'] / $total_volumes) * 100) : 0,
    'terminé' => $total_volumes > 0 ? round(($status_counts['terminé'] / $total_volumes) * 100) : 0
];

// Calculer le nombre total d'auteurs et d'éditeurs uniques
$total_unique_authors = count($unique_authors);
$total_unique_publishers = count($unique_publishers);

// Calculer le temps de lecture pour chaque statut
$reading_time_by_status = [
    'à lire' => 0,
    'en cours' => 0,
    'terminé' => 0
];

foreach ($data as $series) {
    foreach ($series['volumes'] as $volume) {
        $reading_time_by_status[$volume['status']] += 40; // 40 minutes par tome
    }
}

// Convertir les temps de lecture en format lisible
$reading_time_by_status_readable = [
    'à lire' => convertMinutesToReadableTime($reading_time_by_status['à lire']),
    'en cours' => convertMinutesToReadableTime($reading_time_by_status['en cours']),
    'terminé' => convertMinutesToReadableTime($reading_time_by_status['terminé'])
];

// Calcul du temps de lecture total
$total_reading_time_minutes = $total_volumes * 40; // 40 minutes par tome
$total_reading_time = convertMinutesToReadableTime($total_reading_time_minutes);

// Données pour le graphique
$chart_labels = ['À lire', 'En cours', 'Terminé'];
$chart_values = [$status_counts['à lire'], $status_counts['en cours'], $status_counts['terminé']];

// Calculer les statistiques pour les séries "lues ailleurs"
$total_read_series = count($read);
$total_read_volumes = array_sum(array_map(function($item) {
    return $item['volumes_read'];
}, $read));

// Calculer le temps de lecture pour les séries "lues ailleurs"
$total_read_reading_time_minutes = $total_read_volumes * 40;
$total_read_reading_time = convertMinutesToReadableTime($total_read_reading_time_minutes);

// Préparer les données pour la recherche dynamique
$search_data = [];
foreach ($data as $series) {
    $search_data[] = [
        'name' => $series['name'],
        'author' => $series['author'],
        'publisher' => $series['publisher'],
        'categories' => $series['categories'] ?? [],
        'genres' => $series['genres'] ?? [],
        'other_contributors' => $series['other_contributors'] ?? [],
        'volumes_count' => count($series['volumes']),
    ];
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
    <title><?= htmlspecialchars($options['index_page_title']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($options['site_description']) ?>">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        .container {
            width: 100%;
            padding: 20px;
            box-sizing: border-box;
        }

        .stats-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #1e1e1e;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-item {
            padding: 10px;
            background-color: #2d2d2d;
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-value {
            font-weight: bold;
            color: #bb86fc;
        }

        h1, h2 {
            text-align: center;
            color: white;
        }

        h2 {
            margin-top: 20px;
        }

        .chart-container {
            width: 100%;
            height: 300px;
            margin-top: 20px;
        }

        .reading-time-container {
            width: 100%;
            margin-top: 20px;
        }

        .reading-time-item {
            text-align: center;
            padding: 10px;
            background-color: #2d2d2d;
            border-radius: 5px;
            margin: 5px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .footer {
            justify-content: center;
            display: flex;
            padding-top: 20px;
        }

        .search-container {
            width: 100%;
            box-sizing: border-box;
        }

        #dynamic-search-form input {
            background-color: #2d2d2d;
            color: white;
        }

        #dynamic-search-form input::placeholder {
            color: #aaa;
        }

        #search-results {
            background-color: #2d2d2d;
            padding: 15px;
            border-radius: 5px;
            display: none;
        }

        #search-results.show {
            display: block;
        }

        .result-item {
            padding: 10px;
            border-bottom: 1px solid #444;
        }

        .result-item:last-child {
            border-bottom: none;
        }

        .result-link {
            color: #bb86fc;
            text-decoration: none;
        }

        .result-link:hover {
            text-decoration: underline;
        }

        /* Style pour les suggestions d'autocomplétion */
        .autocomplete-suggestions {
            background-color: #2d2d2d;
            border: 1px solid #444;
            border-radius: 5px;
            max-height: 200px;
            overflow-y: auto;
            display: none;
            margin-top: 2px;
        }

        .autocomplete-suggestions.show {
            display: block;
        }

        .autocomplete-suggestions div {
            padding: 8px 12px;
            cursor: pointer;
            color: white;
        }

        .autocomplete-suggestions div:hover {
            background-color: #bb86fc;
            color: white;
        }

        /* Style pour le bouton de recherche */
        #search-button {
            margin-top: 10px;
        }

        /* Responsive pour mobile */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-item {
                text-align: center;
                padding: 12px;
            }

            .stat-value {
                margin-top: 5px;
                font-size: 1.1em;
            }

            .reading-time-item {
                flex-direction: column;
                text-align: center;
            }

            /* Empiler le graphique et le temps de lecture */
            .stats-container > div:last-child {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><?= htmlspecialchars($options['index_page_title']) ?></h1>
        <div class="stats-container">
            <div class="stats-grid">
                <div class="stat-item">
                    <span>Nombre total de séries :</span>
                    <span class="stat-value"><?= $total_series ?></span>
                </div>
                <div class="stat-item">
                    <span>Nombre total de tomes :</span>
                    <span class="stat-value"><?= $total_volumes ?></span>
                </div>
                <div class="stat-item">
                    <span>Tomes à lire :</span>
                    <span class="stat-value"><?= $status_counts['à lire'] ?> (<?= $percentages['à lire'] ?>%)</span>
                </div>
                <div class="stat-item">
                    <span>Tomes en cours :</span>
                    <span class="stat-value"><?= $status_counts['en cours'] ?> (<?= $percentages['en cours'] ?>%)</span>
                </div>
                <div class="stat-item">
                    <span>Tomes terminés :</span>
                    <span class="stat-value"><?= $status_counts['terminé'] ?> (<?= $percentages['terminé'] ?>%)</span>
                </div>
                <div class="stat-item">
                    <span>Séries complètes :</span>
                    <span class="stat-value"><?= $complete_series ?></span>
                </div>
                <div class="stat-item">
                    <span>Tomes collectors :</span>
                    <span class="stat-value"><?= $collector_counts ?></span>
                </div>
                <div class="stat-item">
                    <span>Séries terminées :</span>
                    <span class="stat-value"><?= $completed_series ?></span>
                </div>
                <div class="stat-item">
                    <span>Nombre total d'auteurs :</span>
                    <span class="stat-value"><?= $total_unique_authors ?></span>
                </div>
                <div class="stat-item">
                    <span>Nombre total d'éditeurs :</span>
                    <span class="stat-value"><?= $total_unique_publishers ?></span>
                </div>
                <div class="stat-item">
                    <span>Séries lues non possédées :</span>
                    <span class="stat-value" style="color: #03dac6;"><?= $total_read_series ?></span>
                </div>
                <div class="stat-item">
                    <span>Tomes lus non possédés :</span>
                    <span class="stat-value" style="color: #03dac6;"><?= $total_read_volumes ?></span>
                </div>
            </div>

            <h2>Répartition par statut</h2>
            <div style="display: flex; justify-content: space-between;">
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
                <div class="reading-time-container">
                    <div class="reading-time-item">
                        <span>Temps de lecture total :</span>
                        <span class="stat-value"><?= $total_reading_time ?></span>
                    </div>
                    <div class="reading-time-item">
                        <span>Temps de lecture à lire :</span>
                        <span class="stat-value"><?= $reading_time_by_status_readable['à lire'] ?></span>
                    </div>
                    <div class="reading-time-item">
                        <span>Temps de lecture en cours :</span>
                        <span class="stat-value"><?= $reading_time_by_status_readable['en cours'] ?></span>
                    </div>
                    <div class="reading-time-item">
                        <span>Temps de lecture terminé :</span>
                        <span class="stat-value"><?= $reading_time_by_status_readable['terminé'] ?></span>
                    </div>
                    <div class="reading-time-item">
                        <span>Temps de lecture des non possédées :</span>
                        <span class="stat-value" style="color: #03dac6;"><?= $total_read_reading_time ?></span>
                    </div>
                    <p><i>* Pour un temps moyen de 40 min par tome.</i></p>
                </div>
            </div>
        </div>
        <!-- Recherche dynamique -->
        <div class="search-container" style="max-width: 800px; margin: 30px auto; background-color: #1e1e1e; padding: 20px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);">
            <h2 style="text-align: center; color: white; margin-bottom: 20px;">Statistiques avancées</h2>
            <div style="position: relative; width: 100%; margin-bottom: 20px;">
                <input type="text"
                    id="search-input"
                    placeholder="Rechercher : Série, auteur, éditeur, catégorie, genre, contributeur..."
                    style="width: 100%; padding: 10px; border-radius: 5px; border: none; background-color: #2d2d2d; color: white;"
                    autocomplete="off">
                <div id="search-suggestions" class="autocomplete-suggestions" style="top: 100%; left: 0; right: 0; z-index: 1000;"></div>
            </div>
            <button id="search-button" style="padding: 10px 20px; background-color: #bb86fc; color: white; border: none; border-radius: 5px; cursor: pointer; display: block; margin: 0 auto;">Rechercher</button>
            <div id="search-results" style="margin-top: 20px; color: white; display: none;"></div>
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

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Définir les données nécessaires pour le graphique
        var chartLabels = <?php echo json_encode($chart_labels); ?>;
        var chartValues = <?php echo json_encode($chart_values); ?>;
        var totalVolumes = <?= $total_volumes ?>;
    </script>
    <script src="assets/js/stats.js"></script>
    <script>
        // Exposer les données PHP en JavaScript
        const searchData = <?php echo json_encode($search_data); ?>;
        const allAuthors = <?php echo json_encode($unique_authors); ?>;
        const allPublishers = <?php echo json_encode($unique_publishers); ?>;
        const allCategories = <?php
            $categories = [];
            foreach ($data as $series) {
                if (!empty($series['categories'])) {
                    $categories = array_merge($categories, $series['categories']);
                }
            }
            echo json_encode(array_unique($categories));
        ?>;
        const allGenres = <?php
            $genres = [];
            foreach ($data as $series) {
                if (!empty($series['genres'])) {
                    $genres = array_merge($genres, $series['genres']);
                }
            }
            echo json_encode(array_unique($genres));
        ?>;
        const allContributors = <?php
            $contributors = [];
            foreach ($data as $series) {
                if (!empty($series['other_contributors'])) {
                    $contributors = array_merge($contributors, $series['other_contributors']);
                }
            }
            echo json_encode(array_unique($contributors));
        ?>;
    </script>
    <script>
        // Version ultra-simple pour éviter toute erreur
        const allSuggestions = [
            <?php
                $suggestions = [];
                foreach ($data as $series) {
                    if (!empty($series['name'])) $suggestions[] = '"' . addslashes($series['name']) . '"';
                    if (!empty($series['author'])) $suggestions[] = '"' . addslashes($series['author']) . '"';
                    if (!empty($series['publisher'])) $suggestions[] = '"' . addslashes($series['publisher']) . '"';
                    if (!empty($series['categories'])) {
                        foreach ($series['categories'] as $cat) {
                            $suggestions[] = '"' . addslashes($cat) . '"';
                        }
                    }
                    if (!empty($series['genres'])) {
                        foreach ($series['genres'] as $genre) {
                            $suggestions[] = '"' . addslashes($genre) . '"';
                        }
                    }
                    if (!empty($series['other_contributors'])) {
                        foreach ($series['other_contributors'] as $contrib) {
                            $suggestions[] = '"' . addslashes($contrib) . '"';
                        }
                    }
                }
                echo implode(',', array_unique($suggestions));
            ?>
        ];

        document.addEventListener('DOMContentLoaded', function() {
            const datalist = document.getElementById('search-suggestions');
            allSuggestions.forEach(suggestion => {
                const option = document.createElement('option');
                option.value = suggestion;
                datalist.appendChild(option);
            });
        });
    </script>
</body>
</html>