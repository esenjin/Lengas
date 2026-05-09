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

async function fetchSuggestionsForFields(term, fields) {
    const normalizedTerm = normalizeString(term);
    const promises = fields.map(field =>
        fetch(`admin.php?get_suggestions=true&field=${field}&term=${encodeURIComponent(normalizedTerm)}`)
            .then(response => response.json())
    );
    const results = await Promise.all(promises);
    return [...new Set(results.flat())];
}

// Helper : ajoute la navigation clavier à une liste de suggestions
function addKeyboardNav(input, suggestionsList, onSelect) {
    input.addEventListener('keydown', function(e) {
        if (suggestionsList.style.display === 'none') return;

        const items = [...suggestionsList.querySelectorAll('div')];
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

    // Indique si cet input est la barre de recherche principale
    const isMainSearch = (inputId === 'search-all');

    input.addEventListener('input', async function() {
        const term = this.value.trim();
        if (term.length < 2) {
            suggestionsList.style.display = 'none';
            return;
        }

        try {
            const suggestions = await fetchSuggestionsForFields(term, fields);
            suggestionsList.innerHTML = '';
            if (suggestions.length > 0) {
                const normalizedTerm = normalizeString(term);
                suggestions
                    .filter(suggestion => normalizeString(suggestion).includes(normalizedTerm))
                    .forEach(suggestion => {
                        const div = document.createElement('div');
                        div.textContent = suggestion;
                        div.addEventListener('click', () => {
                            input.value = suggestion;
                            suggestionsList.style.display = 'none';
                            if (isMainSearch) triggerMainSearch(input);
                        });
                        suggestionsList.appendChild(div);
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
        input.value = activeItem.textContent;
        activeItem.classList.remove('autocomplete-active');
        suggestionsList.style.display = 'none';
        if (isMainSearch) triggerMainSearch(input);
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

    // Indique si cet input est la barre de recherche principale
    const isMainSearch = (inputId === 'search-all');

    function getLastTerm(value) {
        const parts = value.split(',').map(part => part.trim());
        return parts[parts.length - 1];
    }

    function selectSuggestion(suggestionText) {
        const parts = input.value.split(',').map(part => part.trim());
        parts[parts.length - 1] = suggestionText;
        input.value = parts.join(', ');
        suggestionsList.style.display = 'none';
        if (isMainSearch) triggerMainSearch(input);
    }

    input.addEventListener('input', async function() {
        const lastTerm = getLastTerm(this.value);

        if (lastTerm.length < 2) {
            suggestionsList.style.display = 'none';
            return;
        }

        try {
            const normalizedLastTerm = normalizeString(lastTerm);
            const suggestions = await fetchSuggestionsForFields(lastTerm, fields);

            suggestionsList.innerHTML = '';
            if (suggestions.length > 0) {
                const filteredSuggestions = suggestions.filter(suggestion =>
                    normalizeString(suggestion).includes(normalizedLastTerm)
                );

                filteredSuggestions.forEach(suggestion => {
                    const div = document.createElement('div');
                    div.textContent = suggestion;
                    div.addEventListener('click', () => selectSuggestion(suggestion));
                    suggestionsList.appendChild(div);
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
        selectSuggestion(activeItem.textContent);
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
setupMultiAutocomplete('search-all', ['name', 'author', 'other_contributors', 'publisher', 'categories', 'genres']);
