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

// ─────────────────────────────────────────────────────────────────────────────
// Soumission AJAX du formulaire d'édition d'un tome : évite de recharger toute
// la page pour ne rafraîchir, au final, qu'une seule carte. Même mécanique que
// edit-series-form (series.js) : le serveur (admin.php) reçoit "ajax=1" et
// répond en JSON avec la carte "light" à jour (le statut de la série peut
// changer avec le tag « dernier tome », d'où le remplacement de la carte
// entière plutôt qu'une simple mise à jour de la liste des tomes).
// ─────────────────────────────────────────────────────────────────────────────
(function () {
    const form = document.getElementById('edit-volume-form');
    if (!form) return;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalLabel = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Mise à jour…';
        }

        const seriesId = document.getElementById('edit-series-id').value;
        const formData = new FormData(form);
        formData.set('ajax', '1');
        // FormData n'inclut la valeur d'un bouton submit que s'il a déclenché
        // l'envoi ; on la rajoute donc explicitement pour que le serveur voie
        // bien $_POST['update_volume'].
        formData.set('update_volume', 'Mettre à jour');

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

            // Remplace la carte existante par une version fraîchement générée
            // (tomes + badges de statut à jour), et tient seriesData à jour
            // pour les autres écrans sans re-fetch complet de la collection.
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

            document.getElementById('edit-volume-modal').classList.remove('modal-active');
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