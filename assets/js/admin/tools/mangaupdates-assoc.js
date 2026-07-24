// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/mangaupdates-assoc.js — Outils « Association MangaUpdates »
//
// Recherche en flux (SSE) des fiches MangaUpdates pour les séries sans URL
// et des genres pour les séries sans genre, avec validation avant
// enregistrement.
// ──────────────────────────────────────────────────────────────────────────

// ──────────────────────────────────────────────────────────────────────────────
// Outil « Associer MangaUpdates » (page Outils)
// Recherche une fiche MangaUpdates pour chaque série sans URL, puis laisse
// l'utilisateur valider la bonne correspondance avant l'enregistrement.
// ──────────────────────────────────────────────────────────────────────────────

// Échappements locaux
function muEscHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function muEscAttr(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Délégation : le bouton est dans le HTML statique de la modale Outils
document.addEventListener('click', (e) => {
    if (e.target.closest('#mu-associate-btn')) {
        loadMuAssociate();
    } else if (e.target.closest('#mu-associate-save-btn')) {
        saveMuAssociations();
    } else if (e.target.closest('#mu-genres-btn')) {
        loadMuGenres();
    } else if (e.target.closest('#mu-genres-save-btn')) {
        saveMuGenres();
    }
});

// Bord vert sur l'option choisie (uniquement une vraie correspondance, pas « Aucune »)
document.addEventListener('change', (e) => {
    const radio = e.target.closest('#mu-associate-form input[type="radio"]');
    if (!radio) return;
    const optionsWrap = radio.closest('.mu-associate-options');
    if (!optionsWrap) return;
    optionsWrap.querySelectorAll('.mu-associate-option').forEach(lbl => lbl.classList.remove('is-selected'));
    const label = radio.closest('.mu-associate-option');
    if (label && radio.value) label.classList.add('is-selected');
});

// Lance l'association via le flux SSE : toutes les séries sans URL, avec progression
let muAssociateSource = null;
function loadMuAssociate() {
    const btn      = document.getElementById('mu-associate-btn');
    const textEl   = document.getElementById('mu-associate-text');
    const spinner  = document.getElementById('mu-associate-spinner');
    const progress = document.getElementById('mu-associate-progress');
    const results  = document.getElementById('mu-associate-results');
    if (!results || !progress) return;

    if (muAssociateSource) { muAssociateSource.close(); muAssociateSource = null; }

    if (btn) btn.disabled = true;
    if (textEl) textEl.textContent = 'Recherche en cours...';
    if (spinner) spinner.style.display = 'inline-block';

    results.innerHTML = '<div class="mu-associate-form" id="mu-associate-form"></div>';

    let current = 0, total = 0, currentName = '';
    const renderProgress = () => {
        const countText = total > 0 ? `${current} / ${total}` : `${current}`;
        progress.innerHTML =
            `<p class="analysis-progress">` +
            `<span class="progress-spinner"></span>` +
            `Recherche : <strong>${muEscHtml(currentName) || '…'}</strong> ` +
            `<span class="progress-count">(${countText})</span>` +
            `</p>`;
    };
    renderProgress();

    let anyMatch = false;
    const source = new EventSource('page-outils.php?action=mu_associate_stream');
    muAssociateSource = source;

    source.addEventListener('progress', (ev) => {
        const d = JSON.parse(ev.data);
        current = d.current; total = d.total; currentName = d.name;
        renderProgress();
    });

    source.addEventListener('match', (ev) => {
        const d = JSON.parse(ev.data);
        if (d.series) { appendMuAssociateSeries(d.series); anyMatch = true; }
    });

    source.addEventListener('done', (ev) => {
        const d = JSON.parse(ev.data);
        source.close(); muAssociateSource = null;
        finalizeMuAssociate(d, anyMatch);
        if (btn) btn.disabled = false;
        if (textEl) textEl.textContent = 'Relancer la recherche';
        if (spinner) spinner.style.display = 'none';
    });

    source.onerror = () => {
        source.close(); muAssociateSource = null;
        progress.innerHTML = '';
        if (!anyMatch) results.innerHTML = '<p class="error-text">La recherche a été interrompue. Veuillez réessayer.</p>';
        if (btn) btn.disabled = false;
        if (textEl) textEl.textContent = 'Recherche des liens';
        if (spinner) spinner.style.display = 'none';
    };
}

// Ajoute le bloc d'une série au formulaire, au fil du flux
function appendMuAssociateSeries(series) {
    const form = document.getElementById('mu-associate-form');
    if (!form) return;
    const list = series.results || [];

    const wrap = document.createElement('div');
    wrap.className = 'mu-associate-series';
    wrap.dataset.seriesId = series.id;

    let html = `<div class="mu-associate-series-name">${muEscHtml(series.name)}`;
    if (series.author) html += ` <small>${muEscHtml(series.author)}</small>`;
    html += `</div><div class="mu-associate-options">`;

    // Toujours « Aucune correspondance » par défaut (aucune présélection)
    html += `<label class="mu-associate-option">
                <input type="radio" name="mu_${muEscAttr(series.id)}" value="" checked>
                <span class="mu-cand-none">Aucune correspondance</span>
             </label>`;

    list.forEach(r => {
        const status = r.status_text ? translateMuStatus(r.status_text) : '';
        const meta   = [r.type, r.year, status].filter(Boolean).map(muEscHtml).join(' · ');
        const author = (r.authors && r.authors.length) ? r.authors.join(', ') : '';
        const badges =
            (r.author_match ? '<span class="mu-cand-badge mu-cand-badge--author">auteur ✓</span>' : '') +
            (r.title_match  ? '<span class="mu-cand-badge mu-cand-badge--title">titre ✓</span>' : '');
        html += `<label class="mu-associate-option">
                    <input type="radio" name="mu_${muEscAttr(series.id)}" value="${muEscAttr(r.url)}">
                    <span class="mu-cand-info">
                        <span class="mu-cand-title">${muEscHtml(r.title)} ${badges}</span>
                        ${meta ? `<span class="mu-cand-meta">${meta}</span>` : ''}
                        ${author ? `<span class="mu-cand-author">${muEscHtml(author)}</span>` : ''}
                    </span>
                    ${r.url ? `<a class="mu-cand-link" href="${muEscAttr(r.url)}" target="_blank" rel="noopener" onclick="event.stopPropagation()">fiche ↗</a>` : ''}
                 </label>`;
    });

    html += `</div>`;
    wrap.innerHTML = html;
    form.appendChild(wrap);
}

// Finalise : bouton d'enregistrement + récapitulatif des séries sans correspondance
function finalizeMuAssociate(d, anyMatch) {
    const progress = document.getElementById('mu-associate-progress');
    const results  = document.getElementById('mu-associate-results');
    if (progress) progress.innerHTML = '';
    if (!results) return;

    if (!anyMatch) {
        results.innerHTML = '<p class="mu-associate-empty">Aucune correspondance trouvée — vos séries possèdent peut-être déjà toutes une URL MangaUpdates. ✅</p>';
        return;
    }

    const saveBtn = document.createElement('button');
    saveBtn.id = 'mu-associate-save-btn';
    saveBtn.className = 'button button-ats';
    saveBtn.textContent = 'Enregistrer les correspondances';
    results.appendChild(saveBtn);

    const noRes = (d && d.no_results) || [];
    if (noRes.length > 0) {
        const det = document.createElement('details');
        det.className = 'mu-associate-noresults';
        det.innerHTML = `<summary>${noRes.length} série(s) sans correspondance trouvée</summary>` +
            `<ul>${noRes.map(n => `<li>${muEscHtml(n)}</li>`).join('')}</ul>`;
        results.appendChild(det);
    }
}

// Enregistre les correspondances sélectionnées
function saveMuAssociations() {
    const blocks = document.querySelectorAll('#mu-associate-results .mu-associate-series');
    const params = new URLSearchParams();
    params.set('tool_action', 'mu_associate_save');

    let count = 0;
    blocks.forEach(block => {
        const id  = block.dataset.seriesId;
        const sel = block.querySelector('input[type="radio"]:checked');
        if (sel && sel.value) {
            params.append(`associations[${id}]`, sel.value);
            count++;
        }
    });

    if (count === 0) {
        showCustomAlert('Information', 'Aucune correspondance sélectionnée.');
        return;
    }

    const saveBtn = document.getElementById('mu-associate-save-btn');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Enregistrement...'; }

    fetch('page-outils.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showSuccessModal(`${data.saved} association(s) enregistrée(s).`);
            setTimeout(() => window.location.reload(), 900);
        } else {
            showErrorModal(data.message || "Erreur lors de l'enregistrement.");
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Enregistrer les correspondances'; }
        }
    })
    .catch(() => {
        showErrorModal('Une erreur est survenue.');
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Enregistrer les correspondances'; }
    });
}

