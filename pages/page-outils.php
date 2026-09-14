<?php

// Rétablit le dossier de travail à la racine du projet : cette page vit dans
// pages/ mais tous les chemins relatifs (config.php, includes/, bdd/, uploads/…)
// sont résolus depuis la racine.
chdir(__DIR__ . '/..');
// ────────────────────────────────────────────────────────────────────────────
// page-outils.php — Index des outils
//
// Point d'entrée de l'administration pour la maintenance de la collection.
// Chaque outil vit désormais sur sa propre page, dans pages/outils/ (un
// fichier par outil, avec ses fonctions déjà séparées dans fonctions/tools/
// et son script déjà séparé dans assets/js/admin/tools/) : cette page se
// contente de les lister proprement — icône, nom, description, bouton
// d'accès — sans plus porter elle-même aucune logique d'outil ni aucun
// endpoint.
// ────────────────────────────────────────────────────────────────────────────

require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require_once 'includes/babengas.php';
require 'fonctions/series.php';
require 'includes/themes.php';
require_once 'includes/opengraph.php';

$data    = load_data();
$options = load_options();

$has_anime          = !empty(series_of_type($data, 'anime'));
$has_babengas        = function_exists('babengas_enabled') && babengas_enabled();

// ── Registre des outils ───────────────────────────────────────────────────
// Chaque entrée : icône Iconify (mdi/...), nom, description courte, fichier
// cible dans pages/outils/, une condition d'affichage facultative, et une
// couleur thématique (accord avec les couleurs déjà utilisées ailleurs sur le
// site — rose pour les mangas, bleu pour les animés, brun pour le mutualisé,
// violet pour tout ce qui touche au site lui-même) : 'pink' | 'blue' | 'brown' | 'purple'.
// Les outils sont désormais regroupés par section (Mangathèque, Animethèque,
// Mutualisés, Site) plutôt que listés en vrac : la clé 'section' rattache
// chaque outil à l'une des sections ci-dessous, et l'ordre à l'intérieur
// d'une section suit l'ordre du tableau.
$tool_sections = [
    'manga'  => 'Mangathèque',
    'anime'  => 'Animethèque',
    'shared' => 'Mutualisés',
    'site'   => 'Site',
];

$tools = [
    [
        'icon'        => 'book-check-outline',
        'name'        => 'Vérification via MangaUpdates',
        'description' => "Détecte les tomes manquants en comparant votre collection au nombre de tomes indiqué par MangaUpdates.",
        'href'        => 'outils/outil-mangaupdates.php',
        'visible'     => true,
        'color'       => 'pink',
        'section'     => 'manga',
    ],
    [
        'icon'        => 'book-search-outline',
        'name'        => 'Vérification via Babengas',
        'description' => "Nombre de tomes réellement parus en France, via Babelio — complète MangaUpdates sur l'édition VF.",
        'href'        => 'outils/outil-babengas.php',
        'visible'     => $has_babengas,
        'color'       => 'pink',
        'section'     => 'manga',
    ],
    [
        'icon'        => 'alert-circle-check-outline',
        'name'        => 'Vérification des mangas',
        'description' => "Repère les anomalies de la collection (doublons, tomes manquants, mauvais tags, prêts orphelins…).",
        'href'        => 'outils/outil-coherences.php',
        'visible'     => true,
        'color'       => 'pink',
        'section'     => 'manga',
    ],
    [
        'icon'        => 'link-variant',
        'name'        => 'Association MangaUpdates',
        'description' => "Recherche automatiquement une fiche MangaUpdates et les genres manquants pour chaque série.",
        'href'        => 'outils/outil-associations-mu.php',
        'visible'     => true,
        'color'       => 'pink',
        'section'     => 'manga',
    ],
    [
        'icon'        => 'cloud-sync-outline',
        'name'        => 'Synchronisation Syngas',
        'description' => "Envoyez et récupérez des fiches avec la base commune des mangathèques Lengas.",
        'href'        => 'outils/outil-syngas.php',
        'visible'     => true,
        'color'       => 'pink',
        'section'     => 'manga',
    ],
    [
        'icon'        => 'sync',
        'name'        => 'Synchronisation via Anilist',
        'description' => "Tient à jour les épisodes et le statut de diffusion des séries animées en cours.",
        'href'        => 'outils/outil-anilist-sync.php',
        'visible'     => $has_anime,
        'color'       => 'blue',
        'section'     => 'anime',
    ],
    [
        'icon'        => 'cloud-download-outline',
        'name'        => 'Import Anilist',
        'description' => "Importe en masse la liste animée d'un compte Anilist public, avec aperçu détaillé avant écriture.",
        'href'        => 'outils/outil-anilist-import.php',
        'visible'     => true,
        'color'       => 'blue',
        'section'     => 'anime',
    ],
    [
        'icon'        => 'clipboard-check-outline',
        'name'        => 'Vérification des animés',
        'description' => "Compare chaque fiche animée à Anilist (studios, format, genres, vignette…), avec validation avant correction.",
        'href'        => 'outils/outil-anilist-recheck.php',
        'visible'     => $has_anime,
        'color'       => 'blue',
        'section'     => 'anime',
    ],
    [
        'icon'        => 'source-merge',
        'name'        => 'Groupage de licences',
        'description' => "Suggère des séries sans licence qui semblent appartenir à la même œuvre, à regrouper en un clic.",
        'href'        => 'outils/outil-groupage-licences.php',
        'visible'     => true,
        'color'       => 'brown',
        'section'     => 'shared',
    ],
    [
        'icon'        => 'archive-arrow-down-outline',
        'name'        => 'Sauvegardes',
        'description' => "Créez, téléchargez et supprimez des archives de vos données, ou exportez-les en JSON.",
        'href'        => 'outils/outil-sauvegardes.php',
        'visible'     => true,
        'color'       => 'purple',
        'section'     => 'site',
    ],
    [
        'icon'        => 'shield-check-outline',
        'name'        => "Vérification d'intégrité du site",
        'description' => "Compare votre instance au dépôt et vérifie la structure de vos données, fichiers et modules facultatifs.",
        'href'        => 'outils/outil-integrite.php',
        'visible'     => true,
        'color'       => 'purple',
        'section'     => 'site',
    ],
    [
        'icon'        => 'broom',
        'name'        => 'Vider le cache',
        'description' => "Force l'affichage des derniers styles et scripts du site après une mise à jour, sans toucher à votre session.",
        'href'        => 'outils/outil-cache.php',
        'visible'     => true,
        'color'       => 'purple',
        'section'     => 'site',
    ],
];

