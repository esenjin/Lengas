<?php
require 'config.php';
require_once 'fonctions/reviews.php';
require_once 'fonctions/licenses.php';
require_once 'includes/themes.php';
require_once 'includes/status_filter.php';
require_once 'includes/custom_icons.php';
// Connecteur Anilist : seules ses tables de correspondance servent ici
// (libellés de format). Aucun appel réseau n'est fait depuis la page publique.
require_once 'includes/anilist.php';
// Registre central des types. Les fonctions series_latest_date(), sort_series()
// et normalize_string() définies plus bas dans ce fichier priment sur celles de
// helpers.php (déclarations de haut niveau, compilées avant ce require) : la
// recherche publique conserve donc exactement son comportement.
require_once 'includes/helpers.php';
require_once 'includes/opengraph.php';

$data = load_data();
$options = load_options();

// ── Collection affichée (Mangathèque / Animethèque) ──────────────────────────
$current_type = sanitize_series_type($_GET['type'] ?? '');

// ── Visibilité (bloc 14 : réglages scindés par collection) ───────────────────
// Mode privé, masquage des séries matures et masquage des critiques sont
// désormais réglés indépendamment pour chaque collection (cf. helpers.php).
$is_private   = is_private_mode($options, $current_type);
$hide_mature  = is_hide_mature($options, $current_type);

// Critiques visibles publiquement ? (option "cacher les critiques", par collection)
$reviews_public = !$is_private && !is_hide_reviews($options, $current_type);
$public_review_ids = $reviews_public ? array_flip(get_review_series_ids()) : [];
$admin_pseudo = trim($options['admin_pseudo'] ?? '');

// ── Licences (bouton « Licence » de la modale de détail) ─────────────────────
// Contrairement aux critiques (un seul jeu d'ID, filtré une fois pour la
// collection affichée), une licence peut mélanger manga et animé : la
// visibilité ne peut donc pas se résumer au mode privé de $current_type. Le
// filtrage se fait ici par SÉRIE (chacune avec son propre type), aussi bien
// pour la décoration de $data que pour l'endpoint ci-dessous — jamais par
// collection entière comme $is_private le ferait pour les critiques.
$public_series_licenses = get_series_license_map();

// Endpoint : détail d'une licence (séries membres, ordonnées). Interrogé par
// la modale publique « Séries de la licence ».
if (isset($_GET['get_license'])) {
    header('Content-Type: application/json');
    $license_id = $_GET['license_id'] ?? '';
    $series     = get_public_license_series($data, $license_id);
    // Un animé n'a pas de mode privé propre distinct : la collection dont
    // relève CHAQUE série membre doit rester masquée si son mode privé (ou son
    // masquage des séries matures) à elle est actif — une licence peut
    // mélanger manga et animé, chacun avec son propre réglage de visibilité.
    $series = array_values(array_filter($series, function ($s) use ($options, $data) {
        if (is_private_mode($options, $s['type'])) return false;
        if (is_hide_mature($options, $s['type'])) {
            foreach ($data as $full) {
                if ($full['id'] === $s['id']) return empty($full['mature']);
            }
        }
        return true;
    }));
    echo json_encode(['success' => true, 'series' => $series]);
    exit;
}

// ── Profil de l'administrateur (pour la modale « Qui suis-je ? ») ─────────────
$profil_avatar = trim($options['admin_avatar'] ?? '');
$profil_pseudo = trim($options['admin_pseudo'] ?? '');
$profil_bio    = (string)($options['admin_bio'] ?? '');
$profil_social = profil_get_social_links($options);
$profil_has_avatar = ($profil_avatar !== '' && file_exists($profil_avatar));
// Mise en lumière : uniquement les séries dont la collection n'est pas
// masquée (mode privé / masquage mature), tous types confondus. Calculée ici
// (avant tout filtrage de $data par $current_type) car une mise en lumière
// peut mélanger manga et animé.
$profil_highlights = profil_highlighted_series($data, $options, true);
$has_profil = ($profil_pseudo !== '' || trim($profil_bio) !== '' ||
               $profil_has_avatar || !empty($profil_social) ||
               !empty($profil_highlights['manga']) || !empty($profil_highlights['anime']));

