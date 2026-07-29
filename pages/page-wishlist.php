<?php

// Rétablit le dossier de travail à la racine du projet : cette page vit dans
// pages/ mais tous les chemins relatifs (config.php, includes/, bdd/, uploads/…)
// sont résolus depuis la racine.
chdir(__DIR__ . '/..');
require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require 'includes/themes.php';
require_once 'includes/anilist.php';
require 'fonctions/series.php';
require 'fonctions/volumes.php';
require 'fonctions/anime.php';
require 'fonctions/episodes.php';
require 'fonctions/wishlist.php';
require 'fonctions/options.php';

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
    //   • manga : redirige vers admin.php (fiche à compléter à la main) —
    //     comportement inchangé depuis les versions antérieures.
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
            $result = add_anime_from_wishlist($data, $wishlist, $index);
            if ($result['success']) {
                save_data($result['data']);
                save_wishlist($result['wishlist']);
            }
            header('Content-Type: application/json');
            echo json_encode([
                'success'   => $result['success'],
                'message'   => $result['message'] ?? '',
                'wishlist'  => $result['wishlist'] ?? $wishlist,
                'type'      => 'anime',
            ]);
            exit;
        }

        // Manga : on ne retire l'entrée qu'en confirmant l'intention, la
        // finalisation se faisant sur admin.php (préremplissage du formulaire).
        $result = remove_from_wishlist($wishlist, $index);
        if ($result['success']) save_wishlist($result['wishlist']);
        header('Content-Type: application/json');
        echo json_encode([
            'success'  => true,
            'type'     => 'manga',
            'item'     => $item,
            'wishlist' => $result['wishlist'],
        ]);
        exit;
    }
}

