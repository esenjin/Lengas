// Variables globales pour la pagination
let currentPage = 1;
let isLoading = false;
let hasMoreSeries = true;

// Fonction pour normaliser une chaîne de caractères
function normalizeString(str) {
    return str
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^a-z0-9\s\-]/g, '');
}

// Fonction pour charger les séries paginées via AJAX
async function loadMoreSeries() {
    if (isLoading || !hasMoreSeries) return;

    isLoading = true;
    document.getElementById('loading-spinner').classList.add('active');

    try {
        // Récupère les paramètres de recherche/filtre depuis l'URL
        const urlParams = new URLSearchParams(window.location.search);
        let searchTerm = urlParams.get('search') || '';
        searchTerm = normalizeString(searchTerm);
        const sortBy = urlParams.get('sort_by') || 'name';
        const sortOrder = urlParams.get('sort_order') || 'asc';

        // Requête AJAX
        const response = await fetch(
            `admin.php?get_paginated_series=true&page=${currentPage + 1}&per_page=9` +
            `&search=${encodeURIComponent(searchTerm)}` +
            `&sort_by=${sortBy}&sort_order=${sortOrder}`
        );
        const data = await response.json();

        if (data.success && data.series && data.series.length > 0) {
            // Ajoute les nouvelles séries à window.seriesData (variable globale)
            window.seriesData = [...window.seriesData, ...data.series];
            const seriesList = document.getElementById('series-list');

            // Crée et ajoute les cartes des séries au DOM
            data.series.forEach(series => {
                const seriesCard = createSeriesCard(series);
                seriesList.appendChild(seriesCard);
            });

            // Met à jour la pagination
            currentPage++;
            hasMoreSeries = data.has_more;

            // Réattache les événements aux nouveaux éléments
            attachSeriesEvents();
        } else {
            hasMoreSeries = false;
        }
    } catch (error) {
        console.error('Erreur lors du chargement des séries :', error);
    } finally {
        isLoading = false;
        document.getElementById('loading-spinner').classList.remove('active');
    }
}

// Fonction pour créer une carte de série (évite la duplication de code)
function createSeriesCard(series) {
    const seriesCard = document.createElement('div');
    seriesCard.className = 'series-card' + (series.favorite ? ' favorite' : '');

    // Génère le HTML des tomes
    let volumesHTML = '';
    series.volumes.forEach((volume, volumeIndex) => {
        volumesHTML += `
            <li class="status-${volume.status.replace(' ', '-')}
                ${volume.collector ? ' volume-collector' : ''}
                ${volume.last ? ' volume-last' : ''}"
                data-series-id="${series.id}"
                data-volume-index="${volumeIndex}">
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
        <img class="series-image" src="${series.image || 'logo.png'}" alt="${series.name}" loading="lazy">
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

    return seriesCard;
}

// Fonction pour réattacher les événements aux nouvelles séries chargées
function attachSeriesEvents() {
    // Supprime les anciens écouteurs en clonant les éléments
    document.querySelectorAll('.edit-series-btn, .delete-series-btn, .volumes-list li').forEach(el => {
        const newEl = el.cloneNode(true);
        el.parentNode.replaceChild(newEl, el);
    });

    // Événements pour les boutons "Modifier"
    document.querySelectorAll('.edit-series-btn').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            const series = window.seriesData.find(s => s.id === seriesId);
            if (series) {
                document.getElementById('edit-series-id-input').value = seriesId;
                document.getElementById('edit-series-name').value = series.name;
                document.getElementById('edit-series-author').value = series.author;
                document.getElementById('edit-series-publisher').value = series.publisher;
                document.getElementById('edit-series-categories').value = series.categories ? series.categories.join(', ') : '';
                document.getElementById('edit-series-genres').value = series.genres ? series.genres.join(', ') : '';
                document.getElementById('edit-series-anilist-id').value = series.anilist_id || '';
                document.getElementById('edit-series-mature').checked = series.mature || false;
                document.getElementById('edit-series-favorite').checked = series.favorite || false;
                document.getElementById('current-series-image').src = series.image || 'logo.png';
                document.getElementById('edit-series-modal').classList.add('modal-active');
            }
        });
    });

    // Événements pour les boutons "Supprimer"
    document.querySelectorAll('.delete-series-btn').forEach(button => {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const seriesId = this.dataset.seriesId;

            // Affiche la pop-up de confirmation personnalisée
            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer cette série ?')
                .then((confirmed) => {
                    if (confirmed) {
                        fetch('admin.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `delete_series=true&series_id=${encodeURIComponent(seriesId)}&no_redirect=true`
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Erreur réseau');
                            }
                            return response.text();
                        })
                        .then(() => {
                            alert('Série supprimée avec succès !');
                            window.location.reload();
                        })
                        .catch(error => {
                            console.error('Erreur:', error);
                            alert('Une erreur est survenue lors de la suppression.');
                        });
                    }
                })
                .catch(error => {
                    console.error('Erreur lors de la confirmation:', error);
                });
        });
    });

    // Événements pour les tomes (clic pour modifier)
    document.querySelectorAll('.volumes-list li').forEach(li => {
        li.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            const volumeIndex = this.dataset.volumeIndex;
            const series = window.seriesData.find(s => s.id === seriesId);
            if (series && series.volumes[volumeIndex]) {
                const volume = series.volumes[volumeIndex];
                document.getElementById('edit-series-id').value = seriesId;
                document.getElementById('edit-volume-index').value = volumeIndex;
                document.getElementById('edit-volume-number-display').textContent = `Tome ${volume.number}`;
                document.querySelector('#edit-volume-modal [name="status"]').value = volume.status;
                document.querySelector('#edit-volume-modal [name="is_collector"]').checked = !!volume.collector;
                document.querySelector('#edit-volume-modal [name="is_last"]').checked = !!volume.last;
                document.getElementById('edit-volume-modal').classList.add('modal-active');
            }
        });
    });
}

// Écouteur de scroll pour déclencher le chargement
window.addEventListener('scroll', () => {
    const { scrollTop, scrollHeight, clientHeight } = document.documentElement;
    const isNearBottom = scrollTop + clientHeight >= scrollHeight - 200;
    const isShortContent = scrollHeight <= clientHeight;

    if ((isNearBottom || isShortContent) && !isLoading && hasMoreSeries) {
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
    window.seriesData = []; // Réinitialise les données
    document.getElementById('series-list').innerHTML = '<p>Chargement des résultats...</p>';

    // Soumet le formulaire pour recharger la page avec les nouveaux filtres
    this.submit();
});