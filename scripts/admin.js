// Gestion des modales
const modals = {
    'add-series': { modal: document.getElementById('add-series-modal'), closeBtn: document.getElementById('close-add-series-modal') },
    'add-volume': { modal: document.getElementById('add-volume-modal'), closeBtn: document.getElementById('close-add-volume-modal') },
    'add-multiple-volumes': { modal: document.getElementById('add-multiple-volumes-modal'), closeBtn: document.getElementById('close-add-multiple-volumes-modal') },
    'edit-volume': { modal: document.getElementById('edit-volume-modal'), closeBtn: document.getElementById('close-edit-volume-modal') },
    'edit-series': { modal: document.getElementById('edit-series-modal'), closeBtn: document.getElementById('close-edit-series-modal') },
    'wishlist': { modal: document.getElementById('wishlist-modal'), closeBtn: document.getElementById('close-wishlist-modal') },
    'options': { modal: document.getElementById('options-modal'), closeBtn: document.getElementById('close-options-modal') },
    'incomplete-series': { modal: document.getElementById('incomplete-series-modal'), closeBtn: document.getElementById('close-incomplete-series-modal') },
    'loan': { modal: document.getElementById('loan-modal'), closeBtn: document.getElementById('close-loan-modal') }
};

// Ouverture des modales
document.getElementById('open-add-series-modal').addEventListener('click', () => modals['add-series'].modal.classList.add('modal-active'));
document.getElementById('open-add-volume-modal').addEventListener('click', () => {
    modals['add-volume'].modal.classList.add('modal-active');
    document.getElementById('series-results').style.display = 'block';
});
document.getElementById('open-add-multiple-volumes-modal').addEventListener('click', () => {
    modals['add-multiple-volumes'].modal.classList.add('modal-active');
    document.getElementById('multiple-series-results').style.display = 'block';
});
document.getElementById('open-wishlist-modal').addEventListener('click', () => {
    modals['wishlist'].modal.classList.add('modal-active');
});
document.getElementById('open-options-modal').addEventListener('click', () => {
    modals['options'].modal.classList.add('modal-active');
});
document.getElementById('open-incomplete-series-modal').addEventListener('click', () => {
    modals['incomplete-series'].modal.classList.add('modal-active');
});

// Fermeture des modales via la croix
Object.values(modals).forEach(({ closeBtn, modal }) => {
    if (closeBtn && modal) {
        closeBtn.addEventListener('click', () => {
            modal.classList.remove('modal-active');
            // condition pour la modale des séries incomplètes
            if (modal === document.getElementById('incomplete-series-modal')) {
                location.reload();
            }
        });
    }
});

// Fermeture des modales en cliquant à l'extérieur
window.addEventListener('click', (e) => {
    Object.values(modals).forEach(({ modal }) => {
        if (e.target === modal) {
            modal.classList.remove('modal-active');
            // condition pour la modale des séries incomplètes
            if (modal === document.getElementById('incomplete-series-modal')) {
                location.reload();
            }
        }
    });
});

// Recherche des séries incomplètes
document.getElementById('search-incomplete-series').addEventListener('click', function() {
    const resultsDiv = document.getElementById('incomplete-series-results');
    resultsDiv.innerHTML = '<p>Recherche en cours...</p>';

    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_incomplete_series'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.text(); // D'abord, obtenez le texte de la réponse
    })
    .then(text => {
        try {
            const data = JSON.parse(text); // Ensuite, parsez le texte en JSON
            if (data.success) {
                displayIncompleteSeries(data.incomplete_series);
            } else {
                resultsDiv.innerHTML = '<p>Une erreur est survenue lors de la recherche des séries incomplètes.</p>';
            }
        } catch (error) {
            console.error('Erreur de parsing JSON:', error);
            resultsDiv.innerHTML = '<p>Une erreur est survenue lors de la recherche des séries incomplètes.</p>';
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        resultsDiv.innerHTML = '<p>Une erreur est survenue lors de la recherche des séries incomplètes. Veuillez réessayer plus tard.</p>';
    });
});

