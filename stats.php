<?php
require 'config.php';
require 'fonctions/stats_compute.php';

$data    = load_data();
$options = load_options();

// Fonction pour récupérer la dernière version depuis Gitea
function get_latest_version_from_gitea() {
    $url = "https://git.crystalyx.net/api/v1/repos/Esenjin_Asakha/Lengas/releases/latest";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Lengas-Version-Checker");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    curl_close($ch);
    if ($response) {
        $decoded = json_decode($response, true);
        if (isset($decoded['tag_name'])) {
            return ltrim($decoded['tag_name'], 'v');
        }
    }
    return null;
}

// Le titre de la page stats utilise bien stats_page_title (bug corrigé)
$page_title = $options['stats_page_title'] ?? ($options['site_name'] ?? 'Statistiques');

// ── Mode privé : page minimale ───────────────────────────────────────────────
if (!empty($options['private_mode'])) {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($page_title) ?></title>
        <meta name="description" content="<?= htmlspecialchars($options['site_description'] ?? '') ?>">
        <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
        <link rel="stylesheet" href="assets/css/main.css">
    </head>
    <body>
        <div class="container">
            <h1><?= htmlspecialchars($page_title) ?></h1>
            <p style="text-align:center;">Le site est en mode privé. Les statistiques ne sont pas accessibles au public.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── Calcul de toutes les statistiques ────────────────────────────────────────
$stats        = compute_stats($data, $options);
$hide_mature  = !empty($options['hide_mature']);

// Données de recherche (réintégrées dans le nouveau design)
$search_data = [];
foreach ($data as $series) {
    $search_data[] = [
        'name'               => $series['name'],
        'author'             => $series['author'],
        'publisher'          => $series['publisher'],
        'categories'         => stats_clean_list($series['categories'] ?? []),
        'genres'             => stats_clean_list($series['genres'] ?? []),
        'other_contributors' => stats_clean_list($series['other_contributors'] ?? []),
        'volumes_count'      => count($series['volumes']),
    ];
}

// Payload JSON pour le front (graphiques)
$chart_payload = [
    'status' => [
        'labels' => ['Lus', 'En cours', 'À lire'],
        'values' => [
            $stats['status_counts']['terminé']  ?? 0,
            $stats['status_counts']['en cours'] ?? 0,
            $stats['status_counts']['à lire']   ?? 0,
        ],
        'elsewhere' => $stats['elsewhere_volumes'],
    ],
    'time' => [
        'labels' => ['Déjà lu', 'En cours', 'À lire', 'Non possédé'],
        'values' => [
            round($stats['time_by_status']['terminé']  ?? 0),
            round($stats['time_by_status']['en cours'] ?? 0),
            round($stats['time_by_status']['à lire']   ?? 0),
            round($stats['elsewhere_minutes']),
        ],
    ],
    'authors'      => array_map(fn($a) => ['x' => $a['name'], 'y' => $a['volumes'], 'series' => $a['series']], $stats['authors']),
    'publishers'   => array_map(fn($p) => ['x' => $p['name'], 'y' => $p['volumes'], 'series' => $p['series']], $stats['publishers']),
    'genres'       => array_map(fn($g) => ['name' => $g['name'], 'volumes' => $g['volumes']], $stats['genres']),
    'genres_none'  => $stats['genres_none'],
    'categories'   => array_map(fn($c) => ['name' => $c['name'], 'series' => $c['series'], 'volumes' => $c['volumes']], $stats['categories']),
    'contributors' => array_map(fn($c) => ['name' => $c['name'], 'series' => $c['series'], 'volumes' => $c['volumes']], $stats['contributors']),
    'value' => [
        'labels' => ['Tomes normaux', 'Tomes collectors'],
        'values' => [round($stats['value_normal'], 2), round($stats['value_collector'], 2)],
    ],
    'purchases' => $stats['purchases_by_month'],
    'growth'    => $stats['growth'],
    'completion' => [
        'labels' => ['Terminées', 'En cours', 'En pause', 'Abandonnées'],
        'values' => [
            $stats['completed_series'],
            max(0, $stats['total_series'] - $stats['completed_series'] - $stats['paused_series'] - $stats['abandoned_series']),
            $stats['paused_series'],
            $stats['abandoned_series'],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($options['site_description'] ?? '') ?>">
    <meta property="og:image" content="assets/img/logo.png">
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body class="stats-page">
    <div class="container">
        <header class="stats-header">
            <h1><?= htmlspecialchars($page_title) ?></h1>
            <button class="mobile-menu-button" id="mobile-menu-button">☰ Menu</button>
            <div class="public-menu" id="public-menu">
                <?php for ($i = 1; $i <= 3; $i++):
                    $suffix = $i === 1 ? '' : $i;
                    $name = $options["custom_button_name$suffix"] ?? '';
                    $url  = $options["custom_button_url$suffix"]  ?? '';
                    if (!empty($name) && !empty($url)): ?>
                        <a href="<?= htmlspecialchars($url) ?>" class="button button-otl" target="_blank"><?= htmlspecialchars($name) ?> ↗</a>
                    <?php endif;
                endfor; ?>
                <a href="index.php" class="button">Accueil ↗</a>
            </div>
        </header>

        <!-- ══ 1. VUE D'ENSEMBLE ══════════════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Vue d'ensemble</div>
            <div class="kpi-grid">
                <?php
                $kpis = [
                    ['Séries',        $stats['total_series'],      'mdi:bookshelf'],
                    ['Tomes',         $stats['total_volumes'],     'mdi:book-multiple'],
                    ['Auteurs',       $stats['total_authors'],     'mdi:fountain-pen-tip'],
                    ['Éditeurs',      $stats['total_publishers'],  'mdi:domain'],
                    ['Genres',        $stats['total_genres'],      'mdi:tag-multiple'],
                    ['Catégories',    $stats['total_categories'],  'mdi:shape'],
                    ['Contributeurs', $stats['total_contributors'],'mdi:account-group'],
                    ['Collectors',    $stats['collector_count'],   'mdi:star-circle'],
                ];
                foreach ($kpis as [$label, $val, $icon]): ?>
                    <div class="kpi-card">
                        <img class="kpi-icon" src="https://api.iconify.design/<?= $icon ?>.svg?color=%23c084fc" width="22" height="22" alt="">
                        <div class="kpi-value"><?= number_format($val, 0, ',', ' ') ?></div>
                        <div class="kpi-label"><?= $label ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$hide_mature): ?>
                    <div class="kpi-card">
                        <img class="kpi-icon" src="https://api.iconify.design/mdi/alert-octagon.svg?color=%23e879c6" width="22" height="22" alt="">
                        <div class="kpi-value"><?= $stats['mature_series'] ?></div>
                        <div class="kpi-label">Séries matures</div>
                    </div>
                <?php endif; ?>
                <div class="kpi-card kpi-accent">
                    <img class="kpi-icon" src="https://api.iconify.design/mdi/book-open-page-variant.svg?color=%2334d399" width="22" height="22" alt="">
                    <div class="kpi-value"><?= $stats['elsewhere_series'] ?></div>
                    <div class="kpi-label">Séries lues ailleurs</div>
                </div>
            </div>
        </section>

        <!-- ══ 2. LECTURE & PROGRESSION ═══════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Lecture &amp; progression</div>
            <div class="panel-row">
                <div class="panel panel-chart">
                    <h3>Répartition des tomes</h3>
                    <canvas id="chart-status"></canvas>
                </div>
                <div class="panel panel-stats">
                    <div class="big-metric">
                        <span class="big-metric-value"><?= $stats['completion_pct'] ?>%</span>
                        <span class="big-metric-label">de complétion globale</span>
                    </div>
                    <div class="mini-stat"><span>Tomes lus</span><b><?= $stats['status_counts']['terminé'] ?? 0 ?> (<?= $stats['status_pct']['terminé'] ?>%)</b></div>
                    <div class="mini-stat"><span>Tomes en cours</span><b><?= $stats['status_counts']['en cours'] ?? 0 ?> (<?= $stats['status_pct']['en cours'] ?>%)</b></div>
                    <div class="mini-stat"><span>Tomes à lire</span><b><?= $stats['status_counts']['à lire'] ?? 0 ?> (<?= $stats['status_pct']['à lire'] ?>%)</b></div>
                    <div class="mini-stat"><span>Séries possédées entièrement</span><b><?= $stats['complete_series'] ?></b></div>
                    <div class="mini-stat"><span>Séries complètement lues</span><b><?= $stats['completed_series'] ?> (<?= $stats['series_done_pct'] ?>%)</b></div>
                    <div class="mini-stat"><span>Séries commencées non terminées</span><b><?= $stats['started_not_done'] ?></b></div>
                </div>
            </div>
        </section>

        <!-- ══ 3. TEMPS DE LECTURE ════════════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Temps de lecture</div>
            <div class="panel-row">
                <div class="panel panel-chart">
                    <h3>Répartition du temps</h3>
                    <canvas id="chart-time"></canvas>
                </div>
                <div class="panel panel-stats">
                    <div class="big-metric">
                        <span class="big-metric-value"><?= stats_format_minutes($stats['time_total']) ?></span>
                        <span class="big-metric-label">de lecture pour toute la collection</span>
                    </div>
                    <div class="mini-stat"><span>Déjà lu</span><b><?= stats_format_minutes($stats['time_by_status']['terminé'] ?? 0) ?></b></div>
                    <div class="mini-stat"><span>En cours</span><b><?= stats_format_minutes($stats['time_by_status']['en cours'] ?? 0) ?></b></div>
                    <div class="mini-stat"><span>À lire</span><b><?= stats_format_minutes($stats['time_by_status']['à lire'] ?? 0) ?></b></div>
                    <div class="mini-stat"><span>Non possédé (lu ailleurs)</span><b><?= stats_format_minutes($stats['elsewhere_minutes']) ?></b></div>
                    <p class="panel-note">Durées estimées d'après les temps moyens définis par catégorie dans les options.</p>
                </div>
            </div>
        </section>

        <!-- ══ 4. AUTEURS ═════════════════════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Auteurs</div>
            <div class="kpi-strip">
                <div class="kpi-inline"><b><?= $stats['total_authors'] ?></b> auteurs</div>
                <div class="kpi-inline"><b><?= $stats['avg_series_per_author'] ?></b> séries / auteur</div>
                <div class="kpi-inline"><b><?= $stats['avg_volumes_per_author'] ?></b> tomes / auteur</div>
            </div>
            <div class="panel">
                <div class="panel-head">
                    <h3>Cartographie des auteurs</h3>
                    <div class="toggle-group" data-target="authors">
                        <button class="toggle-btn is-active" data-metric="volumes">Par tomes</button>
                        <button class="toggle-btn" data-metric="series">Par séries</button>
                    </div>
                </div>
                <div id="treemap-authors" class="apex-chart"></div>
            </div>
            <div class="panel">
                <div class="panel-head">
                    <h3>Top 10 auteurs</h3>
                </div>
                <div id="bar-authors" class="apex-chart"></div>
            </div>
        </section>

        <!-- ══ 5. ÉDITEURS ════════════════════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Éditeurs</div>
            <div class="kpi-strip">
                <div class="kpi-inline"><b><?= $stats['total_publishers'] ?></b> éditeurs</div>
                <div class="kpi-inline"><b><?= $stats['avg_series_per_publisher'] ?></b> séries / éditeur</div>
            </div>
            <div class="panel">
                <div class="panel-head">
                    <h3>Cartographie des éditeurs</h3>
                    <div class="toggle-group" data-target="publishers">
                        <button class="toggle-btn is-active" data-metric="volumes">Par tomes</button>
                        <button class="toggle-btn" data-metric="series">Par séries</button>
                    </div>
                </div>
                <div id="treemap-publishers" class="apex-chart"></div>
            </div>
            <div class="panel">
                <div class="panel-head"><h3>Top 10 éditeurs</h3></div>
                <div id="bar-publishers" class="apex-chart"></div>
            </div>
        </section>

        <!-- ══ 6. GENRES & CATÉGORIES ═════════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Genres &amp; catégories</div>
            <div class="panel-row">
                <div class="panel">
                    <div class="panel-head"><h3>Genres</h3></div>
                    <div id="genres-chart" class="apex-chart"></div>
                </div>
                <div class="panel">
                    <div class="panel-head"><h3>Catégories</h3></div>
                    <div id="categories-chart" class="apex-chart"></div>
                </div>
            </div>
        </section>

        <!-- ══ 7. CONTRIBUTEURS ═══════════════════════════════════════════ -->
        <?php if (count($stats['contributors']) > 0): ?>
        <section class="stats-section">
            <div class="section-eyebrow">Contributeurs</div>
            <div class="kpi-strip">
                <div class="kpi-inline"><b><?= $stats['total_contributors'] ?></b> contributeurs</div>
            </div>
            <div class="panel">
                <div class="panel-head">
                    <h3>Top contributeurs</h3>
                    <div class="toggle-group" data-target="contributors-view">
                        <button class="toggle-btn is-active" data-view="10">Top 10</button>
                        <button class="toggle-btn" data-view="all">Tous</button>
                    </div>
                </div>
                <div id="bar-contributors" class="apex-chart"></div>
            </div>
        </section>
        <?php endif; ?>

        <!-- ══ 8. VALEUR DE LA COLLECTION ═════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Valeur de la collection</div>
            <div class="kpi-grid kpi-grid-value">
                <div class="kpi-card kpi-accent-warm">
                    <div class="kpi-value"><?= stats_format_value($stats['value_total']) ?></div>
                    <div class="kpi-label">Valeur totale estimée</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value"><?= stats_format_value($stats['value_normal']) ?></div>
                    <div class="kpi-label">Tomes normaux</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value"><?= stats_format_value($stats['value_collector']) ?></div>
                    <div class="kpi-label">Tomes collectors</div>
                </div>
            </div>
            <div class="panel">
                <div class="panel-head"><h3>Répartition de la valeur</h3></div>
                <div id="value-chart" class="apex-chart"></div>
            </div>
            <p class="panel-note">Valeurs estimées d'après les prix moyens par catégorie (normaux / collectors) définis dans les options.</p>
        </section>

        <!-- ══ 9. ÉVOLUTION DANS LE TEMPS ═════════════════════════════════ -->
        <?php if (count($stats['growth']) > 1): ?>
        <section class="stats-section">
            <div class="section-eyebrow">Évolution dans le temps</div>
            <div class="panel">
                <div class="panel-head"><h3>Tomes ajoutés par mois</h3></div>
                <div id="line-purchases" class="apex-chart"></div>
            </div>
            <div class="panel">
                <div class="panel-head"><h3>Croissance de la collection</h3></div>
                <div id="line-growth" class="apex-chart"></div>
            </div>
        </section>
        <?php endif; ?>

        <!-- ══ 10. COMPLÉTUDE DES SÉRIES ══════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Complétude des séries</div>
            <div class="panel-row">
                <div class="panel panel-chart">
                    <h3>État des séries</h3>
                    <canvas id="chart-completion"></canvas>
                </div>
                <div class="panel panel-stats">
                    <div class="mini-stat"><span>Terminées (entièrement lues)</span><b><?= $stats['completed_series'] ?></b></div>
                    <div class="mini-stat"><span>En cours</span><b><?= max(0, $stats['total_series'] - $stats['completed_series'] - $stats['paused_series'] - $stats['abandoned_series']) ?></b></div>
                    <div class="mini-stat"><span>En pause (éditeur)</span><b><?= $stats['paused_series'] ?></b></div>
                    <div class="mini-stat"><span>Abandonnées (éditeur)</span><b><?= $stats['abandoned_series'] ?></b></div>
                </div>
            </div>
        </section>

        <!-- ══ 11. FUN FACTS ══════════════════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Fun facts</div>
            <?php
            $plural = fn($n, $word) => $n . ' ' . $word . ($n > 1 ? 's' : '');
            // Interprétation de l'évenness de Shannon (0 = un auteur domine, 1 = parfaitement réparti)
            $even = $stats['shannon_even'];
            if ($even === null)      $shannon_word = '';
            elseif ($even >= 0.85)   $shannon_word = 'collection très variée';
            elseif ($even >= 0.6)    $shannon_word = 'bonne diversité';
            elseif ($even >= 0.35)   $shannon_word = 'diversité modérée';
            else                     $shannon_word = 'quelques auteurs dominent';
            ?>
            <div class="funfact-grid">
                <div class="funfact">
                    <span class="funfact-label">Auteur le plus représenté</span>
                    <span class="funfact-value"><?= htmlspecialchars($stats['top_author'] ?? '—') ?><?php if ($stats['top_author']): ?> <em>(<?= $plural($stats['top_author_volumes'], 'tome') ?>, <?= $plural($stats['top_author_series'], 'série') ?>)</em><?php endif; ?></span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Série la plus longue</span>
                    <span class="funfact-value"><?= htmlspecialchars($stats['longest_series']['name'] ?? '—') ?><?php if ($stats['longest_series']['name']): ?> <em>(<?= $plural($stats['longest_series']['volumes'], 'tome') ?>)</em><?php endif; ?></span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Éditeur dominant</span>
                    <span class="funfact-value"><?= htmlspecialchars($stats['top_publisher'] ?? '—') ?><?php if ($stats['top_publisher']): ?> <em>(<?= $plural($stats['top_publisher_volumes'], 'tome') ?>, <?= $plural($stats['top_publisher_series'], 'série') ?>)</em><?php endif; ?></span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Part du top 10 auteurs</span>
                    <span class="funfact-value"><?= $stats['top10_authors_pct'] ?>% <em>(<?= $plural($stats['top10_authors_vol'], 'tome') ?> sur <?= $stats['total_volumes'] ?>)</em></span>
                    <span class="funfact-note">Poids des <?= $plural($stats['top10_authors_n'], 'auteur') ?> les plus présents dans la collection.</span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Séries commencées non finies</span>
                    <span class="funfact-value"><?= $stats['started_not_done'] ?></span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Indice de diversité (Shannon)</span>
                    <span class="funfact-value"><?= $stats['shannon'] ?><?php if ($even !== null): ?> <em>(<?= $shannon_word ?>)</em><?php endif; ?></span>
                    <span class="funfact-note">Mesure la répartition des tomes entre auteurs : plus l'indice est élevé, moins la collection dépend de quelques auteurs.</span>
                </div>
            </div>
        </section>

        <!-- ══ RECHERCHE AVANCÉE (réintégrée) ═════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Exploration</div>
            <div class="panel search-panel">
                <h3>Recherche dans la collection</h3>
                <div class="search-field">
                    <input type="text" id="search-input" placeholder="Série, auteur, éditeur, catégorie, genre, contributeur…" autocomplete="off">
                    <div id="search-suggestions" class="autocomplete-suggestions"></div>
                </div>
                <button id="search-button" class="button button-opt">Rechercher</button>
                <div id="search-results"></div>
            </div>
        </section>

        <footer class="footer">
            <?php $current_version = SITE_VERSION; ?>
            <p class="hint">
                <?= htmlspecialchars($options['site_name'] ?? 'Lengas') ?> — version <?= $current_version ?>.
                <a href="<?= URL_GITEA ?>" target="_blank">Dépôt Gitéa</a>.
            </p>
        </footer>
    </div>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
        window.STATS = <?= json_encode($chart_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.SEARCH_DATA = <?= json_encode($search_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="assets/js/stats.js"></script>
</body>
</html>
