<?php
require 'config.php';
require_once 'fonctions/reviews.php';
require_once 'fonctions/licenses.php';
require_once 'includes/themes.php';
require_once 'includes/helpers.php';
// Anilist : uniquement pour anilist_format_label(), utilisé par
// decorate_series_for_display() sur les séries animées.
require_once 'includes/anilist.php';

$data    = load_data();
$options = load_options();

$page_title = $options['history_page_title'] ?? ($options['site_name'] ?? 'Historique');

// ── Visible publiquement ? ───────────────────────────────────────────────────
// Réglage global (indépendant du mode privé par collection) : l'historique
// mélange volontairement les deux collections, donc son interrupteur est
// unique, dans les options générales du site.
$history_hidden = !empty($options['hide_history']);

if ($history_hidden) {
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
            <p style="text-align:center;">Cette page n'est pas accessible publiquement.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── Séries visibles publiquement (respecte le mode privé / masquage mature
// propre à CHAQUE collection, comme partout ailleurs côté public) ───────────
$visible_data = array_values(array_filter($data, function ($series) use ($options) {
    $t = series_type($series);
    if (is_private_mode($options, $t)) return false;
    if (is_hide_mature($options, $t) && !empty($series['mature'])) return false;
    return true;
}));

// Critiques et licences (pour les boutons de la modale de détail, réutilisée
// telle quelle depuis index.php / public.js).
$public_review_ids = [];
foreach (series_type_keys() as $__t) {
    if (!is_private_mode($options, $__t) && !is_hide_reviews($options, $__t)) {
        // Les ID de critiques ne sont pas typés : on les ajoute une fois,
        // la restriction par collection est déjà faite via $visible_data.
        $public_review_ids = array_flip(get_review_series_ids());
        break;
    }
}
$public_series_licenses = get_series_license_map();
$admin_pseudo = trim($options['admin_pseudo'] ?? '');
// Les critiques sont considérées publiques ici si au moins une des deux
// collections les autorise (le détail par série est de toute façon vérifié
// par l'endpoint index.php?get_review=... lui-même).
$reviews_public = !is_hide_reviews($options, 'manga') || !is_hide_reviews($options, 'anime');

// ── Filtre de type (manga / anime / les deux) ────────────────────────────────
$type_filter = $_GET['type'] ?? '';
$type_filter = in_array($type_filter, ['manga', 'anime'], true) ? $type_filter : '';

// Écrit un nombre en ordinal français court : 1 → "1er", 2 → "2ème", etc.
function history_ordinal(int $n): string {
    return $n === 1 ? '1er' : $n . 'ème';
}

// ── Construction du journal : date => [ ['series' => …, 'items' => […]] ] ───
// Un tome/épisode compte dans l'historique s'il est marqué "terminé" et
// possède une date de lecture/visionnage (read_at, format Y-m-d).
//
// Une série apparaît en plus, ce même journal, à la date de sa dernière
// relecture/revisionnage (reread_last_date / rewatch_last_date, cf.
// config.php) — dans une entrée à part (kind: 'reread'), jamais fusionnée
// avec l'entrée « tomes/épisodes » du jour, même si les deux tombent le même
// jour pour la même série. Ce champ n'est renseigné que par les
// AUGMENTATIONS du compteur constatées après l'introduction de la
// fonctionnalité : une série déjà relue avant cette date n'a donc aucune date
// connue et n'apparaît pas ici pour ce motif.
function history_build_entries(array $series_list, string $type_filter): array {
    $by_date = [];
    foreach ($series_list as $series) {
        $t = series_type($series);
        if ($type_filter !== '' && $t !== $type_filter) continue;

        $done = type_vocab($t, 'done'); // "terminé"
        $items_by_date = [];
        foreach ($series['volumes'] ?? [] as $v) {
            if (($v['status'] ?? '') !== $done) continue;
            $read_at = trim((string)($v['read_at'] ?? ''));
            if ($read_at === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $read_at)) continue;
            $date = substr($read_at, 0, 10);
            $items_by_date[$date][] = $v;
        }

        $decorated = null; // décoré paresseusement, seulement si nécessaire

        if (!empty($items_by_date)) {
            $decorated = decorate_series_for_display($series);
            foreach ($items_by_date as $date => $items) {
                usort($items, fn($a, $b) => $a['number'] <=> $b['number']);
                $by_date[$date][] = [
                    'kind'   => 'volumes',
                    'series' => $decorated,
                    'items'  => $items,
                ];
            }
        }

        // Relecture / revisionnage : clé et compteur adaptés au type de série.
        $is_anime_series = is_anime($series);
        $rereread_date = trim((string)($is_anime_series
            ? ($series['rewatch_last_date'] ?? '')
            : ($series['reread_last_date'] ?? '')));
        $rereread_count = (int)($is_anime_series
            ? ($series['rewatch_count'] ?? 0)
            : ($series['reread_count'] ?? 0));

        if ($rereread_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $rereread_date) && $rereread_count > 0) {
            if ($decorated === null) {
                $decorated = decorate_series_for_display($series);
            }
            $date = substr($rereread_date, 0, 10);
            $by_date[$date][] = [
                'kind'    => 'reread',
                'series'  => $decorated,
                'is_anime' => $is_anime_series,
                'count'   => $rereread_count,
                'label'   => history_ordinal($rereread_count) . ' ' . ($is_anime_series ? 'revisionnage' : 'relecture'),
            ];
        }
    }

    // Trie chaque jour par nom de série, puis les jours par date décroissante.
    foreach ($by_date as $date => &$entries) {
        usort($entries, fn($a, $b) => strcasecmp($a['series']['name'], $b['series']['name']));
    }
    unset($entries);

    krsort($by_date); // dates décroissantes (chaîne AAAA-MM-JJ triable telle quelle)
    return $by_date;
}

