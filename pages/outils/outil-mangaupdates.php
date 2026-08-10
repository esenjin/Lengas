<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-mangaupdates.php — Outil « Séries incomplètes »
// (vérification du nombre de tomes via MangaUpdates)
//
// Détecte les tomes manquants en comparant la collection au nombre de tomes
// indiqué par MangaUpdates (le décompte VF est privilégié lorsqu'il est
// disponible), avec progression en direct, filtres et ajout des tomes
// manquants.
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Endpoint SSE : analyse des séries incomplètes avec progression ────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'incomplete_series_stream') {
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
    while (ob_get_level()) ob_end_flush();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $sse = function(string $event, array $payload): void {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload) . "\n\n";
        flush();
    };

    $incomplete_series        = [];
    $series_with_more_volumes = [];
    $no_reference_series      = [];
    $failed_series            = [];

    // Périmètre V4 : ces vérifications ne concernent que la Mangathèque
    // (MangaUpdates ne référence pas d'animés). Même filtrage que
    // get_incomplete_series() côté POST.
    $targets = series_of_type($data, 'manga');

    $total          = count($targets);
    $current        = 0;
    $force_uncached = isset($_GET['force_uncached']) && $_GET['force_uncached'] === '1';

    // Les volumes MangaUpdates sont récupérés à la volée dans la boucle ci-dessous.
    // Le cache SQLite (24h) évite de re-solliciter l'API à chaque analyse.
    // Avec force_uncached=1, seules les séries sans cache récent sont rechargées depuis l'API.

    foreach ($targets as $series) {
        $current++;
        $sse('progress', [
            'current' => $current,
            'total'   => $total,
            'name'    => $series['name'],
        ]);

        $url = $series['mangaupdates_url'] ?? '';

        // Aucune référence disponible
        if ($url === '') {
            $no_reference_series[] = ['id' => $series['id'], 'name' => $series['name'], 'author' => $series['author'] ?? '', 'read_elsewhere' => !empty($series['read_elsewhere'])];
            continue;
        }

        // URL présente mais invalide
        $id = mangaupdates_get_id_from_url($url);
        if ($id === null) {
            $failed_series[] = [
                'id'             => $series['id'],
                'name'           => $series['name'],
                'author'         => $series['author'] ?? '',
                'ref'            => 'mangaupdates',
                'reason'         => 'URL MangaUpdates invalide',
                'has_mu_url'     => false, // URL présente mais invalide : on propose l'ajout
                'read_elsewhere' => !empty($series['read_elsewhere']),
            ];
            continue;
        }

        // Référence : MangaUpdates
        // force_uncached : on force le rechargement uniquement si la série n'a pas
        // de cache récent (< 24h), pour ne pas re-appeler les fiches déjà fraîches.
        $force_this = false;
        if ($force_uncached) {
            $cached_check = mangaupdates_get_cached_status($id, 86400);
            $force_this   = ($cached_check === null); // pas de cache valide → forcer
        }
        $info = mangaupdates_get_volumes($id, $force_this);
        if ($info === null) {
            // Échec de récupération : réseau ou service indisponible
            $failed_series[] = [
                'id'             => $series['id'],
                'name'           => $series['name'],
                'author'         => $series['author'] ?? '',
                'ref'            => 'mangaupdates',
                'reason'         => 'Erreur de récupération MangaUpdates (réseau ou service indisponible)',
                'has_mu_url'     => true, // URL valide : pas besoin du bouton Ajouter
                'read_elsewhere' => !empty($series['read_elsewhere']),
            ];
            continue;
        }

        $av = $info['volumes'];
        if ($av === null || (int)$av <= 0) {
            // Fiche trouvée mais sans nombre de tomes renseigné
            $failed_series[] = [
                'id'              => $series['id'],
                'name'            => $series['name'],
                'author'          => $series['author'] ?? '',
                'ref'             => 'mangaupdates',
                'reason'          => 'Nombre de tomes non renseigné sur MangaUpdates',
                'has_mu_url'      => true, // URL valide : pas besoin du bouton Ajouter
                'mangaupdates_url'=> $url, // URL pour afficher le badge MU
                'read_elsewhere'  => !empty($series['read_elsewhere']),
            ];
            continue;
        }

        $ref_volumes = (int)$av;
        $owned_volumes               = count($series['volumes']);
        $series['ref_volumes_source'] = 'mangaupdates';
        $series['ref_volumes']        = $ref_volumes;
        $series['ref_status']         = $info['status']    ?? null;
        $series['ref_completed']      = $info['completed'] ?? false;
        $series['ref_country']        = $info['country']   ?? '';

        if ($owned_volumes < $ref_volumes) {
            $missing = [];
            for ($i = $owned_volumes + 1; $i <= $ref_volumes; $i++) $missing[] = $i;
            $series['missing_volumes'] = $missing;
            $incomplete_series[] = $series;
        } elseif ($owned_volumes > $ref_volumes) {
            $series['has_more_volumes'] = true;
            $series['missing_volumes']  = [];
            $series_with_more_volumes[] = $series;
        }
    }

    $incomplete = array_merge($incomplete_series, $series_with_more_volumes);
    foreach ($incomplete as &$s) {
        if (!isset($s['missing_volumes'])) $s['missing_volumes'] = [];
    }

    $sse('done', [
        'success'             => true,
        'incomplete_series'   => $incomplete,
        'no_reference_series' => $no_reference_series,
        'failed_series'       => $failed_series,
    ]);
    exit;
}

