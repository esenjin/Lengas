<?php
// includes/public-sidebar.php
// ──────────────────────────────────────────────────────────────────────────────
// Menu latéral public (index.php et stats.php), calqué sur la sidebar admin.
// Attend que $options soit disponible (chargé via load_options()).
// ──────────────────────────────────────────────────────────────────────────────
require_once 'includes/custom_icons.php';

$current_page = basename($_SERVER['PHP_SELF']);
?>
<nav class="sidebar" id="sidebar" aria-label="Navigation principale">

    <!-- Logo -->
    <div class="sidebar-brand">
        <img src="assets/img/logo.png" alt="Lengas" class="sidebar-logo" width="30" height="30">
    </div>

    <ul class="sidebar-nav" role="list">

        <!-- Accueil (bibliothèque publique) -->
        <li>
            <a href="index.php"
               class="sidebar-link <?= $current_page === 'index.php' ? 'is-active' : '' ?>"
               data-tooltip="Accueil">
                <img src="https://api.iconify.design/mdi/bookshelf.svg?color=%23c084fc" width="22" height="22" alt="">
            </a>
        </li>

        <!-- Statistiques -->
        <li>
            <a href="stats.php"
               class="sidebar-link <?= $current_page === 'stats.php' ? 'is-active' : '' ?>"
               data-tooltip="Statistiques">
                <img src="https://api.iconify.design/mdi/chart-bar.svg?color=%2338bdf8" width="22" height="22" alt="">
            </a>
        </li>

        <?php
        // ── Liens personnalisés (icône + couleur choisies) ────────────────────
        $custom_links = custom_link_get_links($options);
        ?>

        <?php if (!empty($custom_links)): ?>
            <li class="sidebar-separator"></li>
        <?php endif; ?>

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

        <?php if ($current_page === 'index.php'): ?>
            <li class="sidebar-separator"></li>

            <!-- Légende / infos (uniquement sur l'accueil : modale disponible) -->
            <li>
                <button type="button"
                        class="sidebar-link"
                        id="open-legend-modal"
                        data-tooltip="Légende">
                    <img src="https://api.iconify.design/mdi/information-outline.svg?color=%23d4d4e8" width="22" height="22" alt="">
                </button>
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