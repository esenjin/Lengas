<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-cache.php — Outil « Vider le cache »
//
// Force le renouvellement du cache navigateur sur les fichiers CSS/JS du
// site, pour que les mises à jour visuelles s'affichent correctement sans
// avoir à faire un rechargement forcé (Ctrl+F5). Ne touche jamais à la
// session admin ni aux données de la collection — voir
// fonctions/tools/cache.php pour le détail.
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

// ============================================================================
// ENDPOINTS
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tool_action'])) {
    $response = ['success' => false, 'message' => 'Action inconnue.'];

    switch ($_POST['tool_action']) {
        case 'clear_cache':
            $response = clear_site_cache();
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$tool_title    = 'Vider le cache';
$tool_subtitle = "Force l'affichage des derniers styles et scripts du site, sans rien perdre de votre session ni de vos données.";
require __DIR__ . '/_layout_head.php';
?>

        <div class="tools-section">
            <h2>Vider le cache</h2>
            <p>
                Après une mise à jour du site, votre navigateur peut continuer d'afficher
                d'anciens styles ou scripts tant qu'il n'a pas eux-mêmes détecté le changement —
                ce qui oblige parfois à faire un rechargement forcé (Ctrl+F5) pour tout réafficher
                correctement. Cet outil force ce renouvellement pour tous les visiteurs du site,
                sans toucher à votre connexion ni à vos données : seuls les fichiers d'apparence
                et de comportement (CSS/JS) sont concernés.
            </p>
            <div class="tools-actions">
                <button id="clear-cache-btn" class="button button-opt">
                    <span id="clear-cache-text">Vider le cache</span>
                    <span id="clear-cache-spinner" class="spinner" style="display: none;"></span>
                </button>
            </div>
            <div id="clear-cache-result"></div>
        </div>

<?php
$tool_scripts = ['cache.js'];
require __DIR__ . '/_layout_foot.php';
