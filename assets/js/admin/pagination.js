// Variables globales
let currentPage = 1;
let isLoading = false;
let hasMoreSeries = true;
const seriesList = document.getElementById('series-list');

// Fonction utilitaire pour "throttle"
function throttle(func, limit) {
    let lastFunc;
    let lastRan;
    return function() {
        const context = this;
        const args = arguments;
        if (!lastRan) {
            func.apply(context, args);
            lastRan = Date.now();
        } else {
            clearTimeout(lastFunc);
            lastFunc = setTimeout(function() {
                if ((Date.now() - lastRan) >= limit) {
                    func.apply(context, args);
                    lastRan = Date.now();
                }
            }, limit - (Date.now() - lastRan));
        }
    };
}

// Fonction pour charger les séries (mode light)
async function loadMoreSeries() {
    if (isLoading || !hasMoreSeries) return;
    isLoading = true;
    document.getElementById('loading-spinner').classList.add('active');

    try {
        const urlParams = new URLSearchParams(window.location.search);
        const searchTerm = urlParams.get('search') || '';
        const sortBy = urlParams.get('sort_by') || 'name';
        const sortOrder = urlParams.get('sort_order') || 'asc';

        const response = await fetch(
            `admin.php?get_paginated_series=true&page=${currentPage + 1}&per_page=9&light=true` +
            `&search=${encodeURIComponent(searchTerm)}` +
            `&sort_by=${sortBy}&sort_order=${sortOrder}`
        );
        const data = await response.json();

        if (data.success && data.series.length > 0) {
            data.series.forEach(series => {
                const seriesCard = createLightSeriesCard(series);
                seriesList.appendChild(seriesCard);
            });

            currentPage++;
            hasMoreSeries = data.has_more;
        } else {
            hasMoreSeries = false;
        }
    } catch (error) {
        console.error('Erreur:', error);
    } finally {
        isLoading = false;
        document.getElementById('loading-spinner').classList.remove('active');
    }
}

// Crée une carte de série allégée (sans tomes)
function createLightSeriesCard(series) {
    const seriesCard = document.createElement('div');
    seriesCard.className = 'series-card' + (series.favorite ? ' favorite' : '');
    seriesCard.dataset.seriesId = series.id;
    const imageSrc = series.image && series.image !== '' ? series.image : 'logo.png';
    seriesCard.innerHTML = `
        <img class="series-image" src="${imageSrc}" alt="${series.name}" loading="lazy">
        <div class="series-info">
            <div class="series-header">
                <h2>${series.name}</h2>
                <div class="series-actions">
                    <button class="edit-series-btn" data-series-id="${series.id}">Modifier</button>
                    <button class="delete-series-btn" data-series-id="${series.id}">Supprimer</button>
                    <button class="move-to-read-btn" data-series-id="${series.id}">Lues ailleurs</button>
                </div>
            </div>
            <p><strong>Auteur :</strong> ${series.author}</p>
            <p><strong>Éditeur :</strong> ${series.publisher}</p>
            <p><strong>Autres contributeurs :</strong> ${series.other_contributors ? series.other_contributors.join(', ') : ''}</p>
            <p><strong>Catégories :</strong> ${series.categories ? series.categories.join(', ') : ''}</p>
            <p><strong>Genres :</strong> ${series.genres ? series.genres.join(', ') : ''}</p>
            ${series.mature ? '<span class="mature-badge">🔞 Mature</span>' : ''}
            <button class="load-volumes-btn" data-series-id="${series.id}">Voir les tomes (${series.volumes_count})</button>
            <div class="volumes-container" data-series-id="${series.id}"></div>
        </div>
    `;
    return seriesCard;
}

