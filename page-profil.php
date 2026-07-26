<?php
// ────────────────────────────────────────────────────────────────────────────
// page-profil.php — Profil de l'administrateur
//
// Permet à l'admin d'éditer son profil : photo de profil, pseudo, biographie
// (Markdown, même moteur de rendu que les critiques) et liens sociaux (même
// système d'icônes/couleurs que les liens personnalisés du menu latéral).
//
// Le profil est stocké dans la table « options » :
//   admin_avatar        → chemin de l'image de profil (dans uploads/)
//   admin_pseudo        → pseudo (déplacé depuis la page Options)
//   admin_bio           → biographie en Markdown brut
//   admin_social_links  → JSON des liens sociaux [{name,url,icon,color}, …]
// ────────────────────────────────────────────────────────────────────────────

require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require 'includes/themes.php';
require 'fonctions/series.php';
require 'fonctions/options.php';
require 'fonctions/reviews.php';       // pour review_render_markdown() (aperçu bio)
require 'includes/custom_icons.php';   // icônes + couleurs (liens sociaux)

$options = load_options();

// ── Aperçu Markdown de la biographie (AJAX, rendu serveur = rendu public) ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profil_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false];

    if ($_POST['profil_action'] === 'preview') {
        $content  = $_POST['content'] ?? '';
        $response = ['success' => true, 'html' => review_render_markdown($content)];
    }

    echo json_encode($response);
    exit;
}

