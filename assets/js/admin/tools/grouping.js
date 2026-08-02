// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/grouping.js — Outil « Groupage de licences »
//
// Analyse locale (pas de flux SSE : aucun appel réseau côté serveur) des
// séries sans licence, affichage des groupes suggérés avec un score, et
// validation groupe par groupe (créer une licence / ajouter à une licence
// existante), avec possibilité d'exclure une série d'un groupe avant
// validation.
// ──────────────────────────────────────────────────────────────────────────

function grpEscHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function grpEscAttr(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Libellé de type affichable (badge court)
function grpTypeLabel(type) {
    return type === 'anime' ? 'Animé' : 'Manga';
}

// ── Slider de seuil ──────────────────────────────────────────────────────────
document.addEventListener('input', (e) => {
    if (e.target.id !== 'grouping-threshold') return;
    const valueEl = document.getElementById('grouping-threshold-value');
    if (valueEl) valueEl.textContent = e.target.value;
});

// ── Lancement de l'analyse ────────────────────────────────────────────────────
document.addEventListener('click', (e) => {
    if (e.target.closest('#grouping-analyze-btn')) {
        runGroupingAnalysis();
    }
});

function runGroupingAnalysis() {
    const btn      = document.getElementById('grouping-analyze-btn');
    const textEl   = document.getElementById('grouping-analyze-text');
    const spinner  = document.getElementById('grouping-analyze-spinner');
    const results  = document.getElementById('grouping-results');
    const slider   = document.getElementById('grouping-threshold');
    if (!results) return;

    const threshold = slider ? slider.value : 50;

    if (btn) btn.disabled = true;
    if (textEl) textEl.textContent = 'Analyse en cours...';
    if (spinner) spinner.style.display = 'inline-block';
    results.innerHTML = '';

    fetch(`outil-groupage-licences.php?action=analyze&threshold=${encodeURIComponent(threshold)}`)
        .then(r => r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (textEl) textEl.textContent = 'Relancer l\'analyse';
            if (spinner) spinner.style.display = 'none';

            if (!data.success) {
                results.innerHTML = '<p class="error-text">Une erreur est survenue pendant l\'analyse.</p>';
                return;
            }
            renderGroupingResults(data.groups || []);
        })
        .catch(() => {
            if (btn) btn.disabled = false;
            if (textEl) textEl.textContent = 'Analyser';
            if (spinner) spinner.style.display = 'none';
            results.innerHTML = '<p class="error-text">La recherche a été interrompue. Veuillez réessayer.</p>';
        });
}

function renderGroupingResults(groups) {
    const results = document.getElementById('grouping-results');
    if (!results) return;

    if (groups.length === 0) {
        results.innerHTML = '<p class="mu-associate-empty">Aucun regroupement suggéré à ce seuil. ✅</p>';
        return;
    }

    let html = '';
    groups.forEach((group, idx) => {
        const seriesIds = group.series.map(s => s.id).join(',');
        const isExisting = group.type === 'existing';

        html += `<div class="grouping-group${isExisting ? ' grouping-group--existing' : ''}" data-group-index="${idx}" data-series-ids="${grpEscAttr(seriesIds)}"${isExisting ? ` data-license-id="${grpEscAttr(group.license_id)}" data-license-name="${grpEscAttr(group.license_name)}"` : ''}>
            <div class="grouping-group-header">
                <span class="grouping-group-score">${grpEscHtml(group.score)}%</span>
                ${isExisting
                    ? `<button type="button" class="grouping-group-license-badge" data-license-id="${grpEscAttr(group.license_id)}">📚 ${grpEscHtml(group.license_name)}</button>`
                    : `<span class="grouping-group-count">${group.series.length} séries</span>`}
            </div>
            <div class="grouping-group-series">`;

        group.series.forEach(s => {
            html += `<div class="grouping-series-item">
                <label class="grouping-series-checkbox-wrap">
                    <input type="checkbox" class="grouping-series-checkbox" value="${grpEscAttr(s.id)}" checked>
                </label>
                <button type="button" class="grouping-series-info" data-series-id="${grpEscAttr(s.id)}">
                    <span class="grouping-series-type grouping-series-type--${grpEscAttr(s.type)}">${grpTypeLabel(s.type)}</span>
                    <span class="grouping-series-name">${grpEscHtml(s.name)}</span>
                    ${s.detail ? `<span class="grouping-series-detail">${grpEscHtml(s.detail)}</span>` : ''}
                </button>
            </div>`;
        });

        html += `</div>
            <div class="grouping-group-actions">`;

        if (isExisting) {
            html += `<button type="button" class="button button-sm button-ats grouping-add-existing-btn">Ajouter à la licence ${grpEscHtml(group.license_name)}</button>
                <button type="button" class="button button-sm button-opt grouping-create-btn">Créer une licence</button>
                <button type="button" class="button button-sm button-opt grouping-add-btn">Ajouter à une autre licence</button>`;
        } else {
            html += `<button type="button" class="button button-sm button-ats grouping-create-btn">Créer une licence</button>
                <button type="button" class="button button-sm button-opt grouping-add-btn">Ajouter à une licence existante</button>`;
        }

        html += `<button type="button" class="button button-sm grouping-dismiss-btn">Ignorer</button>
            </div>
        </div>`;
    });

    results.innerHTML = html;
}

