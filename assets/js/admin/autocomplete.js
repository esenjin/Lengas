// Recherche de série
function setupSeriesSearch(inputId, resultsId) {
    const input = document.getElementById(inputId);
    if (!input) return;

    input.addEventListener('input', function() {
        const searchTerm = normalizeString(this.value);
        const resultsDiv = document.getElementById(resultsId);
        if (!resultsDiv) return;

        const visibleDivs = [];
        resultsDiv.querySelectorAll('div').forEach(div => {
            const visible = normalizeString(div.textContent).includes(searchTerm);
            div.style.display = visible ? 'block' : 'none';
            if (visible) visibleDivs.push(div);
        });

        // Reset highlight
        visibleDivs.forEach(d => d.classList.remove('autocomplete-active'));
    });

    input.addEventListener('keydown', function(e) {
        const resultsDiv = document.getElementById(resultsId);
        if (!resultsDiv || resultsDiv.style.display === 'none') return;

        const items = [...resultsDiv.querySelectorAll('div')].filter(d => d.style.display !== 'none');
        if (!items.length) return;

        const activeIndex = items.findIndex(d => d.classList.contains('autocomplete-active'));

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const next = activeIndex < items.length - 1 ? activeIndex + 1 : 0;
            items.forEach(d => d.classList.remove('autocomplete-active'));
            items[next].classList.add('autocomplete-active');
            items[next].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prev = activeIndex > 0 ? activeIndex - 1 : items.length - 1;
            items.forEach(d => d.classList.remove('autocomplete-active'));
            items[prev].classList.add('autocomplete-active');
            items[prev].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0) {
                e.preventDefault();
                items[activeIndex].click();
            }
        } else if (e.key === 'Escape') {
            resultsDiv.style.display = 'none';
            items.forEach(d => d.classList.remove('autocomplete-active'));
        }
    });
}

// Sélection d'une série
function setupSeriesSelection(resultsId, inputId, searchInputId) {
    const resultsDiv = document.getElementById(resultsId);
    if (!resultsDiv) return;

    resultsDiv.querySelectorAll('div').forEach(div => {
        div.addEventListener('click', function() {
            const seriesId = this.dataset.id;
            const seriesName = this.textContent;
            const inputField = document.getElementById(inputId);
            const searchInput = document.getElementById(searchInputId);

            if (inputField) inputField.value = seriesId;
            if (searchInput) searchInput.value = seriesName;

            resultsDiv.querySelectorAll('div').forEach(d => d.classList.remove('autocomplete-active'));
            resultsDiv.style.display = 'none';
        });
    });
}

// Fonction pour normaliser les chaînes de caractères
function normalizeString(str) {
    return str
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "");
}

// ── Contexte de typage ──────────────────────────────────────────────────────
// window.currentSeriesType et window.seriesTypes sont posés par admin.php.
//
// ⚠️ Ne JAMAIS nommer cette fonction currentSeriesType : une déclaration de
// fonction au premier niveau crée window.<nom>, ce qui écraserait la valeur
// posée par admin.php par la fonction elle-même. La collection affichée serait
// alors une fonction et non une chaîne, et toute la vue retomberait sur le type
// par défaut.
function currentViewType() {
    return typeof window.currentSeriesType === 'string' ? window.currentSeriesType : 'manga';
}

function seriesTypeDef(type) {
    return (window.seriesTypes && window.seriesTypes[type]) || null;
}

// Badge coloré signalant la collection d'où provient une suggestion.
function appendTypeBadges(container, types) {
    if (!Array.isArray(types)) return;
    types.forEach(type => {
        const def = seriesTypeDef(type);
        if (!def) return;
        const badge = document.createElement('span');
        badge.className = 'suggestion-type-badge';
        badge.textContent = def.label;
        badge.style.setProperty('--type-color', def.color);
        container.appendChild(badge);
    });
}

// Uniformise les deux formats de réponse de l'endpoint en { value, types }.
function normalizeSuggestions(raw) {
    return (raw || []).map(item =>
        typeof item === 'string'
            ? { value: item, types: [] }
            : { value: item.value, types: item.types || [] }
    );
}

// Sélection dans la barre de recherche principale : si la suggestion n'existe
// que dans l'autre collection, on bascule la vue au lieu de chercher dans le
// vide. Sinon, recherche classique dans la collection courante.
function handleMainSearchSelection(item, input) {
    const types = Array.isArray(item.types) ? item.types : [];
    if (types.length > 0 && !types.includes(currentViewType())) {
        const params = new URLSearchParams(window.location.search);
        params.set('type', types[0]);
        params.set('search', input.value);
        params.delete('page');
        window.location.search = params.toString();
        return;
    }
    triggerMainSearch(input);
}