// Regroupe une tranche de $by_date (associatif date => entries) en une liste
// ordonnée de jours [ ['date' => …, 'entries' => […]] ], prête pour le rendu
// (HTML ou JSON) — évite de manipuler des clés associatives côté JS.
function history_slice_days(array $by_date, int $offset, int $limit): array {
    $dates = array_keys($by_date);
    $slice = array_slice($dates, $offset, $limit);
    $out = [];
    foreach ($slice as $date) {
        $out[] = ['date' => $date, 'entries' => $by_date[$date]];
    }
    return $out;
}

// Formate une date AAAA-MM-JJ en français lisible ("Lundi 12 janvier 2026").
function history_format_date(string $date): string {
    $ts = strtotime($date);
    if ($ts === false) return $date;
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois  = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
              'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $jour_semaine = $jours[(int)date('w', $ts)];
    $jour = (int)date('j', $ts);
    $mois_nom = $mois[(int)date('n', $ts)];
    $annee = date('Y', $ts);
    return ucfirst($jour_semaine) . ' ' . $jour . ' ' . $mois_nom . ' ' . $annee;
}

// Condense une liste de numéros triés en plages lisibles : [1,2,3,4,5,8,10,11]
// devient "1 à 5, 8, 10 et 11". Une paire consécutive (ex. [10,11]) s'écrit
// "10 et 11" plutôt que "10 à 11", plus naturel à deux éléments.
function history_format_numbers(array $numbers): string {
    $numbers = array_values(array_unique($numbers));
    sort($numbers, SORT_NUMERIC);
    if (empty($numbers)) return '';

    $ranges = [];
    $start = $prev = $numbers[0];
    for ($i = 1; $i < count($numbers); $i++) {
        $n = $numbers[$i];
        if ($n === $prev + 1) {
            $prev = $n;
            continue;
        }
        $ranges[] = [$start, $prev];
        $start = $prev = $n;
    }
    $ranges[] = [$start, $prev];

    $parts = array_map(function ($r) {
        [$a, $b] = $r;
        if ($a === $b)     return (string)$a;
        if ($b === $a + 1) return "$a et $b";
        return "$a à $b";
    }, $ranges);

    return implode(', ', $parts);
}

// Normalisation dédiée à la recherche de la page Historique : accents et
// casse neutralisés, mais espaces CONSERVÉS (contrairement à
// normalize_string() de includes/helpers.php, qui les supprime aussi — utile
// pour un identifiant technique, mais qui casserait ici la recherche
// multi-mots, ex. "one piece" ne devant pas dépendre de l'absence d'espace).
function history_normalize_search(string $string): string {
    $table = [
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE',
        'Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Ì'=>'I','Í'=>'I',
        'Î'=>'I','Ï'=>'I','Ð'=>'D','Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O',
        'Õ'=>'O','Ö'=>'O','Ø'=>'O','Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
        'Ý'=>'Y','ß'=>'s','à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
        'å'=>'a','æ'=>'ae','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ð'=>'d','ñ'=>'n','ò'=>'o',
        'ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ù'=>'u','ú'=>'u',
        'û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','Ŕ'=>'R','ŕ'=>'r',
    ];
    $string = strtr($string, $table);
    $string = mb_strtolower($string, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $string));
}

