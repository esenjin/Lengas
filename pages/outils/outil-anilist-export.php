<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-anilist-export.php — Outil « Export vers Anilist »
//
// Génère, pour les animés et pour les mangas/light-novels, un fichier
// d'export au format MyAnimeList (XML), le seul qu'Anilist accepte en import
// (https://anilist.co/settings/import). Voir fonctions/tools/anilist_export.php
// pour le principe détaillé.
//
// Strictement en LECTURE vis-à-vis de la collection : seule l'option
// `anilist_export_matches` (associations série ↔ fiche Anilist) est écrite.
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

$include_wishlist_req = function (array $src): bool {
    return ($src['include_wishlist'] ?? '1') === '1';
};

// Retrouve la cible désignée par sa clé (collection ou liste d'envies).
$find_target = function (string $key) use ($data): ?array {
    foreach (anilist_export_targets($data, load_wishlist(), true) as $t) {
        if ($t['key'] === $key) return $t;
    }
    return null;
};

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Endpoint : aperçu à partir des associations déjà mémorisées ─────────────
// Aucun appel réseau : permet d'afficher immédiatement l'aperçu au
// rechargement de la page si une préparation a déjà eu lieu.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'anilist_export_preview') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $targets = anilist_export_targets($data, load_wishlist(), $include_wishlist_req($_GET));
    echo json_encode(array_merge(['success' => true], anilist_export_build_preview($targets)));
    exit;
}

// ── Endpoint SSE : préparation (association aux fiches Anilist) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'anilist_export_prepare_stream') {
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

    // Une préparation peut durer plusieurs minutes (une recherche par manga) :
    // on libère le verrou de session pour ne pas bloquer les autres onglets
    // de l'administration pendant ce temps.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $targets = anilist_export_targets($data, load_wishlist(), $include_wishlist_req($_GET));
    $retry   = ($_GET['retry'] ?? '0') === '1';

    $result = anilist_export_prepare($targets, $retry, function (string $message, int $current, int $total) use ($sse) {
        $sse('progress', ['message' => $message, 'current' => $current, 'total' => $total]);
    });

    $sse('done', array_merge(
        ['success' => true, 'errors' => $result['errors']],
        anilist_export_build_preview($targets)
    ));
    exit;
}

// ── Endpoint : association manuelle (URL / identifiant Anilist) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tool_action'] ?? '') === 'anilist_export_set_match') {
    header('Content-Type: application/json; charset=utf-8');
    $key    = trim((string)($_POST['key'] ?? ''));
    $target = $key !== '' ? $find_target($key) : null;
    if ($target === null) {
        echo json_encode(['success' => false, 'message' => "Série introuvable."]);
        exit;
    }
    if ($target['anilist_id'] > 0) {
        // Animé lié à Anilist : l'identifiant fait autorité (il se corrige
        // depuis la fiche de la série, pas depuis l'export).
        echo json_encode(['success' => false, 'message' => "Cette série est déjà liée à sa fiche Anilist : l'association ne se modifie pas ici."]);
        exit;
    }
    $res = anilist_export_set_manual_match($target, (string)($_POST['value'] ?? ''));
    if (!$res['ok']) {
        echo json_encode(['success' => false, 'message' => $res['message']]);
        exit;
    }
    echo json_encode(['success' => true, 'entry' => anilist_export_entry($target, $res['match'])]);
    exit;
}

// ── Endpoint : oubli de toutes les associations mémorisées ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tool_action'] ?? '') === 'anilist_export_reset') {
    anilist_export_clear_matches();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true]);
    exit;
}

// ── Endpoint : téléchargement du fichier XML ─────────────────────────────────
// Soumis par un formulaire classique (et non un fetch) pour que le navigateur
// gère lui-même le téléchargement.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tool_action'] ?? '') === 'anilist_export_download') {
    $kind     = ($_POST['kind'] ?? '') === 'anime' ? 'anime' : 'manga';
    $excluded = isset($_POST['excluded']) ? array_map('strval', (array)$_POST['excluded']) : [];
    $scores   = ($_POST['include_scores'] ?? '1') === '1';

    $targets = anilist_export_targets($data, load_wishlist(), $include_wishlist_req($_POST));
    $built   = anilist_export_build_xml($targets, $kind, $excluded, $scores);

    if ($built['count'] === 0) {
        $_SESSION['error_message'] = $kind === 'anime'
            ? "Aucun animé exportable : rien à télécharger."
            : "Aucun manga exportable : rien à télécharger.";
        header('Location: outil-anilist-export.php');
        exit;
    }

    $filename = 'lengas-anilist-' . $kind . '-' . date('Ymd-His') . '.xml';
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($built['xml']));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $built['xml'];
    exit;
}

