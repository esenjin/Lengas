// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/syngas-search.js — Section « Recherche Syngas »
//
// Recherche EXPLICITE uniquement (bouton « Chercher »), jamais au fil de la
// frappe. Fonctionne dans les deux contextes (ajout / édition d'une série
// manga), différenciés par le préfixe des IDs DOM générés par
// includes/syngas_search_section.php : 'add-series-syngas' ou
// 'edit-series-syngas'.
//
// Contexte "add"  : la validation d'un résultat pré-remplit les champs du
//                    formulaire ET pose les champs cachés syngas_uid /
//                    syngas_thumbnail_path — la création réelle se fait au
//                    clic sur « Ajouter » comme d'habitude.
// Contexte "edit" : la validation écrit directement en base (écriture
//                    ciblée, endpoint syngas_validate) puisque la série
//                    existe déjà, puis rafraîchit la carte à l'affichage
//                    exactement comme l'édition normale (series.js).
// ──────────────────────────────────────────────────────────────────────────

function syngasEscHtml(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

const SYNGAS_CONTEXTS = ['add-series-syngas', 'edit-series-syngas'];

// Pré-remplit le champ de recherche avec le nom déjà saisi dans le formulaire
// (section 4 : « pré-rempli avec le nom déjà saisi, s'il y en a un »).
function syngasPrefillFromNameField(prefix) {
    const input = document.getElementById(`${prefix}-input`);
    if (!input) return;
    const nameField = prefix === 'edit-series-syngas'
        ? document.getElementById('edit-series-name')
        : document.getElementById('add-series-name');
    if (nameField && nameField.value && !input.value) {
        input.value = nameField.value;
    }
}

// Ouverture de la modale d'ajout : préremplissage + reset de la section.
document.getElementById('open-add-series-modal')?.addEventListener('click', () => {
    syngasResetSection('add-series-syngas');
});

// Ouverture de la modale d'édition (délégation, series.js gère le clic sur
// .edit-series-btn) : on écoute l'ajout de la classe modal-active plutôt que
// de dupliquer la logique d'ouverture.
(function () {
    const editModal = document.getElementById('edit-series-modal');
    if (!editModal) return;
    const observer = new MutationObserver(() => {
        if (editModal.classList.contains('modal-active')) {
            syngasResetSection('edit-series-syngas');
            syngasPrefillFromNameField('edit-series-syngas');
        }
    });
    observer.observe(editModal, { attributes: true, attributeFilter: ['class'] });
})();

function syngasResetSection(prefix) {
    const results = document.getElementById(`${prefix}-results`);
    if (results) results.innerHTML = '';
    const banned = document.getElementById(`${prefix}-banned`);
    if (banned) banned.hidden = true;
    const idRow = document.getElementById(`${prefix}-id-row`);
    if (idRow) idRow.hidden = true;
    const idInput = document.getElementById(`${prefix}-id-input`);
    if (idInput) idInput.value = '';
    const hiddenUid = document.getElementById('add-series-syngas-uid');
    if (prefix === 'add-series-syngas' && hiddenUid) hiddenUid.value = '';
    const hiddenThumb = document.getElementById('add-series-syngas-thumbnail-path');
    if (prefix === 'add-series-syngas' && hiddenThumb) hiddenThumb.value = '';
}

// Délégation des clics « Chercher » (nom ET identifiant), « Valider » et
// « Chercher par identifiant Syngas » (bascule d'affichage) pour les deux
// contextes.
document.addEventListener('click', (e) => {
    SYNGAS_CONTEXTS.forEach(prefix => {
        if (e.target.closest(`#${prefix}-btn`)) {
            syngasRunSearch(prefix);
        }
        if (e.target.closest(`#${prefix}-id-btn`)) {
            syngasRunSearchById(prefix);
        }
        if (e.target.closest(`#${prefix}-toggle-id`)) {
            syngasToggleIdRow(prefix);
        }
        const validateBtn = e.target.closest(`[data-syngas-validate][data-syngas-prefix="${prefix}"]`);
        if (validateBtn) {
            syngasValidateResult(prefix, validateBtn.dataset.syngasId, validateBtn);
        }
    });
});

// Recherche également déclenchable via Entrée dans les champs texte (nom ET
// identifiant).
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    SYNGAS_CONTEXTS.forEach(prefix => {
        if (e.target.id === `${prefix}-input`) {
            e.preventDefault();
            syngasRunSearch(prefix);
        }
        if (e.target.id === `${prefix}-id-input`) {
            e.preventDefault();
            syngasRunSearchById(prefix);
        }
    });
});

// Affiche/masque la ligne de recherche par identifiant. Repliée par défaut :
// la recherche par nom reste le cas d'usage principal (section 4).
function syngasToggleIdRow(prefix) {
    const row = document.getElementById(`${prefix}-id-row`);
    if (!row) return;
    row.hidden = !row.hidden;
    if (!row.hidden) {
        document.getElementById(`${prefix}-id-input`)?.focus();
    }
}

