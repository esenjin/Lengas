// Ouverture de la modale "Outils"
document.getElementById('open-tools-modal').addEventListener('click', () => {
    modals['tools'].modal.classList.add('modal-active');
    loadBackupsList();
});

// Création d'une sauvegarde
document.getElementById('create-backup-btn').addEventListener('click', () => {
    const button   = document.getElementById('create-backup-btn');
    const textSpan = document.getElementById('create-backup-text');
    const spinner  = document.getElementById('create-backup-spinner');

    button.disabled = true;
    textSpan.textContent = 'Création en cours...';
    spinner.style.display = 'inline-block';

    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'backup_action=create_backup'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccessModal(data.message);
            loadBackupsList();
        } else {
            showErrorModal(data.message);
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showErrorModal('Une erreur est survenue.');
    })
    .finally(() => {
        button.disabled = false;
        textSpan.textContent = 'Créer une sauvegarde';
        spinner.style.display = 'none';
    });
});

// Charger la liste des sauvegardes
function loadBackupsList() {
    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'backup_action=list_backups'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayBackupsList(data.backups);
        } else {
            console.error('Erreur lors du chargement des sauvegardes.');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
    });
}

// Afficher la liste des sauvegardes
function displayBackupsList(backups) {
    const backupsListDiv = document.getElementById('backups-list');
    backupsListDiv.innerHTML = '';

    if (backups.length === 0) {
        backupsListDiv.innerHTML = '<p>Aucune sauvegarde disponible.</p>';
        return;
    }

    backups.forEach(backup => {
        const backupDiv = document.createElement('div');
        backupDiv.className = 'backup-item';
        backupDiv.innerHTML = `
            <p><strong>${backup.name}</strong> (${backup.date})</p>
            <div class="backup-actions">
                <a href="saves/${backup.name}" download class="button button-oas">Télécharger</a>
                <button class="delete-backup-btn" data-backup-file="${backup.name}">Supprimer</button>
            </div>
        `;
        backupsListDiv.appendChild(backupDiv);
    });

    document.querySelectorAll('.delete-backup-btn').forEach(button => {
        button.addEventListener('click', function() {
            const backupFile = this.dataset.backupFile;
            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer cette sauvegarde ?').then((confirmed) => {
                if (confirmed) {
                    fetch('admin.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `backup_action=delete_backup&backup_file=${encodeURIComponent(backupFile)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            loadBackupsList();
                        } else {
                            alert(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        alert('Une erreur est survenue.');
                    });
                }
            });
        });
    });
}

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
    const rootFiles = ['index.php', 'admin.php', 'stats.php', 'config.php', 'login.php', 'logout.php', '.htaccess', 'page-prets.php', 'page-wishlist.php'];
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
        'fonctions/series.php', 'fonctions/wishlist.php', 'fonctions/volumes.php', 'fonctions/stats_compute.php'
    ];
    functionFiles.forEach(file => {
        html += `<li>${file}: <span class="${results.file_existence[file] ? 'ok' : 'error'}">${results.file_existence[file] ? 'OK' : 'Manquant'}</span></li>`;
    });
    html += `</ul></div><br>`;

    html += `
                <div class="file-category">
                    <h4>Fichiers includes</h4>
                    <ul>
    `;
    const includeFiles = ['includes/mangaupdates.php', 'includes/auth.php', 'includes/helpers.php', 'includes/sidebar.php'];
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
        'assets/css/_sidebar.css', 'assets/css/_pages.css'
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
        'assets/js/admin/loans.js', 'assets/js/admin/tools.js', 'assets/js/admin/autocomplete.js',
        'assets/js/admin/modals.js', 'assets/js/admin/pagination.js', 'assets/js/admin/main.js'
    ];
    jsFiles.forEach(file => {
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
                    fetch('admin.php', {
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
                    fetch('admin.php', {
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
                    fetch('admin.php', {
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

// Vérification d'intégrité : le bouton est désormais statique (onglet « Vérification d'intégrité »)
document.addEventListener('click', (e) => {
    if (!e.target.closest('#check-integrity-btn')) return;
    const button   = document.getElementById('check-integrity-btn');
    const textSpan = document.getElementById('check-integrity-text');
    const spinner  = document.getElementById('check-integrity-spinner');

    button.disabled = true;
    textSpan.textContent = 'Vérification en cours...';
    spinner.style.display = 'inline-block';

    fetch('admin.php', {
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

// Onglets de la modale « Outils »
document.addEventListener('click', (e) => {
    const tab = e.target.closest('.tools-tab');
    if (!tab) return;
    const name  = tab.dataset.tab;
    const modal = document.getElementById('tools-modal');
    if (!modal) return;
    modal.querySelectorAll('.tools-tab').forEach(t => t.classList.toggle('tools-tab--active', t === tab));
    modal.querySelectorAll('.tools-tab-panel').forEach(p => {
        p.classList.toggle('tools-tab-panel--active', p.dataset.tabPanel === name);
    });
});

// ──────────────────────────────────────────────────────────────────────────────
// Modale « Incohérences »
// ──────────────────────────────────────────────────────────────────────────────

document.getElementById('open-coherences-modal').addEventListener('click', () => {
    modals['coherences'].modal.classList.add('modal-active');
    loadCoherences();
});

function loadCoherences() {
    const container = document.getElementById('coherences-results');
    container.innerHTML = '<p class="loading-text">Analyse en cours…</p>';

    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'tool_action=check_coherence'
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            container.innerHTML = '<p class="error-text">Erreur lors de l\'analyse.</p>';
            return;
        }
        renderCoherences(data.issues);
    })
    .catch(() => {
        container.innerHTML = '<p class="error-text">Une erreur est survenue.</p>';
    });
}

const COHERENCE_LABELS = {
    no_volumes:                 { icon: '📭', label: 'Série vide' },
    multiple_last:              { icon: '🔁', label: 'Plusieurs « derniers »' },
    wrong_last:                 { icon: '🏷️', label: 'Dernier mal placé' },
    missing_volumes:            { icon: '🕳️', label: 'Tomes manquants' },
    duplicate_volumes:          { icon: '👯', label: 'Doublons' },
    invalid_number:             { icon: '⚠️', label: 'Numéro invalide' },
    finished_no_last:           { icon: '🏁', label: 'Terminée sans dernier' },
    last_but_not_finished:      { icon: '🔖', label: 'Dernier sans fin' },
    sequence_not_starting_at_1: { icon: '1️⃣',  label: 'Ne commence pas à 1' },
    read_elsewhere_unread:      { icon: '👁️', label: 'Lue ailleurs — tomes non lus' },
    mu_still_ongoing:           { icon: '🔄', label: 'Publication en cours (MangaUpdates)' },
    mu_complete_unmarked:       { icon: '✔️', label: 'Terminée selon MangaUpdates' },
    mu_more_volumes:            { icon: '📦', label: 'Plus de tomes que MangaUpdates' },
    loan_deleted_series:        { icon: '👻', label: 'Prêt — série supprimée' },
    loan_read_elsewhere:        { icon: '📤', label: 'Prêt — lue ailleurs' },
};

function renderCoherences(issues) {
    const container = document.getElementById('coherences-results');
    container.innerHTML = '';

    const summaryDiv = document.createElement('div');
    summaryDiv.className = 'coherences-summary';

    if (issues.length === 0) {
        summaryDiv.innerHTML = '<p class="coherences-ok">✅ Aucune incohérence détectée dans votre collection.</p>';
        container.appendChild(summaryDiv);
        return;
    }

    const totalProblems = issues.reduce((acc, s) => acc + s.problems.length, 0);
    summaryDiv.innerHTML = `<p class="coherences-count">
        <strong>${issues.length}</strong> série(s) concernée(s) — 
        <strong>${totalProblems}</strong> incohérence(s) au total.
    </p>`;
    container.appendChild(summaryDiv);

    const allTypes = [...new Set(issues.flatMap(s => s.problems.map(p => p.type)))];
    if (allTypes.length > 1) {
        const filterDiv = document.createElement('div');
        filterDiv.className = 'coherences-filters';

        const allBtn = document.createElement('button');
        allBtn.className = 'button button-sm filter-btn active';
        allBtn.dataset.filter = 'all';
        allBtn.textContent = 'Tous';
        filterDiv.appendChild(allBtn);

        allTypes.forEach(type => {
            const btn = document.createElement('button');
            btn.className = 'button button-sm filter-btn';
            btn.dataset.filter = type;
            const meta = COHERENCE_LABELS[type] || { icon: '❓', label: type };
            btn.textContent = `${meta.icon} ${meta.label}`;
            filterDiv.appendChild(btn);
        });

        filterDiv.addEventListener('click', e => {
            const btn = e.target.closest('.filter-btn');
            if (!btn) return;
            filterDiv.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            applyCoherenceFilter(btn.dataset.filter);
        });

        container.appendChild(filterDiv);
    }

    const listDiv = document.createElement('div');
    listDiv.id = 'coherences-list';

    issues.forEach(item => {
        const block = document.createElement('div');
        block.className = 'coherence-series-block';
        block.dataset.types = item.problems.map(p => p.type).join(' ');
        block.dataset.seriesId = item.series_id || '';

        const header = document.createElement('div');
        header.className = 'coherence-series-name';

        const nameSpan = document.createElement('span');
        nameSpan.textContent = item.series;
        header.appendChild(nameSpan);

        if (item.mangaupdates_url) {
            const muBadge = document.createElement('a');
            muBadge.href = item.mangaupdates_url;
            muBadge.target = '_blank';
            muBadge.rel = 'noopener';
            muBadge.className = 'mu-badge';
            muBadge.title = 'Voir sur MangaUpdates';
            muBadge.innerHTML = '<img src="assets/img/mulogo.png" alt="MangaUpdates" class="mu-logo">';
            header.appendChild(muBadge);
        }

        if (item.series_id) {
            // Issues exclusivement liées aux prêts → lien vers page-prets
            const loanTypes = new Set(['loan_deleted_series', 'loan_read_elsewhere']);
            const isLoanIssue = item.problems.every(p => loanTypes.has(p.type));

            if (isLoanIssue) {
                const loansLink = document.createElement('a');
                loansLink.href = 'page-prets.php';
                loansLink.className = 'button button-sm button-otl';
                loansLink.textContent = 'Gérer les prêts';
                header.appendChild(loansLink);
            } else {
                const editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'button button-sm cedit-open-btn';
                editBtn.dataset.seriesId = item.series_id;
                editBtn.textContent = 'Modifier';
                header.appendChild(editBtn);
            }
        }

        block.appendChild(header);

        const ul = document.createElement('ul');
        ul.className = 'coherence-problems';
        item.problems.forEach(prob => {
            const meta = COHERENCE_LABELS[prob.type] || { icon: '❓', label: prob.type };
            const li = document.createElement('li');
            li.className = `coherence-item coherence-type-${prob.type}`;
            li.innerHTML = `<span class="coherence-icon">${meta.icon}</span>
                            <span class="coherence-tag">${meta.label}</span>
                            <span class="coherence-msg">${prob.message}</span>`;
            ul.appendChild(li);
        });
        block.appendChild(ul);
        listDiv.appendChild(block);
    });

    container.appendChild(listDiv);
}

// ──────────────────────────────────────────────────────────────────────────────
// Modale d'édition rapide depuis Incohérences
// ──────────────────────────────────────────────────────────────────────────────

// Délégation : clic sur un bouton « Modifier » dans la liste des incohérences
document.getElementById('coherences-results').addEventListener('click', (e) => {
    const btn = e.target.closest('.cedit-open-btn');
    if (!btn) return;
    const seriesId = btn.dataset.seriesId;
    openCoherenceEdit(seriesId);
});

function openCoherenceEdit(seriesId) {
    // Chercher la série dans window.seriesData
    const series = (window.seriesData || []).find(s => s.id === seriesId);
    if (!series) {
        showErrorModal('Données de la série introuvables. Veuillez recharger la page.');
        return;
    }

    // Remplir les infos lecture seule
    document.getElementById('cedit-series-id').value = seriesId;
    document.getElementById('cedit-name').textContent       = series.name || '';
    document.getElementById('cedit-author').textContent     = series.author || '—';
    document.getElementById('cedit-publisher').textContent  = series.publisher || '—';
    const cats = Array.isArray(series.categories)
        ? series.categories.filter(c => c && c.trim()).join(', ')
        : (series.categories || '');
    document.getElementById('cedit-categories').textContent = cats || '—';

    // Statut de publication
    const statusSel = document.getElementById('cedit-status');
    statusSel.value = series.status || 'en cours';

    // Lue ailleurs
    document.getElementById('cedit-read-elsewhere').checked = !!(series.read_elsewhere);

    // Feedback
    document.getElementById('cedit-feedback').textContent = '';

    // Construire la liste des tomes
    buildCeditVolumesList(series.volumes || []);

    modals['coherence-edit'].modal.classList.add('modal-active');
}

function buildCeditVolumesList(volumes) {
    const container = document.getElementById('cedit-volumes-list');
    container.innerHTML = '';

    if (!volumes || volumes.length === 0) {
        container.innerHTML = '<p class="cedit-no-volumes">Aucun tome dans cette série.</p>';
        return;
    }

    // Trier par numéro
    const sorted = [...volumes].sort((a, b) => a.number - b.number);

    sorted.forEach((vol, i) => {
        // Trouver l'index réel dans le tableau original (volumes n'est pas forcément trié)
        const realIndex = volumes.indexOf(vol);

        const row = document.createElement('div');
        row.className = 'cedit-volume-row';
        row.dataset.volIndex = realIndex;
        row.dataset.volNumber = vol.number;

        const numLabel = document.createElement('span');
        numLabel.className = 'cedit-vol-num';
        numLabel.textContent = `Tome ${vol.number}`;
        row.appendChild(numLabel);

        // Statut de lecture
        const statusSel = document.createElement('select');
        statusSel.className = 'cedit-select cedit-select--sm cedit-vol-status';
        ['à lire', 'en cours', 'terminé'].forEach(s => {
            const opt = document.createElement('option');
            opt.value = s;
            opt.textContent = s.charAt(0).toUpperCase() + s.slice(1);
            if (vol.status === s) opt.selected = true;
            statusSel.appendChild(opt);
        });
        row.appendChild(statusSel);

        // Tag "dernier tome"
        const lastLabel = document.createElement('label');
        lastLabel.className = 'cedit-inline-check';
        const lastChk = document.createElement('input');
        lastChk.type = 'checkbox';
        lastChk.className = 'cedit-vol-last';
        lastChk.checked = !!vol.last;
        lastLabel.appendChild(lastChk);
        lastLabel.appendChild(document.createTextNode(' Dernier ✅'));
        row.appendChild(lastLabel);

        // Bouton supprimer
        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'button button-sm cedit-vol-delete';
        delBtn.title = 'Supprimer ce tome';
        delBtn.textContent = '✕';
        delBtn.addEventListener('click', () => {
            row.classList.toggle('cedit-vol-pending-delete');
            delBtn.classList.toggle('cedit-vol-delete--active');
        });
        row.appendChild(delBtn);

        container.appendChild(row);
    });
}

// Bouton « + Ajouter un tome » — insère directement le prochain numéro (max + 1)
document.getElementById('cedit-add-volume-btn').addEventListener('click', () => {
    const container = document.getElementById('cedit-volumes-list');

    // Calculer le prochain numéro : max de tous les tomes visibles (y compris les "new") + 1
    const allNums = [...container.querySelectorAll('.cedit-volume-row')]
        .map(r => parseInt(r.dataset.volNumber, 10))
        .filter(n => !isNaN(n));
    const nextNum = allNums.length > 0 ? Math.max(...allNums) + 1 : 1;

    // Retirer le placeholder "aucun tome" s'il est affiché
    const placeholder = container.querySelector('.cedit-no-volumes');
    if (placeholder) placeholder.remove();

    // Créer la ligne directement
    const row = document.createElement('div');
    row.className = 'cedit-volume-row cedit-vol-pending-add';
    row.dataset.volIndex  = 'new';
    row.dataset.volNumber = nextNum;

    const numLabel = document.createElement('span');
    numLabel.className = 'cedit-vol-num';
    numLabel.textContent = `Tome ${nextNum} (nouveau)`;
    row.appendChild(numLabel);

    const statusSel = document.createElement('select');
    statusSel.className = 'cedit-select cedit-select--sm cedit-vol-status';
    ['à lire', 'en cours', 'terminé'].forEach(s => {
        const opt = document.createElement('option');
        opt.value = s;
        opt.textContent = s.charAt(0).toUpperCase() + s.slice(1);
        if (s === 'à lire') opt.selected = true;
        statusSel.appendChild(opt);
    });
    row.appendChild(statusSel);

    const lastLabel = document.createElement('label');
    lastLabel.className = 'cedit-inline-check';
    const lastChk = document.createElement('input');
    lastChk.type = 'checkbox';
    lastChk.className = 'cedit-vol-last';
    lastChk.checked = false;
    lastLabel.appendChild(lastChk);
    lastLabel.appendChild(document.createTextNode(' Dernier ✅'));
    row.appendChild(lastLabel);

    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'button button-sm cedit-vol-delete';
    delBtn.title = 'Annuler cet ajout';
    delBtn.textContent = '✕';
    delBtn.addEventListener('click', () => row.remove());
    row.appendChild(delBtn);

    container.appendChild(row);
    document.getElementById('cedit-feedback').textContent = '';
});

// Bouton Enregistrer de la modale d'édition rapide
document.getElementById('cedit-save-btn').addEventListener('click', () => {
    const seriesId = document.getElementById('cedit-series-id').value;
    if (!seriesId) return;

    const saveBtn    = document.getElementById('cedit-save-btn');
    const saveText   = document.getElementById('cedit-save-text');
    const saveSpinner= document.getElementById('cedit-save-spinner');
    const feedback   = document.getElementById('cedit-feedback');

    saveBtn.disabled = true;
    saveText.textContent = 'Enregistrement…';
    saveSpinner.style.display = 'inline-block';
    feedback.textContent = '';

    // Collecter les données
    const newStatus      = document.getElementById('cedit-status').value;
    const readElsewhere  = document.getElementById('cedit-read-elsewhere').checked ? '1' : '0';

    const deleteIndexes  = [];
    const volumesUpdates = [];
    const addVolumes     = [];

    document.querySelectorAll('#cedit-volumes-list .cedit-volume-row').forEach(row => {
        const idx    = row.dataset.volIndex;
        const num    = parseInt(row.dataset.volNumber, 10);
        const status = row.querySelector('.cedit-vol-status')?.value || 'à lire';
        const isLast = !!(row.querySelector('.cedit-vol-last')?.checked);

        if (idx === 'new') {
            // Tome à ajouter
            if (!row.classList.contains('cedit-vol-pending-delete')) {
                addVolumes.push({ number: num, status, last: isLast });
            }
        } else {
            if (row.classList.contains('cedit-vol-pending-delete')) {
                // Tome à supprimer
                deleteIndexes.push(parseInt(idx, 10));
            } else {
                // Tome à mettre à jour
                volumesUpdates.push({ index: parseInt(idx, 10), status, last: isLast });
            }
        }
    });

    const params = new URLSearchParams({
        tool_action:     'coherence_quick_edit',
        series_id:       seriesId,
        series_status:   newStatus,
        read_elsewhere:  readElsewhere,
        delete_volumes:  JSON.stringify(deleteIndexes),
        volumes_updates: JSON.stringify(volumesUpdates),
        add_volumes:     JSON.stringify(addVolumes),
    });

    fetch('admin.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    params.toString(),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Mettre à jour window.seriesData pour que la liste principale soit cohérente
            if (data.series && window.seriesData) {
                const idx = window.seriesData.findIndex(s => s.id === seriesId);
                if (idx !== -1) window.seriesData[idx] = data.series;
            }
            window.coherenceEditDirty = true;
            feedback.style.color = 'var(--success-color)';
            feedback.textContent = '✓ Modifications enregistrées.';
            // Rebâtir la liste des tomes avec les données fraîches
            if (data.series && data.series.volumes) {
                buildCeditVolumesList(data.series.volumes);
            }
        } else {
            feedback.style.color = 'var(--error-color)';
            feedback.textContent = data.message || 'Une erreur est survenue.';
        }
    })
    .catch(() => {
        feedback.style.color = 'var(--error-color)';
        feedback.textContent = 'Erreur réseau. Veuillez réessayer.';
    })
    .finally(() => {
        saveBtn.disabled = false;
        saveText.textContent = 'Enregistrer';
        saveSpinner.style.display = 'none';
    });
});

function applyCoherenceFilter(filter) {
    document.querySelectorAll('#coherences-list .coherence-series-block').forEach(block => {
        if (filter === 'all') {
            block.style.display = '';
        } else {
            block.style.display = block.dataset.types.split(' ').includes(filter) ? '' : 'none';
        }
    });
}


// ──────────────────────────────────────────────────────────────────────────────
// Outil « Associer MangaUpdates » (modale Outils)
// Recherche une fiche MangaUpdates pour chaque série sans URL, puis laisse
// l'utilisateur valider la bonne correspondance avant l'enregistrement.
// ──────────────────────────────────────────────────────────────────────────────

// Échappements locaux
function muEscHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function muEscAttr(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Délégation : le bouton est dans le HTML statique de la modale Outils
document.addEventListener('click', (e) => {
    if (e.target.closest('#mu-associate-btn')) {
        loadMuAssociate();
    } else if (e.target.closest('#mu-associate-save-btn')) {
        saveMuAssociations();
    } else if (e.target.closest('#mu-genres-btn')) {
        loadMuGenres();
    } else if (e.target.closest('#mu-genres-save-btn')) {
        saveMuGenres();
    }
});

// Bord vert sur l'option choisie (uniquement une vraie correspondance, pas « Aucune »)
document.addEventListener('change', (e) => {
    const radio = e.target.closest('#mu-associate-form input[type="radio"]');
    if (!radio) return;
    const optionsWrap = radio.closest('.mu-associate-options');
    if (!optionsWrap) return;
    optionsWrap.querySelectorAll('.mu-associate-option').forEach(lbl => lbl.classList.remove('is-selected'));
    const label = radio.closest('.mu-associate-option');
    if (label && radio.value) label.classList.add('is-selected');
});

// Lance l'association via le flux SSE : toutes les séries sans URL, avec progression
let muAssociateSource = null;
function loadMuAssociate() {
    const btn      = document.getElementById('mu-associate-btn');
    const textEl   = document.getElementById('mu-associate-text');
    const spinner  = document.getElementById('mu-associate-spinner');
    const progress = document.getElementById('mu-associate-progress');
    const results  = document.getElementById('mu-associate-results');
    if (!results || !progress) return;

    if (muAssociateSource) { muAssociateSource.close(); muAssociateSource = null; }

    if (btn) btn.disabled = true;
    if (textEl) textEl.textContent = 'Recherche en cours...';
    if (spinner) spinner.style.display = 'inline-block';

    results.innerHTML = '<div class="mu-associate-form" id="mu-associate-form"></div>';

    let current = 0, total = 0, currentName = '';
    const renderProgress = () => {
        const countText = total > 0 ? `${current} / ${total}` : `${current}`;
        progress.innerHTML =
            `<p class="analysis-progress">` +
            `<span class="progress-spinner"></span>` +
            `Recherche : <strong>${muEscHtml(currentName) || '…'}</strong> ` +
            `<span class="progress-count">(${countText})</span>` +
            `</p>`;
    };
    renderProgress();

    let anyMatch = false;
    const source = new EventSource('admin.php?action=mu_associate_stream');
    muAssociateSource = source;

    source.addEventListener('progress', (ev) => {
        const d = JSON.parse(ev.data);
        current = d.current; total = d.total; currentName = d.name;
        renderProgress();
    });

    source.addEventListener('match', (ev) => {
        const d = JSON.parse(ev.data);
        if (d.series) { appendMuAssociateSeries(d.series); anyMatch = true; }
    });

    source.addEventListener('done', (ev) => {
        const d = JSON.parse(ev.data);
        source.close(); muAssociateSource = null;
        finalizeMuAssociate(d, anyMatch);
        if (btn) btn.disabled = false;
        if (textEl) textEl.textContent = 'Relancer la recherche';
        if (spinner) spinner.style.display = 'none';
    });

    source.onerror = () => {
        source.close(); muAssociateSource = null;
        progress.innerHTML = '';
        if (!anyMatch) results.innerHTML = '<p class="error-text">La recherche a été interrompue. Veuillez réessayer.</p>';
        if (btn) btn.disabled = false;
        if (textEl) textEl.textContent = 'Recherche des liens';
        if (spinner) spinner.style.display = 'none';
    };
}

// Ajoute le bloc d'une série au formulaire, au fil du flux
function appendMuAssociateSeries(series) {
    const form = document.getElementById('mu-associate-form');
    if (!form) return;
    const list = series.results || [];

    const wrap = document.createElement('div');
    wrap.className = 'mu-associate-series';
    wrap.dataset.seriesId = series.id;

    let html = `<div class="mu-associate-series-name">${muEscHtml(series.name)}`;
    if (series.author) html += ` <small>${muEscHtml(series.author)}</small>`;
    html += `</div><div class="mu-associate-options">`;

    // Toujours « Aucune correspondance » par défaut (aucune présélection)
    html += `<label class="mu-associate-option">
                <input type="radio" name="mu_${muEscAttr(series.id)}" value="" checked>
                <span class="mu-cand-none">Aucune correspondance</span>
             </label>`;

    list.forEach(r => {
        const status = r.status_text ? translateMuStatus(r.status_text) : '';
        const meta   = [r.type, r.year, status].filter(Boolean).map(muEscHtml).join(' · ');
        const author = (r.authors && r.authors.length) ? r.authors.join(', ') : '';
        const badges =
            (r.author_match ? '<span class="mu-cand-badge mu-cand-badge--author">auteur ✓</span>' : '') +
            (r.title_match  ? '<span class="mu-cand-badge mu-cand-badge--title">titre ✓</span>' : '');
        html += `<label class="mu-associate-option">
                    <input type="radio" name="mu_${muEscAttr(series.id)}" value="${muEscAttr(r.url)}">
                    <span class="mu-cand-info">
                        <span class="mu-cand-title">${muEscHtml(r.title)} ${badges}</span>
                        ${meta ? `<span class="mu-cand-meta">${meta}</span>` : ''}
                        ${author ? `<span class="mu-cand-author">${muEscHtml(author)}</span>` : ''}
                    </span>
                    ${r.url ? `<a class="mu-cand-link" href="${muEscAttr(r.url)}" target="_blank" rel="noopener" onclick="event.stopPropagation()">fiche ↗</a>` : ''}
                 </label>`;
    });

    html += `</div>`;
    wrap.innerHTML = html;
    form.appendChild(wrap);
}

// Finalise : bouton d'enregistrement + récapitulatif des séries sans correspondance
function finalizeMuAssociate(d, anyMatch) {
    const progress = document.getElementById('mu-associate-progress');
    const results  = document.getElementById('mu-associate-results');
    if (progress) progress.innerHTML = '';
    if (!results) return;

    if (!anyMatch) {
        results.innerHTML = '<p class="mu-associate-empty">Aucune correspondance trouvée — vos séries possèdent peut-être déjà toutes une URL MangaUpdates. ✅</p>';
        return;
    }

    const saveBtn = document.createElement('button');
    saveBtn.id = 'mu-associate-save-btn';
    saveBtn.className = 'button button-ats';
    saveBtn.textContent = 'Enregistrer les correspondances';
    results.appendChild(saveBtn);

    const noRes = (d && d.no_results) || [];
    if (noRes.length > 0) {
        const det = document.createElement('details');
        det.className = 'mu-associate-noresults';
        det.innerHTML = `<summary>${noRes.length} série(s) sans correspondance trouvée</summary>` +
            `<ul>${noRes.map(n => `<li>${muEscHtml(n)}</li>`).join('')}</ul>`;
        results.appendChild(det);
    }
}

// Enregistre les correspondances sélectionnées
function saveMuAssociations() {
    const blocks = document.querySelectorAll('#mu-associate-results .mu-associate-series');
    const params = new URLSearchParams();
    params.set('tool_action', 'mu_associate_save');

    let count = 0;
    blocks.forEach(block => {
        const id  = block.dataset.seriesId;
        const sel = block.querySelector('input[type="radio"]:checked');
        if (sel && sel.value) {
            params.append(`associations[${id}]`, sel.value);
            count++;
        }
    });

    if (count === 0) {
        showCustomAlert('Information', 'Aucune correspondance sélectionnée.');
        return;
    }

    const saveBtn = document.getElementById('mu-associate-save-btn');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Enregistrement...'; }

    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showSuccessModal(`${data.saved} association(s) enregistrée(s).`);
            setTimeout(() => window.location.reload(), 900);
        } else {
            showErrorModal(data.message || "Erreur lors de l'enregistrement.");
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Enregistrer les correspondances'; }
        }
    })
    .catch(() => {
        showErrorModal('Une erreur est survenue.');
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Enregistrer les correspondances'; }
    });
}

// ──────────────────────────────────────────────────────────────────────────────
// Outil « Associer les genres » (modale Outils)
// Recherche les genres sur la fiche MangaUpdates de chaque série qui possède une
// URL mais aucun genre renseigné, les traduit en FR et les pré-remplit pour
// validation (par série ou en masse), avec édition possible.
// ──────────────────────────────────────────────────────────────────────────────

let muGenresSource = null;
function loadMuGenres() {
    const btn      = document.getElementById('mu-genres-btn');
    const textEl   = document.getElementById('mu-genres-text');
    const spinner  = document.getElementById('mu-genres-spinner');
    const progress = document.getElementById('mu-genres-progress');
    const results  = document.getElementById('mu-genres-results');
    if (!results || !progress) return;

    if (muGenresSource) { muGenresSource.close(); muGenresSource = null; }

    if (btn) btn.disabled = true;
    if (textEl) textEl.textContent = 'Recherche en cours...';
    if (spinner) spinner.style.display = 'inline-block';

    results.innerHTML = '<div class="mu-genres-form" id="mu-genres-form"></div>';

    let current = 0, total = 0, currentName = '';
    const renderProgress = () => {
        const countText = total > 0 ? `${current} / ${total}` : `${current}`;
        progress.innerHTML =
            `<p class="analysis-progress">` +
            `<span class="progress-spinner"></span>` +
            `Recherche : <strong>${muEscHtml(currentName) || '…'}</strong> ` +
            `<span class="progress-count">(${countText})</span>` +
            `</p>`;
    };
    renderProgress();

    let anyMatch = false;
    const source = new EventSource('admin.php?action=mu_genres_stream');
    muGenresSource = source;

    source.addEventListener('progress', (ev) => {
        const d = JSON.parse(ev.data);
        current = d.current; total = d.total; currentName = d.name;
        renderProgress();
    });

    source.addEventListener('match', (ev) => {
        const d = JSON.parse(ev.data);
        if (d.series) { appendMuGenresSeries(d.series); anyMatch = true; }
    });

    source.addEventListener('done', (ev) => {
        const d = JSON.parse(ev.data);
        source.close(); muGenresSource = null;
        finalizeMuGenres(d, anyMatch);
        if (btn) btn.disabled = false;
        if (textEl) textEl.textContent = 'Relancer la recherche';
        if (spinner) spinner.style.display = 'none';
    });

    source.onerror = () => {
        source.close(); muGenresSource = null;
        progress.innerHTML = '';
        if (!anyMatch) results.innerHTML = '<p class="error-text">La recherche a été interrompue. Veuillez réessayer.</p>';
        if (btn) btn.disabled = false;
        if (textEl) textEl.textContent = 'Recherche des genres';
        if (spinner) spinner.style.display = 'none';
    };
}

// Ajoute le bloc d'une série au formulaire, au fil du flux
function appendMuGenresSeries(series) {
    const form = document.getElementById('mu-genres-form');
    if (!form) return;
    const genres = (series.genres || []).join(', ');

    const wrap = document.createElement('div');
    wrap.className = 'mu-genres-series';
    wrap.dataset.seriesId = series.id;

    let html = `<div class="mu-genres-series-name">${muEscHtml(series.name)}`;
    if (series.author) html += ` <small>${muEscHtml(series.author)}</small>`;
    if (series.mangaupdates_url) {
        html += ` <a class="mu-cand-link" href="${muEscAttr(series.mangaupdates_url)}" target="_blank" rel="noopener">fiche ↗</a>`;
    }
    html += `</div>`;

    html += `<div class="mu-genres-row">
                <input type="text" class="mu-genres-input" value="${muEscAttr(genres)}"
                       placeholder="Genres (séparés par des virgules)" autocomplete="off">
                <button type="button" class="button button-ats mu-genres-validate-btn">Valider</button>
                <span class="mu-genres-feedback"></span>
             </div>`;

    wrap.innerHTML = html;
    form.appendChild(wrap);
}

// Finalise : bouton « tout valider » + récapitulatif des séries sans genre trouvé
function finalizeMuGenres(d, anyMatch) {
    const progress = document.getElementById('mu-genres-progress');
    const results  = document.getElementById('mu-genres-results');
    if (progress) progress.innerHTML = '';
    if (!results) return;

    if (!anyMatch) {
        results.innerHTML = '<p class="mu-associate-empty">Aucun genre à associer — vos séries avec une URL MangaUpdates possèdent peut-être déjà toutes des genres, ou aucun genre n\'est renseigné sur leur fiche. ✅</p>';
        return;
    }

    const saveBtn = document.createElement('button');
    saveBtn.id = 'mu-genres-save-btn';
    saveBtn.className = 'button button-ats';
    saveBtn.textContent = 'Tout valider';
    results.appendChild(saveBtn);

    const noRes = (d && d.no_results) || [];
    if (noRes.length > 0) {
        const det = document.createElement('details');
        det.className = 'mu-associate-noresults';
        det.innerHTML = `<summary>${noRes.length} série(s) sans genre trouvé</summary>` +
            `<ul>${noRes.map(n => `<li>${muEscHtml(n)}</li>`).join('')}</ul>`;
        results.appendChild(det);
    }
}

// Envoie un lot de genres (objet {series_id: "g1, g2"}) au serveur.
// onDone(success, savedCount) est appelé en fin de requête.
function postMuGenres(payload, onDone) {
    const params = new URLSearchParams();
    params.set('tool_action', 'mu_genres_save');
    Object.keys(payload).forEach(id => {
        params.append(`genres[${id}]`, payload[id]);
    });

    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => onDone(!!data.success, data.saved || 0, data.message))
    .catch(() => onDone(false, 0, 'Une erreur est survenue.'));
}

// Validation d'une seule série (bouton « Valider » de la ligne)
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.mu-genres-validate-btn');
    if (!btn) return;
    const block = btn.closest('.mu-genres-series');
    if (!block) return;

    const id       = block.dataset.seriesId;
    const input    = block.querySelector('.mu-genres-input');
    const feedback = block.querySelector('.mu-genres-feedback');
    const value    = input ? input.value : '';

    btn.disabled = true;
    if (feedback) { feedback.className = 'mu-genres-feedback'; feedback.textContent = 'Enregistrement…'; }

    postMuGenres({ [id]: value }, (success, saved, message) => {
        if (success) {
            block.classList.add('mu-genres-saved');
            if (input) input.disabled = true;
            if (feedback) { feedback.className = 'mu-genres-feedback is-success'; feedback.textContent = '✓ Enregistré'; }
            btn.textContent = 'Validé';
        } else {
            btn.disabled = false;
            if (feedback) { feedback.className = 'mu-genres-feedback is-error'; feedback.textContent = message || 'Erreur'; }
        }
    });
});

// Validation de toutes les séries non encore enregistrées (« Tout valider »)
function saveMuGenres() {
    const blocks = document.querySelectorAll('#mu-genres-results .mu-genres-series:not(.mu-genres-saved)');
    const payload = {};
    let count = 0;
    blocks.forEach(block => {
        const id    = block.dataset.seriesId;
        const input = block.querySelector('.mu-genres-input');
        payload[id] = input ? input.value : '';
        count++;
    });

    if (count === 0) {
        showCustomAlert('Information', 'Toutes les séries ont déjà été validées.');
        return;
    }

    const saveBtn = document.getElementById('mu-genres-save-btn');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Enregistrement...'; }

    postMuGenres(payload, (success, saved, message) => {
        if (success) {
            blocks.forEach(block => {
                block.classList.add('mu-genres-saved');
                const input = block.querySelector('.mu-genres-input');
                const btn   = block.querySelector('.mu-genres-validate-btn');
                const feedback = block.querySelector('.mu-genres-feedback');
                if (input) input.disabled = true;
                if (btn) { btn.disabled = true; btn.textContent = 'Validé'; }
                if (feedback) { feedback.className = 'mu-genres-feedback is-success'; feedback.textContent = '✓ Enregistré'; }
            });
            showSuccessModal(`${saved} série(s) mise(s) à jour.`);
            setTimeout(() => window.location.reload(), 900);
        } else {
            showErrorModal(message || "Erreur lors de l'enregistrement.");
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Tout valider'; }
        }
    });
}