// Affichage des séries incomplètes
function displayIncompleteSeries(incomplete_series) {
    const resultsDiv = document.getElementById('incomplete-series-results');
    resultsDiv.innerHTML = '';

    if (incomplete_series.length === 0) {
        resultsDiv.innerHTML = '<p>Aucune série incomplète trouvée.</p>';
        return;
    }

    incomplete_series.forEach(series => {
        const seriesDiv = document.createElement('div');
        seriesDiv.className = 'incomplete-series-item';

        let html = `
            <h3>${series.name}</h3>
            <p><strong>Auteur :</strong> ${series.author}</p>
            <p><strong>Éditeur :</strong> ${series.publisher}</p>
            <p><strong>Tomes possédés :</strong> ${series.volumes.length}</p>
        `;

        if (series.missing_volumes && series.missing_volumes.length > 0) {
            html += `<p><strong>Tomes manquants :</strong> ${series.missing_volumes.join(', ')}</p>`;
        } else if (series.has_more_volumes) {
            html += `<p><strong>Tomes manquants :</strong> Aucun</p>`;
            html += `<p class="issues-list"><strong>Attention :</strong> Votre série contient plus de tomes que ce qui est indiqué sur Anilist.</p>`;
        }

        html += `<div class="missing-volumes-actions">`;

        if (series.missing_volumes && series.missing_volumes.length > 0) {
            series.missing_volumes.forEach(volume => {
                html += `
                    <button class="add-missing-volume" data-series-id="${series.id}" data-volume-number="${volume}">
                        + Tome ${volume}
                    </button>
                `;
            });

            html += `
                <button class="add-all-missing-volumes" data-series-id="${series.id}" data-missing-volumes="${series.missing_volumes.join(',')}">
                    Tout ajouter
                </button>
            `;
        }

        html += `</div>`;

        seriesDiv.innerHTML = html;
        resultsDiv.appendChild(seriesDiv);
    });

    // Ajouter les événements aux boutons
    document.querySelectorAll('.add-missing-volume').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            const volumeNumber = parseInt(this.dataset.volumeNumber);

            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=add_missing_volume&series_id=${seriesId}&volume_number=${volumeNumber}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Tome ajouté avec succès !');
                    // Recharger les séries incomplètes pour mettre à jour l'affichage
                    fetch('admin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=get_incomplete_series'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            displayIncompleteSeries(data.incomplete_series);
                        }
                    });
                } else {
                    alert('Une erreur est survenue lors de l\'ajout du tome.');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Une erreur est survenue lors de l\'ajout du tome.');
            });
        });
    });

    document.querySelectorAll('.add-all-missing-volumes').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            const missingVolumes = this.dataset.missingVolumes.split(',').map(Number);

            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=add_all_missing_volumes&series_id=${seriesId}&missing_volumes=${missingVolumes.join(',')}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Tomes ajoutés avec succès !');
                    // Recharger les séries incomplètes pour mettre à jour l'affichage
                    fetch('admin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=get_incomplete_series'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            displayIncompleteSeries(data.incomplete_series);
                        }
                    });
                } else {
                    alert('Une erreur est survenue lors de l\'ajout des tomes.');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Une erreur est survenue lors de l\'ajout des tomes.');
            });
        });
    });
}

// Recherche de série
function setupSeriesSearch(inputId, resultsId) {
    document.getElementById(inputId).addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        document.querySelectorAll(`#${resultsId} div`).forEach(div => {
            div.style.display = div.textContent.toLowerCase().includes(searchTerm) ? 'block' : 'none';
        });
    });
}

setupSeriesSearch('series-search', 'series-results');
setupSeriesSearch('multiple-series-search', 'multiple-series-results');

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
            // Masquer les résultats
            resultsDiv.style.display = 'none';
        });
    });
}

setupSeriesSelection('series-results', 'selected-series-id', 'series-search');
setupSeriesSelection('multiple-series-results', 'multiple-selected-series-id', 'multiple-series-search');

// Édition d'un tome
document.querySelectorAll('.volumes-list li').forEach(li => {
    li.addEventListener('click', function() {
        const seriesId = this.dataset.seriesId;
        const volumeIndex = this.dataset.volumeIndex;
        const volumeNumber = this.textContent.trim();

        const series = seriesData.find(s => s.id === seriesId);
        if (series) {
            document.getElementById('edit-series-id').value = seriesId;
            document.getElementById('edit-volume-index').value = volumeIndex;
            document.getElementById('edit-volume-number-display').textContent = `Tome ${volumeNumber}`;

            const volume = series.volumes[volumeIndex];
            document.querySelector('#edit-volume-modal [name="status"]').value = volume.status;
            document.querySelector('#edit-volume-modal [name="is_collector"]').checked = !!volume.collector;
            document.querySelector('#edit-volume-modal [name="is_last"]').checked = !!volume.last;

            modals['edit-volume'].modal.classList.add('modal-active');
        }
    });
});

