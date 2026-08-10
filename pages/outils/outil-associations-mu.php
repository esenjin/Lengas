<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-associations-mu.php — Outil « Association MangaUpdates »
//
// Recherche automatique d'une fiche MangaUpdates pour chaque série sans URL
// (corrélation titre + auteur), avec progression en direct et validation
// avant enregistrement ; un second bloc récupère de la même façon les genres
// manquants.
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Endpoint SSE : association MangaUpdates avec progression ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'mu_associate_stream') {
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
    @set_time_limit(0);
    while (ob_get_level()) ob_end_flush();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $sse = function(string $event, array $payload): void {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload) . "\n\n";
        flush();
    };

    // Séries sans URL MangaUpdates, avec exclusion facultative d'une ou
    // plusieurs catégories Lengas (ex. « Light Novel », dont la publication
    // FR ne suit pas MangaUpdates) — même filtrage que mu_assoc_targets().
    $exclude_categories = $_GET['exclude_categories'] ?? [];
    if (!is_array($exclude_categories)) $exclude_categories = [];
    $exclude_categories = array_values(array_filter(array_map(fn($c) => trim((string)$c), $exclude_categories), fn($c) => $c !== ''));
    $targets = mu_assoc_targets($data, $exclude_categories);
    $total        = count($targets);
    $current      = 0;
    $with_results = 0;
    $no_results   = [];
    $failed       = [];              // séries passées faute d'avoir pu être traitées
    $rate_limit_notified = false;    // n'informer le client qu'une seule fois

    foreach ($targets as $series) {
        $current++;
        $sse('progress', [
            'current' => $current,
            'total'   => $total,
            'name'    => $series['name'],
        ]);

        // Quoi qu'il arrive sur cette série (erreur API, exception imprévue,
        // réponse invalide…), on avance : une série en échec ne doit jamais
        // bloquer ni interrompre le traitement des suivantes.
        try {
            $candidates = mangaupdates_associate_candidates($series['name'], $series['author'] ?? '', 5);
        } catch (\Throwable $e) {
            $candidates = [];
            $failed[] = $series['name'];
        }

        // Signaler une seule fois au client que l'API MangaUpdates limite les
        // requêtes (429) : la recherche continue, mais ralentie (backoff
        // géré dans mangaupdates_curl_with_backoff), et les résultats des
        // séries suivantes peuvent être partiels tant que la limite est active.
        if (!$rate_limit_notified && mangaupdates_rate_limit_hits() > 0) {
            $rate_limit_notified = true;
            $sse('rate_limited', [
                'message' => "L'API MangaUpdates limite temporairement les requêtes (erreur 429) : la recherche continue mais ralentit automatiquement.",
            ]);
        }

        if (!empty($candidates)) {
            $with_results++;
            $sse('match', [
                'series' => [
                    'id'      => $series['id'],
                    'name'    => $series['name'],
                    'author'  => $series['author'] ?? '',
                    'results' => $candidates,
                ],
            ]);
        } else {
            $no_results[] = $series['name'];
        }

        usleep(120000); // ~120 ms entre séries
    }

    $sse('done', [
        'success'        => true,
        'total'          => $total,
        'with_results'   => $with_results,
        'no_results'     => $no_results,
        'failed'         => $failed,
        'rate_limited'   => $rate_limit_notified,
    ]);
    exit;
}

