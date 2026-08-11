// ─────────────────────────────────────────────────────────────────────────────
// assets/js/admin/anime.js — Séries animées (admin)
//
// Deux modales et le rendu des cartes de l'Animethèque.
//
// Principe : ce fichier n'invente aucune donnée. Il affiche ce que le serveur
// lui donne et ne renvoie que ce que l'utilisateur a le droit de changer —
// titre choisi, coches, note, vignette, éditions physiques. Tout le reste vient
// d'Anilist et n'est présenté qu'en lecture seule.
// ─────────────────────────────────────────────────────────────────────────────

// Échappement HTML : les titres Anilist contiennent des guillemets, des
// esperluettes et parfois des chevrons. Ils passent tous par ici.
function animeEscape(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// ── Badges communs aux cartes et aux modales ────────────────────────────────

// Statut de DIFFUSION (et non de publication) : mêmes tags que les mangas,
// vocabulaire de l'Animethèque.
function animeStatusBadge(status) {
    switch (status) {
        case 'terminée':   return { icon: '✅ diffusion terminée',   cls: 'status-completed' };
        case 'en pause':   return { icon: '⏳ diffusion en pause',   cls: 'status-paused' };
        case 'abandonnée': return { icon: '⛔ diffusion abandonnée', cls: 'status-abandoned' };
        default:           return { icon: '▶️ diffusion en cours',   cls: 'status-in-progress' };
    }
}

// Badge « éditions physiques » : le détail des commentaires apparaît au survol.
function animeEditionsBadgeHtml(series) {
    const editions = series.editions || [];
    if (!editions.length) return '';
    const tip = editions.map(e => '• ' + e).join('\n');
    return `<span class="editions-badge" data-title="${animeEscape(tip)}" aria-label="Éditions physiques">` +
           `<img src="assets/img/physique.png" alt="Éditions physiques" class="editions-logo">` +
           `</span>`;
}

// Lien vers la fiche Anilist, sur le modèle des badges MangaUpdates et Babelio.
function animeAnilistBadgeHtml(series) {
    if (!series.anilist_url) return '';
    return `<a class="anilist-badge" href="${animeEscape(series.anilist_url)}" target="_blank" rel="noopener" title="Voir la fiche sur Anilist">` +
           `<img src="assets/img/anilogo.png" alt="Anilist" class="anilist-logo"></a>`;
}

// ── Carte de série animée (admin) ───────────────────────────────────────────
// Reprend le gabarit des mangas ; les champs sans objet (auteur, éditeur,
// MangaUpdates, Babelio, lue ailleurs, collector) sont simplement absents.
function createAnimeSeriesCard(series) {
    const card = document.createElement('div');
    card.className = 'series-card series-card--anime' + (series.favorite ? ' favorite' : '');
    card.dataset.seriesId = series.id;

    const imageSrc = series.image && series.image !== '' ? series.image : 'assets/img/logo.png';
    const badge = animeStatusBadge(series.status);

    card.innerHTML = `
        <img class="series-image" src="${animeEscape(imageSrc)}" alt="${animeEscape(series.name)}" loading="lazy">
        <div class="series-actions">
            <button class="edit-series-btn" data-series-id="${series.id}">Modifier</button>
            <button class="review-series-btn" data-series-id="${series.id}">${series.has_review ? 'Éditer la critique' : 'Ajouter une critique'}</button>
            <button class="delete-series-btn" data-series-id="${series.id}">Supprimer</button>
        </div>
        <div class="series-info">
            <h2>${animeEscape(series.name)}</h2>
            <p><strong>Studios :</strong> ${series.studios_text ? animeEscape(series.studios_text) : '<em>inconnus</em>'}</p>
            <p><strong>Catégorie :</strong> ${animeEscape(series.format_label || '')}</p>
            <p><strong>Genres :</strong> ${formatListCollapsed(series.genres)}</p>
            <div class="series-badges series-badges--links">
                ${animeEditionsBadgeHtml(series)}
                ${animeAnilistBadgeHtml(series)}
            </div>
            <div class="series-badges series-badges--tags">
                ${series.mature ? '<span class="mature-badge">🔞 mature</span>' : ''}
                ${series.watching_abandoned ? '<span class="watching-abandoned-badge">📕 visionnage abandonné</span>' : ''}
                <span class="series-status-badge ${badge.cls}" data-anime-status-badge>${badge.icon}</span>
                ${ratingBadgeHtml(series)}
                ${rewatchBadgeHtml(series)}
                ${series.has_review ? '<span class="review-badge">✏️ Critique</span>' : ''}
                <span class="anime-sync-badge" data-anime-sync-badge hidden></span>
            </div>
            <div class="volumes-container" data-series-id="${series.id}" data-loaded="true">${series.volumes_html || ''}</div>
        </div>
    `;
    return card;
}

// ─────────────────────────────────────────────────────────────────────────────
// Synchronisation automatique
//
// Déclenchée à l'affichage de chaque carte animée éligible (diffusion ET
// visionnage « en cours », verrou de 24h écoulé — sync_due calculé côté
// serveur). La page reste utilisable pendant l'appel : seul un petit badge
// discret apparaît sur la carte concernée, remplacé par le résultat une fois
// la réponse reçue. Aucune alerte bloquante, aucun rechargement de page.
// ─────────────────────────────────────────────────────────────────────────────

// Nombre de synchronisations déjà déclenchées lors de cette visite : une
// protection de confort, qui évite de déclencher plus d'appels que nécessaire
// si beaucoup de cartes éligibles s'affichent d'un coup (défilement rapide,
// tri...). Repart de zéro à chaque chargement complet de page.
//
// Le VRAI plafond, celui qui compte, est appliqué côté serveur
// (anilist_sync_visit_quota_*, fonctions/tools/anilist_sync.php) sur une
// fenêtre glissante de 10 minutes — il ne dépend donc pas d'un rechargement
// de page et ne peut pas être contourné en rechargeant simplement l'onglet.
let animeSyncTriggeredCount = 0;
const ANIME_SYNC_MAX_PER_VISIT = 5; // doit rester ≤ à anilist_sync_max_per_visit() côté PHP

function animeSyncBadgeEl(card) {
    return card.querySelector('[data-anime-sync-badge]');
}

function animeSyncSetBadge(card, html, cls) {
    const el = animeSyncBadgeEl(card);
    if (!el) return;
    el.className = 'anime-sync-badge' + (cls ? ' ' + cls : '');
    el.innerHTML = html;
    el.hidden = !html;
}

// Recharge la liste des épisodes (toujours affichée) et met à jour le badge
// de statut de diffusion, pour que les nouveaux épisodes diffusés apparaissent
// sans que l'utilisateur ait à recharger la page lui-même.
function animeSyncRefreshCard(card, seriesId, result) {
    const badge = animeStatusBadge(result.anime_status);
    const statusBadgeEl = card.querySelector('[data-anime-status-badge]');
    if (statusBadgeEl) {
        statusBadgeEl.className = 'series-status-badge ' + badge.cls;
        statusBadgeEl.textContent = badge.icon;
    }

    if (typeof refreshSeriesVolumes === 'function') {
        refreshSeriesVolumes(seriesId);
    }

    // window.seriesData reflète aussi ce que verrait une nouvelle carte créée
    // ensuite (tri, changement de page) : on le tient à jour pour éviter un
    // badge de statut périmé après un rafraîchissement partiel de la liste.
    if (Array.isArray(window.seriesData)) {
        const s = window.seriesData.find(s => s && s.id === seriesId);
        if (s) {
            s.status = result.anime_status;
            s.volumes_count = result.volumes_count;
            s.anilist_synced_at = result.anilist_synced_at;
        }
    }
}

async function animeSyncCard(card, seriesId) {
    animeSyncSetBadge(card, '<span class="spinner"></span> Synchronisation…', 'is-loading');

    try {
        const response = await fetch('admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'sync_anime_series=1&series_id=' + encodeURIComponent(seriesId)
        });
        const result = await response.json();

        if (result.status === 'synced') {
            animeSyncRefreshCard(card, seriesId, result);
            animeSyncSetBadge(card, '✓ Synchronisée', 'is-done');
            setTimeout(() => animeSyncSetBadge(card, '', ''), 4000);
        } else if (result.status === 'unchanged') {
            if (Array.isArray(window.seriesData)) {
                const s = window.seriesData.find(s => s && s.id === seriesId);
                if (s) s.anilist_synced_at = result.anilist_synced_at;
            }
            animeSyncSetBadge(card, '', '');
        } else if (result.status === 'error') {
            animeSyncSetBadge(card, '⚠️ Échec de la synchronisation', 'is-error');
            card.querySelector('[data-anime-sync-badge]')?.setAttribute(
                'data-title', result.message || "Erreur inconnue."
            );
        } else {
            // 'skipped' : non éligible, verrou non écoulé, ou quota de
            // synchronisations par visite déjà atteint (vérifiés côté
            // serveur, qui reste seul juge malgré le sync_due du front).
            // Rien à montrer : ce n'est pas un échec, juste rien à faire
            // maintenant — la carte sera reprise à une prochaine visite.
            animeSyncSetBadge(card, '', '');
        }
    } catch (error) {
        console.error('Erreur de synchronisation Anilist :', error);
        animeSyncSetBadge(card, '⚠️ Échec de la synchronisation', 'is-error');
    }
}