// Gestion de la suppression d'un tome
document.getElementById('delete-volume-btn').addEventListener('click', function() {
    showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer ce tome ?').then((confirmed) => {
    if (confirmed) {
        const seriesId = document.getElementById('edit-series-id').value;
        const volumeIndex = document.getElementById('edit-volume-index').value;

        fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: `delete_volume=true&series_id=${encodeURIComponent(seriesId)}&volume_index=${volumeIndex}`
        })
        .then(response => {
            window.location.reload();
        })
        .catch(error => {
            alert('Une erreur est survenue: ' + error.message);
        });
    }});
});

// Gestion du bouton "Modifier" une série
document.querySelectorAll('.edit-series-btn').forEach(button => {
    button.addEventListener('click', function() {
        const seriesId = this.dataset.seriesId;
        const series = seriesData.find(s => s.id === seriesId);

        if (series) {
            document.getElementById('edit-series-id-input').value = seriesId;
            document.getElementById('edit-series-name').value = series.name;
            document.getElementById('edit-series-author').value = series.author;
            document.getElementById('edit-series-publisher').value = series.publisher;
            document.getElementById('edit-series-categories').value = series.categories ? series.categories.join(', ') : '';
            document.getElementById('edit-series-genres').value = series.genres ? series.genres.join(', ') : '';
            document.getElementById('edit-series-anilist-id').value = series.anilist_id || '';
            document.getElementById('edit-series-mature').checked = series.mature || false;
            document.getElementById('edit-series-favorite').checked = series.favorite || false;
            document.getElementById('current-series-image').src = series.image;

            modals['edit-series'].modal.classList.add('modal-active');
        }
    });
});

// Gestion de la suppression d'une série
document.querySelectorAll('.delete-series-btn').forEach(button => {
    button.addEventListener('click', function() {
        showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer cette série ? ').then((confirmed) => {
    if (confirmed) {
            const seriesId = this.dataset.seriesId;
            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `delete_series=true&series_id=${encodeURIComponent(seriesId)}`
            })
            .then(response => response.text())
            .then(() => {
                window.location.reload();
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Une erreur est survenue lors de la suppression.');
            });
        }
    });
});
});

// Masquer le message d'erreur après 3 secondes
setTimeout(function() {
    var errorMessage = document.getElementById('error-message');
    if (errorMessage) {
        errorMessage.style.display = 'none';
    }
}, 3000);

// Ajouter une série à la liste d'envies
document.getElementById('add-to-wishlist-btn').addEventListener('click', function() {
    const name = document.getElementById('wishlist-name').value;
    const author = document.getElementById('wishlist-author').value;
    const publisher = document.getElementById('wishlist-publisher').value;

    if (name && author && publisher) {
        fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `add_to_wishlist=true&wishlist_name=${encodeURIComponent(name)}&wishlist_author=${encodeURIComponent(author)}&wishlist_publisher=${encodeURIComponent(publisher)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Vider les champs après l'ajout réussi
                document.getElementById('wishlist-name').value = '';
                document.getElementById('wishlist-author').value = '';
                document.getElementById('wishlist-publisher').value = '';

                updateWishlist(data.wishlist);
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Une erreur est survenue lors de l\'ajout à la liste d\'envies.');
        });
    } else {
        alert('Veuillez remplir tous les champs.');
    }
});

// Supprimer une série de la liste d'envies
document.querySelectorAll('.remove-from-wishlist-btn').forEach(button => {
    button.addEventListener('click', function() {
        const index = this.dataset.index;
        fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `remove_from_wishlist=true&index=${index}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateWishlist(data.wishlist);
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Une erreur est survenue lors de la suppression de la liste d\'envies.');
        });
    });
});