// Regroupe les outils visibles par section, en conservant l'ordre défini
// dans $tool_sections puis l'ordre du tableau $tools au sein de chacune.
// Une section sans aucun outil visible (ex. Animethèque vide) est
// simplement omise de l'affichage.
$tools_by_section = [];
foreach ($tools as $tool) {
    if (empty($tool['visible'])) continue;
    $tools_by_section[$tool['section']][] = $tool;
}

// Couleurs hexadécimales utilisées pour teinter dynamiquement les icônes
// Iconify (paramètre ?color=), en accord avec les classes CSS
// .tools-index-card--<couleur> (voir assets/css/_admin.css).
$tool_icon_colors = [
    'pink'   => 'c94e93',
    'blue'   => '38bdf8',
    'brown'  => 'b08968',
    'purple' => 'c084fc',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outils — <?= htmlspecialchars($options['site_name'] ?? 'Lengas') ?></title>
    <meta name="description" content="Outils de maintenance et de vérification de la collection.">
    <?= opengraph_tags($options) ?>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <?= asset_css_tag('assets/css/main.css') ?>
    <?= theme_link_tag($options) ?>
</head>
<body class="with-sidebar tools-page">

    <?php include 'includes/sidebar.php'; ?>

    <main class="page-main">
        <div class="page-header">
            <h1>Outils</h1>
            <p class="page-subtitle">Vérifiez, complétez et sauvegardez votre collection.</p>
        </div>

        <?php foreach ($tool_sections as $section_key => $section_label): ?>
            <?php if (empty($tools_by_section[$section_key])) continue; ?>
            <section class="tools-index-section">
                <h2 class="tools-index-section-title tools-index-section-title--<?= htmlspecialchars($section_key) ?>"><?= htmlspecialchars($section_label) ?></h2>
                <div class="tools-index-grid">
                    <?php foreach ($tools_by_section[$section_key] as $tool): ?>
                        <?php $tool_color = $tool['color'] ?? 'purple'; ?>
                        <a class="tools-index-card tools-index-card--<?= htmlspecialchars($tool_color) ?>" href="<?= htmlspecialchars($tool['href']) ?>">
                            <img class="tools-index-card-icon" src="https://api.iconify.design/mdi/<?= htmlspecialchars($tool['icon']) ?>.svg?color=%23<?= htmlspecialchars($tool_icon_colors[$tool_color] ?? $tool_icon_colors['purple']) ?>" width="32" height="32" alt="">
                            <div class="tools-index-card-body">
                                <h3 class="tools-index-card-title"><?= htmlspecialchars($tool['name']) ?></h3>
                                <p class="tools-index-card-desc"><?= htmlspecialchars($tool['description']) ?></p>
                            </div>
                            <span class="tools-index-card-cta">Ouvrir →</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

    </main>

</body>
</html>
