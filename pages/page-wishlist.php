<?php

// Rétablit le dossier de travail à la racine du projet : cette page vit dans
// pages/ mais tous les chemins relatifs (config.php, includes/, bdd/, uploads/…)
// sont résolus depuis la racine.
chdir(__DIR__ . '/..');
require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require 'includes/themes.php';
require_once 'includes/opengraph.php';
require_once 'includes/anilist.php';
require 'includes/mangaupdates.php'; // pour le réchauffage de cache après « Ajouter à la collection » (manga)
require_once 'includes/babengas.php'; // validation/normalisation de l'URL Manga News (manganews_normalize_url)
require_once 'includes/syngas.php';   // lien public d'une fiche Syngas (syngas_site_url)
require 'fonctions/series.php';
require 'fonctions/volumes.php';
require 'fonctions/anime.php';
require 'fonctions/episodes.php';
require 'fonctions/wishlist.php';
require 'fonctions/options.php';
require 'fonctions/loans.php'; // pour series_has_active_loans() (module « Déplacer dans la liste »)

$data    = load_data();
$options = load_options();

// Réponse JSON commune à toutes les actions AJAX de cette page. En cas de
// succès, la liste d'envies renvoyée est TOUJOURS relue en base (et non le
// tableau en mémoire de la fonction appelée) : le front reçoit ainsi les
// vignettes, contributeurs et liens tels qu'effectivement enregistrés.
function wishlist_page_json(array $payload): void {
    if (!empty($payload['success'])) {
        $payload['wishlist'] = load_wishlist();
    } elseif (!isset($payload['wishlist'])) {
        $payload['wishlist'] = load_wishlist();
    }
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Actions AJAX ─────────────────────────────────────────────────────────────
// Les fonctions de fonctions/wishlist.php écrivent elles-mêmes en base, de
// façon ciblée (INSERT / UPDATE / DELETE d'une seule ligne) : aucune action
// ci-dessous ne réécrit plus la liste complète via save_wishlist().
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Ajout rapide : manga (nom / auteur / éditeur) ou animé (fiche Anilist).
    if (isset($_POST['add_to_wishlist'])) {
        $type       = sanitize_series_type($_POST['wishlist_type'] ?? '');
        $name       = trim($_POST['wishlist_name'] ?? '');
        $author     = trim($_POST['wishlist_author'] ?? '');
        $publisher  = trim($_POST['wishlist_publisher'] ?? '');
        $studio     = trim($_POST['wishlist_studio'] ?? '');
        $anilist_id = trim($_POST['wishlist_anilist_id'] ?? '');
        $extra      = [];
        if ($type === 'anime') {
            // Couverture Anilist distante : affichage seul (cf. fonctions/wishlist.php).
            $extra['cover_url'] = trim($_POST['wishlist_cover_url'] ?? '');
        }
        wishlist_page_json(add_to_wishlist(load_wishlist(), $name, $author, $publisher, $type, $studio, $anilist_id, $extra));
    }

    // Fiche complète d'un manga (création si index vide, sinon mise à jour) :
    // nom, tous les contributeurs, vignette, URL MangaUpdates / Manga News et
    // identifiant Syngas. Formulaire multipart (upload de vignette).
    if (isset($_POST['save_wishlist_entry'])) {
        $raw_index = trim((string)($_POST['index'] ?? ''));
        $index     = $raw_index === '' ? null : (int)$raw_index;

        $links = wishlist_validate_links(
            (string)($_POST['mangaupdates_url'] ?? ''),
            (string)($_POST['manganews_url'] ?? ''),
            (string)($_POST['syngas_uid'] ?? '')
        );
        if (!$links['ok']) {
            wishlist_page_json(['success' => false, 'message' => $links['message']]);
        }
        [$mangaupdates_url, $manganews_url, $syngas_uid] = $links['values'];

        // Vignette : contrairement à l'ajout d'une série, un échec de
        // téléversement est bloquant ici (c'est souvent la raison même de
        // l'édition) — message explicite plutôt qu'une vignette ignorée.
        $new_image = null;
        if (!empty($_FILES['image']['name'])) {
            $upload_error = null;
            $new_image = upload_image($_FILES['image'], $upload_error);
            if ($new_image === false) {
                wishlist_page_json(['success' => false, 'message' => $upload_error ?: "Le téléversement de la vignette a échoué."]);
            }
        }

        wishlist_page_json(save_wishlist_manga_entry(load_wishlist(), $index, [
            'name'             => trim((string)($_POST['name'] ?? '')),
            'contributors'     => admin_read_contributors_from_post('wl_contrib'),
            'mangaupdates_url' => $mangaupdates_url,
            'manganews_url'    => $manganews_url,
            'syngas_uid'       => $syngas_uid,
            'new_image'        => $new_image,
            'remove_image'     => !empty($_POST['remove_image']),
        ]));
    }

    // Rattrapage des animés (vignette non téléchargée et/ou studio vide) :
    // appelé par lots successifs depuis le front après l'affichage de la
    // grille, voir wishlist_anime_backfill() (fonctions/wishlist.php).
    if (isset($_POST['wishlist_anime_backfill'])) {
        $skip   = array_map('intval', (array)($_POST['skip'] ?? []));
        // Libère le verrou de session : les appels Anilist / téléchargements
        // ne doivent pas bloquer les autres requêtes de la page.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $result = wishlist_anime_backfill(load_wishlist(), $skip, 10);
        wishlist_page_json([
            'success'   => $result['ok'],
            'message'   => $result['error'],
            'processed' => $result['processed'],
            'remaining' => $result['remaining'],
            'updated'   => $result['updated'],
        ]);
    }

    if (isset($_POST['remove_from_wishlist'])) {
        $index = (int)($_POST['index'] ?? 0);
        wishlist_page_json(remove_from_wishlist(load_wishlist(), $index));
    }

    // Ancienne édition simple (nom / auteur / éditeur, ou studio d'un animé) :
    // conservée pour compatibilité, la page passe désormais par
    // save_wishlist_entry pour les mangas.
    if (isset($_POST['edit_wishlist'])) {
        $index     = (int)($_POST['index'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $author    = trim($_POST['author'] ?? '');
        $publisher = trim($_POST['publisher'] ?? '');
        $studio    = trim($_POST['studio'] ?? '');
        wishlist_page_json(edit_wishlist_item(load_wishlist(), $index, $name, $author, $publisher, $studio));
    }

    // Passage en collection : la manière diverge selon le type.
    //   • manga : ouvre la modale d'ajout de série de CETTE page, préremplie
    //     avec toute la fiche de l'entrée (contributeurs, liens, vignette) —
    //     rien n'est écrit en base tant que ce formulaire n'est pas enregistré
    //     (cf. action 'add_series_from_wishlist' ci-dessous). Cette branche se
    //     contente de renvoyer l'item à afficher, sans écriture.
    //   • anime : import COMPLET et immédiat depuis Anilist, sans quitter
    //     cette page — on peut ainsi enchaîner plusieurs animés d'affilée
    //     sans aller-retour entre les deux pages.
    if (isset($_POST['add_from_wishlist'])) {
        $index    = (int)($_POST['index'] ?? 0);
        $wishlist = load_wishlist();
        $item     = $wishlist[$index] ?? null;

        if (!$item) {
            wishlist_page_json(['success' => false, 'message' => 'Entrée introuvable.']);
        }

        if (sanitize_series_type($item['type'] ?? '') === 'anime') {
            // Écriture ciblée : add_anime_from_wishlist() délègue à
            // add_anime_series(), qui upserte elle-même la série (+ ses
            // épisodes, + ses éditions si la clé est fournie) en base.
            $result = add_anime_from_wishlist($data, $wishlist, $index);
            wishlist_page_json([
                'success'   => $result['success'],
                'message'   => $result['message'] ?? '',
                'type'      => 'anime',
                // Identifiant de la série fraîchement importée : permet au
                // front de rediriger vers admin.php avec sa modale d'édition
                // déjà ouverte (favoris, note, revisionnages… à compléter).
                'series_id' => $result['series_id'] ?? '',
            ]);
        }

        // Manga : aucune écriture ici, cf. commentaire ci-dessus.
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'type' => 'manga', 'index' => $index, 'item' => $item], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Enregistrement du formulaire d'ajout de série préouvert depuis la
    // liste d'envies (manga uniquement — les animés passent par la branche
    // ci-dessus, sans formulaire à remplir). Même jeu de champs que la
    // modale d'ajout de series.php sur admin.php, upload d'image compris,
    // plus l'identifiant Syngas et la reprise de la vignette de l'entrée.
    // add_series_from_wishlist() garantit que l'entrée de la wishlist n'est
    // retirée QUE si la série a effectivement été créée.
    if (isset($_POST['add_series_from_wishlist'])) {
        $index = (int)($_POST['index'] ?? 0);

        $name               = trim($_POST['name'] ?? '');
        $contributors       = admin_read_contributors_from_post('contrib');
        $categories         = trim($_POST['categories'] ?? '');
        $genres             = trim($_POST['genres'] ?? '');
        $mature             = !empty($_POST['mature']);
        $favorite           = !empty($_POST['favorite']);
        $volumes_count      = (int)($_POST['volumes_count'] ?? 1);
        $volumes_status     = $_POST['volumes_status'] ?? 'à lire';
        $all_collector      = !empty($_POST['all_collector']);
        $last_volume        = !empty($_POST['last_volume']);
        $status             = $_POST['series_status'] ?? 'en cours';
        $read_elsewhere     = !empty($_POST['read_elsewhere']);
        $reading_abandoned  = !empty($_POST['reading_abandoned']);
        $rating             = sanitize_rating($_POST['rating'] ?? '');
        $reread_count       = max(0, (int)($_POST['reread_count'] ?? 0));
        $use_wishlist_image = !empty($_POST['use_wishlist_image']);

        $wishlist = load_wishlist();
        $item     = $wishlist[$index] ?? null;

        if (!$item || sanitize_series_type($item['type'] ?? '') !== 'manga') {
            wishlist_page_json(['success' => false, 'message' => 'Entrée introuvable ou invalide.']);
        }

        // Au moins un Auteur ET un Éditeur — même règle que admin.php.
        $has_author    = !empty(array_filter($contributors, fn($c) => $c['role'] === 'auteur' && trim($c['name']) !== ''));
        $has_publisher = !empty(array_filter($contributors, fn($c) => $c['role'] === 'editeur' && trim($c['name']) !== ''));
        if (!$has_author || !$has_publisher) {
            wishlist_page_json(['success' => false, 'message' => 'Au moins un contributeur avec le rôle « Auteur » et un avec le rôle « Éditeur » sont requis.']);
        }

        // Liens facultatifs : mêmes validations que la fiche de la liste
        // d'envies (URL MangaUpdates assainie, URL Manga News normalisée,
        // identifiant Syngas au format UUID).
        $links = wishlist_validate_links(
            (string)($_POST['mangaupdates_url'] ?? ''),
            (string)($_POST['manganews_url'] ?? ''),
            (string)($_POST['syngas_uid'] ?? '')
        );
        if (!$links['ok']) {
            wishlist_page_json(['success' => false, 'message' => $links['message']]);
        }
        [$mangaupdates_url, $manganews_url, $syngas_uid] = $links['values'];

        // Un UID Syngas déjà porté par une autre série ferait échouer
        // l'écriture (index unique idx_series_syngas_uid) : message clair
        // plutôt qu'une PDOException brute.
        if ($syngas_uid !== '' && ($dup = find_series_by_syngas_uid($data, $syngas_uid))) {
            wishlist_page_json(['success' => false, 'message' => "Cet identifiant Syngas est déjà lié à la série « {$dup['name']} » de votre collection."]);
        }

        // Initialiser $image à null par défaut, même logique que admin.php :
        // un échec de téléversement n'empêche pas l'ajout de la série.
        $image = null;
        if (!empty($_FILES['image']['name'])) {
            $error_message = null;
            $image = upload_image($_FILES['image'], $error_message);
            if ($image === false) {
                $image = null;
            }
        }

        $result = add_series_from_wishlist($data, $wishlist, $index, [
            'name'               => $name,
            'contributors'       => $contributors,
            'categories'         => $categories,
            'genres'             => $genres,
            'mangaupdates_url'   => $mangaupdates_url,
            'manganews_url'      => $manganews_url,
            'mature'             => $mature,
            'favorite'           => $favorite,
            'volumes_count'      => $volumes_count,
            'volumes_status'     => $volumes_status,
            'all_collector'      => $all_collector,
            'last_volume'        => $last_volume,
            'image'              => $image,
            'use_wishlist_image' => $use_wishlist_image,
            'status'             => $status,
            'read_elsewhere'     => $read_elsewhere,
            'reading_abandoned'  => $reading_abandoned,
            'rating'             => $rating,
            'reread_count'       => $reread_count,
            'syngas_uid'         => $syngas_uid,
        ]);

        // Réchauffer le cache MangaUpdates pour la nouvelle série, même
        // geste que l'ajout classique depuis admin.php.
        if ($result['success'] && $mangaupdates_url !== '') {
            $mu_id = mangaupdates_get_id_from_url($mangaupdates_url);
            if ($mu_id !== null) @mangaupdates_get_volumes($mu_id, true);
        }

        wishlist_page_json([
            'success'   => $result['success'],
            'message'   => $result['message'] ?? '',
            'series_id' => $result['series_id'] ?? '',
        ]);
    }

    // ── Module « Déplacer dans la liste » ─────────────────────────────────────
    // Vérifie les prêts en cours d'une série de la collection avant tout
    // déplacement effectif (avertissement bloquant côté front, cf. JS plus bas :
    // ce endpoint ne fait qu'informer, il ne bloque rien lui-même).
    if (isset($_POST['check_series_loans'])) {
        $series_id = $_POST['series_id'] ?? '';
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'has_loans' => series_has_active_loans($series_id)]);
        exit;
    }

    // Déplacement effectif : retire la série de la collection, l'ajoute à la
    // liste d'envies avec les informations déjà connues (vignette comprise).
    if (isset($_POST['move_to_wishlist'])) {
        $series_id = $_POST['series_id'] ?? '';
        $result    = move_series_to_wishlist($data, load_wishlist(), $series_id);
        unset($result['data']); // inutile au front, potentiellement volumineux
        wishlist_page_json($result);
    }
}

