<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-anilist-import.php — Outil « Import Anilist »
//
// Importe en masse la liste ANIME d'un compte Anilist (par pseudo public),
// avec un écran d'aperçu détaillé avant toute écriture : rien n'est
// enregistré tant que l'administrateur n'a pas validé.
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Endpoint SSE : phase 1 de l'import Anilist (récupération + aperçu) ───────
// Récupère l'intégralité de la liste ANIME du pseudo donné, construit
// l'aperçu (classement par destination, décomptes, listes personnalisées,
// favoris natifs) et le PERSISTE (anilist_import_save_state) avant de le
// renvoyer : fermer l'onglet à ce stade ne perd donc pas le travail déjà fait,
// l'écran d'aperçu peut le relire au prochain chargement de la page.
//
// Aucune écriture dans `series` / `wishlist` à ce stade — uniquement dans la
// table `options` (l'aperçu lui-même), qui n'a aucune incidence sur la
// collection.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'anilist_import_preview_stream') {
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

    $username = trim($_GET['username'] ?? '');
    if ($username === '') {
        $sse('done', ['success' => false, 'message' => "Veuillez saisir un pseudo Anilist."]);
        exit;
    }

    // Nouvelle campagne : toute campagne d'aperçu précédente (validée ou non)
    // est abandonnée. Réglages et pseudo ne sont jamais mémorisés d'une
    // campagne à l'autre.
    anilist_import_clear_state();

    $wishlist = load_wishlist();

    $sse('progress', [
        'phase'   => 'list',
        'message' => "Récupération de la liste de « " . $username . " »…",
    ]);

    // anilist_fetch_user_list() répond par tranches de 250 entrées ; on relaie
    // une progression à chaque tranche reçue plutôt qu'un simple message figé.
    $preview = anilist_import_build_preview($username, $data, $wishlist);

    if (empty($preview['ok'])) {
        $sse('done', [
            'success' => false,
            'message' => $preview['message'] ?? "Impossible de récupérer la liste Anilist.",
        ]);
        exit;
    }

    anilist_import_save_state($preview);

    $sse('done', array_merge(['success' => true], $preview));
    exit;
}

// ── Endpoint : relit l'aperçu persisté (reprise après rechargement) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'anilist_import_state') {
    header('Content-Type: application/json');
    $state = anilist_import_load_state();
    if ($state === null) {
        echo json_encode(['success' => true, 'has_state' => false]);
    } else {
        echo json_encode(array_merge(['success' => true, 'has_state' => true], $state));
    }
    exit;
}

