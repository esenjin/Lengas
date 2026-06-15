<?php
require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require 'fonctions/series.php';
require 'fonctions/volumes.php';
require 'fonctions/loans.php';
require 'fonctions/options.php';

$data    = load_data();
$options = load_options();

// ── Actions AJAX ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loan_action'])) {
    $response = ['success' => false];
    $action   = $_POST['loan_action'];

    switch ($action) {
        case 'add_single_loan':
            $series_id     = $_POST['series_id'] ?? '';
            $volume_number = (int)($_POST['volume_number'] ?? 0);
            $borrower_name = trim($_POST['borrower_name'] ?? '');
            if ($series_id && $volume_number > 0 && $borrower_name) {
                $response = add_loan($data, $series_id, $volume_number, $borrower_name);
            } else {
                $response['message'] = 'Veuillez remplir tous les champs.';
            }
            break;

        case 'add_multiple_loans':
            $series_id     = $_POST['series_id'] ?? '';
            $start_volume  = (int)($_POST['start_volume'] ?? 0);
            $end_volume    = (int)($_POST['end_volume'] ?? 0);
            $borrower_name = trim($_POST['borrower_name'] ?? '');
            if ($series_id && $start_volume > 0 && $end_volume >= $start_volume && $borrower_name) {
                $response = add_multiple_loans($data, $series_id, $start_volume, $end_volume, $borrower_name);
            } else {
                $response['message'] = 'Veuillez remplir tous les champs.';
            }
            break;

        case 'remove_loan':
            $series_id     = $_POST['series_id'] ?? '';
            $volume_number = (int)($_POST['volume_number'] ?? 0);
            if ($series_id && $volume_number > 0) {
                $response['success'] = remove_loan($series_id, $volume_number);
            }
            break;

        case 'remove_all_loans':
            $series_id = $_POST['series_id'] ?? '';
            if ($series_id) {
                $response['success'] = remove_all_loans($series_id);
            }
            break;

        case 'get_loans':
            $loans_by_series    = get_loans_by_series($data);
            $response['success'] = true;
            $response['loans']   = $loans_by_series;
            break;

        case 'get_series_suggestions':
            $term             = normalize_string(trim($_POST['term'] ?? ''));
            $suggestions      = [];
            foreach ($data as $series) {
                if (str_contains(normalize_string($series['name'] ?? ''), $term)) {
                    $suggestions[] = ['id' => $series['id'], 'name' => $series['name']];
                }
            }
            $response['success']     = true;
            $response['suggestions'] = array_slice($suggestions, 0, 10);
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
    <title>Livres prêtés — <?= htmlspecialchars($options['site_name'] ?? 'Lengas') ?></title>
    <meta name="description" content="Gestion des livres prêtés.">
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body class="with-sidebar">

    <?php include 'includes/sidebar.php'; ?>

    <main class="page-main">
        <div class="page-header">
            <h1>Livres prêtés</h1>
            <p class="page-subtitle">Suivez les tomes que vous avez prêtés à vos amis.</p>
        </div>

        <div class="loans-page-layout">

            <!-- Panneau : ajouter un prêt unique -->
            <section class="loans-panel">
                <h2 class="loans-panel-title">
                    <img src="https://api.iconify.design/mdi/book-arrow-right.svg?color=%2338bdf8" width="20" height="20" alt="">
                    Prêter un tome
                </h2>
                <form id="add-single-loan-form" class="loans-form" autocomplete="off">
                    <label for="loan-series-search">Série</label>
                    <input type="text" id="loan-series-search" class="series-search" placeholder="Rechercher une série…">
                    <div class="series-results" id="loan-series-results">
                        <?php foreach ($data as $series): ?>
                            <div data-id="<?= $series['id'] ?>"><?= htmlspecialchars($series['name']) ?></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="series_id" id="loan-selected-series-id" required>

                    <label for="loan-volume-number">Numéro du tome</label>
                    <input type="number" inputmode="numeric" id="loan-volume-number" name="volume_number" placeholder="Ex : 3" min="1" required>

                    <label for="loan-borrower">Emprunteur</label>
                    <input type="text" id="loan-borrower" name="borrower_name" placeholder="Nom de l'emprunteur" required>

                    <button type="submit" class="button button-otl">Enregistrer le prêt</button>
                </form>
            </section>

            <!-- Panneau : prêter plusieurs tomes -->
            <section class="loans-panel">
                <h2 class="loans-panel-title">
                    <img src="https://api.iconify.design/mdi/book-multiple.svg?color=%2338bdf8" width="20" height="20" alt="">
                    Prêter plusieurs tomes
                </h2>
                <form id="add-multiple-loans-form" class="loans-form" autocomplete="off">
                    <label for="multiple-loan-series-search">Série</label>
                    <input type="text" id="multiple-loan-series-search" class="series-search" placeholder="Rechercher une série…">
                    <div class="series-results" id="multiple-loan-series-results">
                        <?php foreach ($data as $series): ?>
                            <div data-id="<?= $series['id'] ?>"><?= htmlspecialchars($series['name']) ?></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="series_id" id="multiple-loan-selected-series-id" required>

                    <label>Plage de tomes</label>
                    <div class="volume-range">
                        <input type="number" inputmode="numeric" name="start_volume" placeholder="Début" min="1" required>
                        <span>à</span>
                        <input type="number" inputmode="numeric" name="end_volume" placeholder="Fin" min="1" required>
                    </div>

                    <label for="multiple-loan-borrower">Emprunteur</label>
                    <input type="text" id="multiple-loan-borrower" name="borrower_name" placeholder="Nom de l'emprunteur" required>

                    <button type="submit" class="button button-otl">Enregistrer les prêts</button>
                </form>
            </section>

            <!-- Panneau : liste des prêts -->
            <section class="loans-panel loans-panel--full">
                <div class="loans-list-header">
                    <h2 class="loans-panel-title">
                        <img src="https://api.iconify.design/mdi/format-list-bulleted.svg?color=%2338bdf8" width="20" height="20" alt="">
                        Prêts en cours
                    </h2>
                    <input type="text" id="loan-search" class="loans-search-input" placeholder="Filtrer par série ou emprunteur…" autocomplete="off">
                </div>
                <div id="loan-list" class="loan-list-container">
                    <p class="loans-empty">Chargement…</p>
                </div>
            </section>

        </div>

        <!-- Modales de confirmation -->
        <div class="modal" id="custom-confirm-modal">
            <div class="modal-content modal-content--narrow">
                <h2 id="custom-confirm-title">Confirmation</h2>
                <p id="custom-confirm-message"></p>
                <div class="modal-actions">
                    <button class="button" id="custom-confirm-ok">Confirmer</button>
                    <button class="button button-ext" id="custom-confirm-cancel">Annuler</button>
                </div>
            </div>
        </div>
        <div class="modal" id="custom-alert-modal">
            <div class="modal-content modal-content--narrow">
                <h2 id="custom-alert-title">Information</h2>
                <p id="custom-alert-message"></p>
                <div class="modal-actions">
                    <button class="button" id="custom-alert-ok">OK</button>
                </div>
            </div>
        </div>

    </main>

    <script>
        // Données des séries pour l'autocomplétion
        window.seriesData = <?= json_encode(array_values(array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name']], $data))) ?>;

        // Fonctions utilitaires
        function normalizeString(str) {
            return str.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
        }

        function showCustomConfirm(title, message) {
            const modal = document.getElementById('custom-confirm-modal');
            document.getElementById('custom-confirm-title').textContent = title;
            document.getElementById('custom-confirm-message').textContent = message;
            modal.classList.add('modal-active');
            return new Promise(resolve => {
                document.getElementById('custom-confirm-ok').onclick = () => { modal.classList.remove('modal-active'); resolve(true); };
                document.getElementById('custom-confirm-cancel').onclick = () => { modal.classList.remove('modal-active'); resolve(false); };
            });
        }

        function showCustomAlert(title, message) {
            const modal = document.getElementById('custom-alert-modal');
            document.getElementById('custom-alert-title').textContent = title;
            document.getElementById('custom-alert-message').textContent = message;
            modal.classList.add('modal-active');
            return new Promise(resolve => {
                document.getElementById('custom-alert-ok').onclick = () => { modal.classList.remove('modal-active'); resolve(); };
            });
        }

        window.alert = (msg) => showCustomAlert('Avertissement', msg);

        // ── Recherche de série (dropdown) ─────────────────────────────────────
        function setupSeriesSearch(inputId, resultsId, hiddenId) {
            const input   = document.getElementById(inputId);
            const results = document.getElementById(resultsId);
            const hidden  = document.getElementById(hiddenId);

            input.addEventListener('focus', () => { results.style.display = 'block'; });
            input.addEventListener('input', () => {
                const term = normalizeString(input.value);
                let found  = 0;
                results.querySelectorAll('div').forEach(div => {
                    const matches = normalizeString(div.textContent).includes(term);
                    div.style.display = matches ? '' : 'none';
                    if (matches) found++;
                });
                results.style.display = found > 0 ? 'block' : 'none';
                hidden.value = '';
            });

            results.querySelectorAll('div').forEach(div => {
                div.addEventListener('click', () => {
                    input.value         = div.textContent;
                    hidden.value        = div.dataset.id;
                    results.style.display = 'none';
                });
            });

            document.addEventListener('click', e => {
                if (!input.contains(e.target) && !results.contains(e.target)) {
                    results.style.display = 'none';
                }
            });
        }

        setupSeriesSearch('loan-series-search',          'loan-series-results',          'loan-selected-series-id');
        setupSeriesSearch('multiple-loan-series-search', 'multiple-loan-series-results', 'multiple-loan-selected-series-id');

        // ── Soumission formulaire unique ──────────────────────────────────────
        document.getElementById('add-single-loan-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('loan_action', 'add_single_loan');
            const res = await fetch('page-prets.php', { method: 'POST', body: new URLSearchParams(fd) });
            const data = await res.json();
            if (data.success) {
                this.reset();
                document.getElementById('loan-series-search').value = '';
                document.getElementById('loan-selected-series-id').value = '';
                loadLoanList();
                showCustomAlert('Succès', 'Prêt enregistré.');
            } else {
                showCustomAlert('Erreur', data.message || 'Une erreur est survenue.');
            }
        });

        // ── Soumission formulaire multiple ────────────────────────────────────
        document.getElementById('add-multiple-loans-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('loan_action', 'add_multiple_loans');
            const res = await fetch('page-prets.php', { method: 'POST', body: new URLSearchParams(fd) });
            const data = await res.json();
            if (data.success) {
                this.reset();
                document.getElementById('multiple-loan-series-search').value = '';
                document.getElementById('multiple-loan-selected-series-id').value = '';
                loadLoanList();
                showCustomAlert('Succès', 'Prêts enregistrés.');
            } else {
                showCustomAlert('Erreur', data.message || 'Une erreur est survenue.');
            }
        });

        // ── Filtre de recherche ───────────────────────────────────────────────
        document.getElementById('loan-search').addEventListener('input', function() {
            const term = normalizeString(this.value);
            document.querySelectorAll('.loan-series-item').forEach(item => {
                const name      = normalizeString(item.querySelector('h3')?.textContent || '');
                const borrowers = Array.from(item.querySelectorAll('.loan-volumes-list li'))
                                       .map(li => normalizeString(li.textContent));
                item.style.display = (name.includes(term) || borrowers.some(b => b.includes(term))) ? '' : 'none';
            });
        });

        // ── Chargement et affichage des prêts ─────────────────────────────────
        async function loadLoanList() {
            const res  = await fetch('page-prets.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'loan_action=get_loans'
            });
            const data = await res.json();
            if (data.success) displayLoanList(data.loans);
        }

        function displayLoanList(loans) {
            const container = document.getElementById('loan-list');
            if (!loans || loans.length === 0) {
                container.innerHTML = '<p class="loans-empty">Aucun tome prêté en ce moment. 📚</p>';
                return;
            }

            container.innerHTML = '';
            loans.forEach(item => {
                const series       = item.series;
                const loanItems    = item.loans;
                const seriesExists = item.series_exists;

                const div = document.createElement('div');
                div.className = 'loan-series-item';

                let html = '';
                if (!seriesExists || !series) {
                    html += `<h3 class="loan-series-deleted">Série supprimée</h3>
                             <p class="loan-series-deleted-hint">Cette série n'existe plus dans votre bibliothèque.</p>`;
                } else {
                    html += `<h3>${htmlEscape(series.name)}</h3>
                             <p class="loan-series-author">${htmlEscape(series.author)}</p>`;
                }

                html += `<ul class="loan-volumes-list">`;
                loanItems.forEach(loan => {
                    html += `<li>
                        <span>Tome ${loan.volume_number} → <strong>${htmlEscape(loan.borrower_name)}</strong></span>
                        <button class="remove-loan-btn button-sm" data-series-id="${loan.series_id}" data-volume-number="${loan.volume_number}">Retirer</button>
                    </li>`;
                });
                html += `</ul>`;

                if (loanItems.length > 1) {
                    html += `<button class="remove-all-loans-btn" data-series-id="${loanItems[0].series_id}">Tout retirer</button>`;
                }

                div.innerHTML = html;
                container.appendChild(div);
            });

            // Events
            container.querySelectorAll('.remove-loan-btn').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const confirmed = await showCustomConfirm('Confirmation', 'Retirer ce prêt ?');
                    if (!confirmed) return;
                    const fd = `loan_action=remove_loan&series_id=${this.dataset.seriesId}&volume_number=${this.dataset.volumeNumber}`;
                    const res = await fetch('page-prets.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: fd });
                    const data = await res.json();
                    if (data.success) loadLoanList();
                });
            });

            container.querySelectorAll('.remove-all-loans-btn').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const confirmed = await showCustomConfirm('Confirmation', 'Retirer tous les prêts de cette série ?');
                    if (!confirmed) return;
                    const fd = `loan_action=remove_all_loans&series_id=${this.dataset.seriesId}`;
                    const res = await fetch('page-prets.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: fd });
                    const data = await res.json();
                    if (data.success) loadLoanList();
                });
            });
        }

        function htmlEscape(str) {
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // Charge dès l'arrivée
        loadLoanList();

        // Fermeture modales en cliquant dehors
        window.addEventListener('click', e => {
            ['custom-confirm-modal','custom-alert-modal'].forEach(id => {
                const m = document.getElementById(id);
                if (e.target === m) m.classList.remove('modal-active');
            });
        });
    </script>
</body>
</html>
