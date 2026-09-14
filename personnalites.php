<?php
// ────────────────────────────────────────────────────────────────────────────
// personnalites.php — Annuaire des « Personnalités » (Mangathèque uniquement)
//
// Une personnalité n'est PAS une entité en base : c'est un regroupement
// calculé à la volée (collection_personalities(), includes/helpers.php) de
// toutes les lignes `contributors` qui partagent exactement le même nom, tous
// rôles confondus, à travers toute la Mangathèque. Deux orthographes
// différentes d'un même auteur donnent donc deux personnalités distinctes —
// c'est un choix assumé (voir la conception de la fonctionnalité), pas un bug.
//
// Page unique : l'annuaire (cartes, tri, filtre, compteur dynamique) et la
// fiche individuelle d'une personnalité (modale, jamais une page séparée)
// vivent tous les deux ici — voir assets/js/personnalites.js, qui fait tout
// le rendu et le tri/filtre côté client à partir de window.personalitiesData.
//
// Respecte le mode privé et le masquage des séries matures de la Mangathèque,
// comme toute page publique : une série non éligible n'est jamais comptée ni
// listée ici, et une personnalité qui n'aurait plus aucune série visible
// n'apparaît tout simplement pas.
// ────────────────────────────────────────────────────────────────────────────

require 'config.php';
require_once 'fonctions/reviews.php';
require_once 'fonctions/licenses.php';
require_once 'includes/themes.php';
require_once 'includes/helpers.php';
require_once 'includes/opengraph.php';
// Anilist : uniquement pour anilist_format_label(), utilisé par
// decorate_series_for_display() sur les séries animées (même si cette page ne
// liste que des mangas, la modale de détail réutilisée gère les deux types).
require_once 'includes/anilist.php';

$data    = load_data();
$options = load_options();

$page_title = 'Personnalités — ' . ($options['site_name'] ?? 'Lengas');

// ── Visible publiquement ? ───────────────────────────────────────────────────
// Périmètre Mangathèque uniquement : la page n'a de sens que si la
// Mangathèque elle-même est consultable publiquement (même règle que le lien
// sidebar, voir includes/public-sidebar.php et includes/sidebar.php).
$manga_private = is_private_mode($options, 'manga');

