<?php
require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require 'fonctions/series.php';
require 'fonctions/volumes.php';
require 'fonctions/wishlist.php';
require 'fonctions/options.php';

$data    = load_data();
$options = load_options();

// ── Actions AJAX ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_to_wishlist'])) {
        $name      = trim($_POST['wishlist_name'] ?? '');
        $author    = trim($_POST['wishlist_author'] ?? '');
        $publisher = trim($_POST['wishlist_publisher'] ?? '');
        $wishlist  = load_wishlist();
        $result    = add_to_wishlist($wishlist, $name, $author, $publisher);
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
        $index     = $_POST['index'] ?? 0;
        $name      = trim($_POST['name'] ?? '');
        $author    = trim($_POST['author'] ?? '');
        $publisher = trim($_POST['publisher'] ?? '');
        $wishlist  = load_wishlist();
        $result    = edit_wishlist($wishlist, $index, $name, $author, $publisher);
        if ($result['success']) save_wishlist($result['wishlist']);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    // Ajouter depuis liste d'envies → redirige vers admin.php avec pré-remplissage
    if (isset($_POST['add_from_wishlist'])) {
        $index    = $_POST['index'] ?? 0;
        $wishlist = load_wishlist();
        $item     = $wishlist[(int)$index] ?? null;
        if ($item) {
            $result = remove_from_wishlist($wishlist, $index);
            if ($result['success']) save_wishlist($result['wishlist']);
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'item' => $item, 'wishlist' => $result['wishlist']]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Entrée introuvable.']);
        }
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
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
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
                <div class="wishlist-add-form" autocomplete="off">
                    <input type="text" id="wishlist-name"      placeholder="Nom de la série"   autocomplete="off">
                    <input type="text" id="wishlist-author"    placeholder="Auteur"             autocomplete="off">
                    <input type="text" id="wishlist-publisher" placeholder="Éditeur"            autocomplete="off">
                    <button id="add-to-wishlist-btn" class="button button-ats">Ajouter</button>
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
                    <select id="wishlist-sort-field" class="wishlist-sort-select">
                        <option value="name">Trier par titre</option>
                        <option value="author">Trier par auteur</option>
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
                    <p>Nom :</p>
                    <input type="text" id="edit-wishlist-name"      placeholder="Nom de la série" required>
                    <p>Auteur :</p>
                    <input type="text" id="edit-wishlist-author"    placeholder="Auteur" required>
                    <p>Éditeur :</p>
                    <input type="text" id="edit-wishlist-publisher" placeholder="Éditeur" required>
                    <button type="submit" class="button">Mettre à jour</button>
                </form>
            </div>
        </div>

        <!-- Modale "ajouter à la collection" -->
        <div class="modal" id="add-from-wishlist-modal">
            <div class="modal-content modal-content--narrow">
                <span class="close-modal" id="close-add-from-wishlist-modal">&times;</span>
                <h2>Ajouter à la collection</h2>
                <p>La série <strong id="afw-series-name"></strong> va être retirée de votre liste d'envies.</p>
                <p class="hint">Vous serez redirigé vers l'administration pour finaliser l'ajout.</p>
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

    <script>
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

        // ── Mise à jour du DOM après changement de la wishlist ────────────────
        function renderWishlist(wishlist) {
            wishlistData = wishlist;
            applyFiltersAndSort();
        }

        // ── Attacher les événements aux boutons ───────────────────────────────
        function attachWishlistEvents() {
            document.querySelectorAll('.add-from-wishlist-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const index = parseInt(this.dataset.index);
                    const item  = wishlistData[index];
                    pendingAddFromWishlist = { index, item };
                    document.getElementById('afw-series-name').textContent = item.name;
                    document.getElementById('add-from-wishlist-modal').classList.add('modal-active');
                });
            });

            document.querySelectorAll('.edit-wishlist-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const index = parseInt(this.dataset.index);
                    const item  = wishlistData[index];
                    document.getElementById('edit-wishlist-index').value     = index;
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

        attachWishlistEvents();

        // ── Ajouter à la liste ────────────────────────────────────────────────
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
                body: `add_to_wishlist=true&wishlist_name=${encodeURIComponent(name)}&wishlist_author=${encodeURIComponent(author)}&wishlist_publisher=${encodeURIComponent(publisher)}`
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

        // ── Modifier une entrée ───────────────────────────────────────────────
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

        // ── Ajouter depuis wishlist → collection ──────────────────────────────
        document.getElementById('afw-confirm-btn').addEventListener('click', async function() {
            if (!pendingAddFromWishlist) return;
            const { index, item } = pendingAddFromWishlist;
            const res = await fetch('page-wishlist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `add_from_wishlist=true&index=${index}`
            });
            const data = await res.json();
            document.getElementById('add-from-wishlist-modal').classList.remove('modal-active');
            if (data.success) {
                renderWishlist(data.wishlist);
                const params = new URLSearchParams({
                    prefill_name:      item.name,
                    prefill_author:    item.author,
                    prefill_publisher: item.publisher,
                    open_add_series:   '1'
                });
                window.location.href = 'admin.php?' + params.toString();
            }
            pendingAddFromWishlist = null;
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

        // ── Filtre de recherche + tri ─────────────────────────────────────────
        function applyFiltersAndSort() {
            const term      = normalizeString(document.getElementById('wishlist-search').value);
            const sortField = document.getElementById('wishlist-sort-field').value;
            const sortOrder = document.getElementById('wishlist-sort-order').value;

            // Filtrage
            let filtered = wishlistData.map((item, originalIndex) => ({ ...item, _originalIndex: originalIndex }));
            if (term) {
                filtered = filtered.filter(item =>
                    normalizeString(item.name).includes(term) ||
                    normalizeString(item.author).includes(term) ||
                    normalizeString(item.publisher || '').includes(term)
                );
            }

            // Tri
            filtered.sort((a, b) => {
                const valA = normalizeString(a[sortField] || '');
                const valB = normalizeString(b[sortField] || '');
                const cmp  = valA.localeCompare(valB, 'fr');
                return sortOrder === 'asc' ? cmp : -cmp;
            });

            // Rendre uniquement les éléments filtrés/triés
            const container = document.getElementById('wishlist-list');
            document.getElementById('wishlist-count').textContent = wishlistData.length;

            if (filtered.length === 0) {
                container.innerHTML = wishlistData.length === 0
                    ? '<p class="loans-empty">Votre liste d\'envies est vide. ✨</p>'
                    : '<p class="loans-empty">Aucun résultat pour cette recherche.</p>';
                return;
            }

            container.innerHTML = '';
            filtered.forEach(item => {
                const index = item._originalIndex;
                const div   = document.createElement('div');
                div.className   = 'wishlist-item';
                div.dataset.index = index;
                div.innerHTML = `
                    <div class="wishlist-item-info">
                        <span class="wishlist-series-name">${htmlEscape(item.name)}</span>
                        <span class="wishlist-series-meta">
                            ${htmlEscape(item.author)}${item.publisher ? ' · ' + htmlEscape(item.publisher) : ''}
                        </span>
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
        document.getElementById('wishlist-sort-field').addEventListener('change', applyFiltersAndSort);
        document.getElementById('wishlist-sort-order').addEventListener('change', applyFiltersAndSort);

        // Rendu initial via JS (remplace le rendu PHP statique pour cohérence)
        applyFiltersAndSort();
    </script>
</body>
</html>
