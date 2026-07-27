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
    const count = series.volumes_count || 0;

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
            <p><strong>Genres :</strong> ${formatList(series.genres)}</p>
            <div class="series-badges">
                ${series.mature ? '<span class="mature-badge">🔞 mature</span>' : ''}
                ${series.watching_abandoned ? '<span class="watching-abandoned-badge">📕 visionnage abandonné</span>' : ''}
                <span class="series-status-badge ${badge.cls}">${badge.icon}</span>
                ${ratingBadgeHtml(series)}
                ${series.has_review ? '<span class="review-badge">✏️ Critique</span>' : ''}
                ${animeEditionsBadgeHtml(series)}
                ${animeAnilistBadgeHtml(series)}
            </div>
            <button class="load-volumes-btn" data-series-id="${series.id}" data-volumes-count="${count}">Voir les épisodes (${count})</button>
            <div class="volumes-container" data-series-id="${series.id}"></div>
        </div>
    `;
    return card;
}

// ─────────────────────────────────────────────────────────────────────────────
// Modale « Ajouter une série animée »
// ─────────────────────────────────────────────────────────────────────────────
(function setupAnimeSearch() {
    const modal    = document.getElementById('add-anime-modal');
    if (!modal) return;

    const input    = document.getElementById('anime-search-input');
    const button   = document.getElementById('anime-search-btn');
    const results  = document.getElementById('anime-search-results');
    const feedback = document.getElementById('anime-search-feedback');

    let searching = false;
    let importing = false;

    function setFeedback(text, kind) {
        feedback.textContent = text || '';
        feedback.className = 'anime-search-feedback' + (kind ? ' is-' + kind : '');
    }

    function resultHtml(media) {
        // Une série non encore diffusée ne rejoint jamais la vidéothèque : elle
        // relève de la liste d'envies. Le refus est expliqué sur place plutôt
        // que découvert après le clic.
        const blocked = media.already_present || media.not_yet_released;

        let notice = '';
        if (media.already_present) {
            notice = `<p class="anime-result-notice">Déjà dans la vidéothèque : « ${animeEscape(media.present_as)} »</p>`;
        } else if (media.not_yet_released) {
            notice = `<p class="anime-result-notice">Pas encore diffusée : cette série relève de la liste d'envies.</p>`;
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

        return `
            <div class="anime-result${blocked ? ' is-blocked' : ''}" data-anilist-id="${media.anilist_id}">
                <img class="anime-result-cover" src="${animeEscape(media.cover || 'assets/img/logo.png')}" alt="" loading="lazy">
                <div class="anime-result-body">
                    <p class="anime-result-title">${animeEscape(media.title)}${media.is_adult ? ' <span class="mature-badge">🔞</span>' : ''}</p>
                    ${alt ? `<p class="anime-result-alt">${animeEscape(alt)}</p>` : ''}
                    <p class="anime-result-meta">${animeEscape(meta)}</p>
                    ${media.studios_text ? `<p class="anime-result-meta">${animeEscape(media.studios_text)}</p>` : ''}
                    ${notice}
                </div>
                ${blocked ? '' : '<button type="button" class="button anime-result-add">Ajouter</button>'}
            </div>
        `;
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
            results.innerHTML = data.results.map(resultHtml).join('');
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
        document.getElementById('edit-anime-rewatch').textContent =
            (series.rewatch_count || 0) === 0
                ? 'aucun revisionnage'
                : series.rewatch_count + ' revisionnage' + (series.rewatch_count > 1 ? 's' : '');

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

    // Garde-fou de taille, identique à celui des mangas.
    document.getElementById('edit-anime-form')?.addEventListener('submit', function (e) {
        const fileInput = this.querySelector('input[type="file"]');
        if (fileInput && fileInput.files.length > 0 && fileInput.files[0].size > 5 * 1024 * 1024) {
            e.preventDefault();
            showCustomAlert('Avertissement', 'Le fichier est trop volumineux (max. 5 Mo).');
        }
    });
})();