// L'endpoint « get_suggestions » (autocomplétion) vit uniquement dans
// admin.php : autocomplete.js y est redirigé via window.suggestionsEndpoint,
// posé plus bas dans le <script> de cette page. Pas de duplication ici.

$wishlist = load_wishlist();

// ── Candidats pour le module « Déplacer dans la liste » ──────────────────────
// Toute la collection (mangas et animés), sous une forme allégée pour la
// recherche côté client : id, nom, type, auteur/éditeur OU studio (selon le
// type), et vignette déjà arbitrée par la cascade d'affichage habituelle.
$move_candidates = array_map(function ($s) {
    $type = series_type($s);
    $authors    = series_contributors_names_text($s, 'auteur');
    $publishers = series_contributors_names_text($s, 'editeur');
    return [
        'id'     => $s['id'],
        'name'   => $s['name'],
        'type'   => $type,
        'meta'   => ($type === 'anime')
            ? series_studios_text($s)
            : trim($authors . ($publishers !== '' ? ' · ' . $publishers : '')),
        'thumb'  => series_thumbnail($s),
    ];
}, $data);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste d'envies — <?= htmlspecialchars($options['site_name'] ?? 'Lengas') ?></title>
    <meta name="description" content="Gestion de la liste d'envies.">
    <?= opengraph_tags($options) ?>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <?= asset_css_tag('assets/css/main.css') ?>
    <?= theme_link_tag($options) ?>
