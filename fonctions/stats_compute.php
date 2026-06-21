<?php
/**
 * stats_compute.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Moteur de calcul des statistiques de la bibliothèque.
 *
 * Toutes les données brutes (séries + tomes) sont agrégées ici en structures
 * prêtes à être consommées côté front (Chart.js / ApexCharts).
 *
 * Conventions :
 *   - $data        = sortie de load_data()    (toutes les séries)
 *   - $options     = sortie de load_options() (options du site)
 *
 * Les réglages "Statistiques" sont stockés dans l'option JSON
 * `stats_category_settings` sous la forme :
 *   {
 *     "Manga":       { "minutes": 40, "value": 7.5,  "value_collector": 15 },
 *     "Light Novel": { "minutes": 90, "value": 12,   "value_collector": 20 },
 *     ...
 *   }
 * Plus trois valeurs de repli globales :
 *   stats_default_minutes, stats_default_value, stats_default_value_collector
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ─────────────────────────────────────────────────────────────────────────────
// Helpers de formatage
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('stats_format_minutes')) {
    /** Convertit un nombre de minutes en texte lisible (jours / heures / minutes). */
    function stats_format_minutes($minutes): string {
        $minutes = (int) round($minutes);
        if ($minutes <= 0) return '0 minute';

        $hours      = intdiv($minutes, 60);
        $rem_min    = $minutes % 60;
        $days       = intdiv($hours, 24);
        $rem_hours  = $hours % 24;

        $parts = [];
        if ($days > 0)      $parts[] = $days . ' jour'   . ($days > 1 ? 's' : '');
        if ($rem_hours > 0) $parts[] = $rem_hours . ' heure'  . ($rem_hours > 1 ? 's' : '');
        if ($rem_min > 0)   $parts[] = $rem_min . ' minute' . ($rem_min > 1 ? 's' : '');

        if (count($parts) === 0) return '0 minute';
        if (count($parts) === 1) return $parts[0];
        $last = array_pop($parts);
        return implode(', ', $parts) . ' et ' . $last;
    }
}

if (!function_exists('stats_format_value')) {
    /** Formate une valeur monétaire en euros. */
    function stats_format_value($value): string {
        return number_format((float) $value, 2, ',', ' ') . ' €';
    }
}

if (!function_exists('stats_clean_list')) {
    /**
     * Nettoie une liste de chaînes (catégories, genres, contributeurs) :
     * - retire les valeurs vides / espaces
     * - retire les doublons (en préservant l'ordre)
     */
    function stats_clean_list($list): array {
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $item) {
            $item = trim((string) $item);
            if ($item === '') continue;
            if (!in_array($item, $out, true)) $out[] = $item;
        }
        return $out;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Réglages "Statistiques" (temps & valeur par catégorie)
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('stats_get_settings')) {
    /**
     * Retourne les réglages normalisés :
     *   [
     *     'categories' => [ 'Manga' => ['minutes'=>..,'value'=>..,'value_collector'=>..], ... ],
     *     'default'    => ['minutes'=>..,'value'=>..,'value_collector'=>..],
     *   ]
     */
    function stats_get_settings(array $options): array {
        $default = [
            'minutes'         => isset($options['stats_default_minutes'])         ? (float) $options['stats_default_minutes']         : 40.0,
            'value'           => isset($options['stats_default_value'])           ? (float) $options['stats_default_value']           : 7.0,
            'value_collector' => isset($options['stats_default_value_collector']) ? (float) $options['stats_default_value_collector'] : 15.0,
        ];

        $categories = [];
        if (!empty($options['stats_category_settings'])) {
            $decoded = json_decode($options['stats_category_settings'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $cat => $cfg) {
                    if (!is_array($cfg)) continue;
                    $categories[$cat] = [
                        'minutes'         => isset($cfg['minutes'])         && $cfg['minutes']         !== '' ? (float) $cfg['minutes']         : $default['minutes'],
                        'value'           => isset($cfg['value'])           && $cfg['value']           !== '' ? (float) $cfg['value']           : $default['value'],
                        'value_collector' => isset($cfg['value_collector']) && $cfg['value_collector'] !== '' ? (float) $cfg['value_collector'] : $default['value_collector'],
                    ];
                }
            }
        }

        return ['categories' => $categories, 'default' => $default];
    }
}