// Récupère la date la plus récente (added_at ou read_at) parmi les tomes d'une série
function series_latest_date($series, $field) {
    $dates = [];
    foreach ($series['volumes'] ?? [] as $v) {
        if (!empty($v[$field])) {
            $dates[] = $v[$field];
        }
    }
    return empty($dates) ? '0000-00-00' : max($dates);
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
$per_page_public = 120;
$offset = ($page - 1) * $per_page_public;


// Endpoint pour la pagination infinie
if (isset($_GET['get_paginated_series'])) {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 12;
    $search_term = $_GET['search'] ?? '';
    $sort_by = $_GET['sort_by'] ?? 'name';
    $sort_order = $_GET['sort_order'] ?? 'asc';
    $status_filter = $_GET['status_filter'] ?? '';
    $status_mode   = $_GET['status_mode'] ?? 'or';
    $type_filter   = sanitize_series_type($_GET['type'] ?? '');

    // Collection privée : masquage total, aucune série ne remonte (endpoint
    // interrogé même si la page HTML affiche déjà le message de collection privée).
    if (is_private_mode($options, $type_filter)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'series' => [], 'has_more' => false]);
        exit;
    }

    // Applique le type, la recherche et le tri à chaque requête
    $filtered_data = series_of_type($data, $type_filter);
    if (is_hide_mature($options, $type_filter)) {
        $filtered_data = array_filter($filtered_data, function ($series) {
            return !($series['mature'] ?? false);
        });
    }
    if (!empty($search_term)) {
        $normalized_search = normalize_string($search_term);
        $filtered_data = array_filter($filtered_data, function($series) use ($normalized_search) {
            return series_matches_search($series, $normalized_search);
        });
    }

    // Recalculés pour $type_filter : en pratique toujours égal à $current_type
    // (transmis par un champ caché du formulaire), mais on ne suppose rien.
    $ep_reviews_public   = is_hide_reviews($options, $type_filter) ? false : true;
    $ep_public_review_ids = $ep_reviews_public ? array_flip(get_review_series_ids()) : [];

    $filtered_data = apply_status_filter(
        $filtered_data,
        $status_filter,
        $status_mode,
        function($series) use ($ep_public_review_ids, $ep_reviews_public) {
            return $ep_reviews_public && isset($ep_public_review_ids[$series['id']]);
        },
        $type_filter
    );

    // Trie les résultats filtrés
    sort_series($filtered_data, $sort_by, $sort_order);

    // Paginer les résultats filtrés
    $offset = ($page - 1) * $per_page;
    $paginated_data = array_slice($filtered_data, $offset, $per_page);

    // Marque les séries possédant une critique visible et ajoute les champs
    // d'affichage (vignette résolue, studios, format, éditions).
    $ep_public_series_licenses = get_series_license_map();
    $paginated_data = array_map(function ($s) use ($ep_public_review_ids, $ep_public_series_licenses) {
        $s = decorate_series_for_display($s);
        $s['has_review'] = isset($ep_public_review_ids[$s['id']]);
        $lic = $ep_public_series_licenses[$s['id']] ?? null;
        $s['has_license']   = $lic !== null;
        $s['license_id']    = $lic['license_id'] ?? '';
        $s['license_name']  = $lic['license_name'] ?? '';
        return $s;
    }, array_values($paginated_data));

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'series' => $paginated_data,
        'has_more' => ($offset + $per_page) < count($filtered_data)
    ]);
    exit;
}