// Ajouter une série de la liste d'envies à la collection principale
document.querySelectorAll('.add-from-wishlist-btn').forEach(button => {
    button.addEventListener('click', function() {
        const index = this.dataset.index;
        const item = wishlistData[index];

        // Fermer la modale de la liste d'envies
        modals['wishlist'].modal.classList.remove('modal-active');

        // Ouvrir la modale d'ajout de série et préremplir les champs
        document.getElementById('add-series-name').value = item.name;
        document.getElementById('add-series-author').value = item.author;
        document.getElementById('add-series-publisher').value = item.publisher;
        modals['add-series'].modal.classList.add('modal-active');

        // Supprimer la série de la liste d'envies
        fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `remove_from_wishlist=true&index=${index}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateWishlist(data.wishlist);
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Une erreur est survenue lors de l\'ajout de la série à votre collection.');
        });
    });
});

// Mettre à jour la liste d'envies dans le DOM
function updateWishlist(wishlist) {
    const wishlistList = document.getElementById('wishlist-list');
    wishlistList.innerHTML = '';

    wishlist.forEach((item, index) => {
        const wishlistItem = document.createElement('div');
        wishlistItem.className = 'wishlist-item';
        wishlistItem.dataset.index = index;
        wishlistItem.innerHTML = `
            <span class="wishlist-series-name">${item.name}</span>
            <span class="wishlist-series-author">${item.author}</span>
            <span class="wishlist-series-publisher">${item.publisher}</span>
            <div class="wishlist-item-actions">
                <button class="add-from-wishlist-btn" data-index="${index}">+</button>
                <button class="remove-from-wishlist-btn" data-index="${index}">x</button>
            </div>
        `;
        wishlistList.appendChild(wishlistItem);
    });

    // Réattacher les événements aux nouveaux boutons
    document.querySelectorAll('.add-from-wishlist-btn').forEach(button => {
        button.addEventListener('click', function() {
            const index = this.dataset.index;
            const item = wishlist[index];

            // Fermer la modale de la liste d'envies
            modals['wishlist'].modal.classList.remove('modal-active');

            // Ouvrir la modale d'ajout de série et préremplir les champs
            document.getElementById('add-series-name').value = item.name;
            document.getElementById('add-series-author').value = item.author;
            document.getElementById('add-series-publisher').value = item.publisher;
            modals['add-series'].modal.classList.add('modal-active');

            // Supprimer la série de la liste d'envies
            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `remove_from_wishlist=true&index=${index}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateWishlist(data.wishlist);
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Une erreur est survenue lors de l\'ajout de la série à votre collection.');
            });
        });
    });

    document.querySelectorAll('.remove-from-wishlist-btn').forEach(button => {
        button.addEventListener('click', function() {
            const index = this.dataset.index;
            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `remove_from_wishlist=true&index=${index}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateWishlist(data.wishlist);
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Une erreur est survenue lors de la suppression de la liste d\'envies.');
            });
        });
    });
}

// Autocomplétion pour les champs
function setupAutocomplete(inputId, field) {
    const input = document.getElementById(inputId);
    if (!input) return;

    // Créer un conteneur pour les suggestions
    const container = document.createElement('div');
    container.className = 'autocomplete-container';
    input.parentNode.insertBefore(container, input);
    container.appendChild(input);

    // Créer la liste déroulante
    const suggestionsList = document.createElement('div');
    suggestionsList.className = 'autocomplete-suggestions';
    container.appendChild(suggestionsList);

    // Gérer la saisie
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

    // Masquer les suggestions si on clique ailleurs
    document.addEventListener('click', (e) => {
        if (e.target !== input) {
            suggestionsList.style.display = 'none';
        }
    });
}

// Initialiser l'autocomplétion pour les champs concernés
setupAutocomplete('add-series-author', 'author');
setupAutocomplete('add-series-publisher', 'publisher');
setupAutocomplete('edit-series-author', 'author');
setupAutocomplete('edit-series-publisher', 'publisher');
setupAutocomplete('wishlist-author', 'author');
setupAutocomplete('wishlist-publisher', 'publisher');

// Pour les champs "catégories" et "genres", il faut gérer les valeurs séparées par des virgules
function setupMultiAutocomplete(inputId, field) {
    const input = document.getElementById(inputId);
    if (!input) {
        console.error(`Input avec l'ID ${inputId} non trouvé.`);
        return;
    }

    // Créer un conteneur pour les suggestions
    const container = document.createElement('div');
    container.className = 'autocomplete-container';
    input.parentNode.insertBefore(container, input);
    container.appendChild(input);

    // Créer la liste déroulante
    const suggestionsList = document.createElement('div');
    suggestionsList.className = 'autocomplete-suggestions';
    container.appendChild(suggestionsList);

    // Fonction pour extraire le dernier terme
    function getLastTerm(value) {
        const parts = value.split(',').map(part => part.trim());
        const lastPart = parts[parts.length - 1];
        return lastPart;
    }

    // Gérer la saisie
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
                            // Remplacer le dernier terme par la suggestion cliquée
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

    // Masquer les suggestions si on clique ailleurs
    document.addEventListener('click', (e) => {
        if (e.target !== input) {
            suggestionsList.style.display = 'none';
        }
    });
}

// Initialiser pour les champs multiples
setupMultiAutocomplete('add-series-categories', 'categories');
setupMultiAutocomplete('add-series-genres', 'genres');
setupMultiAutocomplete('edit-series-categories', 'categories');
setupMultiAutocomplete('edit-series-genres', 'genres');

// Ouverture de la modale "Livres prêtés"
document.getElementById('open-loan-modal').addEventListener('click', () => {
    modals['loan'] = { modal: document.getElementById('loan-modal'), closeBtn: document.getElementById('close-loan-modal') };
    modals['loan'].modal.classList.add('modal-active');
    loadLoanList();
});

// Fermeture de la modale "Livres prêtés"
document.getElementById('close-loan-modal').addEventListener('click', () => {
    modals['loan'].modal.classList.remove('modal-active');
});

// Recherche de série pour les prêts
setupSeriesSearch('loan-series-search', 'loan-series-results');
setupSeriesSearch('multiple-loan-series-search', 'multiple-loan-series-results');

// Autocomplétion pour les prêts
setupAutocomplete('loan-series-search', 'series');
setupAutocomplete('multiple-loan-series-search', 'series');

// Sélection de série pour les prêts
setupSeriesSelection('loan-series-results', 'loan-selected-series-id', 'loan-series-search');
setupSeriesSelection('multiple-loan-series-results', 'multiple-loan-selected-series-id', 'multiple-loan-series-search');

// Afficher les résultats au focus du champ de recherche
document.getElementById('loan-series-search').addEventListener('focus', function() {
    document.getElementById('loan-series-results').style.display = 'block';
});

document.getElementById('multiple-loan-series-search').addEventListener('focus', function() {
    document.getElementById('multiple-loan-series-results').style.display = 'block';
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('#loan-series-search') && !e.target.closest('#loan-series-results')) {
        document.getElementById('loan-series-results').style.display = 'none';
    }
    if (!e.target.closest('#multiple-loan-series-search') && !e.target.closest('#multiple-loan-series-results')) {
        document.getElementById('multiple-loan-series-results').style.display = 'none';
    }
});

// Ajouter un prêt (un seul tome)
document.getElementById('add-single-loan-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('loan_action', 'add_single_loan');

    fetch('admin.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Prêt ajouté avec succès !');
            this.reset();
            loadLoanList();
        } else {
            let errorMessage = 'Une erreur est survenue.';
            if (data.message) {
                errorMessage = data.message;
            }
            alert(errorMessage);
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        alert('Une erreur est survenue.');
    });
});