// Charge les tomes d'une série
function loadSeriesVolumes(seriesId) {
    const container = document.querySelector(`.volumes-container[data-series-id="${seriesId}"]`);
    if (container.innerHTML) return;

    container.innerHTML = '<p class="loading-text">Chargement des tomes...</p>';
    fetch(`admin.php?get_series_volumes=true&series_id=${encodeURIComponent(seriesId)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                container.innerHTML = data.volumes_html;
            } else {
                container.innerHTML = `<p class="error">Erreur : ${data.message}</p>`;
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            container.innerHTML = '<p class="error">Erreur de chargement des tomes.</p>';
        });
}

// Écouteur unique pour TOUS les clics sur les tomes (délégation)
document.getElementById('series-list').addEventListener('click', (e) => {
    const volumeLi = e.target.closest('.volumes-list li');
    if (volumeLi) {
        e.preventDefault();
        const seriesId = volumeLi.dataset.seriesId;
        const volumeIndex = volumeLi.dataset.volumeIndex;
        let series = null;
        for (const key in window.seriesData) {
            if (window.seriesData[key].id === seriesId) {
                series = window.seriesData[key];
                break;
            }
        }
        if (series && series.volumes && series.volumes[volumeIndex]) {
            const volume = series.volumes[volumeIndex];
            document.getElementById('edit-series-id').value = seriesId;
            document.getElementById('edit-volume-index').value = volumeIndex;
            document.getElementById('edit-volume-number-display').textContent = `Tome ${volume.number}`;
            document.querySelector('#edit-volume-modal [name="status"]').value = volume.status;
            document.querySelector('#edit-volume-modal [name="is_collector"]').checked = !!volume.collector;
            document.querySelector('#edit-volume-modal [name="is_last"]').checked = !!volume.last;
            document.getElementById('edit-volume-modal').classList.add('modal-active');
        }
    }
});

// Écouteur unique pour tous les clics dans #series-list (délégation d'événements)
document.getElementById('series-list').addEventListener('click', (e) => {
    // Bouton "Voir les tomes"
    const loadBtn = e.target.closest('.load-volumes-btn');
    if (loadBtn) {
        e.preventDefault();
        const seriesId = loadBtn.dataset.seriesId;
        loadSeriesVolumes(seriesId);
        return;
    }

    // Bouton "Modifier"
    const editBtn = e.target.closest('.edit-series-btn');
        if (editBtn) {
            e.preventDefault();
            const seriesId = editBtn.dataset.seriesId;
            let series = null;
            for (const key in window.seriesData) {
                if (window.seriesData[key].id === seriesId) {
                    series = window.seriesData[key];
                    break;
                }
            }

            if (series) {
                document.getElementById('edit-series-id-input').value = seriesId;
                document.getElementById('edit-series-name').value = series.name;
                document.getElementById('edit-series-author').value = series.author;
                document.getElementById('edit-series-publisher').value = series.publisher;
                document.getElementById('edit-series-other-contributors').value = series.other_contributors ? series.other_contributors.join(', ') : '';
                document.getElementById('edit-series-categories').value = series.categories ? series.categories.join(', ') : '';
                document.getElementById('edit-series-genres').value = series.genres ? series.genres.join(', ') : '';
                document.getElementById('edit-series-anilist-id').value = series.anilist_id || '';
                document.getElementById('edit-series-mature').checked = series.mature || false;
                document.getElementById('edit-series-favorite').checked = series.favorite || false;
                document.getElementById('current-series-image').src = series.image || 'logo.png';
                document.getElementById('edit-series-modal').classList.add('modal-active');
            }
            return;
        }

    // Bouton "Supprimer"
    const deleteBtn = e.target.closest('.delete-series-btn');
    if (deleteBtn) {
        e.preventDefault();
        const seriesId = deleteBtn.dataset.seriesId;
        showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer cette série ?')
            .then((confirmed) => {
                if (confirmed) {
                    fetch('admin.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `delete_series=true&series_id=${encodeURIComponent(seriesId)}&no_redirect=true`
                    })
                    .then(() => window.location.reload())
                    .catch(error => console.error('Erreur:', error));
                }
            });
        return;
    }

    // Bouton "Lues ailleurs"
    const moveBtn = e.target.closest('.move-to-read-btn');
    if (moveBtn) {
        e.preventDefault();
        const seriesId = moveBtn.dataset.seriesId;
        const seriesName = moveBtn.closest('.series-card').querySelector('h2').textContent;
        showCustomConfirm('Confirmation', `Déplacer "${seriesName}" vers "Lues ailleurs" ?`)
            .then((confirmed) => {
                if (confirmed) {
                    fetch('admin.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `move_to_read=true&series_id=${encodeURIComponent(seriesId)}`
                    })
                    .then(() => window.location.reload())
                    .catch(error => console.error('Erreur:', error));
                }
            });
        return;
    }

    // Tome (pour modification)
    const volumeLi = e.target.closest('.volumes-list li');
    if (volumeLi) {
        e.preventDefault();
        const seriesId = volumeLi.dataset.seriesId;
        const volumeIndex = volumeLi.dataset.volumeIndex;
        let series = null;
        for (const key in window.seriesData) {
            if (window.seriesData[key].id === seriesId) {
                series = window.seriesData[key];
                break;
            }
        }
        if (series && series.volumes && series.volumes[volumeIndex]) {
            const volume = series.volumes[volumeIndex];
            document.getElementById('edit-series-id').value = seriesId;
            document.getElementById('edit-volume-index').value = volumeIndex;
            document.getElementById('edit-volume-number-display').textContent = `Tome ${volume.number}`;
            document.querySelector('#edit-volume-modal [name="status"]').value = volume.status;
            document.querySelector('#edit-volume-modal [name="is_collector"]').checked = !!volume.collector;
            document.querySelector('#edit-volume-modal [name="is_last"]').checked = !!volume.last;
            document.getElementById('edit-volume-modal').classList.add('modal-active');
        }
        return;
    }
});

// Écouteur de scroll avec throttle
window.addEventListener('scroll', throttle(() => {
    const { scrollTop, scrollHeight, clientHeight } = document.documentElement;
    if (scrollTop + clientHeight >= scrollHeight - 200 && !isLoading && hasMoreSeries) {
        loadMoreSeries();
    }
}, 300));

// Initialisation au chargement de la page
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('series-list').innerHTML = '';
    currentPage = 0; // On commence à 0 pour charger la page 1
    loadMoreSeries();
});