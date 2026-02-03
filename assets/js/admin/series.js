// Gestion du bouton "Modifier" une série
document.querySelectorAll('.edit-series-btn').forEach(button => {
    button.addEventListener('click', function() {
        const seriesId = this.dataset.seriesId;
        if (!Array.isArray(seriesData)) {
            console.error("seriesData n'est pas un tableau, conversion forcée :", seriesData);
            seriesData = Object.values(seriesData); // Conversion en tableau si nécessaire
        }
        const series = seriesData.find(s => s.id === seriesId);

        if (series) {
            document.getElementById('edit-series-id-input').value = seriesId;
            document.getElementById('edit-series-name').value = series.name;
            document.getElementById('edit-series-author').value = series.author;
            document.getElementById('edit-series-publisher').value = series.publisher;
            document.getElementById('edit-series-categories').value = series.categories ? series.categories.join(', ') : '';
            document.getElementById('edit-series-genres').value = series.genres ? series.genres.join(', ') : '';
            document.getElementById('edit-series-anilist-id').value = series.anilist_id || '';
            document.getElementById('edit-series-new-volumes-count').value = 0;
            document.getElementById('edit-series-new-volumes-status').value = 'à lire';
            document.querySelector('#edit-series-form [name="new_volumes_collector"]').checked = false;
            document.querySelector('#edit-series-form [name="new_volumes_last"]').checked = false;
            document.getElementById('edit-series-mature').checked = series.mature || false;
            document.getElementById('edit-series-favorite').checked = series.favorite || false;
            document.getElementById('current-series-image').src = series.image;

            modals['edit-series'].modal.classList.add('modal-active');
        }
    });
});

// Gestion de la suppression d'une série
document.querySelectorAll('.delete-series-btn').forEach(button => {
    button.addEventListener('click', function() {
        showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer cette série ?').then((confirmed) => {
            if (confirmed) {
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
});

// Écouteur pour le formulaire d'ajout de série
document.querySelector('form[enctype="multipart/form-data"]').addEventListener('submit', function(e) {
    const fileInput = this.querySelector('input[type="file"]');
    if (fileInput.files.length > 0) {
        const file = fileInput.files[0];
        const maxSize = 5 * 1024 * 1024; // 5 Mo

        if (file.size > maxSize) {
            e.preventDefault();
            alert("Le fichier est trop volumineux (max. 5 Mo).");
        }
    }
});

// Fonction pour récupérer et afficher les séries en cours
function fetch_current_series() {
    fetch('admin.php?get_current_series=true')
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('current-series-list');
            container.innerHTML = '';
            if (data.success && data.series.length > 0) {
                data.series.forEach(series => {
                    const seriesDiv = document.createElement('div');
                    seriesDiv.className = 'current-series-item';
                    seriesDiv.innerHTML = `
                        <h3>${series.name}</h3>
                        <p>Dernier tome possédé : ${series.last_volume}</p>
                        <button class="add-volume-btn" data-series-id="${series.id}">+</button>
                    `;
                    container.appendChild(seriesDiv);
                });
                // Écouteurs pour les boutons "+"
                document.querySelectorAll('.add-volume-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const seriesId = this.dataset.seriesId;
                        document.getElementById('add-volume-series-id').value = seriesId;
                        modals['add-volume'].modal.classList.add('modal-active');
                    });
                });
            } else {
                container.innerHTML = '<p>Aucune série en cours.</p>';
            }
        });
}