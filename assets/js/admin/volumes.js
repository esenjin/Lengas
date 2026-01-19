// Édition d'un tome
document.querySelectorAll('.volumes-list li').forEach(li => {
    li.addEventListener('click', function() {
        const seriesId = this.dataset.seriesId;
        const volumeIndex = this.dataset.volumeIndex;
        const volumeNumber = this.textContent.trim();

        const series = seriesData.find(s => s.id === seriesId);
        if (series) {
            document.getElementById('edit-series-id').value = seriesId;
            document.getElementById('edit-volume-index').value = volumeIndex;
            document.getElementById('edit-volume-number-display').textContent = `Tome ${volumeNumber}`;

            const volume = series.volumes[volumeIndex];
            document.querySelector('#edit-volume-modal [name="status"]').value = volume.status;
            document.querySelector('#edit-volume-modal [name="is_collector"]').checked = !!volume.collector;
            document.querySelector('#edit-volume-modal [name="is_last"]').checked = !!volume.last;

            modals['edit-volume'].modal.classList.add('modal-active');
        }
    });
});

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
    const isCollector = document.querySelector('#add-multiple-volumes-modal [name="is_collector"]').checked;
    const isLast = document.querySelector('#add-multiple-volumes-modal [name="is_last"]').checked;

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