<?php
require 'config.php';
require 'fonctions/stats_compute.php';
require 'fonctions/reviews.php';
require_once 'fonctions/licenses.php'; // get_series_license_map() — bouton « Licence » de la fiche détail
require 'includes/themes.php';
require 'includes/helpers.php';      // registre central des types
require_once 'includes/anilist.php'; // anilist_format_label() — libellés des formats Animethèque

$all_data = load_data();
$options  = load_options();
// ── Mangathèque / Animethèque ────────────────────────────────────────────────
// Deux collections cloisonnées, comme partout ailleurs sur le site (admin,
// index, filtres…). Le filtrage est sans danger ici : aucune écriture sur la
// table `series` n'a lieu, ces tableaux ne servent qu'à la lecture et à
// l'affichage.
$data       = series_of_type($all_data, 'manga');
$anime_data = series_of_type($all_data, 'anime');
$has_anime  = count($anime_data) > 0;

// ── Séries visibles publiquement, tous types confondus (pour la modale de
// détail série, réutilisée telle quelle depuis index.php / public.js — les
// « séries coup de cœur » du profil peuvent y renvoyer) ─────────────────────
$visible_data = array_values(array_filter($all_data, function ($series) use ($options) {
    $t = series_type($series);
    if (is_private_mode($options, $t)) return false;
    if (is_hide_mature($options, $t) && !empty($series['mature'])) return false;
    return true;
}));
$public_review_ids = [];
foreach (series_type_keys() as $__t) {
    if (!is_private_mode($options, $__t) && !is_hide_reviews($options, $__t)) {
        $public_review_ids = array_flip(get_review_series_ids());
        break;
    }
}
$public_series_licenses = get_series_license_map();
$reviews_public = !is_hide_reviews($options, 'manga') || !is_hide_reviews($options, 'anime');

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
        <?= theme_link_tag($options) ?>
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

// Nombre de critiques (séries encore présentes uniquement)
$stats['review_count'] = count(list_reviews($data));

// Réglages temps/prix par défaut (pour préciser les explications)
$stats_settings = stats_get_settings($options);
$def_minutes    = $stats_settings['default']['minutes'];
$def_value      = $stats_settings['default']['value'];
$def_value_coll = $stats_settings['default']['value_collector'];
$stats_cats     = $stats_settings['categories'];   // [cat => ['minutes','value','value_collector']]
$has_cat_settings = count($stats_cats) > 0;

// ── Statistiques Animethèque (calcul à part, cf. fonctions/stats_compute.php) ─
$anime_stats = compute_anime_stats($anime_data, $options);
$anime_stats['review_count'] = count(list_reviews($anime_data));
$anime_def_minutes     = $anime_stats['settings']['default'];
$anime_format_settings = $anime_stats['settings']['formats']; // [FORMAT => minutes]
$has_anime_format_settings = count($anime_format_settings) > 0;

// Fun fact « Licence la plus conséquente » (Animethèque) : poids combiné
// tomes+épisodes et séries manga+anime de toute la licence — cf. commentaire
// de tête de compute_top_license(). $all_data (non filtré par type) est
// requis pour voir les deux collections d'une même licence mixte.
$anime_stats['top_license'] = compute_top_license($all_data);

// Formate un nombre sans zéros inutiles (40.0 → "40", 7.50 → "7,5")
$fmt_num = function ($n, $dec = 2) {
    $s = number_format((float) $n, $dec, ',', ' ');
    if (strpos($s, ',') !== false) $s = rtrim(rtrim($s, '0'), ',');
    return $s;
};

