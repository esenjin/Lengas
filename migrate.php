<?php
// ──────────────────────────────────────────────────────────────────────────────
// migrate.php — DOIT ÊTRE EN TOUT PREMIER, avant tout HTML
// ──────────────────────────────────────────────────────────────────────────────
ob_start(); // Capture tout output parasite (notices, warnings, BOM...)

set_time_limit(120);

define('OLD_DATA_FILE',     'bdd/data.json');
define('OLD_OPTIONS_FILE',  'bdd/options.json');
define('OLD_LOAN_FILE',     'bdd/loan.json');
define('OLD_WISHLIST_FILE', 'bdd/list.json');
define('OLD_READ_FILE',     'bdd/read.json');
define('OLD_ANILIST_FILE',  'bdd/anilist.json');
define('OLD_PASSWORD_FILE', 'bdd/mdp.json');
define('DB_FILE',           'bdd/lengas.db');

$json_files    = [OLD_DATA_FILE, OLD_OPTIONS_FILE, OLD_LOAN_FILE, OLD_WISHLIST_FILE, OLD_READ_FILE, OLD_PASSWORD_FILE];
$json_exist    = array_filter($json_files, 'file_exists');
$db_already_ok = file_exists(DB_FILE) && filesize(DB_FILE) > 4096;
$action        = $_POST['action'] ?? '';