// ── Endpoint : suggestions d'autocomplétion pour la barre de recherche ──────
// La barre de recherche TRAVERSE les collections : avec with_types=1, chaque
// suggestion indique les types où elle apparaît, ce qui permet au front
// d'afficher un badge coloré et de basculer de vue à la sélection.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_suggestions'])) {
    $all_data = load_data();

    // La recherche traverse les collections : chaque réglage de visibilité
    // (mode privé, séries matures) s'applique selon le type propre à CHAQUE
    // série, pas selon un seul type global — une collection privée doit
    // disparaître entièrement des suggestions, l'autre rester cherchable.
    $all_data = array_filter($all_data, function ($series) use ($options) {
        if (is_private_mode($options, series_type($series))) return false;
        if (is_hide_mature($options, series_type($series)) && !empty($series['mature'])) return false;
        return true;
    });

    $field = $_GET['field'] ?? '';
    $term  = trim($_GET['term'] ?? '');
    $normalizedTerm = normalize_string($term);

    $restrict_type = (isset($_GET['restrict_type']) && $_GET['restrict_type'] !== '')
        ? sanitize_series_type($_GET['restrict_type'])
        : '';
    $with_types = !empty($_GET['with_types']);

    if ($restrict_type !== '') {
        $all_data = series_of_type($all_data, $restrict_type);
    }

    // valeur => types où elle apparaît
    $suggestions = [];

    if (in_array($field, ['name', 'author', 'publisher', 'other_contributors', 'categories', 'genres', 'studios', 'alt_titles'], true)) {
        foreach ($all_data as $series) {
            $series_type = series_type($series);

            // Studios et titres alternatifs sont propres aux animés : pas une
            // colonne directement lisible comme les autres champs (cf. le
            // même correctif dans admin.php).
            if ($field === 'studios') {
                if (!is_anime($series)) continue;
                $values = (array)($series['studios'] ?? []);
            } elseif ($field === 'alt_titles') {
                if (!is_anime($series)) continue;
                $values = series_alt_titles($series);
            } else {
                if (!isset($series[$field])) continue;
                $values = is_array($series[$field]) ? $series[$field] : [$series[$field]];
            }

            foreach ($values as $value) {
                $value = trim((string)$value);
                if ($value === '') continue;
                if (!str_contains(normalize_string($value), $normalizedTerm)) continue;

                if (!isset($suggestions[$value])) {
                    $suggestions[$value] = [];
                }
                if (!in_array($series_type, $suggestions[$value], true)) {
                    $suggestions[$value][] = $series_type;
                }
            }
        }
    }

    header('Content-Type: application/json');
    if ($with_types) {
        $out = [];
        foreach ($suggestions as $value => $types) {
            $out[] = ['value' => (string)$value, 'types' => $types];
        }
        echo json_encode($out);
    } else {
        echo json_encode(array_map('strval', array_keys($suggestions)));
    }
    exit;
}

// ── Endpoint : rendu HTML d'une critique (sanitizé côté serveur) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_review'])) {
    header('Content-Type: application/json');

    $series_id = $_GET['series_id'] ?? '';

    // La série doit exister publiquement (recherche tous types confondus, la
    // critique pouvant être demandée depuis n'importe quelle collection).
    $all_data_gr = load_data();
    $series = null;
    foreach ($all_data_gr as $s) {
        if ($s['id'] === $series_id) { $series = $s; break; }
    }
    if ($series === null) {
        echo json_encode(['success' => false, 'message' => 'Introuvable.']);
        exit;
    }

    // Respecte le mode privé, le masquage des matures et celui des critiques —
    // chacun évalué selon le type PROPRE à la série demandée.
    $__series_type = series_type($series);
    if (
        is_private_mode($options, $__series_type) ||
        is_hide_reviews($options, $__series_type) ||
        (is_hide_mature($options, $__series_type) && !empty($series['mature']))
    ) {
        echo json_encode(['success' => false, 'message' => 'Indisponible.']);
        exit;
    }

    $review = get_review($series_id);
    if ($review === null || trim($review['content']) === '') {
        echo json_encode(['success' => false, 'message' => 'Aucune critique.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'html'    => review_render_markdown($review['content']),
        'author'  => $admin_pseudo,
    ]);
    exit;
}

