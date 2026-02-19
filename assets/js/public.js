// scripts/public.js
let currentSearchTerm = '';
let currentSortBy = 'name';
let currentSortOrder = 'asc';

// Fonction pour normaliser une chaîne de caractères
function normalizeString(str) {
    return str
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^a-z0-9\s\-]/g, '');
}

// Fermer toutes les modales actives
function closeAllModals() {
    document.querySelectorAll('.modal.modal-active').forEach(modal => {
        modal.classList.remove('modal-active');
        modal.style.display = 'none';
    });
}

// Ouvrir une modale spécifique
function openModal(modalId) {
    closeAllModals();
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('modal-active');
        modal.style.display = 'flex';
    }
}


// Écouteurs pour les boutons d'ouverture de modale
document.addEventListener('DOMContentLoaded', function() {
    // Bouton Légende
    const openLegendModalButton = document.getElementById('open-legend-modal');
    if (openLegendModalButton) {
        openLegendModalButton.addEventListener('click', function(e) {
            e.preventDefault();
            openModal('legend-modal');
        });
    }

    // Bouton Lues ailleurs
    const openReadModalButton = document.getElementById('open-read-modal');
    if (openReadModalButton) {
        openReadModalButton.addEventListener('click', function(e) {
            e.preventDefault();
            loadRead();
            openModal('read-modal');
        });
    }

    // Boutons de fermeture de modale
    document.querySelectorAll('.close-modal').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            closeAllModals();
        });
    });

    // Fermeture des modales en cliquant à l'extérieur
    window.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal')) {
            closeAllModals();
        }
    });
});

// Fonction pour charger les séries "lues ailleurs"
function loadRead() {
    fetch('index.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_read'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Erreur réseau : ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        updateReadList(data.read || []);
    })
    .catch(error => {
        console.error('Erreur:', error);
        updateReadList([]);
    });
}

// Mettre à jour la liste "lues ailleurs" dans le DOM
function updateReadList(read) {
    const readList = document.getElementById('read-list');
    if (!readList) {
        console.error("L'élément read-list n'existe pas.");
        return;
    }

    readList.innerHTML = '';

    if (!read || read.length === 0) {
        readList.innerHTML = '<p>Aucune série "lue ailleurs" trouvée.</p>';;
        return;
    }

    read.forEach(item => {
        const readItem = document.createElement('div');
        readItem.className = 'read-item';
        readItem.innerHTML = `
            <div class="read-item-content">
                <div class="read-item-line read-item-line-top">
                    <span class="read-series-name">${item.name || ''}</span>
                    <span class="read-series-author">${item.author || ''}</span>
                    <span class="read-series-publisher">${item.publisher || ''}</span>
                </div>
                <div class="read-item-line read-item-line-bottom">
                    <span class="read-series-volumes">${item.volumes_read || 0} tomes lus</span>
                    <span class="read-series-status">${item.status || 'inconnu'}</span>
                </div>
            </div>
        `;
        readList.appendChild(readItem);
    });
}

// Écouteur pour le champ de recherche dans la modale "Lues ailleurs"
document.getElementById('read-search')?.addEventListener('input', function() {
    const searchTerm = normalizeString(this.value);
    document.querySelectorAll('.read-item').forEach(item => {
        const name = normalizeString(item.querySelector('.read-series-name')?.textContent || '');
        const author = normalizeString(item.querySelector('.read-series-author')?.textContent || '');
        const publisher = normalizeString(item.querySelector('.read-series-publisher')?.textContent || '');

        item.style.display = (name.includes(searchTerm) || author.includes(searchTerm) || publisher.includes(searchTerm)) ? 'flex' : 'none';
    });
});

// Gestion des cartes cliquables et autres fonctionnalités existantes
document.querySelectorAll('.series-card').forEach(card => {
    card.addEventListener('click', function() {
        const seriesIndex = this.dataset.seriesIndex;
        const series = seriesData[seriesIndex];

        document.getElementById('modal-series-title').textContent = series.name;
        document.getElementById('modal-series-image').src = series.image || 'logo.png';
        document.getElementById('modal-series-author').textContent = series.author;
        document.getElementById('modal-series-publisher').textContent = series.publisher;
        document.getElementById('modal-series-other-contributors').textContent = series.other_contributors ? series.other_contributors.join(', ') : '';
        document.getElementById('modal-series-categories').textContent = series.categories ? series.categories.join(', ') : '';
        document.getElementById('modal-series-genres').textContent = series.genres ? series.genres.join(', ') : '';

        const totalVolumes = series.volumes ? series.volumes.length : 0;
        const readVolumes = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;
        document.getElementById('modal-series-stats').innerHTML =
            `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} ` +
            `(${readVolumes} lu${readVolumes > 1 ? 's' : ''})`;

        const volumesList = document.getElementById('modal-volumes-list');
        volumesList.innerHTML = '';
        const sortedVolumes = series.volumes ? [...series.volumes].sort((a, b) => a.number - b.number) : [];
        sortedVolumes.forEach(volume => {
            const li = document.createElement('li');
            li.className = `status-${volume.status.replace(' ', '-')} ${volume.collector ? 'volume-collector' : ''} ${volume.last ? 'volume-last' : ''}`;
            li.textContent = volume.number;
            volumesList.appendChild(li);
        });

        openModal('series-detail-modal');
    });
});

