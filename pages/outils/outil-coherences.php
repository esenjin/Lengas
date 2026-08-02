<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-coherences.php — Outil « Incohérences »
//
// Repère les anomalies de la collection (doublons, numéros manquants, mauvais
// tag « dernier tome »/« dernier épisode », statut différent de MangaUpdates
// ou d'Anilist, prêts orphelins, série animée sans identifiant Anilist,
// épisode terminé sans date, vignette Anilist introuvable, etc.) et propose
// une édition rapide de la série concernée.
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Outils « Incohérences » : actions POST ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tool_action'])) {
    $response = ['success' => false, 'message' => 'Action inconnue.'];

    switch ($_POST['tool_action']) {
        case 'check_coherence':
            $response = ['success' => true, 'issues' => check_collection_coherence($data)];
            break;

        case 'coherence_quick_edit':
            $response = coherence_quick_edit($data, $_POST);
            break;

        case 'coherence_quick_edit_anime':
            $response = coherence_quick_edit_anime($data, $_POST);
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$tool_title    = 'Incohérences de la collection';
$tool_subtitle = 'Repère les anomalies internes de vos séries et propose une correction rapide.';
require __DIR__ . '/_layout_head.php';
?>

        <div class="tools-section">
            <h2>Incohérences de la collection</h2>
            <p>Vérification des incohérences internes de vos séries (doublons, numéros manquants, mauvais tag « dernier tome », prêts orphelins…). Cet outil exploite aussi le statut de publication MangaUpdates mis en cache.</p>
            <?php if (!empty(series_of_type($data, 'anime'))): ?>
            <p class="hint">Couvre aussi l'Animethèque : épisodes manquants ou en double, mauvais tag « dernier épisode », épisode terminé sans date, vignette Anilist introuvable, série sans identifiant Anilist. Les anomalies qui viennent d'Anilist (statut de diffusion, fiche incomplète…) ne se corrigent pas ici : le rapport renvoie vers la fiche Anilist. Seules celles qui sont purement locales (statut et date de visionnage) proposent un bouton « Corriger ».</p>
            <?php endif; ?>
            <div class="tools-actions">
                <button id="reload-coherences-btn" class="button button-opt">Relancer l'analyse</button>
            </div>
            <div id="coherences-results">
                <!-- Résultats chargés dynamiquement -->
            </div>
        </div>

<?php
$tm_coherence_edit       = true;
$tm_anime_coherence_edit = true;
require __DIR__ . '/_tools-modals.php';

// Données de la collection utilisées par l'outil « Incohérences »
// (édition rapide d'une série sans rechargement de la page).
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
}, array_values($data));
?>
<script>
    window.seriesData  = <?= json_encode($series_with_status) ?>;
    window.seriesTypes = <?= json_encode(series_types_for_js()) ?>;
</script>
<?php
$tool_scripts = ['coherence.js'];
require __DIR__ . '/_layout_foot.php';
