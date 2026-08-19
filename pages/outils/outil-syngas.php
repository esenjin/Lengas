<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-syngas.php — Outil « Synchronisation Syngas »
//
// Deux sections dans une seule page, sur le modèle exact
// d'outil-associations-mu.php (section 6 du cahier des charges) :
//   6.1 Envoi     — séries manga sans syngas_uid, envoyées à Syngas après
//                    récapitulatif et confirmation explicite.
//   6.2 Réception — séries manga déjà liées, comparées champ par champ à
//                    leur fiche Syngas actuelle, validation sélective.
//
// Bannissement (6.5) : bandeau persistant, même comportement que la section
// « Recherche Syngas » des modales.
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

// ────────────────────────────────────────────────────────────────────────────
// Libération du verrou de session AVANT tout traitement long.
//
// PHP verrouille le fichier/la ligne de session pendant toute la durée du
// script tant que la session reste ouverte : sans cette libération, un envoi
// ou une réception de plusieurs dizaines de séries (chacune avec un aller-
// retour réseau vers Syngas) bloque TOUTE autre requête authentifiée de
// Lengas — y compris dans un autre onglet, ou le rafraîchissement du menu
// latéral — jusqu'à expiration de leur propre délai d'attente, ce qui peut
// se traduire par des déconnexions ou des échecs qui n'ont rien à voir avec
// Syngas lui-même. _bootstrap.php inclut includes/auth.php, qui a déjà lu et
// vérifié $_SESSION à ce stade (refresh_session_cookie() y a aussi déjà
// tourné) : aucun endpoint de ce fichier n'a besoin d'écrire en session
// ensuite, la fermer ici est donc sans danger.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// ── Endpoint : revérification manuelle du bannissement ─────────────────────
// Déclenché par le bouton « Revérifier maintenant » du bandeau : force une
// vraie vérification réseau (recherche anodine avec la clé déjà enregistrée)
// pour trancher un flag local qui pourrait être resté actif à tort après un
// simple aléa réseau passé (voir syngas_reverify_ban() dans includes/syngas.php).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tool_action'] ?? '') === 'syngas_recheck_ban') {
    header('Content-Type: application/json');
    $still_banned = syngas_reverify_ban();
    echo json_encode([
        'success' => true,
        'banned'  => $still_banned,
        'reason'  => $still_banned ? syngas_banned_reason() : '',
    ]);
    exit;
}

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Endpoint : résolution automatique des soumissions en attente ───────────
// Appelé au chargement de la page (avant même d'afficher les sections) : une
// soumission déposée lors d'une précédente visite peut avoir été traitée par
// un modérateur Syngas entre-temps (creee/fusionnee) — syngas_uid est alors
// posé automatiquement, sans action de l'utilisateur.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'syngas_resolve_pending') {
    header('Content-Type: application/json');

    // syngas_is_banned() revérifie déjà elle-même l'état si le flag a
    // dépassé sa durée de vie (SYNGAS_BAN_FLAG_TTL, voir includes/syngas.php)
    // : pas besoin d'un second appel explicite à syngas_reverify_ban() ici,
    // ce serait un aller-retour réseau redondant à chaque chargement de page.
    if (syngas_is_banned()) {
        echo json_encode([
            'success' => false,
            'banned'  => true,
            'message' => 'La connexion de ce site à Syngas a été suspendue.',
            'reason'  => syngas_banned_reason(),
        ]);
        exit;
    }

    $result = syngas_resolve_tracked_submissions($data);
    echo json_encode(['success' => true] + $result);
    exit;
}

// ── Endpoint SSE : envoi des séries éligibles ───────────────────────────────
// Récap listant les séries qui SERONT envoyées (nom, auteur, éditeur) —
// rien n'est envoyé sans confirmation explicite (section 6.1, point 3). Cet
// endpoint ne fait donc AUCUNE écriture réseau : il liste seulement les
// cibles et les exclusions. L'envoi réel se fait via l'endpoint POST suivant,
// après confirmation.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'syngas_send_preview') {
    header('Content-Type: application/json');

    $targets  = syngas_sync_send_targets($data);
    $excluded = syngas_sync_excluded_targets($data);

    echo json_encode([
        'success'  => true,
        'targets'  => array_map(fn($s) => [
            'id'        => $s['id'],
            'name'      => $s['name'],
            'author'    => $s['author'] ?? '',
            'publisher' => $s['publisher'] ?? '',
        ], $targets),
        'excluded_count' => count($excluded),
    ]);
    exit;
}

