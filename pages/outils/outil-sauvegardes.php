<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-sauvegardes.php — Outil « Sauvegardes »
//
// Création et téléchargement d'archives ZIP des données, ainsi que l'export
// JSON complet (inclut les tables et vignettes propres à l'Animethèque).
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Téléchargement d'une archive ──────────────────────────────────────────────
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

// ── Actions POST (création, suppression, liste, export JSON) ─────────────────
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

$tool_title    = 'Sauvegardes';
$tool_subtitle = 'Créez, téléchargez et exportez vos données.';
require __DIR__ . '/_layout_head.php';
?>

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

<?php
require __DIR__ . '/_tools-modals.php';

$tool_scripts = ['backups.js'];
require __DIR__ . '/_layout_foot.php';