// Ajouter un prêt (plusieurs tomes)
document.getElementById('add-multiple-loans-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('loan_action', 'add_multiple_loans');

    fetch('admin.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Prêts ajoutés avec succès !');
            this.reset();
            loadLoanList();
        } else {
            let errorMessage = 'Une erreur est survenue.';
            if (data.message) {
                errorMessage = data.message;
            }
            alert(errorMessage);
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        alert('Une erreur est survenue.');
    });
});

// Charger la liste des prêts
function loadLoanList() {
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'loan_action=get_loans'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayLoanList(data.loans);
        } else {
            console.error('Erreur lors du chargement des prêts.');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
    });
}

// Afficher la liste des prêts
function displayLoanList(loans) {
    const loanListDiv = document.getElementById('loan-list');
    loanListDiv.innerHTML = '';

    if (loans.length === 0) {
        loanListDiv.innerHTML = '<p>Aucun livre prêté.</p>';
        return;
    }

    loans.forEach(item => {
        const series = item.series;
        const loans = item.loans;
        const seriesExists = item.series_exists;

        const seriesDiv = document.createElement('div');
        seriesDiv.className = 'loan-series-item';

        let html = '';

        if (!seriesExists) {
            html += `
                <h4 style="color: #cf6679;">Série supprimée de la base</h4>
                <p>Cette série a été supprimée de votre base, mais des prêts sont encore enregistrés.</p>
            `;
        } else {
            html += `
                <h4>${series.name}</h4>
                <p><strong>Auteur :</strong> ${series.author}</p>
            `;
        }

        html += `
            <p><strong>Tomes prêtés :</strong></p>
            <ul class="loan-volumes-list">
        `;

        loans.forEach(loan => {
            html += `
                <li>
                    Tome ${loan.volume_number} (à ${loan.borrower_name})
                    <button class="remove-loan-btn" data-series-id="${loan.series_id}" data-volume-number="${loan.volume_number}">Retirer</button>
                </li>
            `;
        });

        html += `</ul>`;

        // Ajout du bouton "Tout retirer" si plusieurs prêts
        if (loans.length > 1) {
            html += `
                <button class="remove-all-loans-btn" data-series-id="${loans[0].series_id}">
                    Tout retirer
                </button>
            `;
        }

        seriesDiv.innerHTML = html;
        loanListDiv.appendChild(seriesDiv);
    });

    // Ajouter les événements aux boutons de suppression
    document.querySelectorAll('.remove-loan-btn').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            const volumeNumber = this.dataset.volumeNumber;

            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer ce tome ?').then((confirmed) => {
            if (confirmed) {
                fetch('admin.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `loan_action=remove_loan&series_id=${seriesId}&volume_number=${volumeNumber}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadLoanList();
                    } else {
                        alert('Une erreur est survenue.');
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Une erreur est survenue.');
                });
            }
        });
    });
    });

    // Ajouter les événements aux boutons "Tout retirer"
    document.querySelectorAll('.remove-all-loans-btn').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;

            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer tous les prêts de cette série ?').then((confirmed) => {
            if (confirmed) {
                fetch('admin.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `loan_action=remove_all_loans&series_id=${seriesId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadLoanList();
                    } else {
                        alert('Une erreur est survenue.');
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Une erreur est survenue.');
                });
            }
        });
    });
});
}

