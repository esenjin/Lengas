<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-groupage-licences.php — Outil « Groupage de licences »
//
// Suggère des regroupements de séries sans licence qui semblent appartenir à
// la même œuvre (titre + titres alternatifs Anilist + auteur/studio commun),
// à valider une par une : création d'une nouvelle licence ou rattachement à
// une licence existante. Analyse entièrement locale (aucun appel réseau),
// donc pas de flux SSE contrairement aux autres outils : une seule requête
// suffit, même sur une grosse collection.
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';
require_once 'fonctions/licenses.php';

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Analyse : renvoie les groupes suggérés pour un seuil donné ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'analyze') {
    $threshold = isset($_GET['threshold']) ? (float)$_GET['threshold'] : 50.0;
    $threshold = max(0.0, min(100.0, $threshold));

    $groups = find_license_grouping_suggestions($data, $threshold);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'groups'  => $groups,
        'total'   => count($groups),
    ]);
    exit;
}

// ── Diagnostic : détail du calcul de score entre deux séries (débogage) ─────
// Endpoint discret, sans lien dans l'UI : utile pour investiguer un score
// suspect en le comparant précisément, sans devoir relire tout le code.
// Usage : ?action=debug_pair&a=ID1&b=ID2
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'debug_pair') {
    $id_a = trim($_GET['a'] ?? '');
    $id_b = trim($_GET['b'] ?? '');
    $sa = null; $sb = null;
    foreach ($data as $s) {
        if ($s['id'] === $id_a) $sa = $s;
        if ($s['id'] === $id_b) $sb = $s;
    }

    header('Content-Type: application/json');
    if (!$sa || !$sb) {
        echo json_encode(['success' => false, 'message' => 'Série(s) introuvable(s).']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'series_a' => ['name' => $sa['name'], 'type' => series_type($sa), 'variants' => grouping_title_variants($sa)],
        'series_b' => ['name' => $sb['name'], 'type' => series_type($sb), 'variants' => grouping_title_variants($sb)],
        'title_similarity' => grouping_title_similarity($sa, $sb),
        'secondary_bonus'  => grouping_secondary_bonus($sa, $sb),
        'final_score'      => grouping_pair_score($sa, $sb),
        'php_version'      => PHP_VERSION,
    ]);
    exit;
}

// ── Détail léger d'une licence existante (modale de consultation) ───────────
// Réutilise get_license_detail() déjà fournie par fonctions/licenses.php.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'license_detail') {
    $license_id = trim($_GET['license_id'] ?? '');
    $detail = get_license_detail($data, $license_id);

    header('Content-Type: application/json');
    if (!$detail) {
        echo json_encode(['success' => false, 'message' => 'Licence introuvable.']);
        exit;
    }

    $series_out = [];
    foreach (($detail['series'] ?? []) as $m) {
        $series_out[] = [
            'id'     => $m['id'],
            'name'   => $m['name'],
            'type'   => $m['type'] ?? '',
        ];
    }
    usort($series_out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

    echo json_encode([
        'success' => true,
        'license' => [
            'id'     => $detail['id'],
            'name'   => $detail['name'],
            'series' => $series_out,
        ],
    ]);
    exit;
}

