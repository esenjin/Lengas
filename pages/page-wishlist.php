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
require 'fonctions/series.php';
require 'fonctions/volumes.php';
require 'fonctions/anime.php';
require 'fonctions/episodes.php';
require 'fonctions/wishlist.php';
require 'fonctions/options.php';
require 'fonctions/loans.php'; // pour series_has_active_loans() (module « Déplacer dans la liste »)

$data    = load_data();
$options = load_options();

// ── Actions AJAX ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_to_wishlist'])) {
        $type       = sanitize_series_type($_POST['wishlist_type'] ?? '');
        $name       = trim($_POST['wishlist_name'] ?? '');
        $author     = trim($_POST['wishlist_author'] ?? '');
        $publisher  = trim($_POST['wishlist_publisher'] ?? '');
        $studio     = trim($_POST['wishlist_studio'] ?? '');
        $anilist_id = trim($_POST['wishlist_anilist_id'] ?? '');
        $wishlist   = load_wishlist();
        $result     = add_to_wishlist($wishlist, $name, $author, $publisher, $type, $studio, $anilist_id);
        if ($result['success']) save_wishlist($result['wishlist']);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    if (isset($_POST['remove_from_wishlist'])) {
        $index    = $_POST['index'] ?? 0;
        $wishlist = load_wishlist();
        $result   = remove_from_wishlist($wishlist, $index);
        if ($result['success']) save_wishlist($result['wishlist']);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    if (isset($_POST['edit_wishlist'])) {
        $index     = (int)($_POST['index'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $author    = trim($_POST['author'] ?? '');
        $publisher = trim($_POST['publisher'] ?? '');
        $studio    = trim($_POST['studio'] ?? '');
        $wishlist  = load_wishlist();
        $result    = edit_wishlist_item($wishlist, $index, $name, $author, $publisher, $studio);
        if ($result['success']) save_wishlist($result['wishlist']);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    // Passage en collection : la manière dont diverge selon le type.
    //   • manga : ouvre la modale d'ajout de série de CETTE page, préremplie
    //     avec le nom/auteur/éditeur de l'entrée — rien n'est écrit en base
    //     tant que ce formulaire n'est pas enregistré (cf. action
    //     'add_series_from_wishlist' ci-dessous). L'entrée n'est donc jamais
    //     retirée de la liste d'envies sur un simple clic sur « + » : cette
    //     branche se contente de renvoyer l'item à afficher, sans écriture.
    //   • anime : import COMPLET et immédiat depuis Anilist, sans quitter
    //     cette page — on peut ainsi enchaîner plusieurs animés d'affilée
    //     sans aller-retour entre les deux pages.
    if (isset($_POST['add_from_wishlist'])) {
        $index    = (int)($_POST['index'] ?? 0);
        $wishlist = load_wishlist();
        $item     = $wishlist[$index] ?? null;

        if (!$item) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Entrée introuvable.']);
            exit;
        }

        if (sanitize_series_type($item['type'] ?? '') === 'anime') {
            // Écriture ciblée : add_anime_from_wishlist() délègue à
            // add_anime_series(), qui upserte elle-même la série (+ ses
            // épisodes, + ses éditions si la clé est fournie) en base au
            // moment de l'appel. $result['data'] ne sert plus qu'à renvoyer
            // 'series_id' pour la redirection front, jamais à une
            // resynchronisation globale.
            $result = add_anime_from_wishlist($data, $wishlist, $index);
            if ($result['success']) {
                save_wishlist($result['wishlist']);
            }
            header('Content-Type: application/json');
            echo json_encode([
                'success'   => $result['success'],
                'message'   => $result['message'] ?? '',
                'wishlist'  => $result['wishlist'] ?? $wishlist,
                'type'      => 'anime',
                // Identifiant de la série fraîchement importée : permet au
                // front de rediriger vers admin.php avec sa modale d'édition
                // déjà ouverte (favoris, note, revisionnages… à compléter).
                'series_id' => $result['series_id'] ?? '',
            ]);
            exit;
        }

        // Manga : aucune écriture ici. On renvoie simplement l'item pour que
        // le front préremplisse la modale d'ajout de série locale ; c'est
        // l'enregistrement de ce formulaire (action 'add_series_from_wishlist'
        // ci-dessous) qui créera la série ET retirera l'entrée, en une seule
        // opération atomique.
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'type'    => 'manga',
            'index'   => $index,
            'item'    => $item,
        ]);
        exit;
    }

    // Enregistrement du formulaire d'ajout de série préouvert depuis la
    // liste d'envies (manga uniquement — les animés passent par la branche
    // ci-dessus, sans formulaire à remplir). Même jeu de champs que la
    // modale d'ajout de series.php sur admin.php, upload d'image compris.
    // add_series_from_wishlist() garantit que l'entrée de la wishlist n'est
    // retirée QUE si la série a effectivement été créée.
    if (isset($_POST['add_series_from_wishlist'])) {
        $index = (int)($_POST['index'] ?? 0);

        $name               = trim($_POST['name'] ?? '');
        $author             = trim($_POST['author'] ?? '');
        $publisher          = trim($_POST['publisher'] ?? '');
        $other_contributors = trim($_POST['other_contributors'] ?? '');
        $categories         = trim($_POST['categories'] ?? '');
        $genres             = trim($_POST['genres'] ?? '');
        $mangaupdates_url   = trim($_POST['mangaupdates_url'] ?? '');
        $babelio_url        = trim($_POST['babelio_url'] ?? '');
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

        // Initialiser $image à null par défaut, même logique que admin.php :
        // un échec de téléversement n'empêche pas l'ajout de la série.
        $image         = null;
        $error_message = null;
        if (!empty($_FILES['image']['name'])) {
            $image = upload_image($_FILES['image'], $error_message);
            if ($image === false) {
                $image = null;
            }
        }

        $wishlist = load_wishlist();
        $item     = $wishlist[$index] ?? null;

        if (!$item || sanitize_series_type($item['type'] ?? '') !== 'manga') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Entrée introuvable ou invalide.']);
            exit;
        }

        $series_fields = [
            'name'               => $name,
            'author'             => $author,
            'publisher'          => $publisher,
            'other_contributors' => $other_contributors,
            'categories'         => $categories,
            'genres'             => $genres,
            'mangaupdates_url'   => $mangaupdates_url,
            'babelio_url'        => $babelio_url,
            'mature'             => $mature,
            'favorite'           => $favorite,
            'volumes_count'      => $volumes_count,
            'volumes_status'     => $volumes_status,
            'all_collector'      => $all_collector,
            'last_volume'        => $last_volume,
            'image'              => $image,
            'status'             => $status,
            'read_elsewhere'     => $read_elsewhere,
            'reading_abandoned'  => $reading_abandoned,
            'rating'             => $rating,
            'reread_count'       => $reread_count,
        ];

        $result = add_series_from_wishlist($data, $wishlist, $index, $series_fields);

        if ($result['success']) {
            save_wishlist($result['wishlist']);
            // Réchauffer le cache MangaUpdates pour la nouvelle série,
            // même geste que l'ajout classique depuis admin.php.
            if ($mangaupdates_url !== '') {
                $mu_id = mangaupdates_get_id_from_url($mangaupdates_url);
                if ($mu_id !== null) @mangaupdates_get_volumes($mu_id, true);
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success'   => $result['success'],
            'message'   => $result['message'] ?? '',
            'wishlist'  => $result['wishlist'] ?? $wishlist,
            'series_id' => $result['series_id'] ?? '',
        ]);
        exit;
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
    // liste d'envies avec les informations déjà connues.
    if (isset($_POST['move_to_wishlist'])) {
        $series_id       = $_POST['series_id'] ?? '';
        $current_wishlist = load_wishlist();
        $result          = move_series_to_wishlist($data, $current_wishlist, $series_id);
        header('Content-Type: application/json');
        if ($result['success']) {
            save_wishlist($result['wishlist']);
            $data = $result['data'];
        }
        echo json_encode($result);
        exit;
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
    return [
        'id'     => $s['id'],
        'name'   => $s['name'],
        'type'   => $type,
        'meta'   => ($type === 'anime')
            ? series_studios_text($s)
            : trim(($s['author'] ?? '') . (($s['publisher'] ?? '') !== '' ? ' · ' . $s['publisher'] : '')),
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
    <link rel="stylesheet" href="../assets/css/main.css">
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
                    <input type="text" id="wishlist-name"      placeholder="Nom de la série"   autocomplete="off">
                    <input type="text" id="wishlist-author"    placeholder="Auteur"             autocomplete="off">
                    <input type="text" id="wishlist-publisher" placeholder="Éditeur"            autocomplete="off">
                    <button id="add-to-wishlist-btn" class="button button-ats">Ajouter</button>
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

                <div class="wishlist-list" id="wishlist-list">
                    <!-- Rendu par JS au chargement -->
                </div>
            </section>

        </div>

        <!-- Modale édition entrée (mangas uniquement : un animé n'a rien à y
             faire, son studio étant fixé par Anilist et son titre également —
             il n'a d'ailleurs plus de bouton « Modifier » du tout). -->
        <div class="modal" id="edit-wishlist-modal">
            <div class="modal-content modal-content--narrow">
                <span class="close-modal" id="close-edit-wishlist-modal">&times;</span>
                <h2>Modifier l'entrée</h2>
                <form id="edit-wishlist-form" autocomplete="off">
                    <input type="hidden" id="edit-wishlist-index">

                    <p>Nom :</p>
                    <input type="text" id="edit-wishlist-name"      placeholder="Nom de la série">
                    <p>Auteur :</p>
                    <input type="text" id="edit-wishlist-author"    placeholder="Auteur">
                    <p>Éditeur :</p>
                    <input type="text" id="edit-wishlist-publisher" placeholder="Éditeur">

                    <button type="submit" class="button">Mettre à jour</button>
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
                    <p>Auteur :</p>
                    <input type="text" name="author" id="asfw-author" placeholder="Nom de l'auteur (obligatoire)" autocomplete="off" required>
                    <p>Éditeur :</p>
                    <input type="text" name="publisher" id="asfw-publisher" placeholder="Nom de l'éditeur (obligatoire)" autocomplete="off" required>
                    <p>Autres contributeurs :</p>
                    <input type="text" name="other_contributors" id="asfw-other-contributors" placeholder="Autres contributeurs (séparés par des virgules) (facultatif)" autocomplete="off">
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
                    <p>URL Babelio :</p>
                    <input type="text" name="babelio_url" id="asfw-babelio-url" placeholder="https://www.babelio.com/serie/… (ou …/livres/… pour un one-shot)" autocomplete="off">
                    <p class="hint"><a tabindex="0" data-hint="L'URL Babelio permet de connaître le nombre de tomes réellement parus en France, via le service Babengas (onglet « Vérification Babelio » de la page Outils). Sur babelio.com, ouvrez la fiche SÉRIE (adresse en /serie/…) et copiez l'URL complète. Pour un one-shot (un seul tome, sans fiche série), collez l'adresse de la fiche du tome (/livres/…).">À quoi ça sert ? Où la trouver ?</a></p>
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

    <script src="../assets/js/admin/anime.js"></script>
    <script>
        // Redirige l'endpoint d'autocomplétion (assets/js/admin/autocomplete.js)
        // vers admin.php, qui vit à la racine du projet — cette page est dans
        // pages/. Doit être posé AVANT le chargement d'autocomplete.js, qui
        // interroge cet endpoint dès son exécution.
        window.suggestionsEndpoint = '../admin.php';
    </script>
    <script src="../assets/js/admin/autocomplete.js"></script>
    <script>
        // Registre des types (libellés, couleurs) : seule source de vérité,
        // partagée avec admin.php et index.php. Aucun libellé ni couleur ne
        // doit être écrit en dur plus bas.
        window.seriesTypes = <?= json_encode(series_types_for_js()) ?>;

        let wishlistData = <?= json_encode(array_values($wishlist)) ?>;
        // Entrée animée en attente de confirmation d'import Anilist (modale
        // #add-from-wishlist-modal). Les mangas n'utilisent plus cette
        // variable : voir pendingAddSeriesFromWishlistIndex plus bas.
        let pendingAddFromWishlist = null;

        // Collection complète (mangas + animés), pour le module « Déplacer
        // dans la liste » : recherche instantanée côté client, comme les
        // autres sélecteurs de série du site.
        window.moveCandidates = <?= json_encode(array_values($move_candidates), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        function normalizeString(str) {
            return str.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
        }

        function htmlEscape(str) {
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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

        // ─────────────────────────────────────────────────────────────────────
        // Bascule de type (manga / animé) du formulaire d'ajout
        // ─────────────────────────────────────────────────────────────────────
        let addFormType = 'manga';
        // Fiche Anilist sélectionnée en attente d'ajout (recherche animé) :
        // { anilist_id, title, studios_text }. Vidée après ajout ou changement
        // de type/recherche.
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
                pendingAnimeSelection = {
                    anilist_id:   anilistId,
                    title:        row.dataset.title || '',
                    studios_text: row.dataset.studiosText || '',
                };

                results.querySelectorAll('.anime-result').forEach(r => r.classList.remove('is-selected'));
                row.classList.add('is-selected');
                setFeedback('Sélectionné : ' + pendingAnimeSelection.title + '. Cliquez sur « Ajouter à la liste ».', '');
            });
        })();

        // ─────────────────────────────────────────────────────────────────────
        // Attacher les événements aux boutons de la liste
        // ─────────────────────────────────────────────────────────────────────
        function attachWishlistEvents() {
            document.querySelectorAll('.add-from-wishlist-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const index = parseInt(this.dataset.index);
                    const item  = wishlistData[index];

                    if (item.type === 'anime') {
                        // Animé : import Anilist direct, la modale ne sert
                        // que de confirmation avant l'appel réseau.
                        pendingAddFromWishlist = { index, item };
                        document.getElementById('afw-anime-series-name').textContent = item.name;
                        document.getElementById('add-from-wishlist-modal').classList.add('modal-active');
                        return;
                    }

                    // Manga : ouverture directe de la modale d'ajout de
                    // série, préremplie avec les infos déjà connues. Aucune
                    // écriture n'a lieu tant que ce formulaire n'est pas
                    // enregistré (cf. openAddSeriesFromWishlistModal()).
                    openAddSeriesFromWishlistModal(index, item);
                });
            });

            // Bouton absent pour les animés (cf. rendu ci-dessus) : ce
            // gestionnaire ne concerne donc plus que des entrées manga.
            document.querySelectorAll('.edit-wishlist-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const index = parseInt(this.dataset.index);
                    const item  = wishlistData[index];
                    document.getElementById('edit-wishlist-index').value = index;
                    document.getElementById('edit-wishlist-name').value      = item.name;
                    document.getElementById('edit-wishlist-author').value    = item.author;
                    document.getElementById('edit-wishlist-publisher').value = item.publisher;
                    document.getElementById('edit-wishlist-modal').classList.add('modal-active');
                });
            });

            document.querySelectorAll('.remove-from-wishlist-btn').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const confirmed = await showCustomConfirm('Confirmation', 'Supprimer cette entrée de la liste ?');
                    if (!confirmed) return;
                    const index = this.dataset.index;
                    const res   = await fetch('page-wishlist.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `remove_from_wishlist=true&index=${index}`
                    });
                    const data = await res.json();
                    if (data.success) renderWishlist(data.wishlist);
                });
            });
        }

        // ── Mise à jour du DOM après changement de la wishlist ────────────────
        function renderWishlist(wishlist) {
            wishlistData = wishlist;
            applyFiltersAndSort();
        }

        // ── Ajouter à la liste (manga ou animé selon la bascule active) ───────
        document.getElementById('add-to-wishlist-btn').addEventListener('click', async function() {
            const name      = document.getElementById('wishlist-name').value.trim();
            const author    = document.getElementById('wishlist-author').value.trim();
            const publisher = document.getElementById('wishlist-publisher').value.trim();
            if (!name || !author || !publisher) {
                showCustomAlert('Attention', 'Veuillez remplir les trois champs.');
                return;
            }
            const res  = await fetch('page-wishlist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `add_to_wishlist=true&wishlist_type=manga&wishlist_name=${encodeURIComponent(name)}&wishlist_author=${encodeURIComponent(author)}&wishlist_publisher=${encodeURIComponent(publisher)}`
            });
            const data = await res.json();
            if (data.success) {
                document.getElementById('wishlist-name').value      = '';
                document.getElementById('wishlist-author').value    = '';
                document.getElementById('wishlist-publisher').value = '';
                renderWishlist(data.wishlist);
            } else {
                showCustomAlert('Erreur', data.message || 'Erreur.');
            }
        });

        // Bouton d'ajout commun au formulaire animé : ajoute la fiche
        // sélectionnée par la recherche (obligatoire — pas de saisie libre).
        document.getElementById('wishlist-add-anime-btn').addEventListener('click', async function() {
            if (!pendingAnimeSelection) {
                showCustomAlert('Attention', 'Sélectionnez une série dans les résultats de recherche.');
                return;
            }
            const res = await fetch('page-wishlist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `add_to_wishlist=true&wishlist_type=anime&wishlist_name=${encodeURIComponent(pendingAnimeSelection.title)}`
                    + `&wishlist_studio=${encodeURIComponent(pendingAnimeSelection.studios_text)}`
                    + `&wishlist_anilist_id=${encodeURIComponent(pendingAnimeSelection.anilist_id)}`
            });
            const data = await res.json();
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

        // ── Modifier une entrée (mangas uniquement) ───────────────────────────
        document.getElementById('edit-wishlist-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const index     = document.getElementById('edit-wishlist-index').value;
            const name      = document.getElementById('edit-wishlist-name').value;
            const author    = document.getElementById('edit-wishlist-author').value;
            const publisher = document.getElementById('edit-wishlist-publisher').value;
            const res = await fetch('page-wishlist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `edit_wishlist=true&index=${index}&name=${encodeURIComponent(name)}&author=${encodeURIComponent(author)}&publisher=${encodeURIComponent(publisher)}`
            });
            const data = await res.json();
            if (data.success) {
                renderWishlist(data.wishlist);
                document.getElementById('edit-wishlist-modal').classList.remove('modal-active');
            } else {
                showCustomAlert('Erreur', data.message || 'Erreur.');
            }
        });

        // ── Ajouter depuis wishlist → collection (animés uniquement) ──────────
        // Les mangas passent désormais par #add-series-from-wishlist-modal
        // (cf. plus bas), sans confirmation intermédiaire : le formulaire lui
        // même sert de point de confirmation, et rien n'est écrit tant qu'il
        // n'est pas soumis.
        document.getElementById('afw-confirm-btn').addEventListener('click', async function() {
            if (!pendingAddFromWishlist) return;
            const { index } = pendingAddFromWishlist;
            const confirmBtn = this;
            confirmBtn.disabled = true;
            try {
                const res = await fetch('page-wishlist.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `add_from_wishlist=true&index=${index}`
                });
                const data = await res.json();
                document.getElementById('add-from-wishlist-modal').classList.remove('modal-active');

                if (!data.success) {
                    showCustomAlert('Erreur', data.message || "L'ajout à la collection a échoué.");
                    return;
                }

                // Import déjà terminé côté serveur : direction admin.php,
                // avec la modale d'édition de la série fraîchement importée
                // déjà ouverte (pour le tag favori, la note, les
                // revisionnages, etc.) — plutôt que de rester ici où il n'y
                // a plus rien à faire pour cette série.
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
        // Ouvre #add-series-from-wishlist-modal directement, préremplie avec
        // les infos déjà connues de l'entrée. Rien n'est envoyé au serveur à
        // l'ouverture : seule la soumission du formulaire (plus bas) crée la
        // série et retire l'entrée, en une seule opération atomique côté PHP.
        let pendingAddSeriesFromWishlistIndex = null;

        function openAddSeriesFromWishlistModal(index, item) {
            pendingAddSeriesFromWishlistIndex = index;
            const form = document.getElementById('add-series-from-wishlist-form');
            form.reset();
            document.getElementById('asfw-index').value       = index;
            document.getElementById('asfw-name').value        = item.name || '';
            document.getElementById('asfw-author').value      = item.author || '';
            document.getElementById('asfw-publisher').value   = item.publisher || '';
            document.getElementById('add-series-from-wishlist-modal').classList.add('modal-active');
        }

        // Suggestions d'autocomplétion (assets/js/admin/autocomplete.js) sur
        // les champs de cette modale, identiques à celles de admin.php :
        // window.currentSeriesType n'est pas posé sur cette page, donc
        // currentViewType() retombe sur 'manga' par défaut — cohérent, cette
        // modale ne créant que des mangas.
        setupAutocomplete('asfw-name', ['name']);
        setupAutocomplete('asfw-author', ['author', 'other_contributors']);
        setupAutocomplete('asfw-publisher', ['publisher']);
        setupMultiAutocomplete('asfw-other-contributors', ['author', 'other_contributors']);
        setupMultiAutocomplete('asfw-categories', ['categories']);
        setupMultiAutocomplete('asfw-genres', ['genres']);

        // Mêmes suggestions sur le formulaire d'ajout rapide manga en haut de
        // page (déjà référencées par autocomplete.js, mais inertes tant que
        // ce script n'était pas chargé ici).
        setupAutocomplete('wishlist-author', ['author', 'other_contributors']);
        setupAutocomplete('wishlist-publisher', ['publisher']);

        document.getElementById('add-series-from-wishlist-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            if (pendingAddSeriesFromWishlistIndex === null) return;

            const submitBtn = document.getElementById('asfw-submit-btn');
            submitBtn.disabled = true;
            const originalLabel = submitBtn.textContent;
            submitBtn.textContent = 'Enregistrement…';

            try {
                const formData = new FormData(this);
                formData.set('add_series_from_wishlist', 'true');
                formData.set('index', pendingAddSeriesFromWishlistIndex);

                const res = await fetch('page-wishlist.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

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
        document.getElementById('close-edit-wishlist-modal').addEventListener('click', () => {
            document.getElementById('edit-wishlist-modal').classList.remove('modal-active');
        });
        document.getElementById('close-add-from-wishlist-modal').addEventListener('click', () => {
            document.getElementById('add-from-wishlist-modal').classList.remove('modal-active');
        });
        document.getElementById('close-add-series-from-wishlist-modal').addEventListener('click', () => {
            document.getElementById('add-series-from-wishlist-modal').classList.remove('modal-active');
            pendingAddSeriesFromWishlistIndex = null;
        });

        window.addEventListener('click', e => {
            ['edit-wishlist-modal','add-from-wishlist-modal','add-series-from-wishlist-modal','custom-confirm-modal','custom-alert-modal'].forEach(id => {
                const m = document.getElementById(id);
                if (e.target === m) {
                    m.classList.remove('modal-active');
                    if (id === 'add-series-from-wishlist-modal') pendingAddSeriesFromWishlistIndex = null;
                }
            });
        });

        // ── Filtre de type + recherche + tri ──────────────────────────────────
        // Réinitialisé à « les deux » à chaque visite (pas de mémorisation),
        // à l'image du filtre de type de la page « Critiques ».
        function applyFiltersAndSort() {
            const term      = normalizeString(document.getElementById('wishlist-search').value);
            const typeFilter = document.getElementById('wishlist-type-filter').value;
            const sortField = document.getElementById('wishlist-sort-field').value;
            const sortOrder = document.getElementById('wishlist-sort-order').value;

            // Filtrage
            let filtered = wishlistData.map((item, originalIndex) => ({ ...item, _originalIndex: originalIndex }));
            if (typeFilter !== 'all') {
                filtered = filtered.filter(item => (item.type || 'manga') === typeFilter);
            }
            if (term) {
                filtered = filtered.filter(item =>
                    normalizeString(item.name).includes(term) ||
                    normalizeString(item.author || '').includes(term) ||
                    normalizeString(item.publisher || '').includes(term) ||
                    normalizeString(item.studio || '').includes(term)
                );
            }

            // Tri : « auteur » se lit aussi comme « studio » pour un animé.
            filtered.sort((a, b) => {
                const fieldA = sortField === 'author' && a.type === 'anime' ? (a.studio || '') : (a[sortField] || '');
                const fieldB = sortField === 'author' && b.type === 'anime' ? (b.studio || '') : (b[sortField] || '');
                const valA = normalizeString(fieldA);
                const valB = normalizeString(fieldB);
                const cmp  = valA.localeCompare(valB, 'fr');
                return sortOrder === 'asc' ? cmp : -cmp;
            });

            // Rendre uniquement les éléments filtrés/triés
            const container = document.getElementById('wishlist-list');
            document.getElementById('wishlist-count').textContent = wishlistData.length;

            if (filtered.length === 0) {
                container.innerHTML = wishlistData.length === 0
                    ? '<p class="loans-empty">Votre liste d\'envies est vide. ✨</p>'
                    : '<p class="loans-empty">Aucun résultat pour ces filtres.</p>';
                return;
            }

            container.innerHTML = '';
            filtered.forEach(item => {
                const index   = item._originalIndex;
                const isAnime = item.type === 'anime';
                const typeDef = (window.seriesTypes && window.seriesTypes[item.type || 'manga']) || null;
                const div     = document.createElement('div');
                div.className   = 'wishlist-item';
                div.dataset.index = index;

                const meta = isAnime
                    ? (item.studio ? htmlEscape(item.studio) : '<em>studio inconnu</em>')
                    : `${htmlEscape(item.author)}${item.publisher ? ' · ' + htmlEscape(item.publisher) : ''}`;

                const typeBadge = typeDef
                    ? `<span class="suggestion-type-badge wishlist-type-badge" style="--type-color:${typeDef.color}">${htmlEscape(typeDef.label)}</span>`
                    : '';

                div.innerHTML = `
                    <div class="wishlist-item-info">
                        <span class="wishlist-series-name">${htmlEscape(item.name)} ${typeBadge}</span>
                        <span class="wishlist-series-meta">${meta}</span>
                    </div>
                    <div class="wishlist-item-actions">
                        <button class="add-from-wishlist-btn button-icon" title="Ajouter à la collection" data-index="${index}">
                            <img src="https://api.iconify.design/mdi/plus-circle.svg?color=%234ade80" width="18" height="18" alt="">
                        </button>
                        ${isAnime ? '' : `
                        <button class="edit-wishlist-btn button-icon" title="Modifier" data-index="${index}">
                            <img src="https://api.iconify.design/mdi/pencil.svg?color=%23c084fc" width="18" height="18" alt="">
                        </button>`}
                        <button class="remove-from-wishlist-btn button-icon" title="Supprimer" data-index="${index}">
                            <img src="https://api.iconify.design/mdi/trash-can.svg?color=%23f87171" width="18" height="18" alt="">
                        </button>
                    </div>
                `;
                container.appendChild(div);
            });

            attachWishlistEvents();
        }

        document.getElementById('wishlist-search').addEventListener('input', applyFiltersAndSort);
        document.getElementById('wishlist-type-filter').addEventListener('change', applyFiltersAndSort);
        document.getElementById('wishlist-sort-field').addEventListener('change', applyFiltersAndSort);
        document.getElementById('wishlist-sort-order').addEventListener('change', applyFiltersAndSort);

        // Rendu initial via JS (remplace le rendu PHP statique pour cohérence)
        applyFiltersAndSort();

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
                    wishlistData = data.wishlist;
                    applyFiltersAndSort();
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