// ── Endpoint : persistance de la sélection avant l'envoi (POST) ────────────
// EventSource ne pouvant faire que des requêtes GET, la liste des séries
// cochées (potentiellement nombreuse) transite ici par un POST classique
// plutôt que par l'URL du flux SSE qui suit — voir la note détaillée dans
// fonctions/tools/syngas.php (syngas_save_send_state()) : une URL trop
// longue échoue avant même d'atteindre PHP, ce qui se traduisait par un
// échec immédiat et silencieux du flux, sans rien envoyer à Syngas.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tool_action'] ?? '') === 'syngas_send_settings_save') {
    header('Content-Type: application/json');
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    syngas_save_send_state(array_values(array_filter(array_map('strval', $ids))));
    echo json_encode(['success' => true]);
    exit;
}

// ── Endpoint SSE : envoi effectif, après confirmation ───────────────────────
// Lit la sélection persistée par syngas_send_settings_save juste avant
// (aucun paramètre dans l'URL du flux lui-même, qui reste toujours courte
// quel que soit le nombre de séries cochées).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'syngas_send_stream') {
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

    $ids = array_flip(syngas_load_send_state());
    syngas_clear_send_state(); // consommée : une prochaine visite ne doit pas la rejouer

    $targets = syngas_sync_send_targets($data);
    if (!empty($ids)) {
        $targets = array_values(array_filter($targets, fn($s) => isset($ids[$s['id']])));
    }

    // syngas_is_banned() revérifie déjà elle-même l'état si le flag a expiré
    // (voir includes/syngas.php) : pas de second appel réseau explicite ici.
    if (syngas_is_banned()) {
        $sse('banned', ['message' => 'La connexion de ce site à Syngas a été suspendue.', 'reason' => syngas_banned_reason()]);
        $sse('done', ['success' => true, 'total' => count($targets), 'sent' => 0, 'failed' => []]);
        exit;
    }

    $total   = count($targets);
    $current = 0;
    $sent    = 0;
    $failed  = [];

    foreach ($targets as $series) {
        $current++;
        $sse('progress', ['current' => $current, 'total' => $total, 'name' => $series['name']]);

        $result = syngas_sync_send_one($series);

        if (!$result['ok']) {
            if (syngas_is_banned()) {
                $sse('banned', ['message' => 'La connexion de ce site à Syngas a été suspendue.', 'reason' => syngas_banned_reason()]);
                break;
            }
            $failed[] = ['name' => $series['name'], 'error' => $result['error']];
            continue;
        }

        $sent++;
        usleep(150000); // ~150 ms entre séries, par courtoisie envers Syngas
    }

    $sse('done', [
        'success' => true,
        'total'   => $total,
        'sent'    => $sent,
        'failed'  => $failed,
    ]);
    exit;
}

// ── Endpoint SSE : comparaison des séries déjà liées (réception) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'syngas_receive_stream') {
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

    $targets = syngas_sync_receive_targets($data);
    $total   = count($targets);
    $current = 0;
    $with_changes = 0;

    // syngas_is_banned() revérifie déjà elle-même l'état si le flag a expiré
    // (voir includes/syngas.php) : pas de second appel réseau explicite ici.
    if (syngas_is_banned()) {
        $sse('banned', ['message' => 'La connexion de ce site à Syngas a été suspendue.', 'reason' => syngas_banned_reason()]);
        $sse('done', ['success' => true, 'total' => $total, 'with_changes' => 0]);
        exit;
    }

    foreach ($targets as $series) {
        $current++;
        $sse('progress', ['current' => $current, 'total' => $total, 'name' => $series['name']]);

        $diff_info = syngas_sync_compute_diff($series);

        if ($diff_info === null) {
            if (syngas_is_banned()) {
                $sse('banned', ['message' => 'La connexion de ce site à Syngas a été suspendue.', 'reason' => syngas_banned_reason()]);
                break;
            }
            continue;
        }

        $with_changes++;
        $sse('match', ['series' => $diff_info]);
        usleep(80000);
    }

    $sse('done', [
        'success'      => true,
        'total'        => $total,
        'with_changes' => $with_changes,
    ]);
    exit;
}

