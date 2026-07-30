<?php
// includes/sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);
// Prefixe d'URL selon la profondeur de la page qui inclut ce menu :
// les pages de pages/ sont un cran plus bas que la racine du site.
$__in_pages = strpos(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/pages/') !== false;
$base  = $__in_pages ? '../' : '';
$pages = $base . 'pages/';

// Collection actuellement affichée. Sert à marquer la bonne entrée comme active
// et à n'exposer l'ajout d'un animé que dans l'Animethèque.
$__sidebar_type = function_exists('sanitize_series_type')
    ? sanitize_series_type($_GET['type'] ?? '')
    : 'manga';

// Filtre de statuts actuellement appliqué (utilisé pour « Mangas à lire » et
// « Animés à visionner », et pour détecter quand l'une des deux est active).
//
// Manga : un seul jeton — une série DÉBUTÉE mais pas encore terminée (ni « à
// débuter », ni « terminée », ni « abandonnée »).
//
// Anime : DEUX jetons — en plus des animés en cours de visionnage, « Animés à
// visionner » inclut désormais ceux pas encore commencés (« à voir »), pour
// couvrir toute la file d'attente en un clic plutôt que le seul visionnage
// déjà entamé.
//
// Les deux liens appliquent en plus un tri par date de lecture/visionnage
// descendant (les plus récemment avancées en tête), pour retrouver en un
// coup d'œil ce qu'on vient de commencer.
$__sidebar_status_filter = trim((string)($_GET['status_filter'] ?? ''));
$__sidebar_backlog_tokens_manga = 'reading_in_progress';
$__sidebar_backlog_tokens_anime = 'reading_not_started,reading_in_progress';
$__sidebar_backlog_tokens = ($__sidebar_type === 'anime') ? $__sidebar_backlog_tokens_anime : $__sidebar_backlog_tokens_manga;
$__sidebar_is_backlog = in_array($__sidebar_status_filter, [$__sidebar_backlog_tokens_manga, $__sidebar_backlog_tokens_anime], true);

// Couleurs des sections, centralisées dans includes/helpers.php (elles-mêmes
// le pendant des variables CSS --sidebar-section-* de assets/css/_variables.css).
$__c_manga  = rawurlencode(sidebar_section_color('manga'));
$__c_anime  = rawurlencode(sidebar_section_color('anime'));
$__c_green  = rawurlencode(sidebar_section_color('green'));
$__c_brown  = rawurlencode(sidebar_section_color('brown'));
$__c_gray   = rawurlencode(sidebar_section_color('gray'));
$__c_orange = rawurlencode(sidebar_section_color('orange'));
?>
<nav class="sidebar" id="sidebar" aria-label="Navigation principale">

    <!-- Logo -->
    <div class="sidebar-brand">
        <img src="<?= $base ?>assets/img/logo.png" alt="Lengas" class="sidebar-logo" width="30" height="30">
    </div>

    <ul class="sidebar-nav" role="list">

        <!-- ═══════════════════ Section Mangathèque ═══════════════════ -->
        <li class="sidebar-section">
            <span class="sidebar-section-title">Mangathèque</span>
            <ul class="sidebar-section-items" role="list">

                <li>
                    <a href="<?= $base ?>admin.php?type=manga"
                       class="sidebar-link <?= ($current_page === 'admin.php' && $__sidebar_type === 'manga' && !$__sidebar_is_backlog) ? 'is-active is-active--pink' : '' ?>"
                       data-tooltip="Mangathèque">
                        <img src="https://api.iconify.design/mdi/bookshelf.svg?color=<?= $__c_manga ?>" width="22" height="22" alt="">
                    </a>
                </li>

                <!-- Ajouter une série (modale présente en permanence sur
                     admin.php ; toujours visible ici, redirige/bascule vers
                     la Mangathèque si nécessaire avant de l'ouvrir) -->
                <li>
                    <button type="button"
                            class="sidebar-link"
                            id="sidebar-add-series-btn"
                            data-tooltip="Ajouter une série"
                            data-modal-trigger="open-add-series-modal"
                            data-modal-needs-type="manga"
                            data-admin-redirect="<?= $base ?>admin.php?type=manga">
                        <img src="https://api.iconify.design/mdi/book-plus.svg?color=<?= $__c_manga ?>" width="22" height="22" alt="">
                    </button>
                </li>

                <!-- Ajouter des tomes -->
                <li>
                    <button type="button"
                            class="sidebar-link"
                            id="sidebar-add-volumes-btn"
                            data-tooltip="Ajouter des tomes"
                            data-modal-trigger="open-add-multiple-volumes-modal"
                            data-modal-needs-type="manga"
                            data-admin-redirect="<?= $base ?>admin.php?type=manga">
                        <img src="https://api.iconify.design/mdi/book-plus-multiple.svg?color=<?= $__c_manga ?>" width="22" height="22" alt="">
                    </button>
                </li>

                <!-- Mangas à lire : lecture débutée, pas encore terminée —
                     triés par date de lecture descendante (les plus
                     récemment avancés en tête). -->
                <li>
                    <a href="<?= $base ?>admin.php?type=manga&status_filter=<?= urlencode($__sidebar_backlog_tokens_manga) ?>&sort_by=read_at&sort_order=desc"
                       class="sidebar-link <?= ($current_page === 'admin.php' && $__sidebar_type === 'manga' && $__sidebar_is_backlog) ? 'is-active is-active--pink' : '' ?>"
                       data-tooltip="Mangas à lire">
                        <img src="https://api.iconify.design/mdi/book-clock.svg?color=<?= $__c_manga ?>" width="22" height="22" alt="">
                    </a>
                </li>

            </ul>
        </li>

        <!-- ═══════════════════ Section Animethèque ═══════════════════ -->
        <li class="sidebar-section">
            <span class="sidebar-section-title">Animethèque</span>
            <ul class="sidebar-section-items" role="list">

                <li>
                    <a href="<?= $base ?>admin.php?type=anime"
                       class="sidebar-link <?= ($current_page === 'admin.php' && $__sidebar_type === 'anime' && !$__sidebar_is_backlog) ? 'is-active is-active--blue' : '' ?>"
                       data-tooltip="Animethèque">
                        <img src="https://api.iconify.design/mdi/television-classic.svg?color=<?= $__c_anime ?>" width="22" height="22" alt="">
                    </a>
                </li>

                <!-- Ajouter une série animée (recherche Anilist), toujours
                     visible ici, redirige/bascule vers l'Animethèque si
                     nécessaire avant de l'ouvrir -->
                <li>
                    <button type="button"
                            class="sidebar-link"
                            id="sidebar-add-anime-btn"
                            data-tooltip="Ajouter une série animée"
                            data-modal-trigger="open-add-anime-modal"
                            data-modal-needs-type="anime"
                            data-admin-redirect="<?= $base ?>admin.php?type=anime">
                        <img src="https://api.iconify.design/mdi/video-plus.svg?color=<?= $__c_anime ?>" width="22" height="22" alt="">
                    </button>
                </li>

                <!-- Animés à visionner : à voir OU visionnage débuté (pas
                     encore terminé) — triés par date de visionnage
                     descendante (les plus récemment avancés en tête). -->
                <li>
                    <a href="<?= $base ?>admin.php?type=anime&status_filter=<?= urlencode($__sidebar_backlog_tokens_anime) ?>&sort_by=read_at&sort_order=desc"
                       class="sidebar-link <?= ($current_page === 'admin.php' && $__sidebar_type === 'anime' && $__sidebar_is_backlog) ? 'is-active is-active--blue' : '' ?>"
                       data-tooltip="Animés à visionner">
                        <img src="https://api.iconify.design/mdi/television-play.svg?color=<?= $__c_anime ?>" width="22" height="22" alt="">
                    </a>
                </li>

            </ul>
        </li>

        <!-- ═══════════════════ Section Hors collection ═══════════════════ -->
        <li class="sidebar-section">
            <span class="sidebar-section-title">Hors<br>collection</span>
            <ul class="sidebar-section-items" role="list">

                <!-- Prêts -->
                <li>
                    <a href="<?= $pages ?>page-prets.php"
                       class="sidebar-link <?= $current_page === 'page-prets.php' ? 'is-active is-active--green' : '' ?>"
                       data-tooltip="Livres prêtés">
                        <img src="https://api.iconify.design/mdi/book-arrow-right.svg?color=<?= $__c_green ?>" width="22" height="22" alt="">
                    </a>
                </li>

                <!-- Liste d'envies -->
                <li>
                    <a href="<?= $pages ?>page-wishlist.php"
                       class="sidebar-link <?= $current_page === 'page-wishlist.php' ? 'is-active is-active--green' : '' ?>"
                       data-tooltip="Liste d'envies">
                        <img src="https://api.iconify.design/mdi/heart-multiple.svg?color=<?= $__c_green ?>" width="22" height="22" alt="">
                    </a>
                </li>

            </ul>
        </li>

        <!-- ═══════════════════ Section Mutualisé ═══════════════════ -->
        <li class="sidebar-section">
            <span class="sidebar-section-title">Mutualisé</span>
            <ul class="sidebar-section-items" role="list">

                <!-- Critiques -->
                <li>
                    <a href="<?= $pages ?>page-critiques.php"
                       class="sidebar-link <?= $current_page === 'page-critiques.php' ? 'is-active is-active--brown' : '' ?>"
                       data-tooltip="Critiques">
                        <img src="https://api.iconify.design/mdi/pencil.svg?color=<?= $__c_brown ?>" width="22" height="22" alt="">
                    </a>
                </li>

                <!-- Licences -->
                <li>
                    <a href="<?= $pages ?>page-licences.php"
                       class="sidebar-link <?= $current_page === 'page-licences.php' ? 'is-active is-active--brown' : '' ?>"
                       data-tooltip="Licences">
                        <img src="https://api.iconify.design/mdi/bookmark-multiple.svg?color=<?= $__c_brown ?>" width="22" height="22" alt="">
                    </a>
                </li>

            </ul>
        </li>

        <!-- ═══════════════════ Section Divers ═══════════════════ -->
        <li class="sidebar-section">
            <span class="sidebar-section-title">Divers</span>
            <ul class="sidebar-section-items" role="list">

                <!-- Accueil public -->
                <li>
                    <a href="<?= $base ?>index.php"
                       class="sidebar-link"
                       data-tooltip="Accueil public"
                       target="_blank">
                        <img src="https://api.iconify.design/mdi/home.svg?color=<?= $__c_gray ?>" width="22" height="22" alt="">
                    </a>
                </li>

                <!-- Statistiques -->
                <li>
                    <a href="<?= $base ?>stats.php"
                       class="sidebar-link <?= $current_page === 'stats.php' ? 'is-active is-active--gray' : '' ?>"
                       data-tooltip="Statistiques"
                       target="_blank">
                        <img src="https://api.iconify.design/mdi/chart-bar.svg?color=<?= $__c_gray ?>" width="22" height="22" alt="">
                    </a>
                </li>

            </ul>
        </li>

        <!-- ═══════════════════ Section Gestion ═══════════════════ -->
        <li class="sidebar-section">
            <span class="sidebar-section-title">Gestion</span>
            <ul class="sidebar-section-items" role="list">

                <!-- Profil -->
                <li>
                    <a href="<?= $pages ?>page-profil.php"
                       class="sidebar-link <?= $current_page === 'page-profil.php' ? 'is-active is-active--orange' : '' ?>"
                       data-tooltip="Profil">
                        <img src="https://api.iconify.design/mdi/account-circle.svg?color=<?= $__c_orange ?>" width="22" height="22" alt="">
                    </a>
                </li>

                <!-- Options -->
                <li>
                    <a href="<?= $pages ?>page-options.php"
                       class="sidebar-link <?= $current_page === 'page-options.php' ? 'is-active is-active--orange' : '' ?>"
                       data-tooltip="Options">
                        <img src="https://api.iconify.design/mdi/cog.svg?color=<?= $__c_orange ?>" width="22" height="22" alt="">
                    </a>
                </li>

                <!-- Outils -->
                <li>
                    <a href="<?= $pages ?>page-outils.php"
                       class="sidebar-link <?= $current_page === 'page-outils.php' ? 'is-active is-active--orange' : '' ?>"
                       data-tooltip="Outils">
                        <img src="https://api.iconify.design/mdi/wrench.svg?color=<?= $__c_orange ?>" width="22" height="22" alt="">
                    </a>
                </li>

            </ul>
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
    var currentType = <?= json_encode($__sidebar_type) ?>;
    document.querySelectorAll('.sidebar-link[data-modal-trigger]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var triggerId = btn.dataset.modalTrigger;
            var redirect  = btn.dataset.adminRedirect;
            var needsType = btn.dataset.modalNeedsType;

            /* Sur mobile, ferme le drawer avant d'ouvrir la modale */
            if (window.innerWidth <= 768) {
                closeDrawer();
            }

            /* La modale voulue existe dans le DOM (elle est toujours présente
               sur admin.php, quel que soit le type affiché), mais si elle est
               rattachée à une collection précise (manga/anime) et qu'on n'est
               pas déjà dans cette collection, on bascule d'abord dessus :
               plus cohérent que d'ouvrir "Ajouter une série animée" alors que
               la Mangathèque est affichée à l'écran. */
            var wrongType = needsType && (!isAdmin || currentType !== needsType);

            if (isAdmin && !wrongType) {
                var hiddenBtn = document.getElementById(triggerId);
                if (hiddenBtn) {
                    /* Petit délai pour laisser l'animation du drawer se terminer */
                    var delay = window.innerWidth <= 768 ? 250 : 0;
                    setTimeout(function() { hiddenBtn.click(); }, delay);
                }
            } else {
                var target = redirect || 'admin.php';
                if (triggerId) {
                    target += (target.indexOf('?') === -1 ? '?' : '&') + 'open_modal=' + encodeURIComponent(triggerId);
                }
                window.location.href = target;
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

            /* Pré-remplissage depuis page-wishlist.php → ajouter une série.
               La query string est retirée de l'URL (history.replaceState) une
               fois consommée : sans ça, le rechargement de page déclenché par
               le submit natif du formulaire d'ajout (header("Location: " .
               $_SERVER['REQUEST_URI']) dans admin.php, qui inclut cette même
               query string) referait tourner ce bloc et rouvrirait la modale
               juste après l'enregistrement. */
            var params = new URLSearchParams(window.location.search);

            /* Ouverture automatique après redirection depuis un bouton de la
               sidebar dont la modale n'était pas disponible dans le contexte
               d'origine (mauvais type affiché, ou page hors admin.php). */
            var openModalId = params.get('open_modal');
            if (openModalId) {
                var openModalBtn = document.getElementById(openModalId);
                if (openModalBtn) openModalBtn.click();

                params.delete('open_modal');
                var cleanedQueryModal = params.toString();
                history.replaceState(null, '', window.location.pathname + (cleanedQueryModal ? '?' + cleanedQueryModal : ''));
            }

            if (params.get('open_add_series') === '1') {
                var nameEl      = document.getElementById('add-series-name');
                var authorEl    = document.getElementById('add-series-author');
                var publisherEl = document.getElementById('add-series-publisher');
                if (nameEl)      nameEl.value      = params.get('prefill_name')      || '';
                if (authorEl)    authorEl.value    = params.get('prefill_author')    || '';
                if (publisherEl) publisherEl.value = params.get('prefill_publisher') || '';
                document.getElementById('open-add-series-modal')?.click();

                params.delete('open_add_series');
                params.delete('prefill_name');
                params.delete('prefill_author');
                params.delete('prefill_publisher');
                var cleanedQuery = params.toString();
                history.replaceState(null, '', window.location.pathname + (cleanedQuery ? '?' + cleanedQuery : ''));
            }

            /* Retour de page-wishlist.php après import d'un animé → modale
               d'édition de la série fraîchement importée déjà ouverte (tag
               favori, note, revisionnages, etc. à compléter). window.seriesData
               (défini par admin.php, cf. assets/js/admin/anime.js) doit déjà
               être en mémoire à ce stade : ce bloc s'exécute après lui.
               Même nettoyage d'URL qu'au-dessus, et pour la même raison : le
               formulaire d'édition de série fait lui aussi un submit natif qui
               recharge la page sur son REQUEST_URI courant. */
            var editAnimeId = params.get('open_edit_anime');
            if (editAnimeId && typeof window.openAnimeEditModalById === 'function') {
                window.openAnimeEditModalById(editAnimeId);

                params.delete('type');
                params.delete('open_edit_anime');
                var cleanedQuery2 = params.toString();
                history.replaceState(null, '', window.location.pathname + (cleanedQuery2 ? '?' + cleanedQuery2 : ''));
            }
        });
    }
})();
</script>
