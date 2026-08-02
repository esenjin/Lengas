<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-anilist-sync.php — Outil « Synchronisation via Anilist »
//
// Déclenche la synchronisation automatique des séries animées éligibles
// (diffusion et visionnage tous deux « en cours »), avec un bouton de
// forçage qui ignore le verrou de 24 h. C'est la même synchronisation que
// celle qui se déclenche automatiquement en arrière-plan à l'affichage de
// l'Animethèque.
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Endpoint SSE : synchronisation Anilist en flux ────────────────────────────
// $_GET['force'] = '1' → bouton de forçage : synchronise TOUTES les séries
// animées éligibles, verrous de 24h ignorés (sur le modèle du bouton « Forcer
// la recherche (non analysées) » de l'outil MangaUpdates).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'anilist_sync_stream') {
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
    @set_time_limit(0);
    while (ob_get_level()) ob_end_flush();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $sse = function (string $event, array $payload): void {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload) . "\n\n";
        flush();
    };

    $force = isset($_GET['force']) && $_GET['force'] === '1';

    // Périmètre : toutes les séries éligibles si $force (le bouton de forçage
    // ignore les verrous), seulement celles dont le verrou est écoulé sinon.
    // Pas de plafond « par visite » ici, contrairement à l'endpoint AJAX
    // d'admin.php : cet outil est une action explicite de l'administrateur,
    // pas un déclenchement automatique à l'affichage d'une page.
    $series_ids = $force
        ? anilist_sync_eligible_series_ids($data)
        : anilist_sync_due_series_ids($data);

    if (empty($series_ids)) {
        $sse('done', [
            'success'   => true,
            'synced'    => [], 'unchanged' => [], 'skipped' => [], 'errors' => [],
            'processed' => 0,
            'message'   => $force
                ? "Aucune série animée éligible (diffusion et visionnage « en cours »)."
                : "Aucune série à synchroniser pour le moment (verrous de 24h non écoulés).",
        ]);
        exit;
    }

    $result = anilist_sync_run_batch(
        $series_ids,
        count($series_ids), // pas de plafond de visite dans cet outil : action explicite
        $force,
        function ($current, $total, $title) use ($sse) {
            $sse('progress', ['current' => $current, 'total' => $total, 'title' => $title]);
        }
    );

    $sse('done', array_merge(['success' => true], $result));
    exit;
}

$tool_title    = 'Synchronisation via Anilist';
$tool_subtitle = 'Nouveaux épisodes et statut de diffusion des séries animées en cours.';
require __DIR__ . '/_layout_head.php';
?>

        <div class="tools-section">
            <h2>Synchronisation via Anilist</h2>
            <p>Tient à jour les séries animées dont la diffusion <strong>et</strong> le visionnage sont tous les deux « en cours » : nouveaux épisodes diffusés et statut de diffusion. C'est la même synchronisation qui se déclenche automatiquement, en arrière-plan, à l'affichage de l'Animethèque — cet outil permet de la lancer à la demande, ou de la forcer en ignorant le verrou habituel.</p>
            <p class="hint">⚠️ Limitations : seuls les épisodes et le statut de diffusion sont concernés. Les studios, genres, format, titres alternatifs ou la vignette Anilist ne sont vérifiés que par l'outil de revérification (« Vérification des animés »), qui demande une validation avant toute écriture. Les personnalisations (titre choisi, vignette personnelle, note, coches, éditions physiques) ne sont jamais affectées, ici comme ailleurs.</p>
            <p class="hint">Un verrou de 24 h protège chaque série contre des vérifications trop rapprochées ; en cas d'échec de l'API pour une série, ce délai est ramené à 1 h avant une nouvelle tentative.</p>

            <div class="tools-actions">
                <button id="anilist-sync-launch" class="button">Synchroniser les séries éligibles</button>
                <button id="anilist-sync-launch-force" class="button button-opt" title="Synchronise toutes les séries animées éligibles (diffusion et visionnage « en cours »), en ignorant le verrou de 24 h">Forcer toutes les séries éligibles</button>
            </div>

            <div id="anilist-sync-progress"></div>
            <div id="anilist-sync-results"></div>
        </div>

<?php
require __DIR__ . '/_tools-modals.php';

$tool_scripts = ['anilist-sync.js'];
require __DIR__ . '/_layout_foot.php';