// ── Endpoint POST : enregistrement des changements validés (réception) ─────
// $_POST['selections'] : JSON { "<series_id>": true, … } — séries cochées.
// $_POST['diffs']      : JSON { "<series_id>": { fields, thumbnail_url }, … }
//                        recalculé côté client à partir des évènements SSE
//                        déjà reçus (pas de nouvel appel réseau à Syngas ici).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tool_action'] ?? '') === 'syngas_receive_save') {
    header('Content-Type: application/json');

    $selections = json_decode($_POST['selections'] ?? '{}', true);
    if (!is_array($selections)) $selections = [];
    $diffs = json_decode($_POST['diffs'] ?? '{}', true);
    if (!is_array($diffs)) $diffs = [];

    $response = syngas_sync_save_selected($data, $selections, $diffs);
    echo json_encode($response);
    exit;
}

// ── Endpoint SSE : comparaison des séries déjà liées (envoi de mises à jour) ─
// Sens inverse de syngas_receive_stream ci-dessus : signale les champs qui
// diffèrent côté LENGAS par rapport à la fiche Syngas actuelle — pas la
// même chose que "Récupérer les mises à jour Syngas" (Syngas → Lengas).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'syngas_send_updates_stream') {
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

    $targets = syngas_sync_send_updates_targets($data);
    $total   = count($targets);
    $current = 0;
    $with_changes = 0;

    // syngas_is_banned() revérifie déjà elle-même l'état si le flag a expiré
    // (voir includes/syngas.php) : pas de second appel réseau explicite ici.
    if (syngas_is_banned()) {
        $sse('banned', ['message' => 'La connexion de ce site à Syngas a été suspendue.', 'reason' => syngas_banned_reason()]);
        $sse('done', ['success' => true, 'total' => $total, 'with_changes' => 0]);
        exit;
    }

    foreach ($targets as $series) {
        $current++;
        $sse('progress', ['current' => $current, 'total' => $total, 'name' => $series['name']]);

        $diff_info = syngas_sync_compute_reverse_diff($series);

        if ($diff_info === null) {
            if (syngas_is_banned()) {
                $sse('banned', ['message' => 'La connexion de ce site à Syngas a été suspendue.', 'reason' => syngas_banned_reason()]);
                break;
            }
            continue;
        }

        $with_changes++;
        $sse('match', ['series' => $diff_info]);
        usleep(80000);
    }

    $sse('done', [
        'success'      => true,
        'total'        => $total,
        'with_changes' => $with_changes,
    ]);
    exit;
}

// ── Endpoint : persistance des sélections + diffs avant l'envoi (POST) ─────
// Même raison que syngas_send_settings_save ci-dessus : selections ET diffs
// (le résultat complet de syngas_sync_compute_reverse_diff() pour chaque
// série cochée) transitaient auparavant dans l'URL du flux SSE — de loin le
// cas le plus volumineux des trois flux de cet outil, donc le plus exposé à
// dépasser la longueur d'URL maximale dès que plusieurs dizaines de séries
// sont concernées.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tool_action'] ?? '') === 'syngas_send_updates_settings_save') {
    header('Content-Type: application/json');
    $selections = json_decode($_POST['selections'] ?? '{}', true);
    if (!is_array($selections)) $selections = [];
    $diffs = json_decode($_POST['diffs'] ?? '{}', true);
    if (!is_array($diffs)) $diffs = [];
    syngas_save_send_updates_state($selections, $diffs);
    echo json_encode(['success' => true]);
    exit;
}

