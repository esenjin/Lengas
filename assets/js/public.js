// scripts/public.js
let currentSearchTerm = '';
let currentSortBy = 'name';
let currentSortOrder = 'asc';
let currentStatusFilter = '';

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
    // Bouton Légende (dans le logout-container)
    const openLegendModalButton = document.getElementById('open-legend-modal');
    if (openLegendModalButton) {
        openLegendModalButton.addEventListener('click', function(e) {
            e.preventDefault();
            openModal('legend-modal');
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

// Gestion des cartes cliquables et autres fonctionnalités existantes
document.querySelectorAll('.series-card').forEach(card => {
    card.addEventListener('click', function() {
        const seriesIndex = this.dataset.seriesIndex;
        const series = seriesData[seriesIndex];

        document.getElementById('modal-series-title').textContent = series.name;
        document.getElementById('modal-series-image').src = series.image || 'assets/img/logo.png';
        document.getElementById('modal-series-author').textContent = series.author;
        document.getElementById('modal-series-publisher').textContent = series.publisher;
        document.getElementById('modal-series-other-contributors').textContent = series.other_contributors && series.other_contributors.filter(i => i.trim()).length > 0 ? series.other_contributors.filter(i => i.trim()).join(', ') : 'aucun';
        document.getElementById('modal-series-categories').textContent = series.categories ? series.categories.join(', ') : '';
        document.getElementById('modal-series-genres').textContent = series.genres && series.genres.filter(i => i.trim()).length > 0 ? series.genres.filter(i => i.trim()).join(', ') : 'aucun';

        const totalVolumes = series.volumes ? series.volumes.length : 0;
        const readVolumes = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;
        if (series.read_elsewhere) {
            document.getElementById('modal-series-stats').innerHTML =
                `${readVolumes} tome${readVolumes > 1 ? 's' : ''} lu${readVolumes > 1 ? 's' : ''}`;
        } else {
            document.getElementById('modal-series-stats').innerHTML =
                `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} ` +
                `(${readVolumes} lu${readVolumes > 1 ? 's' : ''})`;
        }

        let seriesStatus = 'en cours';
        if (series.volumes && series.volumes.some(v => v.last)) {
            seriesStatus = 'terminée';
        } else if (series.status) {
            seriesStatus = series.status;
        }
        let statusIcon, statusClass;
        switch (seriesStatus) {
            case 'terminée':   statusIcon = '✅ publication terminée';   statusClass = 'status-completed';  break;
            case 'en pause':   statusIcon = '⏳ publication en pause';   statusClass = 'status-paused';     break;
            case 'abandonnée': statusIcon = '⛔ publication abandonnée'; statusClass = 'status-abandoned';  break;
            default:           statusIcon = '▶️ publication en cours';   statusClass = 'status-in-progress';
        }
        document.getElementById('modal-series-badges').innerHTML =
            `${series.mature ? '<span class="mature-badge">🔞 mature</span>' : ''}` +
            `${series.read_elsewhere ? '<span class="read-elsewhere-badge">📖 lue ailleurs</span>' : ''}` +
            `<span class="series-status-badge ${statusClass}">${statusIcon}</span>` +
            `${series.mangaupdates_url ? `<a class="mu-badge" href="${series.mangaupdates_url}" target="_blank" rel="noopener" title="Voir sur MangaUpdates"><img src="assets/img/mulogo.png" alt="MangaUpdates" class="mu-logo"></a>` : ''}`;

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

    fetch(`index.php?get_paginated_series=true&page=${currentPage + 1}&per_page=12&search=${encodeURIComponent(searchTerm)}&sort_by=${sortBy}&sort_order=${sortOrder}` + `&status_filter=${encodeURIComponent(document.getElementById('status-filter')?.value || '')}`)
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
                        <img class="series-image" src="${series.image || 'assets/img/logo.png'}" alt="${series.name}" loading="lazy">
                        <div class="series-info">
                            <h2>${series.name}</h2>
                            <p><strong>Auteur :</strong> ${series.author}</p>
                            <p><strong>Éditeur :</strong> ${series.publisher}</p>
                            <div class="series-stats">
                                ${series.read_elsewhere
                                    ? `${readVolumes} tome${readVolumes > 1 ? 's' : ''} lu${readVolumes > 1 ? 's' : ''}`
                                    : `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} (${readVolumes} lu${readVolumes > 1 ? 's' : ''})`
                                }
                            </div>
                        </div>
                    `;

                    // Écouteur pour la nouvelle carte
                    seriesCard.addEventListener('click', function() {
                        const series = seriesData[this.dataset.seriesIndex];
                        document.getElementById('modal-series-title').textContent = series.name;
                        document.getElementById('modal-series-image').src = series.image || 'assets/img/logo.png';
                        document.getElementById('modal-series-author').textContent = series.author;
                        document.getElementById('modal-series-publisher').textContent = series.publisher;
                        document.getElementById('modal-series-other-contributors').textContent = series.other_contributors && series.other_contributors.filter(i => i.trim()).length > 0 ? series.other_contributors.filter(i => i.trim()).join(', ') : 'aucun';
                        document.getElementById('modal-series-categories').textContent = series.categories ? series.categories.join(', ') : '';
                        document.getElementById('modal-series-genres').textContent = series.genres && series.genres.filter(i => i.trim()).length > 0 ? series.genres.filter(i => i.trim()).join(', ') : 'aucun';

                        const totalVolumes = series.volumes ? series.volumes.length : 0;
                        const readVolumes = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;
                        if (series.read_elsewhere) {
                            document.getElementById('modal-series-stats').innerHTML =
                                `${readVolumes} tome${readVolumes > 1 ? 's' : ''} lu${readVolumes > 1 ? 's' : ''}`;
                        } else {
                            document.getElementById('modal-series-stats').innerHTML =
                                `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} ` +
                                `(${readVolumes} lu${readVolumes > 1 ? 's' : ''})`;
                        }

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
    currentStatusFilter = formData.get('status_filter') || '';

    document.getElementById('series-list').innerHTML = '<p>Chargement des résultats...</p>';

    // Charge les résultats via AJAX
    fetch(`index.php?get_paginated_series=true&page=1&per_page=12&search=${encodeURIComponent(currentSearchTerm)}&sort_by=${currentSortBy}&sort_order=${currentSortOrder}` + `&status_filter=${encodeURIComponent(currentStatusFilter)}`)
        .then(response => response.json())
        .then(data => {
            const seriesList = document.getElementById('series-list');
            seriesList.innerHTML = ''; // Vide la liste
            const statusFilterEl = document.getElementById('status-filter');
            if (statusFilterEl) statusFilterEl.value = currentStatusFilter;

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
                        <img class="series-image" src="${series.image || 'assets/img/logo.png'}" alt="${series.name}" loading="lazy">
                        <div class="series-info">
                            <h2>${series.name}</h2>
                            <p><strong>Auteur :</strong> ${series.author}</p>
                            <p><strong>Éditeur :</strong> ${series.publisher}</p>
                            <div class="series-stats">
                                ${series.read_elsewhere
                                    ? `${readVolumes} tome${readVolumes > 1 ? 's' : ''} lu${readVolumes > 1 ? 's' : ''}`
                                    : `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} (${readVolumes} lu${readVolumes > 1 ? 's' : ''})`
                                }
                            </div>
                        </div>
                    `;

                    // Écouteur pour la nouvelle carte
                    seriesCard.addEventListener('click', function() {
                        const series = seriesData[this.dataset.seriesIndex];
                        document.getElementById('modal-series-title').textContent = series.name;
                        document.getElementById('modal-series-image').src = series.image || 'assets/img/logo.png';
                        document.getElementById('modal-series-author').textContent = series.author;
                        document.getElementById('modal-series-publisher').textContent = series.publisher;
                        document.getElementById('modal-series-other-contributors').textContent = series.other_contributors && series.other_contributors.filter(i => i.trim()).length > 0 ? series.other_contributors.filter(i => i.trim()).join(', ') : 'aucun';
                        document.getElementById('modal-series-categories').textContent = series.categories ? series.categories.join(', ') : '';
                        document.getElementById('modal-series-genres').textContent = series.genres && series.genres.filter(i => i.trim()).length > 0 ? series.genres.filter(i => i.trim()).join(', ') : 'aucun';

                        const totalVolumes = series.volumes ? series.volumes.length : 0;
                        const readVolumes = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;
                        if (series.read_elsewhere) {
                            document.getElementById('modal-series-stats').innerHTML =
                                `${readVolumes} tome${readVolumes > 1 ? 's' : ''} lu${readVolumes > 1 ? 's' : ''}`;
                        } else {
                            document.getElementById('modal-series-stats').innerHTML =
                                `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} ` +
                                `(${readVolumes} lu${readVolumes > 1 ? 's' : ''})`;
                        }

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


// Gestion du menu mobile
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuButton = document.getElementById('mobile-menu-button');
    const publicMenu = document.getElementById('public-menu');

    if (mobileMenuButton && publicMenu) {
        mobileMenuButton.addEventListener('click', function() {
            publicMenu.classList.toggle('active');
        });

        publicMenu.addEventListener('click', function(e) {
            if (e.target === publicMenu) {
                publicMenu.classList.remove('active');
            }
        });
    }

    document.getElementById('status-filter')?.addEventListener('change', function() {
        currentStatusFilter = this.value;
        currentPage = 1;
        hasMoreSeries = true;
        seriesData = [];

        document.getElementById('series-list').innerHTML = '<p>Chargement des résultats...</p>';

        const urlParams = new URLSearchParams(window.location.search);
        const searchTerm = urlParams.get('search') || currentSearchTerm;
        const sortBy = urlParams.get('sort_by') || currentSortBy;
        const sortOrder = urlParams.get('sort_order') || currentSortOrder;

        fetch(`index.php?get_paginated_series=true&page=1&per_page=12&search=${encodeURIComponent(searchTerm)}&sort_by=${sortBy}&sort_order=${sortOrder}&status_filter=${encodeURIComponent(currentStatusFilter)}`)
            .then(response => response.json())
            .then(data => {
                const seriesList = document.getElementById('series-list');
                seriesList.innerHTML = '';

                if (data.success && data.series && data.series.length > 0) {
                    data.series.forEach(series => {
                        seriesData.push(series);
                        const seriesIndex = seriesData.length - 1;
                        const seriesCard = document.createElement('div');
                        seriesCard.className = `series-card ${series.mature ? 'mature' : ''}`;
                        seriesCard.dataset.seriesIndex = seriesIndex;
                        const totalVolumes = series.volumes ? series.volumes.length : 0;
                        const readVolumes = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;
                        seriesCard.innerHTML = `
                            <img class="series-image" src="${series.image || 'assets/img/logo.png'}" alt="${series.name}" loading="lazy">
                            <div class="series-info">
                                <h2>${series.name}</h2>
                                <p><strong>Auteur :</strong> ${series.author}</p>
                                <p><strong>Éditeur :</strong> ${series.publisher}</p>
                                <div class="series-stats">
                                    ${series.read_elsewhere
                                        ? `${readVolumes} tome${readVolumes > 1 ? 's' : ''} lu${readVolumes > 1 ? 's' : ''}`
                                        : `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} (${readVolumes} lu${readVolumes > 1 ? 's' : ''})`
                                    }
                                </div>
                            </div>
                        `;
                        seriesCard.addEventListener('click', function() {
                            const s = seriesData[this.dataset.seriesIndex];
                            document.getElementById('modal-series-title').textContent = s.name;
                            document.getElementById('modal-series-image').src = s.image || 'assets/img/logo.png';
                            document.getElementById('modal-series-author').textContent = s.author;
                            document.getElementById('modal-series-publisher').textContent = s.publisher;
                            document.getElementById('modal-series-other-contributors').textContent = s.other_contributors && s.other_contributors.filter(i => i.trim()).length > 0 ? s.other_contributors.filter(i => i.trim()).join(', ') : 'aucun';
                            document.getElementById('modal-series-categories').textContent = s.categories ? s.categories.join(', ') : '';
                            document.getElementById('modal-series-genres').textContent = s.genres && s.genres.filter(i => i.trim()).length > 0 ? s.genres.filter(i => i.trim()).join(', ') : 'aucun';
                            const tv = s.volumes ? s.volumes.length : 0;
                            const rv = s.volumes ? s.volumes.filter(v => v.status === 'terminé').length : 0;
                            if (s.read_elsewhere) {
                                document.getElementById('modal-series-stats').innerHTML =
                                    `${rv} tome${rv > 1 ? 's' : ''} lu${rv > 1 ? 's' : ''}`;
                            } else {
                                document.getElementById('modal-series-stats').innerHTML =
                                    `${tv} tome${tv > 1 ? 's' : ''} possédé${tv > 1 ? 's' : ''} (${rv} lu${rv > 1 ? 's' : ''})`;
                            }

                            let seriesStatus = 'en cours';
                            if (s.volumes && s.volumes.some(v => v.last)) {
                                seriesStatus = 'terminée';
                            } else if (s.status) {
                                seriesStatus = s.status;
                            }
                            let statusIcon, statusClass;
                            switch (seriesStatus) {
                                case 'terminée':   statusIcon = '✅ publication terminée';   statusClass = 'status-completed';  break;
                                case 'en pause':   statusIcon = '⏳ publication en pause';   statusClass = 'status-paused';     break;
                                case 'abandonnée': statusIcon = '⛔ publication abandonnée'; statusClass = 'status-abandoned';  break;
                                default:           statusIcon = '▶️ publication en cours';   statusClass = 'status-in-progress';
                            }
                            document.getElementById('modal-series-badges').innerHTML =
                                `${s.mature ? '<span class="mature-badge">🔞 mature</span>' : ''}` +
                                `${s.read_elsewhere ? '<span class="read-elsewhere-badge">📖 lue ailleurs</span>' : ''}` +
                                `<span class="series-status-badge ${statusClass}">${statusIcon}</span>` +
                                `${s.mangaupdates_url ? `<a class="mu-badge" href="${s.mangaupdates_url}" target="_blank" rel="noopener" title="Voir sur MangaUpdates"><img src="assets/img/mulogo.png" alt="MangaUpdates" class="mu-logo"></a>` : ''}`;

                            const volumesList = document.getElementById('modal-volumes-list');
                            volumesList.innerHTML = '';
                            const sortedVolumes = s.volumes ? [...s.volumes].sort((a, b) => a.number - b.number) : [];
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
});

// ── Autocomplétion de la barre de recherche publique ──────────────────────
(function setupPublicSearchAutocomplete() {
    const input = document.getElementById('search-index');
    if (!input) return;

    // Créer le conteneur et la liste de suggestions
    const wrapper = document.createElement('div');
    wrapper.className = 'autocomplete-container';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const suggestionsList = document.createElement('div');
    suggestionsList.className = 'autocomplete-suggestions';
    suggestionsList.style.display = 'none';
    wrapper.appendChild(suggestionsList);

    const fields = ['name', 'author', 'publisher', 'categories', 'genres', 'other_contributors'];

    async function fetchSuggestions(term) {
        const normalized = normalizeString(term);
        const promises = fields.map(field =>
            fetch(`index.php?get_suggestions=true&field=${field}&term=${encodeURIComponent(normalized)}`)
                .then(r => r.ok ? r.json() : [])
                .catch(() => [])
        );
        const results = await Promise.all(promises);
        return [...new Set(results.flat())];
    }

    function selectSuggestion(value) {
        input.value = value;
        suggestionsList.style.display = 'none';
        suggestionsList.querySelectorAll('div').forEach(d => d.classList.remove('autocomplete-active'));
        const form = input.closest('form');
        if (form) form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }

    input.addEventListener('input', async function() {
        const term = this.value.trim();
        if (term.length < 2) {
            suggestionsList.style.display = 'none';
            return;
        }
        try {
            const suggestions = await fetchSuggestions(term);
            const normalizedTerm = normalizeString(term);
            const filtered = suggestions.filter(s => normalizeString(s).includes(normalizedTerm));
            suggestionsList.innerHTML = '';
            if (filtered.length > 0) {
                filtered.forEach(suggestion => {
                    const div = document.createElement('div');
                    div.textContent = suggestion;
                    div.addEventListener('click', () => selectSuggestion(suggestion));
                    suggestionsList.appendChild(div);
                });
                suggestionsList.style.display = 'block';
            } else {
                suggestionsList.style.display = 'none';
            }
        } catch (e) {
            console.error('Autocomplete error:', e);
        }
    });

    // Navigation clavier
    input.addEventListener('keydown', function(e) {
        if (suggestionsList.style.display === 'none') return;
        const items = [...suggestionsList.querySelectorAll('div')];
        if (!items.length) return;
        const activeIdx = items.findIndex(d => d.classList.contains('autocomplete-active'));

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const next = activeIdx < items.length - 1 ? activeIdx + 1 : 0;
            items.forEach(d => d.classList.remove('autocomplete-active'));
            items[next].classList.add('autocomplete-active');
            items[next].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prev = activeIdx > 0 ? activeIdx - 1 : items.length - 1;
            items.forEach(d => d.classList.remove('autocomplete-active'));
            items[prev].classList.add('autocomplete-active');
            items[prev].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' && activeIdx >= 0) {
            e.preventDefault();
            selectSuggestion(items[activeIdx].textContent);
        } else if (e.key === 'Escape') {
            suggestionsList.style.display = 'none';
            items.forEach(d => d.classList.remove('autocomplete-active'));
        }
    });

    // Fermeture au clic extérieur
    document.addEventListener('click', e => {
        if (!wrapper.contains(e.target)) {
            suggestionsList.style.display = 'none';
            suggestionsList.querySelectorAll('div').forEach(d => d.classList.remove('autocomplete-active'));
        }
    });
})();