// ──────────────────────────────────────────────────────────────────────────────
// TRAITEMENT AJAX — répond en JSON et sort immédiatement
// ──────────────────────────────────────────────────────────────────────────────
if ($action && isset($_POST['step'])) {
    ob_clean(); // Vide tout output accumulé avant d'envoyer les headers
    header('Content-Type: application/json; charset=utf-8');

    $step   = (int)$_POST['step'];
    $result = ['ok' => false, 'msg' => '', 'detail' => ''];

    try {
        switch ($step) {

            // ── Étape 1 : vérifications préalables ───────────────────────────
            case 1:
                if (empty($json_exist)) {
                    $result['ok']   = true;
                    $result['skip'] = true;
                    $result['msg']  = "Aucun fichier JSON trouvé — déjà migré ou installation vierge.";
                    break;
                }
                if (!extension_loaded('pdo_sqlite')) {
                    throw new RuntimeException("L'extension PHP pdo_sqlite n'est pas activée sur ce serveur.");
                }
                if (!is_writable('bdd/')) {
                    throw new RuntimeException("Le dossier bdd/ n'est pas accessible en écriture.");
                }
                $result['ok']     = true;
                $result['msg']    = "Vérifications OK.";
                $result['detail'] = count($json_exist) . " fichier(s) JSON détecté(s).";
                break;

            // ── Étape 2 : lire les données JSON ──────────────────────────────
            case 2:
                $data = [];
                if (file_exists(OLD_DATA_FILE)) {
                    $raw = file_get_contents(OLD_DATA_FILE);
                    if ($raw === false) throw new RuntimeException("Impossible de lire data.json.");
                    $data = json_decode($raw, true) ?? [];
                }
                $result['ok']     = true;
                $result['msg']    = "Données lues avec succès.";
                $result['detail'] = count($data) . " série(s) dans la bibliothèque.";
                break;

            // ── Étape 3 : créer la base SQLite & le schéma ───────────────────
            case 3:
                if (file_exists(DB_FILE)) {
                    rename(DB_FILE, DB_FILE . '.bak.' . time());
                }
                $pdo = new PDO('sqlite:' . DB_FILE);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA synchronous = NORMAL');
                $pdo->exec('PRAGMA foreign_keys = ON');

                $pdo->exec("CREATE TABLE IF NOT EXISTS series (
                    id TEXT PRIMARY KEY,
                    name TEXT NOT NULL,
                    author TEXT NOT NULL DEFAULT '',
                    publisher TEXT NOT NULL DEFAULT '',
                    other_contributors TEXT NOT NULL DEFAULT '',
                    categories TEXT NOT NULL DEFAULT '',
                    genres TEXT NOT NULL DEFAULT '',
                    image TEXT NOT NULL DEFAULT '',
                    anilist_id TEXT NOT NULL DEFAULT '',
                    mature INTEGER NOT NULL DEFAULT 0,
                    favorite INTEGER NOT NULL DEFAULT 0,
                    status TEXT NOT NULL DEFAULT 'en cours'
                )");

                $pdo->exec("CREATE TABLE IF NOT EXISTS volumes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    series_id TEXT NOT NULL REFERENCES series(id) ON DELETE CASCADE,
                    number INTEGER NOT NULL,
                    status TEXT NOT NULL DEFAULT 'a lire',
                    collector INTEGER NOT NULL DEFAULT 0,
                    last INTEGER NOT NULL DEFAULT 0,
                    added_at TEXT NOT NULL DEFAULT '',
                    UNIQUE(series_id, number)
                )");

                $pdo->exec("CREATE TABLE IF NOT EXISTS wishlist (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    author TEXT NOT NULL DEFAULT '',
                    publisher TEXT NOT NULL DEFAULT ''
                )");

                $pdo->exec("CREATE TABLE IF NOT EXISTS loans (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    series_id TEXT NOT NULL,
                    volume_number INTEGER NOT NULL,
                    borrower_name TEXT NOT NULL,
                    loan_date TEXT NOT NULL,
                    UNIQUE(series_id, volume_number)
                )");

                $pdo->exec("CREATE TABLE IF NOT EXISTS read_elsewhere (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    author TEXT NOT NULL DEFAULT '',
                    publisher TEXT NOT NULL DEFAULT '',
                    volumes_read INTEGER NOT NULL DEFAULT 0,
                    status TEXT NOT NULL DEFAULT '',
                    added_at TEXT NOT NULL DEFAULT ''
                )");

                $pdo->exec("CREATE TABLE IF NOT EXISTS options (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL DEFAULT ''
                )");

                $pdo->exec("CREATE TABLE IF NOT EXISTS anilist_cache (
                    cache_key TEXT PRIMARY KEY,
                    volumes INTEGER,
                    timestamp INTEGER NOT NULL
                )");

                $pdo->exec("CREATE TABLE IF NOT EXISTS password (
                    id INTEGER PRIMARY KEY CHECK (id = 1),
                    hash TEXT NOT NULL
                )");

                $result['ok']  = true;
                $result['msg'] = "Base SQLite créée et schéma initialisé.";
                break;

            // ── Étape 4 : importer les séries & volumes ───────────────────────
            case 4:
                if (!file_exists(OLD_DATA_FILE)) {
                    $result['ok'] = true;
                    $result['msg'] = "Pas de données à importer (data.json absent).";
                    break;
                }
                $data = json_decode(file_get_contents(OLD_DATA_FILE), true) ?? [];

                $pdo = new PDO('sqlite:' . DB_FILE);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA foreign_keys = ON');

                $stmtS = $pdo->prepare("INSERT OR IGNORE INTO series
                    (id, name, author, publisher, other_contributors, categories, genres, image, anilist_id, mature, favorite, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

                $stmtV = $pdo->prepare("INSERT OR IGNORE INTO volumes
                    (series_id, number, status, collector, last, added_at)
                    VALUES (?,?,?,?,?,?)");

                $pdo->beginTransaction();
                $nb_series = 0;
                $nb_volumes = 0;

                foreach ($data as $s) {
                    $id = $s['id'] ?? null;
                    if (!$id) continue;
                    $stmtS->execute([
                        $id,
                        $s['name']              ?? '',
                        $s['author']            ?? '',
                        $s['publisher']         ?? '',
                        implode(',', $s['other_contributors'] ?? ['']),
                        implode(',', $s['categories']         ?? ['']),
                        implode(',', $s['genres']             ?? ['']),
                        $s['image']             ?? '',
                        $s['anilist_id']        ?? '',
                        (int)($s['mature']      ?? false),
                        (int)($s['favorite']    ?? false),
                        $s['status']            ?? 'en cours',
                    ]);
                    $nb_series++;

                    foreach ($s['volumes'] ?? [] as $v) {
                        $stmtV->execute([
                            $id,
                            (int)$v['number'],
                            $v['status']             ?? 'a lire',
                            (int)($v['collector']    ?? false),
                            (int)($v['last']         ?? false),
                            $v['added_at']           ?? date('Y-m-d'),
                        ]);
                        $nb_volumes++;
                    }
                }
                $pdo->commit();

                $result['ok']     = true;
                $result['msg']    = "Séries et tomes importés.";
                $result['detail'] = "$nb_series série(s), $nb_volumes tome(s).";
                break;

            // ── Étape 5 : importer la wishlist ───────────────────────────────
            case 5:
                if (!file_exists(OLD_WISHLIST_FILE)) {
                    $result['ok'] = true;
                    $result['msg'] = "list.json absent, ignoré.";
                    break;
                }
                $wishlist = json_decode(file_get_contents(OLD_WISHLIST_FILE), true) ?? [];
                $pdo = new PDO('sqlite:' . DB_FILE);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO wishlist (name, author, publisher) VALUES (?,?,?)");
                $pdo->beginTransaction();
                foreach ($wishlist as $w) {
                    $stmt->execute([$w['name'] ?? '', $w['author'] ?? '', $w['publisher'] ?? '']);
                }
                $pdo->commit();
                $result['ok']     = true;
                $result['msg']    = "Liste d'envies importée.";
                $result['detail'] = count($wishlist) . " entrée(s).";
                break;

            // ── Étape 6 : importer les prêts ─────────────────────────────────
            case 6:
                if (!file_exists(OLD_LOAN_FILE)) {
                    $result['ok'] = true;
                    $result['msg'] = "loan.json absent, ignoré.";
                    break;
                }
                $loans = json_decode(file_get_contents(OLD_LOAN_FILE), true) ?? [];
                $pdo   = new PDO('sqlite:' . DB_FILE);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt  = $pdo->prepare("INSERT OR IGNORE INTO loans (series_id, volume_number, borrower_name, loan_date) VALUES (?,?,?,?)");
                $pdo->beginTransaction();
                foreach ($loans as $l) {
                    $stmt->execute([
                        $l['series_id']     ?? '',
                        (int)($l['volume_number'] ?? 0),
                        $l['borrower_name'] ?? '',
                        $l['loan_date']     ?? date('Y-m-d H:i:s'),
                    ]);
                }
                $pdo->commit();
                $result['ok']     = true;
                $result['msg']    = "Prêts importés.";
                $result['detail'] = count($loans) . " prêt(s).";
                break;

            // ── Étape 7 : importer les lues ailleurs ─────────────────────────
            case 7:
                if (!file_exists(OLD_READ_FILE)) {
                    $result['ok'] = true;
                    $result['msg'] = "read.json absent, ignoré.";
                    break;
                }
                $read = json_decode(file_get_contents(OLD_READ_FILE), true) ?? [];
                $pdo  = new PDO('sqlite:' . DB_FILE);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO read_elsewhere (name, author, publisher, volumes_read, status, added_at) VALUES (?,?,?,?,?,?)");
                $pdo->beginTransaction();
                foreach ($read as $r) {
                    $stmt->execute([
                        $r['name']      ?? '',
                        $r['author']    ?? '',
                        $r['publisher'] ?? '',
                        (int)($r['volumes_read'] ?? 0),
                        $r['status']    ?? '',
                        $r['added_at']  ?? date('Y-m-d'),
                    ]);
                }
                $pdo->commit();
                $result['ok']     = true;
                $result['msg']    = "Lues ailleurs importées.";
                $result['detail'] = count($read) . " entrée(s).";
                break;

            // ── Étape 8 : importer les options ───────────────────────────────
            case 8:
                if (!file_exists(OLD_OPTIONS_FILE)) {
                    $result['ok'] = true;
                    $result['msg'] = "options.json absent, ignoré.";
                    break;
                }
                $options = json_decode(file_get_contents(OLD_OPTIONS_FILE), true) ?? [];
                $pdo     = new PDO('sqlite:' . DB_FILE);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt    = $pdo->prepare("INSERT OR REPLACE INTO options (key, value) VALUES (?,?)");
                $pdo->beginTransaction();
                foreach ($options as $k => $v) {
                    $stmt->execute([$k, is_bool($v) ? (int)$v : $v]);
                }
                $pdo->commit();
                $result['ok']     = true;
                $result['msg']    = "Options importées.";
                $result['detail'] = count($options) . " clé(s).";
                break;

            // ── Étape 9 : importer le mot de passe ───────────────────────────
            case 9:
                if (!file_exists(OLD_PASSWORD_FILE)) {
                    $result['ok'] = true;
                    $result['msg'] = "mdp.json absent, ignoré.";
                    break;
                }
                $pwd_data = json_decode(file_get_contents(OLD_PASSWORD_FILE), true) ?? [];
                $hash     = $pwd_data['admin_password_hash'] ?? null;
                if ($hash) {
                    $pdo = new PDO('sqlite:' . DB_FILE);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $pdo->prepare("INSERT OR REPLACE INTO password (id, hash) VALUES (1, ?)")->execute([$hash]);
                    $result['ok']  = true;
                    $result['msg'] = "Mot de passe importé.";
                } else {
                    $result['ok']  = true;
                    $result['msg'] = "Aucun hash trouvé dans mdp.json.";
                }
                break;

            // ── Étape 10 : importer le cache Anilist ─────────────────────────
            case 10:
                if (!file_exists(OLD_ANILIST_FILE)) {
                    $result['ok'] = true;
                    $result['msg'] = "anilist.json absent, ignoré.";
                    break;
                }
                $cache = json_decode(file_get_contents(OLD_ANILIST_FILE), true) ?? [];
                $pdo   = new PDO('sqlite:' . DB_FILE);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt  = $pdo->prepare("INSERT OR REPLACE INTO anilist_cache (cache_key, volumes, timestamp) VALUES (?,?,?)");
                $pdo->beginTransaction();
                $nb = 0;
                foreach ($cache as $key => $entry) {
                    $stmt->execute([$key, $entry['volumes'] ?? null, $entry['timestamp'] ?? time()]);
                    $nb++;
                }
                $pdo->commit();
                $result['ok']     = true;
                $result['msg']    = "Cache Anilist importé.";
                $result['detail'] = "$nb entrée(s).";
                break;

            // ── Étape 11 : vérification de la base ───────────────────────────
            case 11:
                $pdo           = new PDO('sqlite:' . DB_FILE);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $series_count  = (int)$pdo->query("SELECT COUNT(*) FROM series")->fetchColumn();
                $volumes_count = (int)$pdo->query("SELECT COUNT(*) FROM volumes")->fetchColumn();
                $has_pwd       = (bool)$pdo->query("SELECT COUNT(*) FROM password")->fetchColumn();
                $result['ok']     = true;
                $result['msg']    = "Vérification réussie.";
                $result['detail'] = "$series_count série(s), $volumes_count tome(s). Mot de passe : " . ($has_pwd ? "présent" : "absent");
                break;

            // ── Étape 12 : suppression des fichiers JSON ──────────────────────
            case 12:
                $deleted   = [];
                $failed    = [];
                $to_delete = [
                    OLD_DATA_FILE, OLD_OPTIONS_FILE, OLD_LOAN_FILE,
                    OLD_WISHLIST_FILE, OLD_READ_FILE, OLD_ANILIST_FILE, OLD_PASSWORD_FILE,
                ];
                foreach ($to_delete as $f) {
                    if (file_exists($f)) {
                        if (unlink($f)) { $deleted[] = basename($f); }
                        else            { $failed[]  = basename($f); }
                    }
                }
                $result['ok']     = true;
                $result['msg']    = "Fichiers JSON supprimés.";
                $result['detail'] = empty($deleted)
                    ? "Aucun fichier trouvé."
                    : implode(', ', $deleted) . (empty($failed) ? '' : ". Echec : " . implode(', ', $failed));
                break;

            default:
                throw new RuntimeException("Étape inconnue.");
        }

    } catch (Exception $e) {
        $result['ok']  = false;
        $result['msg'] = $e->getMessage();
    }

    ob_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// AFFICHAGE HTML (requête normale, pas AJAX)
// ──────────────────────────────────────────────────────────────────────────────
ob_end_flush(); // Envoie le buffer HTML normalement
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lengas — Migration JSON → SQLite</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #0f1117;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .card {
            background: #1a1d27;
            border: 1px solid #2d3148;
            border-radius: 12px;
            padding: 2.5rem;
            max-width: 640px;
            width: 100%;
            box-shadow: 0 8px 32px rgba(0,0,0,.4);
        }

        h1 { font-size: 1.5rem; font-weight: 700; color: #a78bfa; margin-bottom: .4rem; }
        .subtitle { font-size: .9rem; color: #64748b; margin-bottom: 2rem; }

        .steps { display: flex; flex-direction: column; gap: .6rem; margin-bottom: 2rem; }

        .step {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .6rem .9rem;
            border-radius: 8px;
            background: #12151f;
            border: 1px solid #2d3148;
            font-size: .875rem;
            transition: background .3s, border-color .3s;
        }
        .step.pending  { opacity: .5; }
        .step.running  { background: #1e1b4b; border-color: #7c3aed; }
        .step.done     { background: #0f2e1a; border-color: #22c55e; }
        .step.error    { background: #2e0f0f; border-color: #ef4444; }
        .step.skipped  { background: #1a1d27; border-color: #475569; opacity: .7; }

        .step-icon  { font-size: 1.1rem; width: 1.4rem; text-align: center; flex-shrink: 0; }
        .step-label { flex: 1; }
        .step-detail { font-size: .78rem; color: #94a3b8; margin-top: .15rem; }

        .progress-wrap { margin-bottom: 1.5rem; }
        .progress-label { display: flex; justify-content: space-between; font-size: .8rem; color: #64748b; margin-bottom: .4rem; }
        .progress-bar   { height: 8px; background: #2d3148; border-radius: 99px; overflow: hidden; }
        .progress-fill  { height: 100%; background: linear-gradient(90deg,#7c3aed,#a78bfa); border-radius: 99px; width: 0%; transition: width .4s ease; }

        .alert { padding: .9rem 1rem; border-radius: 8px; font-size: .875rem; margin-top: 1rem; display: none; }
        .alert.success { background: #0f2e1a; border: 1px solid #22c55e; color: #86efac; display: block; }
        .alert.error   { background: #2e0f0f; border: 1px solid #ef4444; color: #fca5a5; display: block; }
        .alert.info    { background: #1e293b; border: 1px solid #475569; color: #cbd5e1; display: block; }
        .alert ul { margin-top: .5rem; padding-left: 1.2rem; }
        .alert li { margin-top: .2rem; }

        .btn { display: inline-flex; align-items: center; gap: .5rem; padding: .6rem 1.2rem; border-radius: 8px; font-size: .875rem; font-weight: 600; cursor: pointer; border: none; transition: opacity .2s; text-decoration: none; }
        .btn:hover { opacity: .85; }
        .btn-primary   { background: #7c3aed; color: #fff; }
        .btn-secondary { background: #2d3148; color: #e2e8f0; }
        .btn:disabled  { opacity: .4; cursor: not-allowed; }

        .actions { display: flex; gap: .75rem; margin-top: 1.5rem; flex-wrap: wrap; }

        .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid transparent; border-top-color: currentColor; border-radius: 50%; animation: spin .7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        code { font-family: monospace; background: #2d3148; padding: .1rem .35rem; border-radius: 4px; font-size: .85em; }
    </style>
</head>
<body>
<div class="card">
    <h1>🔄 Migration Lengas</h1>
    <p class="subtitle">Migration de la base de données JSON → SQLite</p>

    <?php if ($db_already_ok && empty($json_exist)): ?>
    <div class="alert success">
        ✅ La migration a déjà été effectuée. Le fichier <code>bdd/lengas.db</code> est présent et les fichiers JSON ont été supprimés.<br>
        <small style="color:#64748b">Vous pouvez supprimer ce fichier <code>migrate.php</code> de votre serveur.</small>
    </div>

    <?php elseif (empty($json_exist)): ?>
    <div class="alert info">
        ℹ️ Aucun fichier JSON trouvé dans <code>bdd/</code>. S'il s'agit d'une installation neuve, la base SQLite sera initialisée automatiquement au premier accès.
    </div>

    <?php else: ?>
    <div class="alert info" style="display:block;margin-bottom:1.5rem">
        📂 Fichiers JSON détectés :
        <ul>
            <?php foreach ($json_exist as $f): ?>
            <li><code><?= htmlspecialchars($f) ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="progress-wrap">
        <div class="progress-label">
            <span>Progression</span>
            <span id="progress-pct">0%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" id="progress-fill"></div>
        </div>
    </div>

    <div class="steps" id="steps-list">
        <div class="step pending" id="step-1"><span class="step-icon">🔍</span><div class="step-label"><div>Vérifications préalables</div><div class="step-detail" id="detail-1"></div></div></div>
        <div class="step pending" id="step-2"><span class="step-icon">📖</span><div class="step-label"><div>Lecture des données JSON</div><div class="step-detail" id="detail-2"></div></div></div>
        <div class="step pending" id="step-3"><span class="step-icon">🗄️</span><div class="step-label"><div>Création de la base SQLite &amp; du schéma</div><div class="step-detail" id="detail-3"></div></div></div>
        <div class="step pending" id="step-4"><span class="step-icon">📚</span><div class="step-label"><div>Import des séries &amp; tomes</div><div class="step-detail" id="detail-4"></div></div></div>
        <div class="step pending" id="step-5"><span class="step-icon">🌟</span><div class="step-label"><div>Import de la liste d'envies</div><div class="step-detail" id="detail-5"></div></div></div>
        <div class="step pending" id="step-6"><span class="step-icon">📤</span><div class="step-label"><div>Import des prêts</div><div class="step-detail" id="detail-6"></div></div></div>
        <div class="step pending" id="step-7"><span class="step-icon">📖</span><div class="step-label"><div>Import des lues ailleurs</div><div class="step-detail" id="detail-7"></div></div></div>
        <div class="step pending" id="step-8"><span class="step-icon">⚙️</span><div class="step-label"><div>Import des options</div><div class="step-detail" id="detail-8"></div></div></div>
        <div class="step pending" id="step-9"><span class="step-icon">🔒</span><div class="step-label"><div>Import du mot de passe</div><div class="step-detail" id="detail-9"></div></div></div>
        <div class="step pending" id="step-10"><span class="step-icon">💾</span><div class="step-label"><div>Import du cache Anilist</div><div class="step-detail" id="detail-10"></div></div></div>
        <div class="step pending" id="step-11"><span class="step-icon">✔️</span><div class="step-label"><div>Vérification de la base</div><div class="step-detail" id="detail-11"></div></div></div>
        <div class="step pending" id="step-12"><span class="step-icon">🗑️</span><div class="step-label"><div>Suppression des fichiers JSON</div><div class="step-detail" id="detail-12"></div></div></div>
    </div>

    <div id="result-alert" class="alert"></div>

    <div class="actions">
        <button class="btn btn-primary" id="btn-start" onclick="startMigration()">▶ Lancer la migration</button>
        <a class="btn btn-secondary" href="admin.php" id="btn-admin" style="display:none">Aller à l'administration →</a>
    </div>
    <?php endif; ?>
</div>

<script>
const TOTAL_STEPS = 12;
let aborted = false;

function setStep(n, state, detail) {
    const el   = document.getElementById('step-' + n);
    const icon = el.querySelector('.step-icon');
    const det  = document.getElementById('detail-' + n);
    el.className = 'step ' + state;
    const icons = { running: '<span class="spinner"></span>', done: '✅', error: '❌', skipped: '⏭️' };
    if (icons[state]) icon.innerHTML = icons[state];
    if (det && detail) det.textContent = detail;
    if (state === 'done' || state === 'skipped') {
        const pct = Math.round((n / TOTAL_STEPS) * 100);
        document.getElementById('progress-fill').style.width = pct + '%';
        document.getElementById('progress-pct').textContent  = pct + '%';
    }
}

async function runStep(n) {
    setStep(n, 'running', '');
    const fd = new FormData();
    fd.append('action', 'migrate');
    fd.append('step', n);
    const resp = await fetch('migrate.php', { method: 'POST', body: fd });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    const text = await resp.text();
    try {
        return JSON.parse(text);
    } catch(e) {
        // Affiche les 200 premiers caractères de la réponse pour debug
        throw new Error('Réponse non-JSON : ' + text.substring(0, 200));
    }
}

async function startMigration() {
    document.getElementById('btn-start').disabled = true;
    aborted = false;
    for (let s = 1; s <= TOTAL_STEPS; s++) {
        if (aborted) break;
        try {
            const res = await runStep(s);
            if (res.ok) {
                setStep(s, res.skip ? 'skipped' : 'done', res.detail || res.msg);
            } else {
                setStep(s, 'error', res.msg);
                aborted = true;
                showAlert('error', '❌ Erreur étape ' + s + ' : ' + res.msg);
                document.getElementById('btn-start').disabled = false;
                document.getElementById('btn-start').textContent = '↩ Réessayer';
                return;
            }
        } catch(e) {
            setStep(s, 'error', e.message);
            aborted = true;
            showAlert('error', '❌ Erreur étape ' + s + ' : ' + e.message);
            document.getElementById('btn-start').disabled = false;
            return;
        }
    }
    if (!aborted) {
        document.getElementById('progress-fill').style.width = '100%';
        document.getElementById('progress-pct').textContent  = '100%';
        showAlert('success', '🎉 Migration terminée ! <strong>Pensez à supprimer ce fichier migrate.php de votre serveur.</strong>');
        document.getElementById('btn-admin').style.display = 'inline-flex';
    }
}

function showAlert(type, html) {
    const el = document.getElementById('result-alert');
    el.className = 'alert ' + type;
    el.innerHTML = html;
    el.style.display = 'block';
}
</script>
</body>
</html>
