<?php
// includes/public-profil-modal.php
// ──────────────────────────────────────────────────────────────────────────────
// Modale « Qui suis-je ? » (profil de l'administrateur), affichée sur les pages
// publiques qui exposent le bouton correspondant dans la sidebar
// (includes/public-sidebar.php). Auparavant définie uniquement dans index.php ;
// extraite ici pour être également disponible sur stats.php et historique.php,
// sans dupliquer ce bloc à trois endroits.
//
// Attend $options (chargé via load_options()). Pour la mise en lumière, utilise
// en priorité $profil_data si la page l'a définie (collection complète, TOUS
// types confondus, non filtrée par mode privé/mature — le filtrage se fait à
// l'intérieur de profil_highlighted_series() via $visible_only=true) ; à
// défaut $all_data, puis $data ; sinon la mise en lumière reste simplement vide
// (le reste du profil — avatar, bio, liens sociaux — s'affiche quand même).
//
// N'affiche rien si $has_profil est déjà défini à false par la page appelante
// (évite un calcul redondant sur index.php, qui le calcule déjà pour ses propres
// besoins avant d'inclure la sidebar).
// ──────────────────────────────────────────────────────────────────────────────
require_once 'includes/custom_icons.php'; // profil_get_social_links(), custom_link_*()
require_once 'fonctions/reviews.php';      // review_render_markdown() (bio en Markdown)

if (!isset($profil_avatar)) {
    $profil_avatar = trim($options['admin_avatar'] ?? '');
}
if (!isset($profil_pseudo)) {
    $profil_pseudo = trim($options['admin_pseudo'] ?? '');
}
if (!isset($profil_bio)) {
    $profil_bio = (string)($options['admin_bio'] ?? '');
}
if (!isset($profil_social)) {
    $profil_social = profil_get_social_links($options);
}
if (!isset($profil_has_avatar)) {
    $profil_has_avatar = ($profil_avatar !== '' && file_exists($profil_avatar));
}
if (!isset($profil_highlights)) {
    $__profil_source = $profil_data ?? $all_data ?? $data ?? [];
    $profil_highlights = profil_highlighted_series($__profil_source, $options, true);
}
// Lectures / visionnages du moment : séries actuellement « en cours »,
// tous types confondus, les plus récemment avancées en premier — visibles
// par les visiteurs au même titre que la mise en lumière ci-dessus, mais
// calculées automatiquement (rien à choisir côté admin). Respecte les mêmes
// réglages de visibilité (mode privé / masquage mature par collection) que
// la mise en lumière, via $visible_only=true.
if (!isset($profil_in_progress)) {
    $__profil_source = $profil_data ?? $all_data ?? $data ?? [];
    $profil_in_progress = profil_in_progress_series($__profil_source, 10, $options, true);
}
if (!isset($has_profil)) {
    $has_profil = ($profil_pseudo !== '' || trim($profil_bio) !== '' ||
                   $profil_has_avatar || !empty($profil_social) ||
                   !empty($profil_highlights['manga']) || !empty($profil_highlights['anime']));
}
?>
<?php if ($has_profil): ?>
<!-- Modale « Qui suis-je ? » (profil de l'administrateur) -->
<div class="modal" id="profil-modal">
    <div class="modal-content">
        <span class="close-modal" id="close-profil-modal">&times;</span>

        <div class="profil-modal-header">
            <img class="profil-modal-avatar"
                 src="<?= $profil_has_avatar ? htmlspecialchars($profil_avatar) . '?v=' . filemtime($profil_avatar) : 'assets/img/logo.png' ?>"
                 alt="<?= htmlspecialchars($profil_pseudo !== '' ? $profil_pseudo : 'Profil') ?>">
            <div class="profil-modal-heading">
                <h2><?= $profil_pseudo !== '' ? htmlspecialchars($profil_pseudo) : 'Qui suis-je ?' ?></h2>
            </div>
        </div>

        <?php if (trim($profil_bio) !== ''): ?>
            <div class="profil-modal-bio review-rendered">
                <?= review_render_markdown($profil_bio) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($profil_highlights['manga']) || !empty($profil_highlights['anime'])): ?>
            <div class="profil-modal-highlights">
                <h3 class="profil-modal-section-title">Séries coup de cœur</h3>
                <?php foreach (series_type_keys() as $__ht):
                    $__list = $profil_highlights[$__ht] ?? [];
                    if (empty($__list)) continue;
                    $__color = type_color($__ht);
                ?>
                    <div class="profil-highlights-group" style="--type-color: <?= htmlspecialchars($__color) ?>">
                        <h4 class="profil-highlights-title">
                            <img src="https://api.iconify.design/<?= str_replace(':', '/', type_icon($__ht)) ?>.svg?color=<?= rawurlencode($__color) ?>" width="16" height="16" alt="">
                            <?= htmlspecialchars(type_vocab($__ht, 'collection')) ?>
                        </h4>
                        <div class="profil-highlights-row">
                            <?php foreach ($__list as $__s): ?>
                                <button type="button" class="profil-highlight-card" data-series-id="<?= htmlspecialchars($__s['id']) ?>" title="<?= htmlspecialchars($__s['name']) ?>">
                                    <img class="profil-highlight-thumb" src="<?= htmlspecialchars($__s['thumbnail']) ?>" alt="" loading="lazy">
                                    <span class="profil-highlight-name"><?= htmlspecialchars($__s['name']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($profil_in_progress)): ?>
            <div class="profil-modal-highlights profil-modal-inprogress">
                <h3 class="profil-modal-section-title">Lectures / visionnages du moment</h3>
                <div class="profil-highlights-row">
                    <?php foreach ($profil_in_progress as $__s):
                        $__color = type_color($__s['type']);
                    ?>
                        <button type="button" class="profil-highlight-card" data-series-id="<?= htmlspecialchars($__s['id']) ?>" style="--type-color: <?= htmlspecialchars($__color) ?>" title="<?= htmlspecialchars($__s['name']) ?>">
                            <img class="profil-highlight-thumb" src="<?= htmlspecialchars($__s['thumbnail']) ?>" alt="" loading="lazy">
                            <span class="profil-highlight-name"><?= htmlspecialchars($__s['name']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($profil_social)): ?>
            <div class="profil-modal-social">
                <h3 class="profil-modal-section-title">Liens sociaux</h3>
                <div class="profil-modal-social-links">
                <?php foreach ($profil_social as $__link):
                    $__icon_name = str_replace(':', '/', custom_link_icon_name($__link['icon']));
                    $__icon_col  = rawurlencode(custom_link_color_hex($__link['color'])); ?>
                    <a href="<?= htmlspecialchars($__link['url']) ?>"
                       class="profil-social-link"
                       target="_blank" rel="noopener"
                       title="<?= htmlspecialchars($__link['name']) ?>">
                        <img src="https://api.iconify.design/<?= $__icon_name ?>.svg?color=<?= $__icon_col ?>" width="22" height="22" alt="">
                        <span><?= htmlspecialchars($__link['name']) ?></span>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
