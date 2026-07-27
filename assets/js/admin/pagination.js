// Variables globales
let currentPage = 1;
let isLoading = false;
let hasMoreSeries = true;
const seriesList = document.getElementById('series-list');

// État courant des contrôles de tri / filtre (admin).
let currentSortBy = 'name';
let currentSortOrder = 'asc';
let currentSearchTerm = '';

// Lit l'état du widget de filtre de statuts (cases + mode OU/ET).
function readStatusFilter() {
    const root = document.getElementById('status-filter');
    if (root && typeof root.__sfRead === 'function') {
        return root.__sfRead();
    }
    return { filter: '', mode: 'or' };
}

// Fragment d'URL pour le filtre de statuts.
function statusFilterQuery() {
    const st = readStatusFilter();
    return `&status_filter=${encodeURIComponent(st.filter)}&status_mode=${encodeURIComponent(st.mode)}`;
}

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
        const searchTerm = currentSearchTerm || urlParams.get('search') || '';
        const sortBy = currentSortBy || urlParams.get('sort_by') || 'name';
        const sortOrder = currentSortOrder || urlParams.get('sort_order') || 'asc';

        // La collection affichée (Mangathèque / Animethèque) accompagne chaque
        // requête : recherche, tri et pagination restent cloisonnés par type.
        const seriesType = window.currentSeriesType || 'manga';

        const response = await fetch(
            `admin.php?get_paginated_series=true&page=${currentPage + 1}&per_page=9&light=true` +
            `&type=${encodeURIComponent(seriesType)}` +
            `&search=${encodeURIComponent(searchTerm)}` +
            `&sort_by=${sortBy}&sort_order=${sortOrder}` +
            statusFilterQuery()
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
            // Afficher le message uniquement si la liste est vide (premier appel ou filtre sans résultat)
            if (seriesList.children.length === 0) {
                seriesList.innerHTML = '<p>Aucune série trouvée.</p>';
            }
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
    const imageSrc = series.image && series.image !== '' ? series.image : 'assets/img/logo.png';
    
    // Détermine le statut de la série
    let seriesStatus = 'en cours';
    if (series.volumes && series.volumes.some(volume => volume.last)) {
        seriesStatus = 'terminée';
    } else if (series.status) {
        seriesStatus = series.status;
    }

    // Détermine l'icône et la classe CSS en fonction du statut
    let statusIcon = '▶️';
    let statusClass = 'status-in-progress';
    switch (seriesStatus) {
        case 'terminée':
            statusIcon = '✅ publication terminée';
            statusClass = 'status-completed';
            break;
        case 'en pause':
            statusIcon = '⏳ publication en pause';
            statusClass = 'status-paused';
            break;
        case 'abandonnée':
            statusIcon = '⛔ publication abandonnée';
            statusClass = 'status-abandoned';
            break;
        default:
            statusIcon = '▶️ publication en cours';
            statusClass = 'status-in-progress';
    }

    seriesCard.innerHTML = `
        <img class="series-image" src="${imageSrc}" alt="${series.name}" loading="lazy">
        <div class="series-actions">
            <button class="edit-series-btn" data-series-id="${series.id}">Modifier</button>
            <button class="review-series-btn" data-series-id="${series.id}">${series.has_review ? 'Éditer la critique' : 'Ajouter une critique'}</button>
            <button class="delete-series-btn" data-series-id="${series.id}">Supprimer</button>
        </div>
        <div class="series-info">
            <h2>${series.name}</h2>
            <p><strong>Auteur :</strong> ${series.author}</p>
            <p><strong>Éditeur :</strong> ${series.publisher}</p>
            <p><strong>Autres contributeurs :</strong> ${formatList(series.other_contributors)}</p>
            <p><strong>Catégories :</strong> ${series.categories ? series.categories.join(', ') : ''}</p>
            <p><strong>Genres :</strong> ${formatList(series.genres)}</p>
            <div class="series-badges">
                ${series.mature ? '<span class="mature-badge">🔞 mature</span>' : ''}
                ${series.read_elsewhere ? '<span class="read-elsewhere-badge">📖 lue ailleurs</span>' : ''}
                <span class="series-status-badge ${statusClass}">${statusIcon}</span>
                ${ratingBadgeHtml(series)}
                ${series.has_review ? '<span class="review-badge">✏️ Critique</span>' : ''}
                ${series.mangaupdates_url ? `<a class="mu-badge" href="${series.mangaupdates_url}" target="_blank" rel="noopener" title="Voir sur MangaUpdates"><img src="assets/img/mulogo.png" alt="MangaUpdates" class="mu-logo"></a>` : ''}
                ${series.babelio_url ? `<a class="babelio-badge" href="${series.babelio_url}" target="_blank" rel="noopener" title="Voir sur Babelio"><img src="assets/img/babelogo.png" alt="Babelio" class="babelio-logo"></a>` : ''}
            </div>
            <button class="load-volumes-btn" data-series-id="${series.id}" data-volumes-count="${series.volumes_count}">Voir les tomes (${series.volumes_count})</button>
            <div class="volumes-container" data-series-id="${series.id}"></div>
        </div>
    `;
    return seriesCard;
}