$wishlist = load_wishlist();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste d'envies — <?= htmlspecialchars($options['site_name'] ?? 'Lengas') ?></title>
    <meta name="description" content="Gestion de la liste d'envies.">
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

        <!-- Modale édition entrée -->
        <div class="modal" id="edit-wishlist-modal">
            <div class="modal-content modal-content--narrow">
                <span class="close-modal" id="close-edit-wishlist-modal">&times;</span>
                <h2>Modifier l'entrée</h2>
                <form id="edit-wishlist-form" autocomplete="off">
                    <input type="hidden" id="edit-wishlist-index">

                    <!-- Champs manga -->
                    <div id="edit-wishlist-fields-manga">
                        <p>Nom :</p>
                        <input type="text" id="edit-wishlist-name"      placeholder="Nom de la série">
                        <p>Auteur :</p>
                        <input type="text" id="edit-wishlist-author"    placeholder="Auteur">
                        <p>Éditeur :</p>
                        <input type="text" id="edit-wishlist-publisher" placeholder="Éditeur">
                    </div>

                    <!-- Champs animé : titre et identifiant Anilist figés (Anilist fait
                         autorité), seul le studio se corrige à la main. -->
                    <div id="edit-wishlist-fields-anime" hidden>
                        <p>Titre <span class="hint">(fixé par Anilist)</span> :</p>
                        <p class="anime-readonly-value" id="edit-wishlist-anime-name"></p>
                        <p>Studio :</p>
                        <input type="text" id="edit-wishlist-studio" placeholder="Studio">
                    </div>

                    <button type="submit" class="button">Mettre à jour</button>
                </form>
            </div>
        </div>

        <!-- Modale "ajouter à la collection" -->
        <div class="modal" id="add-from-wishlist-modal">
            <div class="modal-content modal-content--narrow">
                <span class="close-modal" id="close-add-from-wishlist-modal">&times;</span>
                <h2>Ajouter à la collection</h2>
                <p id="afw-manga-text">
                    La série <strong id="afw-series-name"></strong> va être retirée de votre liste d'envies.
                </p>
                <p class="hint" id="afw-manga-hint">
                    Vous serez redirigé vers l'administration pour finaliser l'ajout. La série sera retirée de la liste d'envies, dès que vous cliquerez sur "continuer", même si l'ajout n'est pas finalisé.
                </p>
                <p id="afw-anime-text" hidden>
                    La série animée <strong id="afw-anime-series-name"></strong> va être importée
                    intégralement depuis Anilist et rejoindre l'Animethèque.
                </p>
                <div class="modal-actions">
                    <button class="button button-ats" id="afw-confirm-btn">Continuer</button>
                    <button class="button button-ext" id="afw-cancel-btn">Annuler</button>
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
        // Registre des types (libellés, couleurs) : seule source de vérité,
        // partagée avec admin.php et index.php. Aucun libellé ni couleur ne
        // doit être écrit en dur plus bas.
        window.seriesTypes = <?= json_encode(series_types_for_js()) ?>;

        let wishlistData = <?= json_encode(array_values($wishlist)) ?>;
        let pendingAddFromWishlist = null;

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
                    pendingAddFromWishlist = { index, item };
                    const isAnime = item.type === 'anime';
                    document.getElementById('afw-manga-text').hidden = isAnime;
                    document.getElementById('afw-manga-hint').hidden = isAnime;
                    document.getElementById('afw-anime-text').hidden = !isAnime;
                    document.getElementById('afw-series-name').textContent = item.name;
                    document.getElementById('afw-anime-series-name').textContent = item.name;
                    document.getElementById('add-from-wishlist-modal').classList.add('modal-active');
                });
            });

            document.querySelectorAll('.edit-wishlist-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const index = parseInt(this.dataset.index);
                    const item  = wishlistData[index];
                    const isAnime = item.type === 'anime';
                    document.getElementById('edit-wishlist-index').value = index;
                    document.getElementById('edit-wishlist-fields-manga').hidden = isAnime;
                    document.getElementById('edit-wishlist-fields-anime').hidden = !isAnime;
                    if (isAnime) {
                        document.getElementById('edit-wishlist-anime-name').textContent = item.name;
                        document.getElementById('edit-wishlist-studio').value = item.studio || '';
                    } else {
                        document.getElementById('edit-wishlist-name').value      = item.name;
                        document.getElementById('edit-wishlist-author').value    = item.author;
                        document.getElementById('edit-wishlist-publisher').value = item.publisher;
                    }
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

        // ── Modifier une entrée ───────────────────────────────────────────────
        document.getElementById('edit-wishlist-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const index     = document.getElementById('edit-wishlist-index').value;
            const isAnime   = !document.getElementById('edit-wishlist-fields-anime').hidden;
            const name      = isAnime ? '' : document.getElementById('edit-wishlist-name').value;
            const author    = isAnime ? '' : document.getElementById('edit-wishlist-author').value;
            const publisher = isAnime ? '' : document.getElementById('edit-wishlist-publisher').value;
            const studio    = isAnime ? document.getElementById('edit-wishlist-studio').value : '';
            const res = await fetch('page-wishlist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `edit_wishlist=true&index=${index}&name=${encodeURIComponent(name)}&author=${encodeURIComponent(author)}&publisher=${encodeURIComponent(publisher)}&studio=${encodeURIComponent(studio)}`
            });
            const data = await res.json();
            if (data.success) {
                renderWishlist(data.wishlist);
                document.getElementById('edit-wishlist-modal').classList.remove('modal-active');
            } else {
                showCustomAlert('Erreur', data.message || 'Erreur.');
            }
        });

        // ── Ajouter depuis wishlist → collection ──────────────────────────────
        document.getElementById('afw-confirm-btn').addEventListener('click', async function() {
            if (!pendingAddFromWishlist) return;
            const { index, item } = pendingAddFromWishlist;
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

                if (data.type === 'anime') {
                    // Import déjà terminé côté serveur : on reste sur la page,
                    // la wishlist se met simplement à jour. Aucun aller-retour
                    // vers admin.php n'est nécessaire.
                    renderWishlist(data.wishlist);
                    showCustomAlert('Ajouté', (data.message || 'Série animée importée avec succès.'));
                } else {
                    renderWishlist(data.wishlist);
                    const params = new URLSearchParams({
                        prefill_name:      item.name,
                        prefill_author:    item.author,
                        prefill_publisher: item.publisher,
                        open_add_series:   '1'
                    });
                    window.location.href = '../admin.php?' + params.toString();
                }
            } finally {
                confirmBtn.disabled = false;
                pendingAddFromWishlist = null;
            }
        });

        document.getElementById('afw-cancel-btn').addEventListener('click', () => {
            document.getElementById('add-from-wishlist-modal').classList.remove('modal-active');
            pendingAddFromWishlist = null;
        });

        // ── Fermeture modales ─────────────────────────────────────────────────
        document.getElementById('close-edit-wishlist-modal').addEventListener('click', () => {
            document.getElementById('edit-wishlist-modal').classList.remove('modal-active');
        });
        document.getElementById('close-add-from-wishlist-modal').addEventListener('click', () => {
            document.getElementById('add-from-wishlist-modal').classList.remove('modal-active');
        });

        window.addEventListener('click', e => {
            ['edit-wishlist-modal','add-from-wishlist-modal','custom-confirm-modal','custom-alert-modal'].forEach(id => {
                const m = document.getElementById(id);
                if (e.target === m) m.classList.remove('modal-active');
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
                        <button class="edit-wishlist-btn button-icon" title="Modifier" data-index="${index}">
                            <img src="https://api.iconify.design/mdi/pencil.svg?color=%23c084fc" width="18" height="18" alt="">
                        </button>
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
    </script>
</body>
</html>
