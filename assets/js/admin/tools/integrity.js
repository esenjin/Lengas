// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/integrity.js — Outil « Vérification d'intégrité »
//
// Lance la vérification et met en forme le rapport (fichiers, permissions,
// base de données, thèmes, Vestikan, API MangaUpdates…), ainsi que les
// actions de nettoyage proposées.
// ──────────────────────────────────────────────────────────────────────────

// Fonction pour afficher les résultats de la vérification d'intégrité
function displayIntegrityResults(results) {
    const container = document.getElementById('integrity-results-container');
    if (!container) return;
    let html = `
        <div class="integrity-header">
            <h3>Résultats de la vérification d'intégrité</h3>
        </div>
        <div class="integrity-results">
    `;

    // 1. Existence des fichiers/dossiers
    html += `
        <div class="integrity-section">
            <h3>Existence des fichiers/dossiers</h3>
            <div class="file-categories">
                <div class="file-category">
                    <h4>Fichiers racines</h4>
                    <ul>
    `;
    const rootFiles = [
        'index.php', 'admin.php', 'stats.php', 'config.php', 'login.php', 'logout.php', '.htaccess',
        'pages/page-prets.php', 'pages/page-wishlist.php', 'pages/page-critiques.php', 'pages/page-outils.php', 'pages/page-options.php', 'pages/page-profil.php'
    ];
    rootFiles.forEach(file => {
        html += `<li>${file}: <span class="${results.file_existence[file] ? 'ok' : 'error'}">${results.file_existence[file] ? 'OK' : 'Manquant'}</span></li>`;
    });
    html += `</ul></div><br>`;

    html += `
                <div class="file-category">
                    <h4>Fichiers d'assets (général)</h4>
                    <ul>
    `;
    const generalAssets = ['assets/css/main.css', 'assets/js/public.js', 'assets/js/stats.js', 'assets/js/admin/'];
    generalAssets.forEach(file => {
        html += `<li>${file}: <span class="${results.file_existence[file] ? 'ok' : 'error'}">${results.file_existence[file] ? 'OK' : 'Manquant'}</span></li>`;
    });
    html += `</ul></div><br>`;

    html += `
                <div class="file-category">
                    <h4>Fichiers de fonctions</h4>
                    <ul>
    `;
    const functionFiles = [
        'fonctions/loans.php', 'fonctions/options.php', 'fonctions/tools.php', 'fonctions/read.php',
        'fonctions/series.php', 'fonctions/wishlist.php', 'fonctions/volumes.php',
        'fonctions/stats_compute.php', 'fonctions/reviews.php'
    ];
    functionFiles.forEach(file => {
        html += `<li>${file}: <span class="${results.file_existence[file] ? 'ok' : 'error'}">${results.file_existence[file] ? 'OK' : 'Manquant'}</span></li>`;
    });
    html += `</ul></div><br>`;

    html += `
                <div class="file-category">
                    <h4>Fichiers de fonctions (outils)</h4>
                    <ul>
    `;
    const toolFunctionFiles = [
        'fonctions/tools/backups.php', 'fonctions/tools/integrity.php', 'fonctions/tools/cleanup.php',
        'fonctions/tools/mangaupdates_assoc.php', 'fonctions/tools/incomplete.php',
        'fonctions/tools/coherence.php'
    ];
    toolFunctionFiles.forEach(file => {
        html += `<li>${file}: <span class="${results.file_existence[file] ? 'ok' : 'error'}">${results.file_existence[file] ? 'OK' : 'Manquant'}</span></li>`;
    });
    html += `</ul></div><br>`;

    html += `
                <div class="file-category">
                    <h4>Fichiers includes</h4>
                    <ul>
    `;
    const includeFiles = [
        'includes/mangaupdates.php', 'includes/auth.php', 'includes/helpers.php',
        'includes/sidebar.php', 'includes/public-sidebar.php', 'includes/custom_icons.php',
        'includes/themes.php', 'includes/status_filter.php'
    ];
    includeFiles.forEach(file => {
        html += `<li>${file}: <span class="${results.file_existence[file] ? 'ok' : 'error'}">${results.file_existence[file] ? 'OK' : 'Manquant'}</span></li>`;
    });
    html += `</ul></div><br>`;

    html += `
                <div class="file-category">
                    <h4>Dossiers principaux</h4>
                    <ul>
    `;
    const mainDirectories = ['includes/', 'fonctions/', 'uploads/', 'saves/', 'bdd/'];
    mainDirectories.forEach(file => {
        html += `<li>${file}: <span class="${results.file_existence[file] ? 'ok' : 'error'}">${results.file_existence[file] ? 'OK' : 'Manquant'}</span></li>`;
    });
    html += `</ul></div><br>`;

    html += `
                <div class="file-category">
                    <h4>Fichiers CSS</h4>
                    <ul>
    `;
    const cssFiles = [
        'assets/css/_admin.css', 'assets/css/_base.css', 'assets/css/_buttons.css',
        'assets/css/_forms.css', 'assets/css/_layout.css', 'assets/css/_modals.css',
        'assets/css/_public.css', 'assets/css/_responsive.css', 'assets/css/_series.css',
        'assets/css/_stats.css', 'assets/css/_utils.css', 'assets/css/_variables.css',
        'assets/css/_sidebar.css', 'assets/css/_pages.css', 'assets/css/_reviews.css',
        'assets/css/_variables-light.css'
    ];
    cssFiles.forEach(file => {
        html += `<li>${file}: <span class="${results.file_existence[file] ? 'ok' : 'error'}">${results.file_existence[file] ? 'OK' : 'Manquant'}</span></li>`;
    });
    html += `</ul></div><br>`;

    html += `
                <div class="file-category">
                    <h4>Fichiers JS (admin)</h4>
                    <ul>
    `;
    const jsFiles = [
        'assets/js/admin/series.js', 'assets/js/admin/volumes.js', 'assets/js/admin/wishlist.js',
        'assets/js/admin/loans.js', 'assets/js/admin/autocomplete.js', 'assets/js/admin/modals.js',
        'assets/js/admin/pagination.js', 'assets/js/admin/reviews.js',
        'assets/js/admin/main.js'
    ];
    jsFiles.forEach(file => {
        html += `<li>${file}: <span class="${results.file_existence[file] ? 'ok' : 'error'}">${results.file_existence[file] ? 'OK' : 'Manquant'}</span></li>`;
    });
    html += `</ul></div><br>`;

    html += `
                <div class="file-category">
                    <h4>Fichiers JS (outils)</h4>
                    <ul>
    `;
    const jsToolFiles = [
        'assets/js/admin/tools/backups.js', 'assets/js/admin/tools/integrity.js',
        'assets/js/admin/tools/mangaupdates-assoc.js', 'assets/js/admin/tools/incomplete.js',
        'assets/js/admin/tools/coherence.js'
    ];
    jsToolFiles.forEach(file => {
        html += `<li>${file}: <span class="${results.file_existence[file] ? 'ok' : 'error'}">${results.file_existence[file] ? 'OK' : 'Manquant'}</span></li>`;
    });
    html += `</ul></div><br>`;

    // Base de données SQLite
    html += `
                <div class="file-category">
                    <h4>Base de données (bdd/)</h4>
                    <ul>
    `;
    const bddFiles = ['bdd/lengas.db'];
    bddFiles.forEach(file => {
        html += `<li>${file}: <span class="${results.file_existence[file] ? 'ok' : 'error'}">${results.file_existence[file] ? 'OK' : 'Manquant'}</span></li>`;
    });
    html += `</ul></div>`;

    html += `
            </div>
        </div>
    `;

    // 2. Fichiers interdits
    html += `
        <div class="integrity-section">
            <h3>Fichiers interdits</h3>
            <ul>
    `;
    for (const [file, ok] of Object.entries(results.forbidden_files)) {
        html += `<li>${file}: <span class="${ok ? 'ok' : 'error'}">${ok ? 'Absent' : 'Présent'}</span></li>`;
    }
    html += `</ul>`;

    if (Object.values(results.forbidden_files).some(ok => !ok)) {
        html += `
            <button id="clean-forbidden-files-btn" class="button button-opt">
                Supprimer les fichiers interdits
            </button>
        `;
    }
    html += `</div>`;

    // 3. Permissions
    html += `
        <div class="integrity-section">
            <h3>Permissions des fichiers/dossiers</h3>
            <table class="permissions-table">
                <thead>
                    <tr>
                        <th>Fichier/Dossier</th>
                        <th>Permission actuelle</th>
                        <th>Permission attendue</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
    `;
    for (const [file, data] of Object.entries(results.permissions)) {
        html += `
            <tr>
                <td>${file}</td>
                <td>${data.current}</td>
                <td>${data.expected}</td>
                <td class="${data.ok ? 'ok' : 'error'}">${data.ok ? 'OK' : 'Incorrect'}</td>
            </tr>
        `;
    }
    html += `
                </tbody>
            </table>
        </div>
    `;

    // 4. Accès externe aux dossiers sensibles
    html += `
        <div class="integrity-section">
            <h3>Accès externe aux dossiers sensibles</h3>
            <ul>
    `;
    for (const [folder, info] of Object.entries(results.external_access)) {
        let statusClass, statusText;
        if (info.ok === null) {
            statusClass = 'warn';
            statusText  = `Indéterminé (code HTTP ${info.status})`;
        } else if (info.ok) {
            statusClass = 'ok';
            statusText  = `Bloqué (${info.status})`;
        } else {
            statusClass = 'error';
            statusText  = `Accessible ! (code HTTP ${info.status})`;
        }
        html += `<li>${folder} : <span class="${statusClass}">${statusText}</span></li>`;
    }
    html += `</ul>`;
    if (Object.values(results.external_access).some(i => i.ok === false)) {
        html += `<p class="hint error">⚠️ Un ou plusieurs dossiers sensibles sont accessibles depuis l'extérieur. Vérifiez votre fichier <code>.htaccess</code>.</p>`;
    }
    html += `</div>`;

    // 5. Doublons
    html += `
        <div class="integrity-section">
            <h3>Doublons</h3>
            <ul>
    `;
    if (results.duplicates.collection_wishlist.length > 0) {
        html += `<li>Séries présentes à la fois dans la collection et la liste d'envies: <span class="error">${results.duplicates.collection_wishlist.join(', ')}</span></li>`;
    } else {
        html += `<li>Aucun doublon collection/envies</li>`;
    }
    if (results.duplicates.deleted_loans.length > 0) {
        html += `<li>Séries supprimées mais encore en prêt: <span class="error">${results.duplicates.deleted_loans.join(', ')}</span></li>`;
    } else {
        html += `<li>Aucune série supprimée en prêt</li>`;
    }
    html += `</ul>`;

    if (results.duplicates.collection_wishlist.length > 0 || results.duplicates.deleted_loans.length > 0) {
        html += `
            <button id="clean-duplicates-btn" class="button button-opt">
                Nettoyer les doublons
            </button>
        `;
    }
    html += `</div>`;

    // 5. Images orphelines
    html += `
        <div class="integrity-section">
            <h3>Images orphelines</h3>
            <ul>
    `;
    if (results.orphaned_images.length > 0) {
        results.orphaned_images.forEach(image => {
            html += `<li>${image} <span class="error">(orpheline)</span></li>`;
        });
    } else {
        html += `<li>Aucune image orpheline</li>`;
    }
    html += `</ul>`;

    if (results.orphaned_images.length > 0) {
        html += `
            <button id="clean-orphaned-images-btn" class="button button-opt">
                Nettoyer les images orphelines
            </button>
        `;
    }
    html += `</div>`;

    // 5a-bis. Thèmes personnalisés
    html += `
        <div class="integrity-section">
            <h3>Thèmes personnalisés</h3>
            <ul>
    `;
    if (results.custom_themes && results.custom_themes.length > 0) {
        results.custom_themes.forEach(theme => {
            html += `<li>${theme.label} <span class="ok">(${theme.file})</span></li>`;
        });
    } else {
        html += `<li>Aucun thème personnalisé détecté</li>`;
    }
    html += `</ul></div>`;

    // 5a-ter. Fichiers Vestikan (facultatifs — absence non bloquante)
    if (results.vestikan_files) {
        html += `
            <div class="integrity-section">
                <h3>Fichiers Vestikan</h3>
                <p class="hint">Ces fichiers sont facultatifs : s'ils sont absents, la connexion via Vestikan est simplement désactivée. Le site reste 100% fonctionnel.</p>
                <ul>
        `;
        for (const [file, present] of Object.entries(results.vestikan_files)) {
            const statusClass = present ? 'ok' : 'warn';
            const statusText  = present ? 'OK' : 'Absent';
            html += `<li>${file}: <span class="${statusClass}">${statusText}</span></li>`;
        }
        html += `</ul></div>`;
    }

    // 5a-quater. Fichiers Babengas (facultatifs — absence non bloquante)
    if (results.babengas_files) {
        html += `
            <div class="integrity-section">
                <h3>Fichiers Babengas</h3>
                <p class="hint">Ces fichiers sont facultatifs : s'ils sont absents, la vérification du décompte VF via Babelio est simplement désactivée. Le site reste 100% fonctionnel.</p>
                <ul>
        `;
        for (const [file, present] of Object.entries(results.babengas_files)) {
            const statusClass = present ? 'ok' : 'warn';
            const statusText  = present ? 'OK' : 'Absent';
            html += `<li>${file}: <span class="${statusClass}">${statusText}</span></li>`;
        }
        html += `</ul></div>`;
    }

    // 5b. Structure de la base de données (MangaUpdates)
    if (results.db_structure) {
        html += `
            <div class="integrity-section">
                <h3>Base de données (MangaUpdates)</h3>
                <ul>
        `;
        for (const [label, ok] of Object.entries(results.db_structure)) {
            html += `<li>${label} : <span class="${ok ? 'ok' : 'error'}">${ok ? 'OK' : 'Manquant'}</span></li>`;
        }
        html += `
                </ul>
            </div>
        `;
    }

    // 5c. Connectivité de l'API MangaUpdates
    if (results.mangaupdates_api) {
        const api = results.mangaupdates_api;
        html += `
            <div class="integrity-section">
                <h3>API MangaUpdates</h3>
                <ul>
                    <li>Accès à l'API : <span class="${api.ok ? 'ok' : 'error'}">${api.ok ? 'OK' : 'Échec'}</span>${(!api.ok && api.http) ? ` (HTTP ${api.http})` : ''}</li>
                    ${(!api.ok && api.error) ? `<li class="error">Erreur : ${api.error}</li>` : ''}
                    <li>Entrées en cache : ${api.cache_count ?? 0}</li>
                </ul>
            </div>
        `;
    }

    // 6. Version
    html += `
        <div class="integrity-section">
            <h3>Version du site</h3>
            <ul>
                <li>Version actuelle : ${results.version.current}</li>
                <li>Dernière version : ${results.version.latest || 'Inconnue'}</li>
                ${results.version.needs_update ?
                    `<li class="error">Une nouvelle version est disponible !</li>` : ''}
            </ul>
        </div>
    `;

    // 7. Informations sur le site
    html += `
        <div class="integrity-section">
            <h3>Informations sur le site</h3>
            <ul>
                <li>URL du site : <a href="${results.site_info.site_url}" target="_blank">${results.site_info.site_url}</a></li>
                <li>HTTPS : <span class="${results.site_info.uses_https ? 'ok' : 'error'}">${results.site_info.uses_https ? 'Activé' : 'Non activé'}</span></li>
                <li>Taille du dossier uploads (vignettes) : ${results.site_info.uploads_size}</li>
                <li>Taille maximale des fichiers téléversés : ${results.site_info.max_upload_size}</li>
                <li>Taille effective maximale : ${results.site_info.effective_max_upload_size}</li>
            </ul>
        </div>
    `;

    // 8. Informations serveur
    html += `
        <div class="integrity-section">
            <h3>Informations sur le serveur</h3>
            <ul>
                <li>Architecture serveur : ${results.site_info.server_info.server_architecture}</li>
                <li>Serveur web : ${results.site_info.server_info.server_software}</li>
                <li>Version de PHP : ${results.site_info.server_info.php_version}</li>
                <li>Limite d'exécution PHP : ${results.site_info.server_info.max_execution_time} secondes</li>
                <li>Limite de mémoire PHP : ${results.site_info.server_info.memory_limit}</li>
            </ul>
        </div>
    `;

    html += `</div>`;
    container.innerHTML = html;

    // Événements boutons de nettoyage
    if (results.duplicates.collection_wishlist.length > 0 || results.duplicates.deleted_loans.length > 0) {
        document.getElementById('clean-duplicates-btn').addEventListener('click', () => {
            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir nettoyer les doublons ?').then((confirmed) => {
                if (confirmed) {
                    fetch('page-outils.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'tool_action=clean_duplicates'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showSuccessModal(data.message);
                            window.location.reload();
                        } else {
                            showErrorModal(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        showErrorModal('Une erreur est survenue.');
                    });
                }
            });
        });
    }

    if (results.orphaned_images.length > 0) {
        document.getElementById('clean-orphaned-images-btn').addEventListener('click', () => {
            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer les images orphelines ?').then((confirmed) => {
                if (confirmed) {
                    fetch('page-outils.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'tool_action=clean_orphaned_images'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showSuccessModal(data.message);
                            window.location.reload();
                        } else {
                            showErrorModal(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        showErrorModal('Une erreur est survenue.');
                    });
                }
            });
        });
    }

    if (Object.values(results.forbidden_files).some(ok => !ok)) {
        document.getElementById('clean-forbidden-files-btn').addEventListener('click', () => {
            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer les fichiers interdits ?').then((confirmed) => {
                if (confirmed) {
                    fetch('page-outils.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'tool_action=clean_forbidden_files'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showSuccessModal(data.message);
                            window.location.reload();
                        } else {
                            showErrorModal(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        showErrorModal('Une erreur est survenue.');
                    });
                }
            });
        });
    }
}

// Vérification d'intégrité : bouton de la section dédiée de la page « Outils »
document.addEventListener('click', (e) => {
    if (!e.target.closest('#check-integrity-btn')) return;
    const button   = document.getElementById('check-integrity-btn');
    const textSpan = document.getElementById('check-integrity-text');
    const spinner  = document.getElementById('check-integrity-spinner');

    button.disabled = true;
    textSpan.textContent = 'Vérification en cours...';
    spinner.style.display = 'inline-block';

    fetch('page-outils.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'tool_action=check_integrity'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayIntegrityResults(data.results);
        } else {
            showErrorModal('Une erreur est survenue lors de la vérification.');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showErrorModal('Une erreur est survenue.');
    })
    .finally(() => {
        button.disabled = false;
        textSpan.textContent = "Vérifier l'intégrité";
        spinner.style.display = 'none';
    });
});