// Séries actuellement cochées dans un groupe donné
function grpCheckedSeriesIds(groupEl) {
    return Array.from(groupEl.querySelectorAll('.grouping-series-checkbox:checked')).map(cb => cb.value);
}

// Ignorer un groupe (le retire simplement de l'affichage, rien de persisté)
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.grouping-dismiss-btn');
    if (!btn) return;
    const group = btn.closest('.grouping-group');
    if (group) group.remove();
});

// ── Ajout direct à la licence détectée automatiquement (bouton principal ───
// des groupes de type « existing ») : pas de modale, la licence est déjà
// connue via data-license-id sur le conteneur du groupe.
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.grouping-add-existing-btn');
    if (!btn) return;
    const group = btn.closest('.grouping-group');
    if (!group) return;

    const ids = grpCheckedSeriesIds(group);
    if (ids.length === 0) {
        showCustomAlert('Information', 'Sélectionnez au moins une série.');
        return;
    }

    const licenseId   = group.dataset.licenseId || '';
    const licenseName = group.dataset.licenseName || '';
    if (!licenseId) return;

    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Ajout...';

    const params = new URLSearchParams();
    params.set('tool_action', 'add_to_existing');
    params.set('license_id', licenseId);
    ids.forEach(id => params.append('series_ids[]', id));

    fetch('outil-groupage-licences.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            grpRemoveGroupBySeriesIds(ids);
            showSuccessModal(data.message || `Séries ajoutées à la licence ${licenseName}.`);
        } else {
            btn.disabled = false;
            btn.textContent = originalText;
            showCustomAlert('Erreur', data.message || "Erreur lors de l'ajout.");
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = originalText;
        showCustomAlert('Erreur', 'Une erreur est survenue.');
    });
});

// ── Modale « Créer une licence » ─────────────────────────────────────────────
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.grouping-create-btn');
    if (!btn) return;
    const group = btn.closest('.grouping-group');
    if (!group) return;

    const ids = grpCheckedSeriesIds(group);
    if (ids.length === 0) {
        showCustomAlert('Information', 'Sélectionnez au moins une série.');
        return;
    }

    const names = Array.from(group.querySelectorAll('.grouping-series-checkbox:checked'))
        .map(cb => cb.closest('.grouping-series-item').querySelector('.grouping-series-name').textContent);

    const modal    = document.getElementById('grouping-create-modal');
    const idsInput = document.getElementById('grouping-create-series-ids');
    const nameInput = document.getElementById('grouping-create-name-input');
    const listEl   = document.getElementById('grouping-create-series-list');
    const feedback = document.getElementById('grouping-create-feedback');

    if (idsInput) idsInput.value = ids.join(',');
    if (nameInput) nameInput.value = names[0] || '';
    if (listEl) listEl.innerHTML = names.map(n => `<div>${grpEscHtml(n)}</div>`).join('');
    if (feedback) feedback.textContent = '';
    if (modal) modal.classList.add('modal-active');
});

