// Gestion des modales
const modals = {
    'add-series': { modal: document.getElementById('add-series-modal'), closeBtn: document.getElementById('close-add-series-modal') },
    'add-volume': { modal: document.getElementById('add-volume-modal'), closeBtn: document.getElementById('close-add-volume-modal') },
    'add-multiple-volumes': { modal: document.getElementById('add-multiple-volumes-modal'), closeBtn: document.getElementById('close-add-multiple-volumes-modal') },
    'edit-volume': { modal: document.getElementById('edit-volume-modal'), closeBtn: document.getElementById('close-edit-volume-modal') },
    'edit-series': { modal: document.getElementById('edit-series-modal'), closeBtn: document.getElementById('close-edit-series-modal') }
};

// Ouverture des modales
document.getElementById('open-add-series-modal').addEventListener('click', () => modals['add-series'].modal.classList.add('modal-active'));
document.getElementById('open-add-volume-modal').addEventListener('click', () => {
    modals['add-volume'].modal.classList.add('modal-active');
    document.getElementById('series-results').style.display = 'block';
});
document.getElementById('open-add-multiple-volumes-modal').addEventListener('click', () => {
    modals['add-multiple-volumes'].modal.classList.add('modal-active');
    document.getElementById('multiple-series-results').style.display = 'block';
});

// Fermeture des modales via la croix
Object.values(modals).forEach(({ closeBtn, modal }) => {
    if (closeBtn && modal) {
        closeBtn.addEventListener('click', () => {
            modal.classList.remove('modal-active');
        });
    }
});

// Recherche de série
function setupSeriesSearch(inputId, resultsId) {
    document.getElementById(inputId).addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        document.querySelectorAll(`#${resultsId} div`).forEach(div => {
            div.style.display = div.textContent.toLowerCase().includes(searchTerm) ? 'block' : 'none';
        });
    });
}

setupSeriesSearch('series-search', 'series-results');
setupSeriesSearch('multiple-series-search', 'multiple-series-results');

// Sélection d'une série
function setupSeriesSelection(resultsId, inputId) {
    document.querySelectorAll(`#${resultsId} div`).forEach(div => {
        div.addEventListener('click', function() {
            const seriesId = this.dataset.id;
            document.getElementById(inputId).value = seriesId;
            this.parentElement.previousElementSibling.value = this.textContent;
            this.parentElement.style.display = 'none';
        });
    });
}

setupSeriesSelection('series-results', 'selected-series-id');
setupSeriesSelection('multiple-series-results', 'multiple-selected-series-id');

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
    if (confirm('Êtes-vous sûr de vouloir supprimer ce tome ?')) {
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

// Gestion du bouton "Modifier" une série
document.querySelectorAll('.edit-series-btn').forEach(button => {
    button.addEventListener('click', function() {
        const seriesId = this.dataset.seriesId;
        const series = seriesData.find(s => s.id === seriesId);

        if (series) {
            document.getElementById('edit-series-id-input').value = seriesId;
            document.getElementById('edit-series-name').value = series.name;
            document.getElementById('edit-series-author').value = series.author;
            document.getElementById('edit-series-publisher').value = series.publisher;
            document.getElementById('edit-series-categories').value = series.categories ? series.categories.join(', ') : '';
            document.getElementById('current-series-image').src = series.image;

            modals['edit-series'].modal.classList.add('modal-active');
        }
    });
});

// Gestion de la suppression d'une série
document.querySelectorAll('.delete-series-btn').forEach(button => {
    button.addEventListener('click', function() {
        if (confirm('Êtes-vous sûr de vouloir supprimer cette série ? Cette action est irréversible.')) {
            const seriesId = this.dataset.seriesId;
            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `delete_series=true&series_id=${encodeURIComponent(seriesId)}`
            })
            .then(response => response.text())
            .then(() => {
                window.location.reload();
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Une erreur est survenue lors de la suppression.');
            });
        }
    });
});

// Fermeture des modales en cliquant à l'extérieur
window.addEventListener('click', (e) => {
    Object.values(modals).forEach(({ modal }) => {
        if (e.target === modal) {
            modal.classList.remove('modal-active');
        }
    });
});

// Masquer le message d'erreur après 3 secondes
setTimeout(function() {
    var errorMessage = document.getElementById('error-message');
    if (errorMessage) {
        errorMessage.style.display = 'none';
    }
}, 3000);