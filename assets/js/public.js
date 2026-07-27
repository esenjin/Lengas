// ── Contexte de typage ──────────────────────────────────────────────────────
// window.currentSeriesType et window.seriesTypes sont posés par index.php.
function publicSeriesType() {
    return window.currentSeriesType || 'manga';
}

// Badge coloré signalant la collection d'où provient une suggestion.
function appendPublicTypeBadges(container, types) {
    if (!Array.isArray(types)) return;
    types.forEach(type => {
        const def = (window.seriesTypes && window.seriesTypes[type]) || null;
        if (!def) return;
        const badge = document.createElement('span');
        badge.className = 'suggestion-type-badge';
        badge.textContent = def.label;
        badge.style.setProperty('--type-color', def.color);
        container.appendChild(badge);
    });
}

// scripts/public.js

// Construit le texte de la pop-up (data-title) d'un tome, façon admin.
// Formate les dates au format JJ/MM/AAAA si présentes.
function buildVolumeTooltip(volume) {
    const lines = [];
    const fmt = (d) => {
        if (!d) return '';
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d);
        return m ? `${m[3]}/${m[2]}/${m[1]}` : d;
    };
    const added = fmt(volume.added_at);
    if (added) lines.push(`Date d'ajout à la collection : ${added}`);
    if (volume.status === 'terminé') {
        const read = fmt(volume.read_at);
        if (read) lines.push(`Date de lecture : ${read}`);
    }
    if (volume.collector) lines.push('Tome collector !');
    if (volume.last) lines.push('Dernier tome de la série !');
    return lines.join('\n');
}

let currentSearchTerm = '';
let currentSortBy = 'name';
let currentSortOrder = 'asc';
let currentStatusFilter = '';
let currentStatusMode = 'or';

// Lit l'état du widget de filtre de statuts (cases + mode OU/ET).
function readStatusFilter() {
    const root = document.getElementById('status-filter');
    if (root && typeof root.__sfRead === 'function') {
        return root.__sfRead();
    }
    return { filter: currentStatusFilter || '', mode: currentStatusMode || 'or' };
}