document.addEventListener('click', (e) => {
    if (e.target.closest('#close-grouping-create-modal')) {
        document.getElementById('grouping-create-modal')?.classList.remove('modal-active');
    }
    if (e.target.id === 'grouping-create-modal') {
        document.getElementById('grouping-create-modal')?.classList.remove('modal-active');
    }
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('#grouping-create-save-btn')) return;

    const idsInput  = document.getElementById('grouping-create-series-ids');
    const nameInput = document.getElementById('grouping-create-name-input');
    const feedback  = document.getElementById('grouping-create-feedback');
    const saveBtn   = document.getElementById('grouping-create-save-btn');
    const saveText  = document.getElementById('grouping-create-save-text');
    const spinner   = document.getElementById('grouping-create-save-spinner');

    const name = nameInput ? nameInput.value.trim() : '';
    const ids  = idsInput ? idsInput.value.split(',').filter(Boolean) : [];

    if (name === '') {
        if (feedback) { feedback.className = 'cedit-feedback is-error'; feedback.textContent = 'Le nom de la licence est requis.'; }
        return;
    }

    if (saveBtn) saveBtn.disabled = true;
    if (saveText) saveText.textContent = 'Création...';
    if (spinner) spinner.style.display = 'inline-block';

    const params = new URLSearchParams();
    params.set('tool_action', 'create_from_group');
    params.set('name', name);
    ids.forEach(id => params.append('series_ids[]', id));

    fetch('outil-groupage-licences.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (saveBtn) saveBtn.disabled = false;
        if (saveText) saveText.textContent = 'Créer';
        if (spinner) spinner.style.display = 'none';

        if (data.success) {
            document.getElementById('grouping-create-modal')?.classList.remove('modal-active');
            grpRemoveGroupBySeriesIds(ids);
            showSuccessModal(data.message || 'Licence créée.');
        } else {
            if (feedback) { feedback.className = 'cedit-feedback is-error'; feedback.textContent = data.message || 'Erreur lors de la création.'; }
        }
    })
    .catch(() => {
        if (saveBtn) saveBtn.disabled = false;
        if (saveText) saveText.textContent = 'Créer';
        if (spinner) spinner.style.display = 'none';
        if (feedback) { feedback.className = 'cedit-feedback is-error'; feedback.textContent = 'Une erreur est survenue.'; }
    });
});

// ── Modale « Ajouter à une licence existante » ───────────────────────────────
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.grouping-add-btn');
    if (!btn) return;
    const group = btn.closest('.grouping-group');
    if (!group) return;

    const ids = grpCheckedSeriesIds(group);
    if (ids.length === 0) {
        showCustomAlert('Information', 'Sélectionnez au moins une série.');
        return;
    }

    const names = Array.from(group.querySelectorAll('.grouping-series-checkbox:checked'))
        .map(cb => cb.closest('.grouping-series-item').querySelector('.grouping-series-name').textContent);

    const modal     = document.getElementById('grouping-add-modal');
    const idsInput  = document.getElementById('grouping-add-series-ids');
    const listEl    = document.getElementById('grouping-add-series-list');
    const selectEl  = document.getElementById('grouping-add-license-select');
    const feedback  = document.getElementById('grouping-add-feedback');

    if (idsInput) idsInput.value = ids.join(',');
    if (listEl) listEl.innerHTML = names.map(n => `<div>${grpEscHtml(n)}</div>`).join('');
    if (feedback) feedback.textContent = '';
    if (selectEl) selectEl.innerHTML = '<option value="">Chargement…</option>';
    if (modal) modal.classList.add('modal-active');

    fetch('outil-groupage-licences.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'tool_action=list_licenses'
    })
    .then(r => r.json())
    .then(data => {
        if (!selectEl) return;
        const licenses = (data.success && data.licenses) ? data.licenses : [];
        if (licenses.length === 0) {
            selectEl.innerHTML = '<option value="">Aucune licence existante</option>';
            return;
        }
        selectEl.innerHTML = licenses.map(l =>
            `<option value="${grpEscAttr(l.id)}">${grpEscHtml(l.name)} (${l.count})</option>`
        ).join('');
    })
    .catch(() => {
        if (selectEl) selectEl.innerHTML = '<option value="">Erreur de chargement</option>';
    });
});