// Parcourt les cartes animées visibles à l'instant T et déclenche la synchro
// pour celles marquées `sync_due` par le serveur, dans la limite du plafond
// de visite. Appelée après chaque insertion de nouvelles cartes (chargement
// de page, changement de tri/filtre, pagination infinie).
function animeSyncScanVisibleCards() {
    if (animeSyncTriggeredCount >= ANIME_SYNC_MAX_PER_VISIT) return;
    if (!Array.isArray(window.seriesData)) return;
    if (window.currentSeriesType !== 'anime') return;

    document.querySelectorAll('.series-card--anime[data-series-id]').forEach(card => {
        if (animeSyncTriggeredCount >= ANIME_SYNC_MAX_PER_VISIT) return;
        if (card.dataset.syncTriggered === '1') return;

        const seriesId = card.dataset.seriesId;
        const s = window.seriesData.find(s => s && s.id === seriesId);
        if (!s || !s.sync_due) return;

        card.dataset.syncTriggered = '1';
        animeSyncTriggeredCount++;
        animeSyncCard(card, seriesId);
    });
}
window.animeSyncScanVisibleCards = animeSyncScanVisibleCards;

// Nouvelles cartes ajoutées par la pagination infinie (assets/js/admin/
// pagination.js) : on observe l'apparition de cartes dans #series-list plutôt
// que de modifier loadMoreSeries() directement, pour rester sans dépendance
// d'ordre de chargement entre les deux fichiers.
(function watchAnimeCardsInsertion() {
    const list = document.getElementById('series-list');
    if (!list || typeof MutationObserver === 'undefined') return;

    const observer = new MutationObserver(() => animeSyncScanVisibleCards());
    observer.observe(list, { childList: true });

    document.addEventListener('DOMContentLoaded', () => animeSyncScanVisibleCards());
})();

