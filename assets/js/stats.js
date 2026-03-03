document.addEventListener('DOMContentLoaded', function() {
    // 1. Graphique existant (si présent)
    if (typeof Chart !== 'undefined') {
        const ctx = document.getElementById('statusChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: chartLabels,
                datasets: [{
                    data: chartValues,
                    backgroundColor: [
                        '#9a67ea',  // À lire
                        '#bb86fc',  // En cours
                        '#4CAF50',  // Terminé
                        '#ff9800',  // En pause
                        '#cf6679'   // Abandonnée
                    ],
                    borderColor: [
                        '#8156c5',  // À lire
                        '#986cce',  // En cours
                        '#3b883d',  // Terminé
                        '#c77802',  // En pause
                        '#9c4e5c'   // Abandonnée
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = totalVolumes;
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: ${value} tomes (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // 2. Autocomplétion et recherche
    const searchInput = document.getElementById('search-input');
    const suggestionsContainer = document.getElementById('search-suggestions');
    const searchButton = document.getElementById('search-button');
    const searchResults = document.getElementById('search-results');

    // Fonction pour normaliser les chaînes
    function normalizeString(str) {
        return str.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
    }

    // Afficher les suggestions
    searchInput.addEventListener('input', function() {
        const term = this.value.trim();
        if (term.length < 2) {
            suggestionsContainer.classList.remove('show');
            return;
        }

        const normalizedTerm = normalizeString(term);
        const suggestions = new Set(); // Utiliser un Set pour éviter les doublons

        // Parcourir toutes les données pour trouver des correspondances
        searchData.forEach(series => {
            // Séries
            if (normalizeString(series.name).includes(normalizedTerm)) {
                suggestions.add(series.name);
            }
            // Auteurs
            if (normalizeString(series.author).includes(normalizedTerm)) {
                suggestions.add(series.author);
            }
            // Éditeurs
            if (normalizeString(series.publisher).includes(normalizedTerm)) {
                suggestions.add(series.publisher);
            }
            // Catégories
            if (series.categories) {
                series.categories.forEach(category => {
                    if (normalizeString(category).includes(normalizedTerm)) {
                        suggestions.add(category);
                    }
                });
            }
            // Genres
            if (series.genres) {
                series.genres.forEach(genre => {
                    if (normalizeString(genre).includes(normalizedTerm)) {
                        suggestions.add(genre);
                    }
                });
            }
            // Contributeurs
            if (series.other_contributors) {
                series.other_contributors.forEach(contributor => {
                    if (normalizeString(contributor).includes(normalizedTerm)) {
                        suggestions.add(contributor);
                    }
                });
            }
        });

        // Afficher les suggestions
        suggestionsContainer.innerHTML = '';
        if (suggestions.size > 0) {
            suggestions.forEach(suggestion => {
                const div = document.createElement('div');
                div.textContent = suggestion;
                div.addEventListener('click', function() {
                    searchInput.value = suggestion;
                    suggestionsContainer.classList.remove('show');
                });
                suggestionsContainer.appendChild(div);
            });
            suggestionsContainer.classList.add('show');
        } else {
            suggestionsContainer.classList.remove('show');
        }
    });

    // Masquer les suggestions si on clique ailleurs
    document.addEventListener('click', function(e) {
        if (e.target !== searchInput) {
            suggestionsContainer.classList.remove('show');
        }
    });

    // Soumission du formulaire (bouton ou entrée)
    function performSearch() {
        const term = searchInput.value.trim();
        if (term.length < 2) return;

        const normalizedTerm = normalizeString(term);
        const results = {
            series: [],
            authors: [],
            publishers: [],
            categories: [],
            genres: [],
            contributors: []
        };

        // Rechercher dans les données
        searchData.forEach(series => {
            // Séries
            if (normalizeString(series.name).includes(normalizedTerm)) {
                results.series.push(series);
            }
            // Auteurs
            if (normalizeString(series.author).includes(normalizedTerm)) {
                results.authors.push(series.author);
            }
            // Éditeurs
            if (normalizeString(series.publisher).includes(normalizedTerm)) {
                results.publishers.push(series.publisher);
            }
            // Catégories
            if (series.categories) {
                series.categories.forEach(category => {
                    if (normalizeString(category).includes(normalizedTerm)) {
                        results.categories.push(category);
                    }
                });
            }
            // Genres
            if (series.genres) {
                series.genres.forEach(genre => {
                    if (normalizeString(genre).includes(normalizedTerm)) {
                        results.genres.push(genre);
                    }
                });
            }
            // Contributeurs
            if (series.other_contributors) {
                series.other_contributors.forEach(contributor => {
                    if (normalizeString(contributor).includes(normalizedTerm)) {
                        results.contributors.push(contributor);
                    }
                });
            }
        });

        // Afficher les résultats
        displayResults(results, term);
    }

    // Gestion du clic sur le bouton
    searchButton.addEventListener('click', function(e) {
        e.preventDefault();
        performSearch();
    });

    // Gestion de la touche Entrée
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            performSearch();
        }
    });

    // Affichage des résultats
    function displayResults(results, term) {
        let html = '';

        // Séries
        if (results.series.length > 0) {
            html += `<h3>Séries (${results.series.length})</h3>`;
            results.series.forEach(series => {
                html += `
                    <div class="result-item" style="padding: 10px; border-bottom: 1px solid #444;">
                        <p><strong>${series.name}</strong></p>
                        <p>Auteur : ${series.author}</p>
                        <p>Éditeur : ${series.publisher}</p>
                        <p>Nombre de tomes : ${series.volumes_count}</p>
                        <a href="index.php?search=${encodeURIComponent(series.name)}" style="color: #bb86fc; text-decoration: none;">Voir sur l'accueil</a>
                    </div>
                `;
            });
        }

        // Auteurs
        if (results.authors.length > 0) {
            const uniqueAuthors = [...new Set(results.authors)];
            html += `<h3>Auteurs (${uniqueAuthors.length})</h3>`;
            uniqueAuthors.forEach(author => {
                const authorSeries = searchData.filter(s => normalizeString(s.author) === normalizeString(author));
                const totalVolumes = authorSeries.reduce((sum, s) => sum + s.volumes_count, 0);
                html += `
                    <div class="result-item" style="padding: 10px; border-bottom: 1px solid #444;">
                        <p><strong>${author}</strong></p>
                        <p>Nombre de séries : ${authorSeries.length}</p>
                        <p>Nombre de tomes : ${totalVolumes}</p>
                        <a href="index.php?search=${encodeURIComponent(author)}" style="color: #bb86fc; text-decoration: none;">Voir sur l'accueil</a>
                    </div>
                `;
            });
        }

        // Éditeurs
        if (results.publishers.length > 0) {
            const uniquePublishers = [...new Set(results.publishers)];
            html += `<h3>Éditeurs (${uniquePublishers.length})</h3>`;
            uniquePublishers.forEach(publisher => {
                const publisherSeries = searchData.filter(s => normalizeString(s.publisher) === normalizeString(publisher));
                const totalVolumes = publisherSeries.reduce((sum, s) => sum + s.volumes_count, 0);
                html += `
                    <div class="result-item" style="padding: 10px; border-bottom: 1px solid #444;">
                        <p><strong>${publisher}</strong></p>
                        <p>Nombre de séries : ${publisherSeries.length}</p>
                        <p>Nombre de tomes : ${totalVolumes}</p>
                        <a href="index.php?search=${encodeURIComponent(publisher)}" style="color: #bb86fc; text-decoration: none;">Voir sur l'accueil</a>
                    </div>
                `;
            });
        }

        // Catégories
        if (results.categories.length > 0) {
            const uniqueCategories = [...new Set(results.categories)];
            html += `<h3>Catégories (${uniqueCategories.length})</h3>`;
            uniqueCategories.forEach(category => {
                const categorySeries = searchData.filter(s =>
                    s.categories && s.categories.some(cat => normalizeString(cat) === normalizeString(category))
                );
                const totalVolumes = categorySeries.reduce((sum, s) => sum + s.volumes_count, 0);
                html += `
                    <div class="result-item" style="padding: 10px; border-bottom: 1px solid #444;">
                        <p><strong>${category}</strong></p>
                        <p>Nombre de séries : ${categorySeries.length}</p>
                        <p>Nombre de tomes : ${totalVolumes}</p>
                        <a href="index.php?search=${encodeURIComponent(category)}" style="color: #bb86fc; text-decoration: none;">Voir sur l'accueil</a>
                    </div>
                `;
            });
        }

        // Genres
        if (results.genres.length > 0) {
            const uniqueGenres = [...new Set(results.genres)];
            html += `<h3>Genres (${uniqueGenres.length})</h3>`;
            uniqueGenres.forEach(genre => {
                const genreSeries = searchData.filter(s =>
                    s.genres && s.genres.some(g => normalizeString(g) === normalizeString(genre))
                );
                const totalVolumes = genreSeries.reduce((sum, s) => sum + s.volumes_count, 0);
                html += `
                    <div class="result-item" style="padding: 10px; border-bottom: 1px solid #444;">
                        <p><strong>${genre}</strong></p>
                        <p>Nombre de séries: ${genreSeries.length}</p>
                        <p>Nombre de tomes : ${totalVolumes}</p>
                        <a href="index.php?search=${encodeURIComponent(genre)}" style="color: #bb86fc; text-decoration: none;">Voir sur l'accueil</a>
                    </div>
                `;
            });
        }

        // Contributeurs
        if (results.contributors.length > 0) {
            const uniqueContributors = [...new Set(results.contributors)];
            html += `<h3>Contributeurs (${uniqueContributors.length})</h3>`;
            uniqueContributors.forEach(contributor => {
                const contributorSeries = searchData.filter(s =>
                    s.other_contributors && s.other_contributors.some(c => normalizeString(c) === normalizeString(contributor))
                );
                const totalVolumes = contributorSeries.reduce((sum, s) => sum + s.volumes_count, 0);
                html += `
                    <div class="result-item" style="padding: 10px; border-bottom: 1px solid #444;">
                        <p><strong>${contributor}</strong></p>
                        <p>Nombre de séries : ${contributorSeries.length}</p>
                        <p>Nombre de tomes : ${totalVolumes}</p>
                        <a href="index.php?search=${encodeURIComponent(contributor)}" style="color: #bb86fc; text-decoration: none;">Voir sur l'accueil</a>
                    </div>
                `;
            });
        }

        // Afficher les résultats ou un message si aucun résultat
        if (html) {
            searchResults.innerHTML = html;
            searchResults.style.display = 'block';
        } else {
            searchResults.innerHTML = '<p>Aucun résultat trouvé.</p>';
            searchResults.style.display = 'block';
        }
    }
});