document.addEventListener('click', (e) => {
    if (e.target.closest('#close-grouping-add-modal')) {
        document.getElementById('grouping-add-modal')?.classList.remove('modal-active');
    }
    if (e.target.id === 'grouping-add-modal') {
        document.getElementById('grouping-add-modal')?.classList.remove('modal-active');
    }
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('#grouping-add-save-btn')) return;

    const idsInput = document.getElementById('grouping-add-series-ids');
    const selectEl = document.getElementById('grouping-add-license-select');
    const feedback = document.getElementById('grouping-add-feedback');
    const saveBtn  = document.getElementById('grouping-add-save-btn');
    const saveText = document.getElementById('grouping-add-save-text');
    const spinner  = document.getElementById('grouping-add-save-spinner');

    const licenseId = selectEl ? selectEl.value : '';
    const ids = idsInput ? idsInput.value.split(',').filter(Boolean) : [];

    if (!licenseId) {
        if (feedback) { feedback.className = 'cedit-feedback is-error'; feedback.textContent = 'Choisissez une licence.'; }
        return;
    }

    if (saveBtn) saveBtn.disabled = true;
    if (saveText) saveText.textContent = 'Ajout...';
    if (spinner) spinner.style.display = 'inline-block';

    const params = new URLSearchParams();
    params.set('tool_action', 'add_to_existing');
    params.set('license_id', licenseId);
    ids.forEach(id => params.append('series_ids[]', id));

    fetch('outil-groupage-licences.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (saveBtn) saveBtn.disabled = false;
        if (saveText) saveText.textContent = 'Ajouter';
        if (spinner) spinner.style.display = 'none';

        if (data.success) {
            document.getElementById('grouping-add-modal')?.classList.remove('modal-active');
            grpRemoveGroupBySeriesIds(ids);
            showSuccessModal(data.message || 'Séries ajoutées.');
        } else {
            if (feedback) { feedback.className = 'cedit-feedback is-error'; feedback.textContent = data.message || "Erreur lors de l'ajout."; }
        }
    })
    .catch(() => {
        if (saveBtn) saveBtn.disabled = false;
        if (saveText) saveText.textContent = 'Ajouter';
        if (spinner) spinner.style.display = 'none';
        if (feedback) { feedback.className = 'cedit-feedback is-error'; feedback.textContent = 'Une erreur est survenue.'; }
    });
});

// Retire de l'affichage tout groupe dont au moins une série vient d'être
// rattachée à une licence (elle ne peut plus appartenir qu'à celle-ci : le
// reste du groupe, s'il existe encore, sera reproposé à la prochaine analyse
// sans cette série déjà traitée).
function grpRemoveGroupBySeriesIds(ids) {
    document.querySelectorAll('.grouping-group').forEach(group => {
        const groupIds = (group.dataset.seriesIds || '').split(',');
        if (ids.some(id => groupIds.includes(id))) {
            group.remove();
        }
    });
}