// ─────────────────────────────────────────────────────────────────────────────
// Rendu d'un résultat de recherche Anilist (fiche normalisée allégée) — commun
// à la modale d'ajout d'admin.php et au panneau d'ajout animé de la liste
// d'envies (pages/page-wishlist.php). Exposé sur window pour être réutilisé
// tel quel, sans dupliquer le gabarit.
// ─────────────────────────────────────────────────────────────────────────────
// $context vaut 'library' (défaut, vidéothèque — modale d'ajout d'admin.php)
// ou 'wishlist' (panneau d'ajout animé de pages/page-wishlist.php).
//
// En contexte vidéothèque, une série non encore diffusée ne rejoint jamais la
// vidéothèque : elle relève de la liste d'envies, et le résultat est bloqué
// (pas de bouton) pour éviter un clic qui échouerait de toute façon côté
// serveur. En contexte wishlist, c'est l'inverse : « pas encore diffusée » est
// justement le cas d'usage attendu — le bouton reste actif, seule une série
// déjà présente (vidéothèque OU wishlist) bloque réellement l'ajout.
function animeResultHtml(media, context) {
    context = context || 'library';
    const blocked = context === 'wishlist'
        ? !!media.already_present
        : (media.already_present || media.not_yet_released);

    let notice = '';
    if (media.already_present) {
        notice = `<p class="anime-result-notice">Déjà dans la vidéothèque : « ${animeEscape(media.present_as)} »</p>`;
    } else if (media.not_yet_released) {
        notice = context === 'wishlist'
            ? `<p class="anime-result-notice">Pas encore diffusée : elle sera importée le jour de sa diffusion.</p>`
            : `<p class="anime-result-notice">Pas encore diffusée : cette série relève de la liste d'envies.</p>`;
    }

    const meta = [
        media.year ? String(media.year) : '',
        media.format_label || '',
        media.status_label || '',
        media.episodes ? media.episodes + ' épisode' + (media.episodes > 1 ? 's' : '') : ''
    ].filter(Boolean).join(' • ');

    const alt = [media.title_english, media.title_native]
        .filter(t => t && t !== media.title)
        .join(' — ');

    const addLabel = context === 'wishlist' ? 'Sélectionner' : 'Ajouter';

    return `
        <div class="anime-result${blocked ? ' is-blocked' : ''}" data-anilist-id="${media.anilist_id}" data-title="${animeEscape(media.title)}" data-studios-text="${animeEscape(media.studios_text || '')}">
            <img class="anime-result-cover" src="${animeEscape(media.cover || 'assets/img/logo.png')}" alt="" loading="lazy">
            <div class="anime-result-body">
                <p class="anime-result-title">${animeEscape(media.title)}${media.is_adult ? ' <span class="mature-badge">🔞</span>' : ''}</p>
                ${alt ? `<p class="anime-result-alt">${animeEscape(alt)}</p>` : ''}
                <p class="anime-result-meta">${animeEscape(meta)}</p>
                ${media.studios_text ? `<p class="anime-result-meta">${animeEscape(media.studios_text)}</p>` : ''}
                ${notice}
            </div>
            ${blocked ? '' : `<button type="button" class="button anime-result-add">${addLabel}</button>`}
        </div>
    `;
}
window.animeResultHtml = animeResultHtml;