// ──────────────────────────────────────────────────────────────────────────────
// Outil « Associer les genres » (page Outils)
// Recherche les genres sur la fiche MangaUpdates de chaque série qui possède une
// URL mais aucun genre renseigné, les traduit en FR et les pré-remplit pour
// validation (par série ou en masse), avec édition possible.
// ──────────────────────────────────────────────────────────────────────────────

let muGenresSource = null;
function loadMuGenres() {
    const btn      = document.getElementById('mu-genres-btn');
    const textEl   = document.getElementById('mu-genres-text');
    const spinner  = document.getElementById('mu-genres-spinner');
    const progress = document.getElementById('mu-genres-progress');
    const results  = document.getElementById('mu-genres-results');
    if (!results || !progress) return;

    if (muGenresSource) { muGenresSource.close(); muGenresSource = null; }

    if (btn) btn.disabled = true;
    if (textEl) textEl.textContent = 'Recherche en cours...';
    if (spinner) spinner.style.display = 'inline-block';

    results.innerHTML = '<div class="mu-genres-form" id="mu-genres-form"></div>';

    let current = 0, total = 0, currentName = '';
    const renderProgress = () => {
        const countText = total > 0 ? `${current} / ${total}` : `${current}`;
        progress.innerHTML =
            `<p class="analysis-progress">` +
            `<span class="progress-spinner"></span>` +
            `Recherche : <strong>${muEscHtml(currentName) || '…'}</strong> ` +
            `<span class="progress-count">(${countText})</span>` +
            `</p>`;
    };
    renderProgress();

    let anyMatch = false;
    const source = new EventSource('page-outils.php?action=mu_genres_stream');
    muGenresSource = source;

    source.addEventListener('progress', (ev) => {
        const d = JSON.parse(ev.data);
        current = d.current; total = d.total; currentName = d.name;
        renderProgress();
    });

    source.addEventListener('match', (ev) => {
        const d = JSON.parse(ev.data);
        if (d.series) { appendMuGenresSeries(d.series); anyMatch = true; }
    });

    source.addEventListener('done', (ev) => {
        const d = JSON.parse(ev.data);
        source.close(); muGenresSource = null;
        finalizeMuGenres(d, anyMatch);
        if (btn) btn.disabled = false;
        if (textEl) textEl.textContent = 'Relancer la recherche';
        if (spinner) spinner.style.display = 'none';
    });

    source.onerror = () => {
        source.close(); muGenresSource = null;
        progress.innerHTML = '';
        if (!anyMatch) results.innerHTML = '<p class="error-text">La recherche a été interrompue. Veuillez réessayer.</p>';
        if (btn) btn.disabled = false;
        if (textEl) textEl.textContent = 'Recherche des genres';
        if (spinner) spinner.style.display = 'none';
    };
}