// ── Endpoint : abandon de l'aperçu en cours, sans import ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tool_action'] ?? '') === 'anilist_import_discard') {
    anilist_import_clear_state();
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// ── Endpoint : enregistre les réglages retenus avant de lancer l'import ──────
// Les décochages individuels peuvent porter sur plusieurs centaines de
// séries : les transmettre en query string à l'EventSource de la phase 2
// risquerait de dépasser les limites de longueur d'URL de certains serveurs.
// On les persiste donc ici (avec le reste des réglages, par cohérence), et
// l'endpoint SSE de la phase 2 les relit depuis l'état plutôt que depuis
// l'URL.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tool_action'] ?? '') === 'anilist_import_settings_save') {
    $state = anilist_import_load_state();
    if ($state === null) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Aucun aperçu en attente."]);
        exit;
    }

    $settings = [
        'statuses'         => isset($_POST['statuses']) ? (array)$_POST['statuses'] : anilist_list_status_keys(),
        'formats'          => isset($_POST['formats']) ? (array)$_POST['formats'] : anilist_format_keys(),
        'include_adult'    => ($_POST['include_adult'] ?? '0') === '1',
        'update_existing'  => ($_POST['update_existing'] ?? '1') === '1',
        'excluded_ids'     => isset($_POST['excluded_ids']) ? array_map('intval', (array)$_POST['excluded_ids']) : [],
        'favourite_lists'  => isset($_POST['favourite_lists']) ? (array)$_POST['favourite_lists'] : [],
        'favourite_native' => ($_POST['favourite_native'] ?? '0') === '1',
    ];

    $state['pending_settings'] = $settings;
    anilist_import_save_state($state);

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// ── Endpoint SSE : phase 2 de l'import Anilist (écriture) ────────────────────
// Relit l'aperçu ET les réglages persistés par les deux étapes précédentes
// (jamais les données du navigateur : les décomptes affichés doivent
// correspondre à ce qui a réellement été récupéré), puis exécute l'import
// avec une progression série par série.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'anilist_import_run_stream') {
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

    $state = anilist_import_load_state();
    if ($state === null) {
        $sse('done', [
            'success' => false,
            'message' => "Aucun aperçu en attente. Relancez une récupération avant d'importer.",
        ]);
        exit;
    }

    $settings = $state['pending_settings'] ?? [
        'statuses'         => anilist_list_status_keys(),
        'formats'          => anilist_format_keys(),
        'include_adult'    => false,
        'update_existing'  => true,
        'excluded_ids'     => [],
        'favourite_lists'  => [],
        'favourite_native' => false,
    ];

    $entries = anilist_import_apply_settings($state['entries'] ?? [], $settings);
    $total   = count($entries);

    if ($total === 0) {
        $sse('done', [
            'success' => true,
            'created' => [], 'updated' => [], 'wishlisted' => [], 'skipped' => [], 'errors' => [],
            'favourite_count' => 0,
            'message' => "Aucune série ne correspond aux réglages retenus : rien à importer.",
        ]);
        anilist_import_clear_state();
        exit;
    }

    $wishlist = load_wishlist();

    $result = anilist_import_run($data, $wishlist, $entries, function ($current, $entry_total, $title) use ($sse) {
        $sse('progress', ['current' => $current, 'total' => $entry_total, 'title' => $title]);
    });

    // Écriture ciblée : anilist_import_run() upserte chaque série créée ou
    // mise à jour au fil de la boucle (via add_anime_series() et les
    // écritures ciblées propres aux fonctions de anilist_import.php).
    // $result['data'] ne sert plus qu'à l'éventuel usage en mémoire de cette
    // requête, jamais à une resynchronisation globale de la collection.
    save_wishlist($result['wishlist']);

    // La campagne est intégralement traitée (avec ou sans erreurs partielles) :
    // l'aperçu n'a plus lieu d'être conservé.
    anilist_import_clear_state();

    $sse('done', [
        'success'         => true,
        'created'         => $result['created'],
        'updated'         => $result['updated'],
        'wishlisted'      => $result['wishlisted'],
        'skipped'         => $result['skipped'],
        'errors'          => $result['errors'],
        'favourite_count' => $result['favourite_count'],
    ]);
    exit;
}