// ─────────────────────────────────────────────────────────────────────────────
// Modale « Ajouter une série animée »
// ─────────────────────────────────────────────────────────────────────────────
(function setupAnimeSearch() {
    const modal        = document.getElementById('add-anime-modal');
    if (!modal) return;

    const input         = document.getElementById('anime-search-input');
    const button        = document.getElementById('anime-search-btn');
    const lookupInput   = document.getElementById('anime-lookup-input');
    const lookupButton  = document.getElementById('anime-lookup-btn');
    const results       = document.getElementById('anime-search-results');
    const feedback      = document.getElementById('anime-search-feedback');

    let searching = false;
    let importing = false;

    function setFeedback(text, kind) {
        feedback.textContent = text || '';
        feedback.className = 'anime-search-feedback' + (kind ? ' is-' + kind : '');
    }

    async function runSearch() {
        const term = (input.value || '').trim();
        if (term === '' || searching) return;

        searching = true;
        results.innerHTML = '';
        setFeedback('Interrogation d\u2019Anilist…', 'loading');

        try {
            const response = await fetch('admin.php?anilist_search=1&q=' + encodeURIComponent(term));
            const data = await response.json();

            if (!data.success) {
                setFeedback(data.message || 'La recherche a échoué.', 'error');
                return;
            }
            if (!data.results.length) {
                setFeedback('Aucune série animée ne correspond à cette recherche.', 'error');
                return;
            }
            setFeedback('');
            results.innerHTML = data.results.map(m => animeResultHtml(m)).join('');
        } catch (error) {
            console.error('Erreur:', error);
            setFeedback('Anilist est injoignable pour le moment.', 'error');
        } finally {
            searching = false;
        }
    }

    // Recherche par identifiant Anilist direct : même feedback, même liste de
    // résultats (un seul ici), même bouton « Ajouter ».
    async function runLookup() {
        const raw = (lookupInput.value || '').trim();
        const id  = parseInt(raw, 10);
        if (!raw || !Number.isInteger(id) || id <= 0 || searching) {
            if (raw) setFeedback("L'identifiant Anilist doit être un nombre entier positif.", 'error');
            return;
        }

        searching = true;
        results.innerHTML = '';
        setFeedback('Interrogation d\u2019Anilist…', 'loading');

        try {
            const response = await fetch('admin.php?anilist_lookup=1&id=' + encodeURIComponent(id));
            const data = await response.json();

            if (!data.success || !data.results.length) {
                setFeedback(data.message || 'Aucune série ne correspond à cet identifiant.', 'error');
                return;
            }
            setFeedback('');
            results.innerHTML = data.results.map(m => animeResultHtml(m)).join('');
        } catch (error) {
            console.error('Erreur:', error);
            setFeedback('Anilist est injoignable pour le moment.', 'error');
        } finally {
            searching = false;
        }
    }

    button?.addEventListener('click', runSearch);
    input?.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            runSearch();
        }
    });

    lookupButton?.addEventListener('click', runLookup);
    lookupInput?.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            runLookup();
        }
    });

    // Import : seul l'identifiant part au serveur, qui recharge la fiche depuis
    // Anilist. Rien de ce qui est affiché ici ne sert de source.
    results?.addEventListener('click', async function (e) {
        const addBtn = e.target.closest('.anime-result-add');
        if (!addBtn || importing) return;

        const row = addBtn.closest('.anime-result');
        const anilistId = row?.dataset.anilistId;
        if (!anilistId) return;

        importing = true;
        addBtn.disabled = true;
        addBtn.textContent = 'Import en cours…';

        try {
            const response = await fetch('admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'add_anime_series=1&anilist_id=' + encodeURIComponent(anilistId)
            });
            const data = await response.json();

            if (data.success) {
                // On bascule sur l'Animethèque : la série vient d'y arriver.
                window.location.href = 'admin.php?type=anime';
                return;
            }
            setFeedback(data.message || "L'import a échoué.", 'error');
        } catch (error) {
            console.error('Erreur:', error);
            setFeedback("L'import a échoué : le serveur n'a pas répondu.", 'error');
        } finally {
            importing = false;
            addBtn.disabled = false;
            addBtn.textContent = 'Ajouter';
        }
    });

    // Réinitialisation à chaque ouverture : une campagne de recherche ne doit
    // pas rouvrir sur les résultats de la précédente.
    document.getElementById('open-add-anime-modal')?.addEventListener('click', function () {
        input.value = '';
        if (lookupInput) lookupInput.value = '';
        results.innerHTML = '';
        setFeedback('');
        modal.classList.add('modal-active');
        setTimeout(() => input.focus(), 50);
    });
})();