// Vérifie si un champ de liste est vide
function formatList(list) {
    // Filtre les éléments vides ou uniquement des espaces
    const filtered = list ? list.filter(item => item && item.trim() !== '') : [];
    return filtered.length > 0 ? filtered.join(', ') : '<em>aucun</em>';
}

// Charge les tomes d'une série (ou les masque si déjà affichés)
function loadSeriesVolumes(seriesId) {
    const container = document.querySelector(`.volumes-container[data-series-id="${seriesId}"]`);
    const btn = document.querySelector(`.load-volumes-btn[data-series-id="${seriesId}"]`);
    const volumesCount = btn ? btn.dataset.volumesCount : '';

    // Toggle : si les tomes sont visibles, on les masque
    if (container.dataset.loaded === 'true') {
        if (container.style.display === 'none') {
            container.style.display = '';
            if (btn) btn.textContent = `Cacher les tomes (${volumesCount})`;
        } else {
            container.style.display = 'none';
            if (btn) btn.textContent = `Voir les tomes (${volumesCount})`;
        }
        return;
    }

    // Premier chargement
    container.innerHTML = '<p class="loading-text">Chargement des tomes...</p>';
    fetch(`admin.php?get_series_volumes=true&series_id=${encodeURIComponent(seriesId)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                container.innerHTML = data.volumes_html;
                container.dataset.loaded = 'true';
                if (btn) btn.textContent = `Cacher les tomes (${volumesCount})`;
            } else {
                container.innerHTML = `<p class="error">Erreur : ${data.message}</p>`;
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            container.innerHTML = '<p class="error">Erreur de chargement des tomes.</p>';
        });
}

// Ouvre la modale « Ajouter des tomes » avec une série déjà sélectionnée.
function openAddVolumesForSeries(seriesId) {
    let series = null;
    for (const key in window.seriesData) {
        if (window.seriesData[key].id === seriesId) { series = window.seriesData[key]; break; }
    }
    const hidden = document.getElementById('multiple-selected-series-id');
    const search = document.getElementById('multiple-series-search');
    if (hidden) hidden.value = seriesId;
    if (search) search.value = series ? series.name : '';
    // Réinitialise la liste de résultats (tout visible, masquée)
    const results = document.getElementById('multiple-series-results');
    if (results) {
        results.querySelectorAll('div').forEach(d => { d.style.display = 'block'; d.classList.remove('autocomplete-active'); });
        results.style.display = 'none';
    }
    const modal = document.getElementById('add-multiple-volumes-modal');
    if (modal) modal.classList.add('modal-active');
}

// Écouteur unique pour TOUS les clics sur les tomes (délégation)
document.getElementById('series-list').addEventListener('click', (e) => {
    // Bouton « + » d'ajout rapide de tomes (prioritaire sur l'édition d'un tome)
    const addBtn = e.target.closest('.volume-add-btn');
    if (addBtn) {
        e.preventDefault();
        openAddVolumesForSeries(addBtn.dataset.seriesId);
        return;
    }

    const volumeLi = e.target.closest('.volumes-list li:not(.volume-add-btn)');
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
            document.getElementById('edit-volume-read-at').value = volume.read_at || '';
            const applyAll = document.getElementById('edit-volume-apply-status-all');
            if (applyAll) applyAll.checked = false;
            if (typeof updateReadAtVisibility === 'function') updateReadAtVisibility();
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
                let seriesStatus = 'en cours';
                if (series.volumes && series.volumes.some(volume => volume.last)) {
                    seriesStatus = 'terminée';
                } else if (series.status === 'en pause' || series.status === 'abandonnée') {
                    seriesStatus = series.status;
                }

                document.getElementById('edit-series-id-input').value = seriesId;
                document.getElementById('edit-series-name').value = series.name;
                document.getElementById('edit-series-author').value = series.author;
                document.getElementById('edit-series-publisher').value = series.publisher;
                document.getElementById('edit-series-other-contributors').value = series.other_contributors ? series.other_contributors.join(', ') : '';
                document.getElementById('edit-series-categories').value = series.categories ? series.categories.join(', ') : '';
                document.getElementById('edit-series-genres').value = series.genres ? series.genres.join(', ') : '';
                document.getElementById('edit-series-mangaupdates-url').value = series.mangaupdates_url || '';
                const babelioField = document.getElementById('edit-series-babelio-url');
                if (babelioField) babelioField.value = series.babelio_url || '';
                document.getElementById('edit-series-new-volumes-count').value = 0;
                document.getElementById('edit-series-new-volumes-status').value = 'à lire';
                document.querySelector('#edit-series-form [name="new_volumes_collector"]').checked = false;
                document.getElementById('edit-series-mature').checked = series.mature || false;
                document.getElementById('edit-series-favorite').checked = series.favorite || false;
                document.getElementById('edit-series-read-elsewhere').checked = series.read_elsewhere || false;
                document.getElementById('current-series-image').src = series.image || 'logo.png';
                const statusSelect = document.getElementById('edit-series-status');
                Array.from(statusSelect.options).forEach(option => {
                    option.selected = option.value === seriesStatus;
                });
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

    // Bouton « + » d'ajout rapide de tomes (prioritaire sur l'édition d'un tome)
    const addVolBtn = e.target.closest('.volume-add-btn');
    if (addVolBtn) {
        e.preventDefault();
        openAddVolumesForSeries(addVolBtn.dataset.seriesId);
        return;
    }

    // Tome (pour modification)
    const volumeLi = e.target.closest('.volumes-list li:not(.volume-add-btn)');
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
            document.getElementById('edit-volume-read-at').value = volume.read_at || '';
            const applyAll = document.getElementById('edit-volume-apply-status-all');
            if (applyAll) applyAll.checked = false;
            if (typeof updateReadAtVisibility === 'function') updateReadAtVisibility();
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
    // Synchronise l'état JS avec les contrôles / l'URL.
    const urlParams = new URLSearchParams(window.location.search);
    currentSearchTerm = urlParams.get('search') || document.getElementById('search-all')?.value || '';
    currentSortBy = document.getElementById('sort-by')?.value || urlParams.get('sort_by') || 'name';
    currentSortOrder = document.getElementById('sort-order')?.value || urlParams.get('sort_order') || 'asc';

    // Recharge la liste depuis la page 1 (tri / filtre en direct).
    function reloadAdminList() {
        currentSortBy = document.getElementById('sort-by')?.value || currentSortBy;
        currentSortOrder = document.getElementById('sort-order')?.value || currentSortOrder;
        seriesList.innerHTML = '';
        currentPage = 0;
        hasMoreSeries = true;
        loadMoreSeries();
    }

    document.getElementById('sort-by')?.addEventListener('change', reloadAdminList);
    document.getElementById('sort-order')?.addEventListener('change', reloadAdminList);
    document.getElementById('status-filter')?.addEventListener('statusfilter:change', reloadAdminList);

    document.getElementById('series-list').innerHTML = '';
    currentPage = 0;
    loadMoreSeries();
});

// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
// ── Widget de filtre de statuts (cases à cocher regroupées + bascule OU/ET) ──
// Fournit window.StatusFilter.read() => { filter: "a,b,c", mode: "or"|"and" }
// et déclenche un événement 'statusfilter:change' quand la sélection change.
(function () {
    const root = document.getElementById('status-filter');
    if (!root || root.__sfInit) return;
    root.__sfInit = true;

    const panel   = root.querySelector('.status-filter-panel');
    const toggle  = root.querySelector('.status-filter-toggle');
    const modeSel = root.querySelector('.status-filter-mode');
    const toggleAllBtn = root.querySelector('.status-filter-toggle-all');
    const checkboxes = () => Array.from(root.querySelectorAll('.status-filter-cb'));
    const groups = () => Array.from(root.querySelectorAll('.status-filter-group'));

    function mode() { return modeSel && modeSel.value === 'and' ? 'and' : 'or'; }

    // En mode ET, on interdit plusieurs cases dans une même catégorie multi :
    // dès qu'une case est cochée dans le groupe, les autres sont grisées.
    function applyAndConstraints() {
        const isAnd = mode() === 'and';
        groups().forEach(group => {
            const multi = group.dataset.multi === '1';
            const cbs = Array.from(group.querySelectorAll('.status-filter-cb'));
            const anyChecked = cbs.some(cb => cb.checked);
            cbs.forEach(cb => {
                const disable = isAnd && multi && anyChecked && !cb.checked;
                cb.disabled = disable;
                cb.closest('.status-filter-option')?.classList.toggle('disabled', disable);
            });
        });
    }

    // Le libellé du bouton reflète l'état (nombre de cases cochées).
    function refreshLabel() {
        const label = root.querySelector('.status-filter-label');
        if (!label) return;
        const cbs = checkboxes();
        const total = cbs.length;
        const checked = cbs.filter(cb => cb.checked).length;
        if (checked === 0 || checked === total) {
            label.textContent = 'Statuts';
        } else {
            label.textContent = 'Statuts (' + checked + ')';
        }
    }

    // Lecture de l'état -> chaîne pour l'URL.
    // Tout coché OU rien coché => filtre vide (= tout afficher).
    root.__sfRead = function () {
        const cbs = checkboxes();
        const total = cbs.length;
        const checkedVals = cbs.filter(cb => cb.checked).map(cb => cb.value);
        let filter = checkedVals.join(',');
        if (checkedVals.length === 0 || checkedVals.length === total) {
            filter = '';
        }
        return { filter: filter, mode: mode() };
    };

    function emitChange() {
        applyAndConstraints();
        refreshLabel();
        root.dispatchEvent(new CustomEvent('statusfilter:change', { bubbles: true }));
    }

    // Ouverture / fermeture du panneau.
    toggle?.addEventListener('click', function (e) {
        e.stopPropagation();
        const open = panel.hasAttribute('hidden');
        if (open) { panel.removeAttribute('hidden'); toggle.setAttribute('aria-expanded', 'true'); }
        else      { panel.setAttribute('hidden', '');  toggle.setAttribute('aria-expanded', 'false'); }
    });
    document.addEventListener('click', function (e) {
        if (!root.contains(e.target)) {
            panel.setAttribute('hidden', '');
            toggle?.setAttribute('aria-expanded', 'false');
        }
    });
    panel?.addEventListener('click', e => e.stopPropagation());

    // Cases à cocher.
    checkboxes().forEach(cb => cb.addEventListener('change', emitChange));

    // Bascule OU/ET : en repassant en ET, on garde au plus une case par
    // catégorie multi (on décoche les surplus pour éviter un état incohérent).
    modeSel?.addEventListener('change', function () {
        root.dataset.statusMode = mode();
        if (mode() === 'and') {
            groups().forEach(group => {
                if (group.dataset.multi !== '1') return;
                let seen = false;
                Array.from(group.querySelectorAll('.status-filter-cb')).forEach(cb => {
                    if (cb.checked) {
                        if (seen) cb.checked = false;
                        else seen = true;
                    }
                });
            });
        }
        emitChange();
    });

    // Tout cocher / tout décocher.
    toggleAllBtn?.addEventListener('click', function () {
        const cbs = checkboxes();
        const shouldCheck = cbs.some(cb => !cb.checked); // s'il en reste des décochées -> tout cocher
        if (shouldCheck && mode() === 'and') {
            // En mode ET, "tout cocher" n'a pas de sens (une seule par catégorie).
            // On repasse en OU pour cocher réellement tout.
            if (modeSel) { modeSel.value = 'or'; root.dataset.statusMode = 'or'; }
        }
        cbs.forEach(cb => { cb.checked = shouldCheck; cb.disabled = false; cb.closest('.status-filter-option')?.classList.remove('disabled'); });
        toggleAllBtn.textContent = shouldCheck ? 'Tout décocher' : 'Tout cocher';
        toggleAllBtn.dataset.state = shouldCheck ? 'uncheck' : 'check';
        emitChange();
    });

    // État initial.
    applyAndConstraints();
    refreshLabel();
})();
});
