// Ouverture de la modale "Outils"
document.getElementById('open-tools-modal').addEventListener('click', () => {
    modals['tools'].modal.classList.add('modal-active');
    loadBackupsList();
    addIntegrityCheckButton();
});

// Création d'une sauvegarde
document.getElementById('create-backup-btn').addEventListener('click', () => {
    const button = document.getElementById('create-backup-btn');
    const textSpan = document.getElementById('create-backup-text');
    const spinner = document.getElementById('create-backup-spinner');

    button.disabled = true;
    textSpan.textContent = 'Création en cours...';
    spinner.style.display = 'inline-block';

    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
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
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
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
                <a href="saves/${backup.name}" download class="button button-opt">Télécharger</a>
                <button class="delete-backup-btn" data-backup-file="${backup.name}">Supprimer</button>
            </div>
        `;
        backupsListDiv.appendChild(backupDiv);
    });

    // Ajouter les événements aux boutons de suppression
    document.querySelectorAll('.delete-backup-btn').forEach(button => {
        button.addEventListener('click', function() {
            const backupFile = this.dataset.backupFile;
            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer cette sauvegarde ?').then((confirmed) => {
                if (confirmed) {
                    fetch('admin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
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
    const modalContent = document.querySelector('#tools-modal .modal-content');
    let html = `
        <div class="integrity-header">
            <h2>Résultats de la vérification d'intégrité</h2>
        </div>
        <div class="integrity-results">
    `;

    // 1. Vérification de l'existence des fichiers/dossiers
    html += `
        <div class="integrity-section">
            <h3>Existence des fichiers/dossiers</h3>
            <ul>
    `;
    for (const [file, exists] of Object.entries(results.file_existence)) {
        html += `<li>${file}: <span class="${exists ? 'ok' : 'error'}">${exists ? 'OK' : 'Manquant'}</span></li>`;
    }
    html += `</ul></div>`;

    // 2. Vérification de l'absence de generate_password.php
    html += `
        <div class="integrity-section">
            <h3>Fichiers interdits</h3>
            <ul>
    `;
    for (const [file, ok] of Object.entries(results.forbidden_files)) {
        html += `<li>${file}: <span class="${ok ? 'ok' : 'error'}">${ok ? 'Absent' : 'Présent'}</span></li>`;
    }
    html += `</ul>`;

    if (!results.forbidden_files['generate_password.php']) {
        html += `
            <button id="clean-forbidden-files-btn" class="button button-opt">
                Supprimer les fichiers interdits
            </button>
        `;
    }
    html += `</div>`;

    // 3. Vérification des permissions
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

    // 4. Vérification des doublons
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

    // 5. Vérification des images orphelines
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

    // 6. Vérification de la version
    html += `
        <div class="integrity-section">
            <h3>Version du site</h3>
            <ul>
                <li>Version actuelle : ${results.version.current}</li>
                <li>Dernière version : ${results.version.latest || 'Inconnue'}</li>
                ${results.version.latest && results.version.current !== results.version.latest ?
                    `<li class="error">Une nouvelle version est disponible !</li>` : ''}
            </ul>
        </div>
    `;

    // 7. Informations sur le site et le serveur
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

    // 8. Informations sur le serveur
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
    modalContent.innerHTML = html;

    // Ajout des événements pour les boutons de nettoyage
    if (results.duplicates.collection_wishlist.length > 0 || results.duplicates.deleted_loans.length > 0) {
        document.getElementById('clean-duplicates-btn').addEventListener('click', () => {
            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir nettoyer les doublons ?').then((confirmed) => {
                if (confirmed) {
                    fetch('admin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
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
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
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

    if (!results.forbidden_files['generate_password.php']) {
        document.getElementById('clean-forbidden-files-btn').addEventListener('click', () => {
            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer les fichiers interdits ?').then((confirmed) => {
                if (confirmed) {
                    fetch('admin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
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

// Ajouter un bouton pour la vérification d'intégrité dans la modale "Outils"
function addIntegrityCheckButton() {
    if (document.getElementById('check-integrity-btn')) return;

    const toolsModalContent = document.querySelector('#tools-modal .modal-content');
    const integritySection = document.createElement('div');
    integritySection.className = 'tools-section';
    integritySection.innerHTML = `
        <h3>Vérification d'intégrité</h3>
        <p>Vérifie l'intégrité de votre site et de vos données.</p>
        <button id="check-integrity-btn" class="button button-opt">
            <span id="check-integrity-text">Vérifier l'intégrité</span>
            <span id="check-integrity-spinner" class="spinner" style="display: none;"></span>
        </button>
    `;
    toolsModalContent.appendChild(integritySection);

    document.getElementById('check-integrity-btn').addEventListener('click', () => {
        const button = document.getElementById('check-integrity-btn');
        const textSpan = document.getElementById('check-integrity-text');
        const spinner = document.getElementById('check-integrity-spinner');

        button.disabled = true;
        textSpan.textContent = 'Vérification en cours...';
        spinner.style.display = 'inline-block';

        fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
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
            textSpan.textContent = 'Vérifier l\'intégrité';
            spinner.style.display = 'none';
        });
    });
}