<?php

// Rétablit le dossier de travail à la racine du projet : cette page vit dans
// pages/ mais tous les chemins relatifs (config.php, includes/, bdd/, uploads/…)
// sont résolus depuis la racine.
chdir(__DIR__ . '/..');
// ────────────────────────────────────────────────────────────────────────────
// page-options.php — Page dédiée aux options du site
//
// Regroupe toutes les options du site, auparavant présentées dans la modale
// « Options » d'admin.php. La logique de traitement (POST update_options) et
// le formulaire ont été déplacés ici, à l'image de ce qui a été fait pour la
// page « Outils ».
// ────────────────────────────────────────────────────────────────────────────

require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require_once 'includes/babengas.php';
require 'fonctions/series.php';
require 'fonctions/options.php';
require 'includes/custom_icons.php';
require 'includes/themes.php';
require_once 'vestikan/vestikan.php';
// Formats Anilist (anilist_format_keys/anilist_format_label) et réglages de
// statistiques Animethèque (stats_get_anime_settings) : nécessaires pour la
// section « Statistiques » de l'Animethèque (bloc 14).
require_once 'includes/anilist.php';
require_once 'fonctions/stats_compute.php';

$all_data = load_data();
$options  = load_options();
// ── Mangathèque / Animethèque ────────────────────────────────────────────────
// $data (manga) sert à la fois d'affichage historique de cette page (liste des
// catégories pour la section Statistiques) et reste filtré comme avant. On y
// ajoute $anime_data, propre au bloc 14, pour lister les formats d'animé
// réellement présents en collection. Aucune écriture sur la table `series`
// n'a lieu ici : ces tableaux ne servent qu'à la lecture.
$data       = series_of_type($all_data, 'manga');
$anime_data = series_of_type($all_data, 'anime');


