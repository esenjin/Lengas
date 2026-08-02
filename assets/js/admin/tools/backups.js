// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/backups.js — Outil « Sauvegardes »
//
// Création d'archives ZIP, export JSON, listage et suppression des
// sauvegardes existantes.
// ──────────────────────────────────────────────────────────────────────────

// Création d'une sauvegarde
document.getElementById('create-backup-btn').addEventListener('click', () => {
    const button   = document.getElementById('create-backup-btn');
    const textSpan = document.getElementById('create-backup-text');
    const spinner  = document.getElementById('create-backup-spinner');

    button.disabled = true;
    textSpan.textContent = 'Création en cours...';
    spinner.style.display = 'inline-block';

    fetch('outil-sauvegardes.php', {
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

// Export JSON de la base — télécharge un fichier .json
const exportJsonBtn = document.getElementById('export-json-btn');
if (exportJsonBtn) {
    exportJsonBtn.addEventListener('click', () => {
        const button   = exportJsonBtn;
        const textSpan = document.getElementById('export-json-text');
        const spinner  = document.getElementById('export-json-spinner');

        button.disabled = true;
        if (textSpan) textSpan.textContent = 'Export en cours...';
        if (spinner) spinner.style.display = 'inline-block';

        fetch('outil-sauvegardes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'backup_action=export_json'
        })
        .then(response => {
            if (!response.ok) throw new Error('Réponse invalide du serveur.');
            // Récupère le nom de fichier proposé par le serveur
            const disposition = response.headers.get('Content-Disposition') || '';
            let filename = 'lengas_export.json';
            const match = /filename="?([^"]+)"?/.exec(disposition);
            if (match) filename = match[1];
            return response.blob().then(blob => ({ blob, filename }));
        })
        .then(({ blob, filename }) => {
            const url = URL.createObjectURL(blob);
            const a   = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            showSuccessModal('Export JSON téléchargé avec succès.');
        })
        .catch(error => {
            console.error('Erreur:', error);
            showErrorModal("Une erreur est survenue lors de l'export JSON.");
        })
        .finally(() => {
            button.disabled = false;
            if (textSpan) textSpan.textContent = 'Exporter en JSON';
            if (spinner) spinner.style.display = 'none';
        });
    });
}

// Charger la liste des sauvegardes
function loadBackupsList() {
    fetch('outil-sauvegardes.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'backup_action=list_backups'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayBackupsList(data.backups, data);
        } else {
            console.error('Erreur lors du chargement des sauvegardes.');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
    });
}

// Afficher la liste des sauvegardes
function displayBackupsList(backups, meta) {
    const backupsListDiv = document.getElementById('backups-list');
    backupsListDiv.innerHTML = '';

    if (backups.length === 0) {
        backupsListDiv.innerHTML = '<p>Aucune sauvegarde disponible.</p>';
        return;
    }

    backups.forEach(backup => {
        const backupDiv = document.createElement('div');
        backupDiv.className = 'backup-item';

        // Détails : taille et, si disponible, nombre de fichiers
        const details = [];
        if (backup.size) details.push(backup.size);
        if (backup.file_count !== null && backup.file_count !== undefined) {
            details.push(`${backup.file_count} fichier${backup.file_count > 1 ? 's' : ''}`);
        }
        const detailsText = details.length ? ` — ${details.join(' • ')}` : '';

        backupDiv.innerHTML = `
            <p><strong>${backup.name}</strong> (${backup.date})${detailsText}</p>
            <div class="backup-actions">
                <a href="outil-sauvegardes.php?download_backup=${encodeURIComponent(backup.name)}" class="button button-oas">Télécharger</a>
                <button class="delete-backup-btn" data-backup-file="${backup.name}">Supprimer</button>
            </div>
        `;
        backupsListDiv.appendChild(backupDiv);
    });

    // Total occupé par l'ensemble des sauvegardes
    if (meta && meta.total_size) {
        const totalDiv = document.createElement('p');
        totalDiv.className = 'backups-total';
        totalDiv.innerHTML = `<strong>Total :</strong> ${backups.length} sauvegarde${backups.length > 1 ? 's' : ''} — ${meta.total_size}`;
        backupsListDiv.appendChild(totalDiv);
    }

    document.querySelectorAll('.delete-backup-btn').forEach(button => {
        button.addEventListener('click', function() {
            const backupFile = this.dataset.backupFile;
            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer cette sauvegarde ?').then((confirmed) => {
                if (confirmed) {
                    fetch('outil-sauvegardes.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `backup_action=delete_backup&backup_file=${encodeURIComponent(backupFile)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            loadBackupsList();
                        } else {
                            showErrorModal(data.message || 'Impossible de supprimer la sauvegarde.');
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        showErrorModal('Une erreur est survenue.');
                    });
                }
            });
        });
    });
}

// La page « Outils » affiche la liste dès son chargement.
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('backups-list')) loadBackupsList();
});