// ============================================================================
// TRAITEMENT : enregistrement du profil
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profil'])) {

    // ── Pseudo ────────────────────────────────────────────────────────────────
    $options['admin_pseudo'] = trim($_POST['admin_pseudo'] ?? '');

    // ── Biographie (Markdown brut, borné à 64 Ko comme les critiques) ─────────
    $bio = str_replace(["\r\n", "\r"], "\n", $_POST['admin_bio'] ?? '');
    if (strlen($bio) > 65536) {
        $bio = substr($bio, 0, 65536);
    }
    $options['admin_bio'] = $bio;

    // ── Photo de profil ───────────────────────────────────────────────────────
    // Suppression demandée : on efface le fichier et on vide l'option.
    if (!empty($_POST['remove_avatar'])) {
        $old = trim($options['admin_avatar'] ?? '');
        if ($old !== '' && strpos($old, UPLOAD_DIR) === 0 && file_exists($old)) {
            @unlink($old);
        }
        $options['admin_avatar'] = '';
    }
    // Nouvel envoi : upload_image() valide type/taille et déplace dans uploads/.
    if (!empty($_FILES['admin_avatar']['name'])) {
        $err = null;
        $uploaded = upload_image($_FILES['admin_avatar'], $err);
        if ($uploaded === false) {
            $_SESSION['error_message'] = $err ?: "Impossible de téléverser la photo de profil.";
        } else {
            // Supprime l'ancienne photo pour ne pas laisser d'orphelins.
            $old = trim($options['admin_avatar'] ?? '');
            if ($old !== '' && $old !== $uploaded && strpos($old, UPLOAD_DIR) === 0 && file_exists($old)) {
                @unlink($old);
            }
            $options['admin_avatar'] = $uploaded;
        }
    }

    // ── Liens sociaux (même mécanisme que les liens personnalisés) ────────────
    // Tableaux parallèles POST :
    //   social_link_name[], social_link_url[], social_link_icon[], social_link_color[]
    $allowed_icon_keys  = array_keys(custom_link_icons());
    $allowed_color_keys = array_keys(custom_link_colors());

    $names  = $_POST['social_link_name']  ?? [];
    $urls   = $_POST['social_link_url']   ?? [];
    $icons  = $_POST['social_link_icon']  ?? [];
    $colors = $_POST['social_link_color'] ?? [];
    if (!is_array($names))  $names  = [];
    if (!is_array($urls))   $urls   = [];
    if (!is_array($icons))  $icons  = [];
    if (!is_array($colors)) $colors = [];

    $social_links = [];
    $count = max(count($names), count($urls));
    for ($i = 0; $i < $count; $i++) {
        $name = trim((string)($names[$i] ?? ''));
        $url  = trim((string)($urls[$i]  ?? ''));
        if ($name === '' || $url === '') continue; // ligne incomplète ignorée
        $icon  = $icons[$i]  ?? 'link';
        $color = $colors[$i] ?? custom_link_default_color();
        $social_links[] = [
            'name'  => $name,
            'url'   => $url,
            'icon'  => in_array($icon, $allowed_icon_keys, true) ? $icon : 'link',
            'color' => in_array($color, $allowed_color_keys, true) ? $color : custom_link_default_color(),
        ];
    }
    $options['admin_social_links'] = json_encode($social_links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    save_options($options);

    if (!isset($_SESSION['error_message'])) {
        $_SESSION['success_message'] = 'Profil mis à jour avec succès';
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// ── Données pour l'affichage ──────────────────────────────────────────────────
$avatar        = trim($options['admin_avatar'] ?? '');
$pseudo        = trim($options['admin_pseudo'] ?? '');
$bio           = (string)($options['admin_bio'] ?? '');
$social_links  = profil_get_social_links($options);
$has_avatar    = ($avatar !== '' && file_exists($avatar));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil — <?= htmlspecialchars($options['site_name'] ?? 'Lengas') ?></title>
    <meta name="description" content="Profil de l'administrateur du site.">
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
        <div class="alert alert-success">
            <?= htmlspecialchars($_SESSION['success_message']) ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <main class="page-main">
        <div class="page-header">
            <h1>Profil</h1>
            <p class="page-subtitle">Présentez-vous à vos visiteurs : photo, pseudo, biographie et liens.</p>
        </div>

        <div class="options-page-form">
            <form id="profil-form" method="post" enctype="multipart/form-data">

                <!-- ══ PHOTO DE PROFIL ═══════════════════════════════════ -->
                <h3 class="options-section-title">Photo de profil</h3>

                <div class="profil-avatar-field">
                    <div class="profil-avatar-preview" id="profil-avatar-preview">
                        <img src="<?= $has_avatar ? htmlspecialchars($avatar) . '?v=' . filemtime($avatar) : 'assets/img/logo.png' ?>"
                             alt="Photo de profil" id="profil-avatar-img">
                    </div>
                    <div class="profil-avatar-controls">
                        <label for="admin_avatar" class="button button-ats profil-avatar-choose">Choisir une image…</label>
                        <input type="file" id="admin_avatar" name="admin_avatar" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
                        <p class="hint">JPG, PNG, GIF ou WebP (max. 5 Mo). Idéalement carrée.</p>
                        <?php if ($has_avatar): ?>
                            <label class="profil-avatar-remove-label">
                                <input type="checkbox" name="remove_avatar" value="1"> Supprimer la photo actuelle
                            </label>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ══ PSEUDO ════════════════════════════════════════════ -->
                <h3 class="options-section-title">Pseudo</h3>
                <label for="admin-pseudo">Votre pseudo</label>
                <input type="text" name="admin_pseudo" id="admin-pseudo" placeholder="Ex : Esenjin" value="<?= htmlspecialchars($pseudo) ?>">
                <p class="hint">Affiché sur votre profil public et utilisé pour créditer vos critiques auprès des visiteurs.</p>

                <!-- ══ BIOGRAPHIE ════════════════════════════════════════ -->
                <h3 class="options-section-title">Biographie</h3>
                <p class="hint">Rédigez votre présentation en Markdown : la mise en forme est identique à celle des critiques (gras, italique, titres, listes, citations, liens, images, médias…).</p>

                <div class="review-editor-area" id="bio-editor-area">
                    <!-- Barre d'outils Markdown (identique aux critiques) -->
                    <div class="review-toolbar" id="bio-toolbar">
                        <button type="button" class="rt-btn" data-md="undo" title="Annuler (Ctrl+Z)">↶</button>
                        <button type="button" class="rt-btn" data-md="redo" title="Rétablir (Ctrl+Y)">↷</button>
                        <span class="rt-sep"></span>
                        <button type="button" class="rt-btn" data-md="bold"   title="Gras (**texte**)"><strong>B</strong></button>
                        <button type="button" class="rt-btn" data-md="italic" title="Italique (*texte*)"><em>I</em></button>
                        <button type="button" class="rt-btn" data-md="underline" title="Souligné (++texte++)"><u>U</u></button>
                        <button type="button" class="rt-btn" data-md="strike" title="Barré (~~texte~~)"><s>S</s></button>
                        <span class="rt-sep"></span>
                        <button type="button" class="rt-btn" data-md="h1" title="Titre 1">H1</button>
                        <button type="button" class="rt-btn" data-md="h2" title="Titre 2">H2</button>
                        <button type="button" class="rt-btn" data-md="h3" title="Titre 3">H3</button>
                        <span class="rt-sep"></span>
                        <button type="button" class="rt-btn" data-md="ul" title="Liste à puces">• Liste</button>
                        <button type="button" class="rt-btn" data-md="ol" title="Liste numérotée">1. Liste</button>
                        <button type="button" class="rt-btn" data-md="quote" title="Citation">❝ Citation</button>
                        <button type="button" class="rt-btn" data-md="code" title="Code">&lt;/&gt;</button>
                        <span class="rt-sep"></span>
                        <button type="button" class="rt-btn" data-md="link"  title="Lien">🔗 Lien</button>
                        <button type="button" class="rt-btn" data-md="image" title="Image (lien direct)">🖼️ Image</button>
                        <button type="button" class="rt-btn" data-md="media" title="Média (YouTube, Vimeo, SoundCloud, fichier direct)">🎬 Média</button>
                        <span class="rt-sep"></span>
                        <button type="button" class="rt-btn mobile-only-inline" id="bio-preview-toggle">Aperçu</button>
                    </div>

                    <div class="review-split" id="bio-split">
                        <div class="review-pane review-pane--editor">
                            <textarea id="bio-content" name="admin_bio" class="review-textarea"
                                      placeholder="Écrivez votre biographie en Markdown…"
                                      spellcheck="true"><?= htmlspecialchars($bio) ?></textarea>
                        </div>
                        <div class="review-pane review-pane--preview">
                            <div id="bio-preview" class="review-rendered review-preview-body">
                                <p class="review-preview-placeholder">L'aperçu s'affichera ici.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══ LIENS SOCIAUX ═════════════════════════════════════ -->
                <h3 class="options-section-title">Liens sociaux</h3>
                <p class="hint">Ajoutez autant de liens que souhaité (réseaux sociaux, site perso, Discord…). Choisissez une icône et une couleur pour chacun ; un lien sans nom ou sans URL est ignoré. Ils apparaissent sur votre profil public.</p>

                <?php
                // Fragment de rendu d'une carte de lien social.
                // Identique au rendu des liens personnalisés (options), préfixes « social_link_ ».
                $render_social_link = function (array $link, bool $tpl = false): void {
                    $name  = $tpl ? '' : htmlspecialchars($link['name']);
                    $url   = $tpl ? '' : htmlspecialchars($link['url']);
                    $icon  = $tpl ? 'link' : ($link['icon'] ?? 'link');
                    $color = $tpl ? custom_link_default_color() : ($link['color'] ?? custom_link_default_color());
                    $icon_hex = custom_link_color_hex($color);
                    $icon_url = 'https://api.iconify.design/' . str_replace(':', '/', custom_link_icon_name($icon)) . '.svg?color=' . rawurlencode($icon_hex);
                    $n = function (string $field) use ($tpl): string {
                        return $tpl ? '' : ' name="social_link_' . $field . '[]"';
                    };
                    ?>
                    <div class="custom-link-card" data-custom-link<?= $tpl ? ' data-template' : '' ?>>
                        <div class="custom-link-card-head">
                            <span class="custom-link-card-title">Lien social</span>
                            <button type="button" class="custom-link-remove" title="Supprimer ce lien" aria-label="Supprimer ce lien">&times;</button>
                        </div>

                        <label>Nom du lien</label>
                        <input type="text"<?= $n('name') ?> class="cl-name" placeholder="Ex : Mastodon" value="<?= $name ?>">

                        <label>URL du lien</label>
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
                ?>

                <div id="social-links-list">
                    <?php foreach ($social_links as $__link) $render_social_link($__link, false); ?>
                </div>

                <p id="social-links-empty" class="hint" style="<?= empty($social_links) ? '' : 'display:none;' ?>">
                    Aucun lien pour le moment. Cliquez sur « Ajouter un lien social » pour commencer.
                </p>

                <button type="button" id="add-social-link-btn" class="button button-ats">＋ Ajouter un lien social</button>

                <!-- Gabarit d'un nouveau lien social (cloné par le JS) -->
                <template id="social-link-template">
                    <?php $render_social_link(['name' => '', 'url' => '', 'icon' => 'link', 'color' => custom_link_default_color()], true); ?>
                </template>

                <button type="submit" name="update_profil" class="button button-opt">Enregistrer le profil</button>
            </form>
        </div>
    </main>

    <!-- ── Modale de sélection d'icône + couleur (liens sociaux) ───────────── -->
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

    <!-- Petit prompt média (pour insérer lien/image/média dans la bio) -->
    <div class="modal" id="review-insert-modal">
        <div class="modal-content modal-content--sm">
            <span class="close-modal" id="review-insert-close">&times;</span>
            <h2 id="review-insert-title">Insérer</h2>
            <label for="review-insert-url" id="review-insert-url-label">URL</label>
            <input type="url" id="review-insert-url" placeholder="https://…" autocomplete="off">
            <div id="review-insert-text-wrap">
                <label for="review-insert-text">Texte affiché (facultatif)</label>
                <input type="text" id="review-insert-text" placeholder="Texte du lien" autocomplete="off">
            </div>
            <p class="hint" id="review-insert-hint"></p>
            <p class="review-insert-error" id="review-insert-error" style="display:none;"></p>
            <div class="modal-actions">
                <button id="review-insert-ok" class="button button-ats">Insérer</button>
                <button id="review-insert-cancel" class="button button-ext">Annuler</button>
            </div>
        </div>
    </div>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <script>
        window.profilColors       = <?= json_encode(custom_link_colors(), JSON_UNESCAPED_SLASHES) ?>;
        window.profilDefaultColor = <?= json_encode(custom_link_default_color()) ?>;
    </script>
    <script src="assets/js/admin/main.js"></script>
    <script src="assets/js/admin/profil.js"></script>

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

</body>
</html>
