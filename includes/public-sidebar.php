<?php
// includes/public-sidebar.php
// ──────────────────────────────────────────────────────────────────────────────
// Menu latéral public (index.php et stats.php), calqué sur la sidebar admin.
// Attend que $options soit disponible (chargé via load_options()).
// ──────────────────────────────────────────────────────────────────────────────
require_once 'includes/custom_icons.php';

$current_page = basename($_SERVER['PHP_SELF']);

// Collection affichée.
$__sidebar_type = function_exists('sanitize_series_type')
    ? sanitize_series_type($_GET['type'] ?? '')
    : 'manga';

// Liens personnalisés (section « Liens », affichée seulement si non vide).
$custom_links = custom_link_get_links($options);

// Profil de l'admin : bouton « Qui suis-je ? », affiché uniquement si au moins
// un champ du profil est renseigné.
$profil_avatar = trim($options['admin_avatar'] ?? '');
$profil_pseudo = trim($options['admin_pseudo'] ?? '');
$profil_bio    = trim($options['admin_bio'] ?? '');
$profil_social = profil_get_social_links($options);
$has_profil = ($profil_pseudo !== '' || $profil_bio !== '' ||
               ($profil_avatar !== '' && file_exists($profil_avatar)) ||
               !empty($profil_social));

// Pages publiques qui embarquent la modale « Qui suis-je ? »
// (includes/public-profil-modal.php) et peuvent donc afficher le bouton.
$__profil_pages = ['index.php', 'stats.php', 'historique.php'];

// Couleurs des sections, centralisées dans includes/helpers.php (elles-mêmes
// le pendant des variables CSS --sidebar-section-* de assets/css/_variables.css).
$__c_manga = rawurlencode(sidebar_section_color('manga'));
$__c_anime = rawurlencode(sidebar_section_color('anime'));
$__c_gray  = rawurlencode(sidebar_section_color('gray'));

