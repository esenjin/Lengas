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
    return { filter: '', mode: 'and' };
}

// Fragment d'URL pour le filtre de statuts.
function statusFilterQuery() {
    const st = readStatusFilter();
    return `&status_filter=${encodeURIComponent(st.filter)}&status_mode=${encodeURIComponent(st.mode)}`;
}

// Lit l'état du widget « Affiner » (catégories + genres, mode OU/ET).
function readRefineFilter() {
    const root = document.getElementById('refine-filter');
    if (root && typeof root.__sfReadRefine === 'function') {
        return root.__sfReadRefine();
    }
    return { categories: '', genres: '', mode: 'and' };
}

// Fragment d'URL pour le filtre « Affiner ».
function refineFilterQuery() {
    const rf = readRefineFilter();
    return `&refine_categories=${encodeURIComponent(rf.categories)}` +
           `&refine_genres=${encodeURIComponent(rf.genres)}` +
           `&refine_mode=${encodeURIComponent(rf.mode)}`;
}

// Met à jour le compteur « Séries visibles : N » à partir de la réponse d'un
// endpoint de pagination (champ `total`, déjà filtré/recherché/trié).
function updateSeriesCount(total) {
    const el = document.getElementById('series-count-value');
    const wrap = document.getElementById('series-count');
    if (!el) return;
    el.textContent = (typeof total === 'number') ? total.toLocaleString('fr-FR') : '—';
    if (wrap && typeof total === 'number') wrap.dataset.count = String(total);
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
        const seriesType = typeof window.currentSeriesType === 'string'
            ? window.currentSeriesType
            : 'manga';

        const response = await fetch(
            `admin.php?get_paginated_series=true&page=${currentPage + 1}&per_page=90&light=true` +
            `&type=${encodeURIComponent(seriesType)}` +
            `&search=${encodeURIComponent(searchTerm)}` +
            `&sort_by=${sortBy}&sort_order=${sortOrder}` +
            statusFilterQuery() + refineFilterQuery()
        );
        const data = await response.json();

        if (data.success) updateSeriesCount(data.total);

        if (data.success && data.series.length > 0) {
            // Alimente window.seriesData au fil de la pagination infinie : ce
            // tableau n'est peuplé côté PHP qu'avec la première page (voir
            // admin.php, window.seriesData = ...). Sans cet ajout, toute
            // série chargée ensuite ici resterait introuvable par son id
            // pour les fonctions qui la recherchent dans window.seriesData
            // (modale d'édition, badges de carte...). N'est plus utilisé par
            // la synchro automatique elle-même, qui traite désormais les
            // séries dues par ID via un endpoint dédié
            // (get_anime_sync_due_ids), indépendamment de ce qui est déjà
            // chargé dans le DOM — voir assets/js/admin/anime.js,
            // animeSyncStart().
            if (Array.isArray(window.seriesData)) {
                data.series.forEach(series => {
                    if (!window.seriesData.some(s => s && s.id === series.id)) {
                        window.seriesData.push(series);
                    }
                });
            }

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
    // Les séries animées ont leur propre gabarit (studios et format à la place
    // de l'auteur et de l'éditeur, badges Anilist et éditions physiques).
    // Il vit dans anime.js, chargé avant ce fichier.
    if (series.type === 'anime' && typeof createAnimeSeriesCard === 'function') {
        return createAnimeSeriesCard(series);
    }

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
            <p><strong>Autres contributeurs :</strong> ${formatListCollapsed(series.other_contributors)}</p>
            <p><strong>Catégories :</strong> ${series.categories ? series.categories.join(', ') : ''}</p>
            <p><strong>Genres :</strong> ${formatListCollapsed(series.genres)}</p>
            <div class="series-badges series-badges--links">
                ${series.mangaupdates_url ? `<a class="mu-badge" href="${series.mangaupdates_url}" target="_blank" rel="noopener" title="Voir sur MangaUpdates"><img src="assets/img/mulogo.png" alt="MangaUpdates" class="mu-logo"></a>` : ''}
                ${series.babelio_url ? `<a class="babelio-badge" href="${series.babelio_url}" target="_blank" rel="noopener" title="Voir sur Babelio"><img src="assets/img/babelogo.png" alt="Babelio" class="babelio-logo"></a>` : ''}
            </div>
            <div class="series-badges series-badges--tags">
                ${series.mature ? '<span class="mature-badge">🔞 mature</span>' : ''}
                ${series.read_elsewhere ? '<span class="read-elsewhere-badge">📖 lue ailleurs</span>' : ''}
                <span class="series-status-badge ${statusClass}">${statusIcon}</span>
                ${ratingBadgeHtml(series)}
                ${rewatchBadgeHtml(series)}
                ${series.has_review ? '<span class="review-badge">✏️ Critique</span>' : ''}
            </div>
            <div class="volumes-container" data-series-id="${series.id}" data-loaded="true">${series.volumes_html || ''}</div>
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

// Échappement HTML minimal, pour les valeurs insérées dans un attribut
// (data-more) ou en tant que texte au sein des cartes admin.
function pgEscape(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Affiche uniquement le premier élément d'une liste à choix multiples
// (autres contributeurs, genres…) ; s'il y en a d'autres, un bouton « +N »
// les révèle au clic (cf. délégation d'événement plus bas) — gagne de la
// place sur la carte admin sans rien cacher définitivement.
function formatListCollapsed(list) {
    const filtered = list ? list.filter(item => item && item.trim() !== '') : [];
    if (filtered.length === 0) return '<em>aucun</em>';
    if (filtered.length === 1) return pgEscape(filtered[0]);

    const rest = filtered.slice(1);
    const restText = pgEscape(rest.join(', '));
    return `${pgEscape(filtered[0])}` +
           `<button type="button" class="list-more-toggle" data-more="${restText}" data-more-count="${rest.length}">+${rest.length}</button>`;
}

// Délégation d'événement : clic sur un bouton « +N » -> dévoile le reste de
// la liste à la place du bouton (remplacement en place, pas de tooltip —
// reste lisible et accessible au clavier/tactile).
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.list-more-toggle');
    if (!btn) return;
    e.stopPropagation();
    const rest = btn.dataset.more || '';
    const span = document.createElement('span');
    span.className = 'list-more-expanded';
    span.textContent = ', ' + rest;
    btn.replaceWith(span);
});

// Libellé pluriel de l'unité d'une série (« tomes » ou « épisodes »), lu dans
// le registre de types exposé par le PHP (window.seriesTypes) — jamais écrit
// en dur, pour qu'un type futur n'ait rien à changer ici. episodes.js définit
// déjà animeVocab() pour le type `anime` ; on généralise ici à N'IMPORTE quel
// type via son propre vocabulaire, avec repli sur "tomes" si le registre ou le
// type sont absents (bases antérieures à la V4, script chargé isolément).
function seriesItemsLabel(seriesId) {
    const series = (typeof findSeriesById === 'function') ? findSeriesById(seriesId) : null;
    const type   = series ? (series.type || 'manga') : 'manga';
    const def    = window.seriesTypes && window.seriesTypes[type];
    return (def && def.vocab && def.vocab.items) ? def.vocab.items : 'tomes';
}

// Recharge le contenu (déjà affiché) des tomes/épisodes d'une série depuis le
// serveur. Les tomes/épisodes sont désormais affichés en permanence — plus de
// bouton "voir/cacher" — cette fonction ne sert donc plus qu'à rafraîchir un
// container déjà visible après une action qui a pu en changer le contenu côté
// serveur sans repasser par le DOM local (ex : synchronisation Anilist).
function refreshSeriesVolumes(seriesId) {
    const container = document.querySelector(`.volumes-container[data-series-id="${seriesId}"]`);
    if (!container) return;
    const itemsLabel = seriesItemsLabel(seriesId);

    fetch(`admin.php?get_series_volumes=true&series_id=${encodeURIComponent(seriesId)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                container.innerHTML = data.volumes_html;
                container.dataset.loaded = 'true';
            } else {
                container.innerHTML = `<p class="error">Erreur : ${data.message}</p>`;
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            container.innerHTML = `<p class="error">Erreur de chargement des ${itemsLabel}.</p>`;
        });
}


// Ouvre la modale « Ajouter des tomes » avec une série déjà sélectionnée.
function openAddVolumesForSeries(seriesId) {
    const series = findSeriesById(seriesId);
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

// Écouteur unique pour tous les clics dans #series-list (délégation d'événements)
//
// Le bouton "Voir/cacher les tomes" a disparu : les tomes/épisodes sont
// désormais affichés en permanence dans chaque carte, rendus côté serveur dès
// le chargement (plus pertinent depuis SQLite, qui rend ce contenu bon marché
// à générer). Il ne reste ici que les autres actions de la carte.
document.getElementById('series-list').addEventListener('click', (e) => {
    // Bouton "Modifier"
    const editBtn = e.target.closest('.edit-series-btn');
        if (editBtn) {
            e.preventDefault();
            const seriesId = editBtn.dataset.seriesId;
            const series = findSeriesById(seriesId);

            // Les animés ouvrent leur propre modale, gérée par anime.js.
            if (series && series.type === 'anime') return;

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

    // Bouton « + » (prioritaire sur l'édition d'un tome). Deux sens selon la
    // collection : ajouter des tomes à un manga, marquer l'épisode suivant d'un
    // animé comme vu.
    const addVolBtn = e.target.closest('.volume-add-btn');
    if (addVolBtn) {
        e.preventDefault();
        const seriesId = addVolBtn.dataset.seriesId;
        const series = findSeriesById(seriesId);
        if (series && series.type === 'anime') {
            markNextEpisode(seriesId);
        } else {
            openAddVolumesForSeries(seriesId);
        }
        return;
    }

    // Tome ou épisode (pour modification)
    const volumeLi = e.target.closest('.volumes-list li:not(.volume-add-btn)');
    if (volumeLi) {
        e.preventDefault();
        const seriesId = volumeLi.dataset.seriesId;
        const volumeIndex = volumeLi.dataset.volumeIndex;
        const series = findSeriesById(seriesId);

        // Un épisode a sa propre modale : pas de collector, pas de « dernier
        // épisode » à cocher, pas de suppression.
        if (series && series.type === 'anime') {
            openEpisodeModal(series, volumeIndex);
            return;
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

// ─────────────────────────────────────────────────────────────────────────────
// Widget générique de filtre à cases à cocher regroupées (bascule OU/ET,
// tout cocher/décocher, panneau dépliant). Partagé par « Statuts » et
// « Affiner » (admin comme public) — seule la fonction de lecture de l'état
// (installée sur root via `installReader`) diffère entre les deux.
//
// Comportement par défaut : AUCUNE case cochée, mode ET (« et ») — un critère
// non coché ne filtre rien ; l'utilisateur choisit explicitement ce qu'il
// veut voir apparaître.
// ─────────────────────────────────────────────────────────────────────────────
function initCheckboxFilterWidget(rootId, baseLabel, eventName, installReader) {
    const root = document.getElementById(rootId);
    if (!root || root.__sfInit) return;
    root.__sfInit = true;

    const panel   = root.querySelector('.status-filter-panel');
    const toggle  = root.querySelector('.status-filter-toggle');
    const modeSel = root.querySelector('.status-filter-mode');
    const toggleAllBtn = root.querySelector('.status-filter-toggle-all');
    const checkboxes = () => Array.from(root.querySelectorAll('.status-filter-cb'));
    const groups = () => Array.from(root.querySelectorAll('.status-filter-group'));

    function mode() { return modeSel && modeSel.value === 'or' ? 'or' : 'and'; }
    root.__sfMode = mode;

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
        const checked = cbs.filter(cb => cb.checked).length;
        label.textContent = checked === 0 ? baseLabel : (baseLabel + ' (' + checked + ')');
    }

    // Le bouton "Tout cocher/décocher" reflète l'état courant plutôt que de
    // garder un état interne : "Tout décocher" si au moins une case est
    // cochée, sinon "Tout cocher".
    function refreshToggleAllLabel() {
        if (!toggleAllBtn) return;
        const anyChecked = checkboxes().some(cb => cb.checked);
        toggleAllBtn.textContent = anyChecked ? 'Tout décocher' : 'Tout cocher';
        toggleAllBtn.dataset.state = anyChecked ? 'uncheck' : 'check';
    }

    installReader(root, checkboxes);

    function emitChange() {
        applyAndConstraints();
        refreshLabel();
        refreshToggleAllLabel();
        root.dispatchEvent(new CustomEvent(eventName, { bubbles: true }));
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
        emitChange();
    });

    // État initial.
    applyAndConstraints();
    refreshLabel();
    refreshToggleAllLabel();
}

// Écouteur de scroll avec throttle
window.addEventListener('scroll', throttle(() => {
    const { scrollTop, scrollHeight, clientHeight } = document.documentElement;
    if (scrollTop + clientHeight >= scrollHeight - 200 && !isLoading && hasMoreSeries) {
        loadMoreSeries();
    }
}, 300));

// Initialisation au chargement de la page.
//
// ⚠️ Ordre important : le widget de filtre de statuts (juste en dessous) DOIT
// être initialisé avant le tout premier appel à loadMoreSeries(), sans quoi
// celui-ci lit le filtre via readStatusFilter() → root.__sfRead(), qui n'existe
// pas encore et retombe sur le repli { filter: '', mode: 'or' } : un
// status_filter fourni dans l'URL (ex. depuis un lien de la sidebar) serait
// alors silencieusement ignoré au premier chargement. D'où un seul et même
// bloc DOMContentLoaded, dans cet ordre précis.
document.addEventListener('DOMContentLoaded', () => {
    // ── Widget de filtre « Statuts » (cases à cocher regroupées + bascule OU/ET) ──
    // Fournit root.__sfRead() => { filter: "a,b,c", mode: "or"|"and" }
    // et déclenche un événement 'statusfilter:change' quand la sélection change.
    initCheckboxFilterWidget('status-filter', 'Statuts', 'statusfilter:change', function (root, checkboxes) {
        root.__sfRead = function () {
            const cbs = checkboxes();
            const checkedVals = cbs.filter(cb => cb.checked).map(cb => cb.value);
            return { filter: checkedVals.join(','), mode: root.__sfMode() };
        };
    });

    // ── Widget de filtre « Affiner » (Catégories / Genres) ──────────────────
    // Même mécanique que « Statuts », mais deux champs distincts pour l'URL
    // (refine_categories / refine_genres) selon le groupe d'origine de chaque
    // case (attribut data-refine-field posé par render_refine_filter()).
    initCheckboxFilterWidget('refine-filter', 'Affiner', 'refinefilter:change', function (root, checkboxes) {
        root.__sfReadRefine = function () {
            const cbs = checkboxes();
            const cats = cbs.filter(cb => cb.checked && cb.dataset.refineField === 'categories').map(cb => cb.value);
            const genres = cbs.filter(cb => cb.checked && cb.dataset.refineField === 'genres').map(cb => cb.value);
            return { categories: cats.join(','), genres: genres.join(','), mode: root.__sfMode() };
        };
    });

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
    document.getElementById('refine-filter')?.addEventListener('refinefilter:change', reloadAdminList);

    document.getElementById('series-list').innerHTML = '';
    currentPage = 0;
    loadMoreSeries();
});
