// scripts/public.js

function normalizeString(str) {
    return str
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^a-z0-9\s\-]/g, '');
}

// Gestion des cartes cliquables
document.querySelectorAll('.series-card').forEach(card => {
    card.addEventListener('click', function() {
        const seriesIndex = this.dataset.seriesIndex;
        const series = seriesData[seriesIndex];

        // Vérifie si l'image existe, sinon utilise une image par défaut
        const imageUrl = series.image || 'logo.png';
        document.getElementById('modal-series-image').src = imageUrl;

        // Ajoute un gestionnaire d'erreur pour l'image
        const modalImage = document.getElementById('modal-series-image');
        modalImage.onerror = function() {
            this.src = 'logo.png';
        };

        // Remplit les autres informations de la série
        document.getElementById('modal-series-title').textContent = series.name;
        document.getElementById('modal-series-author').textContent = series.author;
        document.getElementById('modal-series-publisher').textContent = series.publisher;
        document.getElementById('modal-series-other-contributors').textContent = series.other_contributors ? series.other_contributors.join(', ') : '';
        document.getElementById('modal-series-categories').textContent = series.categories ? series.categories.join(', ') : '';
        document.getElementById('modal-series-genres').textContent = series.genres ? series.genres.join(', ') : '';

        // Calcule les stats
        const totalVolumes = series.volumes ? series.volumes.length : 0;
        const readVolumes = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;
        document.getElementById('modal-series-stats').innerHTML =
            `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} ` +
            `(${readVolumes} lu${readVolumes > 1 ? 's' : ''})`;

        // Remplit la liste des tomes
        const volumesList = document.getElementById('modal-volumes-list');
        volumesList.innerHTML = '';

        // Trie les tomes par numéro
        const sortedVolumes = series.volumes ? [...series.volumes].sort((a, b) => a.number - b.number) : [];

        // Affiche les tomes triés
        sortedVolumes.forEach(volume => {
            const li = document.createElement('li');
            li.className = `status-${volume.status.replace(' ', '-')} ${volume.collector ? 'volume-collector' : ''} ${volume.last ? 'volume-last' : ''}`;
            li.textContent = volume.number;
            volumesList.appendChild(li);
        });

        // Affiche la modale
        document.getElementById('series-detail-modal').classList.add('modal-active');
    });
});

// Fermeture de la modale
document.getElementById('close-series-detail-modal').addEventListener('click', function() {
    document.getElementById('series-detail-modal').classList.remove('modal-active');
});

// Fermeture de la modale en cliquant à l'extérieur
window.addEventListener('click', (e) => {
    const modal = document.getElementById('series-detail-modal');
    if (e.target === modal) {
        modal.classList.remove('modal-active');
    }
});

// Gestion de la modale de légende
document.getElementById('open-legend-modal').addEventListener('click', function() {
    document.getElementById('legend-modal').classList.add('modal-active');
});

document.getElementById('close-legend-modal').addEventListener('click', function() {
    document.getElementById('legend-modal').classList.remove('modal-active');
});

// Fermeture de la modale en cliquant à l'extérieur
window.addEventListener('click', (e) => {
    const legendModal = document.getElementById('legend-modal');
    if (e.target === legendModal) {
        legendModal.classList.remove('modal-active');
    }
});

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
    let searchTerm = urlParams.get('search') || '';
    searchTerm = normalizeString(searchTerm);
    const sortBy = urlParams.get('sort_by') || 'name';
    const sortOrder = urlParams.get('sort_order') || 'asc';

    // Requête AJAX pour charger les séries suivantes
    fetch(`index.php?get_paginated_series=true&page=${currentPage + 1}&per_page=12&search=${encodeURIComponent(searchTerm)}&sort_by=${sortBy}&sort_order=${sortOrder}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.series && data.series.length > 0) {
                const seriesList = document.getElementById('series-list');
                data.series.forEach((series, seriesIndex) => {
                    // Crée la carte de la série
                    const seriesCard = document.createElement('div');
                    seriesCard.className = `series-card ${series.mature ? 'mature' : ''}`;
                    seriesCard.dataset.seriesIndex = seriesIndex;

                    // Calcule les stats
                    const totalVolumes = series.volumes ? series.volumes.length : 0;
                    const readVolumes = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;

                    // Remplit la carte avec le HTML
                    seriesCard.innerHTML = `
                        <img class="series-image" src="${series.image || ''}" alt="${series.name || ''}" loading="lazy">
                        <div class="series-info">
                            <h2>${series.name || ''}</h2>
                            <p><strong>Auteur :</strong> ${series.author || ''}</p>
                            <p><strong>Éditeur :</strong> ${series.publisher || ''}</p>
                            <div class="series-stats">
                                ${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''}
                                (${readVolumes} lu${readVolumes > 1 ? 's' : ''})
                            </div>
                        </div>
                    `;

                    // Ajoute la carte à la liste
                    seriesList.appendChild(seriesCard);

                    // Attache l'événement de clic pour ouvrir la modale
                    seriesCard.addEventListener('click', function() {
                        document.getElementById('modal-series-title').textContent = series.name;
                        document.getElementById('modal-series-image').src = series.image;
                        document.getElementById('modal-series-author').textContent = series.author;
                        document.getElementById('modal-series-publisher').textContent = series.publisher;
                        document.getElementById('modal-series-other-contributors').textContent = series.other_contributors ? series.other_contributors.join(', ') : '';
                        document.getElementById('modal-series-categories').textContent = series.categories ? series.categories.join(', ') : '';
                        document.getElementById('modal-series-genres').textContent = series.genres ? series.genres.join(', ') : '';

                        // Calcule les stats
                        const totalVolumes = series.volumes ? series.volumes.length : 0;
                        const readVolumes = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;
                        document.getElementById('modal-series-stats').innerHTML =
                            `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} ` +
                            `(${readVolumes} lu${readVolumes > 1 ? 's' : ''})`;

                        // Remplit la liste des tomes
                        const volumesList = document.getElementById('modal-volumes-list');
                        volumesList.innerHTML = '';

                        // Trie les tomes par numéro
                        const sortedVolumes = series.volumes ? [...series.volumes].sort((a, b) => a.number - b.number) : [];

                        // Affiche les tomes triés
                        sortedVolumes.forEach(volume => {
                            const li = document.createElement('li');
                            li.className = `status-${volume.status.replace(' ', '-')} ${volume.collector ? 'volume-collector' : ''} ${volume.last ? 'volume-last' : ''}`;
                            li.textContent = volume.number;
                            volumesList.appendChild(li);
                        });

                        // Affiche la modale
                        document.getElementById('series-detail-modal').classList.add('modal-active');
                    });
                });

                // Met à jour la page et le statut
                currentPage++;
                hasMoreSeries = data.has_more;
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

// Écouteur de scroll pour déclencher le chargement
window.addEventListener('scroll', () => {
    const { scrollTop, scrollHeight, clientHeight } = document.documentElement;
    if (scrollTop + clientHeight >= scrollHeight - 200 && !isLoading && hasMoreSeries) {
        loadMoreSeries();
    }
});

// Réinitialiser la pagination lors d'une nouvelle recherche
document.querySelector('.filters form').addEventListener('submit', function() {
    currentPage = 1; // Réinitialise la pagination
    hasMoreSeries = true;
    document.getElementById('series-list').innerHTML = '<p>Chargement des résultats...</p>';
});