// ── Détail léger d'une série (modale de consultation, lecture seule) ────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'series_detail') {
    $series_id = trim($_GET['series_id'] ?? '');
    $found = null;
    foreach ($data as $s) {
        if ($s['id'] === $series_id) { $found = $s; break; }
    }

    header('Content-Type: application/json');
    if (!$found) {
        echo json_encode(['success' => false, 'message' => 'Série introuvable.']);
        exit;
    }

    $is_anime_series = is_anime($found);
    $license_map = get_series_license_map();
    $license = $license_map[$found['id']] ?? null;

    $categories = [];
    if (!$is_anime_series) {
        $categories = array_values(array_filter(array_map('trim', (array)($found['categories'] ?? [])), fn($c) => $c !== ''));
    }

    echo json_encode([
        'success' => true,
        'series'  => [
            'id'               => $found['id'],
            'name'             => $found['name'],
            'type'             => series_type($found),
            'thumbnail'        => function_exists('series_thumbnail') ? series_thumbnail($found) : '',
            'detail'           => $is_anime_series ? series_studios_text($found) : (string)($found['author'] ?? ''),
            'categories'       => $categories,
            'mangaupdates_url' => (string)($found['mangaupdates_url'] ?? ''),
            'babelio_url'      => (string)($found['babelio_url'] ?? ''),
            'anilist_url'      => (string)($found['anilist_url'] ?? ''),
            'license_name'     => $license['license_name'] ?? null,
        ],
    ]);
    exit;
}

// ── Actions POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tool_action'])) {
    $response = ['success' => false, 'message' => 'Action inconnue.'];

    switch ($_POST['tool_action']) {
        // Crée une nouvelle licence à partir d'un groupe suggéré (ou d'une
        // sélection partielle de ce groupe) et y ajoute les séries fournies.
        case 'create_from_group':
            $name       = trim($_POST['name'] ?? '');
            $series_ids = $_POST['series_ids'] ?? [];
            if (!is_array($series_ids)) $series_ids = [];

            if (empty($series_ids)) {
                $response = ['success' => false, 'message' => 'Aucune série sélectionnée.'];
                break;
            }

            $created = create_license($name);
            if (empty($created['success'])) {
                $response = $created;
                break;
            }

            $license_id = $created['id'];
            $failed = [];
            foreach ($series_ids as $sid) {
                $add = add_series_to_license($data, $license_id, trim((string)$sid));
                if (empty($add['success'])) $failed[] = $sid;
            }

            $response = [
                'success'    => true,
                'message'    => empty($failed) ? 'Licence créée.' : 'Licence créée, mais certaines séries n\'ont pas pu être ajoutées.',
                'license_id' => $license_id,
                'failed'     => $failed,
            ];
            break;

        // Ajoute une sélection de séries d'un groupe suggéré à une licence
        // déjà existante.
        case 'add_to_existing':
            $license_id = trim($_POST['license_id'] ?? '');
            $series_ids = $_POST['series_ids'] ?? [];
            if (!is_array($series_ids)) $series_ids = [];

            if ($license_id === '') {
                $response = ['success' => false, 'message' => 'Aucune licence sélectionnée.'];
                break;
            }
            if (empty($series_ids)) {
                $response = ['success' => false, 'message' => 'Aucune série sélectionnée.'];
                break;
            }

            $failed = [];
            foreach ($series_ids as $sid) {
                $add = add_series_to_license($data, $license_id, trim((string)$sid));
                if (empty($add['success'])) $failed[] = $sid;
            }

            $response = [
                'success' => true,
                'message' => empty($failed) ? 'Séries ajoutées à la licence.' : 'Certaines séries n\'ont pas pu être ajoutées.',
                'failed'  => $failed,
            ];
            break;

        // Liste des licences existantes, pour peupler le sélecteur de la
        // modale « Ajouter à une licence existante ».
        case 'list_licenses':
            $licenses = list_licenses($data, 'name', 'asc');
            $response = [
                'success'  => true,
                'licenses' => array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'count' => $l['count']], $licenses),
            ];
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// ── Repère de calibrage, calculé une fois au chargement de la page ─────────
$grouping_calibration = grouping_calibration_reference($data);