// Ajoute le bloc d'une série au formulaire, au fil du flux
function appendMuGenresSeries(series) {
    const form = document.getElementById('mu-genres-form');
    if (!form) return;
    const genres = (series.genres || []).join(', ');

    const wrap = document.createElement('div');
    wrap.className = 'mu-genres-series';
    wrap.dataset.seriesId = series.id;

    let html = `<div class="mu-genres-series-name">${muEscHtml(series.name)}`;
    if (series.author) html += ` <small>${muEscHtml(series.author)}</small>`;
    if (series.mangaupdates_url) {
        html += ` <a class="mu-cand-link" href="${muEscAttr(series.mangaupdates_url)}" target="_blank" rel="noopener">fiche ↗</a>`;
    }
    html += `</div>`;

    html += `<div class="mu-genres-row">
                <input type="text" class="mu-genres-input" value="${muEscAttr(genres)}"
                       placeholder="Genres (séparés par des virgules)" autocomplete="off">
                <button type="button" class="button button-ats mu-genres-validate-btn">Valider</button>
                <span class="mu-genres-feedback"></span>
             </div>`;

    wrap.innerHTML = html;
    form.appendChild(wrap);
}

// Finalise : bouton « tout valider » + récapitulatif des séries sans genre trouvé
function finalizeMuGenres(d, anyMatch) {
    const progress = document.getElementById('mu-genres-progress');
    const results  = document.getElementById('mu-genres-results');
    if (progress) progress.innerHTML = '';
    if (!results) return;

    if (!anyMatch) {
        results.innerHTML = '<p class="mu-associate-empty">Aucun genre à associer — vos séries avec une URL MangaUpdates possèdent peut-être déjà toutes des genres, ou aucun genre n\'est renseigné sur leur fiche. ✅</p>';
        return;
    }

    const saveBtn = document.createElement('button');
    saveBtn.id = 'mu-genres-save-btn';
    saveBtn.className = 'button button-ats';
    saveBtn.textContent = 'Tout valider';
    results.appendChild(saveBtn);

    const noRes = (d && d.no_results) || [];
    if (noRes.length > 0) {
        const det = document.createElement('details');
        det.className = 'mu-associate-noresults';
        det.innerHTML = `<summary>${noRes.length} série(s) sans genre trouvé</summary>` +
            `<ul>${noRes.map(n => `<li>${muEscHtml(n)}</li>`).join('')}</ul>`;
        results.appendChild(det);
    }
}