// ── Modale de détail léger d'une série (lecture seule) ───────────────────────
// Ouverte au clic sur la zone d'infos d'une ligne série (le nom, le badge de
// type…), jamais sur la case à cocher elle-même : cliquer la case coche/
// décoche sans ouvrir la modale, cliquer le reste de la ligne consulte les
// détails sans changer la sélection.
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.grouping-series-info');
    if (!btn) return;

    const seriesId = btn.dataset.seriesId;
    if (!seriesId) return;

    const modal = document.getElementById('grouping-series-detail-modal');
    const body  = document.getElementById('grouping-series-detail-body');
    if (!modal || !body) return;

    body.innerHTML = '<p class="grouping-series-detail-loading">Chargement…</p>';
    modal.classList.add('modal-active');

    fetch(`outil-groupage-licences.php?action=series_detail&series_id=${encodeURIComponent(seriesId)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.series) {
                body.innerHTML = '<p class="error-text">Série introuvable.</p>';
                return;
            }
            renderSeriesDetailBody(data.series);
        })
        .catch(() => {
            body.innerHTML = '<p class="error-text">Une erreur est survenue.</p>';
        });
});

function renderSeriesDetailBody(s) {
    const body = document.getElementById('grouping-series-detail-body');
    if (!body) return;

    const typeLabel = grpTypeLabel(s.type);
    const detailLabel = s.type === 'anime' ? 'Studio' : 'Auteur';

    let html = '';
    if (s.thumbnail) {
        html += `<img class="grouping-series-detail-thumb" src="../../${grpEscAttr(s.thumbnail)}" alt="">`;
    }
    html += `<h2 class="grouping-series-detail-title">${grpEscHtml(s.name)}</h2>`;
    html += `<span class="grouping-series-type grouping-series-type--${grpEscAttr(s.type)}">${typeLabel}</span>`;

    html += `<div class="grouping-series-detail-fields">`;
    if (s.detail) {
        html += `<div class="grouping-series-detail-field"><span class="grouping-series-detail-label">${detailLabel}</span><span>${grpEscHtml(s.detail)}</span></div>`;
    }
    if (s.categories && s.categories.length) {
        html += `<div class="grouping-series-detail-field"><span class="grouping-series-detail-label">Catégories</span><span>${grpEscHtml(s.categories.join(', '))}</span></div>`;
    }
    html += `<div class="grouping-series-detail-field"><span class="grouping-series-detail-label">Licence</span><span>${s.license_name ? grpEscHtml(s.license_name) : 'Aucune'}</span></div>`;
    html += `</div>`;

    const badges = [];
    if (s.mangaupdates_url) badges.push(`<a href="${grpEscAttr(s.mangaupdates_url)}" target="_blank" rel="noopener" class="mu-badge" title="Voir la fiche sur MangaUpdates"><img src="../../assets/img/mulogo.png" alt="MangaUpdates" class="mu-logo"></a>`);
    if (s.babelio_url) badges.push(`<a href="${grpEscAttr(s.babelio_url)}" target="_blank" rel="noopener" class="babelio-badge" title="Voir la fiche sur Babelio"><img src="../../assets/img/babelogo.png" alt="Babelio" class="babelio-logo"></a>`);
    if (s.anilist_url) badges.push(`<a href="${grpEscAttr(s.anilist_url)}" target="_blank" rel="noopener" class="anilist-badge" title="Voir la fiche sur Anilist"><img src="../../assets/img/anilogo.png" alt="Anilist" class="anilist-logo"></a>`);
    if (badges.length) {
        html += `<div class="grouping-series-detail-badges">${badges.join('')}</div>`;
    }

    body.innerHTML = html;
}

document.addEventListener('click', (e) => {
    if (e.target.closest('#close-grouping-series-detail-modal')) {
        document.getElementById('grouping-series-detail-modal')?.classList.remove('modal-active');
    }
    if (e.target.id === 'grouping-series-detail-modal') {
        document.getElementById('grouping-series-detail-modal')?.classList.remove('modal-active');
    }
});

// ── Modale de détail léger d'une licence existante (lecture seule) ───────────
// Ouverte au clic sur le badge « 📚 Nom de la licence » d'un groupe de type
// « existing ». Affiche la liste des séries déjà membres de cette licence,
// pour se faire une idée avant de décider d'y ajouter le groupe suggéré.
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.grouping-group-license-badge');
    if (!btn) return;

    const licenseId = btn.dataset.licenseId;
    if (!licenseId) return;

    const modal = document.getElementById('grouping-license-detail-modal');
    const body  = document.getElementById('grouping-license-detail-body');
    if (!modal || !body) return;

    body.innerHTML = '<p class="grouping-series-detail-loading">Chargement…</p>';
    modal.classList.add('modal-active');

    fetch(`outil-groupage-licences.php?action=license_detail&license_id=${encodeURIComponent(licenseId)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.license) {
                body.innerHTML = '<p class="error-text">Licence introuvable.</p>';
                return;
            }
            renderLicenseDetailBody(data.license);
        })
        .catch(() => {
            body.innerHTML = '<p class="error-text">Une erreur est survenue.</p>';
        });
});

function renderLicenseDetailBody(lic) {
    const body = document.getElementById('grouping-license-detail-body');
    if (!body) return;

    let html = `<h2 class="grouping-series-detail-title">📚 ${grpEscHtml(lic.name)}</h2>`;
    html += `<p class="grouping-license-detail-count">${lic.series.length} série${lic.series.length > 1 ? 's' : ''} déjà incluse${lic.series.length > 1 ? 's' : ''}</p>`;

    if (lic.series.length) {
        html += `<div class="grouping-license-detail-series-list">`;
        lic.series.forEach(s => {
            html += `<div class="grouping-license-detail-series-item">
                <span class="grouping-series-type grouping-series-type--${grpEscAttr(s.type)}">${grpTypeLabel(s.type)}</span>
                <span class="grouping-series-name">${grpEscHtml(s.name)}</span>
            </div>`;
        });
        html += `</div>`;
    } else {
        html += `<p class="grouping-series-detail-loading">Aucune série pour l'instant.</p>`;
    }

    body.innerHTML = html;
}

document.addEventListener('click', (e) => {
    if (e.target.closest('#close-grouping-license-detail-modal')) {
        document.getElementById('grouping-license-detail-modal')?.classList.remove('modal-active');
    }
    if (e.target.id === 'grouping-license-detail-modal') {
        document.getElementById('grouping-license-detail-modal')?.classList.remove('modal-active');
    }
});