// Bouton « Critiques mangas|animés » : affiché uniquement si les critiques
// sont effectivement consultables publiquement pour cette collection (ni
// mode privé, ni masquage des critiques réglé séparément) — sans quoi le
// filtre « Avec critique » mènerait vers une collection qui n'affiche de
// toute façon jamais rien.
$__reviews_public_manga = !is_private_mode($options, 'manga') && !is_hide_reviews($options, 'manga');
$__reviews_public_anime = !is_private_mode($options, 'anime') && !is_hide_reviews($options, 'anime');
?>
<nav class="sidebar" id="sidebar" aria-label="Navigation principale">

    <!-- Logo -->
    <div class="sidebar-brand">
        <img src="assets/img/logo.png" alt="Lengas" class="sidebar-logo" width="30" height="30">
    </div>

    <ul class="sidebar-nav" role="list">

        <!-- ═══════════════════ Section Mangathèque ═══════════════════ -->
        <li class="sidebar-section">
            <span class="sidebar-section-title">Mangathèque</span>
            <ul class="sidebar-section-items" role="list">

                <li>
                    <a href="index.php?type=manga"
                       class="sidebar-link <?= ($current_page === 'index.php' && $__sidebar_type === 'manga' && ($_GET['status_filter'] ?? '') !== 'has_review') ? 'is-active is-active--pink' : '' ?>"
                       data-tooltip="Mangathèque">
                        <img src="https://api.iconify.design/mdi/bookshelf.svg?color=<?= $__c_manga ?>" width="22" height="22" alt="">
                    </a>
                </li>

                <?php if ($__reviews_public_manga): ?>
                <!-- Critiques mangas : bascule directement sur le filtre unique
                     « Avec critique », déjà appliqué à l'arrivée. -->
                <li>
                    <a href="index.php?type=manga&status_filter=has_review"
                       class="sidebar-link <?= ($current_page === 'index.php' && $__sidebar_type === 'manga' && ($_GET['status_filter'] ?? '') === 'has_review') ? 'is-active is-active--pink' : '' ?>"
                       data-tooltip="Critiques mangas">
                        <img src="https://api.iconify.design/mdi/pencil.svg?color=<?= $__c_manga ?>" width="22" height="22" alt="">
                    </a>
                </li>
                <?php endif; ?>

            </ul>
        </li>

        <!-- ═══════════════════ Section Animethèque ═══════════════════ -->
        <li class="sidebar-section">
            <span class="sidebar-section-title">Animethèque</span>
            <ul class="sidebar-section-items" role="list">

                <li>
                    <a href="index.php?type=anime"
                       class="sidebar-link <?= ($current_page === 'index.php' && $__sidebar_type === 'anime' && ($_GET['status_filter'] ?? '') !== 'has_review') ? 'is-active is-active--blue' : '' ?>"
                       data-tooltip="Animethèque">
                        <img src="https://api.iconify.design/mdi/television-classic.svg?color=<?= $__c_anime ?>" width="22" height="22" alt="">
                    </a>
                </li>

                <?php if ($__reviews_public_anime): ?>
                <!-- Critiques animés : même principe que côté Mangathèque. -->
                <li>
                    <a href="index.php?type=anime&status_filter=has_review"
                       class="sidebar-link <?= ($current_page === 'index.php' && $__sidebar_type === 'anime' && ($_GET['status_filter'] ?? '') === 'has_review') ? 'is-active is-active--blue' : '' ?>"
                       data-tooltip="Critiques animés">
                        <img src="https://api.iconify.design/mdi/pencil.svg?color=<?= $__c_anime ?>" width="22" height="22" alt="">
                    </a>
                </li>
                <?php endif; ?>

            </ul>
        </li>

        <!-- ═══════════════════ Section Divers ═══════════════════ -->
        <li class="sidebar-section">
            <span class="sidebar-section-title">Divers</span>
            <ul class="sidebar-section-items" role="list">

                <?php if (in_array($current_page, $__profil_pages, true) && $has_profil): ?>
                    <!-- Qui suis-je ? (profil de l'admin, modale disponible) -->
                    <li>
                        <button type="button"
                                class="sidebar-link"
                                id="open-profil-modal"
                                data-tooltip="Qui suis-je ?">
                            <img src="https://api.iconify.design/mdi/account-circle.svg?color=<?= $__c_gray ?>" width="22" height="22" alt="">
                        </button>
                    </li>
                <?php endif; ?>

                <!-- Statistiques -->
                <li>
                    <a href="stats.php"
                       class="sidebar-link <?= $current_page === 'stats.php' ? 'is-active is-active--gray' : '' ?>"
                       data-tooltip="Statistiques">
                        <img src="https://api.iconify.design/mdi/chart-bar.svg?color=<?= $__c_gray ?>" width="22" height="22" alt="">
                    </a>
                </li>

                <?php if (empty($options['hide_history'])): ?>
                <!-- Historique (journal chronologique des tomes lus / épisodes vus) -->
                <li>
                    <a href="historique.php"
                       class="sidebar-link <?= $current_page === 'historique.php' ? 'is-active is-active--gray' : '' ?>"
                       data-tooltip="Historique">
                        <img src="https://api.iconify.design/mdi/history.svg?color=<?= $__c_gray ?>" width="22" height="22" alt="">
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($current_page === 'index.php'): ?>
                    <!-- Légende / infos (uniquement sur l'accueil : modale disponible) -->
                    <li>
                        <button type="button"
                                class="sidebar-link"
                                id="open-legend-modal"
                                data-tooltip="Légende">
                            <img src="https://api.iconify.design/mdi/information-outline.svg?color=<?= $__c_gray ?>" width="22" height="22" alt="">
                        </button>
                    </li>
                <?php endif; ?>

            </ul>
        </li>

        <?php if (!empty($custom_links)): ?>
        <!-- ═══════════════════ Section Liens ═══════════════════ -->
        <li class="sidebar-section">
            <span class="sidebar-section-title">Liens</span>
            <ul class="sidebar-section-items" role="list">

                <?php foreach ($custom_links as $link):
                    $icon_name = str_replace(':', '/', custom_link_icon_name($link['icon']));
                    $icon_hex  = custom_link_color_hex($link['color']);
                    $icon_col  = rawurlencode($icon_hex); // ex. %23f87171
                ?>
                    <li>
                        <a href="<?= htmlspecialchars($link['url']) ?>"
                           class="sidebar-link"
                           data-tooltip="<?= htmlspecialchars($link['name']) ?>"
                           target="_blank" rel="noopener">
                            <img src="https://api.iconify.design/<?= $icon_name ?>.svg?color=<?= $icon_col ?>" width="22" height="22" alt="">
                        </a>
                    </li>
                <?php endforeach; ?>

            </ul>
        </li>
        <?php endif; ?>

    </ul>

    <!-- Bas de sidebar -->
    <ul class="sidebar-nav sidebar-nav--bottom" role="list">

        <!-- Connexion / administration -->
        <li>
            <a href="admin.php"
               class="sidebar-link"
               data-tooltip="Administration">
                <img src="https://api.iconify.design/mdi/lock.svg?color=%23fb923c" width="22" height="22" alt="">
            </a>
        </li>

        <!-- Version (cliquable vers le dépôt Gitéa) -->
        <li>
            <a href="<?= URL_GITEA ?>"
               class="sidebar-link sidebar-version"
               data-tooltip="<?= htmlspecialchars(($options['site_name'] ?? 'Lengas') . ' - ' . SITE_VERSION) ?> — dépôt Gitéa"
               target="_blank" rel="noopener">
                <span class="sidebar-version-text"><?= htmlspecialchars(SITE_VERSION) ?></span>
            </a>
        </li>

    </ul>

</nav>

<!-- Bouton hamburger mobile -->
<button class="sidebar-hamburger" id="sidebar-hamburger" aria-label="Ouvrir le menu" aria-expanded="false">
    <span class="bar"></span>
</button>

<!-- Overlay derrière le drawer mobile -->
<div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>

<script>
(function() {
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

    document.addEventListener('click', function(e) {
        if (e.target.closest('#sidebar-hamburger')) {
            e.stopPropagation();
            var s = getSidebar();
            s && s.classList.contains('is-open') ? closeDrawer() : openDrawer();
            return;
        }
        if (e.target === getOverlay()) {
            closeDrawer();
        }
    }, true);

    var ov = getOverlay();
    if (ov) ov.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { var s = getSidebar(); if (s && s.classList.contains('is-open')) closeDrawer(); }
    });

    /* Fermer le drawer sur mobile après un clic sur un lien de navigation.
       Le bouton Légende (#open-legend-modal) est géré par public.js : on ferme
       simplement le drawer pour laisser la modale s'afficher. */
    document.querySelectorAll('.sidebar-link').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                closeDrawer();
            }
        });
    });
})();
</script>
