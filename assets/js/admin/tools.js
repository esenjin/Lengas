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
    const rootFiles = ['index.php', 'admin.php', 'stats.php', 'config.php', 'login.php', 'logout.php', '.htaccess'];
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
        'fonctions/series.php', 'fonctions/wishlist.php', 'fonctions/volumes.php', 'fonctions/unread.php'
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
    const includeFiles = ['includes/mangaupdates.php', 'includes/auth.php', 'includes/helpers.php'];
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
        'assets/css/_stats.css', 'assets/css/_utils.css', 'assets/css/_variables.css'
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
        'assets/js/admin/modals.js', 'assets/js/admin/pagination.js', 'assets/js/admin/main.js',
        'assets/js/admin/read.js', 'assets/js/admin/unread.js'
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
    mu_still_ongoing:           { icon: '🔄', label: 'Publication en cours (MangaUpdates)' },
    mu_complete_unmarked:       { icon: '✔️', label: 'Terminée selon MangaUpdates' },
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

        const header = document.createElement('div');
        header.className = 'coherence-series-name';
        header.textContent = item.series;
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
        if (textEl) textEl.textContent = 'Rechercher les correspondances';
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