if (!function_exists('stats_series_averages')) {
    /**
     * Pour une série donnée, calcule la MOYENNE des réglages sur l'ensemble de
     * ses catégories (conformément au choix : "moyenne des catégories").
     * Si la série n'a pas de catégorie connue, on retombe sur les valeurs par défaut.
     */
    function stats_series_averages(array $series, array $settings): array {
        $cats = stats_clean_list($series['categories'] ?? []);
        $acc  = ['minutes' => [], 'value' => [], 'value_collector' => []];

        foreach ($cats as $cat) {
            $cfg = $settings['categories'][$cat] ?? $settings['default'];
            $acc['minutes'][]         = $cfg['minutes'];
            $acc['value'][]           = $cfg['value'];
            $acc['value_collector'][] = $cfg['value_collector'];
        }

        if (count($acc['minutes']) === 0) {
            return $settings['default'];
        }

        return [
            'minutes'         => array_sum($acc['minutes'])         / count($acc['minutes']),
            'value'           => array_sum($acc['value'])           / count($acc['value']),
            'value_collector' => array_sum($acc['value_collector']) / count($acc['value_collector']),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Calcul principal
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('compute_stats')) {
    function compute_stats(array $data, array $options): array {
        $settings = stats_get_settings($options);

        // Séparer possédé / lu ailleurs
        $owned      = array_values(array_filter($data, fn($s) => empty($s['read_elsewhere'])));
        $elsewhere  = array_values(array_filter($data, fn($s) => !empty($s['read_elsewhere'])));

        // ── Compteurs globaux ────────────────────────────────────────────────
        $total_series  = count($owned);
        $total_volumes = 0;

        $status_counts    = ['à lire' => 0, 'en cours' => 0, 'terminé' => 0];
        $collector_count  = 0;
        $loaned_volumes   = 0; // rempli par l'appelant si dispo (voir merge plus bas)

        $paused_series      = 0;
        $abandoned_series   = 0;
        $complete_series    = 0; // possédées entièrement (a un tome "dernier")
        $completed_series   = 0; // entièrement lues
        $started_not_done   = 0; // commencées mais pas terminées
        $mature_series      = 0;

        // Temps & valeur
        $time_by_status = ['à lire' => 0.0, 'en cours' => 0.0, 'terminé' => 0.0];
        $value_total      = 0.0;
        $value_collector  = 0.0;
        // Valeur ventilée par catégorie : nom => ['normal'=>x, 'collector'=>y]
        // Clé spéciale '' = séries sans catégorie.
        $value_by_category = [];

        // Agrégats par dimension
        $authors      = [];   // name => ['series'=>n, 'volumes'=>n]
        $publishers   = [];
        $genres       = [];   // name => ['series'=>n, 'volumes'=>n]
        $genres_none  = 0;    // tomes des séries sans aucun genre
        $genres_none_series = 0; // séries sans aucun genre
        $categories   = [];   // name => ['series'=>n, 'volumes'=>n]
        $contributors = [];   // name => ['series'=>n, 'volumes'=>n]

        // Séries
        $longest_series = ['name' => null, 'volumes' => 0];

        // Statut de publication (basé sur le champ status de la série)
        $status_series_counts = ['terminée' => 0, 'en cours' => 0, 'en pause' => 0, 'abandonnée' => 0];

        // Fun facts temporels
        $longest_publication = ['name' => null, 'days' => -1, 'first' => null, 'last' => null];
        $most_recently_read  = ['name' => null, 'date' => null];
        $longest_to_read     = ['name' => null, 'days' => -1, 'first' => null, 'last' => null];

        // Time series (par mois) : achats & croissance cumulée
        $purchases_by_month = []; // 'YYYY-MM' => n tomes achetés

        foreach ($owned as $series) {
            $vols      = $series['volumes'] ?? [];
            $vcount    = count($vols);
            $total_volumes += $vcount;

            if ($vcount > $longest_series['volumes']) {
                $longest_series = ['name' => $series['name'], 'volumes' => $vcount];
            }

            // Statut éditeur (publication)
            $st = $series['status'] ?? '';
            if ($st === 'en pause')    $paused_series++;
            if ($st === 'abandonnée')  $abandoned_series++;
            if (isset($status_series_counts[$st])) {
                $status_series_counts[$st]++;
            } else {
                // statut non renseigné ou inconnu → considéré "en cours"
                $status_series_counts['en cours']++;
            }

            if (!empty($series['mature'])) $mature_series++;

            // Moyennes de la série (temps/valeur)
            $avg = stats_series_averages($series, $settings);

            // Clés de catégorie de la série pour la ventilation de la valeur
            // (chaque catégorie reçoit une part égale du tome ; '' = sans catégorie)
            $series_cat_keys = stats_clean_list($series['categories'] ?? []);
            if (count($series_cat_keys) === 0) $series_cat_keys = [''];

            // Auteur
            $author = trim((string) ($series['author'] ?? ''));
            if ($author !== '') {
                if (!isset($authors[$author])) $authors[$author] = ['series' => 0, 'volumes' => 0];
                $authors[$author]['series']  += 1;
                $authors[$author]['volumes'] += $vcount;
            }

            // Éditeur
            $publisher = trim((string) ($series['publisher'] ?? ''));
            if ($publisher !== '') {
                if (!isset($publishers[$publisher])) $publishers[$publisher] = ['series' => 0, 'volumes' => 0];
                $publishers[$publisher]['series']  += 1;
                $publishers[$publisher]['volumes'] += $vcount;
            }

            // Catégories
            foreach (stats_clean_list($series['categories'] ?? []) as $cat) {
                if (!isset($categories[$cat])) $categories[$cat] = ['series' => 0, 'volumes' => 0];
                $categories[$cat]['series']  += 1;
                $categories[$cat]['volumes'] += $vcount;
            }

            // Genres
            $series_genres = stats_clean_list($series['genres'] ?? []);
            if (count($series_genres) === 0) {
                $genres_none += $vcount;
                $genres_none_series += 1;
            } else {
                foreach ($series_genres as $g) {
                    if (!isset($genres[$g])) $genres[$g] = ['series' => 0, 'volumes' => 0];
                    $genres[$g]['series']  += 1;
                    $genres[$g]['volumes'] += $vcount;
                }
            }

            // Contributeurs
            foreach (stats_clean_list($series['other_contributors'] ?? []) as $c) {
                if (!isset($contributors[$c])) $contributors[$c] = ['series' => 0, 'volumes' => 0];
                $contributors[$c]['series']  += 1;
                $contributors[$c]['volumes'] += $vcount;
            }

            // Progression de la série
            $has_last         = false;
            $last_completed   = false;
            $has_read         = false;
            $has_unread       = false;

            // Suivi des dates pour les fun facts temporels
            $first_vol_added  = null;  // added_at du tome n°1
            $last_vol_added   = null;  // added_at du tome tagué "dernier"
            $first_vol_read   = null;  // read_at du tome n°1
            $last_vol_read    = null;  // read_at du tome tagué "dernier"
            $series_max_read  = null;  // read_at le plus récent de la série

            foreach ($vols as $v) {
                $vstatus = $v['status'] ?? 'à lire';
                if (!isset($status_counts[$vstatus])) $status_counts[$vstatus] = 0;
                $status_counts[$vstatus] += 1;

                // Temps
                if (!isset($time_by_status[$vstatus])) $time_by_status[$vstatus] = 0.0;
                $time_by_status[$vstatus] += $avg['minutes'];

                // Valeur (collector = valeur collector, sinon valeur normale)
                $is_collector = !empty($v['collector']);
                $vval  = $is_collector ? $avg['value_collector'] : $avg['value'];
                if ($is_collector) {
                    $value_total     += $avg['value_collector'];
                    $value_collector += $avg['value_collector'];
                    $collector_count += 1;
                } else {
                    $value_total += $avg['value'];
                }

                // Ventilation par catégorie (part égale entre les catégories de la série)
                $cat_share = $vval / count($series_cat_keys);
                $val_key   = $is_collector ? 'collector' : 'normal';
                foreach ($series_cat_keys as $ck) {
                    if (!isset($value_by_category[$ck])) {
                        $value_by_category[$ck] = ['normal' => 0.0, 'collector' => 0.0];
                    }
                    $value_by_category[$ck][$val_key] += $cat_share;
                }

                if ($vstatus === 'terminé') $has_read = true;
                else                         $has_unread = true;

                $vnum     = (int) ($v['number'] ?? 0);
                $added    = $v['added_at'] ?? '';
                $read_at  = $v['read_at']  ?? '';
                $is_date  = fn($d) => is_string($d) && strlen($d) >= 10 && $d[4] === '-' && $d[7] === '-';

                // Dates du 1er tome
                if ($vnum === 1) {
                    if ($is_date($added))   $first_vol_added = $added;
                    if ($is_date($read_at)) $first_vol_read  = $read_at;
                }

                if (!empty($v['last'])) {
                    $has_last = true;
                    if ($vstatus === 'terminé') $last_completed = true;
                    if ($is_date($added))   $last_vol_added = $added;
                    if ($is_date($read_at)) $last_vol_read  = $read_at;
                }

                // read_at le plus récent de la série (et global)
                if ($vstatus === 'terminé' && $is_date($read_at)) {
                    if ($series_max_read === null || $read_at > $series_max_read) {
                        $series_max_read = $read_at;
                    }
                }

                // Achats par mois (added_at = 'YYYY-MM-DD')
                if (is_string($added) && strlen($added) >= 7 && $added[4] === '-') {
                    $month = substr($added, 0, 7);
                    if (!isset($purchases_by_month[$month])) $purchases_by_month[$month] = 0;
                    $purchases_by_month[$month] += 1;
                }
            }

            if ($has_last)                    $complete_series++;
            if ($has_last && $last_completed) $completed_series++;
            if ($has_read && $has_unread)     $started_not_done++;

            // ── Fun fact : plus long temps de publication ────────────────────
            // Écart entre l'ajout du 1er tome et du dernier tome (tagué "dernier").
            if ($has_last && $first_vol_added !== null && $last_vol_added !== null) {
                $days = (strtotime($last_vol_added) - strtotime($first_vol_added)) / 86400;
                if ($days >= 0 && $days > $longest_publication['days']) {
                    $longest_publication = [
                        'name'  => $series['name'],
                        'days'  => $days,
                        'first' => $first_vol_added,
                        'last'  => $last_vol_added,
                    ];
                }
            }

            // ── Fun fact : série la plus récemment lue ───────────────────────
            if ($series_max_read !== null) {
                if ($most_recently_read['date'] === null || $series_max_read > $most_recently_read['date']) {
                    $most_recently_read = ['name' => $series['name'], 'date' => $series_max_read];
                }
            }

            // ── Fun fact : série la plus longue à lire ───────────────────────
            // Écart entre la lecture du 1er tome et du dernier tome (tagué "dernier").
            if ($has_last && $first_vol_read !== null && $last_vol_read !== null) {
                $days = (strtotime($last_vol_read) - strtotime($first_vol_read)) / 86400;
                if ($days >= 0 && $days > $longest_to_read['days']) {
                    $longest_to_read = [
                        'name'  => $series['name'],
                        'days'  => $days,
                        'first' => $first_vol_read,
                        'last'  => $last_vol_read,
                    ];
                }
            }
        }

        // ── Pourcentages ────────────────────────────────────────────────────
        $pct = function ($n) use ($total_volumes) {
            return $total_volumes > 0 ? round(($n / $total_volumes) * 100, 1) : 0;
        };
        $status_pct = [
            'à lire'   => $pct($status_counts['à lire']   ?? 0),
            'en cours' => $pct($status_counts['en cours'] ?? 0),
            'terminé'  => $pct($status_counts['terminé']  ?? 0),
        ];

        $completion_pct = $total_volumes > 0
            ? round((($status_counts['terminé'] ?? 0) / $total_volumes) * 100, 1)
            : 0;
        $series_done_pct = $total_series > 0
            ? round(($completed_series / $total_series) * 100, 1)
            : 0;

        // ── Lues ailleurs ───────────────────────────────────────────────────
        $elsewhere_series  = count($elsewhere);
        $elsewhere_volumes = array_sum(array_map(fn($s) => count($s['volumes'] ?? []), $elsewhere));
        $elsewhere_minutes = 0.0;
        foreach ($elsewhere as $s) {
            $avg = stats_series_averages($s, $settings);
            $elsewhere_minutes += count($s['volumes'] ?? []) * $avg['minutes'];
        }

        // ── Tri & top N des dimensions ──────────────────────────────────────
        $to_sorted = function (array $assoc, string $key) {
            $rows = [];
            foreach ($assoc as $name => $vals) {
                $rows[] = is_array($vals)
                    ? ['name' => $name, 'series' => $vals['series'] ?? 0, 'volumes' => $vals['volumes'] ?? 0]
                    : ['name' => $name, 'volumes' => $vals];
            }
            usort($rows, fn($a, $b) => ($b[$key] ?? 0) <=> ($a[$key] ?? 0));
            return $rows;
        };

        $authors_sorted      = $to_sorted($authors, 'volumes');
        $publishers_sorted   = $to_sorted($publishers, 'volumes');
        $genres_sorted       = $to_sorted($genres, 'volumes');
        $categories_sorted   = $to_sorted($categories, 'volumes');
        $contributors_sorted = $to_sorted($contributors, 'volumes');

        // Tris alternatifs (par nombre de séries) pour auteurs/éditeurs/contributeurs
        $authors_by_series      = $to_sorted($authors, 'series');
        $publishers_by_series   = $to_sorted($publishers, 'series');
        $contributors_by_series = $to_sorted($contributors, 'series');

        // ── Valeur ventilée par catégorie (normal + collector) ──────────────
        // Ordonnée par valeur totale décroissante ; 'sans catégorie' placé en fin.
        $value_categories = [];
        foreach ($value_by_category as $ck => $vals) {
            $value_categories[] = [
                'name'      => $ck === '' ? 'Sans catégorie' : $ck,
                'is_none'   => $ck === '',
                'normal'    => round($vals['normal'], 2),
                'collector' => round($vals['collector'], 2),
                'total'     => round($vals['normal'] + $vals['collector'], 2),
            ];
        }
        usort($value_categories, function ($a, $b) {
            if ($a['is_none'] !== $b['is_none']) return $a['is_none'] <=> $b['is_none'];
            return $b['total'] <=> $a['total'];
        });

        // ── Croissance cumulée ──────────────────────────────────────────────
        ksort($purchases_by_month);
        $growth = [];
        $running = 0;
        foreach ($purchases_by_month as $month => $n) {
            $running += $n;
            $growth[] = ['month' => $month, 'value' => $running];
        }
        $purchases_series = [];
        foreach ($purchases_by_month as $month => $n) {
            $purchases_series[] = ['month' => $month, 'value' => $n];
        }

        // ── Fun facts ───────────────────────────────────────────────────────
        // Auteur le plus représenté — en tomes
        $top_author          = $authors_sorted[0]['name']       ?? null;
        $top_author_volumes  = $authors_sorted[0]['volumes']    ?? 0;
        $top_author_series   = $authors_sorted[0]['series']     ?? 0;
        // Auteur le plus représenté — en séries
        $top_author_s_name   = $authors_by_series[0]['name']    ?? null;
        $top_author_s_series = $authors_by_series[0]['series']  ?? 0;
        $top_author_s_vol    = $authors_by_series[0]['volumes'] ?? 0;

        // Éditeur dominant — en tomes
        $top_publisher          = $publishers_sorted[0]['name']    ?? null;
        $top_publisher_volumes  = $publishers_sorted[0]['volumes'] ?? 0;
        $top_publisher_series   = $publishers_sorted[0]['series']  ?? 0;
        // Éditeur dominant — en séries
        $top_publisher_s_name   = $publishers_by_series[0]['name']    ?? null;
        $top_publisher_s_series = $publishers_by_series[0]['series']  ?? 0;
        $top_publisher_s_vol    = $publishers_by_series[0]['volumes'] ?? 0;

        // Part du top 10 auteurs (en tomes)
        $top10_slice       = array_slice($authors_sorted, 0, 10);
        $top10_authors_n   = count($top10_slice);
        $top10_authors_vol = array_sum(array_map(fn($a) => $a['volumes'], $top10_slice));
        $top10_authors_pct = $total_volumes > 0 ? round(($top10_authors_vol / $total_volumes) * 100, 1) : 0;

        // Part du top 10 auteurs (en séries)
        $top10_slice_s        = array_slice($authors_by_series, 0, 10);
        $top10_authors_s_n    = count($top10_slice_s);
        $top10_authors_s_ser  = array_sum(array_map(fn($a) => $a['series'], $top10_slice_s));
        $top10_authors_s_pct  = $total_series > 0 ? round(($top10_authors_s_ser / $total_series) * 100, 1) : 0;

        // Indice de diversité de Shannon (sur les auteurs, en tomes)
        $shannon = 0.0;
        if ($total_volumes > 0) {
            foreach ($authors_sorted as $a) {
                $p = $a['volumes'] / $total_volumes;
                if ($p > 0) $shannon -= $p * log($p);
            }
            $shannon = round($shannon, 2);
        }
        // Évenness (équirépartition) : Shannon normalisé sur [0,1] par log(nb auteurs)
        $n_authors = count($authors_sorted);
        $shannon_even = $n_authors > 1 ? round($shannon / log($n_authors), 2) : ($n_authors === 1 ? 0.0 : null);

        // Normalisation des fun facts temporels (sentinelle -1 → null)
        if ($longest_publication['days'] < 0) $longest_publication = ['name' => null, 'days' => null, 'first' => null, 'last' => null];
        if ($longest_to_read['days']     < 0) $longest_to_read     = ['name' => null, 'days' => null, 'first' => null, 'last' => null];

        return [
            // KPI collection
            'total_series'      => $total_series,
            'total_volumes'     => $total_volumes,
            'total_authors'     => count($authors),
            'total_publishers'  => count($publishers),
            'total_genres'      => count($genres),
            'total_categories'  => count($categories),
            'total_contributors'=> count($contributors),

            // KPI lecture (tomes)
            'status_counts'     => $status_counts,
            'status_pct'        => $status_pct,
            'collector_count'   => $collector_count,
            'completion_pct'    => $completion_pct,

            // KPI progression séries
            'complete_series'   => $complete_series,
            'completed_series'  => $completed_series,
            'started_not_done'  => $started_not_done,
            'series_done_pct'   => $series_done_pct,
            'paused_series'     => $paused_series,
            'abandoned_series'  => $abandoned_series,
            'mature_series'     => $mature_series,

            // Statuts de publication (séries possédées)
            'status_series_counts' => $status_series_counts,

            // Lues ailleurs
            'elsewhere_series'  => $elsewhere_series,
            'elsewhere_volumes' => $elsewhere_volumes,
            'elsewhere_minutes' => $elsewhere_minutes,

            // Temps
            'time_by_status'    => $time_by_status,
            'time_total'        => array_sum($time_by_status),

            // Valeur
            'value_total'       => $value_total,
            'value_collector'   => $value_collector,
            'value_normal'      => $value_total - $value_collector,
            'value_categories'  => $value_categories,

            // Dimensions (triées, complètes — le front coupera en topN)
            'authors'           => $authors_sorted,
            'publishers'        => $publishers_sorted,
            'genres'            => $genres_sorted,
            'genres_none'       => $genres_none,
            'genres_none_series' => $genres_none_series,
            'categories'        => $categories_sorted,
            'contributors'      => $contributors_sorted,

            // Temporel
            'purchases_by_month'=> $purchases_series,
            'growth'            => $growth,

            // Séries
            'longest_series'    => $longest_series,

            // Fun facts
            'top_author'             => $top_author,
            'top_author_volumes'     => $top_author_volumes,
            'top_author_series'      => $top_author_series,
            'top_author_s_name'      => $top_author_s_name,
            'top_author_s_series'    => $top_author_s_series,
            'top_author_s_vol'       => $top_author_s_vol,
            'top_publisher'          => $top_publisher,
            'top_publisher_volumes'  => $top_publisher_volumes,
            'top_publisher_series'   => $top_publisher_series,
            'top_publisher_s_name'   => $top_publisher_s_name,
            'top_publisher_s_series' => $top_publisher_s_series,
            'top_publisher_s_vol'    => $top_publisher_s_vol,
            'top10_authors_pct'      => $top10_authors_pct,
            'top10_authors_n'        => $top10_authors_n,
            'top10_authors_vol'      => $top10_authors_vol,
            'top10_authors_s_pct'    => $top10_authors_s_pct,
            'top10_authors_s_n'      => $top10_authors_s_n,
            'top10_authors_s_ser'    => $top10_authors_s_ser,
            'longest_publication'    => $longest_publication,
            'most_recently_read'     => $most_recently_read,
            'longest_to_read'        => $longest_to_read,
            'shannon'                => $shannon,
            'shannon_even'           => $shannon_even,

            // Moyennes utiles
            'avg_series_per_author'    => count($authors) > 0 ? round($total_series / count($authors), 2) : 0,
            'avg_volumes_per_author'   => count($authors) > 0 ? round($total_volumes / count($authors), 2) : 0,
            'avg_series_per_publisher' => count($publishers) > 0 ? round($total_series / count($publishers), 2) : 0,
            'avg_volumes_per_publisher'=> count($publishers) > 0 ? round($total_volumes / count($publishers), 2) : 0,
            'avg_series_per_contributor'  => count($contributors) > 0 ? round(array_sum(array_map(fn($c) => $c['series'], $contributors)) / count($contributors), 2) : 0,
            'avg_volumes_per_contributor' => count($contributors) > 0 ? round($total_volumes / count($contributors), 2) : 0,
        ];
    }
}
