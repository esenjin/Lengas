let readData = [];

// Charger les séries "lues ailleurs"
function loadRead() {
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_read'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Erreur réseau : ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.success && Array.isArray(data.read)) {
            readData = data.read;
            updateReadList(data.read);
        } else {
            console.error('Erreur : données invalides reçues du serveur.', data);
            updateReadList([]);
        }
    })
    .catch(error => {
        console.error('Erreur lors du chargement des données :', error);
        updateReadList([]);
    });
}

// Ajouter une série à "lues ailleurs"
document.getElementById('add-to-read-btn').addEventListener('click', function() {
    const name = document.getElementById('read-name').value;
    const author = document.getElementById('read-author').value;
    const publisher = document.getElementById('read-publisher').value;
    const volumes_read = document.getElementById('read-volumes').value;
    const status = document.getElementById('read-status').value;

    if (name && author && publisher && volumes_read) {
        fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `add_to_read=true&read_name=${encodeURIComponent(name)}&read_author=${encodeURIComponent(author)}&read_publisher=${encodeURIComponent(publisher)}&read_volumes=${volumes_read}&read_status=${status}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('read-name').value = '';
                document.getElementById('read-author').value = '';
                document.getElementById('read-publisher').value = '';
                document.getElementById('read-volumes').value = '';
                updateReadList(data.read);
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Une erreur est survenue.');
        });
    } else {
        alert('Veuillez remplir tous les champs.');
    }
});

// Mettre à jour la liste "lues ailleurs" dans le DOM
function updateReadList(read) {
    const readList = document.getElementById('read-list');
    readList.innerHTML = '';

    read.forEach((item, index) => {
        if (!item) return;

        const readItem = document.createElement('div');
        readItem.className = 'read-item';
        readItem.dataset.index = index;
        readItem.innerHTML = `
            <div class="read-item-content">
                <div class="read-item-line read-item-line-top">
                    <span class="read-series-name">${item.name || ''}</span>
                    <span class="read-series-author">${item.author || ''}</span>
                    <span class="read-series-publisher">${item.publisher || ''}</span>
                </div>

                <div class="read-item-line read-item-line-bottom">
                    <span class="read-series-volumes">${item.volumes_read || 0} tomes lus</span>&nbsp;-&nbsp;
                    <span class="read-series-status">${item.status || 'inconnu'}</span>
                </div>
            </div>

            <div class="read-item-actions">
                <button class="add-from-read-btn" data-index="${index}">+</button>
                <button class="edit-read-btn" data-index="${index}">...</button>
                <button class="remove-from-read-btn" data-index="${index}">x</button>
            </div>
        `;
        readList.appendChild(readItem);
    });

    // Réattacher les écouteurs pour les boutons
    document.querySelectorAll('.add-from-read-btn').forEach(button => {
    button.addEventListener('click', function() {
        const index = this.dataset.index;
        const item = readData[index];
        if (!item) return;

        // Appeler l'action pour supprimer la série de "Lues ailleurs" après ajout
        fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `add_from_read=true&index=${index}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateReadList(data.read);
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Une erreur est survenue.');
        });
    });
});

    // Écouteurs pour les boutons "Éditer"
    document.querySelectorAll('.edit-read-btn').forEach(button => {
        button.addEventListener('click', function() {
            const index = this.dataset.index;
            const item = read[index]; // Utiliser le tableau 'read' passé en paramètre
            if (!item) return;

            document.getElementById('edit-read-index').value = index;
            document.getElementById('edit-read-name').value = item.name;
            document.getElementById('edit-read-author').value = item.author;
            document.getElementById('edit-read-publisher').value = item.publisher;
            document.getElementById('edit-read-volumes').value = item.volumes_read;
            document.getElementById('edit-read-status').value = item.status;
            document.getElementById('edit-read-modal').classList.add('modal-active');
        });
    });

    // Écouteurs pour les boutons "Supprimer"
    document.querySelectorAll('.remove-from-read-btn').forEach(button => {
        button.addEventListener('click', function() {
            const index = this.dataset.index;
            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `remove_from_read=true&index=${index}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateReadList(data.read);
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Une erreur est survenue.');
            });
        });
    });
}

// Éditer une série de "lues ailleurs"
document.getElementById('edit-read-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const index = document.getElementById('edit-read-index').value;
    const name = document.getElementById('edit-read-name').value;
    const author = document.getElementById('edit-read-author').value;
    const publisher = document.getElementById('edit-read-publisher').value;
    const volumes_read = document.getElementById('edit-read-volumes').value;
    const status = document.getElementById('edit-read-status').value;

    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `edit_read=true&index=${index}&name=${encodeURIComponent(name)}&author=${encodeURIComponent(author)}&publisher=${encodeURIComponent(publisher)}&volumes_read=${volumes_read}&status=${status}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateReadList(data.read);
            document.getElementById('edit-read-modal').classList.remove('modal-active');
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        alert('Une erreur est survenue.');
    });
});

// Écouteur pour le champ de recherche
document.getElementById('read-search').addEventListener('input', function() {
    const searchTerm = normalizeString(this.value);
    const readItems = document.querySelectorAll('.read-item');

    readItems.forEach(item => {
        const name = normalizeString(item.querySelector('.read-series-name').textContent);
        const author = normalizeString(item.querySelector('.read-series-author').textContent);
        const publisher = normalizeString(item.querySelector('.read-series-publisher').textContent);

        if (name.includes(searchTerm) || author.includes(searchTerm) || publisher.includes(searchTerm)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
});

// Ouvrir la modale "Lues ailleurs"
document.getElementById('open-read-modal').addEventListener('click', () => {
    loadRead();
    modals['read'].modal.classList.add('modal-active');
});

// Fermer la modale "Lues ailleurs"
document.getElementById('close-read-modal').addEventListener('click', () => {
    modals['read'].modal.classList.remove('modal-active');
});

// Fermer la modale d'édition "Lues ailleurs"
document.getElementById('close-edit-read-modal').addEventListener('click', () => {
    modals['edit-read'].modal.classList.remove('modal-active');
});

// Ajouter la modale "Lues ailleurs" à l'objet modals
modals['read'] = { modal: document.getElementById('read-modal'), closeBtn: document.getElementById('close-read-modal') };
modals['edit-read'] = { modal: document.getElementById('edit-read-modal'), closeBtn: document.getElementById('close-edit-read-modal') };