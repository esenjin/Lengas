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

        modals['wishlist'].modal.classList.remove('modal-active');
        document.getElementById('add-series-name').value = item.name;
        document.getElementById('add-series-author').value = item.author;
        document.getElementById('add-series-publisher').value = item.publisher;
        modals['add-series'].modal.classList.add('modal-active');

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

// Éditer une série de la liste d'envies
document.querySelectorAll('.edit-wishlist-btn').forEach(button => {
    button.addEventListener('click', function() {
        const index = this.dataset.index;
        const item = wishlistData[index];
        document.getElementById('edit-wishlist-index').value = index;
        document.getElementById('edit-wishlist-name').value = item.name;
        document.getElementById('edit-wishlist-author').value = item.author;
        document.getElementById('edit-wishlist-publisher').value = item.publisher;
        document.getElementById('edit-wishlist-modal').classList.add('modal-active');
    });
});

document.getElementById('edit-wishlist-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const index = document.getElementById('edit-wishlist-index').value;
    const name = document.getElementById('edit-wishlist-name').value;
    const author = document.getElementById('edit-wishlist-author').value;
    const publisher = document.getElementById('edit-wishlist-publisher').value;

    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `edit_wishlist=true&index=${index}&name=${encodeURIComponent(name)}&author=${encodeURIComponent(author)}&publisher=${encodeURIComponent(publisher)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateWishlist(data.wishlist);
            document.getElementById('edit-wishlist-modal').classList.remove('modal-active');
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        alert('Une erreur est survenue lors de la mise à jour.');
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
                <button class="edit-wishlist-btn" data-index="${index}">...</button>
                <button class="remove-from-wishlist-btn" data-index="${index}">x</button>
            </div>
        `;
        wishlistList.appendChild(wishlistItem);
    });

    // Réattacher les écouteurs pour les boutons "Éditer"
    document.querySelectorAll('.edit-wishlist-btn').forEach(button => {
        button.addEventListener('click', function() {
            const index = this.dataset.index;
            const item = wishlist[index];
            document.getElementById('edit-wishlist-index').value = index;
            document.getElementById('edit-wishlist-name').value = item.name;
            document.getElementById('edit-wishlist-author').value = item.author;
            document.getElementById('edit-wishlist-publisher').value = item.publisher;
            document.getElementById('edit-wishlist-modal').classList.add('modal-active');
        });
    });

    // Réattacher les événements aux boutons "Ajouter" et "Supprimer"
    document.querySelectorAll('.add-from-wishlist-btn').forEach(button => {
        button.addEventListener('click', function() {
            const index = this.dataset.index;
            const item = wishlist[index];

            modals['wishlist'].modal.classList.remove('modal-active');
            document.getElementById('add-series-name').value = item.name;
            document.getElementById('add-series-author').value = item.author;
            document.getElementById('add-series-publisher').value = item.publisher;
            modals['add-series'].modal.classList.add('modal-active');

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