// Données de recherche (réintégrées dans le nouveau design)
// Couvre les DEUX collections, quel que soit l'onglet affiché : la recherche
// est un outil transverse, elle ne doit pas dépendre de l'onglet actif.
$search_data = [];
foreach ($data as $series) {
    // Décompte des tomes lus / collectors / statut de lecture de la série
    $vols       = $series['volumes'] ?? [];
    $read_count = 0;
    $coll_count = 0;
    $has_last   = false;
    foreach ($vols as $v) {
        if (($v['status'] ?? '') === 'terminé') $read_count++;
        if (!empty($v['collector']))            $coll_count++;
        if (!empty($v['last']))                 $has_last = true;
    }
    $search_data[] = [
        'type'               => 'manga',
        'name'               => $series['name'],
        'author'             => $series['author'],
        'publisher'          => $series['publisher'],
        'categories'         => stats_clean_list($series['categories'] ?? []),
        'genres'             => stats_clean_list($series['genres'] ?? []),
        'other_contributors' => stats_clean_list($series['other_contributors'] ?? []),
        // Pas de titres alternatifs côté manga (notion propre à l'Animethèque) :
        // clé toujours présente, vide ici, pour que le JS de recherche n'ait
        // pas à distinguer les deux types sur ce champ.
        'alt_titles'         => [],
        'volumes_count'      => count($vols),
        'read_count'         => $read_count,
        'collector_count'    => $coll_count,
        'status'             => $series['status'] ?? 'en cours',
        'complete'           => $has_last,
        'mature'             => !empty($series['mature']),
        'read_elsewhere'     => !empty($series['read_elsewhere']),
    ];
}
foreach ($anime_data as $series) {
    // Même forme que côté manga, avec un vocabulaire adapté : studios à la
    // place d'auteur/éditeur, pas de tomes collectors ni de « lu ailleurs ».
    // Les clés communes (name, categories, genres, volumes_count, read_count,
    // status, complete, mature) restent identiques pour que le JS de
    // recherche n'ait besoin d'aucune branche par type sur l'essentiel.
    $episodes    = $series['volumes'] ?? [];
    $watch_count = 0;
    $has_last    = false;
    foreach ($episodes as $ep) {
        if (($ep['status'] ?? '') === 'terminé') $watch_count++;
        if (!empty($ep['last']))                  $has_last = true;
    }
    $search_data[] = [
        'type'               => 'anime',
        'name'               => $series['name'],
        'author'             => series_studios_text($series), // affiché à la place de l'auteur
        'publisher'          => '',
        'categories'         => stats_clean_list($series['categories'] ?? []),
        'genres'             => stats_clean_list($series['genres'] ?? []),
        'other_contributors' => [],
        // Titres alternatifs (romaji/anglais/natif/synonymes) : absents côté
        // manga (clé vide), présents ici pour que la recherche et ses
        // suggestions retrouvent une série par un titre qui n'est pas celui
        // affiché sur la carte.
        'alt_titles'         => array_values(array_diff(series_alt_titles($series), [$series['name']])),
        'volumes_count'      => count($episodes),
        'read_count'         => $watch_count,
        'collector_count'    => 0,
        'status'             => $series['status'] ?? 'en cours',
        'complete'           => $has_last,
        'mature'             => !empty($series['mature']),
        'read_elsewhere'     => false,
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
    'genres'       => array_map(fn($g) => ['name' => $g['name'], 'series' => $g['series'] ?? 0, 'volumes' => $g['volumes']], $stats['genres']),
    'genres_none'  => $stats['genres_none'],
    'genres_none_series' => $stats['genres_none_series'] ?? 0,
    'categories'   => array_map(fn($c) => ['name' => $c['name'], 'series' => $c['series'], 'volumes' => $c['volumes']], $stats['categories']),
    'contributors' => array_map(fn($c) => ['name' => $c['name'], 'series' => $c['series'], 'volumes' => $c['volumes']], $stats['contributors']),
    'value' => (function () use ($stats) {
        // Une barre par catégorie pour les tomes normaux et pour les collectors.
        // Une barre n'est pas incluse si elle vaut 0 €.
        $cats = $stats['value_categories'] ?? [];
        $labels    = [];
        $normal    = [];
        $collector = [];
        foreach ($cats as $c) {
            if ($c['total'] <= 0) continue; // catégorie entièrement à 0 €
            $labels[]    = $c['name'];
            // null => barre masquée (valeur 0)
            $normal[]    = $c['normal']    > 0 ? $c['normal']    : null;
            $collector[] = $c['collector'] > 0 ? $c['collector'] : null;
        }
        return [
            'labels' => $labels,
            'series' => [
                ['name' => 'Tomes normaux',    'data' => $normal],
                ['name' => 'Tomes collectors', 'data' => $collector],
            ],
        ];
    })(),
    'purchases' => $stats['purchases_by_month'],
    'growth'    => $stats['growth'],
    'reads'          => $stats['reads_by_month']  ?? [],
    'reading_growth' => $stats['reading_growth']  ?? [],
    'completion' => [
        'labels' => ['Publication terminée', 'Publication en cours', 'Publication mise en pause', 'Publication abandonnée'],
        'values' => [
            $stats['status_series_counts']['terminée']    ?? 0,
            $stats['status_series_counts']['en cours']    ?? 0,
            $stats['status_series_counts']['en pause']    ?? 0,
            $stats['status_series_counts']['abandonnée']  ?? 0,
        ],
    ],
];

// Payload JSON pour le front — Animethèque (mêmes conventions de clés que
// $chart_payload ci-dessus, pour réutiliser telles quelles les fonctions de
// rendu de stats.js — seul le conteneur DOM ciblé change, cf. data-scope).
$anime_chart_payload = [
    'status' => [
        'labels' => ['Vus', 'En cours', 'À voir'],
        'values' => [
            $anime_stats['episode_status_counts']['terminé']  ?? 0,
            $anime_stats['episode_status_counts']['en cours'] ?? 0,
            $anime_stats['episode_status_counts']['à voir']   ?? 0,
        ],
        'elsewhere' => 0, // pas de notion de « lu ailleurs » côté Animethèque
    ],
    'time' => [
        'labels' => ['Déjà vu', 'En cours', 'À voir'],
        'values' => [
            round($anime_stats['time_by_episode_status']['terminé']  ?? 0),
            round($anime_stats['time_by_episode_status']['en cours'] ?? 0),
            round($anime_stats['time_by_episode_status']['à voir']   ?? 0),
        ],
    ],
    'genres'       => array_map(fn($g) => ['name' => $g['name'], 'series' => $g['series'] ?? 0, 'volumes' => $g['episodes']], $anime_stats['genres']),
    'genres_none'  => $anime_stats['genres_none'],
    'genres_none_series' => $anime_stats['genres_none_series'] ?? 0,
    'formats'      => array_map(fn($f) => ['name' => $f['name'], 'series' => $f['series'], 'volumes' => $f['episodes']], $anime_stats['formats']),
    'studios'      => array_map(fn($s) => ['name' => $s['name'], 'series' => $s['series'], 'volumes' => $s['episodes']], $anime_stats['studios']),
    'added'          => $anime_stats['added_by_month'],
    'growth'         => $anime_stats['growth'],
    'watched'        => $anime_stats['watched_by_month'],
    'watched_growth' => $anime_stats['watched_growth'],
    'airing' => [
        'labels' => ['Diffusion terminée', 'Diffusion en cours', 'Diffusion en pause', 'Diffusion abandonnée'],
        'values' => [
            $anime_stats['airing_status_counts']['terminée']   ?? 0,
            $anime_stats['airing_status_counts']['en cours']   ?? 0,
            $anime_stats['airing_status_counts']['en pause']   ?? 0,
            $anime_stats['airing_status_counts']['abandonnée'] ?? 0,
        ],
    ],
    'watch_status' => [
        'labels' => ['À débuter', 'En cours', 'Terminée', 'Abandonnée'],
        'values' => [
            $anime_stats['watch_status_counts']['not_started'] ?? 0,
            $anime_stats['watch_status_counts']['in_progress'] ?? 0,
            $anime_stats['watch_status_counts']['completed']   ?? 0,
            $anime_stats['watch_status_counts']['abandoned']   ?? 0,
        ],
    ],
    'rating' => [
        'labels' => ['Apprécié', 'Mitigé', 'Pas aimé', 'Sans note'],
        'values' => [
            $anime_stats['rating_counts']['apprecie'] ?? 0,
            $anime_stats['rating_counts']['mitige']    ?? 0,
            $anime_stats['rating_counts']['deteste']   ?? 0,
            $anime_stats['rating_counts']['']          ?? 0,
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
        <?= theme_link_tag($options) ?>
</head>
<body class="stats-page with-sidebar">
    <?php include 'includes/public-sidebar.php'; ?>
    <div class="container">
        <header class="stats-header">
            <h1><?= htmlspecialchars($page_title) ?></h1>
        </header>

        <!-- ══ ONGLETS MANGATHÈQUE / ANIMETHÈQUE ═══════════════════════════ -->
        <?php if ($has_anime): ?>
        <div class="stats-tabs" role="tablist">
            <button type="button" class="stats-tab stats-tab--active" data-stats-tab="manga" role="tab" aria-selected="true">
                <img src="https://api.iconify.design/mdi/bookshelf.svg?color=%23c94e93" width="16" height="16" alt="">
                Mangathèque
            </button>
            <button type="button" class="stats-tab" data-stats-tab="anime" role="tab" aria-selected="false">
                <img src="https://api.iconify.design/mdi/television-classic.svg?color=%2338bdf8" width="16" height="16" alt="">
                Animethèque
            </button>
        </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════════════════════
             PANNEAU MANGATHÈQUE (contenu historique, inchangé)
             ══════════════════════════════════════════════════════════════════ -->
        <div class="stats-tab-panel stats-tab-panel--active" data-stats-tab-panel="manga">

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
                    ['Critiques',     $stats['review_count'],      'mdi:pencil'],
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
                    <p class="panel-note">
                        Durées estimées d'après les temps moyens de lecture par tome (moyenne des catégories de chaque série).
                        <?php if ($has_cat_settings): ?>
                            Réglages actuels :
                            <?php
                            $bits = [];
                            foreach ($stats_cats as $cat => $cfg) {
                                $bits[] = htmlspecialchars($cat) . ' : ' . $fmt_num($cfg['minutes'], 1) . ' min';
                            }
                            $bits[] = 'autres : ' . $fmt_num($def_minutes, 1) . ' min (défaut)';
                            echo implode(' · ', $bits);
                            ?>.
                        <?php else: ?>
                            Réglage actuel : <?= $fmt_num($def_minutes, 1) ?> min/tome (valeur par défaut, aucune catégorie personnalisée).
                        <?php endif; ?>
                    </p>
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
                        <button class="toggle-btn" data-metric="volumes">Par tomes</button>
                        <button class="toggle-btn is-active" data-metric="series">Par séries</button>
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
                <div class="kpi-inline"><b><?= $stats['avg_volumes_per_publisher'] ?></b> tomes / éditeur</div>
            </div>
            <div class="panel">
                <div class="panel-head">
                    <h3>Cartographie des éditeurs</h3>
                    <div class="toggle-group" data-target="publishers">
                        <button class="toggle-btn" data-metric="volumes">Par tomes</button>
                        <button class="toggle-btn is-active" data-metric="series">Par séries</button>
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
                    <div class="panel-head">
                        <h3>Genres</h3>
                        <div class="toggle-group" data-target="genres">
                            <button class="toggle-btn" data-metric="volumes">Par tomes</button>
                            <button class="toggle-btn is-active" data-metric="series">Par séries</button>
                        </div>
                    </div>
                    <div id="genres-chart" class="apex-chart"></div>
                </div>
                <div class="panel">
                    <div class="panel-head">
                        <h3>Catégories</h3>
                        <div class="toggle-group" data-target="categories">
                            <button class="toggle-btn" data-metric="volumes">Par tomes</button>
                            <button class="toggle-btn is-active" data-metric="series">Par séries</button>
                        </div>
                    </div>
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
                <div class="kpi-inline"><b><?= $stats['avg_series_per_contributor'] ?></b> séries / contributeur</div>
                <div class="kpi-inline"><b><?= $stats['avg_volumes_per_contributor'] ?></b> tomes / contributeur</div>
            </div>
            <div class="panel">
                <div class="panel-head">
                    <h3>Top contributeurs</h3>
                    <div class="toggle-group" data-target="contributors-view">
                        <button class="toggle-btn" data-metric="volumes">Par tomes</button>
                        <button class="toggle-btn is-active" data-metric="series">Par séries</button>
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
            <p class="panel-note">
                Valeurs estimées d'après les prix moyens par tome (normaux / collectors, moyenne des catégories de chaque série).
                <?php if ($has_cat_settings): ?>
                    Réglages actuels :
                    <?php
                    $bits = [];
                    foreach ($stats_cats as $cat => $cfg) {
                        $bits[] = htmlspecialchars($cat) . ' : ' . $fmt_num($cfg['value']) . ' € / ' . $fmt_num($cfg['value_collector']) . ' € collector';
                    }
                    $bits[] = 'autres : ' . $fmt_num($def_value) . ' € / ' . $fmt_num($def_value_coll) . ' € collector (défaut)';
                    echo implode(' · ', $bits);
                    ?>.
                <?php else: ?>
                    Réglage actuel : <?= $fmt_num($def_value) ?> € normal, <?= $fmt_num($def_value_coll) ?> € collector (valeurs par défaut, aucune catégorie personnalisée).
                <?php endif; ?>
            </p>
        </section>

        <!-- ══ 9. ÉVOLUTION DANS LE TEMPS ═════════════════════════════════ -->
        <?php if (count($stats['growth']) > 1): ?>
        <section class="stats-section">
            <div class="section-eyebrow">Évolution dans le temps</div>
            <div class="panel">
                <div class="panel-head">
                    <h3>Tomes ajoutés par mois</h3>
                    <span class="panel-avg">Moyenne : <?= $fmt_num($stats['purchases_avg'] ?? 0, 1) ?> / mois</span>
                </div>
                <div class="timeline-chart-wrap">
                    <div id="line-purchases-axis" class="apex-chart apex-chart--timeline-axis"></div>
                    <div id="line-purchases" class="apex-chart apex-chart--timeline"></div>
                </div>
            </div>
            <div class="panel">
                <div class="panel-head"><h3>Croissance de la collection</h3></div>
                <div class="timeline-chart-wrap">
                    <div id="line-growth-axis" class="apex-chart apex-chart--timeline-axis"></div>
                    <div id="line-growth" class="apex-chart apex-chart--timeline"></div>
                </div>
            </div>
            <?php if (!empty($stats['reads_by_month']) && count($stats['reads_by_month']) > 1): ?>
            <div class="panel">
                <div class="panel-head">
                    <h3>Tomes lus par mois</h3>
                    <span class="panel-avg">Moyenne : <?= $fmt_num($stats['reads_avg'] ?? 0, 1) ?> / mois</span>
                </div>
                <div class="timeline-chart-wrap">
                    <div id="line-reads-axis" class="apex-chart apex-chart--timeline-axis"></div>
                    <div id="line-reads" class="apex-chart apex-chart--timeline"></div>
                </div>
            </div>
            <div class="panel">
                <div class="panel-head"><h3>Progression des lectures (cumulé)</h3></div>
                <div class="timeline-chart-wrap">
                    <div id="line-reading-growth-axis" class="apex-chart apex-chart--timeline-axis"></div>
                    <div id="line-reading-growth" class="apex-chart apex-chart--timeline"></div>
                </div>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- ══ 10. COMPLÉTUDE DES SÉRIES ══════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Complétude des séries</div>
            <div class="panel-row">
                <div class="panel panel-chart">
                    <h3>Statut de publication</h3>
                    <canvas id="chart-completion"></canvas>
                </div>
                <div class="panel panel-stats">
                    <div class="mini-stat"><span>Publication terminée</span><b><?= $stats['status_series_counts']['terminée'] ?? 0 ?></b></div>
                    <div class="mini-stat"><span>Publication en cours</span><b><?= $stats['status_series_counts']['en cours'] ?? 0 ?></b></div>
                    <div class="mini-stat"><span>Publication mise en pause</span><b><?= $stats['status_series_counts']['en pause'] ?? 0 ?></b></div>
                    <div class="mini-stat"><span>Publication abandonnée</span><b><?= $stats['status_series_counts']['abandonnée'] ?? 0 ?></b></div>
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

            // Formatage d'une durée en jours → texte lisible (années / mois / jours)
            $fmt_days = function ($days) {
                $days = (int) round($days);
                if ($days <= 0) return 'moins d\'un jour';
                $years = intdiv($days, 365);
                $rem   = $days % 365;
                $months = intdiv($rem, 30);
                $d      = $rem % 30;
                $parts = [];
                if ($years > 0)  $parts[] = $years . ' an'   . ($years > 1 ? 's' : '');
                if ($months > 0) $parts[] = $months . ' mois';
                if ($d > 0 && $years === 0) $parts[] = $d . ' jour' . ($d > 1 ? 's' : '');
                if (count($parts) === 0) return $days . ' jour' . ($days > 1 ? 's' : '');
                return implode(' et ', $parts);
            };
            $fmt_date = function ($d) {
                if (!$d) return '';
                $ts = strtotime($d);
                return $ts ? date('d/m/Y', $ts) : $d;
            };
            $lp = $stats['longest_publication'];
            $mr = $stats['most_recently_read'];
            $lr = $stats['longest_to_read'];
            ?>
            <div class="funfact-grid">
                <div class="funfact">
                    <span class="funfact-label">Auteur le plus représenté</span>
                    <span class="funfact-split">
                        <span class="funfact-line"><span class="funfact-tag">Tomes</span> <?= htmlspecialchars($stats['top_author'] ?? '—') ?><?php if ($stats['top_author']): ?> <em>(<?= $plural($stats['top_author_volumes'], 'tome') ?>, <?= $plural($stats['top_author_series'], 'série') ?>)</em><?php endif; ?></span>
                        <span class="funfact-line"><span class="funfact-tag">Séries</span> <?= htmlspecialchars($stats['top_author_s_name'] ?? '—') ?><?php if ($stats['top_author_s_name']): ?> <em>(<?= $plural($stats['top_author_s_series'], 'série') ?>, <?= $plural($stats['top_author_s_vol'], 'tome') ?>)</em><?php endif; ?></span>
                    </span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Éditeur dominant</span>
                    <span class="funfact-split">
                        <span class="funfact-line"><span class="funfact-tag">Tomes</span> <?= htmlspecialchars($stats['top_publisher'] ?? '—') ?><?php if ($stats['top_publisher']): ?> <em>(<?= $plural($stats['top_publisher_volumes'], 'tome') ?>, <?= $plural($stats['top_publisher_series'], 'série') ?>)</em><?php endif; ?></span>
                        <span class="funfact-line"><span class="funfact-tag">Séries</span> <?= htmlspecialchars($stats['top_publisher_s_name'] ?? '—') ?><?php if ($stats['top_publisher_s_name']): ?> <em>(<?= $plural($stats['top_publisher_s_series'], 'série') ?>, <?= $plural($stats['top_publisher_s_vol'], 'tome') ?>)</em><?php endif; ?></span>
                    </span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Part du top 10 auteurs</span>
                    <span class="funfact-split">
                        <span class="funfact-line"><span class="funfact-tag">Tomes</span> <?= $stats['top10_authors_pct'] ?>% <em>(<?= $plural($stats['top10_authors_vol'], 'tome') ?> sur <?= $stats['total_volumes'] ?>)</em></span>
                        <span class="funfact-line"><span class="funfact-tag">Séries</span> <?= $stats['top10_authors_s_pct'] ?>% <em>(<?= $plural($stats['top10_authors_s_ser'], 'série') ?> sur <?= $stats['total_series'] ?>)</em></span>
                    </span>
                    <span class="funfact-note">Poids des 10 auteurs les plus présents dans la collection.</span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Série la plus longue</span>
                    <span class="funfact-value"><?= htmlspecialchars($stats['longest_series']['name'] ?? '—') ?><?php if ($stats['longest_series']['name']): ?> <em>(<?= $plural($stats['longest_series']['volumes'], 'tome') ?>)</em><?php endif; ?></span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Plus long temps de publication</span>
                    <span class="funfact-value"><?= htmlspecialchars($lp['name'] ?? '—') ?><?php if ($lp['name'] !== null): ?> <em>(<?= $fmt_days($lp['days']) ?>)</em><?php endif; ?></span>
                    <?php if ($lp['name'] !== null): ?><span class="funfact-note">Du tome 1 (<?= $fmt_date($lp['first']) ?>) au dernier tome (<?= $fmt_date($lp['last']) ?>), d'après les dates d'ajout.</span><?php endif; ?>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Série la plus récemment lue</span>
                    <span class="funfact-value"><?= htmlspecialchars($mr['name'] ?? '—') ?><?php if ($mr['name'] !== null): ?> <em>(<?= $fmt_date($mr['date']) ?>)</em><?php endif; ?></span>
                    <?php if ($mr['name'] !== null): ?><span class="funfact-note">Date du tome le plus récemment marqué comme « lu ».</span><?php endif; ?>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Série la plus longue à lire</span>
                    <span class="funfact-value"><?= htmlspecialchars($lr['name'] ?? '—') ?><?php if ($lr['name'] !== null): ?> <em>(<?= $fmt_days($lr['days']) ?>)</em><?php endif; ?></span>
                    <?php if ($lr['name'] !== null): ?><span class="funfact-note">Du 1er au dernier tome lu (<?= $fmt_date($lr['first']) ?> → <?= $fmt_date($lr['last']) ?>).</span><?php endif; ?>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Indice de diversité (Shannon)</span>
                    <span class="funfact-value"><?= $stats['shannon'] ?><?php if ($even !== null): ?> <em>(<?= $shannon_word ?>)</em><?php endif; ?></span>
                    <span class="funfact-note">Plus l'indice est élevé, moins la collection dépend d'un nombre restreint d'auteurs.</span>
                </div>
            </div>
        </section>

        </div><!-- /* fin .stats-tab-panel[data-stats-tab-panel="manga"] */ -->

        <!-- ══════════════════════════════════════════════════════════════════
             PANNEAU ANIMETHÈQUE
             ══════════════════════════════════════════════════════════════════ -->
        <?php if ($has_anime): ?>
        <div class="stats-tab-panel" data-stats-tab-panel="anime">

        <!-- ══ 1. VUE D'ENSEMBLE ══════════════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Vue d'ensemble</div>
            <div class="kpi-grid">
                <?php
                $anime_kpis = [
                    ['Séries',    $anime_stats['total_series'],   'mdi:television-classic'],
                    ['Épisodes',  $anime_stats['total_episodes'], 'mdi:play-box-multiple'],
                    ['Genres',    $anime_stats['total_genres'],   'mdi:tag-multiple'],
                    ['Formats',   $anime_stats['total_formats'],  'mdi:shape'],
                    ['Studios',   $anime_stats['total_studios'],  'mdi:domain'],
                    ['Favoris',   $anime_stats['favorite_count'], 'mdi:heart'],
                    ['Critiques', $anime_stats['review_count'],   'mdi:pencil'],
                ];
                foreach ($anime_kpis as [$label, $val, $icon]): ?>
                    <div class="kpi-card kpi-card--anime">
                        <img class="kpi-icon" src="https://api.iconify.design/<?= $icon ?>.svg?color=%2338bdf8" width="22" height="22" alt="">
                        <div class="kpi-value"><?= number_format($val, 0, ',', ' ') ?></div>
                        <div class="kpi-label"><?= $label ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$hide_mature): ?>
                    <div class="kpi-card kpi-card--anime">
                        <img class="kpi-icon" src="https://api.iconify.design/mdi/alert-octagon.svg?color=%23e879c6" width="22" height="22" alt="">
                        <div class="kpi-value"><?= $anime_stats['mature_series'] ?></div>
                        <div class="kpi-label">Séries matures</div>
                    </div>
                <?php endif; ?>
                <div class="kpi-card kpi-card--anime kpi-accent">
                    <img class="kpi-icon" src="https://api.iconify.design/mdi/replay.svg?color=%2334d399" width="22" height="22" alt="">
                    <div class="kpi-value"><?= $anime_stats['rewatched_series'] ?></div>
                    <div class="kpi-label">Séries revisionnées</div>
                </div>
                <?php if ($anime_stats['physical_editions_total'] > 0): ?>
                    <div class="kpi-card kpi-card--anime">
                        <img class="kpi-icon" src="https://api.iconify.design/mdi/disc.svg?color=%2338bdf8" width="22" height="22" alt="">
                        <div class="kpi-value"><?= $anime_stats['physical_editions_total'] ?></div>
                        <div class="kpi-label">Éditions physiques<?php if ($anime_stats['physical_editions_series'] > 0): ?> <em>(<?= $plural($anime_stats['physical_editions_series'], 'série') ?>)</em><?php endif; ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ══ 2. VISIONNAGE & PROGRESSION ══════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Visionnage &amp; progression</div>
            <div class="panel-row">
                <div class="panel panel-chart">
                    <h3>Répartition des épisodes</h3>
                    <canvas id="anime-chart-status"></canvas>
                </div>
                <div class="panel panel-stats">
                    <div class="big-metric">
                        <span class="big-metric-value"><?= $anime_stats['completion_pct'] ?>%</span>
                        <span class="big-metric-label">de complétion globale</span>
                    </div>
                    <div class="mini-stat"><span>Épisodes vus</span><b><?= $anime_stats['episode_status_counts']['terminé'] ?? 0 ?> (<?= $anime_stats['episode_status_pct']['terminé'] ?>%)</b></div>
                    <div class="mini-stat"><span>Épisodes en cours</span><b><?= $anime_stats['episode_status_counts']['en cours'] ?? 0 ?> (<?= $anime_stats['episode_status_pct']['en cours'] ?>%)</b></div>
                    <div class="mini-stat"><span>Épisodes à voir</span><b><?= $anime_stats['episode_status_counts']['à voir'] ?? 0 ?> (<?= $anime_stats['episode_status_pct']['à voir'] ?>%)</b></div>
                    <div class="mini-stat"><span>Séries possédées entièrement</span><b><?= $anime_stats['complete_series'] ?></b></div>
                    <div class="mini-stat"><span>Séries complètement visionnées</span><b><?= $anime_stats['completed_series'] ?> (<?= $anime_stats['series_done_pct'] ?>%)</b></div>
                    <div class="mini-stat"><span>Séries commencées non terminées</span><b><?= $anime_stats['started_not_done'] ?></b></div>
                </div>
            </div>
        </section>

        <!-- ══ 3. TEMPS DE VISIONNAGE ═══════════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Temps de visionnage</div>
            <div class="panel-row">
                <div class="panel panel-chart">
                    <h3>Répartition du temps</h3>
                    <canvas id="anime-chart-time"></canvas>
                </div>
                <div class="panel panel-stats">
                    <div class="big-metric">
                        <span class="big-metric-value"><?= stats_format_minutes($anime_stats['time_total']) ?></span>
                        <span class="big-metric-label">de visionnage pour toute la collection</span>
                    </div>
                    <div class="mini-stat"><span>Déjà vu</span><b><?= stats_format_minutes($anime_stats['time_by_episode_status']['terminé'] ?? 0) ?></b></div>
                    <div class="mini-stat"><span>En cours</span><b><?= stats_format_minutes($anime_stats['time_by_episode_status']['en cours'] ?? 0) ?></b></div>
                    <div class="mini-stat"><span>À voir</span><b><?= stats_format_minutes($anime_stats['time_by_episode_status']['à voir'] ?? 0) ?></b></div>
                    <p class="panel-note">
                        Durées estimées d'après la durée réelle d'un épisode fournie par Anilist ; à défaut, d'après le
                        temps moyen réglé par format.
                        <?php if ($has_anime_format_settings): ?>
                            Réglages actuels :
                            <?php
                            $abits = [];
                            foreach ($anime_format_settings as $fmt => $mins) {
                                $abits[] = htmlspecialchars(anilist_format_label($fmt)) . ' : ' . $fmt_num($mins, 1) . ' min';
                            }
                            $abits[] = 'autres formats : ' . $fmt_num($anime_def_minutes, 1) . ' min (défaut)';
                            echo implode(' · ', $abits);
                            ?>.
                        <?php else: ?>
                            Réglage actuel : <?= $fmt_num($anime_def_minutes, 1) ?> min/épisode (valeur par défaut, aucun format personnalisé).
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </section>

        <!-- ══ 4. GENRES, FORMATS & STUDIOS ═══════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Genres, formats &amp; studios</div>
            <div class="panel-row">
                <div class="panel">
                    <div class="panel-head">
                        <h3>Genres</h3>
                        <div class="toggle-group" data-target="anime-genres">
                            <button class="toggle-btn" data-metric="volumes">Par épisodes</button>
                            <button class="toggle-btn is-active" data-metric="series">Par séries</button>
                        </div>
                    </div>
                    <div id="anime-genres-chart" class="apex-chart"></div>
                </div>
                <div class="panel">
                    <div class="panel-head">
                        <h3>Formats</h3>
                        <div class="toggle-group" data-target="anime-formats">
                            <button class="toggle-btn" data-metric="volumes">Par épisodes</button>
                            <button class="toggle-btn is-active" data-metric="series">Par séries</button>
                        </div>
                    </div>
                    <div id="anime-formats-chart" class="apex-chart"></div>
                </div>
            </div>
            <?php if ($anime_stats['total_studios'] > 0): ?>
            <div class="panel">
                <div class="panel-head">
                    <h3>Top studios</h3>
                    <div class="toggle-group" data-target="anime-studios">
                        <button class="toggle-btn" data-metric="volumes">Par épisodes</button>
                        <button class="toggle-btn is-active" data-metric="series">Par séries</button>
                    </div>
                </div>
                <div id="anime-bar-studios" class="apex-chart"></div>
            </div>
            <?php endif; ?>
        </section>

        <!-- ══ 5. NOTATION ═══════════════════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Notation</div>
            <div class="panel">
                <div class="panel-head"><h3>Répartition des notes</h3></div>
                <div id="anime-rating-chart" class="apex-chart"></div>
            </div>
        </section>

        <!-- ══ 6. ÉVOLUTION DANS LE TEMPS ══════════════════════════════════ -->
        <?php if (count($anime_stats['growth']) > 1): ?>
        <section class="stats-section">
            <div class="section-eyebrow">Évolution dans le temps</div>
            <div class="panel">
                <div class="panel-head"><h3>Épisodes ajoutés par mois</h3></div>
                <div class="timeline-chart-wrap">
                    <div id="anime-line-added-axis" class="apex-chart apex-chart--timeline-axis"></div>
                    <div id="anime-line-added" class="apex-chart apex-chart--timeline"></div>
                </div>
            </div>
            <div class="panel">
                <div class="panel-head"><h3>Croissance de la collection</h3></div>
                <div class="timeline-chart-wrap">
                    <div id="anime-line-growth-axis" class="apex-chart apex-chart--timeline-axis"></div>
                    <div id="anime-line-growth" class="apex-chart apex-chart--timeline"></div>
                </div>
            </div>
            <?php if (!empty($anime_stats['watched_by_month']) && count($anime_stats['watched_by_month']) > 1): ?>
            <div class="panel">
                <div class="panel-head">
                    <h3>Épisodes vus par mois</h3>
                    <span class="panel-avg">Moyenne : <?= $fmt_num($anime_stats['watched_avg'] ?? 0, 1) ?> / mois</span>
                </div>
                <div class="timeline-chart-wrap">
                    <div id="anime-line-watched-axis" class="apex-chart apex-chart--timeline-axis"></div>
                    <div id="anime-line-watched" class="apex-chart apex-chart--timeline"></div>
                </div>
            </div>
            <div class="panel">
                <div class="panel-head"><h3>Progression du visionnage (cumulé)</h3></div>
                <div class="timeline-chart-wrap">
                    <div id="anime-line-watched-growth-axis" class="apex-chart apex-chart--timeline-axis"></div>
                    <div id="anime-line-watched-growth" class="apex-chart apex-chart--timeline"></div>
                </div>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- ══ 7. COMPLÉTUDE DES SÉRIES ═════════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Complétude des séries</div>
            <div class="panel-row">
                <div class="panel panel-chart">
                    <h3>Statut de diffusion</h3>
                    <canvas id="anime-chart-completion"></canvas>
                </div>
                <div class="panel panel-stats">
                    <div class="mini-stat"><span>Diffusion terminée</span><b><?= $anime_stats['airing_status_counts']['terminée'] ?? 0 ?></b></div>
                    <div class="mini-stat"><span>Diffusion en cours</span><b><?= $anime_stats['airing_status_counts']['en cours'] ?? 0 ?></b></div>
                    <div class="mini-stat"><span>Diffusion mise en pause</span><b><?= $anime_stats['airing_status_counts']['en pause'] ?? 0 ?></b></div>
                    <div class="mini-stat"><span>Diffusion abandonnée</span><b><?= $anime_stats['airing_status_counts']['abandonnée'] ?? 0 ?></b></div>
                </div>
            </div>
            <div class="panel-row">
                <div class="panel panel-chart">
                    <h3>Statut de visionnage</h3>
                    <canvas id="anime-chart-watch-status"></canvas>
                </div>
                <div class="panel panel-stats">
                    <div class="mini-stat"><span>À débuter</span><b><?= $anime_stats['watch_status_counts']['not_started'] ?? 0 ?></b></div>
                    <div class="mini-stat"><span>En cours</span><b><?= $anime_stats['watch_status_counts']['in_progress'] ?? 0 ?></b></div>
                    <div class="mini-stat"><span>Terminée</span><b><?= $anime_stats['watch_status_counts']['completed'] ?? 0 ?></b></div>
                    <div class="mini-stat"><span>Abandonnée</span><b><?= $anime_stats['watch_status_counts']['abandoned'] ?? 0 ?></b></div>
                </div>
            </div>
        </section>

        <!-- ══ 8. FUN FACTS ═════════════════════════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Fun facts</div>
            <?php
            $anime_even = $anime_stats['shannon_even'];
            if ($anime_even === null)      $anime_shannon_word = '';
            elseif ($anime_even >= 0.85)   $anime_shannon_word = 'collection très variée';
            elseif ($anime_even >= 0.6)    $anime_shannon_word = 'bonne diversité';
            elseif ($anime_even >= 0.35)   $anime_shannon_word = 'diversité modérée';
            else                           $anime_shannon_word = 'quelques studios dominent';
            $amrw = $anime_stats['most_recently_watched'];
            $altw = $anime_stats['longest_to_watch'];
            $atl  = $anime_stats['top_license'];
            // Accord du mot composé « tome/épisode » : $plural() se contente
            // d'ajouter un « s » final, ce qui donne « tome/épisodes » au
            // pluriel au lieu de « tomes/épisodes ». On accorde ici chaque
            // moitié séparément.
            $plural_items = fn($n) => $n . ' ' . ($n > 1 ? 'tomes/épisodes' : 'tome/épisode');
            ?>
            <div class="funfact-grid">
                <div class="funfact">
                    <span class="funfact-label">Série la plus longue</span>
                    <span class="funfact-value"><?= htmlspecialchars($anime_stats['longest_series']['name'] ?? '—') ?><?php if ($anime_stats['longest_series']['name']): ?> <em>(<?= $plural($anime_stats['longest_series']['episodes'], 'épisode') ?> vus)</em><?php endif; ?></span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Revisionnages cumulés</span>
                    <span class="funfact-value"><?= $anime_stats['rewatch_total'] ?><?php if ($anime_stats['rewatched_series'] > 0): ?> <em>(<?= $plural($anime_stats['rewatched_series'], 'série') ?> concernée<?= $anime_stats['rewatched_series'] > 1 ? 's' : '' ?>)</em><?php endif; ?></span>
                    <span class="funfact-note">Nombre total de revisionnages déclarés, toutes séries confondues.</span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Studio le plus représenté</span>
                    <span class="funfact-split">
                        <span class="funfact-line"><span class="funfact-tag">Épisodes</span> <?= htmlspecialchars($anime_stats['studios'][0]['name'] ?? '—') ?><?php if (!empty($anime_stats['studios'][0]['name'])): ?> <em>(<?= $plural($anime_stats['studios'][0]['episodes'], 'épisode') ?>, <?= $plural($anime_stats['studios'][0]['series'], 'série') ?>)</em><?php endif; ?></span>
                        <?php
                        $anime_studios_by_series = $anime_stats['studios'];
                        usort($anime_studios_by_series, fn($a, $b) => $b['series'] <=> $a['series']);
                        $top_studio_s = $anime_studios_by_series[0] ?? null;
                        ?>
                        <span class="funfact-line"><span class="funfact-tag">Séries</span> <?= htmlspecialchars($top_studio_s['name'] ?? '—') ?><?php if (!empty($top_studio_s['name'])): ?> <em>(<?= $plural($top_studio_s['series'], 'série') ?>, <?= $plural($top_studio_s['episodes'], 'épisode') ?>)</em><?php endif; ?></span>
                    </span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Format dominant</span>
                    <span class="funfact-value"><?= htmlspecialchars($anime_stats['formats'][0]['name'] ?? '—') ?><?php if (!empty($anime_stats['formats'][0]['name'])): ?> <em>(<?= $plural($anime_stats['formats'][0]['series'], 'série') ?>)</em><?php endif; ?></span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Série la plus récemment vue</span>
                    <span class="funfact-value"><?= htmlspecialchars($amrw['name'] ?? '—') ?><?php if ($amrw['name'] !== null): ?> <em>(<?= $fmt_date($amrw['date']) ?>)</em><?php endif; ?></span>
                    <?php if ($amrw['name'] !== null): ?><span class="funfact-note">Date de l'épisode le plus récemment marqué comme « vu ».</span><?php endif; ?>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Série la plus longue à visionner</span>
                    <span class="funfact-value"><?= htmlspecialchars($altw['name'] ?? '—') ?><?php if ($altw['name'] !== null): ?> <em>(<?= $fmt_days($altw['days']) ?>)</em><?php endif; ?></span>
                    <?php if ($altw['name'] !== null): ?><span class="funfact-note">Du 1er au dernier épisode vu (<?= $fmt_date($altw['first']) ?> → <?= $fmt_date($altw['last']) ?>).</span><?php endif; ?>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Indice de diversité (Shannon)</span>
                    <span class="funfact-value"><?= $anime_stats['shannon'] ?><?php if ($anime_even !== null): ?> <em>(<?= $anime_shannon_word ?>)</em><?php endif; ?></span>
                    <span class="funfact-note">Plus l'indice est élevé, moins la collection dépend d'un nombre restreint de studios.</span>
                </div>
                <div class="funfact">
                    <span class="funfact-label">Licence la plus conséquente</span>
                    <span class="funfact-split">
                        <span class="funfact-line"><span class="funfact-tag">Tomes/épisodes</span> <?= htmlspecialchars($atl['items_name'] ?? '—') ?><?php if ($atl['items_name'] !== null): ?> <em>(<?= $plural_items($atl['items_items']) ?>, <?= $plural($atl['items_series'], 'série') ?>)</em><?php endif; ?></span>
                        <span class="funfact-line"><span class="funfact-tag">Séries</span> <?= htmlspecialchars($atl['series_name'] ?? '—') ?><?php if ($atl['series_name'] !== null): ?> <em>(<?= $plural($atl['series_series'], 'série') ?>, <?= $plural_items($atl['series_items']) ?>)</em><?php endif; ?></span>
                    </span>
                    <span class="funfact-note">Toutes collections confondues (mangas et animés) : une licence peut regrouper les deux.</span>
                </div>
            </div>
        </section>

        </div><!-- /* fin .stats-tab-panel[data-stats-tab-panel="anime"] */ -->
        <?php endif; ?>

        <!-- ══ RECHERCHE AVANCÉE (réintégrée) ═════════════════════════════ -->
        <section class="stats-section">
            <div class="section-eyebrow">Exploration</div>
            <div class="panel search-panel">
                <h3>Recherche dans la collection</h3>
                <div class="search-field">
                    <input type="text" id="search-input" placeholder="Série, auteur, éditeur, studio, catégorie, genre, contributeur…" autocomplete="off">
                    <div id="search-suggestions" class="autocomplete-suggestions"></div>
                </div>
                <button id="search-button" class="button button-opt">Rechercher</button>
                <div id="search-results"></div>
            </div>
        </section>

    </div>

    <!-- Modale pour afficher les détails d'une série (réutilise le rendu et
         les scripts de la page d'accueil : mêmes ID, mêmes fonctions JS).
         Absente du parcours normal de cette page (pas de liste de séries
         cliquable ici) : sert uniquement de cible aux « séries coup de
         cœur » du profil, cf. includes/public-profil-modal.php. -->
    <div class="modal" id="series-detail-modal">
        <div class="modal-content">
            <span class="close-modal" id="close-series-detail-modal">&times;</span>
            <h2 id="modal-series-title"></h2>
            <div id="modal-series-content" class="modal-scrollable-content">
                <div class="modal-series-header">
                    <div class="modal-series-image-col">
                        <img id="modal-series-image" src="" alt="Image de la série" class="series-image">
                        <div id="modal-series-review-btn"></div>
                        <div id="modal-series-license-btn"></div>
                    </div>
                    <div class="modal-series-info">
                        <p id="modal-row-author"><strong>Auteur :</strong> <span id="modal-series-author"></span></p>
                        <p id="modal-row-publisher"><strong>Éditeur :</strong> <span id="modal-series-publisher"></span></p>
                        <p id="modal-row-contributors"><strong>Autres contributeurs :</strong> <span id="modal-series-other-contributors"></span></p>
                        <p id="modal-row-studios"><strong>Studios :</strong> <span id="modal-series-studios"></span></p>
                        <p id="modal-row-categories"><strong id="modal-label-categories">Catégories :</strong> <span id="modal-series-categories"></span></p>
                        <p><strong>Genres :</strong> <span id="modal-series-genres"></span></p>
                        <div class="series-stats" id="modal-series-stats"></div>
                        <div class="series-badges" id="modal-series-badges"></div>
                    </div>
                </div>
                <h3 id="modal-volumes-title">Liste des tomes :</h3>
                <ul class="volumes-list" id="modal-volumes-list"></ul>
            </div>
        </div>
    </div>

    <!-- Modale critique -->
    <div class="modal" id="review-detail-modal">
        <div class="modal-content">
            <span class="close-modal" id="close-review-detail-modal">&times;</span>
            <div class="review-modal-header">
                <img id="review-modal-thumb" class="review-modal-thumb" src="" alt="">
                <div class="review-modal-heading">
                    <h2 id="review-modal-title"></h2>
                    <p id="review-modal-author"></p>
                    <p id="review-modal-publisher"></p>
                    <p id="review-modal-categories"></p>
                </div>
            </div>
            <div class="review-modal-actions">
                <button type="button" id="review-modal-back" class="button button-ext">← Retour à la série</button>
            </div>
            <div id="review-modal-body" class="review-modal-body review-rendered"></div>
            <p id="review-modal-credit" class="review-modal-credit"></p>
        </div>
    </div>

    <!-- Modale licence -->
    <div class="modal" id="license-detail-public-modal">
        <div class="modal-content">
            <span class="close-modal" id="close-license-detail-public-modal">&times;</span>
            <div class="license-public-header">
                <span class="license-public-icon">📚</span>
                <h2 id="license-public-title"></h2>
            </div>
            <div id="license-public-list" class="license-public-list">
                <p class="reviews-empty">Chargement…</p>
            </div>
        </div>
    </div>

    <?php
    // Modale « Qui suis-je ? » (profil de l'admin). $profil_data fournit la
    // collection complète (tous types, non filtrée) pour la mise en lumière —
    // $all_data est déjà chargé en haut de cette page (cf. load_data()).
    $profil_data = $all_data;
    require 'includes/public-profil-modal.php';
    ?>

    <!-- Éléments factices, jamais affichés : public.js (chargé ci-dessous pour
         sa modale de détail/critique/licence/profil) installe un écouteur de
         défilement infini global qui cible ces ID sans jamais vérifier leur
         présence. Sans eux, le premier scroll sur cette page lèverait une
         exception JS (page sans liste de séries paginée). Le fetch qu'il
         déclenchera une seule fois est inoffensif (aucune série mangathèque
         de type vide côté serveur) et hasMoreSeries repasse à false ensuite. -->
    <div id="series-list" style="display:none !important;"></div>
    <div class="loading-spinner" id="loading-spinner" style="display:none !important;"></div>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
        window.STATS = <?= json_encode($chart_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.ANIME_STATS = <?= json_encode($anime_chart_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.SEARCH_DATA = <?= json_encode($search_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.HAS_ANIME = <?= $has_anime ? 'true' : 'false' ?>;
        // Registre allégé des types (libellés + couleurs), pour les badges de
        // type dans les résultats de recherche — même source que partout
        // ailleurs sur le site (cf. includes/helpers.php, series_types_for_js()).
        window.seriesTypes = <?= json_encode(series_types_for_js(), JSON_UNESCAPED_UNICODE) ?>;
        // Toutes les séries visibles publiquement (tous types confondus),
        // décorées comme sur l'accueil, pour alimenter la modale de détail
        // série et ses boutons Critique/Licence — sert ici uniquement de cible
        // aux « séries coup de cœur » du profil (aucune carte de série
        // cliquable ailleurs sur cette page). public.js s'attend aussi à
        // trouver seriesData en portée globale (défilement infini de
        // l'accueil, absent de cette page) : on la fait pointer vers le même
        // pool, sans conséquence puisque ce mécanisme est neutralisé côté DOM
        // (#series-list/#loading-spinner factices ci-dessus).
        window.allSeriesData = <?= json_encode(array_values(array_map(function ($s) use ($public_series_licenses, $public_review_ids) {
            $s = decorate_series_for_display($s);
            $s['has_review'] = isset($public_review_ids[$s['id']]);
            $lic = $public_series_licenses[$s['id']] ?? null;
            $s['has_license']  = $lic !== null;
            $s['license_id']   = $lic['license_id'] ?? '';
            $s['license_name'] = $lic['license_name'] ?? '';
            return $s;
        }, $visible_data))) ?>;
        window.seriesData = window.allSeriesData;
        window.reviewsPublic  = <?= json_encode($reviews_public) ?>;
        window.licensesPublic = true;
    </script>
    <script src="assets/js/admin/main.js"></script>
    <script src="assets/js/public.js"></script>
    <script src="assets/js/stats.js"></script>
</body>
</html>

