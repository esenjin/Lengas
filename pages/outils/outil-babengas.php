<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/outil-babengas.php — Outil « Vérification via Babengas »
// (nombre de tomes réellement parus en France, via Babelio)
//
// Cette page n'a de sens que si Babengas est configuré et activé. Elle reste
// accessible directement (l'utilisateur peut avoir l'URL en favori), mais
// n'apparaît dans la liste des outils (pages/page-outils.php) que si
// babengas_enabled() est vrai.
// ────────────────────────────────────────────────────────────────────────────

require __DIR__ . '/_bootstrap.php';

// ============================================================================
// ENDPOINTS
// ============================================================================

// ── Outils « Babengas » : actions POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tool_action'])) {
    $response = ['success' => false, 'message' => 'Action inconnue.'];

    switch ($_POST['tool_action']) {
        case 'babengas_launch':
            $response = babengas_launch_campaign(
                $data,
                ($_POST['all'] ?? '0') === '1',
                ($_POST['force'] ?? '0') === '1'
            );
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
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$tool_title    = 'Vérification via Babengas';
$tool_subtitle = 'Nombre de tomes réellement parus en France, via Babelio.';
require __DIR__ . '/_layout_head.php';
?>

        <div class="tools-section">
            <h2>Vérification via Babengas</h2>
            <p>Cet outil interroge <strong>Babelio</strong> — via votre service Babengas — pour connaître le nombre de tomes <strong>réellement parus en France</strong>. Là où MangaUpdates se base surtout sur l'édition d'origine, Babelio couvre bien mieux les sorties VF : en cas de divergence, c'est ce décompte qui fait foi.</p>

            <p class="hint">⏱️ Le traitement est <strong>asynchrone et lent, volontairement</strong>, par courtoisie envers leurs serveurs. Comptez environ cinq minutes par série. Vous pouvez fermer cette page sans interrompre la campagne : le suivi reprendra à votre retour.</p>
            <p class="hint">Sont exclues du ciblage les séries dont la publication est figée (terminée, en pause ou abandonnée) et celles possédant un tome tagué « dernier tome » : elles n'ont plus rien à apprendre de Babelio. Les séries vérifiées il y a moins de 30 jours sont ignorées, sauf si un tome a été ajouté depuis. Les one-shots (fiche de tome Babelio) sont vérifiés localement, sans passer par le service.</p>

            <div class="tools-actions">
                <button id="babengas-launch" class="button">Lancer une campagne</button>
                <button id="babengas-launch-all" class="button button-opt" title="Vérifie toutes les séries éligibles, y compris celles contrôlées il y a moins de 30 jours (les séries avec un tome tagué « dernier » restent exclues)">Forcer les séries éligibles</button>
                <button id="babengas-launch-force" class="button button-opt" title="Vérifie l'intégralité des séries ayant une URL de fiche série Babelio, sans aucune exception (y compris celles avec un tome tagué « dernier »)">Forcer toutes les séries</button>
                <button id="babengas-cancel" class="button button-opt" style="display:none;">Annuler la campagne</button>
            </div>

            <div id="babengas-progress"></div>
            <div id="babengas-results"></div>
        </div>

<?php
$tm_add_babelio_url = true;
require __DIR__ . '/_tools-modals.php';

$tool_scripts = ['babengas.js'];
require __DIR__ . '/_layout_foot.php';
