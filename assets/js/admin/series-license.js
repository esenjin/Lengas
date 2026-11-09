// ──────────────────────────────────────────────────────────────────────────────
// assets/js/admin/series-license.js
// Bouton « Licence » des cartes de la collection (mangas comme animés) :
// ouvre une modale qui montre la licence dont fait partie la série, ou, si
// elle n'en a aucune, permet de la rattacher à une licence existante ou à une
// nouvelle licence créée à la volée.
// S'appuie sur les actions AJAX de pages/page-licences.php (`license_action`),
// comme assets/js/admin/licenses.js.
// ──────────────────────────────────────────────────────────────────────────────
(function () {
    'use strict';

    const ENDPOINT = 'pages/page-licences.php';

    const modal      = document.getElementById('series-license-modal');
    const closeBtn   = document.getElementById('close-series-license-modal');
    const nameEl     = document.getElementById('series-license-series-name');
    const body       = document.getElementById('series-license-body');
    if (!modal || !body) return;

    let currentSeriesId   = null;
    let currentSeriesName = '';
    let busy = false;

    // ── Utilitaires ──────────────────────────────────────────────────────────
    function esc(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function normalize(str) {
        return String(str ?? '').toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]/g, '');
    }
    function imgSrc(path) {
        return esc(path && path !== '' ? path : 'assets/img/logo.png');
    }

    async function api(action, extra) {
        const fd = new URLSearchParams();
        fd.append('license_action', action);
        for (const k in (extra || {})) fd.append(k, extra[k]);
        const res = await fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        });
        return res.json();
    }

    function findSeriesName(seriesId) {
        const list = Array.isArray(window.seriesData) ? window.seriesData : Object.values(window.seriesData || {});
        const s = list.find(x => x.id === seriesId);
        if (s) return s.name;
        const card = document.querySelector(`.series-card[data-series-id="${CSS.escape(seriesId)}"] .series-info h2`);
        return card ? card.textContent.trim() : '';
    }

    // ── Ouverture / fermeture ────────────────────────────────────────────────
    async function open(seriesId) {
        currentSeriesId   = seriesId;
        currentSeriesName = findSeriesName(seriesId);
        nameEl.textContent = currentSeriesName;
        body.innerHTML = '<p class="license-series-empty">Chargement…</p>';
        modal.classList.add('modal-active');
        await load();
    }

    function close() {
        modal.classList.remove('modal-active');
        currentSeriesId = null;
    }

    closeBtn?.addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.license-series-btn');
        if (!btn) return;
        const seriesId = btn.dataset.seriesId;
        if (seriesId) open(seriesId);
    });

    async function load() {
        const seriesId = currentSeriesId;
        let data;
        try {
            data = await api('series_license', { series_id: seriesId });
        } catch (err) {
            console.error(err);
            data = { success: false };
        }
        if (seriesId !== currentSeriesId) return; // modale refermée / autre série entre-temps
        if (!data.success) {
            body.innerHTML = '<p class="license-series-empty">Erreur de chargement de la licence.</p>';
            return;
        }
        if (data.license) {
            renderCurrent(data.license);
        } else {
            renderPicker(data.licenses || []);
        }
    }

    // ── État 1 : la série appartient déjà à une licence ─────────────────────
    function renderCurrent(license) {
        const members = license.series || [];
        const n = members.length;
        const rows = members.map(s => {
            const typeDef = (window.seriesTypes && window.seriesTypes[s.type || 'manga']) || null;
            const badge = typeDef
                ? `<span class="suggestion-type-badge" style="--type-color:${esc(typeDef.color)}">${esc(typeDef.label)}</span>`
                : '';
            const isCurrent = s.id === currentSeriesId;
            return `
                <div class="license-series-row${isCurrent ? ' is-current' : ''}">
                    <img class="license-series-row-thumb" src="${imgSrc(s.image)}" alt="" loading="lazy">
                    <div class="license-series-row-info">
                        <p class="license-series-row-name">${esc(s.name)} ${badge}</p>
                        <p class="license-series-row-meta">${esc(s.author || '')}</p>
                    </div>
                    ${isCurrent ? '<span class="series-license-here">cette série</span>' : ''}
                </div>`;
        }).join('');

        body.innerHTML = `
            <div class="series-license-current">
                <div class="series-license-current-head">
                    <span class="series-license-icon" aria-hidden="true">🔖</span>
                    <div>
                        <h3 class="series-license-current-name">${esc(license.name)}</h3>
                        <p class="series-license-current-count">${n} série${n > 1 ? 's' : ''} dans cette licence</p>
                    </div>
                </div>
                <div class="series-license-members">${rows}</div>
                <div class="modal-actions">
                    <a class="button button-ats" href="pages/page-licences.php?license=${encodeURIComponent(license.id)}">Gérer la licence</a>
                    <button type="button" class="button delete-btn" data-action="remove">Retirer de cette licence</button>
                </div>
            </div>`;

        body.querySelector('[data-action="remove"]').addEventListener('click', async () => {
            if (busy) return;
            const ok = await showCustomConfirm('Confirmation', `Retirer « ${currentSeriesName} » de la licence « ${license.name} » ?`);
            if (!ok) return;
            busy = true;
            try {
                const res = await api('remove_series', { license_id: license.id, series_id: currentSeriesId });
                if (!res.success) {
                    showCustomAlert('Erreur', res.message || 'Retrait impossible.');
                    return;
                }
                await load();
            } finally {
                busy = false;
            }
        });
    }

    // ── État 2 : aucune licence → choisir une existante ou en créer une ─────
    function renderPicker(licenses) {
        body.innerHTML = `
            <p class="series-license-none">Cette série n'appartient à aucune licence pour le moment.</p>

            <h3 class="series-license-section-title">Ajouter à une licence existante</h3>
            ${licenses.length ? `
                <input type="text" class="series-license-search" placeholder="Filtrer les licences…" autocomplete="off">
                <div class="series-license-choices"></div>
            ` : '<p class="series-license-hint">Aucune licence n\'existe encore : créez-en une ci-dessous.</p>'}

            <h3 class="series-license-section-title">${licenses.length ? 'Ou créer une nouvelle licence' : 'Créer une nouvelle licence'}</h3>
            <form class="series-license-create">
                <label class="sr-only" for="series-license-new-name">Nom de la nouvelle licence</label>
                <input type="text" id="series-license-new-name" maxlength="120" autocomplete="off"
                       placeholder="Nom de la licence (ex. « Frieren »)" value="${esc(currentSeriesName)}">
                <button type="submit" class="button button-aos">Créer et ajouter</button>
            </form>`;

        const search  = body.querySelector('.series-license-search');
        const choices = body.querySelector('.series-license-choices');

        function renderChoices() {
            if (!choices) return;
            const term = normalize(search.value);
            const filtered = term === ''
                ? licenses
                : licenses.filter(l => normalize(l.name).includes(term)
                    || (l.series_names || []).some(n => normalize(n).includes(term)));
            if (!filtered.length) {
                choices.innerHTML = '<p class="series-license-hint">Aucune licence ne correspond.</p>';
                return;
            }
            choices.innerHTML = filtered.map(l => `
                <button type="button" class="series-license-choice" data-license-id="${esc(l.id)}">
                    <img class="license-series-row-thumb" src="${imgSrc(l.thumbnail)}" alt="" loading="lazy">
                    <span class="license-series-row-info">
                        <span class="license-series-row-name">${esc(l.name)}</span>
                        <span class="license-series-row-meta">${l.count} série${l.count > 1 ? 's' : ''}${(l.series_names || []).length ? ' · ' + esc(l.series_names.slice(0, 3).join(', ')) + (l.series_names.length > 3 ? '…' : '') : ''}</span>
                    </span>
                    <span class="series-license-choice-add">+ Ajouter</span>
                </button>`).join('');
        }

        search?.addEventListener('input', renderChoices);
        renderChoices();

        choices?.addEventListener('click', async e => {
            const btn = e.target.closest('.series-license-choice');
            if (!btn || busy) return;
            busy = true;
            btn.disabled = true;
            try {
                const res = await api('add_series', { license_id: btn.dataset.licenseId, series_id: currentSeriesId });
                if (!res.success) {
                    showCustomAlert('Erreur', res.message || 'Ajout impossible.');
                    btn.disabled = false;
                    return;
                }
                await load();
            } finally {
                busy = false;
            }
        });

        const form = body.querySelector('.series-license-create');
        form.addEventListener('submit', async e => {
            e.preventDefault();
            if (busy) return;
            const input = form.querySelector('input');
            const name = input.value.trim();
            if (name === '') {
                showCustomAlert('Nom manquant', 'Merci de saisir un nom pour la licence.');
                return;
            }
            busy = true;
            const submit = form.querySelector('button[type="submit"]');
            submit.disabled = true;
            try {
                const res = await api('create_with_series', { name: name, series_id: currentSeriesId });
                if (!res.success) {
                    showCustomAlert('Erreur', res.message || 'Création impossible.');
                    return;
                }
                await load();
            } finally {
                busy = false;
                submit.disabled = false;
            }
        });
    }
})();
