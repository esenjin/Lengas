<?php
require 'config.php';
$data = load_data();

$total_series = count($data);
$total_volumes = array_sum(array_map(function($series) {
    return count($series['volumes']);
}, $data));

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
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= STATS_PAGE_TITLE ?></title>
    <meta name="description" content="<?= SITE_DESCRIPTION ?>">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="styles.css">
    <style>
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
        }

        .chart-container {
            margin-top: 20px;
            height: 300px;
        }

        .stat-value {
            font-weight: bold;
            color: #bb86fc;
        }

        .reading-time-container {
            display: flex;
            flex-direction: column;
            margin-top: 20px;
        }

        .reading-time-item {
            text-align: center;
            padding: 10px;
            background-color: #2d2d2d;
            border-radius: 5px;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><?= STATS_PAGE_TITLE ?></h1>
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
                    <p><i>* Pour un temps moyen de 40 min par tome.</i></p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Définir les données nécessaires pour le graphique
        var chartLabels = <?php echo json_encode($chart_labels); ?>;
        var chartValues = <?php echo json_encode($chart_values); ?>;
        var totalVolumes = <?= $total_volumes ?>;
    </script>
    <script src="scripts/stats.js"></script>
</body>
</html>