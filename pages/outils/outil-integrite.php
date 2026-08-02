<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-integrite.php — Outil « Vérification d'intégrité »
//
// Compare l'instance au dépôt Gitea (au tag correspondant à la version
// installée), vérifie la structure de la base de données, les modules
// facultatifs (Vestikan, Babengas), la connectivité Anilist, les permissions,
// les fichiers interdits, les doublons et les images orphelines. Propose
// aussi les nettoyages associés (doublons, images orphelines, fichiers
// interdits).
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Actions POST (vérification + nettoyages proposés) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tool_action'])) {
    $response = ['success' => false, 'message' => 'Action inconnue.'];

    switch ($_POST['tool_action']) {
        case 'check_integrity':
            $response = ['success' => true, 'results' => check_site_integrity($data)];
            break;

        case 'clean_duplicates':
            $response = clean_duplicates();
            break;

        case 'clean_orphaned_images':
            $response = clean_orphaned_images();
            break;

        case 'clean_forbidden_files':
            $response = clean_forbidden_files();
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$tool_title    = "Vérification d'intégrité";
$tool_subtitle = 'Compare votre instance au dépôt et vérifie la structure de vos données.';
require __DIR__ . '/_layout_head.php';
?>

        <div class="tools-section">
            <h2>Vérification d'intégrité</h2>
            <p>Vérifie l'intégrité de votre site et de vos données (fichiers, permissions, structure de la base, thèmes personnalisés, fichiers Vestikan, API MangaUpdates, API Anilist…).</p>
            <button id="check-integrity-btn" class="button button-oas">
                <span id="check-integrity-text">Vérifier l'intégrité</span>
                <span id="check-integrity-spinner" class="spinner" style="display: none;"></span>
            </button>
            <div id="integrity-results-container"></div>
        </div>

<?php
require __DIR__ . '/_tools-modals.php';

$tool_scripts = ['integrity.js'];
require __DIR__ . '/_layout_foot.php';