// Fonction pour afficher une alerte personnalisée
function showCustomAlert(title, message) {
    const modal = document.getElementById('custom-alert-modal');
    const titleElement = document.getElementById('custom-alert-title');
    const messageElement = document.getElementById('custom-alert-message');
    const okButton = document.getElementById('custom-alert-ok');

    titleElement.textContent = title;
    messageElement.textContent = message;
    modal.classList.add('modal-active');

    return new Promise((resolve) => {
        okButton.onclick = () => {
            modal.classList.remove('modal-active');
            resolve();
        };
    });
}

// Fonction pour afficher une confirmation personnalisée
function showCustomConfirm(title, message) {
    const modal = document.getElementById('custom-confirm-modal');
    const titleElement = document.getElementById('custom-confirm-title');
    const messageElement = document.getElementById('custom-confirm-message');
    const okButton = document.getElementById('custom-confirm-ok');
    const cancelButton = document.getElementById('custom-confirm-cancel');

    titleElement.textContent = title;
    messageElement.textContent = message;
    modal.classList.add('modal-active');

    return new Promise((resolve) => {
        okButton.onclick = () => {
            modal.classList.remove('modal-active');
            resolve(true);
        };
        cancelButton.onclick = () => {
            modal.classList.remove('modal-active');
            resolve(false);
        };
    });
}

// Remplacer les alert/confirm natifs
window.alert = function(message) {
    showCustomAlert('Avertissement', message);
};

window.confirm = function(message) {
    return showCustomConfirm('Confirmation', message);
};

// Afficher un message d'erreur dans une modale
function showErrorModal(message) {
    showCustomAlert('Erreur', message);
}

// Afficher un message de succès dans une modale
function showSuccessModal(message) {
    showCustomAlert('Succès', message);
}

