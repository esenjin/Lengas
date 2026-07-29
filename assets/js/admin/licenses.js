// ──────────────────────────────────────────────────────────────────────────────
// assets/js/admin/licenses.js
// Gestion de la page des licences : liste (grille de cartes) + modale de
// détail (renommage, séries membres triables, ajout/retrait, suppression).
// Calqué sur le fonctionnement de reviews.js (endpoint POST unique, actions
// nommées via `license_action`).
// ──────────────────────────────────────────────────────────────────────────────
(function () {
    'use strict';

    const ENDPOINT = 'page-licences.php';

    const listContainer  = document.getElementById('licenses-list');
    const searchInput    = document.getElementById('licenses-search');
    const newBtn         = document.getElementById('new-license-btn');

    const newModal        = document.getElementById('new-license-modal');
    const newNameInput     = document.getElementById('new-license-name');
    const newOkBtn         = document.getElementById('new-license-ok');
    const newCancelBtn      = document.getElementById('new-license-cancel');
    const newCloseBtn       = document.getElementById('close-new-license-modal');

    const detailModal      = document.getElementById('license-detail-modal');
    const detailNameInput  = document.getElementById('license-detail-name');
    const detailSubtitle    = document.getElementById('license-detail-subtitle');
    const detailDeleteBtn   = document.getElementById('license-detail-delete');
    const detailCloseBtn    = document.getElementById('close-license-detail-modal');
    const seriesListEl      = document.getElementById('license-series-list');

    const addInput   = document.getElementById('license-add-series-input');
    const addResults = document.getElementById('license-add-series-results');

    let currentLicenseId = null;
    let currentSeries    = []; // séries de la licence actuellement ouverte, dans l'ordre
    let renameTimer      = null;

    // ── Utilitaires ──────────────────────────────────────────────────────────
    function htmlEscape(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function normalizeString(str) {
        return String(str).toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]/g, '');
    }

    async function api(action, extra) {
        const fd = new URLSearchParams();
        fd.append('license_action', action);
        for (const k in (extra || {})) {
            const v = extra[k];
            if (Array.isArray(v)) {
                v.forEach(item => fd.append(k + '[]', item));
            } else {
                fd.append(k, v);
            }
        }
        const res = await fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        });
        return res.json();
    }

    // ── Vue liste ────────────────────────────────────────────────────────────
    async function loadList() {
        listContainer.innerHTML = '<p class="licenses-empty">Chargement…</p>';
        const data = await api('list');
        if (!data.success) {
            listContainer.innerHTML = '<p class="licenses-empty">Erreur de chargement.</p>';
            return;
        }
        renderList(data.licenses || []);
    }

    function renderList(licenses) {
        if (!licenses.length) {
            listContainer.innerHTML = '<p class="licenses-empty">Aucune licence pour le moment. 📚</p>';
            return;
        }
        listContainer.innerHTML = '';
        licenses.forEach(lic => {
            const card = document.createElement('div');
            card.className = 'license-card';
            card.dataset.licenseId = lic.id;
            const img = '../' + ((lic.thumbnail && lic.thumbnail !== '') ? htmlEscape(lic.thumbnail) : 'assets/img/logo.png');
            const p = lic.count > 1 ? 's' : '';
            card.innerHTML = `
                <button type="button" class="license-card-delete" title="Supprimer la licence" aria-label="Supprimer la licence">&times;</button>
                <img class="license-card-thumb" src="${img}" alt="" loading="lazy">
                <h3 class="license-card-title">${htmlEscape(lic.name)}</h3>
                <p class="license-card-count">${lic.count} série${p}</p>
            `;
            card.addEventListener('click', () => openDetail(lic.id));
            card.querySelector('.license-card-delete').addEventListener('click', async (e) => {
                e.stopPropagation();
                const ok = await showCustomConfirm(
                    'Confirmation',
                    `Supprimer la licence « ${lic.name} » ? Les séries qu'elle contient ne seront pas supprimées.`
                );
                if (!ok) return;
                const res = await api('delete', { license_id: lic.id });
                if (res.success) {
                    card.remove();
                    if (!listContainer.querySelector('.license-card')) {
                        listContainer.innerHTML = '<p class="licenses-empty">Aucune licence pour le moment. 📚</p>';
                    }
                } else {
                    showCustomAlert('Erreur', res.message || 'Suppression impossible.');
                }
            });
            listContainer.appendChild(card);
        });
        applyListFilter();
    }

    function applyListFilter() {
        const term = normalizeString(searchInput ? searchInput.value : '');
        listContainer.querySelectorAll('.license-card').forEach(card => {
            const title = normalizeString(card.querySelector('.license-card-title')?.textContent || '');
            card.style.display = (term === '' || title.includes(term)) ? '' : 'none';
        });
    }
    searchInput?.addEventListener('input', applyListFilter);

    // ── Modale de création ───────────────────────────────────────────────────
    function openNewModal() {
        newNameInput.value = '';
        newModal.classList.add('modal-active');
        setTimeout(() => newNameInput.focus(), 50);
    }
    function closeNewModal() { newModal.classList.remove('modal-active'); }

    newBtn?.addEventListener('click', openNewModal);
    newCancelBtn?.addEventListener('click', closeNewModal);
    newCloseBtn?.addEventListener('click', closeNewModal);
    newModal?.addEventListener('click', e => { if (e.target === newModal) closeNewModal(); });
    newNameInput?.addEventListener('keydown', e => { if (e.key === 'Enter') newOkBtn.click(); });

    newOkBtn?.addEventListener('click', async function () {
        const name = newNameInput.value.trim();
        if (name === '') { showCustomAlert('Nom manquant', 'Merci de saisir un nom de licence.'); return; }
        const res = await api('create', { name: name });
        if (res.success) {
            closeNewModal();
            await loadList();
            if (res.id) openDetail(res.id);
        } else {
            showCustomAlert('Erreur', res.message || 'Création impossible.');
        }
    });

    // ── Modale de détail ─────────────────────────────────────────────────────
    async function openDetail(licenseId) {
        currentLicenseId = licenseId;
        detailNameInput.value = '';
        detailSubtitle.textContent = '';
        seriesListEl.innerHTML = '<p class="license-series-empty">Chargement…</p>';
        addInput.value = '';
        addResults.classList.remove('is-open');
        detailModal.classList.add('modal-active');

        const data = await api('detail', { license_id: licenseId });
        if (!data.success) {
            showCustomAlert('Erreur', data.message || 'Licence introuvable.');
            detailModal.classList.remove('modal-active');
            return;
        }
        detailNameInput.value = data.license.name;
        currentSeries = data.license.series || [];
        renderSeriesList();
    }

    function closeDetail() {
        detailModal.classList.remove('modal-active');
        currentLicenseId = null;
        currentSeries = [];
    }

    detailCloseBtn?.addEventListener('click', closeDetail);
    detailModal?.addEventListener('click', e => { if (e.target === detailModal) closeDetail(); });

    function updateSubtitle() {
        const n = currentSeries.length;
        const p = n > 1 ? 's' : '';
        detailSubtitle.textContent = `${n} série${p} dans cette licence.`;
    }

    function renderSeriesList() {
        updateSubtitle();
        if (!currentSeries.length) {
            seriesListEl.innerHTML = '<p class="license-series-empty">Aucune série dans cette licence pour le moment.</p>';
            return;
        }
        seriesListEl.innerHTML = '';
        currentSeries.forEach((s, index) => {
            const row = document.createElement('div');
            row.className = 'license-series-row';
            row.dataset.seriesId = s.id;
            const img = '../' + ((s.image && s.image !== '') ? htmlEscape(s.image) : 'assets/img/logo.png');
            const typeDef = (window.seriesTypes && window.seriesTypes[s.type || 'manga']) || null;
            const typeBadge = typeDef
                ? `<span class="suggestion-type-badge" style="--type-color:${typeDef.color}">${htmlEscape(typeDef.label)}</span>`
                : '';
            row.innerHTML = `
                <img class="license-series-row-thumb" src="${img}" alt="" loading="lazy">
                <div class="license-series-row-info">
                    <p class="license-series-row-name">${htmlEscape(s.name)} ${typeBadge}</p>
                    <p class="license-series-row-meta">${htmlEscape(s.author || '')}</p>
                </div>
                <div class="license-series-row-actions">
                    <button type="button" class="license-series-move-btn" data-move="up" title="Monter" ${index === 0 ? 'disabled' : ''}>
                        <img src="https://api.iconify.design/mdi/chevron-up.svg?color=%23d4d4e8" width="18" height="18" alt="">
                    </button>
                    <button type="button" class="license-series-move-btn" data-move="down" title="Descendre" ${index === currentSeries.length - 1 ? 'disabled' : ''}>
                        <img src="https://api.iconify.design/mdi/chevron-down.svg?color=%23d4d4e8" width="18" height="18" alt="">
                    </button>
                    <button type="button" class="license-series-remove-btn" title="Retirer de la licence">
                        <img src="https://api.iconify.design/mdi/close.svg?color=%23f87171" width="18" height="18" alt="">
                    </button>
                </div>
            `;
            row.querySelector('[data-move="up"]')?.addEventListener('click', () => moveSeries(index, -1));
            row.querySelector('[data-move="down"]')?.addEventListener('click', () => moveSeries(index, 1));
            row.querySelector('.license-series-remove-btn')?.addEventListener('click', () => removeSeries(s.id, s.name));
            seriesListEl.appendChild(row);
        });
    }

    async function moveSeries(index, delta) {
        const target = index + delta;
        if (target < 0 || target >= currentSeries.length) return;
        const [item] = currentSeries.splice(index, 1);
        currentSeries.splice(target, 0, item);
        renderSeriesList();
        await persistOrder();
    }

    async function persistOrder() {
        if (!currentLicenseId) return;
        const order = currentSeries.map(s => s.id);
        const res = await api('reorder', { license_id: currentLicenseId, order: order });
        if (!res.success) {
            showCustomAlert('Erreur', res.message || "L'enregistrement de l'ordre a échoué.");
        }
    }

    async function removeSeries(seriesId, seriesName) {
        const ok = await showCustomConfirm('Confirmation', `Retirer « ${seriesName} » de cette licence ?`);
        if (!ok) return;
        const res = await api('remove_series', { license_id: currentLicenseId, series_id: seriesId });
        if (res.success) {
            currentSeries = currentSeries.filter(s => s.id !== seriesId);
            renderSeriesList();
            refreshCardCount();
        } else {
            showCustomAlert('Erreur', res.message || 'Retrait impossible.');
        }
    }

    // Met à jour le compteur affiché sur la carte de la liste, sans recharger
    // toute la grille (évite de perdre la position de scroll).
    function refreshCardCount() {
        const card = listContainer.querySelector(`.license-card[data-license-id="${cssEscape(currentLicenseId)}"]`);
        if (!card) return;
        const n = currentSeries.length;
        const countEl = card.querySelector('.license-card-count');
        if (countEl) countEl.textContent = `${n} série${n > 1 ? 's' : ''}`;
    }

    function cssEscape(s) {
        return String(s).replace(/["\\]/g, '\\$&');
    }

    // ── Renommage (auto-save, débounce) ──────────────────────────────────────
    detailNameInput?.addEventListener('input', function () {
        clearTimeout(renameTimer);
        renameTimer = setTimeout(async () => {
            const name = detailNameInput.value.trim();
            if (name === '' || !currentLicenseId) return;
            const res = await api('rename', { license_id: currentLicenseId, name: name });
            if (res.success) {
                const card = listContainer.querySelector(`.license-card[data-license-id="${cssEscape(currentLicenseId)}"] .license-card-title`);
                if (card) card.textContent = name;
            }
        }, 500);
    });

    // ── Suppression depuis la modale de détail ───────────────────────────────
    detailDeleteBtn?.addEventListener('click', async function () {
        if (!currentLicenseId) return;
        const name = detailNameInput.value.trim() || 'cette licence';
        const ok = await showCustomConfirm('Confirmation', `Supprimer la licence « ${name} » ? Les séries qu'elle contient ne seront pas supprimées.`);
        if (!ok) return;
        const res = await api('delete', { license_id: currentLicenseId });
        if (res.success) {
            closeDetail();
            await loadList();
        } else {
            showCustomAlert('Erreur', res.message || 'Suppression impossible.');
        }
    });

    // ── Ajout d'une série à la licence ───────────────────────────────────────
    let licensableCache = null;

    async function fetchLicensable() {
        const res = await api('licensable', { license_id: currentLicenseId || '' });
        licensableCache = res.success ? (res.series || []) : [];
        return licensableCache;
    }

    function renderAddResults(list) {
        if (!list.length) {
            addResults.innerHTML = '<div class="license-add-series-empty">Aucune série disponible (déjà en licence ou aucune correspondance).</div>';
            addResults.classList.add('is-open');
            return;
        }
        addResults.innerHTML = '';
        list.forEach(s => {
            const div = document.createElement('div');
            const typeDef = (window.seriesTypes && window.seriesTypes[s.type || 'manga']) || null;
            const badge = typeDef
                ? `<span class="suggestion-type-badge" style="--type-color:${typeDef.color}; float:right;">${htmlEscape(typeDef.label)}</span>`
                : '';
            div.innerHTML = `${htmlEscape(s.name)}${s.author ? ' <span style="color:var(--text-muted);font-size:0.82em;">' + htmlEscape(s.author) + '</span>' : ''}${badge}`;
            div.dataset.id = s.id;
            div.addEventListener('click', () => chooseSeries(s));
            addResults.appendChild(div);
        });
        addResults.classList.add('is-open');
    }

    async function filterAdd() {
        if (!licensableCache) await fetchLicensable();
        const term = normalizeString(addInput.value);
        const filtered = term === ''
            ? licensableCache
            : licensableCache.filter(s => normalizeString(s.name).includes(term) || normalizeString(s.author || '').includes(term));
        renderAddResults(filtered);
    }

    addInput?.addEventListener('focus', filterAdd);
    addInput?.addEventListener('input', filterAdd);
    document.addEventListener('click', e => {
        if (addInput && addResults && !addInput.contains(e.target) && !addResults.contains(e.target)) {
            addResults.classList.remove('is-open');
        }
    });

    async function chooseSeries(s) {
        const res = await api('add_series', { license_id: currentLicenseId, series_id: s.id });
        if (!res.success) {
            showCustomAlert('Erreur', res.message || 'Ajout impossible.');
            return;
        }
        currentSeries.push({
            id: s.id,
            name: s.name,
            type: s.type,
            author: s.author,
            image: ''
        });
        addInput.value = '';
        addResults.classList.remove('is-open');
        licensableCache = null; // invalide le cache : cette série n'est plus disponible
        renderSeriesList();
        refreshCardCount();
    }

    // ── Init ─────────────────────────────────────────────────────────────────
    loadList();
})();
