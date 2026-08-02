<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-anilist-recheck.php — Outil « Vérification des animés »
//
// Compare chaque série animée à sa fiche Anilist actuelle sur tout ce que la
// synchronisation automatique ne couvre pas (titres alternatifs, studios,
// format, genres, vignette…), avec validation explicite avant toute
// correction.
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Endpoint SSE : outil de revérification des animés ────────────────────────
// Compare, pour chaque série animée avec identifiant Anilist, tous les champs
// factuels NON couverts par la synchronisation automatique (studios, format,
// genres, statut de diffusion, nombre d'épisodes annoncé, vignette, titres
// alternatifs) à la fiche Anilist actuelle. Aucune écriture ici : seul le
// rapport est construit, l'écriture attend une validation explicite (endpoint
// POST ci-dessous).
//
// $_GET['force'] = '1' → ignore le cache 24h du connecteur (bouton « Ignorer
// le cache et tout revérifier »).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'anilist_recheck_stream') {
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

    $targets = anilist_recheck_targets($data);
    if (empty($targets)) {
        $sse('done', [
            'success'         => true,
            'report'          => [],
            'unchanged_count' => 0,
            'missing'         => [],
            'errors'          => [],
            'checked'         => 0,
            'message'         => "Aucune série animée avec un identifiant Anilist à vérifier.",
        ]);
        exit;
    }

    $result = anilist_recheck_build_report(
        $data,
        $force,
        function ($current, $total, $title) use ($sse) {
            $sse('progress', ['current' => $current, 'total' => $total, 'title' => $title]);
        }
    );

    $sse('done', $result);
    exit;
}

// ── Endpoint : application des corrections validées ───────────────────────────
// Format attendu ($_POST['selections'], JSON) :
//   { "<series_id>": { "fields": ["studios","genres",…], "accept_new_titles": bool }, … }
// Une série absente de $selections, ou envoyée avec un tableau `fields` vide
// et `accept_new_titles` faux, n'est pas touchée : c'est le principe même de
// la validation ligne par ligne de cet outil.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tool_action'] ?? '') === 'anilist_recheck_apply') {
    $selections = json_decode($_POST['selections'] ?? '{}', true);
    if (!is_array($selections)) $selections = [];

    if (empty($selections)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'applied' => [], 'errors' => [], 'message' => "Aucune modification sélectionnée."]);
        exit;
    }

    $response = anilist_recheck_apply_batch($selections);
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$tool_title    = 'Vérification des animés';
$tool_subtitle = 'Compare vos fiches animées aux données actuelles d\'Anilist.';
require __DIR__ . '/_layout_head.php';
?>

<?php if (!empty(series_of_type($data, 'anime'))): ?>
        <div class="tools-section">
            <h2>Vérification des animés</h2>
            <p>Compare chaque série animée à sa fiche Anilist actuelle : titres alternatifs, studios, format, genres, statut de diffusion, nombre d'épisodes annoncé et vignette. Rien n'est jamais écrit automatiquement : chaque écart détecté attend votre validation, série par série.</p>
            <p class="hint">⚠️ Périmètre disjoint de la synchronisation (outil « Synchronisation via Anilist ») : celle-ci tient déjà à jour les épisodes et le statut de diffusion des séries en cours. Cet outil couvre tout le reste, y compris pour les séries dont la diffusion est terminée, en pause ou abandonnée. Le nombre d'épisodes annoncé n'est ici que signalé : la liste des épisodes elle-même se met à jour via la synchronisation.</p>
            <p class="hint">Ne sont jamais proposés à l'écrasement : le titre choisi, la vignette personnalisée, la note, les coches (mature, favori, visionnage abandonné) et les éditions physiques.</p>

            <div class="tools-actions">
                <button id="anilist-recheck-launch" class="button">Vérifier les séries animées</button>
                <button id="anilist-recheck-launch-force" class="button button-opt" title="Ignore le cache de 24h du connecteur Anilist et relit chaque fiche">Ignorer le cache et tout revérifier</button>
            </div>

            <div id="anilist-recheck-progress"></div>
            <div id="anilist-recheck-results"></div>

            <div id="anilist-recheck-apply-row" class="tools-actions anilist-recheck-apply-row" style="display:none;">
                <button type="button" id="anilist-recheck-apply-btn" class="button button-ats">
                    <span id="anilist-recheck-apply-text">Appliquer les modifications sélectionnées</span>
                    <span id="anilist-recheck-apply-spinner" class="spinner" style="display:none;"></span>
                </button>
            </div>
            <div id="anilist-recheck-apply-results"></div>
        </div>
<?php else: ?>
        <div class="tools-section">
            <h2>Vérification des animés</h2>
            <p>Cet outil n'a d'utilité que pour l'Animethèque : votre collection ne contient actuellement aucun animé.</p>
        </div>
<?php endif; ?>

<?php
require __DIR__ . '/_tools-modals.php';

$tool_scripts = ['anilist-recheck.js'];
require __DIR__ . '/_layout_foot.php';
