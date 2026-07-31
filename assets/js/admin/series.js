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

        // Une série animée n'a rien à faire dans la modale des mangas : son
        // édition passe par anime.js, qui a déjà intercepté le clic.
        if (series && series.type === 'anime') return;

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
            const babelioField = document.getElementById('edit-series-babelio-url');
            if (babelioField) babelioField.value = series.babelio_url || '';
            document.getElementById('edit-series-new-volumes-count').value = 0;
            document.getElementById('edit-series-new-volumes-status').value = 'à lire';
            document.querySelector('#edit-series-form [name="new_volumes_collector"]').checked = false;
            document.getElementById('edit-series-mature').checked = series.mature || false;
            document.getElementById('edit-series-favorite').checked = series.favorite || false;
            document.getElementById('edit-series-read-elsewhere').checked = series.read_elsewhere || false;
            document.getElementById('edit-series-reading-abandoned').checked = series.reading_abandoned || false;
            const editRating = document.getElementById('edit-series-rating');
            if (editRating) editRating.value = series.rating || '';
            const editRereadCount = document.getElementById('edit-series-reread-count');
            if (editRereadCount) editRereadCount.value = series.reread_count || 0;
            document.getElementById('current-series-image').src = series.image;
            const statusSelect = document.getElementById('edit-series-status');
            Array.from(statusSelect.options).forEach(option => {
                option.selected = option.value === seriesStatus;
            });

            modals['edit-series'].modal.classList.add('modal-active');
        }
    });

// Bouton "Critique" d'une carte série → page de gestion des critiques
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.review-series-btn');
    if (!btn) return;
    const seriesId = btn.dataset.seriesId;
    if (seriesId) {
        window.location.href = 'pages/page-critiques.php?series_id=' + encodeURIComponent(seriesId);
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

// ─────────────────────────────────────────────────────────────────────────────
// Soumission AJAX du formulaire d'édition d'une série (manga) : évite de
// recharger toute la page pour ne rafraîchir, au final, qu'une seule carte.
// Le serveur (admin.php) reçoit un champ "ajax=1" et répond en JSON avec la
// carte "light" à jour (mêmes champs que l'endpoint de pagination).
// ─────────────────────────────────────────────────────────────────────────────
(function () {
    const form = document.getElementById('edit-series-form');
    if (!form) return;

    form.addEventListener('submit', async function (e) {
        // La validation de taille de fichier ci-dessus tourne en premier
        // (même formulaire) : si elle a déjà annulé l'envoi, on ne fait rien.
        if (e.defaultPrevented) return;
        e.preventDefault();

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalLabel = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Mise à jour…';
        }

        const seriesId = document.getElementById('edit-series-id-input').value;
        const formData = new FormData(form);
        formData.set('ajax', '1');
        // FormData n'inclut la valeur d'un bouton submit que s'il a déclenché
        // l'envoi ; on la rajoute donc explicitement pour que le serveur voie
        // bien $_POST['update_series'].
        formData.set('update_series', 'Mettre à jour');

        try {
            const response = await fetch('admin.php' + window.location.search, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (!result.success) {
                showCustomAlert('Erreur', result.message || "La mise à jour a échoué.");
                return;
            }

            // Remplace la carte existante par une version fraîchement générée,
            // et tient seriesData à jour pour les autres écrans (recherche,
            // tri, modale "Licence"…) sans re-fetch complet de la collection.
            const oldCard = document.querySelector(`.series-card[data-series-id="${CSS.escape(seriesId)}"]`);
            const newCard = createLightSeriesCard(result.series);
            if (oldCard) {
                oldCard.replaceWith(newCard);
            } else if (typeof seriesList !== 'undefined' && seriesList) {
                seriesList.appendChild(newCard);
            }

            if (Array.isArray(seriesData)) {
                const idx = seriesData.findIndex(s => s.id === seriesId);
                if (idx !== -1) seriesData[idx] = Object.assign({}, seriesData[idx], result.series);
            }

            modals['edit-series'].modal.classList.remove('modal-active');
        } catch (error) {
            console.error('Erreur:', error);
            showCustomAlert('Erreur', "La mise à jour a échoué : le serveur n'a pas répondu.");
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalLabel;
            }
        }
    });
})();