// Construit le fragment d'URL pour le filtre de statuts.
function statusFilterQuery() {
    const st = readStatusFilter();
    currentStatusFilter = st.filter;
    currentStatusMode = st.mode;
    return `&status_filter=${encodeURIComponent(st.filter)}&status_mode=${encodeURIComponent(st.mode)}`;
}

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

    // Bouton « Qui suis-je ? » (profil de l'administrateur)
    const openProfilModalButton = document.getElementById('open-profil-modal');
    if (openProfilModalButton) {
        openProfilModalButton.addEventListener('click', function(e) {
            e.preventDefault();
            openModal('profil-modal');
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
            ratingBadgeHtml(series) +
            reviewBadgeHtml(series) +
            `${series.mangaupdates_url ? `<a class="mu-badge" href="${series.mangaupdates_url}" target="_blank" rel="noopener" title="Voir sur MangaUpdates"><img src="assets/img/mulogo.png" alt="MangaUpdates" class="mu-logo"></a>` : ''}` +
            `${series.babelio_url ? `<a class="babelio-badge" href="${series.babelio_url}" target="_blank" rel="noopener" title="Voir sur Babelio"><img src="assets/img/babelogo.png" alt="Babelio" class="babelio-logo"></a>` : ''}`;

        const volumesList = document.getElementById('modal-volumes-list');
        volumesList.innerHTML = '';
        const sortedVolumes = series.volumes ? [...series.volumes].sort((a, b) => a.number - b.number) : [];
        sortedVolumes.forEach(volume => {
            const li = document.createElement('li');
            li.className = `status-${volume.status.replace(' ', '-')} ${volume.collector ? 'volume-collector' : ''} ${volume.last ? 'volume-last' : ''}`;
            li.textContent = volume.number;
            const tip = buildVolumeTooltip(volume);
            if (tip) li.setAttribute('data-title', tip);
            volumesList.appendChild(li);
        });

        document.querySelector('#series-detail-modal .modal-content').classList.toggle('favorite', !!series.favorite);

        window.__currentReviewSeries = series;
        setModalReviewBtn(series);
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

    // Récupérer les paramètres de recherche actuels
    const urlParams = new URLSearchParams(window.location.search);
    const searchTerm = currentSearchTerm || urlParams.get('search') || '';
    const sortBy = currentSortBy || urlParams.get('sort_by') || 'name';
    const sortOrder = currentSortOrder || urlParams.get('sort_order') || 'asc';

    fetch(`index.php?get_paginated_series=true&page=${currentPage + 1}&per_page=12&type=${encodeURIComponent(publicSeriesType())}&search=${encodeURIComponent(searchTerm)}&sort_by=${sortBy}&sort_order=${sortOrder}` + statusFilterQuery())
        .then(response => response.json())
        .then(data => {
            if (data.success && data.series && data.series.length > 0) {
                const seriesList = document.getElementById('series-list');
                data.series.forEach((series) => {
                    seriesData.push(series);
                    const seriesIndex = seriesData.length - 1;

                    const seriesCard = document.createElement('div');
                    seriesCard.className = `series-card ${series.mature ? 'mature' : ''} ${series.favorite ? 'favorite' : ''}`;
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
                            ratingBadgeHtml(series) +
                            reviewBadgeHtml(series) +
                            `${series.mangaupdates_url ? `<a class="mu-badge" href="${series.mangaupdates_url}" target="_blank" rel="noopener" title="Voir sur MangaUpdates"><img src="assets/img/mulogo.png" alt="MangaUpdates" class="mu-logo"></a>` : ''}` +
                            `${series.babelio_url ? `<a class="babelio-badge" href="${series.babelio_url}" target="_blank" rel="noopener" title="Voir sur Babelio"><img src="assets/img/babelogo.png" alt="Babelio" class="babelio-logo"></a>` : ''}`;

                        const volumesList = document.getElementById('modal-volumes-list');
                        volumesList.innerHTML = '';
                        const sortedVolumes = series.volumes ? [...series.volumes].sort((a, b) => a.number - b.number) : [];
                        sortedVolumes.forEach(volume => {
                            const li = document.createElement('li');
                            li.className = `status-${volume.status.replace(' ', '-')} ${volume.collector ? 'volume-collector' : ''} ${volume.last ? 'volume-last' : ''}`;
                            li.textContent = volume.number;
                            const tip = buildVolumeTooltip(volume);
                            if (tip) li.setAttribute('data-title', tip);
                            volumesList.appendChild(li);
                        });

                        document.querySelector('#series-detail-modal .modal-content').classList.toggle('favorite', !!series.favorite);

                        window.__currentReviewSeries = series;
                        setModalReviewBtn(series);
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
    fetch(`index.php?get_paginated_series=true&page=1&per_page=12&type=${encodeURIComponent(publicSeriesType())}&search=${encodeURIComponent(currentSearchTerm)}&sort_by=${currentSortBy}&sort_order=${currentSortOrder}` + statusFilterQuery())
        .then(response => response.json())
        .then(data => {
            const seriesList = document.getElementById('series-list');
            seriesList.innerHTML = ''; // Vide la liste

            if (data.success && data.series && data.series.length > 0) {
                data.series.forEach((series, index) => {
                    seriesData.push(series);
                    const seriesIndex = seriesData.length - 1;

                    const seriesCard = document.createElement('div');
                    seriesCard.className = `series-card ${series.mature ? 'mature' : ''} ${series.favorite ? 'favorite' : ''}`;
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
                            ratingBadgeHtml(series) +
                            reviewBadgeHtml(series) +
                            `${series.mangaupdates_url ? `<a class="mu-badge" href="${series.mangaupdates_url}" target="_blank" rel="noopener" title="Voir sur MangaUpdates"><img src="assets/img/mulogo.png" alt="MangaUpdates" class="mu-logo"></a>` : ''}` +
                            `${series.babelio_url ? `<a class="babelio-badge" href="${series.babelio_url}" target="_blank" rel="noopener" title="Voir sur Babelio"><img src="assets/img/babelogo.png" alt="Babelio" class="babelio-logo"></a>` : ''}`;

                        const volumesList = document.getElementById('modal-volumes-list');
                        volumesList.innerHTML = '';
                        const sortedVolumes = series.volumes ? [...series.volumes].sort((a, b) => a.number - b.number) : [];
                        sortedVolumes.forEach(volume => {
                            const li = document.createElement('li');
                            li.className = `status-${volume.status.replace(' ', '-')} ${volume.collector ? 'volume-collector' : ''} ${volume.last ? 'volume-last' : ''}`;
                            li.textContent = volume.number;
                            const tip = buildVolumeTooltip(volume);
                            if (tip) li.setAttribute('data-title', tip);
                            volumesList.appendChild(li);
                        });

                        document.querySelector('#series-detail-modal .modal-content').classList.toggle('favorite', !!series.favorite);

                        window.__currentReviewSeries = series;
                        setModalReviewBtn(series);
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

    // Synchronise l'état JS avec les contrôles / l'URL au chargement.
    (function initPublicFilterState() {
        const urlParams = new URLSearchParams(window.location.search);
        currentSearchTerm = urlParams.get('search') || document.getElementById('search-index')?.value || '';
        currentSortBy = document.getElementById('sort-by')?.value || urlParams.get('sort_by') || 'name';
        currentSortOrder = document.getElementById('sort-order')?.value || urlParams.get('sort_order') || 'asc';
    })();

    // Relance la requête (page 1) via le gestionnaire de soumission du formulaire,
    // qui gère déjà le rendu des cartes.
    function triggerPublicReload() {
        const form = document.querySelector('.filters form');
        if (form) form.dispatchEvent(new Event('submit', { cancelable: true }));
    }

    // Tri et ascendance : application en direct (sans bouton Appliquer).
    document.getElementById('sort-by')?.addEventListener('change', triggerPublicReload);
    document.getElementById('sort-order')?.addEventListener('change', triggerPublicReload);

    // Filtre de statuts : application en direct à chaque changement de sélection.
    document.getElementById('status-filter')?.addEventListener('statusfilter:change', triggerPublicReload);
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

    // with_types=1 : la barre de recherche traverse les collections et chaque
    // suggestion indique celles où elle apparaît.
    async function fetchSuggestions(term) {
        const normalized = normalizeString(term);
        const promises = fields.map(field =>
            fetch(`index.php?get_suggestions=true&with_types=1&field=${field}&term=${encodeURIComponent(normalized)}`)
                .then(r => r.ok ? r.json() : [])
                .catch(() => [])
        );
        const results = await Promise.all(promises);

        // Une même valeur peut remonter de plusieurs champs : on cumule ses types.
        const merged = new Map();
        results.flat().forEach(item => {
            if (!item || typeof item.value !== 'string') return;
            const types = merged.get(item.value) || [];
            (item.types || []).forEach(t => { if (!types.includes(t)) types.push(t); });
            merged.set(item.value, types);
        });
        return [...merged.entries()].map(([value, types]) => ({ value, types }));
    }

    // Si la suggestion n'existe que dans l'autre collection, on y bascule ;
    // sinon, recherche classique dans celle qui est affichée.
    function selectSuggestion(item) {
        input.value = item.value;
        suggestionsList.style.display = 'none';
        suggestionsList.querySelectorAll('div').forEach(d => d.classList.remove('autocomplete-active'));

        const types = Array.isArray(item.types) ? item.types : [];
        if (types.length > 0 && !types.includes(publicSeriesType())) {
            const params = new URLSearchParams(window.location.search);
            params.set('type', types[0]);
            params.set('search', item.value);
            params.delete('page');
            window.location.search = params.toString();
            return;
        }

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
            const filtered = suggestions.filter(item => normalizeString(item.value).includes(normalizedTerm));
            suggestionsList.innerHTML = '';
            if (filtered.length > 0) {
                filtered.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'autocomplete-suggestion';

                    const label = document.createElement('span');
                    label.className = 'suggestion-label';
                    label.textContent = item.value;
                    div.appendChild(label);

                    appendPublicTypeBadges(div, item.types);

                    div.__suggestion = item;
                    div.addEventListener('click', () => selectSuggestion(item));
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
        const items = [...suggestionsList.children];
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
            selectSuggestion(items[activeIdx].__suggestion || { value: items[activeIdx].textContent, types: [] });
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


// ══════════════════════════════════════════════════════════════════════════
// Critiques (côté public)
// Badge "✏️ Critique" dans la modale de détail → ouvre la modale critique.
// Le HTML de la critique est rendu et sanitizé côté serveur (endpoint
// index.php?get_review=…), jamais construit à partir de contenu brut ici.
// ══════════════════════════════════════════════════════════════════════════
function reviewBadgeHtml(series) {
    if (!window.reviewsPublic) return '';
    if (!series || !series.has_review) return '';
    return '<button type="button" class="review-badge" id="open-review-badge">✏️ Critique</button>';
}

// Bouton "Lire la critique" affiché sous la vignette dans la modale de détail.
function setModalReviewBtn(series) {
    const container = document.getElementById('modal-series-review-btn');
    if (!container) return;
    if (window.reviewsPublic && series && series.has_review) {
        container.innerHTML = '<button type="button" class="review-card-btn">✏️ Lire la critique</button>';
    } else {
        container.innerHTML = '';
    }
}

(function () {
    'use strict';

    const reviewModal = document.getElementById('review-detail-modal');
    if (!reviewModal) return;

    function htmlEscape(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    async function openReviewModal(series) {
        if (!series) return;
        // Pré-remplit l'en-tête (titre, vignette, auteur, éditeur, catégories).
        document.getElementById('review-modal-title').textContent = series.name || '';
        document.getElementById('review-modal-thumb').src = series.image || 'assets/img/logo.png';
        document.getElementById('review-modal-author').textContent = series.author ? ('Auteur : ' + series.author) : '';
        document.getElementById('review-modal-publisher').textContent = series.publisher ? ('Éditeur : ' + series.publisher) : '';
        const cats = (series.categories && series.categories.length) ? series.categories.join(', ') : '';
        document.getElementById('review-modal-categories').textContent = cats ? ('Catégories : ' + cats) : '';
        const creditReset = document.getElementById('review-modal-credit');
        creditReset.textContent = '';
        creditReset.style.display = 'none';
        const body = document.getElementById('review-modal-body');
        body.innerHTML = '<p class="review-preview-placeholder">Chargement…</p>';

        // Ferme la modale de détail, ouvre la modale critique.
        openModal('review-detail-modal');

        try {
            const res = await fetch('index.php?get_review=1&series_id=' + encodeURIComponent(series.id));
            const data = await res.json();
            if (data.success) {
                body.innerHTML = data.html || '';
                const creditEl = document.getElementById('review-modal-credit');
                if (data.author && data.author.trim() !== '') {
                    creditEl.innerHTML = 'Rédigé par <span class="review-credit-name">' + htmlEscape(data.author) + '</span>';
                    creditEl.style.display = '';
                } else {
                    creditEl.textContent = '';
                    creditEl.style.display = 'none';
                }
            } else {
                body.innerHTML = '<p class="review-preview-placeholder">Critique indisponible.</p>';
            }
        } catch (err) {
            body.innerHTML = '<p class="review-preview-placeholder">Erreur de chargement.</p>';
        }
    }

    // Exposé pour les boutons "Lire la critique" placés sur les cartes.
    window.openSeriesReview = openReviewModal;

    // Clic sur le badge "Critique" (délégué : le badge est recréé à chaque ouverture).
    document.addEventListener('click', function (e) {
        if (e.target.closest('#open-review-badge')) {
            openReviewModal(window.__currentReviewSeries);
        }
    });

    // Clic sur le bouton "Lire la critique" sous la vignette de la modale de détail.
    document.addEventListener('click', function (e) {
        if (e.target.closest('#modal-series-review-btn .review-card-btn')) {
            openReviewModal(window.__currentReviewSeries);
        }
    });

    // Bouton "Retour à la série" : rouvre la modale de détail.
    document.getElementById('review-modal-back')?.addEventListener('click', function () {
        const series = window.__currentReviewSeries;
        if (!series) { closeAllModals(); return; }
        // Ré-ouvre la modale de détail (déjà peuplée juste avant l'ouverture critique).
        openModal('series-detail-modal');
    });

    // Croix de fermeture de la modale critique.
    document.getElementById('close-review-detail-modal')?.addEventListener('click', closeAllModals);

    // Clic à l'extérieur ferme la modale critique.
    window.addEventListener('click', function (e) {
        if (e.target === reviewModal) closeAllModals();
    });
})();


// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
// ── Widget de filtre de statuts (cases à cocher regroupées + bascule OU/ET) ──
// Fournit window.StatusFilter.read() => { filter: "a,b,c", mode: "or"|"and" }
// et déclenche un événement 'statusfilter:change' quand la sélection change.
(function () {
    const root = document.getElementById('status-filter');
    if (!root || root.__sfInit) return;
    root.__sfInit = true;

    const panel   = root.querySelector('.status-filter-panel');
    const toggle  = root.querySelector('.status-filter-toggle');
    const modeSel = root.querySelector('.status-filter-mode');
    const toggleAllBtn = root.querySelector('.status-filter-toggle-all');
    const checkboxes = () => Array.from(root.querySelectorAll('.status-filter-cb'));
    const groups = () => Array.from(root.querySelectorAll('.status-filter-group'));

    function mode() { return modeSel && modeSel.value === 'and' ? 'and' : 'or'; }

    // En mode ET, on interdit plusieurs cases dans une même catégorie multi :
    // dès qu'une case est cochée dans le groupe, les autres sont grisées.
    function applyAndConstraints() {
        const isAnd = mode() === 'and';
        groups().forEach(group => {
            const multi = group.dataset.multi === '1';
            const cbs = Array.from(group.querySelectorAll('.status-filter-cb'));
            const anyChecked = cbs.some(cb => cb.checked);
            cbs.forEach(cb => {
                const disable = isAnd && multi && anyChecked && !cb.checked;
                cb.disabled = disable;
                cb.closest('.status-filter-option')?.classList.toggle('disabled', disable);
            });
        });
    }

    // Le libellé du bouton reflète l'état (nombre de cases cochées).
    function refreshLabel() {
        const label = root.querySelector('.status-filter-label');
        if (!label) return;
        const cbs = checkboxes();
        const total = cbs.length;
        const checked = cbs.filter(cb => cb.checked).length;
        if (checked === 0 || checked === total) {
            label.textContent = 'Statuts';
        } else {
            label.textContent = 'Statuts (' + checked + ')';
        }
    }

    // Lecture de l'état -> chaîne pour l'URL.
    // Tout coché OU rien coché => filtre vide (= tout afficher).
    root.__sfRead = function () {
        const cbs = checkboxes();
        const total = cbs.length;
        const checkedVals = cbs.filter(cb => cb.checked).map(cb => cb.value);
        let filter = checkedVals.join(',');
        if (checkedVals.length === 0 || checkedVals.length === total) {
            filter = '';
        }
        return { filter: filter, mode: mode() };
    };

    function emitChange() {
        applyAndConstraints();
        refreshLabel();
        root.dispatchEvent(new CustomEvent('statusfilter:change', { bubbles: true }));
    }

    // Ouverture / fermeture du panneau.
    toggle?.addEventListener('click', function (e) {
        e.stopPropagation();
        const open = panel.hasAttribute('hidden');
        if (open) { panel.removeAttribute('hidden'); toggle.setAttribute('aria-expanded', 'true'); }
        else      { panel.setAttribute('hidden', '');  toggle.setAttribute('aria-expanded', 'false'); }
    });
    document.addEventListener('click', function (e) {
        if (!root.contains(e.target)) {
            panel.setAttribute('hidden', '');
            toggle?.setAttribute('aria-expanded', 'false');
        }
    });
    panel?.addEventListener('click', e => e.stopPropagation());

    // Cases à cocher.
    checkboxes().forEach(cb => cb.addEventListener('change', emitChange));

    // Bascule OU/ET : en repassant en ET, on garde au plus une case par
    // catégorie multi (on décoche les surplus pour éviter un état incohérent).
    modeSel?.addEventListener('change', function () {
        root.dataset.statusMode = mode();
        if (mode() === 'and') {
            groups().forEach(group => {
                if (group.dataset.multi !== '1') return;
                let seen = false;
                Array.from(group.querySelectorAll('.status-filter-cb')).forEach(cb => {
                    if (cb.checked) {
                        if (seen) cb.checked = false;
                        else seen = true;
                    }
                });
            });
        }
        emitChange();
    });

    // Tout cocher / tout décocher.
    toggleAllBtn?.addEventListener('click', function () {
        const cbs = checkboxes();
        const shouldCheck = cbs.some(cb => !cb.checked); // s'il en reste des décochées -> tout cocher
        if (shouldCheck && mode() === 'and') {
            // En mode ET, "tout cocher" n'a pas de sens (une seule par catégorie).
            // On repasse en OU pour cocher réellement tout.
            if (modeSel) { modeSel.value = 'or'; root.dataset.statusMode = 'or'; }
        }
        cbs.forEach(cb => { cb.checked = shouldCheck; cb.disabled = false; cb.closest('.status-filter-option')?.classList.remove('disabled'); });
        toggleAllBtn.textContent = shouldCheck ? 'Tout décocher' : 'Tout cocher';
        toggleAllBtn.dataset.state = shouldCheck ? 'uncheck' : 'check';
        emitChange();
    });

    // État initial.
    applyAndConstraints();
    refreshLabel();
})();
});
