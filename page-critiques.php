<?php
require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require 'fonctions/series.php';
require 'fonctions/options.php';
require 'fonctions/reviews.php';

$data    = load_data();
$options = load_options();

// ── Actions AJAX ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false];

    switch ($_POST['review_action']) {

        case 'list':
            $response = ['success' => true, 'reviews' => list_reviews($data)];
            break;

        case 'get':
            $series_id = $_POST['series_id'] ?? '';
            $review    = get_review($series_id);
            // État de lecture pour les alertes de l'éditeur
            $reading_state = 'none';
            foreach ($data as $s) {
                if ($s['id'] === $series_id) { $reading_state = review_reading_state($s); break; }
            }
            $response = [
                'success'       => true,
                'content'       => $review['content'] ?? '',
                'updated_at'    => $review['updated_at'] ?? '',
                'reading_state' => $reading_state,
            ];
            break;

        case 'preview':
            // Aperçu serveur (HTML sûr) — utilisé si on veut un rendu identique
            // au public. L'éditeur utilise un aperçu JS, ceci reste dispo.
            $content  = $_POST['content'] ?? '';
            $response = ['success' => true, 'html' => review_render_markdown($content)];
            break;

        case 'save':
            $series_id = $_POST['series_id'] ?? '';
            $content   = $_POST['content'] ?? '';
            $response  = save_review($data, $series_id, $content);
            break;

        case 'delete':
            $series_id = $_POST['series_id'] ?? '';
            $response  = delete_review($series_id);
            break;

        case 'reading_state':
            $series_id     = $_POST['series_id'] ?? '';
            $reading_state = 'none';
            foreach ($data as $s) {
                if ($s['id'] === $series_id) { $reading_state = review_reading_state($s); break; }
            }
            $response = ['success' => true, 'reading_state' => $reading_state];
            break;
    }

    echo json_encode($response);
    exit;
}

// Série pré-sélectionnée via l'URL (?series_id=…) depuis la carte "Critique".
$prefill_series_id = $_GET['series_id'] ?? '';

// Liste des séries éligibles : collection + lues ailleurs (toutes les séries
// présentes dans $data conviennent, y compris read_elsewhere).
$eligible = array_map(function ($s) {
    return ['id' => $s['id'], 'name' => $s['name'], 'author' => $s['author']];
}, $data);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Critiques — <?= htmlspecialchars($options['site_name'] ?? 'Lengas') ?></title>
    <meta name="description" content="Gestion des critiques de séries.">
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body class="with-sidebar">

    <?php include 'includes/sidebar.php'; ?>

    <main class="page-main">
        <div class="page-header">
            <h1>Critiques</h1>
            <p class="page-subtitle">Rédigez vos avis sur vos séries, visibles par vos visiteurs.</p>
        </div>

        <!-- ══ VUE LISTE ══════════════════════════════════════════════════════ -->
        <section id="reviews-list-view" class="reviews-view">
            <div class="reviews-list-toolbar">
                <input type="text" id="reviews-search" class="reviews-search-input"
                       placeholder="Filtrer par titre ou auteur…" autocomplete="off">
                <button type="button" id="new-review-btn" class="button button-aos">
                    <img src="https://api.iconify.design/mdi/pencil-plus.svg?color=%23ffffff" width="18" height="18" alt="">
                    Nouvelle critique
                </button>
            </div>
            <div id="reviews-list" class="reviews-list-container">
                <p class="reviews-empty">Chargement…</p>
            </div>
        </section>

        <!-- ══ VUE ÉDITEUR ════════════════════════════════════════════════════ -->
        <section id="reviews-editor-view" class="reviews-view" style="display:none;">

            <div class="reviews-editor-topbar">
                <button type="button" id="review-back-btn" class="button button-ext">
                    <img src="https://api.iconify.design/mdi/arrow-left.svg?color=%23ffffff" width="18" height="18" alt="">
                    Retour
                </button>

                <div class="review-series-select-wrap">
                    <label for="review-series-search" class="sr-only">Série</label>
                    <input type="text" id="review-series-search" class="series-search"
                           placeholder="Choisir une série…" autocomplete="off">
                    <div class="series-results" id="review-series-results">
                        <?php foreach ($eligible as $s): ?>
                            <div data-id="<?= htmlspecialchars($s['id']) ?>">
                                <?= htmlspecialchars($s['name']) ?>
                                <span class="review-series-author"><?= htmlspecialchars($s['author']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="review-series-id">
                </div>

                <div class="reviews-editor-actions">
                    <button type="button" id="review-preview-toggle" class="button button-otl mobile-only-inline">
                        Aperçu
                    </button>
                    <button type="button" id="review-delete-btn" class="button delete-btn" style="display:none;">Supprimer</button>
                    <button type="button" id="review-save-btn" class="button button-ats">Enregistrer</button>
                </div>
            </div>

            <!-- Bandeau d'alerte lecture -->
            <div id="review-reading-alert" class="review-reading-alert" style="display:none;"></div>

            <!-- Zone éditeur : barre d'outils (collante) + split, avec défilement propre -->
            <div class="review-editor-area" id="review-editor-area">
            <!-- Barre d'outils Markdown -->
            <div class="review-toolbar" id="review-toolbar">
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
            </div>

            <!-- Zone édition + aperçu -->
            <div class="review-split" id="review-split">
                <div class="review-pane review-pane--editor" id="review-pane-editor">
                    <textarea id="review-content" class="review-textarea"
                              placeholder="Écrivez votre critique en Markdown…"
                              spellcheck="true"></textarea>
                </div>
                <div class="review-pane review-pane--preview" id="review-pane-preview">
                    <div id="review-preview" class="review-rendered review-preview-body">
                        <p class="review-preview-placeholder">L'aperçu s'affichera ici.</p>
                    </div>
                </div>
            </div>
            </div>
        </section>
    </main>

    <!-- Modales génériques (alerte / confirmation), réutilisées par main.js -->
    <div class="modal" id="custom-alert-modal">
        <div class="modal-content modal-content--sm">
            <h2 id="custom-alert-title">Information</h2>
            <p id="custom-alert-message"></p>
            <div class="modal-actions">
                <button id="custom-alert-ok" class="button">OK</button>
            </div>
        </div>
    </div>
    <div class="modal" id="custom-confirm-modal">
        <div class="modal-content modal-content--sm">
            <h2 id="custom-confirm-title">Confirmation</h2>
            <p id="custom-confirm-message"></p>
            <div class="modal-actions">
                <button id="custom-confirm-ok" class="button">OK</button>
                <button id="custom-confirm-cancel" class="button button-ext">Annuler</button>
            </div>
        </div>
    </div>

    <!-- Petit prompt média (pour insérer lien/image/média) -->
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
        window.reviewPrefillSeriesId = <?= json_encode($prefill_series_id) ?>;
    </script>
    <script src="assets/js/admin/main.js"></script>
    <script src="assets/js/admin/reviews.js"></script>
</body>
</html>