// Récupère les suggestions pour une liste de champs.
// opts.withTypes   → réponse enrichie du type de chaque suggestion (barre de
//                    recherche principale, qui traverse les collections) ;
// opts.restrictType → limite la recherche à une seule collection (champs des
//                    modales, qui ne concernent que la collection affichée).
async function fetchSuggestionsForFields(term, fields, opts = {}) {
    const normalizedTerm = normalizeString(term);
    const extra =
        (opts.withTypes ? '&with_types=1' : '') +
        (opts.restrictType ? `&restrict_type=${encodeURIComponent(opts.restrictType)}` : '');

    const promises = fields.map(field =>
        fetch(`admin.php?get_suggestions=true&field=${field}&term=${encodeURIComponent(normalizedTerm)}${extra}`)
            .then(response => response.json())
            .catch(() => [])
    );
    const results = await Promise.all(promises);

    if (!opts.withTypes) {
        return [...new Set(results.flat())].map(value => ({ value, types: [] }));
    }

    // Une même valeur peut remonter de plusieurs champs pour un même type
    // (ex. "Overlord" trouvé à la fois via "name" et "alt_titles" pour l'animé) :
    // on déduplique par (valeur, type). En revanche on NE fusionne PAS les types
    // entre eux : "Overlord" manga et "Overlord" animé restent deux entrées
    // distinctes, chacune avec son propre badge, pour qu'on sache précisément
    // laquelle on sélectionne.
    const merged = new Map();
    results.flat().forEach(item => {
        if (!item || typeof item.value !== 'string') return;
        const itemTypes = (item.types && item.types.length) ? item.types : [null];
        itemTypes.forEach(type => {
            const key = item.value + '\u0001' + (type || '');
            if (!merged.has(key)) {
                merged.set(key, { value: item.value, types: type ? [type] : [] });
            }
        });
    });
    return [...merged.values()];
}

// Construit une ligne de suggestion. L'objet source est attaché au noeud pour
// que la navigation clavier retrouve la valeur exacte (sans le texte du badge).
function buildSuggestionItem(item, showBadges, onSelect) {
    const div = document.createElement('div');
    div.className = 'autocomplete-suggestion';

    const label = document.createElement('span');
    label.className = 'suggestion-label';
    label.textContent = item.value;
    div.appendChild(label);

    if (showBadges) appendTypeBadges(div, item.types);

    div.__suggestion = item;
    div.addEventListener('click', () => onSelect(item));
    return div;
}

// Helper : ajoute la navigation clavier à une liste de suggestions
function addKeyboardNav(input, suggestionsList, onSelect) {
    input.addEventListener('keydown', function(e) {
        if (suggestionsList.style.display === 'none') return;

        const items = [...suggestionsList.children];
        if (!items.length) return;

        const activeIndex = items.findIndex(d => d.classList.contains('autocomplete-active'));

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const next = activeIndex < items.length - 1 ? activeIndex + 1 : 0;
            items.forEach(d => d.classList.remove('autocomplete-active'));
            items[next].classList.add('autocomplete-active');
            items[next].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prev = activeIndex > 0 ? activeIndex - 1 : items.length - 1;
            items.forEach(d => d.classList.remove('autocomplete-active'));
            items[prev].classList.add('autocomplete-active');
            items[prev].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0) {
                e.preventDefault();
                onSelect(items[activeIndex]);
            }
        } else if (e.key === 'Escape') {
            suggestionsList.style.display = 'none';
            items.forEach(d => d.classList.remove('autocomplete-active'));
        }
    });
}

// Autocomplétion pour les champs simples
function setupAutocomplete(inputId, fields) {
    const input = document.getElementById(inputId);
    if (!input) return;

    const container = document.createElement('div');
    container.className = 'autocomplete-container';
    input.parentNode.insertBefore(container, input);
    container.appendChild(input);

    const suggestionsList = document.createElement('div');
    suggestionsList.className = 'autocomplete-suggestions';
    container.appendChild(suggestionsList);

    // La barre de recherche principale traverse les collections ; les champs des
    // modales restent cantonnés à celle qui est affichée.
    const isMainSearch = (inputId === 'search-all');
    const fetchOpts = isMainSearch
        ? { withTypes: true }
        : { restrictType: currentViewType() };

    function applySuggestion(item) {
        input.value = item.value;
        suggestionsList.style.display = 'none';
        if (isMainSearch) handleMainSearchSelection(item, input);
    }

    input.addEventListener('input', async function() {
        const term = this.value.trim();
        if (term.length < 2) {
            suggestionsList.style.display = 'none';
            return;
        }

        try {
            const suggestions = normalizeSuggestions(
                await fetchSuggestionsForFields(term, fields, fetchOpts)
            );
            suggestionsList.innerHTML = '';
            const normalizedTerm = normalizeString(term);
            const filtered = suggestions.filter(item =>
                normalizeString(item.value).includes(normalizedTerm)
            );
            if (filtered.length > 0) {
                filtered.forEach(item => {
                    suggestionsList.appendChild(
                        buildSuggestionItem(item, isMainSearch, applySuggestion)
                    );
                });
                suggestionsList.style.display = 'block';
            } else {
                suggestionsList.style.display = 'none';
            }
        } catch (error) {
            console.error('Erreur:', error);
        }
    });

    addKeyboardNav(input, suggestionsList, (activeItem) => {
        activeItem.classList.remove('autocomplete-active');
        applySuggestion(activeItem.__suggestion || { value: activeItem.textContent, types: [] });
    });

    document.addEventListener('click', (e) => {
        if (e.target !== input) {
            suggestionsList.style.display = 'none';
            suggestionsList.querySelectorAll('div').forEach(d => d.classList.remove('autocomplete-active'));
        }
    });
}

