<?php
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
require 'fonctions/tools.php'; // pour get_latest_version_from_gitea()
require 'includes/custom_icons.php';
require 'includes/themes.php';
require_once 'includes/vestikan.php';

$data    = load_data();
$options = load_options();

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
    $options['private_mode'] = !empty($_POST['private_mode']);
    $options['hide_mature'] = !empty($_POST['hide_mature']);
    $options['hide_reviews'] = !empty($_POST['hide_reviews']);

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
    $options['admin_pseudo'] = trim($_POST['admin_pseudo'] ?? '');
    $options['custom_button_name'] = trim($_POST['custom_button_name'] ?? '');
    $options['custom_button_url'] = trim($_POST['custom_button_url'] ?? '');
    $options['custom_button_name2'] = trim($_POST['custom_button_name2'] ?? '');
    $options['custom_button_url2'] = trim($_POST['custom_button_url2'] ?? '');
    $options['custom_button_name3']   = trim($_POST['custom_button_name3'] ?? '');
    $options['custom_button_url3']    = trim($_POST['custom_button_url3'] ?? '');

    // ── Icônes des liens personnalisés (validées contre le jeu autorisé) ──
    require_once 'includes/custom_icons.php';
    $allowed_icon_keys = array_keys(custom_link_icons());
    foreach (['', '2', '3'] as $suffix) {
        $key = $_POST["custom_button_icon$suffix"] ?? 'link';
        $options["custom_button_icon$suffix"] = in_array($key, $allowed_icon_keys, true) ? $key : 'link';
    }

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
            $logo_path = __DIR__ . '/assets/img/logo.png';

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
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
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

                <label for="admin-pseudo">Pseudo de l'admin</label>
                <input type="text" name="admin_pseudo" id="admin-pseudo" placeholder="Ex : Esenjin" value="<?= htmlspecialchars($options['admin_pseudo'] ?? '') ?>">
                <p class="hint">Utilisé pour créditer les critiques auprès des visiteurs.</p>

                <h3 class="options-section-title">Liens personnalisés</h3>
                <p class="hint">Ces liens apparaissent dans le menu latéral des pages publiques (accueil et statistiques). Choisissez une icône pour chacun.</p>

                <?php
                for ($__i = 1; $__i <= 3; $__i++):
                    $__s        = $__i === 1 ? '' : $__i;
                    $__name_val = htmlspecialchars($options["custom_button_name$__s"] ?? '');
                    $__url_val  = htmlspecialchars($options["custom_button_url$__s"]  ?? '');
                    $__icon_val = $options["custom_button_icon$__s"] ?? 'link';
                ?>
                    <label for="custom-button-name<?= $__s ?>">Nom du bouton personnalisé (<?= $__i ?>)</label>
                    <input type="text" name="custom_button_name<?= $__s ?>" id="custom-button-name<?= $__s ?>" placeholder="Nom du bouton" value="<?= $__name_val ?>">

                    <label for="custom-button-url<?= $__s ?>">URL du bouton personnalisé (<?= $__i ?>)</label>
                    <input type="text" name="custom_button_url<?= $__s ?>" id="custom-button-url<?= $__s ?>" placeholder="URL du bouton" value="<?= $__url_val ?>">

                    <label>Icône du bouton (<?= $__i ?>)</label>
                    <div class="custom-icon-field">
                        <input type="hidden" name="custom_button_icon<?= $__s ?>"
                               id="custom-button-icon<?= $__s ?>" value="<?= htmlspecialchars($__icon_val) ?>">
                        <button type="button"
                                class="custom-icon-trigger"
                                data-target="custom-button-icon<?= $__s ?>"
                                data-preview="custom-icon-preview<?= $__s ?>"
                                data-label="custom-icon-label<?= $__s ?>">
                            <img class="custom-icon-preview" id="custom-icon-preview<?= $__s ?>"
                                 src="https://api.iconify.design/<?= str_replace(':', '/', custom_link_icon_name($__icon_val)) ?>.svg?color=%234ade80"
                                 width="22" height="22" alt="">
                            <span class="custom-icon-label" id="custom-icon-label<?= $__s ?>"><?= htmlspecialchars(custom_link_icon_label($__icon_val)) ?></span>
                            <span class="custom-icon-caret">▾</span>
                        </button>
                    </div>
                    <p class="hint">Laisser le nom ou l'URL vide pour masquer le bouton.</p>
                <?php endfor; ?>

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

                <!-- ══ VIGNETTE ══════════════════════════════════════════ -->
                <h3 class="options-section-title">Vignette</h3>

                <div class="form-group">
                    <label for="default_logo">Remplacer la vignette par défaut :</label>
                    <input type="file" id="default_logo" name="default_logo" accept="image/png">
                    <p class="hint">L'image téléversée remplacera le fichier logo.png actuel (PNG obligatoire).</p>
                    <p class="hint">Vignette par défaut actuelle :</p>
                    <?php if (file_exists('assets/img/logo.png')): ?>
                        <div>
                            <img src="assets/img/logo.png?v=<?= time() ?>" alt="Logo actuel" style="max-width: 100px; max-height: 100px;">
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
                <h3 class="options-section-title">Visibilité</h3>

                <label>
                    <input type="checkbox" name="private_mode" <?= $options['private_mode'] ? 'checked' : '' ?>> Mode privé
                </label>
                <p class="hint">Votre bibliothèque ne sera pas visible publiquement.</p>

                <label>
                    <input type="checkbox" name="hide_mature" <?= $options['hide_mature'] ? 'checked' : '' ?>> Masquer les séries matures
                </label>
                <p class="hint">Vos séries matures ne seront pas visibles au public.</p>

                <label>
                    <input type="checkbox" name="hide_reviews" <?= !empty($options['hide_reviews']) ? 'checked' : '' ?>> Cacher les critiques
                </label>
                <p class="hint">Vos critiques ne seront pas visibles au public.</p>

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
                    <p class="hint"><span class="warn">●</span> Connexion via Vestikan : <strong>inactive</strong>. Déposez les fichiers Vestikan et <code>includes/vestikan-config.php</code> pour l'activer.</p>
                <?php endif; ?>

                <button type="submit" name="update_options" class="button button-opt">Mettre à jour</button>
            </form>
        </div>
    </main>

    <!-- ── Modale de sélection d'icône (liens personnalisés) ───────────────── -->
    <div class="modal" id="icon-picker-modal">
        <div class="modal-content modal-content--narrow">
            <span class="close-modal" id="close-icon-picker-modal">&times;</span>
            <h2>Choisir une icône</h2>
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
    // ── Sélecteur visuel d'icône pour les liens personnalisés ────────────────
    (function () {
        var modal   = document.getElementById('icon-picker-modal');
        var closeEl = document.getElementById('close-icon-picker-modal');
        var search  = document.getElementById('icon-picker-search');
        var emptyEl = document.getElementById('icon-picker-empty');
        if (!modal) return;

        // Champ (hidden) et aperçus visés par le bouton actuellement ouvert
        var active = { input: null, preview: null, label: null };

        function openModal(trigger) {
            active.input   = document.getElementById(trigger.dataset.target);
            active.preview = document.getElementById(trigger.dataset.preview);
            active.label   = document.getElementById(trigger.dataset.label);

            // Réinitialise la recherche et marque l'icône courante
            search.value = '';
            filterIcons('');
            var current = active.input ? active.input.value : '';
            modal.querySelectorAll('.icon-picker-item').forEach(function (item) {
                item.classList.toggle('is-selected', item.dataset.key === current);
            });

            modal.classList.add('modal-active');
            setTimeout(function () { search.focus(); }, 50);
        }

        function closeModal() {
            modal.classList.remove('modal-active');
            active = { input: null, preview: null, label: null };
        }

        function chooseIcon(item) {
            if (!active.input) { closeModal(); return; }
            var key  = item.dataset.key;
            var lbl  = item.dataset.label;
            var img  = item.querySelector('img');
            active.input.value = key;
            if (active.label)   active.label.textContent = lbl;
            if (active.preview && img) {
                // Réutilise l'URL de la vignette, en repassant à la couleur verte de l'aperçu
                active.preview.src = img.getAttribute('src').replace('color=%23d4d4e8', 'color=%234ade80');
            }
            closeModal();
        }

        function filterIcons(term) {
            term = (term || '').trim().toLowerCase();
            var anyVisible = false;
            // Filtre chaque item, puis masque les groupes devenus vides
            modal.querySelectorAll('[data-group]').forEach(function (group) {
                var groupHasMatch = false;
                group.querySelectorAll('.icon-picker-item').forEach(function (item) {
                    var match = !term || (item.dataset.search || '').indexOf(term) !== -1;
                    item.style.display = match ? '' : 'none';
                    if (match) { groupHasMatch = true; anyVisible = true; }
                });
                group.style.display = groupHasMatch ? '' : 'none';
            });
            if (emptyEl) emptyEl.style.display = anyVisible ? 'none' : 'block';
        }

        // Ouverture depuis chaque bouton d'aperçu
        document.querySelectorAll('.custom-icon-trigger').forEach(function (btn) {
            btn.addEventListener('click', function () { openModal(btn); });
        });

        // Sélection d'une icône
        modal.querySelectorAll('.icon-picker-item').forEach(function (item) {
            item.addEventListener('click', function () { chooseIcon(item); });
        });

        // Recherche
        if (search) search.addEventListener('input', function () { filterIcons(search.value); });

        // Fermeture (croix, clic extérieur, Échap)
        if (closeEl) closeEl.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('modal-active')) closeModal();
        });
    })();
    </script>

</body>
</html>