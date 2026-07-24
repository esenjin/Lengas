<?php
// ────────────────────────────────────────────────────────────────────────────
// page-outils.php — Page dédiée aux outils
//
// Regroupe tous les outils du site, auparavant éparpillés entre la modale
// « Outils » et les entrées « Séries incomplètes » / « Incohérences » du menu
// latéral. Chaque outil a ses fonctions dans fonctions/tools/ et son script
// dans assets/js/admin/tools/.
//
// Cette page porte aussi les endpoints (SSE et POST) que ces outils appellent.
// ────────────────────────────────────────────────────────────────────────────

require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require 'includes/mangaupdates.php';
require_once 'includes/babengas.php';
require 'fonctions/series.php';
require 'fonctions/volumes.php';
require 'fonctions/wishlist.php';
require 'fonctions/loans.php';
require 'fonctions/read.php';
require 'fonctions/options.php';
require 'fonctions/tools.php';
require 'includes/themes.php';

$data    = load_data();
$options = load_options();

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

    $total          = count($data);
    $current        = 0;
    $force_uncached = isset($_GET['force_uncached']) && $_GET['force_uncached'] === '1';

    // Les volumes MangaUpdates sont récupérés à la volée dans la boucle ci-dessous.
    // Le cache SQLite (24h) évite de re-solliciter l'API à chaque analyse.
    // Avec force_uncached=1, seules les séries sans cache récent sont rechargées depuis l'API.

    foreach ($data as $series) {
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

    // Séries sans URL MangaUpdates
    $targets = array_values(array_filter($data, function ($s) {
        return empty($s['mangaupdates_url']);
    }));
    $total        = count($targets);
    $current      = 0;
    $with_results = 0;
    $no_results   = [];

    foreach ($targets as $series) {
        $current++;
        $sse('progress', [
            'current' => $current,
            'total'   => $total,
            'name'    => $series['name'],
        ]);

        $candidates = mangaupdates_associate_candidates($series['name'], $series['author'] ?? '', 5);

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
        'success'      => true,
        'total'        => $total,
        'with_results' => $with_results,
        'no_results'   => $no_results,
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

    // Cibles : URL MangaUpdates présente ET aucun genre renseigné
    $targets = array_values(array_filter($data, function ($s) use ($has_genres) {
        return !empty($s['mangaupdates_url']) && !$has_genres($s);
    }));
    $total        = count($targets);
    $current      = 0;
    $with_results = 0;
    $no_results   = [];

    foreach ($targets as $series) {
        $current++;
        $sse('progress', [
            'current' => $current,
            'total'   => $total,
            'name'    => $series['name'],
        ]);

        $genres = mangaupdates_get_genres_from_url($series['mangaupdates_url']);

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
    ]);
    exit;
}