// ── Outil « Séries incomplètes » : actions POST ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action   = $_POST['action'];
    $response = ['success' => false];

    switch ($action) {
        case 'get_incomplete_series':
            try {
                $response = array_merge(['success' => true], build_incomplete_report($data));
            } catch (Exception $e) {
                $response['success'] = false;
                $response['message'] = "Impossible de récupérer les séries incomplètes. Veuillez réessayer plus tard.";
            }
            break;

        case 'add_missing_volume':
            $response = add_missing_volume(
                $data,
                $_POST['series_id'] ?? '',
                (int)($_POST['volume_number'] ?? 0)
            );
            break;

        case 'add_all_missing_volumes':
            $missing = isset($_POST['missing_volumes'])
                ? explode(',', $_POST['missing_volumes'])
                : [];
            $response = add_all_missing_volumes($data, $_POST['series_id'] ?? '', $missing);
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// ── Outils « Association MU » : ajout d'une URL depuis cet outil ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tool_action'] ?? '') === 'mu_associate_save') {
    // Format attendu : associations[series_id] = url
    $assoc = $_POST['associations'] ?? [];
    if (!is_array($assoc)) $assoc = [];
    $response = mu_save_associations($data, $assoc);

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$tool_title    = 'Vérification via MangaUpdates';
$tool_subtitle = 'Détecte les tomes manquants en comparant votre collection aux données de MangaUpdates.';
require __DIR__ . '/_layout_head.php';
?>

        <div class="tools-section">
            <h2>Vérification via MangaUpdates</h2>
            <p>Cet outil vous permet de trouver les séries pour lesquelles il vous manque des tomes, en comparant votre collection aux données de l'API MangaUpdates.</p>
            <p class="hint">⚠️ Limitations : MangaUpdates fournit le nombre de tomes aussi bien pour les séries <strong>terminées</strong> que pour celles <strong>en cours de publication</strong>. En revanche, le décompte se base principalement sur l'édition d'origine (VO) et non sur l'édition française (VF) : un écart est donc possible. Lengas privilégie automatiquement le décompte français lorsque MangaUpdates l'indique (ex. « 8 Volumes (Complete, France) »). Renseignez l'URL MangaUpdates de chaque série — via le champ dédié (ajout / modification) ou l'outil « Association MangaUpdates ».</p>

            <div class="tools-actions">
                <button id="search-incomplete-series" class="button">Rechercher les séries incomplètes</button>
                <button id="force-incomplete-search" class="button button-opt" title="Interroge MangaUpdates pour les séries sans cache récent (ignore les résultats mis en cache il y a moins de 24 h)">Forcer la recherche (non analysées)</button>
            </div>

            <!-- Barre de filtres (masquée jusqu'à l'obtention des résultats) -->
            <div id="incomplete-filters-bar" class="incomplete-filters-bar" style="display:none;">
                <input
                    type="text"
                    id="incomplete-search-input"
                    class="incomplete-search-input"
                    placeholder="Filtrer par titre, auteur, éditeur…"
                    autocomplete="off"
                >
                <div class="incomplete-filter-selects">
                    <select id="incomplete-status-filter" class="incomplete-status-filter">
                        <option value="">Tous les statuts MU</option>
                        <option value="complete">Terminé</option>
                        <option value="ongoing">En cours</option>
                        <option value="hiatus">En pause</option>
                        <option value="cancelled">Annulé</option>
                    </select>
                    <select id="incomplete-sort-date" class="incomplete-sort-date">
                        <option value="">Tri par défaut</option>
                        <option value="recent">Plus récent d'abord</option>
                        <option value="oldest">Plus ancien d'abord</option>
                    </select>
                </div>
                <span id="incomplete-filter-count" class="incomplete-filter-count"></span>
            </div>

            <div id="incomplete-series-results">
                <!-- Les résultats seront affichés ici -->
            </div>
        </div>

<?php
$tm_add_mu_url = true;
require __DIR__ . '/_tools-modals.php';

$tool_scripts = ['incomplete.js'];
require __DIR__ . '/_layout_foot.php';
