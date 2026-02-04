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
            document.getElementById('edit-series-other-contributors').value = series.other_contributors ? series.other_contributors.join(', ') : '';
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

// Validation de la taille du fichier image à l'ajout ou la modification d'une série
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
let currentSeriesData = [];

function fetch_current_series() {
    fetch('admin.php?get_current_series=true')
        .then(response => response.json())
        .then(data => {
            currentSeriesData = data;
            const container = document.getElementById('current-series-list');
            container.innerHTML = '';
            if (data.success && data.series.length > 0) {
                // Sélecteur de tri (uniquement par date)
                container.innerHTML = `
                    <div class="sort-options">
                        <label>Trier par date : </label>
                        <select id="sort-current-series-by-date">
                            <option value="recent">Plus récent d'abord</option>
                            <option value="oldest">Plus ancien d'abord</option>
                        </select>
                    </div>
                    <div id="current-series-items"></div>
                `;
                // Tri par défaut : plus récent d'abord
                data.series.sort((a, b) => new Date(b.last_volume_added_at) - new Date(a.last_volume_added_at));
                render_current_series(data.series);

                // Écouteur pour le tri par date
                document.getElementById('sort-current-series-by-date').addEventListener('change', (e) => {
                    const sortOrder = e.target.value;
                    let sortedSeries = [...data.series];
                    sortedSeries.sort((a, b) => {
                        if (sortOrder === 'recent') {
                            return new Date(b.last_volume_added_at) - new Date(a.last_volume_added_at);
                        } else {
                            return new Date(a.last_volume_added_at) - new Date(b.last_volume_added_at);
                        }
                    });
                    render_current_series(sortedSeries);
                });
            } else {
                container.innerHTML = '<p>Aucune série en cours.</p>';
            }
        });
}

function render_current_series(seriesList) {
    const itemsContainer = document.getElementById('current-series-items');
    itemsContainer.innerHTML = '';
    seriesList.forEach(series => {
        const seriesDiv = document.createElement('div');
        seriesDiv.className = 'current-series-item';
        seriesDiv.innerHTML = `
            <div class="series-title">${series.name}</div>
            <div class="series-details">
                <span>Dernier tome : ${series.last_volume}</span>
                <span>Ajouté le : ${series.last_volume_added_at}</span>
                <button class="add-volume-btn" data-series-id="${series.id}">+</button>
            </div>
        `;
        itemsContainer.appendChild(seriesDiv);
    });

    // Écouteurs pour les boutons "+"
    document.querySelectorAll('.add-volume-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            const series = currentSeriesData.series.find(s => s.id === seriesId);
            document.getElementById('multiple-selected-series-id').value = seriesId;
            document.getElementById('multiple-series-search').value = series.name;
            document.getElementById('multiple-series-results').style.display = 'none';
            modals['add-multiple-volumes'].modal.classList.add('modal-active');
        });
    });
}