$tool_title    = 'Import Anilist';
$tool_subtitle = 'Importe en masse la liste animée d\'un compte Anilist public.';
require __DIR__ . '/_layout_head.php';
?>

        <div class="tools-section">
            <h2>Importer depuis Anilist</h2>
            <p>Récupère l'intégralité de la liste animée d'un compte Anilist public et propose un aperçu détaillé avant toute écriture : rien n'est enregistré tant que vous n'avez pas validé.</p>
            <p class="hint">⚠️ Le pseudo n'est pas mémorisé d'une campagne à l'autre : il est à ressaisir à chaque import. Une liste privée ou un pseudo introuvable sera signalé.</p>

            <!-- ── Étape 1 : saisie du pseudo ──────────────────────────── -->
            <div id="anilist-import-step-username" class="anilist-import-step">
                <div class="anilist-import-username-row">
                    <input type="text" id="anilist-import-username" placeholder="Pseudo Anilist…" autocomplete="off">
                    <button type="button" id="anilist-import-fetch-btn" class="button">
                        <span id="anilist-import-fetch-text">Récupérer la liste</span>
                        <span id="anilist-import-fetch-spinner" class="spinner" style="display:none;"></span>
                    </button>
                </div>
                <div id="anilist-import-fetch-progress"></div>
                <p id="anilist-import-fetch-feedback" class="anilist-import-feedback"></p>
            </div>

            <!-- ── Étape 2 : aperçu (rempli dynamiquement) ─────────────── -->
            <div id="anilist-import-step-preview" class="anilist-import-step" style="display:none;">

                <div class="anilist-import-summary">
                    <h3>Récapitulatif de la récupération</h3>
                    <div id="anilist-import-dest-counts" class="anilist-import-dest-counts"></div>
                    <p id="anilist-import-duration" class="hint"></p>
                </div>

                <!-- Sélecteur des favoris -->
                <div class="anilist-import-settings-block">
                    <h3>Séries favorites</h3>
                    <p class="hint">Sélection multiple : listes personnalisées détectées sur le compte, favoris natifs (cœurs) Anilist, ou aucune.</p>
                    <div id="anilist-import-favourite-options" class="anilist-import-checkbox-grid"></div>
                </div>

                <!-- Statuts à importer -->
                <div class="anilist-import-settings-block">
                    <h3>Statuts à importer</h3>
                    <div id="anilist-import-status-options" class="anilist-import-checkbox-grid"></div>
                </div>

                <!-- Exclusion par format -->
                <div class="anilist-import-settings-block">
                    <h3>Formats à importer</h3>
                    <p class="hint">Les clips musicaux (MUSIC) sont décochés par défaut.</p>
                    <div id="anilist-import-format-options" class="anilist-import-checkbox-grid"></div>
                </div>

                <!-- Séries déjà présentes -->
                <div class="anilist-import-settings-block">
                    <h3>Séries déjà présentes</h3>
                    <label class="anilist-import-radio-option">
                        <input type="radio" name="anilist-import-update-existing" value="1" checked>
                        Mettre à jour (épisodes, statut de diffusion, genres…)
                    </label>
                    <label class="anilist-import-radio-option">
                        <input type="radio" name="anilist-import-update-existing" value="0">
                        Laisser intactes
                    </label>
                </div>

                <!-- Séries adultes -->
                <div class="anilist-import-settings-block">
                    <h3>Séries classées adultes</h3>
                    <label class="anilist-import-radio-option">
                        <input type="radio" name="anilist-import-adult" value="1" checked>
                        Importer (la coche « Contenu mature » sera posée automatiquement)
                    </label>
                    <label class="anilist-import-radio-option">
                        <input type="radio" name="anilist-import-adult" value="0">
                        Exclure de cette campagne
                    </label>
                </div>

                <!-- Estimation de la durée -->
                <p id="anilist-import-estimated-count" class="hint"></p>

                <!-- Liste détaillée dépliable -->
                <details class="anilist-import-details-block">
                    <summary>Détail des séries (dépliable)</summary>

                    <input
                        type="text"
                        id="anilist-import-search-input"
                        class="incomplete-search-input"
                        placeholder="Filtrer par titre (romaji, anglais, natif, synonymes)…"
                        autocomplete="off"
                    >
                    <p id="anilist-import-reset-notice" class="anilist-import-reset-notice" style="display:none;">
                        Les décochages individuels ont été réinitialisés suite à un changement de réglage.
                    </p>

                    <div id="anilist-import-groups"></div>
                </details>

                <!-- Validation -->
                <div class="tools-actions anilist-import-launch-row">
                    <button type="button" id="anilist-import-launch-btn" class="button button-ats">
                        <span id="anilist-import-launch-text">Lancer l'import</span>
                        <span id="anilist-import-launch-spinner" class="spinner" style="display:none;"></span>
                    </button>
                    <button type="button" id="anilist-import-discard-btn" class="button button-opt">Abandonner cet aperçu</button>
                </div>
            </div>

            <!-- ── Étape 3 : progression de l'écriture ─────────────────── -->
            <div id="anilist-import-step-running" class="anilist-import-step" style="display:none;">
                <div id="anilist-import-run-progress"></div>
            </div>

            <!-- ── Étape 4 : récapitulatif final ───────────────────────── -->
            <div id="anilist-import-step-done" class="anilist-import-step" style="display:none;">
                <div id="anilist-import-run-results"></div>
                <div class="tools-actions">
                    <button type="button" id="anilist-import-restart-btn" class="button button-opt">Lancer une nouvelle campagne</button>
                </div>
            </div>

        </div>

<?php
require __DIR__ . '/_tools-modals.php';

$tool_scripts = ['anilist-import.js'];
require __DIR__ . '/_layout_foot.php';
