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
function buildVolumeTooltip(volume, isAnime) {
    const lines = [];
    const fmt = (d) => {
        if (!d) return '';
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d);
        return m ? `${m[3]}/${m[2]}/${m[1]}` : d;
    };
    // Un épisode ne s'achète pas : ni date d'ajout à la collection, ni collector.
    const added = fmt(volume.added_at);
    if (added && !isAnime) lines.push(`Date d'ajout à la collection : ${added}`);
    if (volume.status === 'terminé') {
        const done = fmt(volume.read_at);
        if (done) lines.push(`Date de ${isAnime ? 'visionnage' : 'lecture'} : ${done}`);
    }
    if (volume.collector && !isAnime) lines.push('Tome collector !');
    if (volume.last) lines.push(`Dernier ${isAnime ? 'épisode' : 'tome'} de la série !`);
    return lines.join('\n');
}

// ─────────────────────────────────────────────────────────────────────────────
// Rendu partagé des séries (cartes et modale de détails)
//
// Ces fonctions étaient auparavant recopiées à l'identique à trois endroits —
// affichage initial, pagination infinie et relance après recherche. Le typage
// des séries en aurait fait trois copies à maintenir en phase : elles sont donc
// factorisées ici, une bonne fois.
// ─────────────────────────────────────────────────────────────────────────────

function publicIsAnime(series) {
    return series && series.type === 'anime';
}