// Autocomplétion pour les champs multiples
function setupMultiAutocomplete(inputId, fields) {
    const input = document.getElementById(inputId);
    if (!input) {
        console.error(`Input avec l'ID ${inputId} non trouvé.`);
        return;
    }

    const container = document.createElement('div');
    container.className = 'autocomplete-container';
    input.parentNode.insertBefore(container, input);
    container.appendChild(input);

    const suggestionsList = document.createElement('div');
    suggestionsList.className = 'autocomplete-suggestions';
    container.appendChild(suggestionsList);

    // Idem : seule la barre de recherche principale traverse les collections.
    const isMainSearch = (inputId === 'search-all');
    const fetchOpts = isMainSearch
        ? { withTypes: true }
        : { restrictType: currentViewType() };

    function getLastTerm(value) {
        const parts = value.split(',').map(part => part.trim());
        return parts[parts.length - 1];
    }

    function selectSuggestion(item) {
        const parts = input.value.split(',').map(part => part.trim());
        parts[parts.length - 1] = item.value;
        input.value = parts.join(', ');
        suggestionsList.style.display = 'none';
        if (isMainSearch) handleMainSearchSelection(item, input);
    }

    input.addEventListener('input', async function() {
        const lastTerm = getLastTerm(this.value);

        if (lastTerm.length < 2) {
            suggestionsList.style.display = 'none';
            return;
        }

        try {
            const normalizedLastTerm = normalizeString(lastTerm);
            const suggestions = normalizeSuggestions(
                await fetchSuggestionsForFields(lastTerm, fields, fetchOpts)
            );

            suggestionsList.innerHTML = '';
            const filteredSuggestions = suggestions.filter(item =>
                normalizeString(item.value).includes(normalizedLastTerm)
            );

            if (filteredSuggestions.length > 0) {
                filteredSuggestions.forEach(item => {
                    suggestionsList.appendChild(
                        buildSuggestionItem(item, isMainSearch, selectSuggestion)
                    );
                });
                suggestionsList.style.display = 'block';
            } else {
                suggestionsList.style.display = 'none';
            }
        } catch (error) {
            console.error('Erreur lors de la récupération des suggestions :', error);
        }
    });

    addKeyboardNav(input, suggestionsList, (activeItem) => {
        selectSuggestion(activeItem.__suggestion || { value: activeItem.textContent, types: [] });
    });

    document.addEventListener('click', (e) => {
        if (e.target !== input) {
            suggestionsList.style.display = 'none';
            suggestionsList.querySelectorAll('div').forEach(d => d.classList.remove('autocomplete-active'));
        }
    });
}

// Déclenche la recherche principale après sélection d'une suggestion
function triggerMainSearch(input) {
    // Tente de soumettre le formulaire parent (.filters form)
    const form = input.closest('form') || document.querySelector('.filters form');
    if (form) {
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }
}

// Initialisation des recherches et autocomplétions
setupSeriesSearch('multiple-series-search', 'multiple-series-results');
setupSeriesSelection('series-results', 'selected-series-id', 'series-search');
setupSeriesSelection('multiple-series-results', 'multiple-selected-series-id', 'multiple-series-search');

// Initialisation des autocomplétions
setupAutocomplete('add-series-name', ['name']);
setupAutocomplete('add-series-author', ['author', 'other_contributors']);
setupAutocomplete('add-series-publisher', ['publisher']);
setupAutocomplete('edit-series-name', ['name']);
setupAutocomplete('edit-series-author', ['author', 'other_contributors']);
setupAutocomplete('edit-series-publisher', ['publisher']);
setupAutocomplete('wishlist-author', ['author', 'other_contributors']);
setupAutocomplete('wishlist-publisher', ['publisher']);
setupMultiAutocomplete('add-series-categories', ['categories']);
setupMultiAutocomplete('add-series-genres', ['genres']);
setupMultiAutocomplete('edit-series-categories', ['categories']);
setupMultiAutocomplete('edit-series-genres', ['genres']);
setupMultiAutocomplete('add-series-other-contributors', ['author', 'other_contributors']);
setupMultiAutocomplete('edit-series-other-contributors', ['author', 'other_contributors']);
// La barre de recherche principale traverse les deux collections : elle
// interroge aussi les champs propres aux animés (studios, titres alternatifs),
// sans effet côté mangas (endpoint get_suggestions les ignore pour ce type).
setupMultiAutocomplete('search-all', ['name', 'author', 'other_contributors', 'publisher', 'categories', 'genres', 'studios', 'alt_titles']);
