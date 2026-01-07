<?php
require 'config.php';
$data = load_data();

$total_series = count($data);
$total_volumes = array_sum(array_map(function($series) {
    return count($series['volumes']);
}, $data));

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

// Données pour le graphique
$chart_labels = ['À lire', 'En cours', 'Terminé'];
$chart_values = [$status_counts['à lire'], $status_counts['en cours'], $status_counts['terminé']];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Statistiques de Lengas</title>
    <meta name="description" content="Lengas - Gestion de la collection de mangas d'Esenjin.">
    <link rel="icon" href="logo.png" type="image/png">
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Statistiques de Lengas</h1>
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
            <div class="chart-container">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('statusChart').getContext('2d');

            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($chart_values); ?>,
                        backgroundColor: [
                            'rgba(207, 102, 121, 0.4)',
                            'rgba(187, 134, 252, 0.4)',
                            'rgba(3, 218, 198, 0.4)'
                        ],
                        borderColor: [
                            '#cf6679',
                            '#bb86fc',
                            '#03dac6'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = <?= $total_volumes ?>;
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} tomes (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>