function publicEscape(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Vignette déjà arbitrée côté serveur (perso → Anilist → défaut).
function publicThumbnail(series) {
    return series.thumbnail || series.image || 'assets/img/logo.png';
}

// Statut de publication (manga) ou de diffusion (animé).
function publicStatusBadge(series) {
    let status = 'en cours';
    if (series.volumes && series.volumes.some(v => v.last)) {
        status = 'terminée';
    } else if (series.status) {
        status = series.status;
    }
    const word = publicIsAnime(series) ? 'diffusion' : 'publication';
    switch (status) {
        case 'terminée':   return { icon: `✅ ${word} terminée`,   cls: 'status-completed' };
        case 'en pause':   return { icon: `⏳ ${word} en pause`,   cls: 'status-paused' };
        case 'abandonnée': return { icon: `⛔ ${word} abandonnée`, cls: 'status-abandoned' };
        default:           return { icon: `▶️ ${word} en cours`,   cls: 'status-in-progress' };
    }
}

// Décompte affiché sous la carte et dans la modale.
function publicSeriesCount(series) {
    const total = series.volumes ? series.volumes.length : 0;
    const done  = series.volumes ? series.volumes.filter(v => v.status === 'terminé').length : 0;
    const p     = (n) => (n > 1 ? 's' : '');

    if (publicIsAnime(series)) {
        return `${total} épisode${p(total)} (${done} vu${p(done)})`;
    }
    if (series.read_elsewhere) {
        return `${done} tome${p(done)} lu${p(done)}`;
    }
    return `${total} tome${p(total)} possédé${p(total)} (${done} lu${p(done)})`;
}

// Badge « éditions physiques » (animés) : commentaires au survol.
function publicEditionsBadgeHtml(series) {
    const editions = series.editions || [];
    if (!editions.length) return '';
    const tip = editions.map(e => '• ' + e).join('\n');
    return `<span class="editions-badge" data-title="${publicEscape(tip)}" aria-label="Éditions physiques">` +
           `<img src="assets/img/physique.png" alt="Éditions physiques" class="editions-logo"></span>`;
}

// Lien vers la fiche Anilist, sur le modèle des badges MangaUpdates et Babelio.
function publicAnilistBadgeHtml(series) {
    if (!series.anilist_url) return '';
    return `<a class="anilist-badge" href="${publicEscape(series.anilist_url)}" target="_blank" rel="noopener" title="Voir la fiche sur Anilist">` +
           `<img src="assets/img/anilogo.png" alt="Anilist" class="anilist-logo"></a>`;
}

function publicSeriesCardClass(series) {
    return 'series-card'
        + (publicIsAnime(series) ? ' series-card--anime' : '')
        + (series.mature ? ' mature' : '')
        + (series.favorite ? ' favorite' : '');
}

// Contenu d'une carte. Un animé montre ses studios et son format là où un manga
// montre son auteur et son éditeur : ni l'un ni l'autre n'a de champ vide.
function publicSeriesCardHtml(series) {
    const identity = publicIsAnime(series)
        ? `<p><strong>Studios :</strong> ${series.studios_text ? publicEscape(series.studios_text) : '<em>inconnus</em>'}</p>
           <p><strong>Catégorie :</strong> ${publicEscape(series.format_label || '')}</p>`
        : `<p><strong>Auteur :</strong> ${publicEscape(series.author || '')}</p>
           <p><strong>Éditeur :</strong> ${publicEscape(series.publisher || '')}</p>`;

    return `
        <img class="series-image" src="${publicEscape(publicThumbnail(series))}" alt="${publicEscape(series.name)}" loading="lazy">
        <div class="series-info">
            <h2>${publicEscape(series.name)}</h2>
            ${identity}
            <div class="series-stats">${publicSeriesCount(series)}</div>
        </div>
    `;
}

// Remplit la modale de détails d'une série, quel que soit son type.
function fillSeriesDetailModal(series) {
    const isAnime = publicIsAnime(series);
    const show = (id, visible) => {
        const el = document.getElementById(id);
        if (el) el.style.display = visible ? '' : 'none';
    };
    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    };

    document.getElementById('modal-series-title').textContent = series.name;
    document.getElementById('modal-series-image').src = publicThumbnail(series);

    // Lignes propres à chaque collection.
    show('modal-row-author', !isAnime);
    show('modal-row-publisher', !isAnime);
    show('modal-row-contributors', !isAnime);
    show('modal-row-studios', isAnime);

    setText('modal-series-author', series.author || '');
    setText('modal-series-publisher', series.publisher || '');
    const contributors = (series.other_contributors || []).filter(i => i && i.trim() !== '');
    setText('modal-series-other-contributors', contributors.length ? contributors.join(', ') : 'aucun');
    setText('modal-series-studios', series.studios_text || 'inconnus');

    // La catégorie d'un animé, c'est son format : le libellé passe au singulier.
    setText('modal-label-categories', isAnime ? 'Catégorie :' : 'Catégories :');
    setText('modal-series-categories', (series.categories || []).filter(c => c && c.trim() !== '').join(', '));

    const genres = (series.genres || []).filter(i => i && i.trim() !== '');
    setText('modal-series-genres', genres.length ? genres.join(', ') : 'aucun');

    document.getElementById('modal-series-stats').innerHTML = publicSeriesCount(series);

    const badge = publicStatusBadge(series);
    document.getElementById('modal-series-badges').innerHTML =
        `${series.mature ? '<span class="mature-badge">🔞 mature</span>' : ''}` +
        `${(!isAnime && series.read_elsewhere) ? '<span class="read-elsewhere-badge">📖 lue ailleurs</span>' : ''}` +
        `<span class="series-status-badge ${badge.cls}">${badge.icon}</span>` +
        ratingBadgeHtml(series) +
        rewatchBadgeHtml(series) +
        reviewBadgeHtml(series) +
        (isAnime ? publicEditionsBadgeHtml(series) : '') +
        (isAnime ? publicAnilistBadgeHtml(series) : '') +
        `${(!isAnime && series.mangaupdates_url) ? `<a class="mu-badge" href="${series.mangaupdates_url}" target="_blank" rel="noopener" title="Voir sur MangaUpdates"><img src="assets/img/mulogo.png" alt="MangaUpdates" class="mu-logo"></a>` : ''}` +
        `${(!isAnime && series.babelio_url) ? `<a class="babelio-badge" href="${series.babelio_url}" target="_blank" rel="noopener" title="Voir sur Babelio"><img src="assets/img/babelogo.png" alt="Babelio" class="babelio-logo"></a>` : ''}`;

    const volumesTitle = document.getElementById('modal-volumes-title');
    if (volumesTitle) volumesTitle.textContent = isAnime ? 'Liste des épisodes :' : 'Liste des tomes :';

    const volumesList = document.getElementById('modal-volumes-list');
    volumesList.innerHTML = '';
    const sorted = series.volumes ? [...series.volumes].sort((a, b) => a.number - b.number) : [];
    sorted.forEach(volume => {
        const li = document.createElement('li');
        li.className = `status-${volume.status.replace(' ', '-')} ${(!isAnime && volume.collector) ? 'volume-collector' : ''} ${volume.last ? 'volume-last' : ''}`;
        li.textContent = volume.number;
        const tip = buildVolumeTooltip(volume, isAnime);
        if (tip) li.setAttribute('data-title', tip);
        volumesList.appendChild(li);
    });

    const modalContent = document.querySelector('#series-detail-modal .modal-content');
    modalContent.classList.toggle('favorite', !!series.favorite);
    // Marque la collection d'origine : le titre suit la couleur de son type,
    // comme sur la carte. La classe est portée par la modale et non par le titre
    // lui-même, pour rester disponible si d'autres éléments doivent en dépendre.
    modalContent.classList.toggle('modal-content--anime', isAnime);

    window.__currentReviewSeries = series;
    setModalReviewBtn(series);
    setModalLicenseBtn(series);
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

        fillSeriesDetailModal(series);
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
                    seriesCard.className = publicSeriesCardClass(series);
                    seriesCard.dataset.seriesIndex = seriesIndex;

                    seriesCard.innerHTML = publicSeriesCardHtml(series);

                    // Écouteur pour la nouvelle carte
                    seriesCard.addEventListener('click', function() {
                        const series = seriesData[this.dataset.seriesIndex];
                        fillSeriesDetailModal(series);
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
                    seriesCard.className = publicSeriesCardClass(series);
                    seriesCard.dataset.seriesIndex = seriesIndex;

                    seriesCard.innerHTML = publicSeriesCardHtml(series);

                    // Écouteur pour la nouvelle carte
                    seriesCard.addEventListener('click', function() {
                        const series = seriesData[this.dataset.seriesIndex];
                        fillSeriesDetailModal(series);
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

    // Studios et titres alternatifs (animés) inclus : la barre traverse les
    // deux collections, elle doit pouvoir retrouver un animé par son studio
    // ou par un titre autre que celui affiché sur sa carte, tout comme un
    // manga se retrouve déjà par auteur ou éditeur.
    const fields = ['name', 'author', 'publisher', 'categories', 'genres', 'other_contributors', 'studios', 'alt_titles'];

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

// Bouton "Licence" affiché sous le bouton Critique, dans la modale de détail.
// Visible uniquement si la série appartient à une licence.
function setModalLicenseBtn(series) {
    const container = document.getElementById('modal-series-license-btn');
    if (!container) return;
    if (window.licensesPublic && series && series.has_license) {
        container.innerHTML = '<button type="button" class="license-card-btn">📚 Licence</button>';
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
        // Pré-remplit l'en-tête (titre, vignette). Le sous-titre dépend du type :
        // auteur/éditeur pour un manga, studios pour un animé — même rôle
        // d'affichage, source différente (le champ `author`/`publisher` reste
        // vide côté serveur pour un animé, `studios_text` le remplace).
        document.getElementById('review-modal-title').textContent = series.name || '';
        document.getElementById('review-modal-thumb').src = series.image || 'assets/img/logo.png';
        const authorEl    = document.getElementById('review-modal-author');
        const publisherEl = document.getElementById('review-modal-publisher');
        if (series.type === 'anime') {
            authorEl.textContent = series.studios_text ? ('Studios : ' + series.studios_text) : '';
            publisherEl.textContent = '';
        } else {
            authorEl.textContent = series.author ? ('Auteur : ' + series.author) : '';
            publisherEl.textContent = series.publisher ? ('Éditeur : ' + series.publisher) : '';
        }
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


// ══════════════════════════════════════════════════════════════════════════
// Licences (côté public)
// Bouton "📚 Licence" dans la modale de détail → ouvre la modale listant
// toutes les séries de la même licence, dans l'ordre choisi par l'admin.
// Cliquer sur une entrée ferme cette modale et rouvre la modale de détail de
// la série choisie (déjà chargée dans seriesData, aucune requête nécessaire).
// ══════════════════════════════════════════════════════════════════════════
(function () {
    'use strict';

    const licenseModal = document.getElementById('license-detail-public-modal');
    if (!licenseModal) return;

    function htmlEscape(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    async function openLicenseModal(series) {
        if (!series || !series.license_id) return;

        document.getElementById('license-public-title').textContent = series.license_name || 'Licence';
        const listEl = document.getElementById('license-public-list');
        listEl.innerHTML = '<p class="reviews-empty">Chargement…</p>';

        // Ferme la modale de détail, ouvre la modale licence.
        openModal('license-detail-public-modal');

        try {
            const res = await fetch('index.php?get_license=1&license_id=' + encodeURIComponent(series.license_id));
            const data = await res.json();
            if (!data.success || !data.series || !data.series.length) {
                listEl.innerHTML = '<p class="reviews-empty">Aucune série disponible.</p>';
                return;
            }
            listEl.innerHTML = '';
            data.series.forEach(s => {
                const typeDef = (window.seriesTypes && window.seriesTypes[s.type || 'manga']) || null;
                const typeBadge = typeDef
                    ? `<span class="suggestion-type-badge license-public-row-type-badge" style="--type-color:${typeDef.color}">${htmlEscape(typeDef.label)}</span>`
                    : '';
                const meta = [s.author, s.category].filter(v => v && String(v).trim() !== '').join(' · ');
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'license-public-row';
                btn.dataset.seriesId = s.id;
                btn.innerHTML = `
                    <img class="license-public-row-thumb" src="${htmlEscape(s.image || 'assets/img/logo.png')}" alt="" loading="lazy">
                    <div class="license-public-row-info">
                        <p class="license-public-row-name">${htmlEscape(s.name)}${typeBadge}</p>
                        <p class="license-public-row-meta">${htmlEscape(meta)}</p>
                    </div>
                `;
                btn.addEventListener('click', () => openSeriesFromLicense(s.id));
                listEl.appendChild(btn);
            });
        } catch (err) {
            listEl.innerHTML = '<p class="reviews-empty">Erreur de chargement.</p>';
        }
    }

    // Rouvre la modale de détail pour une série de la licence, retrouvée dans
    // seriesData (déjà en mémoire, chargée par index.php).
    function openSeriesFromLicense(seriesId) {
        // window.allSeriesData couvre les deux collections (une licence peut
        // mélanger manga et animé) ; window.seriesData ne couvre que celle
        // actuellement affichée. On préfère la première quand disponible.
        const pool = Array.isArray(window.allSeriesData) ? window.allSeriesData
                   : (Array.isArray(window.seriesData) ? window.seriesData : null);
        if (!pool) return;
        const series = pool.find(s => s.id === seriesId);
        if (!series) return;
        fillSeriesDetailModal(series);
        openModal('series-detail-modal');
    }

    // Clic sur le bouton "Licence" sous la vignette de la modale de détail.
    document.addEventListener('click', function (e) {
        if (e.target.closest('#modal-series-license-btn .license-card-btn')) {
            openLicenseModal(window.__currentReviewSeries);
        }
    });

    // Croix de fermeture de la modale licence.
    document.getElementById('close-license-detail-public-modal')?.addEventListener('click', closeAllModals);

    // Clic à l'extérieur ferme la modale licence.
    window.addEventListener('click', function (e) {
        if (e.target === licenseModal) closeAllModals();
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
