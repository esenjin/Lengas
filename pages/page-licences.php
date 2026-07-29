<?php

// Rétablit le dossier de travail à la racine du projet : cette page vit dans
// pages/ mais tous les chemins relatifs (config.php, includes/, bdd/, uploads/…)
// sont résolus depuis la racine.
chdir(__DIR__ . '/..');
require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require 'includes/themes.php';
require 'fonctions/series.php';
require 'fonctions/options.php';
require 'fonctions/licenses.php';

$data    = load_data();
$options = load_options();
// ── Mangas ET animés ──────────────────────────────────────────────────────────
// Comme page-critiques.php : $data reste la collection complète, tous types
// confondus. Aucune écriture sur la table `series` n'a lieu ici.

// ── Actions AJAX ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['license_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false];

    switch ($_POST['license_action']) {

        case 'list':
            $response = ['success' => true, 'licenses' => list_licenses($data)];
            break;

        case 'detail':
            $license_id = $_POST['license_id'] ?? '';
            $detail     = get_license_detail($data, $license_id);
            if ($detail === null) {
                $response = ['success' => false, 'message' => 'Licence introuvable.'];
            } else {
                $response = ['success' => true, 'license' => $detail];
            }
            break;

        case 'licensable':
            // Séries de la collection ne relevant d'aucune licence (ou de la
            // licence en cours d'édition, pour ne pas se ré-exclure elle-même).
            $license_id = $_POST['license_id'] ?? '';
            $response   = ['success' => true, 'series' => get_licensable_series($data, $license_id)];
            break;

        case 'create':
            $name     = $_POST['name'] ?? '';
            $response = create_license($name);
            break;

        case 'rename':
            $license_id = $_POST['license_id'] ?? '';
            $name       = $_POST['name'] ?? '';
            $response   = rename_license($license_id, $name);
            break;

        case 'delete':
            $license_id = $_POST['license_id'] ?? '';
            $response   = delete_license($license_id);
            break;

        case 'add_series':
            $license_id = $_POST['license_id'] ?? '';
            $series_id  = $_POST['series_id'] ?? '';
            $response   = add_series_to_license($data, $license_id, $series_id);
            break;

        case 'remove_series':
            $license_id = $_POST['license_id'] ?? '';
            $series_id  = $_POST['series_id'] ?? '';
            $response   = remove_series_from_license($license_id, $series_id);
            break;

        case 'reorder':
            $license_id = $_POST['license_id'] ?? '';
            $order      = $_POST['order'] ?? [];
            if (!is_array($order)) $order = [];
            $response   = reorder_license_series($license_id, $order);
            break;
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Licences — <?= htmlspecialchars($options['site_name'] ?? 'Lengas') ?></title>
    <meta name="description" content="Regroupement de séries sous une même licence.">
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    <link rel="stylesheet" href="../assets/css/main.css">
    <?= theme_link_tag($options) ?>
</head>
<body class="with-sidebar">

    <?php include 'includes/sidebar.php'; ?>

    <main class="page-main">
        <div class="page-header">
            <h1>Licences</h1>
            <p class="page-subtitle">Regroupez vos séries (mangas et animés) sous une même licence.</p>
        </div>

        <div class="licenses-list-toolbar">
            <input type="text" id="licenses-search" class="licenses-search-input"
                   placeholder="Filtrer par nom de licence…" autocomplete="off">
            <button type="button" id="new-license-btn" class="button button-aos">
                <img src="https://api.iconify.design/mdi/bookmark-plus.svg?color=%23ffffff" width="18" height="18" alt="">
                Nouvelle licence
            </button>
        </div>

        <div id="licenses-list" class="licenses-list-container">
            <p class="licenses-empty">Chargement…</p>
        </div>
    </main>

    <!-- Modale de création d'une licence -->
    <div class="modal" id="new-license-modal">
        <div class="modal-content modal-content--sm">
            <span class="close-modal" id="close-new-license-modal">&times;</span>
            <h2>Nouvelle licence</h2>
            <label for="new-license-name" class="sr-only">Nom de la licence</label>
            <input type="text" id="new-license-name" placeholder="Nom de la licence (ex. « Frieren »)" autocomplete="off" maxlength="120">
            <div class="modal-actions">
                <button id="new-license-ok" class="button button-ats">Créer</button>
                <button id="new-license-cancel" class="button button-ext">Annuler</button>
            </div>
        </div>
    </div>

    <!-- Modale de détail d'une licence : séries membres, tri, ajout/retrait -->
    <div class="modal" id="license-detail-modal">
        <div class="modal-content modal-content--wide">
            <span class="close-modal" id="close-license-detail-modal">&times;</span>

            <div class="license-detail-header">
                <input type="text" id="license-detail-name" class="license-detail-name-input" maxlength="120">
                <button type="button" id="license-detail-delete" class="button delete-btn" title="Supprimer la licence">Supprimer</button>
            </div>
            <p class="license-detail-subtitle" id="license-detail-subtitle"></p>

            <div class="license-add-series-wrap">
                <label for="license-add-series-input" class="sr-only">Ajouter une série</label>
                <input type="text" id="license-add-series-input" class="license-add-series-input"
                       placeholder="Ajouter une série de la collection…" autocomplete="off">
                <div class="license-add-series-results" id="license-add-series-results"></div>
            </div>

            <div id="license-series-list" class="license-series-list">
                <p class="license-series-empty">Chargement…</p>
            </div>
        </div>
    </div>

    <!-- Modales génériques (alerte / confirmation), réutilisées par main.js -->
    <div class="modal" id="custom-alert-modal">
        <div class="modal-content modal-content--sm">
            <h2 id="custom-alert-title">Information</h2>
            <p id="custom-alert-message"></p>
            <div class="modal-actions">
                <button id="custom-alert-ok" class="button">OK</button>
            </div>
        </div>
    </div>
    <div class="modal" id="custom-confirm-modal">
        <div class="modal-content modal-content--sm">
            <h2 id="custom-confirm-title">Confirmation</h2>
            <p id="custom-confirm-message"></p>
            <div class="modal-actions">
                <button id="custom-confirm-ok" class="button">OK</button>
                <button id="custom-confirm-cancel" class="button button-ext">Annuler</button>
            </div>
        </div>
    </div>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <script>
        // Registre allégé des types (badges), seule source de vérité pour ce JS.
        window.seriesTypes = <?= json_encode(series_types_for_js()) ?>;
    </script>
    <script src="../assets/js/admin/main.js"></script>
    <script src="../assets/js/admin/licenses.js"></script>
</body>
</html>