// ─────────────────────────────────────────────────────────────────────────────
// Modale « Modifier la série animée »
// ─────────────────────────────────────────────────────────────────────────────
(function setupAnimeEdit() {
    const modal = document.getElementById('edit-anime-modal');
    if (!modal) return;

    const editionsBox   = document.getElementById('edit-anime-editions');
    const editionsCheck = document.getElementById('edit-anime-has-editions');

    function toggleEditions() {
        if (!editionsBox || !editionsCheck) return;
        editionsBox.hidden = !editionsCheck.checked;
    }
    editionsCheck?.addEventListener('change', toggleEditions);

    function fill(series) {
        document.getElementById('edit-anime-id').value = series.id;

        // Titre : uniquement par sélection parmi les titres connus d'Anilist.
        const titleSelect = document.getElementById('edit-anime-name');
        titleSelect.innerHTML = '';
        (series.alt_titles || [series.name]).forEach(title => {
            const option = document.createElement('option');
            option.value = title;
            option.textContent = title;
            option.selected = (title === series.name);
            titleSelect.appendChild(option);
        });

        // Données Anilist, en lecture seule.
        const genres = (series.genres || []).filter(g => g && g.trim() !== '');
        document.getElementById('edit-anime-studios').textContent = series.studios_text || 'inconnus';
        document.getElementById('edit-anime-format').textContent  = series.format_label || '—';
        document.getElementById('edit-anime-genres').textContent  = genres.length ? genres.join(', ') : 'aucun';
        document.getElementById('edit-anime-status').textContent  = animeStatusBadge(series.status).icon;

        const link = document.getElementById('edit-anime-link');
        if (series.anilist_url) {
            link.href = series.anilist_url;
            link.textContent = 'Ouvrir la fiche ↗';
            link.style.display = '';
        } else {
            link.removeAttribute('href');
            link.textContent = '—';
        }

        // Coches et note.
        document.getElementById('edit-anime-mature').checked   = !!series.mature;
        document.getElementById('edit-anime-favorite').checked  = !!series.favorite;
        document.getElementById('edit-anime-watching-abandoned').checked = !!series.watching_abandoned;
        document.getElementById('edit-anime-rating').value = series.rating || '';
        document.getElementById('edit-anime-rewatch-count').value = series.rewatch_count || 0;

        // Éditions physiques.
        const editions = series.editions || [];
        editionsCheck.checked = editions.length > 0;
        const inputs = editionsBox.querySelectorAll('.anime-edition-input');
        inputs.forEach((field, i) => { field.value = editions[i] || ''; });
        toggleEditions();

        // Vignette : la case de suppression ne concerne QUE la vignette
        // personnalisée. Celle d'Anilist n'est jamais effaçable à la main —
        // proposer de la supprimer laisserait croire qu'on peut le faire.
        const preview = document.getElementById('edit-anime-image-preview');
        preview.src = series.thumbnail || series.image || 'assets/img/logo.png';

        const removeRow  = document.getElementById('edit-anime-remove-image-row');
        const removeCb   = document.getElementById('edit-anime-remove-image');
        const originHint = document.getElementById('edit-anime-image-origin');
        const hasCustom  = !!(series.custom_image && series.custom_image !== '');

        removeCb.checked = false;
        removeRow.hidden = !hasCustom;
        if (hasCustom) {
            originHint.textContent = 'Vignette personnalisée.';
        } else if (series.anilist_image) {
            originHint.textContent = "Vignette fournie par Anilist. Téléverser une image la remplacera à l'affichage, sans l'effacer.";
        } else {
            originHint.textContent = 'Aucune vignette : la vignette par défaut du site est utilisée.';
        }

        const fileField = document.getElementById('edit-anime-image');
        if (fileField) fileField.value = '';

        modal.classList.add('modal-active');
    }

    // Bouton « Modifier » d'une carte : on ne prend la main que sur les animés,
    // les mangas restant l'affaire de series.js et pagination.js.
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.edit-series-btn');
        if (!btn) return;

        const seriesId = btn.dataset.seriesId;
        const list = Array.isArray(window.seriesData) ? window.seriesData : Object.values(window.seriesData || {});
        const series = list.find(s => s && s.id === seriesId);
        if (!series || series.type !== 'anime') return;

        e.preventDefault();
        e.stopPropagation();
        fill(series);
    }, true /* capture : passe avant les gestionnaires des mangas */);

    // Ouverture automatique par identifiant de série, depuis l'extérieur de ce
    // fichier (includes/sidebar.php, retour de page-wishlist.php après import
    // d'un animé) : ?open_edit_anime=<id>. window.seriesData couvre déjà toute
    // la collection filtrée par type, pas seulement les cartes chargées à
    // l'écran (pagination), donc la série s'y trouve dès le premier rendu.
    window.openAnimeEditModalById = function (seriesId) {
        const list = Array.isArray(window.seriesData) ? window.seriesData : Object.values(window.seriesData || {});
        const series = list.find(s => s && s.id === seriesId && s.type === 'anime');
        if (series) fill(series);
    };

    // Garde-fou de taille, identique à celui des mangas.
    const animeForm = document.getElementById('edit-anime-form');
    animeForm?.addEventListener('submit', function (e) {
        const fileInput = this.querySelector('input[type="file"]');
        if (fileInput && fileInput.files.length > 0 && fileInput.files[0].size > 5 * 1024 * 1024) {
            e.preventDefault();
            showCustomAlert('Avertissement', 'Le fichier est trop volumineux (max. 5 Mo).');
        }
    });

    // ── Soumission AJAX : évite de recharger toute la page pour ne rafraîchir,
    // au final, qu'une seule carte. Le serveur reçoit "ajax=1" et répond en
    // JSON avec la carte "light" à jour (mêmes champs que la pagination).
    animeForm?.addEventListener('submit', async function (e) {
        if (e.defaultPrevented) return; // garde-fou de taille ci-dessus a déjà annulé
        e.preventDefault();

        const submitBtn = animeForm.querySelector('button[type="submit"]');
        const originalLabel = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Mise à jour…';
        }

        const seriesId = document.getElementById('edit-anime-id').value;
        const formData = new FormData(animeForm);
        formData.set('ajax', '1');
        // FormData n'inclut la valeur d'un bouton submit que s'il a déclenché
        // l'envoi ; on la rajoute donc explicitement pour que le serveur voie
        // bien $_POST['update_anime_series'].
        formData.set('update_anime_series', 'Mettre à jour');

        try {
            const response = await fetch('admin.php' + window.location.search, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (!result.success) {
                showCustomAlert('Erreur', result.message || "La mise à jour a échoué.");
                return;
            }

            const oldCard = document.querySelector(`.series-card[data-series-id="${CSS.escape(seriesId)}"]`);
            const newCard = createAnimeSeriesCard(result.series);
            if (oldCard) {
                oldCard.replaceWith(newCard);
            } else if (typeof seriesList !== 'undefined' && seriesList) {
                seriesList.appendChild(newCard);
            }

            if (Array.isArray(window.seriesData)) {
                const idx = window.seriesData.findIndex(s => s && s.id === seriesId);
                if (idx !== -1) window.seriesData[idx] = Object.assign({}, window.seriesData[idx], result.series);
            }

            modal.classList.remove('modal-active');

            if (result.warning) {
                showCustomAlert('Information', result.warning);
            }
        } catch (error) {
            console.error('Erreur:', error);
            showCustomAlert('Erreur', "La mise à jour a échoué : le serveur n'a pas répondu.");
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalLabel;
            }
        }
    });
})();