// Envoie un lot de genres (objet {series_id: "g1, g2"}) au serveur.
// onDone(success, savedCount) est appelé en fin de requête.
function postMuGenres(payload, onDone) {
    const params = new URLSearchParams();
    params.set('tool_action', 'mu_genres_save');
    Object.keys(payload).forEach(id => {
        params.append(`genres[${id}]`, payload[id]);
    });

    fetch('page-outils.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => onDone(!!data.success, data.saved || 0, data.message))
    .catch(() => onDone(false, 0, 'Une erreur est survenue.'));
}

// Validation d'une seule série (bouton « Valider » de la ligne)
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.mu-genres-validate-btn');
    if (!btn) return;
    const block = btn.closest('.mu-genres-series');
    if (!block) return;

    const id       = block.dataset.seriesId;
    const input    = block.querySelector('.mu-genres-input');
    const feedback = block.querySelector('.mu-genres-feedback');
    const value    = input ? input.value : '';

    btn.disabled = true;
    if (feedback) { feedback.className = 'mu-genres-feedback'; feedback.textContent = 'Enregistrement…'; }

    postMuGenres({ [id]: value }, (success, saved, message) => {
        if (success) {
            block.classList.add('mu-genres-saved');
            if (input) input.disabled = true;
            if (feedback) { feedback.className = 'mu-genres-feedback is-success'; feedback.textContent = '✓ Enregistré'; }
            btn.textContent = 'Validé';
        } else {
            btn.disabled = false;
            if (feedback) { feedback.className = 'mu-genres-feedback is-error'; feedback.textContent = message || 'Erreur'; }
        }
    });
});

// Validation de toutes les séries non encore enregistrées (« Tout valider »)
function saveMuGenres() {
    const blocks = document.querySelectorAll('#mu-genres-results .mu-genres-series:not(.mu-genres-saved)');
    const payload = {};
    let count = 0;
    blocks.forEach(block => {
        const id    = block.dataset.seriesId;
        const input = block.querySelector('.mu-genres-input');
        payload[id] = input ? input.value : '';
        count++;
    });

    if (count === 0) {
        showCustomAlert('Information', 'Toutes les séries ont déjà été validées.');
        return;
    }

    const saveBtn = document.getElementById('mu-genres-save-btn');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Enregistrement...'; }

    postMuGenres(payload, (success, saved, message) => {
        if (success) {
            blocks.forEach(block => {
                block.classList.add('mu-genres-saved');
                const input = block.querySelector('.mu-genres-input');
                const btn   = block.querySelector('.mu-genres-validate-btn');
                const feedback = block.querySelector('.mu-genres-feedback');
                if (input) input.disabled = true;
                if (btn) { btn.disabled = true; btn.textContent = 'Validé'; }
                if (feedback) { feedback.className = 'mu-genres-feedback is-success'; feedback.textContent = '✓ Enregistré'; }
            });
            showSuccessModal(`${saved} série(s) mise(s) à jour.`);
            setTimeout(() => window.location.reload(), 900);
        } else {
            showErrorModal(message || "Erreur lors de l'enregistrement.");
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Tout valider'; }
        }
    });
}