$tool_title    = 'Groupage de licences';
$tool_subtitle = 'Repère les séries sans licence qui semblent appartenir à la même œuvre, à regrouper en un clic.';
require __DIR__ . '/_layout_head.php';
?>

        <div class="tools-section">
            <h2>Rechercher des regroupements possibles</h2>
            <p>Compare le nom (et, pour les animés, les titres alternatifs Anilist) de chaque série sans licence, avec un bonus si deux mangas partagent le même auteur ou si deux animés partagent le même studio. Aucun appel réseau : l'analyse est instantanée et peut être relancée à volonté.</p>

            <div class="grouping-threshold-wrap">
                <label for="grouping-threshold" class="grouping-threshold-label">
                    Seuil de similarité : <strong id="grouping-threshold-value">50</strong>%
                </label>
                <input type="range" id="grouping-threshold" min="20" max="100" step="1" value="50">
                <?php if ($grouping_calibration !== null): ?>
                <p class="hint">Repère : vos licences existantes ont un score moyen de <strong><?= htmlspecialchars((string)$grouping_calibration) ?>%</strong> entre leurs séries membres.</p>
                <?php else: ?>
                <p class="hint">Aucune licence existante à au moins deux séries pour l'instant : pas de repère de calibrage disponible.</p>
                <?php endif; ?>
            </div>

            <button id="grouping-analyze-btn" class="button button-opt">
                <span id="grouping-analyze-text">Analyser</span>
                <span id="grouping-analyze-spinner" class="spinner" style="display: none;"></span>
            </button>
            <div id="grouping-results"></div>
        </div>

<?php
// ── Modale : créer une licence à partir d'un groupe ──────────────────────────
?>
<div class="modal" id="grouping-create-modal">
    <div class="modal-content modal-content--narrow">
        <span class="close-modal" id="close-grouping-create-modal">&times;</span>
        <h2>Créer une licence</h2>
        <input type="hidden" id="grouping-create-series-ids">
        <input type="text" id="grouping-create-name-input" placeholder="Nom de la licence" autocomplete="off">
        <div id="grouping-create-series-list" class="grouping-modal-series-list"></div>
        <div class="modal-actions">
            <button id="grouping-create-save-btn" class="button button-ats">
                <span id="grouping-create-save-text">Créer</span>
                <span id="grouping-create-save-spinner" class="spinner" style="display:none;"></span>
            </button>
        </div>
        <p id="grouping-create-feedback" class="cedit-feedback"></p>
    </div>
</div>

<!-- Modale : ajouter à une licence existante -->
<div class="modal" id="grouping-add-modal">
    <div class="modal-content modal-content--narrow">
        <span class="close-modal" id="close-grouping-add-modal">&times;</span>
        <h2>Ajouter à une licence existante</h2>
        <input type="hidden" id="grouping-add-series-ids">
        <select id="grouping-add-license-select"></select>
        <div id="grouping-add-series-list" class="grouping-modal-series-list"></div>
        <div class="modal-actions">
            <button id="grouping-add-save-btn" class="button button-ats">
                <span id="grouping-add-save-text">Ajouter</span>
                <span id="grouping-add-save-spinner" class="spinner" style="display:none;"></span>
            </button>
        </div>
        <p id="grouping-add-feedback" class="cedit-feedback"></p>
    </div>
</div>

<!-- Modale : détail léger d'une série, lecture seule (consultation avant décision) -->
<div class="modal" id="grouping-series-detail-modal">
    <div class="modal-content modal-content--narrow">
        <span class="close-modal" id="close-grouping-series-detail-modal">&times;</span>
        <div id="grouping-series-detail-body" class="grouping-series-detail-body">
            <!-- Rempli dynamiquement -->
        </div>
    </div>
</div>

<!-- Modale : détail léger d'une licence existante, lecture seule (liste des séries déjà incluses) -->
<div class="modal" id="grouping-license-detail-modal">
    <div class="modal-content modal-content--narrow">
        <span class="close-modal" id="close-grouping-license-detail-modal">&times;</span>
        <div id="grouping-license-detail-body" class="grouping-license-detail-body">
            <!-- Rempli dynamiquement -->
        </div>
    </div>
</div>

<?php
require __DIR__ . '/_tools-modals.php';

$tool_scripts = ['grouping.js'];
require __DIR__ . '/_layout_foot.php';
