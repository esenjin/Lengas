// Gestion des modales
const modals = {
    'add-series': { modal: document.getElementById('add-series-modal'), closeBtn: document.getElementById('close-add-series-modal') },
    'add-volume': { modal: document.getElementById('add-volume-modal'), closeBtn: document.getElementById('close-add-volume-modal') },
    'add-multiple-volumes': { modal: document.getElementById('add-multiple-volumes-modal'), closeBtn: document.getElementById('close-add-multiple-volumes-modal') },
    'edit-volume': { modal: document.getElementById('edit-volume-modal'), closeBtn: document.getElementById('close-edit-volume-modal') },
    'edit-series': { modal: document.getElementById('edit-series-modal'), closeBtn: document.getElementById('close-edit-series-modal') },
    'wishlist': { modal: document.getElementById('wishlist-modal'), closeBtn: document.getElementById('close-wishlist-modal') }
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

// Fermeture des modales via la croix
Object.values(modals).forEach(({ closeBtn, modal }) => {
    if (closeBtn && modal) {
        closeBtn.addEventListener('click', () => {
            modal.classList.remove('modal-active');
        });
    }
});

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
function setupSeriesSelection(resultsId, inputId) {
    document.querySelectorAll(`#${resultsId} div`).forEach(div => {
        div.addEventListener('click', function() {
            const seriesId = this.dataset.id;
            document.getElementById(inputId).value = seriesId;
            this.parentElement.previousElementSibling.value = this.textContent;
            this.parentElement.style.display = 'none';
        });
    });
}

setupSeriesSelection('series-results', 'selected-series-id');
setupSeriesSelection('multiple-series-results', 'multiple-selected-series-id');

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
    if (confirm('Êtes-vous sûr de vouloir supprimer ce tome ?')) {
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
    }
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
            document.getElementById('current-series-image').src = series.image;

            modals['edit-series'].modal.classList.add('modal-active');
        }
    });
});

// Gestion de la suppression d'une série
document.querySelectorAll('.delete-series-btn').forEach(button => {
    button.addEventListener('click', function() {
        if (confirm('Êtes-vous sûr de vouloir supprimer cette série ? Cette action est irréversible.')) {
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

// Fermeture des modales en cliquant à l'extérieur
window.addEventListener('click', (e) => {
    Object.values(modals).forEach(({ modal }) => {
        if (e.target === modal) {
            modal.classList.remove('modal-active');
        }
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