// ── Endpoint SSE : envoi effectif des propositions sélectionnées ───────────
// Lit les sélections et diffs persistés par syngas_send_updates_settings_save
// juste avant (résultat de syngas_sync_compute_reverse_diff() pour chaque
// série, recalculé côté client à partir des évènements SSE déjà reçus — pas
// de nouvel appel réseau à Syngas pour reconstruire le diff ici).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'syngas_send_updates_apply_stream') {
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

    $state      = syngas_load_send_updates_state();
    $selections = $state['selections'];
    $diffs      = $state['diffs'];
    syngas_clear_send_updates_state(); // consommée : une prochaine visite ne doit pas la rejouer

    // syngas_is_banned() revérifie déjà elle-même l'état si le flag a expiré
    // (voir includes/syngas.php) : pas de second appel réseau explicite ici.
    if (syngas_is_banned()) {
        $sse('banned', ['message' => 'La connexion de ce site à Syngas a été suspendue.', 'reason' => syngas_banned_reason()]);
        $sse('done', ['success' => true, 'total' => 0, 'sent' => 0, 'failed' => []]);
        exit;
    }

    $selected_ids = array_keys(array_filter($selections));
    $total   = count($selected_ids);
    $current = 0;
    $sent    = 0;
    $failed  = [];

    foreach ($selected_ids as $series_id) {
        $diff_info = $diffs[$series_id] ?? null;
        if (!is_array($diff_info) || empty($diff_info['syngas_uid'])) {
            continue;
        }

        $found = find_series_by_id($data, $series_id);
        if ($found === null) continue;
        $series = $found['data'];

        $current++;
        $sse('progress', ['current' => $current, 'total' => $total, 'name' => $series['name']]);

        $result = syngas_sync_send_update_one($series, $diff_info);

        if (!$result['ok']) {
            if (syngas_is_banned()) {
                $sse('banned', ['message' => 'La connexion de ce site à Syngas a été suspendue.', 'reason' => syngas_banned_reason()]);
                break;
            }
            $failed[] = ['name' => $series['name'], 'error' => $result['error']];
            continue;
        }

        $sent++;
        usleep(150000); // ~150 ms entre séries, par courtoisie envers Syngas
    }

    $sse('done', [
        'success' => true,
        'total'   => $total,
        'sent'    => $sent,
        'failed'  => $failed,
    ]);
    exit;
}

$tool_title    = 'Synchronisation Syngas';
$tool_subtitle = 'Partagez et récupérez des fiches avec la base commune des mangathèques Lengas.';
require __DIR__ . '/_layout_head.php';
?>

        <div class="syngas-banned-banner" id="syngas-banned-banner" hidden>
            La connexion de ce site à Syngas a été suspendue.
            <span class="syngas-banned-reason" id="syngas-banned-reason"></span>
            <button type="button" id="syngas-banned-recheck-btn" class="button button-opt syngas-banned-recheck-btn">Revérifier maintenant</button>
        </div>

        <div class="tools-section">
            <h2>Envoyer des séries à Syngas</h2>
            <p>Propose à Syngas chaque série manga ou light-novel qui n'y est pas encore liée. Rien n'est envoyé sans votre confirmation explicite, série par série si besoin. La détection de doublon se fait automatiquement côté Syngas.</p>
            <p class="hint">Une série n'est éligible que si son champ « Catégories » contient le mot « manga » ou « light-novel » (voir l'aide dans les modales d'ajout/édition).</p>
            <button id="syngas-send-btn" class="button button-opt">
                <span id="syngas-send-text">Préparer l'envoi</span>
                <span id="syngas-send-spinner" class="spinner" style="display: none;"></span>
            </button>
            <div id="syngas-send-progress"></div>
            <div id="syngas-send-results"></div>
        </div>

        <div class="tools-section">
            <h2>Récupérer les mises à jour Syngas</h2>
            <p>Compare chaque série déjà liée à Syngas à sa fiche actuelle, et vous laisse valider les changements avant enregistrement — série par série ou tous à la fois. Un champ vide côté Syngas n'écrase jamais votre valeur locale.</p>
            <button id="syngas-receive-btn" class="button button-opt">
                <span id="syngas-receive-text">Vérifier les mises à jour</span>
                <span id="syngas-receive-spinner" class="spinner" style="display: none;"></span>
            </button>
            <div id="syngas-receive-progress"></div>
            <div id="syngas-receive-results"></div>
        </div>

        <div class="tools-section">
            <h2>Envoyer des mises à jour à Syngas</h2>
            <p>Compare chaque série déjà liée à Syngas à sa fiche actuelle, et vous laisse choisir lesquelles proposer en retour — série par série ou toutes à la fois. Les propositions apparaissent dans « Propositions de modification » côté Syngas (jamais dans « Séries en attente »), et attendent la validation d'un modérateur avant d'être appliquées.</p>
            <button id="syngas-send-updates-btn" class="button button-opt">
                <span id="syngas-send-updates-text">Vérifier les différences</span>
                <span id="syngas-send-updates-spinner" class="spinner" style="display: none;"></span>
            </button>
            <div id="syngas-send-updates-progress"></div>
            <div id="syngas-send-updates-results"></div>
        </div>

<?php
require __DIR__ . '/_tools-modals.php';

$tool_scripts = ['syngas.js'];
require __DIR__ . '/_layout_foot.php';
