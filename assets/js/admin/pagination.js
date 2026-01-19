// Variables globales pour la pagination
let currentPage = 1;
let isLoading = false;
let hasMoreSeries = true;

// Fonction pour charger les séries paginées via AJAX
function loadMoreSeries() {
    if (isLoading || !hasMoreSeries) return;

    isLoading = true;
    document.getElementById('loading-spinner').classList.add('active');

    // Récupère les paramètres de recherche/filtre depuis l'URL
    const urlParams = new URLSearchParams(window.location.search);
    const searchTerm = urlParams.get('search') || '';
    const sortBy = urlParams.get('sort_by') || 'name';
    const sortOrder = urlParams.get('sort_order') || 'asc';

    // Ajoute les paramètres à la requête AJAX
    fetch(`admin.php?get_paginated_series=true&page=${currentPage + 1}&per_page=9&search=${encodeURIComponent(searchTerm)}&sort_by=${sortBy}&sort_order=${sortOrder}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.series && data.series.length > 0) {
                const seriesList = document.getElementById('series-list');
                data.series.forEach(series => {
                    // Crée la carte de la série
                    const seriesCard = document.createElement('div');
                    seriesCard.className = 'series-card';

                    // Génère le HTML pour la série
                    let volumesHTML = '';
                    series.volumes.forEach((volume, volume_index) => {
                        volumesHTML += `
                            <li class="status-${volume.status.replace(' ', '-')}
                                ${volume.collector ? ' volume-collector' : ''}
                                ${volume.last ? ' volume-last' : ''}"
                                data-series-id="${series.id}"
                                data-volume-index="${volume_index}">
                                ${volume.number}
                            </li>
                        `;
                    });

                    // Génère les notifications (tomes manquants, etc.)
                    let notificationsHTML = '';
                    if (series.notifications && series.notifications.length > 0) {
                        notificationsHTML = `
                            <div class="issues-list">
                                <span class="warning-icon">⚠️</span>
                                <span class="issues-text">${series.notifications.join(' ')}</span>
                            </div>
                        `;
                    }

                    // Remplit la carte avec le HTML
                    seriesCard.innerHTML = `
                        <img class="series-image" src="${series.image}" alt="${series.name}" loading="lazy">
                        <div class="series-info">
                            <div class="series-header">
                                <h2>${series.name}</h2>
                                <div class="series-actions">
                                    <button class="edit-series-btn" data-series-id="${series.id}">Modifier</button>
                                    <button class="delete-series-btn" data-series-id="${series.id}">Supprimer</button>
                                </div>
                            </div>
                            <p><strong>Auteur :</strong> ${series.author}</p>
                            <p><strong>Éditeur :</strong> ${series.publisher}</p>
                            <p><strong>Catégories :</strong> ${series.categories ? series.categories.join(', ') : ''}</p>
                            <p><strong>Genres :</strong> ${series.genres ? series.genres.join(', ') : ''}</p>
                            <p><strong>ID Anilist :</strong>
                                ${series.anilist_id ?
                                    `<a href="https://anilist.co/manga/${series.anilist_id}" target="_blank">${series.anilist_id}</a>` :
                                    'Non défini'}
                            </p>
                            <p><strong>Tomes :</strong> ${series.volumes.length}</p>
                            ${series.mature ? '<span class="mature-badge">🔞 Mature</span>' : ''}
                            <h3>Liste des tomes :</h3>
                            ${notificationsHTML}
                            <ul class="volumes-list">${volumesHTML}</ul>
                        </div>
                    `;

                    // Ajoute la carte à la liste
                    seriesList.appendChild(seriesCard);
                });

                // Met à jour la page et le statut
                currentPage++;
                hasMoreSeries = data.has_more;

                // Réattache les événements aux nouveaux éléments
                attachSeriesEvents();
            } else {
                hasMoreSeries = false;
            }
        })
        .catch(error => {
            console.error('Erreur lors du chargement des séries :', error);
        })
        .finally(() => {
            isLoading = false;
            document.getElementById('loading-spinner').classList.remove('active');
        });
}

// Fonction pour réattacher les événements aux nouvelles séries chargées
function attachSeriesEvents() {
    // Événements pour les boutons "Modifier" et "Supprimer"
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
                document.getElementById('edit-series-genres').value = series.genres ? series.genres.join(', ') : '';
                document.getElementById('edit-series-anilist-id').value = series.anilist_id || '';
                document.getElementById('edit-series-mature').checked = series.mature || false;
                document.getElementById('current-series-image').src = series.image;
                modals['edit-series'].modal.classList.add('modal-active');
            }
        });
    });

    document.querySelectorAll('.delete-series-btn').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer cette série ?').then((confirmed) => {
                if (confirmed) {
                    fetch('admin.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `delete_series=true&series_id=${encodeURIComponent(seriesId)}`
                    })
                    .then(() => window.location.reload())
                    .catch(error => alert('Erreur : ' + error.message));
                }
            });
        });
    });

    // Événements pour les tomes (clic pour modifier)
    document.querySelectorAll('.volumes-list li').forEach(li => {
        li.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            const volumeIndex = this.dataset.volumeIndex;
            const series = seriesData.find(s => s.id === seriesId);
            if (series && series.volumes[volumeIndex]) {
                const volume = series.volumes[volumeIndex];
                document.getElementById('edit-series-id').value = seriesId;
                document.getElementById('edit-volume-index').value = volumeIndex;
                document.getElementById('edit-volume-number-display').textContent = `Tome ${volume.number}`;
                document.querySelector('#edit-volume-modal [name="status"]').value = volume.status;
                document.querySelector('#edit-volume-modal [name="is_collector"]').checked = !!volume.collector;
                document.querySelector('#edit-volume-modal [name="is_last"]').checked = !!volume.last;
                modals['edit-volume'].modal.classList.add('modal-active');
            }
        });
    });
}

// Écouteur de scroll pour déclencher le chargement
window.addEventListener('scroll', () => {
    const { scrollTop, scrollHeight, clientHeight } = document.documentElement;
    if (scrollTop + clientHeight >= scrollHeight - 200 && !isLoading && hasMoreSeries) {
        loadMoreSeries();
    }
});

// Initialisation au chargement de la page
document.addEventListener('DOMContentLoaded', () => {
    attachSeriesEvents();
});

// Écouteur pour le formulaire de recherche/filtre
document.querySelector('.filters form').addEventListener('submit', function(e) {
    e.preventDefault();
    currentPage = 1;
    hasMoreSeries = true;
    document.getElementById('series-list').innerHTML = '<p>Chargement des résultats...</p>';

    const formData = new FormData(this);
    const searchParams = new URLSearchParams(formData).toString();

    fetch(`admin.php?${searchParams}`)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newSeriesList = doc.getElementById('series-list');
            if (newSeriesList) {
                document.getElementById('series-list').innerHTML = newSeriesList.innerHTML;
                attachSeriesEvents();
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            document.getElementById('series-list').innerHTML = '<p>Erreur lors du chargement des résultats.</p>';
        });

    this.submit();
});