// ──────────────────────────────────────────────────────────────────────────────
// Rendu HTML d'un jour (bloc date + cartes série), utilisé à la fois pour le
// rendu initial et par l'endpoint AJAX get_more_days (JSON contenant le HTML
// déjà rendu : le front n'a donc aucun template à dupliquer en JS).
// ──────────────────────────────────────────────────────────────────────────────
function history_render_day(array $day): string {
    ob_start();
    ?>
    <section class="history-day" data-date="<?= htmlspecialchars($day['date']) ?>">
        <h2 class="history-day-title"><?= htmlspecialchars(history_format_date($day['date'])) ?></h2>
        <div class="history-day-cards">
            <?php foreach ($day['entries'] as $entry):
                $series = $entry['series'];
                $is_anime = is_anime($series);
                // Titres alternatifs (animés) inclus dans un attribut data-*
                // dédié : sert de terrain de recherche côté JS, sans jamais
                // s'afficher — seul le titre choisi reste visible sur la carte.
                $search_haystack = history_normalize_search($series['name'] . ' ' . implode(' ', $series['alt_titles'] ?? []));
            ?>
                <?php if (($entry['kind'] ?? 'volumes') === 'reread'): ?>
                    <!-- Carte dédiée : relecture (manga) ou revisionnage (animé) -->
                    <div class="history-card history-card--reread <?= $is_anime ? 'history-card--anime' : '' ?>"
                         data-series-id="<?= htmlspecialchars($series['id']) ?>"
                         data-search="<?= htmlspecialchars($search_haystack) ?>">
                        <img class="history-card-thumb" src="<?= htmlspecialchars(series_thumbnail($series)) ?>" alt="" loading="lazy">
                        <div class="history-card-info">
                            <p class="history-card-name"><?= htmlspecialchars($series['name']) ?></p>
                            <p class="history-card-items history-card-items--reread">
                                <img src="https://api.iconify.design/mdi/repeat.svg?color=%23808090" width="14" height="14" alt="" class="history-reread-icon">
                                <?= htmlspecialchars(ucfirst($entry['label'])) ?>
                            </p>
                        </div>
                    </div>
                <?php else:
                    $items  = $entry['items'];
                    $vocab = type_vocab($series['type']);
                    $numbers = array_map(fn($v) => $v['number'], $items);
                ?>
                    <div class="history-card <?= $is_anime ? 'history-card--anime' : '' ?>"
                         data-series-id="<?= htmlspecialchars($series['id']) ?>"
                         data-search="<?= htmlspecialchars($search_haystack) ?>">
                        <img class="history-card-thumb" src="<?= htmlspecialchars(series_thumbnail($series)) ?>" alt="" loading="lazy">
                        <div class="history-card-info">
                            <p class="history-card-name"><?= htmlspecialchars($series['name']) ?></p>
                            <p class="history-card-items">
                                <?= count($items) > 1 ? htmlspecialchars(ucfirst($vocab['items'])) : htmlspecialchars(ucfirst($vocab['item'])) ?>
                                <?= htmlspecialchars(history_format_numbers($numbers)) ?>
                                <?= count($items) > 1 ? htmlspecialchars($vocab['done_short'] === 'lu' ? 'lus' : 'vus') : htmlspecialchars($vocab['done_short']) ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

$days_per_page = 30;

// ── Endpoint AJAX : charge $days_per_page jours supplémentaires ─────────────
if (isset($_GET['get_more_days'])) {
    header('Content-Type: application/json');
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $ep_type_filter = $_GET['type'] ?? '';
    $ep_type_filter = in_array($ep_type_filter, ['manga', 'anime'], true) ? $ep_type_filter : '';

    $by_date  = history_build_entries($visible_data, $ep_type_filter);
    $total    = count($by_date);
    $days     = history_slice_days($by_date, $offset, $days_per_page);

    // HTML déjà rendu côté serveur (mêmes fonctions que le rendu initial) :
    // le front n'a aucun template à dupliquer, il n'a qu'à insérer le HTML.
    $html = '';
    foreach ($days as $day) {
        $html .= history_render_day($day);
    }

    echo json_encode([
        'success'  => true,
        'html'     => $html,
        'count'    => count($days),
        'has_more' => ($offset + $days_per_page) < $total,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$history = history_build_entries($visible_data, $type_filter);
$total_days = count($history);
$initial_days = history_slice_days($history, 0, $days_per_page);
$has_more = $total_days > $days_per_page;
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
<body class="with-sidebar history-page">
    <?php include 'includes/public-sidebar.php'; ?>
    <div class="container">
        <h1><?= htmlspecialchars($page_title) ?></h1>

        <!-- Filtre de type (manga / anime / les deux) + recherche par série -->
        <div class="history-filters">
            <div class="history-type-toggle" role="group" aria-label="Filtrer par collection">
                <button type="button" class="history-type-btn <?= $type_filter === '' ? 'is-active' : '' ?>" data-history-type="">
                    Tout
                </button>
                <button type="button" class="history-type-btn <?= $type_filter === 'manga' ? 'is-active' : '' ?>" data-history-type="manga" style="--type-color: <?= htmlspecialchars(type_color('manga')) ?>">
                    <img src="https://api.iconify.design/mdi/bookshelf.svg?color=<?= rawurlencode(type_color('manga')) ?>" width="16" height="16" alt="">
                    Mangathèque
                </button>
                <button type="button" class="history-type-btn <?= $type_filter === 'anime' ? 'is-active' : '' ?>" data-history-type="anime" style="--type-color: <?= htmlspecialchars(type_color('anime')) ?>">
                    <img src="https://api.iconify.design/mdi/television-classic.svg?color=<?= rawurlencode(type_color('anime')) ?>" width="16" height="16" alt="">
                    Animethèque
                </button>
            </div>

            <div class="history-search-wrap" id="history-search-wrap">
                <input type="search"
                       id="history-search-input"
                       class="history-search-input"
                       placeholder="Rechercher une série..."
                       autocomplete="off">
                <button type="button" class="history-search-clear" id="history-search-clear" aria-label="Effacer la recherche">&times;</button>
            </div>
            <p class="history-search-status" id="history-search-status"></p>
        </div>

        <!-- Journal, jour après jour -->
        <div class="history-timeline" id="history-timeline">
            <?php if (empty($initial_days)): ?>
                <p class="history-empty" id="history-empty-message">Rien à afficher pour le moment.</p>
            <?php else: ?>
                <?php foreach ($initial_days as $day): ?>
                    <?php echo history_render_day($day); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="history-load-more-wrap">
            <button type="button" id="history-load-more" class="button button-ext" <?= $has_more ? '' : 'hidden' ?>>
                Afficher plus
            </button>
            <p class="loading-spinner" id="history-loading-spinner">Chargement…</p>
        </div>

        <!-- Modale pour afficher les détails d'une série (réutilise le rendu et
             les scripts de la page d'accueil : mêmes ID, mêmes fonctions JS). -->
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
    </div>

    <?php
    // Modale « Qui suis-je ? » (profil de l'admin). $profil_data fournit la
    // collection complète pour la mise en lumière — les mêmes séries que
    // celles chargées plus haut dans $data (avant tout filtrage par visibilité,
    // le fragment applique lui-même $visible_only=true).
    $profil_data = $data;
    require 'includes/public-profil-modal.php';
    ?>

    <!-- Éléments factices, jamais affichés : public.js (chargé ci-dessous pour
         sa modale de détail/critique/licence/profil) installe un écouteur de
         défilement infini global qui cible ces ID génériques sans jamais
         vérifier leur présence — un mécanisme propre à l'accueil (index.php),
         sans rapport avec la pagination par jour de cette page (gérée par
         assets/js/historique.js, sur #history-timeline/#history-load-more).
         Sans eux, le premier scroll ici lèverait une exception JS. -->
    <div id="series-list" style="display:none !important;"></div>
    <div class="loading-spinner" id="loading-spinner" style="display:none !important;"></div>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <script>
        // Toutes les séries visibles publiquement (tous types confondus),
        // décorées comme sur l'accueil, pour alimenter la modale de détail et
        // ses boutons Critique/Licence — même mécanique que index.php.
        window.allSeriesData = <?= json_encode(array_values(array_map(function ($s) use ($public_series_licenses, $public_review_ids) {
            $s = decorate_series_for_display($s);
            $s['has_review'] = isset($public_review_ids[$s['id']]);
            $lic = $public_series_licenses[$s['id']] ?? null;
            $s['has_license']  = $lic !== null;
            $s['license_id']   = $lic['license_id'] ?? '';
            $s['license_name'] = $lic['license_name'] ?? '';
            return $s;
        }, $visible_data))) ?>;
        // seriesData est utilisé par certaines fonctions partagées de public.js ;
        // sur cette page, il pointe vers le même pool que allSeriesData (pas de
        // notion de "collection actuellement affichée" ici).
        window.seriesData = window.allSeriesData;
        window.reviewsPublic  = <?= json_encode($reviews_public) ?>;
        window.licensesPublic = true;
        window.seriesTypes = <?= json_encode(series_types_for_js(), JSON_UNESCAPED_UNICODE) ?>;

        // Données propres à l'historique (chargement incrémental des jours).
        window.HISTORY = {
            offset:    <?= count($initial_days) ?>,
            hasMore:   <?= $has_more ? 'true' : 'false' ?>,
            typeFilter: <?= json_encode($type_filter) ?>,
            perPage:   <?= (int)$days_per_page ?>
        };
    </script>
    <script src="assets/js/admin/main.js"></script>
    <script src="assets/js/public.js"></script>
    <script src="assets/js/historique.js"></script>
</body>
</html>