// Variables globales pour la pagination
let currentPage = 1;
let isLoading = false;
let hasMoreSeries = true;

// Écouteurs pour les liens de recherche depuis stats.php
document.addEventListener('DOMContentLoaded', function() {
    const resultLinks = document.querySelectorAll('.result-link');
    resultLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const searchTerm = this.getAttribute('href').split('search=')[1];
            window.location.href = `index.php?search=${searchTerm}`;
        });
    });
});

// Fonction pour charger les séries paginées via AJAX
function loadMoreSeries() {
    if (isLoading || !hasMoreSeries) return;

    isLoading = true;
    document.getElementById('loading-spinner').classList.add('active');

    // Récupérer les paramètres de recherche actuels depuis l'URL
    const urlParams = new URLSearchParams(window.location.search);
    const searchTerm = urlParams.get('search') || '';
    const sortBy = urlParams.get('sort_by') || 'name';
    const sortOrder = urlParams.get('sort_order') || 'asc';

    fetch(`index.php?get_paginated_series=true&page=${currentPage + 1}&per_page=12&search=${encodeURIComponent(searchTerm)}&sort_by=${sortBy}&sort_order=${sortOrder}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.series && data.series.length > 0) {
                const seriesList = document.getElementById('series-list');
                data.series.forEach((series) => {
                    seriesData.push(series);
                    const seriesIndex = seriesData.length - 1;

                    const seriesCard = document.createElement('div');
                    seriesCard.className = `series-card ${series.mature ? 'mature' : ''}`;
                    seriesCard.dataset.seriesIndex = seriesIndex;

                    const totalVolumes = series.volumes ? series.volumes.length : 0;
                    const readVolumes = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;

                    seriesCard.innerHTML = `
                        <img class="series-image" src="${series.image || 'logo.png'}" alt="${series.name}" loading="lazy">
                        <div class="series-info">
                            <h2>${series.name}</h2>
                            <p><strong>Auteur :</strong> ${series.author}</p>
                            <p><strong>Éditeur :</strong> ${series.publisher}</p>
                            <div class="series-stats">
                                ${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''}
                                (${readVolumes} lu${readVolumes > 1 ? 's' : ''})
                            </div>
                        </div>
                    `;

                    // Écouteur pour la nouvelle carte
                    seriesCard.addEventListener('click', function() {
                        const series = seriesData[this.dataset.seriesIndex];
                        document.getElementById('modal-series-title').textContent = series.name;
                        document.getElementById('modal-series-image').src = series.image || 'logo.png';
                        document.getElementById('modal-series-author').textContent = series.author;
                        document.getElementById('modal-series-publisher').textContent = series.publisher;
                        document.getElementById('modal-series-other-contributors').textContent = series.other_contributors ? series.other_contributors.join(', ') : '';
                        document.getElementById('modal-series-categories').textContent = series.categories ? series.categories.join(', ') : '';
                        document.getElementById('modal-series-genres').textContent = series.genres ? series.genres.join(', ') : '';

                        const totalVolumes = series.volumes ? series.volumes.length : 0;
                        const readVolumes = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;
                        document.getElementById('modal-series-stats').innerHTML =
                            `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} ` +
                            `(${readVolumes} lu${readVolumes > 1 ? 's' : ''})`;

                        const volumesList = document.getElementById('modal-volumes-list');
                        volumesList.innerHTML = '';
                        const sortedVolumes = series.volumes ? [...series.volumes].sort((a, b) => a.number - b.number) : [];
                        sortedVolumes.forEach(volume => {
                            const li = document.createElement('li');
                            li.className = `status-${volume.status.replace(' ', '-')} ${volume.collector ? 'volume-collector' : ''} ${volume.last ? 'volume-last' : ''}`;
                            li.textContent = volume.number;
                            volumesList.appendChild(li);
                        });

                        openModal('series-detail-modal');
                    });

                    seriesList.appendChild(seriesCard);
                });

                currentPage++;
                hasMoreSeries = data.has_more;
            } else {
                hasMoreSeries = false;
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
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
document.querySelector('.filters form')?.addEventListener('submit', function(e) {
    e.preventDefault(); // Empêche le rechargement de la page
    currentPage = 1;
    hasMoreSeries = true;
    seriesData = []; // Réinitialise seriesData comme tableau vide

    // Met à jour les paramètres de recherche
    const formData = new FormData(this);
    currentSearchTerm = formData.get('search') || '';
    currentSortBy = formData.get('sort_by') || 'name';
    currentSortOrder = formData.get('sort_order') || 'asc';

    document.getElementById('series-list').innerHTML = '<p>Chargement des résultats...</p>';

    // Charge les résultats via AJAX
    fetch(`index.php?get_paginated_series=true&page=1&per_page=12&search=${encodeURIComponent(currentSearchTerm)}&sort_by=${currentSortBy}&sort_order=${currentSortOrder}`)
        .then(response => response.json())
        .then(data => {
            const seriesList = document.getElementById('series-list');
            seriesList.innerHTML = ''; // Vide la liste

            if (data.success && data.series && data.series.length > 0) {
                data.series.forEach((series, index) => {
                    seriesData.push(series);
                    const seriesIndex = seriesData.length - 1;

                    const seriesCard = document.createElement('div');
                    seriesCard.className = `series-card ${series.mature ? 'mature' : ''}`;
                    seriesCard.dataset.seriesIndex = seriesIndex;

                    const totalVolumes = series.volumes ? series.volumes.length : 0;
                    const readVolumes = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;

                    seriesCard.innerHTML = `
                        <img class="series-image" src="${series.image || 'logo.png'}" alt="${series.name}" loading="lazy">
                        <div class="series-info">
                            <h2>${series.name}</h2>
                            <p><strong>Auteur :</strong> ${series.author}</p>
                            <p><strong>Éditeur :</strong> ${series.publisher}</p>
                            <div class="series-stats">
                                ${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''}
                                (${readVolumes} lu${readVolumes > 1 ? 's' : ''})
                            </div>
                        </div>
                    `;

                    // Écouteur pour la nouvelle carte
                    seriesCard.addEventListener('click', function() {
                        const series = seriesData[this.dataset.seriesIndex];
                        document.getElementById('modal-series-title').textContent = series.name;
                        document.getElementById('modal-series-image').src = series.image || 'logo.png';
                        document.getElementById('modal-series-author').textContent = series.author;
                        document.getElementById('modal-series-publisher').textContent = series.publisher;
                        document.getElementById('modal-series-other-contributors').textContent = series.other_contributors ? series.other_contributors.join(', ') : '';
                        document.getElementById('modal-series-categories').textContent = series.categories ? series.categories.join(', ') : '';
                        document.getElementById('modal-series-genres').textContent = series.genres ? series.genres.join(', ') : '';

                        const totalVolumes = series.volumes ? series.volumes.length : 0;
                        const readVolumes = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;
                        document.getElementById('modal-series-stats').innerHTML =
                            `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} ` +
                            `(${readVolumes} lu${readVolumes > 1 ? 's' : ''})`;

                        const volumesList = document.getElementById('modal-volumes-list');
                        volumesList.innerHTML = '';
                        const sortedVolumes = series.volumes ? [...series.volumes].sort((a, b) => a.number - b.number) : [];
                        sortedVolumes.forEach(volume => {
                            const li = document.createElement('li');
                            li.className = `status-${volume.status.replace(' ', '-')} ${volume.collector ? 'volume-collector' : ''} ${volume.last ? 'volume-last' : ''}`;
                            li.textContent = volume.number;
                            volumesList.appendChild(li);
                        });

                        openModal('series-detail-modal');
                    });

                    seriesList.appendChild(seriesCard);
                });

                currentPage = 1;
                hasMoreSeries = data.has_more;
            } else {
                seriesList.innerHTML = '<p>Aucune série trouvée.</p>';
                hasMoreSeries = false;
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            document.getElementById('series-list').innerHTML = '<p>Erreur lors du chargement des séries.</p>';
        });
});

// Écouteurs pour la modale "Lues ailleurs"
document.getElementById('open-read-modal').addEventListener('click', function(e) {
    e.preventDefault();
    loadRead();
    openModal('read-modal');

    // Force l'affichage de la modale
    setTimeout(() => {
        const modal = document.getElementById('read-modal');
        if (modal) {
            modal.style.display = 'flex';
        }
    }, 100);
});

// Gestion du menu mobile
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuButton = document.getElementById('mobile-menu-button');
    const publicMenu = document.getElementById('public-menu');

    if (mobileMenuButton && publicMenu) {
        mobileMenuButton.addEventListener('click', function() {
            publicMenu.classList.toggle('active');
        });
    }

    // Fermer le menu si on clique en dehors
    publicMenu.addEventListener('click', function(e) {
        if (e.target === publicMenu) {
            publicMenu.classList.remove('active');
        }
    });
});