function syngasRunSearch(prefix) {
    const input   = document.getElementById(`${prefix}-input`);
    const btn     = document.getElementById(`${prefix}-btn`);
    if (!input) return;

    const query = input.value.trim();
    if (query === '') {
        const results = document.getElementById(`${prefix}-results`);
        if (results) results.innerHTML = '<p class="hint">Saisissez un nom de série avant de chercher.</p>';
        return;
    }

    syngasFetchResults(prefix, btn, 'q=' + encodeURIComponent(query));
}

// Recherche par identifiant Syngas direct : résout une seule fiche via
// GET /series/{id} côté serveur (syngas_get_series()), plutôt qu'une
// recherche par mots-clés — voir le commentaire de l'endpoint dans
// admin.php. Le résultat est restitué dans le même format qu'une recherche
// par nom, donc syngasRenderResults()/syngasValidateResult() n'ont besoin
// d'aucune adaptation.
function syngasRunSearchById(prefix) {
    const input = document.getElementById(`${prefix}-id-input`);
    const btn   = document.getElementById(`${prefix}-id-btn`);
    if (!input) return;

    const id = input.value.trim();
    if (id === '') {
        const results = document.getElementById(`${prefix}-results`);
        if (results) results.innerHTML = '<p class="hint">Saisissez un identifiant Syngas avant de chercher.</p>';
        return;
    }

    syngasFetchResults(prefix, btn, 'id=' + encodeURIComponent(id));
}

// Appel réseau commun aux deux modes de recherche (nom / identifiant) :
// mêmes états de chargement, mêmes cas d'erreur (bannissement, échec réseau),
// même rendu des résultats. Seul le paramètre de requête change.
function syngasFetchResults(prefix, btn, queryString) {
    const spinner = btn ? btn.querySelector('.syngas-search-spinner') : null;
    const text    = btn ? btn.querySelector('.syngas-search-btn-text') : null;
    const results = document.getElementById(`${prefix}-results`);
    const banned  = document.getElementById(`${prefix}-banned`);
    if (!results) return;

    if (btn) btn.disabled = true;
    if (spinner) spinner.hidden = false;
    if (text) text.textContent = 'Recherche…';
    results.innerHTML = '';
    if (banned) banned.hidden = true;

    fetch('admin.php?syngas_search=1&' + queryString)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                if (data.banned && banned) {
                    banned.hidden = false;
                    const reasonEl = banned.querySelector('.syngas-search-banned-reason');
                    if (reasonEl) reasonEl.textContent = data.message || '';
                } else {
                    results.innerHTML = `<p class="error-text">${syngasEscHtml(data.message || 'La recherche a échoué.')}</p>`;
                }
                return;
            }
            syngasRenderResults(prefix, data.results || []);
        })
        .catch(() => {
            results.innerHTML = '<p class="error-text">La recherche a échoué : le serveur n\'a pas répondu.</p>';
        })
        .finally(() => {
            if (btn) btn.disabled = false;
            if (spinner) spinner.hidden = true;
            if (text) text.textContent = 'Chercher';
        });
}

function syngasRenderResults(prefix, results) {
    const container = document.getElementById(`${prefix}-results`);
    if (!container) return;

    if (results.length === 0) {
        container.innerHTML = '<p class="hint">Aucun résultat sur Syngas pour cette recherche.</p>';
        return;
    }

    let html = '';
    results.forEach(r => {
        const thumb = r.thumbnail_url
            ? `<img class="syngas-result-thumb" src="${syngasEscHtml(r.thumbnail_url)}" alt="" loading="lazy">`
            : `<div class="syngas-result-thumb syngas-result-thumb--empty"></div>`;
        const meta = [r.author, r.publisher].filter(Boolean).map(syngasEscHtml).join(' · ');
        html += `
            <div class="syngas-result">
                ${thumb}
                <div class="syngas-result-info">
                    <div class="syngas-result-name">${syngasEscHtml(r.name)}</div>
                    ${meta ? `<div class="syngas-result-meta">${meta}</div>` : ''}
                </div>
                <div class="syngas-result-actions">
                    ${r.public_url ? `<a class="syngas-result-link" href="${syngasEscHtml(r.public_url)}" target="_blank" rel="noopener">Voir sur Syngas ↗</a>` : ''}
                    <button type="button" class="button button-ats syngas-result-validate"
                            data-syngas-validate data-syngas-prefix="${prefix}" data-syngas-id="${syngasEscHtml(r.id)}">
                        Valider
                    </button>
                </div>
            </div>
        `;
    });
    container.innerHTML = html;
}

