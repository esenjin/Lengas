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

// Autocomplétion pour les champs
function setupAutocomplete(inputId, field) {
    const input = document.getElementById(inputId);
    if (!input) return;

    const container = document.createElement('div');
    container.className = 'autocomplete-container';
    input.parentNode.insertBefore(container, input);
    container.appendChild(input);

    const suggestionsList = document.createElement('div');
    suggestionsList.className = 'autocomplete-suggestions';
    container.appendChild(suggestionsList);

    input.addEventListener('input', function() {
        const term = this.value.trim();
        if (term.length < 2) {
            suggestionsList.style.display = 'none';
            return;
        }

        fetch(`admin.php?get_suggestions=true&field=${field}&term=${encodeURIComponent(term)}`)
            .then(response => response.json())
            .then(suggestions => {
                suggestionsList.innerHTML = '';
                if (suggestions.length > 0) {
                    suggestions.forEach(suggestion => {
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
            })
            .catch(error => console.error('Erreur:', error));
    });

    document.addEventListener('click', (e) => {
        if (e.target !== input) {
            suggestionsList.style.display = 'none';
        }
    });
}

// Pour les champs "catégories" et "genres"
function setupMultiAutocomplete(inputId, field) {
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

    function getLastTerm(value) {
        const parts = value.split(',').map(part => part.trim());
        const lastPart = parts[parts.length - 1];
        return lastPart;
    }

    input.addEventListener('input', function() {
        const lastTerm = getLastTerm(this.value);

        if (lastTerm.length < 2) {
            suggestionsList.style.display = 'none';
            return;
        }

        fetch(`admin.php?get_suggestions=true&field=${field}&term=${encodeURIComponent(lastTerm)}`)
            .then(response => response.json())
            .then(suggestions => {
                suggestionsList.innerHTML = '';
                if (suggestions.length > 0) {
                    suggestions.forEach(suggestion => {
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
            })
            .catch(error => console.error('Erreur lors de la récupération des suggestions :', error));
    });

    document.addEventListener('click', (e) => {
        if (e.target !== input) {
            suggestionsList.style.display = 'none';
        }
    });
}

// Initialisation des recherches et autocomplétions
setupSeriesSearch('series-search', 'series-results');
setupSeriesSearch('multiple-series-search', 'multiple-series-results');
setupSeriesSelection('series-results', 'selected-series-id', 'series-search');
setupSeriesSelection('multiple-series-results', 'multiple-selected-series-id', 'multiple-series-search');

// Initialisation des autocomplétions
setupAutocomplete('add-series-author', 'author');
setupAutocomplete('add-series-publisher', 'publisher');
setupAutocomplete('edit-series-author', 'author');
setupAutocomplete('edit-series-publisher', 'publisher');
setupAutocomplete('wishlist-author', 'author');
setupAutocomplete('wishlist-publisher', 'publisher');
setupMultiAutocomplete('add-series-categories', 'categories');
setupMultiAutocomplete('add-series-genres', 'genres');
setupMultiAutocomplete('edit-series-categories', 'categories');
setupMultiAutocomplete('edit-series-genres', 'genres');