// ── Outil « Sauvegardes » : téléchargement d'une archive ─────────────────────
// Les fichiers .zip du dossier saves/ sont volontairement bloqués par le
// .htaccess (accès direct interdit). Le téléchargement passe donc par ce
// endpoint authentifié, qui relit le fichier et le renvoie lui-même.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download_backup'])) {
    $requested = $_GET['download_backup'];

    // On ne garde que le nom de fichier : neutralise toute tentative de
    // traversée de répertoire (../, chemins absolus…).
    $filename = basename($requested);
    $path     = 'saves/' . $filename;

    // Le nom doit correspondre au format généré par create_backup()
    if (!preg_match('/^save_\d+\.zip$/', $filename) || !is_file($path)) {
        http_response_code(404);
        exit('Sauvegarde introuvable.');
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
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

// ── Outil « Sauvegardes » : actions POST ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup_action'])) {
    $action = $_POST['backup_action'];

    // Export JSON : téléchargement direct d'un fichier .json
    if ($action === 'export_json') {
        $export   = build_json_export();
        $json     = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $filename = 'lengas_export_' . date('Y-m-d_His') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    $response = ['success' => false, 'message' => ''];

    switch ($action) {
        case 'create_backup':
            $response = create_backup();
            break;

        case 'delete_backup':
            $response = delete_backup($_POST['backup_file'] ?? '');
            break;

        case 'list_backups':
            $response = list_backups();
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// ── Outils « Intégrité », « Nettoyage », « Association MU » et « Incohérences » ─
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

        case 'check_coherence':
            $response = ['success' => true, 'issues' => check_collection_coherence($data)];
            break;

        case 'babengas_launch':
            $response = babengas_launch_campaign($data, ($_POST['all'] ?? '0') === '1');
            break;

        case 'babengas_status':
            $response = babengas_campaign_status($data);
            break;

        case 'babengas_cancel':
            $response = babengas_cancel_current();
            break;

        case 'babelio_associate_save':
            // Format attendu : associations[series_id] = url
            $assoc = $_POST['associations'] ?? [];
            if (!is_array($assoc)) $assoc = [];
            $response = babelio_save_associations($data, $assoc);
            break;

        case 'coherence_quick_edit':
            $response = coherence_quick_edit($data, $_POST);
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outils — <?= htmlspecialchars($options['site_name'] ?? 'Lengas') ?></title>
    <meta name="description" content="Outils de maintenance et de vérification de la collection.">
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
    <?= theme_link_tag($options) ?>
</head>
<body class="with-sidebar">

    <?php include 'includes/sidebar.php'; ?>

    <main class="page-main">
        <div class="page-header">
            <h1>Outils</h1>
            <p class="page-subtitle">Vérifiez, complétez et sauvegardez votre collection.</p>
        </div>

        <!-- Onglets -->
        <div class="tools-tabs" role="tablist">
            <button type="button" class="tools-tab tools-tab--active" data-tab="incomplete">Séries incomplètes</button>
<?php if (function_exists('babengas_enabled') && babengas_enabled()): ?>
            <button type="button" class="tools-tab" data-tab="babengas">Vérification Babengas</button>
<?php endif; ?>
            <button type="button" class="tools-tab" data-tab="coherences">Incohérences</button>
            <button type="button" class="tools-tab" data-tab="backups">Sauvegardes</button>
            <button type="button" class="tools-tab" data-tab="associate">Association MangaUpdates</button>
            <button type="button" class="tools-tab" data-tab="integrity">Vérification d'intégrité</button>
        </div>

        <!-- ── Onglet : Séries incomplètes ─────────────────────────────────── -->
        <div class="tools-tab-panel tools-tab-panel--active" data-tab-panel="incomplete">
            <div class="tools-section">
                <h2>Séries incomplètes</h2>
                <p>Cet outil vous permet de trouver les séries pour lesquelles il vous manque des tomes, en comparant votre collection aux données de l'API MangaUpdates.</p>
                <p class="hint">⚠️ Limitations : MangaUpdates fournit le nombre de tomes aussi bien pour les séries <strong>terminées</strong> que pour celles <strong>en cours de publication</strong>. En revanche, le décompte se base principalement sur l'édition d'origine (VO) et non sur l'édition française (VF) : un écart est donc possible. Lengas privilégie automatiquement le décompte français lorsque MangaUpdates l'indique (ex. « 8 Volumes (Complete, France) »). Renseignez l'URL MangaUpdates de chaque série — via le champ dédié (ajout / modification) ou l'onglet « Association MangaUpdates ».</p>

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
        </div>

        <!-- ── Onglet : Vérification Babelio (Babengas) ─────────────────────── -->
        <?php if (function_exists('babengas_enabled') && babengas_enabled()): ?>
        <div class="tools-tab-panel" data-tab-panel="babengas">
            <div class="tools-section">
                <h2>Vérification via Babengas</h2>
                <p>Cet outil interroge <strong>Babelio</strong> — via votre service Babengas — pour connaître le nombre de tomes <strong>réellement parus en France</strong>. Là où MangaUpdates se base surtout sur l'édition d'origine, Babelio couvre bien mieux les sorties VF : en cas de divergence, c'est ce décompte qui fait foi.</p>

                <p class="hint">⏱️ Le traitement est <strong>asynchrone et lent, volontairement</strong>, par courtoisie envers leurs serveurs. Comptez environ cinq minutes par série. Vous pouvez fermer cette page sans interrompre la campagne : le suivi reprendra à votre retour.</p>
                <p class="hint">Sont exclues du ciblage les séries dont la publication est terminée et celles possédant un tome tagué « dernier tome » : elles n'ont plus rien à apprendre de Babelio. Les séries vérifiées il y a moins de 30 jours sont ignorées, sauf si un tome a été ajouté depuis.</p>

                <div class="tools-actions">
                    <button id="babengas-launch" class="button">Lancer une campagne</button>
                    <button id="babengas-launch-all" class="button button-opt" title="Vérifie toutes les séries éligibles, y compris celles contrôlées il y a moins de 30 jours">Forcer toutes les séries</button>
                    <button id="babengas-cancel" class="button button-opt" style="display:none;">Annuler la campagne</button>
                </div>

                <div id="babengas-progress"></div>
                <div id="babengas-results"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Onglet : Incohérences ───────────────────────────────────────── -->
        <div class="tools-tab-panel" data-tab-panel="coherences">
            <div class="tools-section">
                <h2>Incohérences de la collection</h2>
                <p>Vérification des incohérences internes de vos séries (doublons, numéros manquants, mauvais tag « dernier tome », prêts orphelins…). Cet outil exploite aussi le statut de publication MangaUpdates mis en cache.</p>
                <div class="tools-actions">
                    <button id="reload-coherences-btn" class="button button-opt">Relancer l'analyse</button>
                </div>
                <div id="coherences-results">
                    <!-- Résultats chargés dynamiquement -->
                </div>
            </div>
        </div>

        <!-- ── Onglet : Sauvegardes ────────────────────────────────────────── -->
        <div class="tools-tab-panel" data-tab-panel="backups">
            <p>Vous pouvez ici sauvegarder vos données. Les fichiers concernés sont : la base de données de votre bibliothèque et leurs images, la liste de vos envies, la liste de vos prêts, ainsi que les options du site.</p>

            <div class="tools-section">
                <h2>Créer une sauvegarde</h2>
                <p>Crée une archive de vos données actuelles.</p>
                <button id="create-backup-btn" class="button button-opt">
                    <span id="create-backup-text">Créer une sauvegarde</span>
                    <span id="create-backup-spinner" class="spinner" style="display: none;"></span>
                </button>
            </div>

            <div class="tools-section">
                <h2>Exporter en JSON</h2>
                <p>Télécharge l'ensemble de vos données (collection, envies, prêts, lues ailleurs et options) sous la forme d'un unique fichier JSON lisible. Idéal pour la portabilité ou une lecture externe — cela ne remplace pas la sauvegarde ZIP, qui inclut aussi la base SQLite et les images.</p>
                <button id="export-json-btn" class="button button-oas">
                    <span id="export-json-text">Exporter en JSON</span>
                    <span id="export-json-spinner" class="spinner" style="display: none;"></span>
                </button>
            </div>

            <div class="tools-section">
                <h2>Liste des sauvegardes</h2>
                <p>Vous pouvez télécharger ou supprimer vos sauvegardes.</p>
                <div id="backups-list">
                    <!-- Les sauvegardes seront affichées ici -->
                </div>
            </div>
        </div>

        <!-- ── Onglet : Association MangaUpdates ───────────────────────────── -->
        <div class="tools-tab-panel" data-tab-panel="associate">
            <div class="tools-section">
                <h2>Associer MangaUpdates</h2>
                <p>Recherche automatiquement une fiche MangaUpdates pour chaque série sans URL renseignée (titre + auteur), puis vous laisse valider la bonne correspondance avant l'enregistrement. Selon le nombre de séries, l'opération peut prendre quelques minutes.</p>
                <button id="mu-associate-btn" class="button button-opt">
                    <span id="mu-associate-text">Recherche des liens</span>
                    <span id="mu-associate-spinner" class="spinner" style="display: none;"></span>
                </button>
                <div id="mu-associate-progress"></div>
                <div id="mu-associate-results"></div>
            </div>

            <div class="tools-section">
                <h2>Associer les genres</h2>
                <p>Recherche les genres indiqués sur la fiche MangaUpdates de chaque série qui possède une URL mais aucun genre renseigné. Les genres sont traduits en français et pré-remplis : vous pouvez les modifier avant de valider, série par série ou toutes à la fois.</p>
                <button id="mu-genres-btn" class="button button-opt">
                    <span id="mu-genres-text">Recherche des genres</span>
                    <span id="mu-genres-spinner" class="spinner" style="display: none;"></span>
                </button>
                <div id="mu-genres-progress"></div>
                <div id="mu-genres-results"></div>
            </div>
        </div>

        <!-- ── Onglet : Vérification d'intégrité ───────────────────────────── -->
        <div class="tools-tab-panel" data-tab-panel="integrity">
            <div class="tools-section">
                <h2>Vérification d'intégrité</h2>
                <p>Vérifie l'intégrité de votre site et de vos données (fichiers, permissions, structure de la base, thèmes personnalisés, fichiers Vestikan, API MangaUpdates…).</p>
                <button id="check-integrity-btn" class="button button-oas">
                    <span id="check-integrity-text">Vérifier l'intégrité</span>
                    <span id="check-integrity-spinner" class="spinner" style="display: none;"></span>
                </button>
                <div id="integrity-results-container"></div>
            </div>
        </div>

    </main>

    <!-- ── Modales utilisées par les outils ────────────────────────────────── -->

    <!-- Édition rapide depuis l'outil « Incohérences » -->
    <div class="modal" id="coherence-edit-modal">
        <div class="modal-content modal-content--wide">
            <span class="close-modal" id="close-coherence-edit-modal">&times;</span>
            <h2>Corriger la série</h2>

            <input type="hidden" id="cedit-series-id">

            <!-- Infos lecture seule -->
            <div class="cedit-info-grid">
                <div class="cedit-info-item">
                    <span class="cedit-info-label">Titre</span>
                    <span class="cedit-info-value" id="cedit-name"></span>
                </div>
                <div class="cedit-info-item">
                    <span class="cedit-info-label">Auteur</span>
                    <span class="cedit-info-value" id="cedit-author"></span>
                </div>
                <div class="cedit-info-item">
                    <span class="cedit-info-label">Éditeur</span>
                    <span class="cedit-info-value" id="cedit-publisher"></span>
                </div>
                <div class="cedit-info-item">
                    <span class="cedit-info-label">Catégories</span>
                    <span class="cedit-info-value" id="cedit-categories"></span>
                </div>
            </div>

            <hr class="cedit-divider">

            <!-- Champs éditables -->
            <div class="cedit-field-group">
                <label class="cedit-label" for="cedit-status">Statut de publication</label>
                <select id="cedit-status" class="cedit-select">
                    <option value="en cours">En cours</option>
                    <option value="terminée">Terminée</option>
                    <option value="en pause">En pause</option>
                    <option value="abandonnée">Abandonnée</option>
                </select>
            </div>

            <div class="cedit-field-group">
                <label class="cedit-label cedit-label--checkbox">
                    <input type="checkbox" id="cedit-read-elsewhere">
                    Lue ailleurs
                </label>
                <p class="hint">La série est lue en dehors de la collection physique.</p>
            </div>

            <hr class="cedit-divider">

            <!-- Liste des tomes -->
            <div class="cedit-volumes-header">
                <span class="cedit-label">Tomes</span>
                <button type="button" class="button button-sm button-ats" id="cedit-add-volume-btn">+ Ajouter un tome</button>
            </div>
            <div id="cedit-volumes-list" class="cedit-volumes-list">
                <!-- Tomes injectés dynamiquement -->
            </div>

            <div class="modal-actions cedit-actions">
                <button type="button" class="button button-ats" id="cedit-save-btn">
                    <span id="cedit-save-text">Enregistrer</span>
                    <span id="cedit-save-spinner" class="spinner" style="display:none;"></span>
                </button>
            </div>
            <p id="cedit-feedback" class="cedit-feedback"></p>
        </div>
    </div>

    <!-- Ajouter une URL MangaUpdates (depuis l'outil des tomes manquants) -->
    <div class="modal" id="add-mu-url-modal">
        <div class="modal-content modal-content--narrow">
            <span class="close-modal" id="close-add-mu-url-modal">&times;</span>
            <h2>Ajouter une URL MangaUpdates</h2>
            <p id="add-mu-url-series-name" class="add-mu-url-series-name"></p>
            <input type="hidden" id="add-mu-url-series-id">
            <input type="text" id="add-mu-url-input" placeholder="https://www.mangaupdates.com/series/xxxxxxx/nom-de-la-serie" autocomplete="off">
            <p class="hint">Collez l'URL de la fiche MangaUpdates de cette série.</p>
            <div class="modal-actions">
                <button id="save-add-mu-url-btn" class="button button-ats">Enregistrer</button>
            </div>
            <p id="add-mu-url-feedback" class="add-mu-url-feedback"></p>
        </div>
    </div>

    <!-- Ajout d'une URL Babelio depuis le récapitulatif de campagne -->
    <div class="modal" id="add-babelio-url-modal">
        <div class="modal-content modal-content--narrow">
            <span class="close-modal" id="close-add-babelio-url-modal">&times;</span>
            <h2>Ajouter une URL Babelio</h2>
            <p id="add-babelio-url-series-name" class="add-mu-url-series-name"></p>
            <input type="hidden" id="add-babelio-url-series-id">
            <input type="text" id="add-babelio-url-input" placeholder="https://www.babelio.com/serie/nom-de-la-serie/12345" autocomplete="off">
            <p class="hint">Collez l'URL de la fiche <strong>série</strong> (adresse en <code>/serie/…</code>). Une URL de tome (<code>/livres/…</code>) est refusée : seule la fiche série porte la liste complète des tomes.</p>
            <div class="modal-actions">
                <button id="save-add-babelio-url-btn" class="button button-ats">Enregistrer</button>
            </div>
            <p id="add-babelio-url-feedback" class="add-mu-url-feedback"></p>
        </div>
    </div>

    <!-- Alertes personnalisées -->
    <div class="modal" id="custom-alert-modal">
        <div class="modal-content">
            <h2 id="custom-alert-title">Avertissement</h2>
            <p id="custom-alert-message"></p>
            <button id="custom-alert-ok" class="button">OK</button>
        </div>
    </div>

    <!-- Confirmations personnalisées -->
    <div class="modal" id="custom-confirm-modal">
        <div class="modal-content">
            <h2 id="custom-confirm-title">Confirmation</h2>
            <p id="custom-confirm-message"></p>
            <div class="modal-actions">
                <button id="custom-confirm-ok" class="button">OK</button>
                <button id="custom-confirm-cancel" class="button">Annuler</button>
            </div>
        </div>
    </div>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <?php
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
        window.seriesData = <?= json_encode($series_with_status) ?>;
    </script>
    <script src="assets/js/admin/tools/page.js"></script>
    <script src="assets/js/admin/tools/incomplete.js"></script>
    <?php if (function_exists('babengas_enabled') && babengas_enabled()): ?>
        <script src="assets/js/admin/tools/babengas.js"></script>
    <?php endif; ?>
    <script src="assets/js/admin/tools/coherence.js"></script>
    <script src="assets/js/admin/tools/backups.js"></script>
    <script src="assets/js/admin/tools/mangaupdates-assoc.js"></script>
    <script src="assets/js/admin/tools/integrity.js"></script>

</body>
</html>