if ($manga_private) {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($page_title) ?></title>
        <meta name="description" content="<?= htmlspecialchars($options['site_description'] ?? '') ?>">
        <?= opengraph_tags($options) ?>
        <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
        <link rel="stylesheet" href="assets/css/main.css">
        <?= theme_link_tag($options) ?>
    </head>
    <body>
        <div class="container">
            <h1>Personnalités</h1>
            <p style="text-align:center;">Cette page n'est pas accessible publiquement.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── Séries visibles publiquement (Mangathèque uniquement, masquage mature
// respecté — le mode privé est déjà écarté ci-dessus) ───────────────────────
$visible_manga = array_values(array_filter($data, function ($series) use ($options) {
    if (is_anime($series)) return false;
    if (is_hide_mature($options, 'manga') && !empty($series['mature'])) return false;
    return true;
}));

// Annuaire complet (nom => ['name','roles','series_ids','series_count',
// 'series_roles']), calculé UNE SEULE FOIS sur les séries déjà filtrées par
// visibilité — collection_personalities() ne refait aucun filtrage lui-même
// (voir sa documentation dans includes/helpers.php).
$all_personalities = collection_personalities($visible_manga);

// Index des séries visibles par id (vignette + nom), pour construire la
// fiche individuelle (modale) côté client sans dupliquer decorate_series_
// for_display() : seuls nom/id/vignette sont nécessaires pour lister les
// séries d'une personnalité, le clic sur une carte ouvre ensuite la vraie
// modale de détail via window.allSeriesData (déjà décorée plus bas).
$series_lite_by_id = [];
foreach ($visible_manga as $s) {
    $series_lite_by_id[$s['id']] = [
        'id'   => $s['id'],
        'name' => $s['name'],
        'thumbnail' => series_thumbnail($s),
    ];
}

// Payload JS de l'annuaire : une entrée par personnalité, avec sa vignette
// déjà résolue côté serveur (personality_thumbnail()) et le détail par série
// (series_roles, clé = id de série) pour que la fiche individuelle (modale)
// n'ait besoin d'aucun aller-retour serveur supplémentaire.
$personalities_payload = [];
foreach ($all_personalities as $name => $profile) {
    $series_roles = [];
    foreach ($profile['series_roles'] as $series_id => $role_labels) {
        if (!isset($series_lite_by_id[$series_id])) continue; // sécurité : série non visible
        $series_roles[] = [
            'id'    => $series_id,
            'name'  => $series_lite_by_id[$series_id]['name'],
            'thumbnail' => $series_lite_by_id[$series_id]['thumbnail'],
            'roles' => array_values($role_labels),
        ];
    }
    usort($series_roles, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

    $personalities_payload[] = [
        'name'          => $name,
        'thumbnail'     => personality_thumbnail($visible_manga, $profile['series_ids']),
        'role_keys'     => array_keys($profile['roles']),
        'role_labels'   => array_values($profile['roles']),
        'series_count'  => $profile['series_count'],
        'roles_count'   => count($profile['roles']),
        'series'        => $series_roles,
    ];
}

// Critiques et licences (pour les boutons de la modale de détail série,
// réutilisée telle quelle depuis index.php / public.js) — même bloc que
// historique.php.
$public_review_ids = [];
if (!is_hide_reviews($options, 'manga')) {
    $public_review_ids = array_flip(get_review_series_ids());
}
$public_series_licenses = get_series_license_map();
$reviews_public = !is_hide_reviews($options, 'manga');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($options['site_description'] ?? '') ?>">
    <?= opengraph_tags($options) ?>
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
    <?= theme_link_tag($options) ?>
</head>
<body class="with-sidebar personalities-page">
    <?php include 'includes/public-sidebar.php'; ?>
    <div class="container">

        <h1>Personnalités</h1>
        <p class="personalities-intro">Tous les auteurs, éditeurs et autres contributeurs de votre Mangathèque, réunis par personne.</p>

        <?php if (!empty($personalities_payload)): ?>
        <div class="personalities-filters">
            <label for="personalities-role-filter" class="sr-only">Filtrer par rôle</label>
            <select id="personalities-role-filter">
                <option value="">Tous les rôles</option>
                <?php foreach (contributor_roles() as $role_key => $role_label): ?>
                    <option value="<?= htmlspecialchars($role_key) ?>"><?= htmlspecialchars($role_label) ?></option>
                <?php endforeach; ?>
                <option value="__none__">Rôle non précisé</option>
            </select>

            <label for="personalities-sort" class="sr-only">Trier</label>
            <select id="personalities-sort">
                <option value="name_asc">Nom (A → Z)</option>
                <option value="name_desc">Nom (Z → A)</option>
                <option value="series_desc">Nombre de séries (décroissant)</option>
                <option value="series_asc">Nombre de séries (croissant)</option>
                <option value="roles_desc">Nombre de rôles (décroissant)</option>
                <option value="roles_asc">Nombre de rôles (croissant)</option>
            </select>

            <p class="personalities-count" id="personalities-count"></p>
        </div>
        <?php endif; ?>

        <div class="personalities-grid" id="personalities-grid">
            <?php if (empty($personalities_payload)): ?>
                <p class="reviews-empty">Aucune personnalité pour le moment.</p>
            <?php endif; ?>
            <!-- Cartes rendues par assets/js/personnalites.js à partir de
                 window.personalitiesData — voir sa documentation pour le tri/
                 filtre/comptage, entièrement côté client. -->
        </div>

        <!-- Modale de fiche individuelle d'une personnalité : liste de ses
             séries et rôle(s) tenu(s) sur chacune, remplie par assets/js/
             personnalites.js. Le clic sur une série ouvre #series-detail-modal
             ci-dessous (même modale que le reste du site). -->
        <div class="modal" id="personality-detail-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-personality-detail-modal">&times;</span>
                <div class="personality-profile-header">
                    <img id="personality-modal-thumb" src="" alt="" class="personality-profile-thumb">
                    <div>
                        <h2 id="personality-modal-name"></h2>
                        <p class="personality-profile-roles" id="personality-modal-roles"></p>
                        <p class="personality-profile-count" id="personality-modal-count"></p>
                    </div>
                </div>
                <div class="personality-series-list" id="personality-modal-series"></div>
            </div>
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
                            <div id="modal-series-badges"></div>
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

    <!-- Éléments factices, jamais affichés : public.js installe un écouteur de
         défilement infini global qui cible ces ID génériques sans jamais
         vérifier leur présence — sans eux, le premier scroll ici lèverait une
         exception JS. Même astuce que historique.php. -->
    <div id="series-list" style="display:none !important;"></div>
    <div class="loading-spinner" id="loading-spinner" style="display:none !important;"></div>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <script>
        // Séries de la Mangathèque visible, décorées comme sur l'accueil, pour
        // alimenter la modale de détail série et ses boutons Critique/Licence —
        // même mécanique que historique.php.
        window.allSeriesData = <?= json_encode(array_values(array_map(function ($s) use ($public_series_licenses, $public_review_ids) {
            $s = decorate_series_for_display($s);
            $s['has_review'] = isset($public_review_ids[$s['id']]);
            $lic = $public_series_licenses[$s['id']] ?? null;
            $s['has_license']  = $lic !== null;
            $s['license_id']   = $lic['license_id'] ?? '';
            $s['license_name'] = $lic['license_name'] ?? '';
            return $s;
        }, $visible_manga))) ?>;
        window.seriesData = window.allSeriesData;
        window.reviewsPublic  = <?= json_encode($reviews_public) ?>;
        window.licensesPublic = true;
        window.seriesTypes = <?= json_encode(series_types_for_js(), JSON_UNESCAPED_UNICODE) ?>;
        window.contributorRoles = <?= json_encode(contributor_roles_for_js(), JSON_UNESCAPED_UNICODE) ?>;
        // Annuaire complet, prêt pour le rendu/tri/filtre côté client — voir
        // assets/js/personnalites.js.
        window.personalitiesData = <?= json_encode($personalities_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <?php
    // Modale « Qui suis-je ? » (profil de l'admin) : pas de bouton dédié dans
    // la sidebar sur cette page (personnalites.php n'est pas dans
    // $__profil_pages, includes/public-sidebar.php) — la modale elle-même
    // n'est donc pas nécessaire ici.
    ?>
    <script src="assets/js/admin/main.js"></script>
    <script src="assets/js/public.js"></script>
    <script src="assets/js/personnalites.js"></script>
</body>
</html>