// Filtrer les séries matures si l'option est activée (réglage de la collection courante)
if ($hide_mature) {
    $data = array_filter($data, function($series) {
        return !($series['mature'] ?? false);
    });
}

// Ne conserver que la collection affichée (aucune écriture sur cette page :
// $data ne sert qu'au rendu, contrairement à admin.php).
$data = series_of_type($data, $current_type);

// ── Collection privée (bloc 14) ──────────────────────────────────────────────
// Chaque collection a désormais son propre réglage de mode privé. Contrairement
// à l'ancien comportement (page minimale, menu masqué), le bouton de la
// collection reste visible dans le menu latéral : seul son CONTENU disparaît,
// avec un message à la place, et aucun décompte n'est affiché nulle part
// (compteurs, pagination, résultats de recherche).
if ($is_private) {
    $data = [];
}

// Gestion du tri, filtre et recherche
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'asc';
$search_term = $_GET['search'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';
$status_mode = $_GET['status_mode'] ?? 'or';

function sort_series(&$data, $sort_by, $sort_order) {
    usort($data, function($a, $b) use ($sort_by, $sort_order) {
        // Vérifier si les clés existent avant de les utiliser
        if ($sort_by === 'volumes') {
            $a_volumes = count($a['volumes'] ?? []);
            $b_volumes = count($b['volumes'] ?? []);
            return $sort_order === 'asc' ? $a_volumes - $b_volumes : $b_volumes - $a_volumes;
        } elseif ($sort_by === 'rereads') {
            $a_val = (int) (is_anime($a) ? ($a['rewatch_count'] ?? 0) : ($a['reread_count'] ?? 0));
            $b_val = (int) (is_anime($b) ? ($b['rewatch_count'] ?? 0) : ($b['reread_count'] ?? 0));
            return $sort_order === 'asc' ? $a_val - $b_val : $b_val - $a_val;
        } elseif ($sort_by === 'added_at' || $sort_by === 'read_at') {
            $a_val = series_latest_date($a, $sort_by);
            $b_val = series_latest_date($b, $sort_by);
            return $sort_order === 'asc' ? strcmp($a_val, $b_val) : strcmp($b_val, $a_val);
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
        return series_matches_search($series, $normalized_search);
    });
}

// Appliquer le filtre de statuts (dont « Avec critique »/« Sans critique »).
// Manquait au rendu HTML initial (non-AJAX) : jusqu'ici seul l'endpoint de
// pagination infinie (get_paginated_series) l'appliquait, ce qui faisait
// apparaître TOUTES les séries au premier chargement d'une page comme
// index.php?status_filter=has_review, avant toute interaction JS — un
// comportement qui donnait l'impression que le filtre se combinait en « OU »
// avec le reste plutôt que d'être appliqué seul.
$data = array_values(apply_status_filter(
    $data,
    $status_filter,
    $status_mode,
    function ($series) use ($public_review_ids) {
        return isset($public_review_ids[$series['id']]);
    },
    $current_type
));
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($options['index_page_title']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($options['site_description']) ?>">
    <?= opengraph_tags($options, ['title' => $options['index_page_title'] ?? '', 'data' => $data, 'public' => true]) ?>
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
        <?= theme_link_tag($options) ?>
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
<body class="with-sidebar">
    <?php include 'includes/public-sidebar.php'; ?>
    <div class="container">
        <h1><?= htmlspecialchars($options['index_page_title']) ?></h1>

        <?php if ($is_private): ?>
            <!-- Collection privée : masquage total du contenu, aucun décompte,
                 aucun filtre affiché (ils n'auraient rien sur quoi porter). -->
            <p class="private-collection-message">
                Cette collection (<?= htmlspecialchars(type_label($current_type, true)) ?>) est privée.
            </p>
        <?php else: ?>
        <!-- Barre de filtres et recherche -->
        <div class="filters">
            <form method="get">
                <!-- Conserve la collection affichée à la soumission du formulaire -->
                <input type="hidden" name="type" value="<?= htmlspecialchars($current_type) ?>">
                <div class="search-row">
                    <input type="text" name="search" id="search-index" placeholder="Rechercher une série, un auteur ou un éditeur..."
                           value="<?= htmlspecialchars($search_term ?? '') ?>" autocomplete="off">
                    <button type="submit">Appliquer</button>
                </div>
                <div class="sort-options">
                    <select name="sort_by" id="sort-by">
                        <option value="name" <?= $sort_by === 'name' ? 'selected' : '' ?>>Trier par nom</option>
                        <option value="author" <?= $sort_by === 'author' ? 'selected' : '' ?>>Trier par auteur</option>
                        <option value="publisher" <?= $sort_by === 'publisher' ? 'selected' : '' ?>>Trier par éditeur</option>
                        <option value="categories" <?= $sort_by === 'categories' ? 'selected' : '' ?>>Trier par catégories</option>
                        <option value="volumes" <?= $sort_by === 'volumes' ? 'selected' : '' ?>>Trier par nombre de <?= htmlspecialchars(type_vocab($current_type, 'items')) ?></option>
                        <option value="rereads" <?= $sort_by === 'rereads' ? 'selected' : '' ?>>Trier par nombre de <?= htmlspecialchars(is_anime($current_type) ? 'revisionnages' : 'relectures') ?></option>
                        <option value="added_at" <?= $sort_by === 'added_at' ? 'selected' : '' ?>>Trier par date d'ajout</option>
                        <option value="read_at" <?= $sort_by === 'read_at' ? 'selected' : '' ?>>Trier par date de lecture</option>
                    </select>
                    <select name="sort_order" id="sort-order">
                        <option value="asc" <?= $sort_order === 'asc' ? 'selected' : '' ?>>Ascendant</option>
                        <option value="desc" <?= $sort_order === 'desc' ? 'selected' : '' ?>>Descendant</option>
                    </select>
                    <?php render_status_filter($status_filter, $status_mode ?? 'or', $reviews_public, $current_type); ?>
                </div>

                <?php if ($hide_mature): ?>
                    <p style="color: var(--status-mature);">🔞 Les séries matures sont masquées.</p>
                <?php endif; ?>
            </form>
        </div>
        <?php endif; ?>

        <!-- Liste des séries -->
        <div class="series-list" id="series-list">
            <?php if ($is_private): ?>
                <?php // Rien : le message ci-dessus remplace entièrement la liste. ?>
            <?php elseif (empty($data)): ?>
                <p>Aucune série trouvée.</p>
            <?php else: ?>
                <?php
                // Applique la pagination initiale (12 premières séries)
                $paginated_data = array_slice($data, 0, $per_page_public);
                foreach ($paginated_data as $series_index => $series):
                    $total_volumes = count($series['volumes'] ?? []);
                    $read_volumes = count(array_filter($series['volumes'] ?? [], fn($v) => $v['status'] === 'terminé'));
                    $card_is_anime = is_anime($series);
                ?>
                    <div class="series-card <?= $card_is_anime ? 'series-card--anime' : '' ?> <?= isset($series['mature']) && $series['mature'] ? 'mature' : '' ?> <?= isset($series['favorite']) && $series['favorite'] ? 'favorite' : '' ?>" data-series-index="<?= $series_index ?>">
                        <img class="series-image" src="<?= htmlspecialchars(series_thumbnail($series)) ?>" alt="<?= $series['name'] ?? '' ?>" loading="lazy">
                        <div class="series-info">
                            <h2><?= $series['name'] ?? '' ?></h2>
                            <?php if ($card_is_anime): ?>
                                <p><strong>Studios :</strong> <?= htmlspecialchars(series_studios_text($series)) ?: '<em>inconnus</em>' ?></p>
                                <p><strong>Catégorie :</strong> <?= htmlspecialchars(anilist_format_label($series['anime_format'] ?? '')) ?></p>
                                <div class="series-stats">
                                    <?= $total_volumes ?> épisode<?= $total_volumes > 1 ? 's' : '' ?>
                                    (<?= $read_volumes ?> vu<?= $read_volumes > 1 ? 's' : '' ?>)
                                </div>
                            <?php else: ?>
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
                            <?php endif; ?>
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
                        <div class="modal-series-image-col">
                            <img id="modal-series-image" src="" alt="Image de la série" class="series-image">
                            <div id="modal-series-review-btn"></div>
                            <div id="modal-series-license-btn"></div>
                        </div>
                        <div class="modal-series-info">
                            <?php // Lignes propres aux mangas : masquées pour un animé (cf. public.js). ?>
                            <p id="modal-row-author"><strong>Auteur :</strong> <span id="modal-series-author"></span></p>
                            <p id="modal-row-publisher"><strong>Éditeur :</strong> <span id="modal-series-publisher"></span></p>
                            <p id="modal-row-contributors"><strong>Autres contributeurs :</strong> <span id="modal-series-other-contributors"></span></p>
                            <?php // Ligne propre aux animés : masquée pour un manga. ?>
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

        <!-- Modale licence : liste ordonnée des séries d'une même licence -->
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

    <!-- Modale pour la légende -->
    <div class="modal" id="legend-modal">
        <div class="modal-content">
            <span class="close-modal" id="close-legend-modal">&times;</span>
            <h2>Légende du site</h2>
            <?php
            // Vocabulaire de la collection affichée : « tome » ou « épisode »,
            // « à lire » ou « à voir ». Rien n'est écrit en dur, tout vient du
            // registre des types. Le tag collector, lui, n'a pas d'objet pour un
            // épisode : c'est l'édition physique de la série entière qui est
            // collector, pas l'épisode.
            $__legend       = type_vocab($current_type);
            $__legend_item  = $__legend['item_cap'];
            $__legend_todo  = $__legend['todo'];
            $__legend_class = 'status-' . str_replace(' ', '-', $__legend_todo);
            ?>
            <div class="legend-content">
                <div class="legend-item">
                    <img src="assets/img/logo.png" alt="Contenu mature" class="mature-thumbnail"><br>
                    <span>Vignette floutée : Contenu mature</span>
                </div><br>
                <div class="legend-item">
                    <div class="legend-sample status-terminé"></div>
                    <span><?= htmlspecialchars($__legend_item) ?> bleu : Fini</span>
                </div><br>
                <div class="legend-item">
                    <div class="legend-sample status-en-cours"></div>
                    <span><?= htmlspecialchars($__legend_item) ?> violet : En cours</span>
                </div><br>
                <div class="legend-item">
                    <div class="legend-sample <?= htmlspecialchars($__legend_class) ?>"></div>
                    <span><?= htmlspecialchars($__legend_item) ?> rose : <?= htmlspecialchars(ucfirst($__legend_todo)) ?></span>
                </div><br>
                <?php if ($current_type !== 'anime'): ?>
                <div class="legend-item">
                    <div class="legend-icon">⭐</div>
                    <span>Étoile : Collector</span>
                </div><br>
                <?php endif; ?>
                <div class="legend-item">
                    <div class="legend-icon last-icon">✅</div>
                    <span>Cochette verte : Dernier <?= htmlspecialchars($__legend['item']) ?></span>
                </div><br>
                <div class="legend-item">
                    <div class="legend-sample favorite-border"></div>
                    <span>Contour doré : Série favorite</span>
                </div>
            </div>
        </div>
    </div>

    <?php
    // Modale « Qui suis-je ? » (profil de l'admin). $profil_data, $profil_avatar,
    // $profil_pseudo, $profil_bio, $profil_social, $profil_has_avatar,
    // $profil_highlights et $has_profil sont déjà calculés plus haut dans cette
    // page (cf. bloc « Profil de l'administrateur ») : le fragment partagé les
    // réutilise tels quels (gardes isset()) plutôt que de les recalculer.
    require 'includes/public-profil-modal.php';
    ?>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <script>
        <?php
        // ── Données complètes pour le bouton "Licence" ────────────────────────
        // $data, à ce stade de la page, ne contient plus que la collection
        // actuellement affichée (cf. series_of_type() plus haut) : une licence
        // mélangeant manga et animé ne pourrait pas rouvrir la fiche d'une
        // série de l'AUTRE collection depuis sa modale. On recharge donc ici
        // une vue de toute la bibliothèque, spécifiquement pour ce besoin,
        // filtrée exactement comme $data l'a été (mature/privé), mais par
        // série et non par collection entière — une licence peut très bien
        // combiner une collection publique et une collection privée.
        $all_data_for_licenses = load_data();
        $all_data_for_licenses = array_values(array_filter($all_data_for_licenses, function ($s) use ($options) {
            if (is_private_mode($options, series_type($s))) return false;
            if (is_hide_mature($options, series_type($s)) && !empty($s['mature'])) return false;
            return true;
        }));
        ?>
        // Données des séries pour JavaScript
        let seriesData = <?= json_encode(array_values(array_map(function ($s) use ($public_review_ids) {
            $s = decorate_series_for_display($s);
            $s['has_review'] = isset($public_review_ids[$s['id']]);
            return $s;
        }, $data))) ?>;
        // Toutes les séries visibles publiquement, tous types confondus : sert
        // uniquement à la modale "Licence" (rouvrir la fiche d'une série qui
        // n'est pas forcément dans la collection actuellement affichée).
        // Décorée avec has_review/has_license comme seriesData, pour que les
        // boutons Critique/Licence fonctionnent aussi sur une série ouverte
        // depuis la modale licence.
        window.allSeriesData = <?= json_encode(array_values(array_map(function ($s) use ($public_series_licenses, $public_review_ids) {
            $s = decorate_series_for_display($s);
            $s['has_review'] = isset($public_review_ids[$s['id']]);
            $lic = $public_series_licenses[$s['id']] ?? null;
            $s['has_license']  = $lic !== null;
            $s['license_id']   = $lic['license_id'] ?? '';
            $s['license_name'] = $lic['license_name'] ?? '';
            return $s;
        }, $all_data_for_licenses))) ?>;
        // Décore aussi seriesData (collection affichée) avec les mêmes champs,
        // pour le bouton "Licence" de la modale de détail sur CETTE collection.
        (function () {
            const byId = {};
            window.allSeriesData.forEach(s => { byId[s.id] = s; });
            seriesData.forEach(s => {
                const full = byId[s.id];
                s.has_license  = !!(full && full.has_license);
                s.license_id   = full ? full.license_id   : '';
                s.license_name = full ? full.license_name : '';
            });
        })();
        window.reviewsPublic = <?= json_encode($reviews_public) ?>;
        window.licensesPublic = true;
        // Contexte de typage (collection affichée + registre allégé).
        window.currentSeriesType = <?= json_encode($current_type) ?>;
        window.seriesTypes = <?= json_encode(series_types_for_js()) ?>;
        // Taille du lot initial rendu server-side ci-dessus (cf. $per_page_public
        // en tête de fichier). public.js s'aligne dessus pour son scroll infini :
        // sans cela, un $per_page_public modifié ici sans toucher au JS ferait
        // réapparaître les séries déjà affichées en double au premier scroll.
        window.initialPublicPerPage = <?= json_encode($per_page_public) ?>;
    </script>
    <script src="assets/js/admin/main.js"></script>
    <script src="assets/js/public.js"></script>
</body>
</html>