function syngasValidateResult(prefix, syngasId, btn) {
    if (!syngasId) return;
    const originalLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Validation…';

    const isEdit = prefix === 'edit-series-syngas';
    const seriesId = isEdit ? (document.getElementById('edit-series-id-input')?.value || '') : '';

    const params = new URLSearchParams();
    params.set('syngas_validate', '1');
    params.set('syngas_id', syngasId);
    if (seriesId) params.set('series_id', seriesId);

    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            if (data.banned) {
                const banned = document.getElementById(`${prefix}-banned`);
                if (banned) {
                    banned.hidden = false;
                    const reasonEl = banned.querySelector('.syngas-search-banned-reason');
                    if (reasonEl) reasonEl.textContent = data.message || '';
                }
            } else {
                showCustomAlert('Erreur', data.message || 'La validation a échoué.');
            }
            btn.disabled = false;
            btn.textContent = originalLabel;
            return;
        }

        if (isEdit) {
            // Série existante : déjà écrite en base côté serveur. On remplace
            // la carte affichée, exactement comme la soumission normale du
            // formulaire d'édition (series.js), puis on ferme la modale.
            const oldCard = document.querySelector(`.series-card[data-series-id="${CSS.escape(seriesId)}"]`);
            if (oldCard && typeof createLightSeriesCard === 'function') {
                const newCard = createLightSeriesCard(data.series);
                oldCard.replaceWith(newCard);
            }
            if (Array.isArray(seriesData)) {
                const idx = seriesData.findIndex(s => s.id === seriesId);
                if (idx !== -1) seriesData[idx] = Object.assign({}, seriesData[idx], data.series);
            }
            if (typeof modals !== 'undefined' && modals['edit-series']) {
                modals['edit-series'].modal.classList.remove('modal-active');
            }
            showCustomAlert('Syngas', 'Fiche mise à jour depuis Syngas.');
        } else {
            syngasApplyFieldsToAddForm(data.fields || {}, data.syngas_uid || '', data.thumbnail_path || '', data.volumes_count);
        }
    })
    .catch(() => {
        showCustomAlert('Erreur', "La validation a échoué : le serveur n'a pas répondu.");
        btn.disabled = false;
        btn.textContent = originalLabel;
    });
}

// Applique les champs renvoyés par syngas_validate au formulaire d'AJOUT
// (la série n'existe pas encore). Un champ absent de `fields` (vide côté
// Syngas) n'est jamais touché — cohérent avec la règle « champ vide
// n'écrase jamais » appliquée côté serveur.
function syngasApplyFieldsToAddForm(fields, syngasUid, thumbnailPath, volumesCount) {
    const map = {
        name: 'add-series-name',
        author: 'add-series-author',
        publisher: 'add-series-publisher',
        other_contributors: 'add-series-other-contributors',
        genres: 'add-series-genres',
        mangaupdates_url: null, // pas de champ dédié dans la modale d'ajout par name direct
        babelio_url: null,
    };

    if (fields.name) document.getElementById('add-series-name').value = fields.name;
    if (fields.author) document.getElementById('add-series-author').value = fields.author;
    if (fields.publisher) document.getElementById('add-series-publisher').value = fields.publisher;
    if (fields.other_contributors) document.getElementById('add-series-other-contributors').value = fields.other_contributors;
    if (fields.genres) document.getElementById('add-series-genres').value = fields.genres;
    if (Array.isArray(fields.categories) && fields.categories.length) {
        document.getElementById('add-series-categories').value = fields.categories.join(', ');
    }
    const muField = document.querySelector('#add-series-modal input[name="mangaupdates_url"]');
    if (muField && fields.mangaupdates_url) muField.value = fields.mangaupdates_url;
    const babelioField = document.querySelector('#add-series-modal input[name="babelio_url"]');
    if (babelioField && fields.babelio_url) babelioField.value = fields.babelio_url;
    if (typeof fields.mature === 'boolean') {
        const matureField = document.querySelector('#add-series-modal input[name="mature"]');
        if (matureField) matureField.checked = fields.mature;
    }
    if (typeof fields.status === 'string' && fields.status) {
        const statusSelect = document.getElementById('add-series-status');
        if (statusSelect) {
            const match = Array.from(statusSelect.options).find(o => o.value === fields.status);
            if (match) statusSelect.value = fields.status;
        }
    }

    const hiddenUid = document.getElementById('add-series-syngas-uid');
    if (hiddenUid) hiddenUid.value = syngasUid;
    const hiddenThumb = document.getElementById('add-series-syngas-thumbnail-path');
    if (hiddenThumb) hiddenThumb.value = thumbnailPath;
    const hiddenVolumes = document.getElementById('add-series-syngas-volumes-count');
    if (hiddenVolumes && volumesCount !== null && volumesCount !== undefined) hiddenVolumes.value = volumesCount;

    showCustomAlert('Syngas', 'Fiche pré-remplie depuis Syngas. Vérifiez les champs avant de valider l\'ajout.');
}
