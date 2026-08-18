<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-coherences.php — Outil « Vérification des mangas »
//
// Repère les anomalies de la Mangathèque (doublons, numéros manquants,
// mauvais tag « dernier tome », statut différent de MangaUpdates, prêts
// orphelins…) et propose une édition rapide de la série concernée.
//
// Périmètre : Mangathèque uniquement. L'Animethèque dispose de son propre
// outil dédié, « Vérification des animés ».
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Outils « Vérification des mangas » : actions POST ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tool_action'])) {
    $response = ['success' => false, 'message' => 'Action inconnue.'];

    switch ($_POST['tool_action']) {
        case 'check_coherence':
            $response = ['success' => true, 'issues' => check_collection_coherence($data)];
            break;

        case 'coherence_quick_edit':
            $response = coherence_quick_edit($data, $_POST);
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$tool_title    = 'Vérification des mangas';
$tool_subtitle = 'Repère les anomalies internes de vos séries et propose une correction rapide.';
require __DIR__ . '/_layout_head.php';
?>

        <div class="tools-section">
            <h2>Vérification des mangas</h2>
            <p>Vérification des incohérences internes de vos séries (doublons, numéros manquants, mauvais tag « dernier tome », prêts orphelins…). Cet outil exploite aussi le statut de publication MangaUpdates mis en cache.</p>
            <div class="tools-actions">
                <button id="reload-coherences-btn" class="button button-opt">Relancer l'analyse</button>
            </div>
            <div id="coherences-results">
                <!-- Résultats chargés dynamiquement -->
            </div>
        </div>

<?php
$tm_coherence_edit = true;
require __DIR__ . '/_tools-modals.php';

// Données de la collection utilisées par l'outil « Vérification des mangas »
// (édition rapide d'une série sans rechargement de la page). Mangathèque
// uniquement : la Mangathèque est le seul périmètre de cet outil, voir
// l'en-tête de ce fichier.
$series_with_status = array_map(function ($series) {
    $status = $series['status'] ?? 'en cours';
    if (empty($series['status'])) {
        foreach ($series['volumes'] ?? [] as $volume) {
            if (!empty($volume['last'])) {
                $status = 'terminée';
                break;
            }
        }
    }
    $series['status'] = $status;
    return $series;
}, array_values(series_of_type($data, 'manga')));
?>
<script>
    window.seriesData = <?= json_encode($series_with_status) ?>;
</script>
<?php
$tool_scripts = ['coherence.js'];
require __DIR__ . '/_layout_foot.php';