</head>
<body class="with-sidebar">

    <?php include 'includes/sidebar.php'; ?>

    <main class="page-main">
        <div class="page-header">
            <h1>Liste d'envies</h1>
            <p class="page-subtitle">Les séries que vous souhaitez acquérir.</p>
        </div>

        <div class="wishlist-page-layout">

            <!-- Ajouter une entrée -->
            <section class="wishlist-add-panel">
                <h2 class="loans-panel-title">
                    <img src="https://api.iconify.design/mdi/playlist-plus.svg?color=%2338bdf8" width="20" height="20" alt="">
                    Ajouter à la liste
                </h2>

                <!-- Bascule de type : conditionne les champs affichés ci-dessous. -->
                <div class="wishlist-type-toggle" id="wishlist-type-toggle" role="tablist">
                    <button type="button" class="wishlist-type-btn is-active" data-type="manga" role="tab" aria-selected="true">
                        <img src="https://api.iconify.design/mdi/bookshelf.svg?color=%23c94e93" width="16" height="16" alt="">
                        Manga
                    </button>
                    <button type="button" class="wishlist-type-btn" data-type="anime" role="tab" aria-selected="false">
                        <img src="https://api.iconify.design/mdi/television-classic.svg?color=%2338bdf8" width="16" height="16" alt="">
                        Animé
                    </button>
                </div>

                <!-- Formulaire manga : saisie libre, inchangé depuis les versions antérieures. -->
                <div class="wishlist-add-form" id="wishlist-form-manga" autocomplete="off">
                    <!-- Mêmes champs obligatoires que la fiche d'une série : nom,
                         au moins un auteur et un éditeur (vérifiés aussi côté serveur). -->
                    <input type="text" id="wishlist-name"      placeholder="Nom de la série (obligatoire)" autocomplete="off" required>
                    <input type="text" id="wishlist-author"    placeholder="Auteur (obligatoire)"          autocomplete="off" required>
                    <input type="text" id="wishlist-publisher" placeholder="Éditeur (obligatoire)"         autocomplete="off" required>
                    <button id="add-to-wishlist-btn" class="button button-ats">Ajouter</button>
                    <!-- Fiche complète : ouvre la modale d'édition en mode création,
                         préremplie avec ce qui a déjà été saisi ci-dessus (vignette,
                         contributeurs supplémentaires, liens MangaUpdates / Manga News /
                         Syngas). -->
                    <button type="button" id="open-wishlist-entry-modal" class="button button-opt wishlist-full-entry-btn">
                        <img src="https://api.iconify.design/mdi/card-account-details-outline.svg?color=currentColor" width="16" height="16" alt="">
                        Fiche complète…
                    </button>
                </div>

                <!-- Formulaire animé : recherche Anilist obligatoire (titre ou identifiant),
                     sur le même gabarit que la modale d'ajout d'admin.php. -->
                <div class="wishlist-add-form" id="wishlist-form-anime" hidden>
                    <p class="hint">
                        Cherchez la série sur Anilist, puis choisissez-la : son titre et son
                        studio sont mémorisés tels quels, en vue d'un import complet le jour
                        de son passage en collection.
                    </p>
                    <div class="anime-search-row">
                        <input type="text" id="wishlist-anime-search-input" placeholder="Titre de la série animée…" autocomplete="off">
                        <button type="button" id="wishlist-anime-search-btn" class="button">Rechercher</button>
                    </div>
                    <p class="hint anime-search-or">— ou, si la recherche par titre ne trouve pas la série —</p>
                    <div class="anime-search-row">
                        <input type="text" id="wishlist-anime-lookup-input" placeholder="Identifiant Anilist (ex. 21519)…" autocomplete="off" inputmode="numeric">
                        <button type="button" id="wishlist-anime-lookup-btn" class="button">Chercher par ID</button>
                    </div>
                    <div id="wishlist-anime-feedback" class="anime-search-feedback"></div>
                    <div id="wishlist-anime-results" class="anime-search-results"></div>
                    <button type="button" id="wishlist-add-anime-btn" class="button button-ats wishlist-add-anime-confirm">Ajouter à la liste</button>
                </div>

                <!-- ══ DÉPLACER DANS LA LISTE ═══════════════════════════════
                     Retire une série de la collection (manga ou animé) pour
                     la replacer dans la liste d'envies, avec ses informations
                     déjà connues (auteur/éditeur ou studio). -->
                <div class="wishlist-move-block">
                    <h3 class="wishlist-move-title">Déplacer dans la liste</h3>
                    <p class="hint">
                        Retirez une série de votre collection pour la replacer dans la liste
                        d'envies, avec ses informations déjà connues.
                    </p>

                    <div class="wishlist-move-search-wrap">
                        <label for="wishlist-move-search" class="sr-only">Série de la collection</label>
                        <input type="text" id="wishlist-move-search" placeholder="Rechercher une série de la collection…" autocomplete="off">
                        <div class="wishlist-move-results" id="wishlist-move-results"></div>
                    </div>

                    <div class="wishlist-move-selected" id="wishlist-move-selected" hidden>
                        <img class="wishlist-move-selected-thumb" id="wishlist-move-selected-thumb" src="" alt="" loading="lazy">
                        <div class="wishlist-move-selected-info">
                            <span class="wishlist-move-selected-name" id="wishlist-move-selected-name"></span>
                            <span class="wishlist-move-selected-meta" id="wishlist-move-selected-meta"></span>
                        </div>
                        <button type="button" class="button-icon" id="wishlist-move-clear-btn" title="Changer de série" aria-label="Changer de série">
                            <img src="https://api.iconify.design/mdi/close.svg?color=%23f87171" width="18" height="18" alt="">
                        </button>
                    </div>

                    <button type="button" id="wishlist-move-confirm-btn" class="button button-ats wishlist-move-confirm" disabled>
                        Déplacer vers la liste d'envies
                    </button>
                </div>
            </section>

            <!-- Liste -->
            <section class="wishlist-list-panel">
                <div class="wishlist-list-header">
                    <h2 class="loans-panel-title">
                        <img src="https://api.iconify.design/mdi/heart-multiple.svg?color=%2338bdf8" width="20" height="20" alt="">
                        <span>Ma liste <span id="wishlist-count" class="wishlist-count"><?= count($wishlist) ?></span></span>
                    </h2>
                </div>
                <div class="wishlist-controls">
                    <input type="text" id="wishlist-search" class="loans-search-input" placeholder="Filtrer…" autocomplete="off">
                    <select id="wishlist-type-filter" class="wishlist-sort-select">
                        <option value="all">Mangas et animés</option>
                        <option value="manga">Mangas uniquement</option>
                        <option value="anime">Animés uniquement</option>
                    </select>
                    <select id="wishlist-sort-field" class="wishlist-sort-select">
                        <option value="name">Trier par titre</option>
                        <option value="author">Trier par auteur / studio</option>
                        <option value="publisher">Trier par éditeur</option>
                    </select>
                    <select id="wishlist-sort-order" class="wishlist-sort-select" style="min-width:110px">
                        <option value="asc">↑ Croissant</option>
                        <option value="desc">↓ Décroissant</option>
                    </select>
                </div>

                <!-- Grille de cartes, rendue par JS (applyFiltersAndSort()). -->
                <div class="wishlist-grid" id="wishlist-list" aria-live="polite"></div>
            </section>

        </div>

        <!-- Modale « fiche de la liste d'envies » (mangas uniquement) : création
             d'une entrée complète OU édition d'une entrée existante. Un animé n'a
             rien à y faire : son titre, son studio et sa couverture sont fixés
             par Anilist (il n'a d'ailleurs pas de bouton « Modifier »). -->
        <div class="modal" id="wishlist-entry-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-wishlist-entry-modal">&times;</span>
                <h2 id="wishlist-entry-title">Modifier l'envie</h2>
                <form id="wishlist-entry-form" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" name="index" id="wle-index" value="">

                    <div class="wishlist-entry-head">
                        <div class="wishlist-entry-thumb" id="wle-thumb-preview" aria-hidden="true"></div>
                        <div class="wishlist-entry-thumb-fields">
                            <p>Vignette :</p>
                            <input type="file" name="image" id="wle-image" accept="image/jpeg, image/jpg, image/png, image/gif, image/webp">
                            <p class="hint">Extensions autorisées : jpeg, jpg, png, gif et webp. Poids maximum : 5 Mo.</p>
                            <label id="wle-remove-image-wrap" hidden>
                                <input type="checkbox" name="remove_image" id="wle-remove-image"> Retirer la vignette actuelle
                            </label>
                        </div>
                    </div>

                    <p>Nom :</p>
                    <input type="text" name="name" id="wle-name" placeholder="Nom de la série (obligatoire)" required>

                    <p>Contributeurs :</p>
                    <p class="hint">Au moins un « Auteur » et un « Éditeur » sont requis. Une même personne peut apparaître sur plusieurs lignes avec des rôles différents.</p>
                    <div class="contributors-field" id="wle-contributors" data-prefix="wl_contrib"></div>
                    <button type="button" class="button button-opt contributors-add-btn" data-contributors-target="wle-contributors">+ Ajouter un contributeur</button>

                    <p>URL MangaUpdates :</p>
                    <input type="text" name="mangaupdates_url" id="wle-mangaupdates-url" placeholder="https://www.mangaupdates.com/series/xxxxxxx/nom-de-la-serie (facultatif)">
                    <p>URL Manga News :</p>
                    <input type="text" name="manganews_url" id="wle-manganews-url" placeholder="https://www.manga-news.com/index.php/serie/Nom-de-la-serie (facultatif)">
                    <p>Identifiant Syngas :</p>
                    <input type="text" name="syngas_uid" id="wle-syngas-uid" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx (facultatif)" spellcheck="false">
                    <p class="hint">
                        Visible dans l'adresse d'une fiche Syngas (<code>serie.php?id=…</code>).
                        <a href="<?= htmlspecialchars(syngas_site_url()) ?>" target="_blank" rel="noopener">Accéder à Syngas ↗</a>
                    </p>
                    <p class="hint">Toutes ces informations seront reprises dans le formulaire d'ajout le jour du passage en collection.</p>

                    <button type="submit" class="button" id="wle-submit-btn">Enregistrer</button>
                </form>
            </div>
        </div>

        <!-- Modale "ajouter à la collection" (animés uniquement : import
             Anilist direct, il ne s'agit que d'une confirmation avant l'appel
             réseau. Les mangas passent directement par #add-series-from-wishlist-modal,
             cf. plus bas, sans confirmation intermédiaire : le formulaire lui-même
             sert de point de confirmation. -->
        <div class="modal" id="add-from-wishlist-modal">
            <div class="modal-content modal-content--narrow">
                <span class="close-modal" id="close-add-from-wishlist-modal">&times;</span>
                <h2>Ajouter à la collection</h2>
                <p id="afw-anime-text">
                    La série animée <strong id="afw-anime-series-name"></strong> va être importée
                    intégralement depuis Anilist et rejoindre l'Animethèque.
                </p>
                <div class="modal-actions">
                    <button class="button button-ats" id="afw-confirm-btn">Continuer</button>
                    <button class="button button-ext" id="afw-cancel-btn">Annuler</button>
                </div>
            </div>
        </div>

        <!-- Modale "ajouter une série" (mangas uniquement), préremplie depuis
             une entrée de la liste d'envies. Mêmes champs que la modale
             d'ajout de admin.php, réutilisés ici pour ne pas quitter la page :
             l'enregistrement crée la série ET retire l'entrée de la liste
             d'envies en une seule opération côté serveur (action
             'add_series_from_wishlist'). Tant que ce formulaire n'est pas
             soumis, ou s'il échoue, l'entrée reste intacte dans la liste. -->
        <div class="modal" id="add-series-from-wishlist-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-add-series-from-wishlist-modal">&times;</span>
                <h2>Ajouter une série</h2>
                <form id="add-series-from-wishlist-form" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="index" id="asfw-index">
                    <p>Nom :</p>
                    <input type="text" name="name" id="asfw-name" placeholder="Nom de la série (obligatoire)" autocomplete="off" required>
                    <p>Contributeurs :</p>
                    <p class="hint">Au moins un « Auteur » et un « Éditeur » sont requis. Une même personne peut apparaître sur plusieurs lignes avec des rôles différents.</p>
                    <div class="contributors-field" id="asfw-contributors" data-prefix="contrib"></div>
                    <button type="button" class="button button-opt contributors-add-btn" data-contributors-target="asfw-contributors">+ Ajouter un contributeur</button>
                    <p>Catégories :</p>
                    <input type="text" name="categories" id="asfw-categories" placeholder="Catégories (séparées par des virgules) (obligatoire)" autocomplete="off" required>
                    <p>Genres :</p>
                    <input type="text" name="genres" id="asfw-genres" placeholder="Genres (séparés par des virgules) (facultatif)" autocomplete="off">
                    <p>Nombre de tomes à créer :</p>
                    <input type="number" name="volumes_count" id="asfw-volumes-count" placeholder="Nombre de tomes" min="1" value="1" autocomplete="off">
                    <p>Statut des tomes :</p>
                    <select name="volumes_status" id="asfw-volumes-status" required>
                        <option value="à lire">À lire</option>
                        <option value="en cours">En cours</option>
                        <option value="terminé">Terminé</option>
                    </select>
                    <label>
                        <input type="checkbox" name="all_collector" id="asfw-all-collector"> Tous en collector ⭐
                    </label>
                    <p>Statut de publication de la série :</p>
                    <select name="series_status" id="asfw-status" required>
                        <option value="en cours">En cours ▶️</option>
                        <option value="terminée">Terminée ✅</option>
                        <option value="en pause">En pause ⏳</option>
                        <option value="abandonnée">Abandonnée ⛔</option>
                    </select>
                    <p>URL MangaUpdates :</p>
                    <input type="text" name="mangaupdates_url" id="asfw-mangaupdates-url" placeholder="https://www.mangaupdates.com/series/xxxxxxx/nom-de-la-serie (facultatif)" autocomplete="off">
                    <p class="hint"><a tabindex="0" data-hint="L'URL MangaUpdates sert à détecter les tomes manquants des séries terminées (outil « Séries incomplètes »). Sur mangaupdates.com, ouvrez la fiche de votre série puis copiez l'URL complète. L'outil « Associer MangaUpdates » (modale Outils) peut aussi remplir ce champ automatiquement.">À quoi ça sert ? Où la trouver ?</a></p>
                    <p>URL Manga News :</p>
                    <input type="text" name="manganews_url" id="asfw-manganews-url" placeholder="https://www.manga-news.com/index.php/serie/Nom-de-la-serie" autocomplete="off">
                    <p class="hint"><a tabindex="0" data-hint="L'URL Manga News permet de connaître le nombre de tomes réellement parus en France et le statut de publication de l'édition française, via le service Babengas (outil « Vérification via Babengas » de la page Outils). Sur manga-news.com, ouvrez la fiche de la série (adresse en /serie/Nom-de-la-serie) et copiez l'URL complète.">À quoi ça sert ? Où la trouver ?</a></p>
                    <p>Identifiant Syngas :</p>
                    <input type="text" name="syngas_uid" id="asfw-syngas-uid" placeholder="Identifiant de la fiche Syngas liée (facultatif)" autocomplete="off" spellcheck="false">
                    <label>
                        <input type="checkbox" name="mature" id="asfw-mature"> Contenu mature 🔞
                    </label>
                    <label>
                        <input type="checkbox" name="favorite" id="asfw-favorite"> Série favorite ❤️
                    </label>
                    <label>
                        <input type="checkbox" name="read_elsewhere" id="asfw-read-elsewhere"> Lue ailleurs 📖
                    </label>
                    <p class="hint">Cochez si vous avez lu cette série sans la posséder (chez un ami, en bibliothèque, revendue, etc.).</p>
                    <label>
                        <input type="checkbox" name="reading_abandoned" id="asfw-reading-abandoned"> Lecture abandonnée 📕
                    </label>
                    <p class="hint">Cochez si vous avez arrêté de lire cette série.</p>
                    <p>Notation (facultatif) :</p>
                    <select name="rating" id="asfw-rating">
                        <option value="">Aucune note ➖</option>
                        <option value="apprecie">J'ai apprécié ☺️</option>
                        <option value="mitige">Mi-figue mi-raisin 😑</option>
                        <option value="deteste">Je n'ai pas aimé 😠</option>
                    </select>
                    <p>Nombre de relectures (facultatif) :</p>
                    <input type="number" name="reread_count" id="asfw-reread-count" min="0" step="1" value="0" autocomplete="off">
                    <p>Vignette :</p>
                    <!-- Vignette de l'entrée de la liste d'envies, reprise par défaut
                         (affiché uniquement si l'entrée en a une). Un nouveau fichier
                         choisi ci-dessous l'emporte toujours. -->
                    <div class="asfw-wishlist-thumb" id="asfw-wishlist-thumb" hidden>
                        <img src="" alt="" id="asfw-wishlist-thumb-img">
                        <label>
                            <input type="checkbox" name="use_wishlist_image" id="asfw-use-wishlist-image" value="1" checked>
                            Utiliser la vignette de la liste d'envies
                        </label>
                    </div>
                    <input type="file" name="image" accept="image/jpeg, image/jpg, image/png, image/gif, image/webp">
                    <p class="hint">Extensions autorisées : jpeg, jpg, png, gif et webp. Poids maximum : 5 Mo.</p>
                    <p class="hint">Cette série sera ajoutée à votre collection et retirée de la liste d'envies dès l'enregistrement.</p>
                    <button type="submit" name="add_series_from_wishlist" id="asfw-submit-btn">Enregistrer</button>
                </form>
            </div>
        </div>

        <!-- Modale "déplacer dans la liste" -->
        <div class="modal" id="move-to-wishlist-modal">
            <div class="modal-content modal-content--narrow">
                <span class="close-modal" id="close-move-to-wishlist-modal">&times;</span>
                <h2>Déplacer dans la liste</h2>
                <p>
                    La série <strong id="mtw-series-name"></strong> va être retirée de votre
                    collection et replacée dans la liste d'envies.
                </p>
                <p class="hint" id="mtw-loans-warning" style="display:none;">
                    ⚠️ Cette série a actuellement des tomes prêtés : les prêts en cours seront
                    perdus si vous continuez.
                </p>
                <div class="modal-actions">
                    <button class="button button-ats" id="mtw-confirm-btn">Continuer</button>
                    <button class="button button-ext" id="mtw-cancel-btn">Annuler</button>
                </div>
            </div>
        </div>

        <!-- Modales utilitaires -->
        <div class="modal" id="custom-confirm-modal">
            <div class="modal-content modal-content--narrow">
                <h2 id="custom-confirm-title">Confirmation</h2>
                <p id="custom-confirm-message"></p>
                <div class="modal-actions">
                    <button class="button" id="custom-confirm-ok">Confirmer</button>
                    <button class="button button-ext" id="custom-confirm-cancel">Annuler</button>
                </div>
            </div>
        </div>
        <div class="modal" id="custom-alert-modal">
            <div class="modal-content modal-content--narrow">
                <h2 id="custom-alert-title">Information</h2>
                <p id="custom-alert-message"></p>
                <div class="modal-actions">
                    <button class="button" id="custom-alert-ok">OK</button>
                </div>
            </div>
        </div>

    </main>

    <?= asset_js_tag('assets/js/admin/anime.js') ?>
    <script>
        // Redirige l'endpoint d'autocomplétion (assets/js/admin/autocomplete.js)
        // vers admin.php, qui vit à la racine du projet — cette page est dans
        // pages/. Doit être posé AVANT le chargement d'autocomplete.js, qui
        // interroge cet endpoint dès son exécution.
        window.suggestionsEndpoint = '../admin.php';
    </script>
    <?= asset_js_tag('assets/js/admin/autocomplete.js') ?>
    <?= asset_js_tag('assets/js/admin/contributors.js') ?>
    <script>
        // Registre des types (libellés, couleurs) : seule source de vérité,
        // partagée avec admin.php et index.php. Aucun libellé ni couleur ne
        // doit être écrit en dur plus bas.
        window.seriesTypes = <?= json_encode(series_types_for_js()) ?>;
        // Registre des rôles de contributeur, même source que admin.php —
        // voir includes/helpers.php, contributor_roles_for_js().
        window.contributorRoles = <?= json_encode(contributor_roles_for_js(), JSON_UNESCAPED_UNICODE) ?>;
        // Racine publique de Syngas (lien « Voir sur Syngas » des cartes).
        window.syngasSiteUrl = <?= json_encode(syngas_site_url(), JSON_UNESCAPED_SLASHES) ?>;

        let wishlistData = <?= json_encode(array_values($wishlist), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        // Entrée animée en attente de confirmation d'import Anilist (modale
        // #add-from-wishlist-modal). Les mangas n'utilisent pas cette
        // variable : voir pendingAddSeriesFromWishlistIndex plus bas.
        let pendingAddFromWishlist = null;

        // Collection complète (mangas + animés), pour le module « Déplacer
        // dans la liste » : recherche instantanée côté client, comme les
        // autres sélecteurs de série du site.
        window.moveCandidates = <?= json_encode(array_values($move_candidates), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        function normalizeString(str) {
            return String(str ?? '').toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
        }

        function htmlEscape(str) {
            return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // Lien externe sûr : http(s) uniquement, sinon chaîne vide.
        function safeHttpUrl(url) {
            return /^https?:\/\//i.test(String(url || '')) ? String(url) : '';
        }

        // Source <img> d'une vignette de la liste d'envies : URL distante
        // (couverture Anilist) telle quelle, chemin local préfixé par '../'
        // (cette page vit dans pages/).
        const WISHLIST_DEFAULT_THUMB = '../assets/img/logo.png';
        function wishlistThumbSrc(thumb) {
            if (!thumb) return '';
            return /^https:\/\//i.test(thumb) ? thumb : '../' + thumb;
        }

        function showCustomConfirm(title, message) {
            const modal = document.getElementById('custom-confirm-modal');
            document.getElementById('custom-confirm-title').textContent = title;
            document.getElementById('custom-confirm-message').textContent = message;
            modal.classList.add('modal-active');
            return new Promise(resolve => {
                document.getElementById('custom-confirm-ok').onclick = () => { modal.classList.remove('modal-active'); resolve(true); };
                document.getElementById('custom-confirm-cancel').onclick = () => { modal.classList.remove('modal-active'); resolve(false); };
            });
        }

        function showCustomAlert(title, message) {
            const modal = document.getElementById('custom-alert-modal');
            document.getElementById('custom-alert-title').textContent = title;
            document.getElementById('custom-alert-message').textContent = message;
            modal.classList.add('modal-active');
            return new Promise(resolve => {
                document.getElementById('custom-alert-ok').onclick = () => { modal.classList.remove('modal-active'); resolve(); };
            });
        }

        window.alert = (msg) => showCustomAlert('Avertissement', msg);

        async function postForm(body) {
            const res = await fetch('page-wishlist.php', {
                method: 'POST',
                headers: body instanceof FormData ? undefined : { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body instanceof FormData ? body : (body instanceof URLSearchParams ? body : new URLSearchParams(body)).toString()
            });
            return res.json();
        }

        // ─────────────────────────────────────────────────────────────────────
        // Bascule de type (manga / animé) du formulaire d'ajout
        // ─────────────────────────────────────────────────────────────────────
        let addFormType = 'manga';
        // Fiche Anilist sélectionnée en attente d'ajout (recherche animé) :
        // { anilist_id, title, studios_text, cover_url }. Vidée après ajout ou
        // changement de type/recherche.
        let pendingAnimeSelection = null;

        const typeToggle    = document.getElementById('wishlist-type-toggle');
        const formManga     = document.getElementById('wishlist-form-manga');
        const formAnime     = document.getElementById('wishlist-form-anime');

        function setAddFormType(type) {
            addFormType = type;
            pendingAnimeSelection = null;
            formManga.hidden = (type !== 'manga');
            formAnime.hidden = (type !== 'anime');
            typeToggle.querySelectorAll('.wishlist-type-btn').forEach(btn => {
                const active = btn.dataset.type === type;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            if (type === 'anime') {
                document.getElementById('wishlist-anime-results').innerHTML = '';
                document.getElementById('wishlist-anime-feedback').textContent = '';
            }
        }

        typeToggle.querySelectorAll('.wishlist-type-btn').forEach(btn => {
            btn.addEventListener('click', () => setAddFormType(btn.dataset.type));
        });

        // ─────────────────────────────────────────────────────────────────────
        // Recherche Anilist (titre + identifiant), formulaire d'ajout animé.
        // Réutilise animeResultHtml() (assets/js/admin/anime.js) pour un rendu
        // identique à la modale d'ajout d'admin.php.
        // ─────────────────────────────────────────────────────────────────────
        (function setupWishlistAnimeSearch() {
            const input        = document.getElementById('wishlist-anime-search-input');
            const button       = document.getElementById('wishlist-anime-search-btn');
            const lookupInput  = document.getElementById('wishlist-anime-lookup-input');
            const lookupButton = document.getElementById('wishlist-anime-lookup-btn');
            const results      = document.getElementById('wishlist-anime-results');
            const feedback     = document.getElementById('wishlist-anime-feedback');

            let searching = false;

            function setFeedback(text, kind) {
                feedback.textContent = text || '';
                feedback.className = 'anime-search-feedback' + (kind ? ' is-' + kind : '');
            }

            async function runSearch() {
                const term = (input.value || '').trim();
                if (term === '' || searching) return;
                searching = true;
                results.innerHTML = '';
                setFeedback('Interrogation d\u2019Anilist…', 'loading');
                try {
                    const response = await fetch('../admin.php?anilist_search=1&q=' + encodeURIComponent(term));
                    const data = await response.json();
                    if (!data.success) { setFeedback(data.message || 'La recherche a échoué.', 'error'); return; }
                    if (!data.results.length) { setFeedback('Aucune série animée ne correspond à cette recherche.', 'error'); return; }
                    setFeedback('');
                    results.innerHTML = data.results.map(m => window.animeResultHtml(m, 'wishlist')).join('');
                } catch (error) {
                    console.error('Erreur:', error);
                    setFeedback('Anilist est injoignable pour le moment.', 'error');
                } finally {
                    searching = false;
                }
            }

            async function runLookup() {
                const raw = (lookupInput.value || '').trim();
                const id  = parseInt(raw, 10);
                if (!raw || !Number.isInteger(id) || id <= 0 || searching) {
                    if (raw) setFeedback("L'identifiant Anilist doit être un nombre entier positif.", 'error');
                    return;
                }
                searching = true;
                results.innerHTML = '';
                setFeedback('Interrogation d\u2019Anilist…', 'loading');
                try {
                    const response = await fetch('../admin.php?anilist_lookup=1&id=' + encodeURIComponent(id));
                    const data = await response.json();
                    if (!data.success || !data.results.length) { setFeedback(data.message || 'Aucune série ne correspond à cet identifiant.', 'error'); return; }
                    setFeedback('');
                    results.innerHTML = data.results.map(m => window.animeResultHtml(m, 'wishlist')).join('');
                } catch (error) {
                    console.error('Erreur:', error);
                    setFeedback('Anilist est injoignable pour le moment.', 'error');
                } finally {
                    searching = false;
                }
            }

            button.addEventListener('click', runSearch);
            input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); runSearch(); } });
            lookupButton.addEventListener('click', runLookup);
            lookupInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); runLookup(); } });

            // Sélection d'un résultat : mémorise la fiche, ne l'ajoute pas
            // encore (l'ajout se fait via le bouton commun, cohérence avec le
            // formulaire manga qui a lui aussi un bouton "Ajouter" explicite).
            results.addEventListener('click', function (e) {
                const addBtn = e.target.closest('.anime-result-add');
                if (!addBtn) return;
                const row = addBtn.closest('.anime-result');
                const anilistId = row?.dataset.anilistId;
                if (!anilistId) return;

                // Lues depuis des attributs data-* dédiés (posés par
                // animeResultHtml()), plutôt que reconstruites depuis le texte
                // affiché : robuste face à un futur changement de gabarit.
                // Couverture distante lue sur l'image du résultat (affichage
                // seul dans la grille — jamais téléchargée à ce stade).
                const coverImg = row.querySelector('.anime-result-cover');
                const coverSrc = coverImg ? coverImg.getAttribute('src') || '' : '';
                pendingAnimeSelection = {
                    anilist_id:   anilistId,
                    title:        row.dataset.title || '',
                    studios_text: row.dataset.studiosText || '',
                    cover_url:    /^https:\/\//i.test(coverSrc) ? coverSrc : '',
                };

                results.querySelectorAll('.anime-result').forEach(r => r.classList.remove('is-selected'));
                row.classList.add('is-selected');
                setFeedback('Sélectionné : ' + pendingAnimeSelection.title + '. Cliquez sur « Ajouter à la liste ».', '');
            });
        })();
        // ── Mise à jour du DOM après changement de la wishlist ────────────────
        function renderWishlist(wishlist) {
            if (Array.isArray(wishlist)) wishlistData = wishlist;
            applyFiltersAndSort();
        }

        // ── Ajout rapide manga (nom / auteur / éditeur) ───────────────────────
        document.getElementById('add-to-wishlist-btn').addEventListener('click', async function() {
            const name      = document.getElementById('wishlist-name').value.trim();
            const author    = document.getElementById('wishlist-author').value.trim();
            const publisher = document.getElementById('wishlist-publisher').value.trim();
            if (!name || !author || !publisher) {
                showCustomAlert('Attention', 'Veuillez remplir les trois champs (ou passer par « Fiche complète… »).');
                return;
            }
            const data = await postForm({
                add_to_wishlist: 'true', wishlist_type: 'manga',
                wishlist_name: name, wishlist_author: author, wishlist_publisher: publisher
            });
            if (data.success) {
                document.getElementById('wishlist-name').value      = '';
                document.getElementById('wishlist-author').value    = '';
                document.getElementById('wishlist-publisher').value = '';
                renderWishlist(data.wishlist);
            } else {
                showCustomAlert('Erreur', data.message || 'Erreur.');
            }
        });

        // Ajout d'un animé : la fiche sélectionnée par la recherche
        // (obligatoire — pas de saisie libre).
        document.getElementById('wishlist-add-anime-btn').addEventListener('click', async function() {
            if (!pendingAnimeSelection) {
                showCustomAlert('Attention', 'Sélectionnez une série dans les résultats de recherche.');
                return;
            }
            const data = await postForm({
                add_to_wishlist: 'true', wishlist_type: 'anime',
                wishlist_name: pendingAnimeSelection.title,
                wishlist_studio: pendingAnimeSelection.studios_text,
                wishlist_anilist_id: pendingAnimeSelection.anilist_id,
                wishlist_cover_url: pendingAnimeSelection.cover_url || ''
            });
            if (data.success) {
                document.getElementById('wishlist-anime-search-input').value = '';
                document.getElementById('wishlist-anime-lookup-input').value = '';
                document.getElementById('wishlist-anime-results').innerHTML = '';
                document.getElementById('wishlist-anime-feedback').textContent = '';
                pendingAnimeSelection = null;
                renderWishlist(data.wishlist);
            } else {
                showCustomAlert('Erreur', data.message || 'Erreur.');
            }
        });

        // ─────────────────────────────────────────────────────────────────────
        // Modale « fiche de la liste d'envies » (mangas) : création complète
        // (index vide) ou édition d'une entrée existante.
        // ─────────────────────────────────────────────────────────────────────
        const entryModal   = document.getElementById('wishlist-entry-modal');
        const entryForm    = document.getElementById('wishlist-entry-form');
        const entryPreview = document.getElementById('wle-thumb-preview');
        const entryFile    = document.getElementById('wle-image');
        const entryRemove  = document.getElementById('wle-remove-image');
        const entryRemoveWrap = document.getElementById('wle-remove-image-wrap');
        let entryCurrentThumb = '';

        function setEntryPreview(src) {
            // Sans vignette : celle par défaut du site, comme partout ailleurs.
            entryPreview.innerHTML = src
                ? `<img src="${htmlEscape(src)}" alt="">`
                : `<img src="${WISHLIST_DEFAULT_THUMB}" alt="" class="is-default">`;
        }

        // Champs obligatoires du bloc Contributeurs, comme sur la fiche d'une
        // série : le nom de la première ligne « Auteur » et de la première
        // ligne « Éditeur » est requis (réévalué à chaque changement de rôle,
        // ajout ou suppression de ligne).
        function markRequiredContributors(container) {
            if (!container) return;
            const rows = [...container.querySelectorAll('.contributor-row')];
            rows.forEach(r => { r.querySelector('.contributor-name').required = false; });
            ['auteur', 'editeur'].forEach(role => {
                const row = rows.find(r => r.querySelector('.contributor-role').value === role);
                if (row) row.querySelector('.contributor-name').required = true;
            });
        }
        // Au moins un Auteur ET un Éditeur nommés ? Sinon, message explicite.
        function contributorsMissingRoles(container) {
            const list = typeof contributorsReadList === 'function' ? contributorsReadList(container) : [];
            const missing = [];
            if (!list.some(c => c.role === 'auteur'))  missing.push('« Auteur »');
            if (!list.some(c => c.role === 'editeur')) missing.push('« Éditeur »');
            return missing;
        }
        ['wle-contributors', 'asfw-contributors'].forEach(id => {
            const container = document.getElementById(id);
            if (!container) return;
            container.addEventListener('change', () => markRequiredContributors(container));
            new MutationObserver(() => markRequiredContributors(container)).observe(container, { childList: true });
        });

        function openWishlistEntryModal(index, item) {
            entryForm.reset();
            const isNew = index === null;
            document.getElementById('wishlist-entry-title').textContent = isNew ? 'Nouvelle envie' : 'Modifier l’envie';
            document.getElementById('wle-index').value            = isNew ? '' : index;
            document.getElementById('wle-name').value             = item.name || '';
            document.getElementById('wle-mangaupdates-url').value = item.mangaupdates_url || '';
            document.getElementById('wle-manganews-url').value    = item.manganews_url || '';
            document.getElementById('wle-syngas-uid').value       = item.syngas_uid || '';

            const container = document.getElementById('wle-contributors');
            if (typeof contributorsRenderList === 'function') {
                contributorsRenderList(container, Array.isArray(item.contributors) ? item.contributors : []);
                markRequiredContributors(container);
            }

            entryCurrentThumb = item.thumb ? wishlistThumbSrc(item.thumb) : '';
            setEntryPreview(entryCurrentThumb);
            // « Retirer » n'a de sens que pour une vignette locale existante.
            entryRemoveWrap.hidden = !(item.image && item.thumb && !/^https?:/i.test(item.thumb));
            entryModal.classList.add('modal-active');
            document.getElementById('wle-name').focus();
        }

        // Aperçu immédiat du fichier choisi (sans téléversement).
        entryFile.addEventListener('change', () => {
            const file = entryFile.files && entryFile.files[0];
            if (file) {
                entryRemove.checked = false;
                setEntryPreview(URL.createObjectURL(file));
            } else {
                setEntryPreview(entryCurrentThumb);
            }
        });
        entryRemove.addEventListener('change', () => {
            if (entryRemove.checked) {
                entryFile.value = '';
                setEntryPreview('');
            } else {
                setEntryPreview(entryCurrentThumb);
            }
        });

        // Ouverture en mode création, préremplie avec l'ajout rapide.
        document.getElementById('open-wishlist-entry-modal').addEventListener('click', () => {
            const contributors = [];
            const author    = document.getElementById('wishlist-author').value.trim();
            const publisher = document.getElementById('wishlist-publisher').value.trim();
            contributors.push({ name: author, role: 'auteur' });
            contributors.push({ name: publisher, role: 'editeur' });
            openWishlistEntryModal(null, {
                name: document.getElementById('wishlist-name').value.trim(),
                contributors
            });
        });

        entryForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const missing = contributorsMissingRoles(document.getElementById('wle-contributors'));
            if (!document.getElementById('wle-name').value.trim() || missing.length) {
                showCustomAlert('Champs obligatoires', 'Le nom de la série, au moins un contributeur « Auteur » et au moins un contributeur « Éditeur » sont requis.');
                return;
            }
            const submitBtn = document.getElementById('wle-submit-btn');
            const originalLabel = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Enregistrement…';
            try {
                const formData = new FormData(this);
                formData.set('save_wishlist_entry', 'true');
                const data = await postForm(formData);
                if (!data.success) {
                    showCustomAlert('Erreur', data.message || "L'enregistrement a échoué.");
                    return;
                }
                const wasNew = document.getElementById('wle-index').value === '';
                entryModal.classList.remove('modal-active');
                if (wasNew) {
                    document.getElementById('wishlist-name').value      = '';
                    document.getElementById('wishlist-author').value    = '';
                    document.getElementById('wishlist-publisher').value = '';
                }
                renderWishlist(data.wishlist);
            } catch (err) {
                console.error('Erreur:', err);
                showCustomAlert('Erreur', "Une erreur est survenue lors de l'enregistrement.");
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = originalLabel;
            }
        });

        // ─────────────────────────────────────────────────────────────────────
        // Actions des cartes (délégation : un seul écouteur pour toute la
        // grille, quel que soit le nombre de rendus).
        // ─────────────────────────────────────────────────────────────────────
        document.getElementById('wishlist-list').addEventListener('click', async function(e) {
            const btn = e.target.closest('button[data-action]');
            if (!btn) return;
            const index = parseInt(btn.dataset.index, 10);
            const item  = wishlistData[index];
            if (!item) return;

            if (btn.dataset.action === 'add') {
                if (item.type === 'anime') {
                    // Animé : import Anilist direct, la modale ne sert que de
                    // confirmation avant l'appel réseau.
                    pendingAddFromWishlist = { index, item };
                    document.getElementById('afw-anime-series-name').textContent = item.name;
                    document.getElementById('add-from-wishlist-modal').classList.add('modal-active');
                    return;
                }
                // Manga : modale d'ajout de série préremplie avec toute la
                // fiche. Aucune écriture tant que ce formulaire n'est pas
                // enregistré.
                openAddSeriesFromWishlistModal(index, item);
                return;
            }

            if (btn.dataset.action === 'edit') {
                openWishlistEntryModal(index, item);
                return;
            }

            if (btn.dataset.action === 'remove') {
                const confirmed = await showCustomConfirm('Retirer des envies', `Retirer « ${item.name} » de la liste d'envies ?`);
                if (!confirmed) return;
                const data = await postForm({ remove_from_wishlist: 'true', index });
                if (data.success) renderWishlist(data.wishlist);
                else showCustomAlert('Erreur', data.message || 'La suppression a échoué.');
            }
        });

        // ── Ajouter depuis wishlist → collection (animés uniquement) ──────────
        document.getElementById('afw-confirm-btn').addEventListener('click', async function() {
            if (!pendingAddFromWishlist) return;
            const { index } = pendingAddFromWishlist;
            const confirmBtn = this;
            confirmBtn.disabled = true;
            try {
                const data = await postForm({ add_from_wishlist: 'true', index });
                document.getElementById('add-from-wishlist-modal').classList.remove('modal-active');

                if (!data.success) {
                    showCustomAlert('Erreur', data.message || "L'ajout à la collection a échoué.");
                    return;
                }

                // Import déjà terminé côté serveur : direction admin.php,
                // avec la modale d'édition de la série fraîchement importée
                // déjà ouverte (pour le tag favori, la note, les
                // revisionnages, etc.).
                const params = new URLSearchParams({ type: 'anime' });
                if (data.series_id) params.set('open_edit_anime', data.series_id);
                window.location.href = '../admin.php?' + params.toString();
            } finally {
                confirmBtn.disabled = false;
                pendingAddFromWishlist = null;
            }
        });

        document.getElementById('afw-cancel-btn').addEventListener('click', () => {
            document.getElementById('add-from-wishlist-modal').classList.remove('modal-active');
            pendingAddFromWishlist = null;
        });

        // ── Ajouter une série depuis la wishlist (mangas) ──────────────────────
        // Ouvre #add-series-from-wishlist-modal, préremplie avec TOUTE la
        // fiche de l'entrée : contributeurs, liens, identifiant Syngas et
        // vignette (reprise par défaut). Rien n'est envoyé au serveur à
        // l'ouverture : seule la soumission du formulaire crée la série et
        // retire l'entrée, en une seule opération côté PHP.
        let pendingAddSeriesFromWishlistIndex = null;

        function openAddSeriesFromWishlistModal(index, item) {
            pendingAddSeriesFromWishlistIndex = index;
            const form = document.getElementById('add-series-from-wishlist-form');
            form.reset();
            document.getElementById('asfw-index').value            = index;
            document.getElementById('asfw-name').value             = item.name || '';
            document.getElementById('asfw-mangaupdates-url').value = item.mangaupdates_url || '';
            document.getElementById('asfw-manganews-url').value    = item.manganews_url || '';
            document.getElementById('asfw-syngas-uid').value       = item.syngas_uid || '';

            const asfwContribContainer = document.getElementById('asfw-contributors');
            if (asfwContribContainer && typeof contributorsRenderList === 'function') {
                let prefill = Array.isArray(item.contributors) ? item.contributors : [];
                if (!prefill.length) {
                    if (item.author)    prefill.push({ name: item.author, role: 'auteur' });
                    if (item.publisher) prefill.push({ name: item.publisher, role: 'editeur' });
                }
                contributorsRenderList(asfwContribContainer, prefill);
                markRequiredContributors(asfwContribContainer);
            }

            // Vignette locale de l'entrée : proposée (et cochée) par défaut.
            const thumbWrap = document.getElementById('asfw-wishlist-thumb');
            const hasLocal  = !!(item.image && item.thumb && !/^https?:/i.test(item.thumb));
            thumbWrap.hidden = !hasLocal;
            document.getElementById('asfw-use-wishlist-image').checked = hasLocal;
            document.getElementById('asfw-wishlist-thumb-img').src = hasLocal ? wishlistThumbSrc(item.thumb) : '';

            document.getElementById('add-series-from-wishlist-modal').classList.add('modal-active');
        }

        // Suggestions d'autocomplétion (assets/js/admin/autocomplete.js) sur
        // les champs de cette modale, identiques à celles de admin.php :
        // window.currentSeriesType n'est pas posé sur cette page, donc
        // currentViewType() retombe sur 'manga' par défaut — cohérent, ces
        // modales ne créant que des mangas. Les contributeurs sont gérés par
        // le bloc dynamique « Contributeurs » (assets/js/admin/contributors.js),
        // qui gère sa propre autocomplétion par ligne.
        setupAutocomplete('asfw-name', ['name']);
        setupMultiAutocomplete('asfw-categories', ['categories']);
        setupMultiAutocomplete('asfw-genres', ['genres']);

        // Mêmes suggestions sur le formulaire d'ajout rapide manga.
        setupAutocomplete('wishlist-author', ['contributors']);
        setupAutocomplete('wishlist-publisher', ['contributors']);

        document.getElementById('add-series-from-wishlist-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            if (pendingAddSeriesFromWishlistIndex === null) return;
            const missingRoles = contributorsMissingRoles(document.getElementById('asfw-contributors'));
            if (missingRoles.length) {
                showCustomAlert('Champs obligatoires', 'Au moins un contributeur « Auteur » et au moins un contributeur « Éditeur » sont requis.');
                return;
            }

            const submitBtn = document.getElementById('asfw-submit-btn');
            submitBtn.disabled = true;
            const originalLabel = submitBtn.textContent;
            submitBtn.textContent = 'Enregistrement…';

            try {
                const formData = new FormData(this);
                formData.set('add_series_from_wishlist', 'true');
                formData.set('index', pendingAddSeriesFromWishlistIndex);

                const data = await postForm(formData);

                if (!data.success) {
                    showCustomAlert('Erreur', data.message || "L'ajout à la collection a échoué.");
                    return;
                }

                document.getElementById('add-series-from-wishlist-modal').classList.remove('modal-active');
                pendingAddSeriesFromWishlistIndex = null;
                renderWishlist(data.wishlist);
                showCustomAlert('Ajoutée', data.message || 'Série ajoutée à votre collection.');
            } catch (err) {
                console.error('Erreur:', err);
                showCustomAlert('Erreur', "Une erreur est survenue lors de l'ajout à la collection.");
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = originalLabel;
            }
        });

        // ── Fermeture modales ─────────────────────────────────────────────────
        document.getElementById('close-wishlist-entry-modal').addEventListener('click', () => {
            entryModal.classList.remove('modal-active');
        });
        document.getElementById('close-add-from-wishlist-modal').addEventListener('click', () => {
            document.getElementById('add-from-wishlist-modal').classList.remove('modal-active');
        });
        document.getElementById('close-add-series-from-wishlist-modal').addEventListener('click', () => {
            document.getElementById('add-series-from-wishlist-modal').classList.remove('modal-active');
            pendingAddSeriesFromWishlistIndex = null;
        });

        window.addEventListener('click', e => {
            ['wishlist-entry-modal','add-from-wishlist-modal','add-series-from-wishlist-modal','custom-confirm-modal','custom-alert-modal'].forEach(id => {
                const m = document.getElementById(id);
                if (e.target === m) {
                    m.classList.remove('modal-active');
                    if (id === 'add-series-from-wishlist-modal') pendingAddSeriesFromWishlistIndex = null;
                }
            });
        });

        // ─────────────────────────────────────────────────────────────────────
        // Grille de cartes : filtre de type + recherche + tri
        // ─────────────────────────────────────────────────────────────────────
        // Réinitialisé à « les deux » à chaque visite (pas de mémorisation),
        // à l'image du filtre de type de la page « Critiques ».
        const ICON = (name, color) => `<img src="https://api.iconify.design/mdi/${name}.svg?color=${encodeURIComponent(color)}" width="18" height="18" alt="">`;

        // Libellé « auteur / studio » d'une carte.
        function wishlistCardMeta(item) {
            if (item.type === 'anime') return item.studio || '';
            return item.author || '';
        }

        function wishlistCardHtml(item, index) {
            const isAnime = item.type === 'anime';
            const typeDef = (window.seriesTypes && window.seriesTypes[item.type || 'manga']) || null;
            const typeColor = typeDef ? typeDef.color : 'var(--primary-color)';
            const thumb = wishlistThumbSrc(item.thumb);
            const meta  = wishlistCardMeta(item);

            // Sans vignette (ou vignette cassée) : vignette par défaut du site,
            // comme sur les cartes de la collection.
            const cover = thumb
                ? `<img class="wishlist-card-img" src="${htmlEscape(thumb)}" alt="" loading="lazy" referrerpolicy="no-referrer"
                        onerror="this.onerror=null;this.src='${WISHLIST_DEFAULT_THUMB}';this.classList.add('is-default')">`
                : `<img class="wishlist-card-img is-default" src="${WISHLIST_DEFAULT_THUMB}" alt="" loading="lazy">`;

            // Liens externes (mangas) : mêmes logos que les cartes de la collection.
            const links = [];
            if (!isAnime && safeHttpUrl(item.mangaupdates_url)) {
                links.push(`<a href="${htmlEscape(safeHttpUrl(item.mangaupdates_url))}" target="_blank" rel="noopener" title="Voir sur MangaUpdates"><img src="../assets/img/mulogo.png" alt="MangaUpdates"></a>`);
            }
            if (!isAnime && safeHttpUrl(item.manganews_url)) {
                links.push(`<a href="${htmlEscape(safeHttpUrl(item.manganews_url))}" target="_blank" rel="noopener" title="Voir sur Manga News"><img src="../assets/img/mnlogo.png" alt="Manga News"></a>`);
            }
            if (!isAnime && item.syngas_uid && window.syngasSiteUrl) {
                links.push(`<a href="${htmlEscape(window.syngasSiteUrl + 'serie.php?id=' + encodeURIComponent(item.syngas_uid))}" target="_blank" rel="noopener" title="Voir sur Syngas"><img src="../assets/img/synlogo.png" alt="Syngas"></a>`);
            }
            if (isAnime && item.anilist_id) {
                links.push(`<a href="https://anilist.co/anime/${encodeURIComponent(item.anilist_id)}" target="_blank" rel="noopener" title="Voir sur Anilist"><img src="../assets/img/anilogo.png" alt="Anilist"></a>`);
            }

            // Info-bulle : tous les contributeurs (manga) ou le studio (animé).
            const tooltip = isAnime
                ? [item.name, item.studio].filter(Boolean).join('\n')
                : [item.name, ...(item.contributors || []).map(c => {
                      const role = c.role === 'autre' ? (c.role_custom || 'Autre') : ((window.contributorRoles || {})[c.role] || 'Rôle non précisé');
                      return `${c.name} — ${role}`;
                  })].join('\n');

            return `
                <article class="wishlist-card" data-index="${index}" style="--type-color:${htmlEscape(typeColor)}" title="${htmlEscape(tooltip)}">
                    <!-- Vignette carrée à gauche (même format que les cartes de la
                         collection), informations et actions à droite. -->
                    <div class="wishlist-card-cover">${cover}</div>
                    <div class="wishlist-card-main">
                    <div class="wishlist-card-body">
                        <div class="wishlist-card-top">
                            ${typeDef ? `<span class="wishlist-card-type">${htmlEscape(typeDef.label)}</span>` : ''}
                            ${links.length ? `<div class="wishlist-card-links">${links.join('')}</div>` : ''}
                        </div>
                        <h3 class="wishlist-card-title">${htmlEscape(item.name)}</h3>
                        <p class="wishlist-card-meta">${meta ? htmlEscape(meta) : `<em>${isAnime ? 'studio non annoncé' : 'auteur inconnu'}</em>`}</p>
                    </div>
                    <div class="wishlist-card-actions">
                        <button type="button" class="wishlist-card-btn is-add" data-action="add" data-index="${index}" title="Ajouter à la collection" aria-label="Ajouter « ${htmlEscape(item.name)} » à la collection">
                            ${ICON('bookshelf', '#4ade80')}
                        </button>
                        ${isAnime ? '' : `
                        <button type="button" class="wishlist-card-btn is-edit" data-action="edit" data-index="${index}" title="Modifier la fiche" aria-label="Modifier « ${htmlEscape(item.name)} »">
                            ${ICON('pencil', '#c084fc')}
                        </button>`}
                        <button type="button" class="wishlist-card-btn is-remove" data-action="remove" data-index="${index}" title="Retirer des envies" aria-label="Retirer « ${htmlEscape(item.name)} » des envies">
                            ${ICON('heart-off', '#f87171')}
                        </button>
                    </div>
                    </div>
                </article>
            `;
        }

        function applyFiltersAndSort() {
            const term       = normalizeString(document.getElementById('wishlist-search').value);
            const typeFilter = document.getElementById('wishlist-type-filter').value;
            const sortField  = document.getElementById('wishlist-sort-field').value;
            const sortOrder  = document.getElementById('wishlist-sort-order').value;

            // Filtrage
            let filtered = wishlistData.map((item, originalIndex) => ({ ...item, _originalIndex: originalIndex }));
            if (typeFilter !== 'all') {
                filtered = filtered.filter(item => (item.type || 'manga') === typeFilter);
            }
            if (term) {
                filtered = filtered.filter(item =>
                    normalizeString(item.name).includes(term) ||
                    normalizeString(item.author).includes(term) ||
                    normalizeString(item.publisher).includes(term) ||
                    normalizeString(item.studio).includes(term) ||
                    (item.contributors || []).some(c => normalizeString(c.name).includes(term))
                );
            }

            // Tri : « auteur » se lit aussi comme « studio » pour un animé.
            filtered.sort((a, b) => {
                const fieldA = sortField === 'author' && a.type === 'anime' ? (a.studio || '') : (a[sortField] || '');
                const fieldB = sortField === 'author' && b.type === 'anime' ? (b.studio || '') : (b[sortField] || '');
                const cmp = normalizeString(fieldA).localeCompare(normalizeString(fieldB), 'fr')
                         || normalizeString(a.name).localeCompare(normalizeString(b.name), 'fr');
                return sortOrder === 'asc' ? cmp : -cmp;
            });

            const container = document.getElementById('wishlist-list');
            document.getElementById('wishlist-count').textContent = wishlistData.length;

            if (filtered.length === 0) {
                container.innerHTML = wishlistData.length === 0
                    ? '<p class="loans-empty wishlist-empty">Votre liste d\'envies est vide. ✨</p>'
                    : '<p class="loans-empty wishlist-empty">Aucun résultat pour ces filtres.</p>';
                return;
            }

            container.innerHTML = filtered.map(item => wishlistCardHtml(item, item._originalIndex)).join('');
        }

        document.getElementById('wishlist-search').addEventListener('input', applyFiltersAndSort);
        document.getElementById('wishlist-type-filter').addEventListener('change', applyFiltersAndSort);
        document.getElementById('wishlist-sort-field').addEventListener('change', applyFiltersAndSort);
        document.getElementById('wishlist-sort-order').addEventListener('change', applyFiltersAndSort);

        applyFiltersAndSort();

        // ─────────────────────────────────────────────────────────────────────
        // Rattrapage des animés ajoutés avant le téléchargement automatique
        // des vignettes (ou sans studio) : lots de 10 fiches, l'un après
        // l'autre, la grille se met à jour au fil de l'eau. S'arrête de
        // lui-même quand il n'y a plus rien à traiter, ou à la première
        // erreur (Anilist injoignable, quota atteint…) — retenté à la
        // prochaine visite.
        // ─────────────────────────────────────────────────────────────────────
        (async function backfillAnimeEntries() {
            const needs = it => it.type === 'anime' && parseInt(it.anilist_id, 10) > 0
                && (!it.image || !it.thumb || /^https?:/i.test(it.thumb) || !it.studio);
            if (!wishlistData.some(needs)) return;
            const skip = [];
            for (let guard = 0; guard < 50; guard++) {
                let data;
                try {
                    const body = new URLSearchParams({ wishlist_anime_backfill: 'true' });
                    skip.forEach(id => body.append('skip[]', id));
                    data = await postForm(body);
                } catch (err) {
                    console.warn('Rattrapage des vignettes animées interrompu :', err);
                    return;
                }
                if (Array.isArray(data.wishlist)) renderWishlist(data.wishlist);
                if (!data.success) { console.warn('Rattrapage des vignettes animées :', data.message); return; }
                (data.processed || []).forEach(id => skip.push(id));
                if (!data.remaining || !(data.processed || []).length) return;
            }
        })();

        // ─────────────────────────────────────────────────────────────────────
        // Module « Déplacer dans la liste » : recherche instantanée dans la
        // collection (window.moveCandidates), sélection d'une série, puis
        // confirmation (avec avertissement bloquant si des tomes sont
        // actuellement prêtés) avant le déplacement effectif.
        // ─────────────────────────────────────────────────────────────────────
        (function setupMoveToWishlist() {
            const searchInput  = document.getElementById('wishlist-move-search');
            const resultsBox   = document.getElementById('wishlist-move-results');
            const selectedWrap = document.getElementById('wishlist-move-selected');
            const selectedThumb = document.getElementById('wishlist-move-selected-thumb');
            const selectedName = document.getElementById('wishlist-move-selected-name');
            const selectedMeta = document.getElementById('wishlist-move-selected-meta');
            const clearBtn     = document.getElementById('wishlist-move-clear-btn');
            const confirmBtn   = document.getElementById('wishlist-move-confirm-btn');

            const modal        = document.getElementById('move-to-wishlist-modal');
            const modalName    = document.getElementById('mtw-series-name');
            const modalWarning = document.getElementById('mtw-loans-warning');
            const modalConfirm = document.getElementById('mtw-confirm-btn');
            const modalCancel  = document.getElementById('mtw-cancel-btn');
            const modalClose   = document.getElementById('close-move-to-wishlist-modal');

            let selected = null; // candidat choisi : { id, name, type, meta, thumb }

            function selectSeries(item) {
                selected = item;
                searchInput.value = '';
                resultsBox.style.display = 'none';
                resultsBox.innerHTML = '';
                searchInput.hidden = true;

                const typeDef = (window.seriesTypes && window.seriesTypes[item.type || 'manga']) || null;
                const typeBadge = typeDef
                    ? `<span class="suggestion-type-badge wishlist-type-badge" style="--type-color:${typeDef.color}">${htmlEscape(typeDef.label)}</span>`
                    : '';

                selectedThumb.src = item.thumb ? ('../' + item.thumb) : '../assets/img/logo.png';
                selectedName.innerHTML = htmlEscape(item.name) + ' ' + typeBadge;
                selectedMeta.textContent = item.meta || '';
                selectedWrap.hidden = false;
                confirmBtn.disabled = false;
            }

            function clearSelection() {
                selected = null;
                selectedWrap.hidden = true;
                searchInput.hidden = false;
                confirmBtn.disabled = true;
                searchInput.value = '';
                searchInput.focus();
            }

            clearBtn.addEventListener('click', clearSelection);

            function renderResults() {
                const term = normalizeString(searchInput.value);
                if (term === '') {
                    resultsBox.style.display = 'none';
                    resultsBox.innerHTML = '';
                    return;
                }
                const matches = (window.moveCandidates || [])
                    .filter(c => normalizeString(c.name).includes(term))
                    .slice(0, 8);

                if (matches.length === 0) {
                    resultsBox.innerHTML = '<div class="wishlist-move-result-empty">Aucune série ne correspond.</div>';
                    resultsBox.style.display = 'block';
                    return;
                }
                resultsBox.innerHTML = '';
                matches.forEach(c => {
                    const typeDef = (window.seriesTypes && window.seriesTypes[c.type || 'manga']) || null;
                    const typeBadge = typeDef
                        ? `<span class="suggestion-type-badge wishlist-type-badge" style="--type-color:${typeDef.color}">${htmlEscape(typeDef.label)}</span>`
                        : '';
                    const row = document.createElement('div');
                    row.className = 'wishlist-move-result-row';
                    const thumb = c.thumb ? ('../' + c.thumb) : '../assets/img/logo.png';
                    row.innerHTML = `
                        <img class="wishlist-move-result-thumb" src="${htmlEscape(thumb)}" alt="" loading="lazy">
                        <span>${htmlEscape(c.name)} ${typeBadge}</span>
                    `;
                    row.addEventListener('click', () => selectSeries(c));
                    resultsBox.appendChild(row);
                });
                resultsBox.style.display = 'block';
            }

            searchInput.addEventListener('input', renderResults);
            searchInput.addEventListener('focus', renderResults);
            document.addEventListener('click', e => {
                if (!searchInput.contains(e.target) && !resultsBox.contains(e.target)) {
                    resultsBox.style.display = 'none';
                }
            });

            // ── Confirmation (avec avertissement bloquant si prêts en cours) ────
            confirmBtn.addEventListener('click', async function () {
                if (!selected) return;
                modalName.textContent = selected.name;
                modalWarning.style.display = 'none';
                modal.classList.add('modal-active');

                try {
                    const res = await fetch('page-wishlist.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `check_series_loans=true&series_id=${encodeURIComponent(selected.id)}`
                    });
                    const data = await res.json();
                    if (data.success && data.has_loans) {
                        modalWarning.style.display = '';
                    }
                } catch (err) {
                    console.error('Erreur de vérification des prêts :', err);
                }
            });

            function closeModal() { modal.classList.remove('modal-active'); }
            modalCancel.addEventListener('click', closeModal);
            modalClose.addEventListener('click', closeModal);
            modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

            modalConfirm.addEventListener('click', async function () {
                if (!selected) return;
                modalConfirm.disabled = true;
                try {
                    const res = await fetch('page-wishlist.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `move_to_wishlist=true&series_id=${encodeURIComponent(selected.id)}`
                    });
                    const data = await res.json();
                    closeModal();
                    if (!data.success) {
                        showCustomAlert('Erreur', data.message || 'Le déplacement a échoué.');
                        return;
                    }
                    // Retire le candidat déplacé de la liste des candidats (évite
                    // de le proposer à nouveau sans recharger la page), met à
                    // jour la liste d'envies affichée, et réinitialise le module.
                    window.moveCandidates = (window.moveCandidates || []).filter(c => c.id !== selected.id);
                    renderWishlist(data.wishlist);
                    clearSelection();
                    showCustomAlert('Déplacée', data.message || 'Série déplacée vers la liste d\'envies.');
                } finally {
                    modalConfirm.disabled = false;
                }
            });
        })();
    </script>
</body>
</html>
