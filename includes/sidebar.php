<?php
// includes/sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);
// Prefixe d'URL selon la profondeur de la page qui inclut ce menu :
// les pages de pages/ sont un cran plus bas que la racine du site.
$__in_pages = strpos(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/pages/') !== false;
$base  = $__in_pages ? '../' : '';
$pages = $base . 'pages/';
?>
<nav class="sidebar" id="sidebar" aria-label="Navigation principale">

    <!-- Logo -->
    <div class="sidebar-brand">
        <img src="<?= $base ?>assets/img/logo.png" alt="Lengas" class="sidebar-logo" width="30" height="30">
    </div>

    <ul class="sidebar-nav" role="list">

        <!-- Bibliothèque -->
        <li>
            <a href="<?= $base ?>admin.php"
               class="sidebar-link <?= $current_page === 'admin.php' ? 'is-active' : '' ?>"
               data-tooltip="Bibliothèque">
                <img src="https://api.iconify.design/mdi/bookshelf.svg?color=%23c084fc" width="22" height="22" alt="">
            </a>
        </li>

<?php if ($current_page === 'admin.php'): ?>
        <!-- Ajouter une série (uniquement sur la page admin : les modales
             correspondantes n'existent que là) -->
        <li>
            <button type="button"
                    class="sidebar-link"
                    id="sidebar-add-series-btn"
                    data-tooltip="Ajouter une série"
                    data-modal-trigger="open-add-series-modal"
                    data-admin-redirect="<?= $base ?>admin.php">
                <img src="https://api.iconify.design/mdi/book-plus.svg?color=%234ade80" width="22" height="22" alt="">
            </button>
        </li>

        <!-- Ajouter des tomes -->
        <li>
            <button type="button"
                    class="sidebar-link"
                    id="sidebar-add-volumes-btn"
                    data-tooltip="Ajouter des tomes"
                    data-modal-trigger="open-add-multiple-volumes-modal"
                    data-admin-redirect="<?= $base ?>admin.php">
                <img src="https://api.iconify.design/mdi/book-plus-multiple.svg?color=%234ade80" width="22" height="22" alt="">
            </button>
        </li>
<?php endif; ?>

        <!-- Critiques -->
        <li>
            <a href="<?= $pages ?>page-critiques.php"
               class="sidebar-link <?= $current_page === 'page-critiques.php' ? 'is-active' : '' ?>"
               data-tooltip="Critiques">
                <img src="https://api.iconify.design/mdi/pencil.svg?color=%234ade80" width="22" height="22" alt="">
            </a>
        </li>

        <li class="sidebar-separator"></li>

        <!-- Prêts -->
        <li>
            <a href="<?= $pages ?>page-prets.php"
               class="sidebar-link <?= $current_page === 'page-prets.php' ? 'is-active' : '' ?>"
               data-tooltip="Livres prêtés">
                <img src="https://api.iconify.design/mdi/book-arrow-right.svg?color=%2338bdf8" width="22" height="22" alt="">
            </a>
        </li>

        <!-- Liste d'envies -->
        <li>
            <a href="<?= $pages ?>page-wishlist.php"
               class="sidebar-link <?= $current_page === 'page-wishlist.php' ? 'is-active is-active--blue' : '' ?>"
               data-tooltip="Liste d'envies">
                <img src="https://api.iconify.design/mdi/heart-multiple.svg?color=%2338bdf8" width="22" height="22" alt="">
            </a>
        </li>

        <li class="sidebar-separator"></li>

        <!-- Statistiques -->
        <li>
            <a href="<?= $base ?>stats.php"
               class="sidebar-link <?= $current_page === 'stats.php' ? 'is-active' : '' ?>"
               data-tooltip="Statistiques"
               target="_blank">
                <img src="https://api.iconify.design/mdi/chart-bar.svg?color=%23d4d4e8" width="22" height="22" alt="">
            </a>
        </li>

        <!-- Accueil public -->
        <li>
            <a href="<?= $base ?>index.php"
               class="sidebar-link"
               data-tooltip="Accueil public"
               target="_blank">
                <img src="https://api.iconify.design/mdi/home.svg?color=%23d4d4e8" width="22" height="22" alt="">
            </a>
        </li>

        <li class="sidebar-separator"></li>

        <!-- Profil -->
        <li>
            <a href="<?= $pages ?>page-profil.php"
               class="sidebar-link <?= $current_page === 'page-profil.php' ? 'is-active' : '' ?>"
               data-tooltip="Profil">
                <img src="https://api.iconify.design/mdi/account-circle.svg?color=%23fb923c" width="22" height="22" alt="">
            </a>
        </li>

        <!-- Options -->
        <li>
            <a href="<?= $pages ?>page-options.php"
               class="sidebar-link <?= $current_page === 'page-options.php' ? 'is-active' : '' ?>"
               data-tooltip="Options">
                <img src="https://api.iconify.design/mdi/cog.svg?color=%23fb923c" width="22" height="22" alt="">
            </a>
        </li>

        <!-- Outils -->
        <li>
            <a href="<?= $pages ?>page-outils.php"
               class="sidebar-link <?= $current_page === 'page-outils.php' ? 'is-active' : '' ?>"
               data-tooltip="Outils">
                <img src="https://api.iconify.design/mdi/wrench.svg?color=%23fb923c" width="22" height="22" alt="">
            </a>
        </li>

    </ul>

    <!-- Bas de sidebar -->
    <ul class="sidebar-nav sidebar-nav--bottom" role="list">
        <li>
            <a href="#"
               class="sidebar-link"
               data-tooltip="Recharger"
               onclick="location.reload(); return false;">
                <img src="https://api.iconify.design/mdi/refresh.svg?color=%23d4d4e8" width="22" height="22" alt="">
            </a>
        </li>
        <li>
            <a href="<?= $base ?>logout.php"
               class="sidebar-link sidebar-link--danger"
               data-tooltip="Déconnexion">
                <img src="https://api.iconify.design/mdi/logout.svg?color=%23f87171" width="22" height="22" alt="">
            </a>
        </li>
    </ul>

</nav>

<!-- Bouton hamburger mobile (en dehors de la nav pour être toujours visible) -->
<button class="sidebar-hamburger" id="sidebar-hamburger" aria-label="Ouvrir le menu" aria-expanded="false">
    <span class="bar"></span>
</button>

<!-- Overlay derrière le drawer mobile -->
<div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>

<script>
(function() {
    var isAdmin = <?= json_encode($current_page === 'admin.php') ?>;

    /* ── Lookups frais à chaque appel (résiste aux rechargements JS) ─── */
    function getSidebar()   { return document.getElementById('sidebar');           }
    function getHamburger() { return document.getElementById('sidebar-hamburger'); }
    function getOverlay()   { return document.getElementById('sidebar-overlay');   }

    function openDrawer() {
        var s = getSidebar(), h = getHamburger(), o = getOverlay();
        if (!s || !o) return;
        s.classList.add('is-open');
        o.classList.add('is-visible');
        if (h) { h.classList.add('is-open'); h.setAttribute('aria-expanded', 'true'); }
        document.body.classList.add('drawer-open');
    }

    function closeDrawer() {
        var s = getSidebar(), h = getHamburger(), o = getOverlay();
        if (!s || !o) return;
        s.classList.remove('is-open');
        o.classList.remove('is-visible');
        if (h) { h.classList.remove('is-open'); h.setAttribute('aria-expanded', 'false'); }
        document.body.classList.remove('drawer-open');
    }

    /* ── Phase CAPTURE : s'exécute en premier, avant modals.js,
       pagination.js, series.js, etc. — non bloquable par stopPropagation
       de la phase bubble ni par d'autres listeners admin ── */
    document.addEventListener('click', function(e) {
        if (e.target.closest('#sidebar-hamburger')) {
            e.stopPropagation(); /* bloque la remontée vers d'autres listeners */
            var s = getSidebar();
            s && s.classList.contains('is-open') ? closeDrawer() : openDrawer();
            return;
        }
        if (e.target === getOverlay()) {
            closeDrawer();
        }
    }, true /* capture phase */);

    /* Redondance : listener bubble sur l'overlay */
    var ov = getOverlay();
    if (ov) ov.addEventListener('click', closeDrawer);

    /* Fermer avec Échap */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { var s = getSidebar(); if (s && s.classList.contains('is-open')) closeDrawer(); }
    });

    /* ── Liens du drawer : ferme le menu puis navigue/ouvre la modale ── */
    document.querySelectorAll('.sidebar-link[data-modal-trigger]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var triggerId = btn.dataset.modalTrigger;
            var redirect  = btn.dataset.adminRedirect;

            /* Sur mobile, ferme le drawer avant d'ouvrir la modale */
            if (window.innerWidth <= 768) {
                closeDrawer();
            }

            if (isAdmin) {
                var hiddenBtn = document.getElementById(triggerId);
                if (hiddenBtn) {
                    /* Petit délai pour laisser l'animation du drawer se terminer */
                    var delay = window.innerWidth <= 768 ? 250 : 0;
                    setTimeout(function() { hiddenBtn.click(); }, delay);
                }
            } else {
                window.location.href = redirect || 'admin.php';
            }
        });
    });

    /* Liens de navigation (non-modal) : ferme aussi le drawer sur mobile */
    document.querySelectorAll('.sidebar-link:not([data-modal-trigger])').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                closeDrawer();
            }
        });
    });

    /* ── Hash et query-string : modales ciblées au chargement ─────────── */
    if (isAdmin) {
        document.addEventListener('DOMContentLoaded', function() {
            var hash = window.location.hash;

            if (hash) {
                history.replaceState(null, '', window.location.pathname + window.location.search);
            }

            /* Pré-remplissage depuis page-wishlist.php → ajouter une série */
            var params = new URLSearchParams(window.location.search);
            if (params.get('open_add_series') === '1') {
                var nameEl      = document.getElementById('add-series-name');
                var authorEl    = document.getElementById('add-series-author');
                var publisherEl = document.getElementById('add-series-publisher');
                if (nameEl)      nameEl.value      = params.get('prefill_name')      || '';
                if (authorEl)    authorEl.value    = params.get('prefill_author')    || '';
                if (publisherEl) publisherEl.value = params.get('prefill_publisher') || '';
                document.getElementById('open-add-series-modal')?.click();
            }
        });
    }
})();
</script>