// ── Endpoint SSE : association des GENRES MangaUpdates avec progression ───────
// Cible les séries qui possèdent une URL MangaUpdates mais aucun genre renseigné.
// Pour chacune, récupère les genres de la fiche MU et les traduit en français.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'mu_genres_stream') {
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
    @set_time_limit(0);
    while (ob_get_level()) ob_end_flush();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $sse = function(string $event, array $payload): void {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload) . "\n\n";
        flush();
    };

    // Une série « sans genre » : champ genres vide ou ne contenant que des chaînes vides
    $has_genres = function ($s): bool {
        $g = $s['genres'] ?? [];
        if (!is_array($g)) $g = [$g];
        foreach ($g as $one) {
            if (trim((string)$one) !== '') return true;
        }
        return false;
    };

    // Cibles : URL MangaUpdates présente ET aucun genre renseigné.
    // Périmètre V4 : Mangathèque uniquement — même filtrage que
    // mu_genres_targets().
    $targets = array_values(array_filter(series_of_type($data, 'manga'), function ($s) use ($has_genres) {
        return !empty($s['mangaupdates_url']) && !$has_genres($s);
    }));
    $total        = count($targets);
    $current      = 0;
    $with_results = 0;
    $no_results   = [];
    $failed       = [];
    $rate_limit_notified = false;

    foreach ($targets as $series) {
        $current++;
        $sse('progress', [
            'current' => $current,
            'total'   => $total,
            'name'    => $series['name'],
        ]);

        // Une série en échec (erreur API, exception imprévue…) ne doit
        // jamais bloquer ni interrompre le traitement des suivantes.
        try {
            $genres = mangaupdates_get_genres_from_url($series['mangaupdates_url']);
        } catch (\Throwable $e) {
            $genres = null;
            $failed[] = $series['name'];
        }

        if (!$rate_limit_notified && mangaupdates_rate_limit_hits() > 0) {
            $rate_limit_notified = true;
            $sse('rate_limited', [
                'message' => "L'API MangaUpdates limite temporairement les requêtes (erreur 429) : la recherche continue mais ralentit automatiquement.",
            ]);
        }

        if (!empty($genres)) {
            $with_results++;
            $sse('match', [
                'series' => [
                    'id'               => $series['id'],
                    'name'             => $series['name'],
                    'author'           => $series['author'] ?? '',
                    'mangaupdates_url' => $series['mangaupdates_url'],
                    'genres'           => $genres,
                ],
            ]);
        } else {
            $no_results[] = $series['name'];
        }

        usleep(120000); // ~120 ms entre séries (politesse API)
    }

    $sse('done', [
        'success'      => true,
        'total'        => $total,
        'with_results' => $with_results,
        'no_results'   => $no_results,
        'failed'       => $failed,
        'rate_limited' => $rate_limit_notified,
    ]);
    exit;
}

// ── Actions POST (enregistrement des associations et des genres) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tool_action'])) {
    $response = ['success' => false, 'message' => 'Action inconnue.'];

    switch ($_POST['tool_action']) {
        case 'mu_associate_save':
            // Format attendu : associations[series_id] = url
            $assoc = $_POST['associations'] ?? [];
            if (!is_array($assoc)) $assoc = [];
            $response = mu_save_associations($data, $assoc);
            break;

        case 'mu_genres_save':
            // Format attendu : genres[series_id] = "Genre1, Genre2"
            $genres_in = $_POST['genres'] ?? [];
            if (!is_array($genres_in)) $genres_in = [];
            $response = mu_save_genres($data, $genres_in);
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$tool_title    = 'Association MangaUpdates';
$tool_subtitle = 'Complète en masse les fiches MangaUpdates et les genres manquants.';
require __DIR__ . '/_layout_head.php';
?>

        <div class="tools-section">
            <h2>Associer une fiche MU aux séries</h2>
            <p>Recherche automatiquement une fiche MangaUpdates pour chaque série sans URL renseignée (titre + auteur), puis vous laisse valider la bonne correspondance avant l'enregistrement. Selon le nombre de séries, l'opération peut prendre quelques minutes.</p>
<?php
$mu_assoc_categories = mu_assoc_available_categories($data);
if (!empty($mu_assoc_categories)):
?>
            <div class="mu-associate-exclude">
                <span class="mu-associate-exclude-label">Exclure des catégories de la recherche</span>
                <div class="mu-associate-exclude-options">
<?php foreach ($mu_assoc_categories as $cat): ?>
                    <label class="mu-associate-exclude-option">
                        <input type="checkbox" class="mu-associate-exclude-checkbox" value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>">
                        <span><?= htmlspecialchars($cat) ?></span>
                    </label>
<?php endforeach; ?>
                </div>
                <p class="hint">Les séries portant l'une des catégories cochées ne seront pas incluses dans la recherche.</p>
            </div>
<?php endif; ?>
            <button id="mu-associate-btn" class="button button-opt">
                <span id="mu-associate-text">Recherche des liens</span>
                <span id="mu-associate-spinner" class="spinner" style="display: none;"></span>
            </button>
            <div id="mu-associate-progress"></div>
            <div id="mu-associate-results"></div>
        </div>

        <div class="tools-section">
            <h2>Associer les genres aux séries</h2>
            <p>Recherche les genres indiqués sur la fiche MangaUpdates de chaque série qui possède une URL mais aucun genre renseigné. Les genres sont traduits en français et pré-remplis : vous pouvez les modifier avant de valider, série par série ou toutes à la fois.</p>
            <button id="mu-genres-btn" class="button button-opt">
                <span id="mu-genres-text">Recherche des genres</span>
                <span id="mu-genres-spinner" class="spinner" style="display: none;"></span>
            </button>
            <div id="mu-genres-progress"></div>
            <div id="mu-genres-results"></div>
        </div>

<?php
require __DIR__ . '/_tools-modals.php';

$tool_scripts = ['mangaupdates-assoc.js'];
require __DIR__ . '/_layout_foot.php';