// ============================================================================
// TRAITEMENT : mise à jour des options du site
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_options'])) {
    $options = load_options();
    $options['site_name'] = trim($_POST['site_name'] ?? '');
    $options['site_description'] = trim($_POST['site_description'] ?? '');
    $options['index_page_title'] = trim($_POST['index_page_title'] ?? '');
    $options['admin_page_title'] = trim($_POST['admin_page_title'] ?? '');
    $options['stats_page_title'] = trim($_POST['stats_page_title'] ?? '');
    $options['history_page_title'] = trim($_POST['history_page_title'] ?? '');
    // ── Visibilité (bloc 14 : réglages scindés par collection) ──────────────
    // Les clés sans suffixe pilotent la Mangathèque (rétro-compatibilité),
    // les clés `_anime` pilotent l'Animethèque, indépendamment.
    $options['private_mode'] = !empty($_POST['private_mode']);
    $options['hide_mature'] = !empty($_POST['hide_mature']);
    $options['hide_reviews'] = !empty($_POST['hide_reviews']);
    $options['private_mode_anime'] = !empty($_POST['private_mode_anime']);
    $options['hide_mature_anime'] = !empty($_POST['hide_mature_anime']);
    $options['hide_reviews_anime'] = !empty($_POST['hide_reviews_anime']);
    // Historique (historique.php) : réglage global, indépendant des deux
    // collections puisque la page les mélange volontairement toutes les deux.
    $options['hide_history'] = !empty($_POST['hide_history']);

    // ── Babengas (facultatif) ────────────────────────────────────────────────
    // L'URL est normalisée sans barre oblique finale, comme attendu par le
    // service. Une clé laissée vide dans le formulaire conserve l'ancienne :
    // elle est affichée masquée, on ne veut pas l'effacer par mégarde.
    $options['babengas_url']     = rtrim(trim($_POST['babengas_url'] ?? ''), '/');
    $options['babengas_enabled'] = !empty($_POST['babengas_enabled']);

    $babengas_key_in = trim($_POST['babengas_key'] ?? '');
    if ($babengas_key_in !== '') {
        $options['babengas_key'] = $babengas_key_in;
    }

    // ── Thème du site (validé contre les fichiers _variables-*.css présents) ──
    $theme_key = strtolower(trim($_POST['theme'] ?? 'dark'));
    $options['theme'] = theme_exists($theme_key) ? $theme_key : 'dark';
    // Note : le pseudo de l'admin (admin_pseudo) se règle depuis la page Profil.
    // On ne le touche pas ici pour ne pas l'écraser.

    // ── Liens personnalisés (nombre variable) ────────────────────────────────
    // Les liens arrivent sous forme de tableaux parallèles POST :
    //   custom_link_name[], custom_link_url[], custom_link_icon[], custom_link_color[]
    // On les valide et on les stocke dans une clé JSON unique « custom_links ».
    // Les anciennes clés fixes custom_button_* sont retirées (migration).
    require_once 'includes/custom_icons.php';
    $allowed_icon_keys  = array_keys(custom_link_icons());
    $allowed_color_keys = array_keys(custom_link_colors());

    $names  = $_POST['custom_link_name']  ?? [];
    $urls   = $_POST['custom_link_url']   ?? [];
    $icons  = $_POST['custom_link_icon']  ?? [];
    $colors = $_POST['custom_link_color'] ?? [];
    if (!is_array($names))  $names  = [];
    if (!is_array($urls))   $urls   = [];
    if (!is_array($icons))  $icons  = [];
    if (!is_array($colors)) $colors = [];

    $custom_links = [];
    $count = max(count($names), count($urls));
    for ($i = 0; $i < $count; $i++) {
        $name = trim((string)($names[$i] ?? ''));
        $url  = trim((string)($urls[$i]  ?? ''));
        // On ignore les lignes incomplètes (nom ou URL manquant)
        if ($name === '' || $url === '') continue;

        $icon  = $icons[$i]  ?? 'link';
        $color = $colors[$i] ?? custom_link_default_color();
        $custom_links[] = [
            'name'  => $name,
            'url'   => $url,
            'icon'  => in_array($icon, $allowed_icon_keys, true) ? $icon : 'link',
            'color' => in_array($color, $allowed_color_keys, true) ? $color : custom_link_default_color(),
        ];
    }
    $options['custom_links'] = json_encode($custom_links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Nettoyage des anciennes clés fixes (devenues obsolètes) : on les retire
    // du tableau ET du stockage, car save_options ne supprime jamais de clés.
    $legacy_keys = [];
    foreach (['', '2', '3'] as $suffix) {
        foreach (["custom_button_name$suffix", "custom_button_url$suffix", "custom_button_icon$suffix"] as $lk) {
            unset($options[$lk]);
            $legacy_keys[] = $lk;
        }
    }
    delete_options($legacy_keys);

    // ── Section "Statistiques" : valeurs de repli globales + par catégorie ──
    $norm_num = function ($v) {
        $v = str_replace(',', '.', trim((string) $v));
        return ($v === '' || !is_numeric($v)) ? '' : (string) (float) $v;
    };

    $options['stats_default_minutes']         = $norm_num($_POST['stats_default_minutes']         ?? '');
    $options['stats_default_value']           = $norm_num($_POST['stats_default_value']           ?? '');
    $options['stats_default_value_collector'] = $norm_num($_POST['stats_default_value_collector'] ?? '');
    if ($options['stats_default_minutes'] === '')         $options['stats_default_minutes']         = '40';
    if ($options['stats_default_value'] === '')           $options['stats_default_value']           = '7';
    if ($options['stats_default_value_collector'] === '') $options['stats_default_value_collector'] = '15';

    $cat_settings = [];
    if (!empty($_POST['stats_cat']) && is_array($_POST['stats_cat'])) {
        foreach ($_POST['stats_cat'] as $cat_name => $fields) {
            $cat_name = trim((string) $cat_name);
            if ($cat_name === '') continue;
            $minutes = $norm_num($fields['minutes'] ?? '');
            $value   = $norm_num($fields['value']   ?? '');
            $valuec  = $norm_num($fields['value_collector'] ?? '');
            // N'enregistrer que si au moins un champ est renseigné
            if ($minutes === '' && $value === '' && $valuec === '') continue;
            $cat_settings[$cat_name] = [
                'minutes'         => $minutes,
                'value'           => $value,
                'value_collector' => $valuec,
            ];
        }
    }
    $options['stats_category_settings'] = json_encode($cat_settings, JSON_UNESCAPED_UNICODE);

    // ── Section "Statistiques" de l'Animethèque : durée par format (bloc 14) ─
    // Pendant de la section manga ci-dessus, mais une seule dimension (durée
    // d'épisode en minutes) par FORMAT Anilist plutôt que temps + valeur x2
    // par catégorie : les animés n'ont pas de notion de prix (cf. bloc 13).
    $options['stats_anime_default_minutes'] = $norm_num($_POST['stats_anime_default_minutes'] ?? '');
    if ($options['stats_anime_default_minutes'] === '') $options['stats_anime_default_minutes'] = '24';

    $anime_format_settings = [];
    if (!empty($_POST['stats_anime_format']) && is_array($_POST['stats_anime_format'])) {
        foreach ($_POST['stats_anime_format'] as $format_key => $minutes_in) {
            $format_key = strtoupper(trim((string) $format_key));
            if ($format_key === '' || !in_array($format_key, anilist_format_keys(), true)) continue;
            $minutes = $norm_num($minutes_in);
            if ($minutes === '') continue; // champ vide → repli sur la valeur par défaut, rien à stocker
            $anime_format_settings[$format_key] = $minutes;
        }
    }
    $options['stats_anime_format_settings'] = json_encode($anime_format_settings, JSON_UNESCAPED_UNICODE);

    $admin_password = trim($_POST['admin_password'] ?? '');

    // Gestion du remplacement de logo.png
    if (!empty($_FILES['default_logo']['name'])) {
        $uploaded_image = $_FILES['default_logo'];
        $allowed_types = ['image/png'];
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($file_info, $uploaded_image['tmp_name']);

        // Vérification du type MIME
        if (!in_array($mime_type, $allowed_types)) {
            $_SESSION['error_message'] = "Seuls le PNG est autorisés pour le logo.";
        } else {
            // Chemin absolu vers logo.png
            $logo_path = __DIR__ . '/../assets/img/logo.png';

            // Supprimer l'ancien logo.png s'il existe
            if (file_exists($logo_path)) {
                if (!unlink($logo_path)) {
                    $_SESSION['error_message'] = "Impossible de supprimer l'ancien logo. Vérifiez les permissions.";
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit;
                }
            }

            // Déplacer le nouveau fichier
            if (move_uploaded_file($uploaded_image['tmp_name'], $logo_path)) {
                $_SESSION['success_message'] = "Le logo par défaut a été mis à jour avec succès.";
            } else {
                $_SESSION['error_message'] = "Erreur lors du déplacement du fichier. Vérifiez les permissions du dossier.";
            }
        }
    }

    // Mise à jour des autres options (sans toucher à default_image)
    $result = update_options($options, $admin_password);
    if ($result['success']) {
        $_SESSION['success_message'] = $result['message'];
    } else {
        $_SESSION['error_message'] = $result['message'];
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Options — <?= htmlspecialchars($options['site_name'] ?? 'Lengas') ?></title>
    <meta name="description" content="Options et configuration du site.">
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <link rel="stylesheet" href="../assets/css/main.css">
    <?= theme_link_tag($options) ?>
</head>
<body class="with-sidebar">

    <?php include 'includes/sidebar.php'; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div id="error-message" class="error-message">
            <?= $_SESSION['error_message'] ?>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success_message'])): ?>
        <?php
        $message = $_SESSION['success_message'];
        $is_warning = (strpos($message, 'attention') !== false);
        ?>
        <div class="alert <?php echo $is_warning ? 'alert-warning' : 'alert-success'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <main class="page-main">
        <div class="page-header">
            <h1>Options</h1>
            <p class="page-subtitle">Personnalisez votre site et sa configuration.</p>
        </div>

        <?php
        $latest_version = get_latest_version_from_gitea();
        $current_version = SITE_VERSION;
        $version_class = '';
        $version_tooltip = '';
        if ($latest_version && version_compare($current_version, $latest_version, '<')) {
            $version_class = 'version-outdated';
            $version_tooltip = "Une nouvelle version ($latest_version) est disponible ! Il est recommandé de mettre à jour.";
        }
        ?>
        <p class="hint <?= $version_class ?>" data-tooltip="<?= htmlspecialchars($version_tooltip) ?>">
            Site en version <?= $current_version ?>.
            <a href="<?= URL_GITEA ?>" target="_blank">Accéder au dépôt Gitéa</a>.
        </p>

        <div class="options-page-form">
            <form id="options-form" method="post" enctype="multipart/form-data">

                <h3 class="options-section-title">Titres et descriptions</h3>

                <label for="site-name">Nom du site</label>
                <input type="text" name="site_name" id="site-name" placeholder="Nom du site" value="<?= htmlspecialchars($options['site_name']) ?>" required>

                <label for="site-description">Description du site</label>
                <input type="text" name="site_description" id="site-description" placeholder="Description du site" value="<?= htmlspecialchars($options['site_description']) ?>" required>

                <label for="index-page-title">Titre de la page d'accueil</label>
                <input type="text" name="index_page_title" id="index-page-title" placeholder="Titre de la page d'accueil" value="<?= htmlspecialchars($options['index_page_title']) ?>" required>

                <label for="admin-page-title">Titre de la page d'administration</label>
                <input type="text" name="admin_page_title" id="admin-page-title" placeholder="Titre de la page d'administration" value="<?= htmlspecialchars($options['admin_page_title']) ?>" required>

                <label for="stats-page-title">Titre de la page de statistiques</label>
                <input type="text" name="stats_page_title" id="stats-page-title" placeholder="Titre de la page de statistiques" value="<?= htmlspecialchars($options['stats_page_title']) ?>" required>

                <label for="history-page-title">Titre de la page « Historique »</label>
                <input type="text" name="history_page_title" id="history-page-title" placeholder="Historique" value="<?= htmlspecialchars($options['history_page_title'] ?? '') ?>">
                <p class="hint">Laissez vide pour reprendre le nom du site.</p>

                <p class="hint">Le pseudo de l'admin (utilisé pour créditer les critiques) se règle désormais depuis la page <a href="page-profil.php">Profil</a>.</p>

                <h3 class="options-section-title">Liens personnalisés</h3>
                <p class="hint">Ces liens apparaissent dans le menu latéral des pages publiques (accueil et statistiques). Vous pouvez en ajouter autant que souhaité ; choisissez une icône et une couleur pour chacun. Un lien sans nom ou sans URL est ignoré.</p>

                <?php
                // Fragment de rendu d'une carte de lien. $link = ['name','url','icon','color'].
                // $tpl = true → gabarit vide, champs sans attribut name pour ne pas être
                // soumis tant que la ligne n'est pas clonée et activée par le JS.
                $render_custom_link = function (array $link, bool $tpl = false): void {
                    $name  = $tpl ? '' : htmlspecialchars($link['name']);
                    $url   = $tpl ? '' : htmlspecialchars($link['url']);
                    $icon  = $tpl ? 'link' : ($link['icon'] ?? 'link');
                    $color = $tpl ? custom_link_default_color() : ($link['color'] ?? custom_link_default_color());
                    $icon_hex = custom_link_color_hex($color);
                    $icon_url = 'https://api.iconify.design/' . str_replace(':', '/', custom_link_icon_name($icon)) . '.svg?color=' . rawurlencode($icon_hex);
                    // Attribut name : vide dans le gabarit (activé au clonage par le JS)
                    $n = function (string $field) use ($tpl): string {
                        return $tpl ? '' : ' name="custom_link_' . $field . '[]"';
                    };
                    ?>
                    <div class="custom-link-card" data-custom-link<?= $tpl ? ' data-template' : '' ?>>
                        <div class="custom-link-card-head">
                            <span class="custom-link-card-title">Lien personnalisé</span>
                            <div class="custom-link-actions">
                                <button type="button" class="custom-link-move custom-link-up" title="Monter ce lien" aria-label="Monter ce lien">▲</button>
                                <button type="button" class="custom-link-move custom-link-down" title="Descendre ce lien" aria-label="Descendre ce lien">▼</button>
                                <button type="button" class="custom-link-remove" title="Supprimer ce lien" aria-label="Supprimer ce lien">&times;</button>
                            </div>
                        </div>

                        <label>Nom du bouton</label>
                        <input type="text"<?= $n('name') ?> class="cl-name" placeholder="Nom du bouton" value="<?= $name ?>">

                        <label>URL du bouton</label>
                        <input type="text"<?= $n('url') ?> class="cl-url" placeholder="https://exemple.fr" value="<?= $url ?>">

                        <label>Icône &amp; couleur</label>
                        <div class="custom-icon-field">
                            <input type="hidden"<?= $n('icon') ?>  class="cl-icon"  value="<?= htmlspecialchars($icon) ?>">
                            <input type="hidden"<?= $n('color') ?> class="cl-color" value="<?= htmlspecialchars($color) ?>">
                            <button type="button" class="custom-icon-trigger">
                                <img class="custom-icon-preview" src="<?= $icon_url ?>" width="22" height="22" alt="">
                                <span class="custom-icon-label"><?= htmlspecialchars(custom_link_icon_label($icon)) ?></span>
                                <span class="custom-icon-caret">▾</span>
                            </button>
                        </div>
                    </div>
                    <?php
                };

                $existing_links = custom_link_get_links($options);
                ?>

                <div id="custom-links-list">
                    <?php foreach ($existing_links as $__link) $render_custom_link($__link, false); ?>
                </div>

                <p id="custom-links-empty" class="hint" style="<?= empty($existing_links) ? '' : 'display:none;' ?>">
                    Aucun lien pour le moment. Cliquez sur « Ajouter un lien personnalisé » pour commencer.
                </p>

                <button type="button" id="add-custom-link-btn" class="button button-ats">＋ Ajouter un lien personnalisé</button>

                <!-- Gabarit d'un nouveau lien (cloné par le JS) -->
                <template id="custom-link-template">
                    <?php $render_custom_link(['name' => '', 'url' => '', 'icon' => 'link', 'color' => custom_link_default_color()], true); ?>
                </template>

                <!-- ══ STATISTIQUES ══════════════════════════════════════ -->
                <h3 class="options-section-title">Statistiques</h3>
                <p class="hint">Réglez le temps de lecture moyen et la valeur moyenne d'un tome, par catégorie. Ces valeurs alimentent la page de statistiques (temps de lecture et valeur de la collection).</p>

                <?php
                // Réglages courants
                $stats_cat_settings = [];
                if (!empty($options['stats_category_settings'])) {
                    $decoded = json_decode($options['stats_category_settings'], true);
                    if (is_array($decoded)) $stats_cat_settings = $decoded;
                }

                // Liste des catégories présentes en collection
                $all_categories = [];
                foreach ($data as $___s) {
                    foreach (($___s['categories'] ?? []) as $___c) {
                        $___c = trim((string) $___c);
                        if ($___c !== '' && !in_array($___c, $all_categories, true)) {
                            $all_categories[] = $___c;
                        }
                    }
                }
                // Inclure aussi les catégories déjà réglées mais absentes de la collection
                foreach (array_keys($stats_cat_settings) as $___c) {
                    if (!in_array($___c, $all_categories, true)) $all_categories[] = $___c;
                }
                sort($all_categories, SORT_NATURAL | SORT_FLAG_CASE);
                ?>

                <div class="stats-defaults">
                    <label>Valeurs par défaut (catégories non renseignées)</label>
                    <div class="stats-cat-row stats-cat-head">
                        <span class="stats-cat-name">Par défaut</span>
                        <input type="number" step="any" min="0" name="stats_default_minutes" placeholder="Min/tome" value="<?= htmlspecialchars($options['stats_default_minutes'] ?? '40') ?>">
                        <input type="number" step="any" min="0" name="stats_default_value" placeholder="€ normal" value="<?= htmlspecialchars($options['stats_default_value'] ?? '7') ?>">
                        <input type="number" step="any" min="0" name="stats_default_value_collector" placeholder="€ collector" value="<?= htmlspecialchars($options['stats_default_value_collector'] ?? '15') ?>">
                    </div>
                </div>

                <?php if (empty($all_categories)): ?>
                    <p class="hint">Aucune catégorie dans votre collection pour le moment.</p>
                <?php else: ?>
                    <div class="stats-cat-row stats-cat-labels">
                        <span class="stats-cat-name">Catégorie</span>
                        <span>Min/tome</span>
                        <span>€ normal</span>
                        <span>€ collector</span>
                    </div>
                    <div class="stats-cat-list">
                        <?php foreach ($all_categories as $cat):
                            $cfg = $stats_cat_settings[$cat] ?? ['minutes' => '', 'value' => '', 'value_collector' => ''];
                            $cat_attr = htmlspecialchars($cat); ?>
                            <div class="stats-cat-row">
                                <span class="stats-cat-name" title="<?= $cat_attr ?>"><?= $cat_attr ?></span>
                                <input type="number" step="any" min="0" name="stats_cat[<?= $cat_attr ?>][minutes]"         placeholder="<?= htmlspecialchars($options['stats_default_minutes'] ?? '40') ?>"         value="<?= htmlspecialchars($cfg['minutes'] ?? '') ?>">
                                <input type="number" step="any" min="0" name="stats_cat[<?= $cat_attr ?>][value]"           placeholder="<?= htmlspecialchars($options['stats_default_value'] ?? '7') ?>"           value="<?= htmlspecialchars($cfg['value'] ?? '') ?>">
                                <input type="number" step="any" min="0" name="stats_cat[<?= $cat_attr ?>][value_collector]" placeholder="<?= htmlspecialchars($options['stats_default_value_collector'] ?? '15') ?>" value="<?= htmlspecialchars($cfg['value_collector'] ?? '') ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="hint">Laissez un champ vide pour utiliser la valeur par défaut. Les séries à plusieurs catégories utilisent la moyenne de leurs catégories.</p>
                <?php endif; ?>

                <!-- ══ STATISTIQUES — ANIMETHÈQUE ═══════════════════════════ -->
                <h3 class="options-section-title">Statistiques (Animethèque)</h3>
                <p class="hint">Réglez la durée moyenne d'un épisode (en minutes), par format Anilist. Ces valeurs alimentent le temps de visionnage de la page de statistiques ; elles ne servent que si la durée réelle de l'épisode n'est pas connue d'Anilist. Pas de notion de prix côté animés (cf. bloc 13).</p>

                <?php
                // Réglages courants (déjà lus/normalisés par stats_get_anime_settings(),
                // qui applique elle-même le repli par défaut — cf. fonctions/stats_compute.php).
                $anime_settings_current = stats_get_anime_settings($options);
                $anime_def_minutes_cur  = $anime_settings_current['default'];
                $anime_fmt_settings_cur = $anime_settings_current['formats']; // [FORMAT => minutes]
                ?>

                <div class="stats-defaults">
                    <label>Valeur par défaut (formats non renseignés)</label>
                    <div class="stats-cat-row stats-cat-row--anime stats-cat-head">
                        <span class="stats-cat-name">Par défaut</span>
                        <input type="number" step="any" min="0" name="stats_anime_default_minutes" placeholder="Min/épisode" value="<?= htmlspecialchars((string) $anime_def_minutes_cur) ?>">
                    </div>
                </div>

                <div class="stats-cat-row stats-cat-row--anime stats-cat-labels">
                    <span class="stats-cat-name">Format</span>
                    <span>Min/épisode</span>
                </div>
                <div class="stats-cat-list">
                    <?php foreach (anilist_format_keys() as $__fmt_key):
                        $__fmt_label = anilist_format_label($__fmt_key);
                        $__fmt_val   = $anime_fmt_settings_cur[$__fmt_key] ?? '';
                    ?>
                        <div class="stats-cat-row stats-cat-row--anime">
                            <span class="stats-cat-name" title="<?= htmlspecialchars($__fmt_label) ?>"><?= htmlspecialchars($__fmt_label) ?></span>
                            <input type="number" step="any" min="0"
                                   name="stats_anime_format[<?= htmlspecialchars($__fmt_key) ?>]"
                                   placeholder="<?= htmlspecialchars((string) $anime_def_minutes_cur) ?>"
                                   value="<?= $__fmt_val === '' ? '' : htmlspecialchars((string) $__fmt_val) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="hint">Laissez un champ vide pour utiliser la valeur par défaut. Un épisode dont la durée réelle est connue via Anilist n'utilise jamais ces réglages.</p>

                <!-- ══ VIGNETTE ══════════════════════════════════════════ -->
                <h3 class="options-section-title">Vignette</h3>

                <div class="form-group">
                    <label for="default_logo">Remplacer la vignette par défaut :</label>
                    <input type="file" id="default_logo" name="default_logo" accept="image/png">
                    <p class="hint">L'image téléversée remplacera le fichier logo.png actuel (PNG obligatoire). Cette vignette est <strong>partagée</strong> entre la Mangathèque et l'Animethèque : elle sert de repli final pour les deux collections (après la vignette personnalisée d'une série, et, côté animés, après la vignette Anilist).</p>
                    <p class="hint">Vignette par défaut actuelle :</p>
                    <?php if (file_exists('assets/img/logo.png')): ?>
                        <div>
                            <img src="../assets/img/logo.png?v=<?= time() ?>" alt="Logo actuel" style="max-width: 100px; max-height: 100px;">
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ══ THÈMES ════════════════════════════════════════════ -->
                <h3 class="options-section-title">Thèmes</h3>
                <p class="hint">Choisissez l'apparence du site. Le thème « Sombre » est appliqué par défaut. Pour ajouter un thème personnalisé, déposez un fichier <code>assets/css/_variables-&lt;nom&gt;.css</code> : il apparaîtra automatiquement dans cette liste.</p>

                <?php
                $themes_list  = list_themes();
                $current_theme = current_theme_key($options);
                ?>
                <label for="theme-select">Thème du site</label>
                <select name="theme" id="theme-select" class="theme-select">
                    <?php foreach ($themes_list as $__t): ?>
                        <option value="<?= htmlspecialchars($__t['key']) ?>" <?= $current_theme === $__t['key'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($__t['label']) ?><?= $__t['custom'] ? ' — personnalisé' : ' — de base' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">
                    Les thèmes marqués « de base » sont fournis avec Lengas
                    (<code>_variables.css</code> et <code>_variables-light.css</code>) ;
                    ceux marqués « personnalisé » proviennent de vos propres fichiers.
                </p>

                <!-- ══ VISIBILITÉ ════════════════════════════════════════ -->
                <?php
                // Fragment de rendu d'un bloc de visibilité pour une collection.
                // $suffix = '' (Mangathèque, clés historiques) ou '_anime' (Animethèque).
                $render_visibility_block = function (string $type, string $suffix) use ($options): void {
                    $collection = type_vocab($type, 'collection'); // "Mangathèque" / "Animethèque"
                    $__private = !empty($options['private_mode' . $suffix]);
                    $__mature  = !empty($options['hide_mature' . $suffix]);
                    $__reviews = !empty($options['hide_reviews' . $suffix]);
                    ?>
                    <h4 class="options-subsection-title"><?= htmlspecialchars($collection) ?></h4>

                    <label>
                        <input type="checkbox" name="private_mode<?= $suffix ?>" <?= $__private ? 'checked' : '' ?>> Mode privé
                    </label>
                    <p class="hint">La <?= htmlspecialchars($collection) ?> ne sera pas visible publiquement : le bouton reste dans le menu, mais son contenu est entièrement masqué (aucun décompte).</p>

                    <label>
                        <input type="checkbox" name="hide_mature<?= $suffix ?>" <?= $__mature ? 'checked' : '' ?>> Masquer les séries matures
                    </label>
                    <p class="hint">Les séries matures de la <?= htmlspecialchars($collection) ?> ne seront pas visibles au public.</p>

                    <label>
                        <input type="checkbox" name="hide_reviews<?= $suffix ?>" <?= $__reviews ? 'checked' : '' ?>> Cacher les critiques
                    </label>
                    <p class="hint">Les critiques de la <?= htmlspecialchars($collection) ?> ne seront pas visibles au public.</p>
                    <?php
                };
                ?>
                <h3 class="options-section-title">Visibilité</h3>
                <p class="hint">Chaque collection a ses propres réglages de visibilité, entièrement indépendants l'un de l'autre.</p>

                <?php $render_visibility_block('manga', ''); ?>
                <?php $render_visibility_block('anime', '_anime'); ?>

                <h4 class="options-subsection-title">Historique</h4>
                <label>
                    <input type="checkbox" name="hide_history" <?= !empty($options['hide_history']) ? 'checked' : '' ?>> Cacher la page « Historique »
                </label>
                <p class="hint">La page <code>historique.php</code>, qui liste jour après jour les tomes lus et épisodes vus (Mangathèque et Animethèque confondues), ne sera pas accessible publiquement. Son lien disparaît aussi du menu latéral public.</p>

                <!-- ══ BABENGAS ══════════════════════════════════════════ -->
                <h3 class="options-section-title">Babengas (Babelio)</h3>
                <p class="hint">
                    Babengas est un microservice à héberger chez vous (Docker, IP résidentielle)
                    qui interroge Babelio pour connaître le nombre de tomes <strong>réellement
                    parus en France</strong>. Il complète MangaUpdates, dont le décompte VF est
                    souvent absent. Laissez ces champs vides pour désactiver la fonctionnalité :
                    Lengas reste 100 % fonctionnel.
                </p>

                <label for="babengas-url">URL du service</label>
                <input type="text" name="babengas_url" id="babengas-url"
                       placeholder="https://babengas.mondomaine.fr"
                       value="<?= htmlspecialchars($options['babengas_url'] ?? '') ?>"
                       autocomplete="off">
                <p class="hint">Sans barre oblique finale. Le HTTPS n'est pas optionnel : la clé circule dans un en-tête à chaque appel.</p>

                <label for="babengas-key">Clé partagée</label>
                <input type="password" name="babengas_key" id="babengas-key"
                       placeholder="<?= !empty($options['babengas_key']) ? 'Clé enregistrée — laisser vide pour ne pas modifier' : 'Valeur de BABENGAS_KEY dans le fichier .env' ?>"
                       autocomplete="off">

                <label>
                    <input type="checkbox" name="babengas_enabled" <?= !empty($options['babengas_enabled']) ? 'checked' : '' ?>> Activer la vérification via Babengas
                </label>

                <?php if (function_exists('babengas_enabled') && babengas_enabled()): ?>
                    <?php $bg_state = babengas_check_service(); ?>
                    <?php if ($bg_state['ok']): ?>
                        <p class="hint"><span class="ok">●</span> Service joignable<?= $bg_state['version'] !== '' ? ' — version ' . htmlspecialchars($bg_state['version']) : '' ?><?= $bg_state['actif'] ? '' : ' (traitement en pause côté Babengas)' ?>.</p>
                    <?php else: ?>
                        <p class="hint"><span class="warn">●</span> Service injoignable : <?= htmlspecialchars($bg_state['error']) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="hint"><span class="warn">●</span> Vérification via Babengas : <strong>inactive</strong>.</p>
                <?php endif; ?>

                <!-- ══ MOT DE PASSE ══════════════════════════════════════ -->
                <h3 class="options-section-title">Mot de passe</h3>

                <label for="admin-password">Mot de passe admin</label>
                <input type="password" name="admin_password" id="admin-password" placeholder="Mot de passe admin">
                <p class="hint">Laisser vide pour ne pas modifier.</p>

                <?php if (function_exists('vestikan_enabled') && vestikan_enabled()): ?>
                    <p class="hint"><span class="ok">●</span> Connexion via Vestikan : <strong>active</strong>.</p>
                <?php else: ?>
                    <p class="hint"><span class="warn">●</span> Connexion via Vestikan : <strong>inactive</strong>. Déposez les fichiers Vestikan et <code>vestikan/vestikan-config.php</code> pour l'activer.</p>
                <?php endif; ?>

                <button type="submit" name="update_options" class="button button-opt">Mettre à jour</button>
            </form>
        </div>
    </main>

    <!-- ── Modale de sélection d'icône + couleur (liens personnalisés) ─────── -->
    <div class="modal" id="icon-picker-modal">
        <div class="modal-content modal-content--narrow">
            <span class="close-modal" id="close-icon-picker-modal">&times;</span>
            <h2>Choisir l'icône et la couleur</h2>

            <div class="icon-picker-colors" id="icon-picker-colors">
                <?php foreach (custom_link_colors() as $__ck => $__hex): ?>
                    <button type="button" class="icon-picker-color"
                            data-color="<?= htmlspecialchars($__ck) ?>"
                            data-hex="<?= htmlspecialchars($__hex) ?>"
                            style="--swatch: <?= htmlspecialchars($__hex) ?>;"
                            title="<?= htmlspecialchars(custom_link_color_labels()[$__ck] ?? $__ck) ?>"
                            aria-label="<?= htmlspecialchars(custom_link_color_labels()[$__ck] ?? $__ck) ?>"></button>
                <?php endforeach; ?>
            </div>

            <input type="text" id="icon-picker-search" class="icon-picker-search"
                   placeholder="Rechercher une icône…" autocomplete="off">
            <div id="icon-picker-grid-wrap" class="icon-picker-grid-wrap">
                <?php foreach (custom_link_icon_groups() as $__group => $__keys): ?>
                    <div class="icon-picker-group" data-group>
                        <h3 class="icon-picker-group-title"><?= htmlspecialchars($__group) ?></h3>
                        <div class="icon-picker-grid">
                            <?php foreach ($__keys as $__key):
                                $__lbl  = custom_link_icon_label($__key);
                                $__name = str_replace(':', '/', custom_link_icon_name($__key)); ?>
                                <button type="button" class="icon-picker-item"
                                        data-key="<?= htmlspecialchars($__key) ?>"
                                        data-label="<?= htmlspecialchars($__lbl) ?>"
                                        data-icon-path="<?= htmlspecialchars($__name) ?>"
                                        data-search="<?= htmlspecialchars(mb_strtolower($__lbl . ' ' . $__key)) ?>"
                                        title="<?= htmlspecialchars($__lbl) ?>">
                                    <img src="https://api.iconify.design/<?= $__name ?>.svg?color=%23d4d4e8"
                                         width="24" height="24" alt="" loading="lazy">
                                    <span><?= htmlspecialchars($__lbl) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <p id="icon-picker-empty" class="icon-picker-empty" style="display:none;">Aucune icône ne correspond.</p>
            </div>
        </div>
    </div>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <script>
    // Bouton « Retour en haut »
    window.addEventListener('scroll', function () {
        var backToTop = document.getElementById('back-to-top');
        if (backToTop) backToTop.style.display = window.pageYOffset > 300 ? 'block' : 'none';
    });
    document.getElementById('back-to-top')?.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    </script>

    <script>
    // ── Liens personnalisés : ajout / suppression + sélecteur icône & couleur ─
    (function () {
        var COLORS = <?= json_encode(custom_link_colors(), JSON_UNESCAPED_SLASHES) ?>;
        var DEFAULT_COLOR = <?= json_encode(custom_link_default_color()) ?>;

        var list    = document.getElementById('custom-links-list');
        var tpl     = document.getElementById('custom-link-template');
        var addBtn  = document.getElementById('add-custom-link-btn');
        var emptyEl = document.getElementById('custom-links-empty');

        var modal   = document.getElementById('icon-picker-modal');
        var closeEl = document.getElementById('close-icon-picker-modal');
        var search  = document.getElementById('icon-picker-search');
        var emptyIc = document.getElementById('icon-picker-empty');
        var colorRow = document.getElementById('icon-picker-colors');

        if (!list || !modal) return;

        function hex(colorKey) { return COLORS[colorKey] || COLORS[DEFAULT_COLOR]; }
        function iconUrl(path, colorKey) {
            return 'https://api.iconify.design/' + path + '.svg?color=' + encodeURIComponent(hex(colorKey));
        }

        // ── Gestion des cartes de liens ──────────────────────────────────────
        function refreshEmpty() {
            if (!emptyEl) return;
            emptyEl.style.display = list.querySelector('.custom-link-card') ? 'none' : '';
        }

        // Désactive « Monter » sur la première carte et « Descendre » sur la dernière
        function refreshMoveButtons() {
            var cards = list.querySelectorAll('.custom-link-card');
            cards.forEach(function (card, i) {
                var up   = card.querySelector('.custom-link-up');
                var down = card.querySelector('.custom-link-down');
                if (up)   up.disabled   = (i === 0);
                if (down) down.disabled = (i === cards.length - 1);
            });
        }

        function moveCard(card, dir) {
            if (dir < 0) {
                var prev = card.previousElementSibling;
                if (prev) list.insertBefore(card, prev);
            } else {
                var next = card.nextElementSibling;
                if (next) list.insertBefore(next, card);
            }
            refreshMoveButtons();
        }

        function activateCard(card) {
            // Active les name[] (le gabarit les laisse vides pour ne pas être soumis)
            card.querySelector('.cl-name').setAttribute('name', 'custom_link_name[]');
            card.querySelector('.cl-url').setAttribute('name', 'custom_link_url[]');
            card.querySelector('.cl-icon').setAttribute('name', 'custom_link_icon[]');
            card.querySelector('.cl-color').setAttribute('name', 'custom_link_color[]');
            card.removeAttribute('data-template');
        }

        function wireCard(card) {
            var removeBtn = card.querySelector('.custom-link-remove');
            if (removeBtn) removeBtn.addEventListener('click', function () {
                card.remove();
                refreshEmpty();
                refreshMoveButtons();
            });
            var upBtn = card.querySelector('.custom-link-up');
            if (upBtn) upBtn.addEventListener('click', function () { moveCard(card, -1); });
            var downBtn = card.querySelector('.custom-link-down');
            if (downBtn) downBtn.addEventListener('click', function () { moveCard(card, 1); });
            var trigger = card.querySelector('.custom-icon-trigger');
            if (trigger) trigger.addEventListener('click', function () { openModal(card); });
        }

        if (addBtn && tpl) {
            addBtn.addEventListener('click', function () {
                var frag = tpl.content.cloneNode(true);
                var card = frag.querySelector('.custom-link-card');
                activateCard(card);
                list.appendChild(card);
                wireCard(card);
                refreshEmpty();
                refreshMoveButtons();
                card.querySelector('.cl-name').focus();
            });
        }

        // Câble les cartes déjà présentes au chargement
        list.querySelectorAll('.custom-link-card').forEach(wireCard);
        refreshEmpty();
        refreshMoveButtons();

        // ── Modale icône + couleur ───────────────────────────────────────────
        var activeCard = null;

        function currentColor() {
            if (!activeCard) return DEFAULT_COLOR;
            var c = activeCard.querySelector('.cl-color').value;
            return COLORS[c] ? c : DEFAULT_COLOR;
        }

        function paintPreviewItems(colorKey) {
            // Teinte tous les items de la grille selon la couleur choisie
            modal.querySelectorAll('.icon-picker-item').forEach(function (item) {
                var img = item.querySelector('img');
                if (img) img.src = iconUrl(item.dataset.iconPath, colorKey);
            });
        }

        function markSelectedColor(colorKey) {
            if (!colorRow) return;
            colorRow.querySelectorAll('.icon-picker-color').forEach(function (sw) {
                sw.classList.toggle('is-selected', sw.dataset.color === colorKey);
            });
        }

        function openModal(card) {
            activeCard = card;
            var iconKey  = card.querySelector('.cl-icon').value || 'link';
            var colorKey = currentColor();

            search.value = '';
            filterIcons('');
            markSelectedColor(colorKey);
            paintPreviewItems(colorKey);
            modal.querySelectorAll('.icon-picker-item').forEach(function (item) {
                item.classList.toggle('is-selected', item.dataset.key === iconKey);
            });

            modal.classList.add('modal-active');
            setTimeout(function () { search.focus(); }, 50);
        }

        function closeModal() {
            modal.classList.remove('modal-active');
            activeCard = null;
        }

        function applyPreview() {
            if (!activeCard) return;
            var iconKey  = activeCard.querySelector('.cl-icon').value || 'link';
            var colorKey = currentColor();
            var item = modal.querySelector('.icon-picker-item[data-key="' + iconKey + '"]');
            var path = item ? item.dataset.iconPath : 'mdi/link-variant';
            var img  = activeCard.querySelector('.custom-icon-preview');
            if (img) img.src = iconUrl(path, colorKey);
        }

        function chooseColor(colorKey) {
            if (!activeCard) return;
            activeCard.querySelector('.cl-color').value = colorKey;
            markSelectedColor(colorKey);
            paintPreviewItems(colorKey); // met à jour la grille en direct
            applyPreview();
        }

        function chooseIcon(item) {
            if (!activeCard) { closeModal(); return; }
            activeCard.querySelector('.cl-icon').value = item.dataset.key;
            var lbl = activeCard.querySelector('.custom-icon-label');
            if (lbl) lbl.textContent = item.dataset.label;
            applyPreview();
            closeModal();
        }

        function filterIcons(term) {
            term = (term || '').trim().toLowerCase();
            var anyVisible = false;
            modal.querySelectorAll('[data-group]').forEach(function (group) {
                var groupHasMatch = false;
                group.querySelectorAll('.icon-picker-item').forEach(function (item) {
                    var match = !term || (item.dataset.search || '').indexOf(term) !== -1;
                    item.style.display = match ? '' : 'none';
                    if (match) { groupHasMatch = true; anyVisible = true; }
                });
                group.style.display = groupHasMatch ? '' : 'none';
            });
            if (emptyIc) emptyIc.style.display = anyVisible ? 'none' : 'block';
        }

        // Couleurs
        if (colorRow) colorRow.querySelectorAll('.icon-picker-color').forEach(function (sw) {
            sw.addEventListener('click', function () { chooseColor(sw.dataset.color); });
        });

        // Icônes
        modal.querySelectorAll('.icon-picker-item').forEach(function (item) {
            item.addEventListener('click', function () { chooseIcon(item); });
        });

        // Recherche
        if (search) search.addEventListener('input', function () { filterIcons(search.value); });

        // Fermeture
        if (closeEl) closeEl.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('modal-active')) closeModal();
        });
    })();
    </script>

</body>
</html>