$flash_error = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

$tool_title    = 'Export vers Anilist';
$tool_subtitle = 'Crée des fichiers à importer sur Anilist depuis votre collection (animés et mangas).';
require __DIR__ . '/_layout_head.php';
?>

        <div class="tools-section">
            <h2>Exporter vers Anilist</h2>
            <p>Anilist n'accepte qu'un seul format d'import : celui des exports <strong>MyAnimeList</strong> (XML), avec un fichier pour les animés et un autre pour les mangas. L'outil associe d'abord chaque série à sa fiche Anilist (et donc à son identifiant MyAnimeList), puis génère les fichiers à partir de vos statuts de lecture/visionnage, de votre progression, de vos dates et de vos relectures/revisionnages.</p>
            <ul class="hint anilist-export-hints">
                <li><strong>Animés</strong> : déjà liés à Anilist, l'association est exacte.</li>
                <li><strong>Mangas / light-novels</strong> : Lengas ne connaît pas leur fiche Anilist, une recherche par titre (et auteur) est faite. Les correspondances incertaines sont décochées par défaut et chaque association peut être corrigée à la main. Les associations sont mémorisées pour les exports suivants.</li>
                <li>Une fiche Anilist sans équivalent MyAnimeList ne peut pas passer par ce format : elle est signalée « non exportable ».</li>
                <li>Rien n'est modifié dans votre collection.</li>
            </ul>

            <?php if ($flash_error !== ''): ?>
                <p class="anilist-import-feedback is-error"><?= htmlspecialchars($flash_error) ?></p>
            <?php endif; ?>

            <!-- ── Étape 1 : réglages + préparation ─────────────────────── -->
            <div id="anilist-export-step-setup" class="anilist-import-step">
                <div class="anilist-import-settings-block">
                    <h3>Contenu de l'export</h3>
                    <label class="anilist-import-checkbox-option">
                        <input type="checkbox" id="anilist-export-include-wishlist" checked>
                        Inclure la liste d'envies (exportée en « À voir » / « À lire »)
                    </label>
                    <label class="anilist-import-checkbox-option">
                        <input type="checkbox" id="anilist-export-include-scores" checked>
                        Exporter la notation (☺️ → 8/10, 😑 → 5/10, 😠 → 3/10)
                    </label>
                </div>

                <p id="anilist-export-estimate" class="hint"></p>

                <div class="tools-actions">
                    <button type="button" id="anilist-export-prepare-btn" class="button button-ats">
                        <span id="anilist-export-prepare-text">Préparer l'export</span>
                        <span id="anilist-export-prepare-spinner" class="spinner" style="display:none;"></span>
                    </button>
                    <button type="button" id="anilist-export-retry-btn" class="button button-opt" style="display:none;">Relancer les recherches sans correspondance fiable</button>
                    <button type="button" id="anilist-export-reset-btn" class="button button-opt" style="display:none;">Oublier toutes les associations</button>
                </div>
                <div id="anilist-export-progress"></div>
                <p id="anilist-export-feedback" class="anilist-import-feedback"></p>
            </div>

            <!-- ── Étape 2 : aperçu ─────────────────────────────────────── -->
            <div id="anilist-export-step-preview" class="anilist-import-step" style="display:none;">
                <div id="anilist-export-panels"></div>

                <div class="anilist-import-settings-block">
                    <h3>Importer sur Anilist</h3>
                    <ol class="anilist-export-howto">
                        <li>Téléchargez le ou les fichiers ci-dessus.</li>
                        <li>Sur Anilist, ouvrez <a href="https://anilist.co/settings/import" target="_blank" rel="noopener noreferrer">Paramètres → Import</a>.</li>
                        <li>Dans la section MyAnimeList, importez le fichier des animés dans « Anime List » et celui des mangas dans « Manga List ».</li>
                    </ol>
                    <p class="hint">Anilist traite l'import en arrière-plan : la liste peut mettre quelques minutes à apparaître sur votre profil.</p>
                </div>
            </div>
        </div>

        <!-- Formulaire de téléchargement (rempli par le script) -->
        <form id="anilist-export-download-form" method="post" action="outil-anilist-export.php" style="display:none;">
            <input type="hidden" name="tool_action" value="anilist_export_download">
        </form>

<?php
require __DIR__ . '/_tools-modals.php';

$tool_scripts = ['anilist-export.js'];
require __DIR__ . '/_layout_foot.php';
