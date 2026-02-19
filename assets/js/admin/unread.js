let unreadData = [];

// Charger les séries non lues
function loadUnread() {
    fetch('admin.php?action=get_unread_series')
        .then(response => {
            if (!response.ok) {
                throw new Error('Erreur réseau : ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && Array.isArray(data.unread_series)) {
                updateUnreadList(data.unread_series);
            } else {
                console.error('Erreur : données invalides.', data);
                updateUnreadList([]);
            }
        })
        .catch(error => {
            console.error('Erreur :', error);
            updateUnreadList([]);
        });
}

// Mettre à jour la liste des séries non lues dans le DOM
function updateUnreadList(unread_series) {
    const unreadList = document.getElementById('unread-list');
    unreadList.innerHTML = `
        <div class="unread-filters">
            <label>
                <input type="checkbox" id="filter-soon-finished">
                Séries bientôt finies (≤2 tomes) uniquement
            </label>
            <div>
                <label for="max-unread-count">Nombre max de tomes restants :</label>
                <input type="number" id="max-unread-count" min="1" placeholder="Ex: 4">
            </div>
        </div>
    `;

    if (unread_series.length === 0) {
        unreadList.innerHTML = '<p>Aucune série non terminée trouvée.</p>';
        return;
    }

    unread_series.forEach((item) => {
        const unreadItem = document.createElement('div');
        unreadItem.className = 'unread-item';
        unreadItem.innerHTML = `
            <div class="unread-item-content">
                <div class="unread-item-line unread-item-line-top">
                    <div class="unread-series-info">
                        <span class="unread-series-name">${item.name || 'Inconnu'}</span>&nbsp;|&nbsp;
                        <span class="unread-series-author">${item.author || 'Inconnu'}</span>&nbsp;|&nbsp;
                        <span class="unread-series-publisher">${item.publisher || 'Inconnu'}</span>
                    </div>
                    <div class="unread-series-actions">
                        ${item.soon_finished ? '<span class="soon-finished-badge">🔚</span>' : ''}
                        <button class="mark-as-read-btn" data-series-id="${item.id}">+</button>
                    </div>
                </div>
                <div class="unread-item-line unread-item-line-bottom">
                    <span class="unread-series-last-read">Dernier lu : ${item.last_read_volume || 'aucun'}</span>
                    <span class="unread-series-unread-count">${item.unread_count} tomes restants (sur ${item.total_volumes})</span>
                </div>
            </div>
        `;
        unreadList.appendChild(unreadItem);
    });

    // Ajoute un écouteur pour filtrer dynamiquement
    document.getElementById('filter-soon-finished').addEventListener('change', () => filterUnreadList(unread_series));
    document.getElementById('max-unread-count').addEventListener('input', () => filterUnreadList(unread_series));

    // Ajoute les écouteurs d'événements pour les boutons "+"
    document.querySelectorAll('.mark-as-read-btn').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.getAttribute('data-series-id');
            markFirstUnreadVolumeAsRead(seriesId);
        });
    });
}

// Filtrer la liste des séries non lues en fonction des critères sélectionnés
function filterUnreadList(unread_series) {
    const filterSoonFinished = document.getElementById('filter-soon-finished').checked;
    const maxUnreadCount = parseInt(document.getElementById('max-unread-count').value) || Infinity;

    const filteredSeries = unread_series.filter(series => {
        const matchesSoonFinished = !filterSoonFinished || series.soon_finished;
        const matchesMaxUnread = series.unread_count <= maxUnreadCount;
        return matchesSoonFinished && matchesMaxUnread;
    });

    renderFilteredUnreadList(filteredSeries);
}

// Rendre la liste filtrée des séries non lues dans le DOM
function renderFilteredUnreadList(filteredSeries) {
    const unreadList = document.getElementById('unread-list');
    // Garde le bloc des filtres
    const filters = unreadList.querySelector('.unread-filters');
    unreadList.innerHTML = '';
    unreadList.appendChild(filters);

    if (filteredSeries.length === 0) {
        unreadList.innerHTML += '<p>Aucune série ne correspond aux filtres.</p>';
        return;
    }

    filteredSeries.forEach((item) => {
        const unreadItem = document.createElement('div');
        unreadItem.className = 'unread-item';
        unreadItem.innerHTML = `
            <div class="unread-item-content">
                <div class="unread-item-line unread-item-line-top">
                    <div class="unread-series-info">
                        <span class="unread-series-name">${item.name || 'Inconnu'}</span>&nbsp;|&nbsp;
                        <span class="unread-series-author">${item.author || 'Inconnu'}</span>&nbsp;|&nbsp;
                        <span class="unread-series-publisher">${item.publisher || 'Inconnu'}</span>
                    </div>
                    <div class="unread-series-actions">
                        ${item.soon_finished ? '<span class="soon-finished-badge">🔚</span>' : ''}
                        <button class="mark-as-read-btn" data-series-id="${item.id}">+</button>
                    </div>
                </div>
                <div class="unread-item-line unread-item-line-bottom">
                    <span class="unread-series-last-read">Dernier lu : ${item.last_read_volume || 'aucun'}</span>
                    <span class="unread-series-unread-count">
                        ${item.unread_count} tomes restants (sur ${item.total_volumes}, ${item.status})
                    </span>
                </div>
            </div>
        `;
        unreadList.appendChild(unreadItem);
    });

    // Réattache les écouteurs
    document.querySelectorAll('.mark-as-read-btn').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.getAttribute('data-series-id');
            markFirstUnreadVolumeAsRead(seriesId);
        });
    });
}

// Marquer le premier tome non lu d'une série comme lu
function markFirstUnreadVolumeAsRead(seriesId) {
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=mark_first_unread_as_read&series_id=${seriesId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadUnread();
        } else {
            alert(data.message || 'Erreur lors de la mise à jour.');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        alert('Une erreur est survenue.');
    });
}

// Écouteur pour ouvrir la modale "À lire"
document.getElementById('open-unread-modal').addEventListener('click', () => {
    loadUnread();
    modals['unread'].modal.classList.add('modal-active');
});

// Écouteur pour fermer la modale "À lire"
document.getElementById('close-unread-modal').addEventListener('click', () => {
    modals['unread'].modal.classList.remove('modal-active');
});