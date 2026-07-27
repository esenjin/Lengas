// ─────────────────────────────────────────────────────────────────────────────
// assets/js/admin/volumes.js — Tomes (Mangathèque)
//
// L'édition d'un tome est ouverte par la délégation d'événements de
// pagination.js : les cartes, et donc les listes de tomes, sont injectées en
// AJAX. Le bloc qui vivait ici parcourait `.volumes-list li` au chargement de la
// page, alors qu'aucun tome n'y figure encore — du code mort, retiré.
//
// Le pendant de ce fichier pour l'Animethèque est episodes.js : un épisode ne
// s'ajoute pas, ne se supprime pas, et n'a ni tag collector ni « dernier
// épisode » à cocher.
// ─────────────────────────────────────────────────────────────────────────────

// Affiche le champ "Date de lecture" uniquement quand le statut est "terminé"
function updateReadAtVisibility() {
    const status = document.getElementById('edit-volume-status').value;
    const label = document.getElementById('edit-volume-read-at-label');
    label.style.display = (status === 'terminé') ? '' : 'none';
}
document.getElementById('edit-volume-status').addEventListener('change', updateReadAtVisibility);

// Gestion de la suppression d'un tome
document.getElementById('delete-volume-btn').addEventListener('click', function() {
    showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer ce tome ?').then((confirmed) => {
        if (confirmed) {
            const seriesId = document.getElementById('edit-series-id').value;
            const volumeIndex = document.getElementById('edit-volume-index').value;

            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `delete_volume=true&series_id=${encodeURIComponent(seriesId)}&volume_index=${volumeIndex}`
            })
            .then(response => {
                window.location.reload();
            })
            .catch(error => {
                alert('Une erreur est survenue: ' + error.message);
            });
        }
    });
});

// Gestion du formulaire "Ajouter des tomes" (modale add-multiple-volumes)
document.querySelector('#add-multiple-volumes-modal form').addEventListener('submit', function(e) {
    e.preventDefault();

    const seriesId = document.getElementById('multiple-selected-series-id').value;
    const volumesCount = parseInt(document.querySelector('#add-multiple-volumes-modal [name="volumes_count"]').value);
    const status = document.querySelector('#add-multiple-volumes-modal [name="status"]').value;
    const isCollector = document.querySelector('#add-multiple-volumes-modal [name="is_collector"]').checked ? 1 : 0;
    const isLast = document.querySelector('#add-multiple-volumes-modal [name="is_last"]').checked ? 1 : 0;

    if (!seriesId || volumesCount <= 0) {
        alert('Veuillez sélectionner une série et indiquer un nombre de tomes valide.');
        return;
    }

    const formData = new FormData();
    formData.append('series_id', seriesId);
    formData.append('volumes_count', volumesCount);
    formData.append('status', status);
    formData.append('is_collector', isCollector);
    formData.append('is_last', isLast);
    formData.append('add_multiple_volumes', true);

    fetch('admin.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(response => {
        if (response.ok) {
            window.location.reload();
        } else {
            alert('Une erreur est survenue.');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        alert('Une erreur est survenue.');
    });
});