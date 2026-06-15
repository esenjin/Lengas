// Gestion du bouton "Modifier" une série
document.addEventListener('click', function(e) {
    const button = e.target.closest('.edit-series-btn');
    if (!button) return;
    const seriesId = button.dataset.seriesId;
        console.log("seriesId récupéré :", seriesId);
        console.log("seriesData :", JSON.stringify(seriesData));
        if (!Array.isArray(seriesData)) {
            console.error("seriesData n'est pas un tableau, conversion forcée :", seriesData);
            seriesData = Object.values(seriesData); // Conversion en tableau si nécessaire
        }
        const series = seriesData.find(s => s.id === seriesId);
        console.log("série trouvée :", series);

        if (series) {
            let seriesStatus = 'en cours';
            if (series.volumes && series.volumes.some(volume => volume.last)) {
                seriesStatus = 'terminée';
            } else if (series.status === 'en pause' || series.status === 'abandonnée') {
                seriesStatus = series.status;
            }

            // Met à jour les champs du formulaire avec les données de la série
            document.getElementById('edit-series-id-input').value = seriesId;
            document.getElementById('edit-series-name').value = series.name;
            document.getElementById('edit-series-author').value = series.author;
            document.getElementById('edit-series-publisher').value = series.publisher;
            document.getElementById('edit-series-other-contributors').value = series.other_contributors ? series.other_contributors.join(', ') : '';
            document.getElementById('edit-series-categories').value = series.categories ? series.categories.join(', ') : '';
            document.getElementById('edit-series-genres').value = series.genres ? series.genres.join(', ') : '';
            document.getElementById('edit-series-mangaupdates-url').value = series.mangaupdates_url || '';
            document.getElementById('edit-series-new-volumes-count').value = 0;
            document.getElementById('edit-series-new-volumes-status').value = 'à lire';
            document.querySelector('#edit-series-form [name="new_volumes_collector"]').checked = false;
            document.getElementById('edit-series-mature').checked = series.mature || false;
            document.getElementById('edit-series-favorite').checked = series.favorite || false;
            document.getElementById('edit-series-read-elsewhere').checked = series.read_elsewhere || false;
            document.getElementById('edit-series-reading-abandoned').checked = series.reading_abandoned || false;
            document.getElementById('current-series-image').src = series.image;
            const statusSelect = document.getElementById('edit-series-status');
            Array.from(statusSelect.options).forEach(option => {
                option.selected = option.value === seriesStatus;
            });

            modals['edit-series'].modal.classList.add('modal-active');
        }
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
