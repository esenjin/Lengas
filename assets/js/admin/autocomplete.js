// Recherche de série
function setupSeriesSearch(inputId, resultsId) {
    document.getElementById(inputId).addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        document.querySelectorAll(`#${resultsId} div`).forEach(div => {
            div.style.display = div.textContent.toLowerCase().includes(searchTerm) ? 'block' : 'none';
        });
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

            if (inputField) {
                inputField.value = seriesId;
            }
            if (searchInput) {
                searchInput.value = seriesName;
            }
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
    // Fusionner les résultats et supprimer les doublons
    return [...new Set(results.flat())];
}

// Autocomplétion pour les champs
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

    document.addEventListener('click', (e) => {
        if (e.target !== input) {
            suggestionsList.style.display = 'none';
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

    // Fonction pour extraire le dernier terme saisi
    function getLastTerm(value) {
        const parts = value.split(',').map(part => part.trim());
        return parts[parts.length - 1];
    }

    input.addEventListener('input', async function() {
        const lastTerm = getLastTerm(this.value);

        if (lastTerm.length < 2) {
            suggestionsList.style.display = 'none';
            return;
        }

        try {
            // Normaliser le dernier terme saisi
            const normalizedLastTerm = normalizeString(lastTerm);
            // Récupérer les suggestions pour tous les champs demandés
            const suggestions = await fetchSuggestionsForFields(lastTerm, fields);

            suggestionsList.innerHTML = '';
            if (suggestions.length > 0) {
                // Filtrer les suggestions pour qu'elles contiennent le terme normalisé
                const filteredSuggestions = suggestions.filter(suggestion =>
                    normalizeString(suggestion).includes(normalizedLastTerm)
                );

                filteredSuggestions.forEach(suggestion => {
                    const div = document.createElement('div');
                    div.textContent = suggestion;
                    div.addEventListener('click', () => {
                        const parts = this.value.split(',').map(part => part.trim());
                        parts[parts.length - 1] = suggestion;
                        this.value = parts.join(', ');
                        suggestionsList.style.display = 'none';
                    });
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

    document.addEventListener('click', (e) => {
        if (e.target !== input) {
            suggestionsList.style.display = 'none';
        }
    });
}

// Initialisation des recherches et autocomplétions
setupSeriesSearch('multiple-series-search', 'multiple-series-results');
setupSeriesSelection('series-results', 'selected-series-id', 'series-search');
setupSeriesSelection('multiple-series-results', 'multiple-selected-series-id', 'multiple-series-search');

// Initialisation des autocomplétions
setupAutocomplete('add-series-author', ['author', 'other_contributors']);
setupAutocomplete('add-series-publisher', ['publisher']);
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