// Bouton "Retour en haut"
window.addEventListener('scroll', function() {
    const backToTop = document.getElementById('back-to-top');
    if (window.pageYOffset > 300) {
        backToTop.style.display = 'block';
    } else {
        backToTop.style.display = 'none';
    }
});
document.getElementById('back-to-top').addEventListener('click', function() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
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
    const searchTerm = urlParams.get('search') || '';
    const sortBy = urlParams.get('sort_by') || 'name';
    const sortOrder = urlParams.get('sort_order') || 'asc';

    // Ajoute les paramètres à la requête AJAX
    fetch(`admin.php?get_paginated_series=true&page=${currentPage + 1}&per_page=9&search=${encodeURIComponent(searchTerm)}&sort_by=${sortBy}&sort_order=${sortOrder}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.series && data.series.length > 0) {
                const seriesList = document.getElementById('series-list');
                data.series.forEach(series => {
                    // Crée la carte de la série
                    const seriesCard = document.createElement('div');
                    seriesCard.className = 'series-card';

                    // Génère le HTML pour la série
                    let volumesHTML = '';
                    series.volumes.forEach((volume, volume_index) => {
                        volumesHTML += `
                            <li class="status-${volume.status.replace(' ', '-')}
                                ${volume.collector ? ' volume-collector' : ''}
                                ${volume.last ? ' volume-last' : ''}"
                                data-series-id="${series.id}"
                                data-volume-index="${volume_index}">
                                ${volume.number}
                            </li>
                        `;
                    });

                    // Génère les notifications (tomes manquants, etc.)
                    let notificationsHTML = '';
                    if (series.notifications && series.notifications.length > 0) {
                        notificationsHTML = `
                            <div class="issues-list">
                                <span class="warning-icon">⚠️</span>
                                <span class="issues-text">${series.notifications.join(' ')}</span>
                            </div>
                        `;
                    }

                    // Remplit la carte avec le HTML
                    seriesCard.innerHTML = `
                        <img class="series-image" src="${series.image}" alt="${series.name}" loading="lazy">
                        <div class="series-info">
                            <div class="series-header">
                                <h2>${series.name}</h2>
                                <div class="series-actions">
                                    <button class="edit-series-btn" data-series-id="${series.id}">Modifier</button>
                                    <button class="delete-series-btn" data-series-id="${series.id}">Supprimer</button>
                                </div>
                            </div>
                            <p><strong>Auteur :</strong> ${series.author}</p>
                            <p><strong>Éditeur :</strong> ${series.publisher}</p>
                            <p><strong>Catégories :</strong> ${series.categories ? series.categories.join(', ') : ''}</p>
                            <p><strong>Genres :</strong> ${series.genres ? series.genres.join(', ') : ''}</p>
                            <p><strong>ID Anilist :</strong>
                                ${series.anilist_id ?
                                    `<a href="https://anilist.co/manga/${series.anilist_id}" target="_blank">${series.anilist_id}</a>` :
                                    'Non défini'}
                            </p>
                            <p><strong>Tomes :</strong> ${series.volumes.length}</p>
                            ${series.mature ? '<span class="mature-badge">🔞 Mature</span>' : ''}
                            <h3>Liste des tomes :</h3>
                            ${notificationsHTML}
                            <ul class="volumes-list">${volumesHTML}</ul>
                        </div>
                    `;

                    // Ajoute la carte à la liste
                    seriesList.appendChild(seriesCard);
                });

                // Met à jour la page et le statut
                currentPage++;
                hasMoreSeries = data.has_more;

                // Réattache les événements aux nouveaux éléments
                attachSeriesEvents();
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

// Fonction pour réattacher les événements aux nouvelles séries chargées
function attachSeriesEvents() {
    // Événements pour les boutons "Modifier" et "Supprimer"
    document.querySelectorAll('.edit-series-btn').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            const series = seriesData.find(s => s.id === seriesId);
            if (series) {
                document.getElementById('edit-series-id-input').value = seriesId;
                document.getElementById('edit-series-name').value = series.name;
                document.getElementById('edit-series-author').value = series.author;
                document.getElementById('edit-series-publisher').value = series.publisher;
                document.getElementById('edit-series-categories').value = series.categories ? series.categories.join(', ') : '';
                document.getElementById('edit-series-genres').value = series.genres ? series.genres.join(', ') : '';
                document.getElementById('edit-series-anilist-id').value = series.anilist_id || '';
                document.getElementById('edit-series-mature').checked = series.mature || false;
                document.getElementById('current-series-image').src = series.image;
                modals['edit-series'].modal.classList.add('modal-active');
            }
        });
    });

    document.querySelectorAll('.delete-series-btn').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer cette série ?').then((confirmed) => {
                if (confirmed) {
                    fetch('admin.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `delete_series=true&series_id=${encodeURIComponent(seriesId)}`
                    })
                    .then(() => window.location.reload())
                    .catch(error => alert('Erreur : ' + error.message));
                }
            });
        });
    });

    // Événements pour les tomes (clic pour modifier)
    document.querySelectorAll('.volumes-list li').forEach(li => {
        li.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            const volumeIndex = this.dataset.volumeIndex;
            const series = seriesData.find(s => s.id === seriesId);
            if (series && series.volumes[volumeIndex]) {
                const volume = series.volumes[volumeIndex];
                document.getElementById('edit-series-id').value = seriesId;
                document.getElementById('edit-volume-index').value = volumeIndex;
                document.getElementById('edit-volume-number-display').textContent = `Tome ${volume.number}`;
                document.querySelector('#edit-volume-modal [name="status"]').value = volume.status;
                document.querySelector('#edit-volume-modal [name="is_collector"]').checked = !!volume.collector;
                document.querySelector('#edit-volume-modal [name="is_last"]').checked = !!volume.last;
                modals['edit-volume'].modal.classList.add('modal-active');
            }
        });
    });
}

// Écouteur de scroll pour déclencher le chargement
window.addEventListener('scroll', () => {
    const { scrollTop, scrollHeight, clientHeight } = document.documentElement;
    if (scrollTop + clientHeight >= scrollHeight - 200 && !isLoading && hasMoreSeries) {
        loadMoreSeries();
    }
});

// Initialisation au chargement de la page
document.addEventListener('DOMContentLoaded', () => {
    attachSeriesEvents(); // Attache les événements aux séries initiales
});

// Écouteur pour le formulaire de recherche/filtre
document.querySelector('.filters form').addEventListener('submit', function(e) {
    e.preventDefault(); // Empêche la soumission normale du formulaire

    // Réinitialise la pagination et vide la liste
    currentPage = 1;
    hasMoreSeries = true;
    document.getElementById('series-list').innerHTML = '<p>Chargement des résultats...</p>';

    // Soumet le formulaire via AJAX pour obtenir les résultats filtrés
    const formData = new FormData(this);
    const searchParams = new URLSearchParams(formData).toString();

    fetch(`admin.php?${searchParams}`)
        .then(response => response.text())
        .then(html => {
            // Remplace le contenu de la liste des séries par les résultats filtrés
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newSeriesList = doc.getElementById('series-list');
            if (newSeriesList) {
                document.getElementById('series-list').innerHTML = newSeriesList.innerHTML;
                attachSeriesEvents(); // Réattache les événements
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            document.getElementById('series-list').innerHTML = '<p>Erreur lors du chargement des résultats.</p>';
        });

    // Soumet le formulaire normalement après avoir chargé les résultats initiaux
    this.submit();
});

// Écouteur pour le formulaire d'ajout de série
document.querySelector('form[enctype="multipart/form-data"]').addEventListener('submit', function(e) {
    const fileInput = this.querySelector('input[type="file"]');
    if (fileInput.files.length > 0) {
        const file = fileInput.files[0];
        const maxSize = 5 * 1024 * 1024; // 5 Mo

        if (file.size > maxSize) {
            e.preventDefault();
            alert("Le fichier est trop volumineux (max. 5 Mo).");
        }
    }
});

// Ouverture de la modale "Outils"
document.getElementById('open-tools-modal').addEventListener('click', () => {
    modals['tools'] = { modal: document.getElementById('tools-modal'), closeBtn: document.getElementById('close-tools-modal') };
    modals['tools'].modal.classList.add('modal-active');
    loadBackupsList();
});

// Fermeture de la modale "Outils"
document.getElementById('close-tools-modal').addEventListener('click', () => {
    modals['tools'].modal.classList.remove('modal-active');
});

// Création d'une sauvegarde
document.getElementById('create-backup-btn').addEventListener('click', () => {
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'backup_action=create_backup'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            loadBackupsList();
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        alert('Une erreur est survenue.');
    });
});

// Charger la liste des sauvegardes
function loadBackupsList() {
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'backup_action=list_backups'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayBackupsList(data.backups);
        } else {
            console.error('Erreur lors du chargement des sauvegardes.');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
    });
}

// Afficher la liste des sauvegardes
function displayBackupsList(backups) {
    const backupsListDiv = document.getElementById('backups-list');
    backupsListDiv.innerHTML = '';

    if (backups.length === 0) {
        backupsListDiv.innerHTML = '<p>Aucune sauvegarde disponible.</p>';
        return;
    }

    backups.forEach(backup => {
        const backupDiv = document.createElement('div');
        backupDiv.className = 'backup-item';
        backupDiv.innerHTML = `
            <p><strong>${backup.name}</strong> (${backup.date})</p>
            <div class="backup-actions">
                <a href="saves/${backup.name}" download class="button button-opt">Télécharger</a>
                <button class="delete-backup-btn" data-backup-file="${backup.name}">Supprimer</button>
            </div>
        `;
        backupsListDiv.appendChild(backupDiv);
    });

    // Ajouter les événements aux boutons de suppression
    document.querySelectorAll('.delete-backup-btn').forEach(button => {
        button.addEventListener('click', function() {
            const backupFile = this.dataset.backupFile;
            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer cette sauvegarde ?').then((confirmed) => {
                if (confirmed) {
                    fetch('admin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `backup_action=delete_backup&backup_file=${encodeURIComponent(backupFile)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            loadBackupsList();
                        } else {
                            alert(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        alert('Une erreur est survenue.');
                    });
                }
            });
        });
    });
}