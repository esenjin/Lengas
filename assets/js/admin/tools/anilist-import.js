// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/anilist-import.js — Outil « Import Anilist » (V4 bloc 8)
//
// Déroulement en deux temps, strictement séparés :
//   1. Récupération + aperçu (SSE) : aucune écriture serveur, seul l'aperçu
//      est persisté côté serveur pour survivre à un rechargement de page.
//   2. Import (SSE), après réglages et validation explicite.
//
// Au chargement de la page, on vérifie si un aperçu est déjà en attente
// (reprise après fermeture d'onglet) et on l'affiche directement le cas
// échéant, sans repasser par l'étape de saisie du pseudo.
// ──────────────────────────────────────────────────────────────────────────

(function () {
    'use strict';

    const panel = document.querySelector('[data-tab-panel="anilist-import"]');
    if (!panel) return;

    // ── Éléments ──────────────────────────────────────────────────────────
    const stepUsername = document.getElementById('anilist-import-step-username');
    const stepPreview   = document.getElementById('anilist-import-step-preview');
    const stepRunning   = document.getElementById('anilist-import-step-running');
    const stepDone      = document.getElementById('anilist-import-step-done');

    const usernameInput = document.getElementById('anilist-import-username');
    const fetchBtn       = document.getElementById('anilist-import-fetch-btn');
    const fetchText      = document.getElementById('anilist-import-fetch-text');
    const fetchSpinner   = document.getElementById('anilist-import-fetch-spinner');
    const fetchProgress  = document.getElementById('anilist-import-fetch-progress');
    const fetchFeedback  = document.getElementById('anilist-import-fetch-feedback');

    const destCountsEl   = document.getElementById('anilist-import-dest-counts');
    const durationEl     = document.getElementById('anilist-import-duration');
    const favouriteBox   = document.getElementById('anilist-import-favourite-options');
    const statusBox      = document.getElementById('anilist-import-status-options');
    const formatBox      = document.getElementById('anilist-import-format-options');
    const estimatedCountEl = document.getElementById('anilist-import-estimated-count');
    const searchInput    = document.getElementById('anilist-import-search-input');
    const resetNotice     = document.getElementById('anilist-import-reset-notice');
    const groupsEl        = document.getElementById('anilist-import-groups');

    const launchBtn      = document.getElementById('anilist-import-launch-btn');
    const launchText     = document.getElementById('anilist-import-launch-text');
    const launchSpinner  = document.getElementById('anilist-import-launch-spinner');
    const discardBtn     = document.getElementById('anilist-import-discard-btn');
    const restartBtn     = document.getElementById('anilist-import-restart-btn');

    const runProgressEl  = document.getElementById('anilist-import-run-progress');
    const runResultsEl   = document.getElementById('anilist-import-run-results');

    // ── État local ────────────────────────────────────────────────────────
    // `preview` : aperçu complet renvoyé par le serveur (toutes les entrées).
    // `excludedIds` : décochages individuels (Set d'anilist_id).
    let preview        = null;
    let excludedIds    = new Set();
    let currentEs       = null; // EventSource actif, pour pouvoir l'annuler

    // Libellés lisibles des formats (alignés sur includes/anilist.php).
    const FORMAT_LABELS = {
        TV: 'Série TV', TV_SHORT: 'Format court', MOVIE: 'Film',
        SPECIAL: 'Spécial', OVA: 'OAV', ONA: 'ONA', MUSIC: 'Clip musical',
    };
    const STATUS_LABELS = {
        CURRENT: 'En cours de visionnage', COMPLETED: 'Terminées',
        REPEATING: 'En revisionnage', PAUSED: 'En pause',
        DROPPED: 'Abandonnées', PLANNING: 'En projet',
    };

    // ── Utilitaires ───────────────────────────────────────────────────────

    function esc(str) {
        return String(str ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function showStep(step) {
        [stepUsername, stepPreview, stepRunning, stepDone].forEach(el => {
            if (el) el.style.display = (el === step) ? '' : 'none';
        });
    }

    function normalizeForFilter(str) {
        return (str || '').toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu, '');
    }

    function post(params) {
        return fetch('page-outils.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params).toString()
        }).then(r => r.json());
    }

    // ── Étape 1 : récupération de la liste (SSE) ─────────────────────────

    function launchFetch() {
        const username = (usernameInput.value || '').trim();
        if (username === '') {
            fetchFeedback.textContent = 'Veuillez saisir un pseudo Anilist.';
            fetchFeedback.className = 'anilist-import-feedback is-error';
            return;
        }

        fetchBtn.disabled = true;
        fetchText.style.display = 'none';
        fetchSpinner.style.display = '';
        fetchFeedback.textContent = '';
        fetchFeedback.className = 'anilist-import-feedback';
        fetchProgress.innerHTML =
            `<p class="analysis-progress"><span class="progress-spinner"></span>` +
            `Récupération de la liste de « ${esc(username)} »…</p>`;

        const url = 'page-outils.php?action=anilist_import_preview_stream&username=' + encodeURIComponent(username);
        const es = new EventSource(url);
        currentEs = es;

        es.addEventListener('progress', e => {
            const d = JSON.parse(e.data);
            fetchProgress.innerHTML =
                `<p class="analysis-progress"><span class="progress-spinner"></span>${esc(d.message || 'Récupération…')}</p>`;
        });

        es.addEventListener('done', e => {
            es.close();
            currentEs = null;
            fetchBtn.disabled = false;
            fetchText.style.display = '';
            fetchSpinner.style.display = 'none';
            fetchProgress.innerHTML = '';

            const d = JSON.parse(e.data);
            if (!d.success) {
                fetchFeedback.textContent = d.message || "Impossible de récupérer la liste Anilist.";
                fetchFeedback.className = 'anilist-import-feedback is-error';
                return;
            }
            openPreview(d);
        });

        es.onerror = () => {
            es.close();
            currentEs = null;
            fetchBtn.disabled = false;
            fetchText.style.display = '';
            fetchSpinner.style.display = 'none';
            fetchProgress.innerHTML = '';
            fetchFeedback.textContent = 'La connexion avec le serveur a été interrompue. Réessayez.';
            fetchFeedback.className = 'anilist-import-feedback is-error';
        };
    }

    fetchBtn?.addEventListener('click', launchFetch);
    usernameInput?.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); launchFetch(); }
    });

    // ── Reprise après rechargement de page ───────────────────────────────

    function checkExistingState() {
        fetch('page-outils.php?action=anilist_import_state')
            .then(r => r.json())
            .then(d => {
                if (d.success && d.has_state) {
                    openPreview(d);
                }
            })
            .catch(() => { /* pas grave : l'utilisateur repartira d'une saisie */ });
    }

    // ── Étape 2 : aperçu ──────────────────────────────────────────────────

    function openPreview(data) {
        preview = data;
        excludedIds = new Set();

        renderDestCounts();
        renderFavouriteOptions();
        renderStatusOptions();
        renderFormatOptions();
        renderGroups();
        updateEstimatedCount();

        showStep(stepPreview);
    }

    function renderDestCounts() {
        const c = preview.counts_by_destination || {};
        const parts = [
            ['library', 'Vidéothèque', c.library || 0],
            ['wishlist', "Liste d'envies", c.wishlist || 0],
            ['existing', 'Déjà présentes', c.existing || 0],
            ['error', 'En erreur', c.error || 0],
        ];
        destCountsEl.innerHTML = parts.map(([key, label, n]) => `
            <div class="anilist-import-dest-count anilist-import-dest-count--${key}">
                <span class="anilist-import-dest-count-value">${n}</span>
                <span class="anilist-import-dest-count-label">${esc(label)}</span>
            </div>
        `).join('');

        durationEl.textContent = preview.total
            ? `${preview.total} série${preview.total > 1 ? 's' : ''} récupérée${preview.total > 1 ? 's' : ''} au total. Durée estimée d'un import complet : ${preview.estimated_duration_label || '?'}.`
            : '';
    }

    function renderFavouriteOptions() {
        const lists = preview.custom_lists || [];
        const favourites = preview.favourites || [];

        let html = '';
        lists.forEach(name => {
            html += `
                <label class="anilist-import-checkbox-option">
                    <input type="checkbox" class="anilist-import-fav-list" value="${esc(name)}">
                    ${esc(name)}
                </label>`;
        });
        html += `
            <label class="anilist-import-checkbox-option">
                <input type="checkbox" id="anilist-import-fav-native" ${favourites.length ? '' : 'disabled'}>
                Favoris natifs Anilist (cœurs)${favourites.length ? ` — ${favourites.length}` : ' — aucun'}
            </label>`;

        if (!lists.length && !favourites.length) {
            html += `<p class="hint">Aucune liste personnalisée ni favori natif détecté sur ce compte.</p>`;
        }

        favouriteBox.innerHTML = html;
        favouriteBox.querySelectorAll('input').forEach(el => {
            el.addEventListener('change', () => { resetExclusions(); renderGroups(); updateEstimatedCount(); });
        });
    }

    function renderStatusOptions() {
        const counts = preview.counts_by_status || {};
        const keys = Object.keys(STATUS_LABELS);
        statusBox.innerHTML = keys.map(key => `
            <label class="anilist-import-checkbox-option">
                <input type="checkbox" class="anilist-import-status-cb" value="${key}" checked>
                ${esc(STATUS_LABELS[key])} <span class="anilist-import-count-badge">${counts[key] || 0}</span>
            </label>
        `).join('');
        statusBox.querySelectorAll('input').forEach(el => {
            el.addEventListener('change', () => { resetExclusions(); renderGroups(); updateEstimatedCount(); });
        });
    }

    function renderFormatOptions() {
        const counts = preview.counts_by_format || {};
        const keys = Object.keys(FORMAT_LABELS);
        formatBox.innerHTML = keys.map(key => `
            <label class="anilist-import-checkbox-option">
                <input type="checkbox" class="anilist-import-format-cb" value="${key}" ${key === 'MUSIC' ? '' : 'checked'}>
                ${esc(FORMAT_LABELS[key])} <span class="anilist-import-count-badge">${counts[key] || 0}</span>
            </label>
        `).join('');
        formatBox.querySelectorAll('input').forEach(el => {
            el.addEventListener('change', () => { resetExclusions(); renderGroups(); updateEstimatedCount(); });
        });
    }

    // Réglages globaux actuellement sélectionnés, lus depuis le DOM.
    function currentSettings() {
        const statuses = Array.from(statusBox.querySelectorAll('.anilist-import-status-cb:checked')).map(el => el.value);
        const formats  = Array.from(formatBox.querySelectorAll('.anilist-import-format-cb:checked')).map(el => el.value);
        const includeAdult = document.querySelector('input[name="anilist-import-adult"]:checked')?.value === '1';
        const updateExisting = document.querySelector('input[name="anilist-import-update-existing"]:checked')?.value === '1';
        const favLists = Array.from(favouriteBox.querySelectorAll('.anilist-import-fav-list:checked')).map(el => el.value);
        const favNative = !!document.getElementById('anilist-import-fav-native')?.checked;

        return { statuses, formats, includeAdult, updateExisting, favLists, favNative };
    }

    // Un filtre global a changé : les décochages individuels perdent leur
    // sens (une série exclue à la main peut redevenir hors-filtre, ou
    // inversement) — décision explicite de la feuille de route, avec mention
    // discrète pour prévenir la surprise.
    let resetNoticeTimer = null;
    function resetExclusions() {
        if (excludedIds.size === 0) return;
        excludedIds = new Set();
        resetNotice.style.display = '';
        clearTimeout(resetNoticeTimer);
        resetNoticeTimer = setTimeout(() => { resetNotice.style.display = 'none'; }, 4000);
    }

    // Une entrée passe-t-elle les filtres globaux actuellement sélectionnés ?
    function passesGlobalFilters(entry, settings) {
        if (!settings.statuses.includes(entry.list_status)) return false;
        if (!settings.formats.includes(entry.format)) return false;
        if (!settings.includeAdult && entry.is_adult) return false;
        if (entry.destination === 'existing' && !settings.updateExisting) return false;
        if (entry.destination === 'unknown') return false;
        return true;
    }

    function updateEstimatedCount() {
        const settings = currentSettings();
        const kept = (preview.entries || []).filter(e => passesGlobalFilters(e, settings) && !excludedIds.has(e.anilist_id));
        estimatedCountEl.textContent = kept.length
            ? `${kept.length} série${kept.length > 1 ? 's' : ''} sera${kept.length > 1 ? 'ont' : ''} traitée${kept.length > 1 ? 's' : ''} à la validation.`
            : 'Aucune série ne correspond aux réglages actuels.';
    }

    // Rend la liste dépliable, groupée par destination, avec décochage
    // individuel. Le filtre de recherche ne touche qu'à l'affichage — il ne
    // réinitialise jamais les décochages (contrairement aux filtres globaux).
    function renderGroups() {
        const settings = currentSettings();
        const searchVal = normalizeForFilter(searchInput?.value || '');

        const groups = { library: [], wishlist: [], existing: [] };
        (preview.entries || []).forEach(entry => {
            if (!passesGlobalFilters(entry, settings)) return;
            if (searchVal) {
                const haystack = normalizeForFilter(
                    [entry.title, entry.title_english, entry.title_native, ...(entry.alt_titles || [])].filter(Boolean).join(' ')
                );
                if (!haystack.includes(searchVal)) return;
            }
            groups[entry.destination === 'existing' ? 'existing' : entry.destination].push(entry);
        });

        const labels = { library: 'Vidéothèque', wishlist: "Liste d'envies", existing: 'Déjà présentes' };
        let html = '';
        ['library', 'wishlist', 'existing'].forEach(key => {
            const list = groups[key];
            if (!list.length) return;
            html += `
                <details class="anilist-import-group" open>
                    <summary>${esc(labels[key])} <span class="summary-badge summary-badge--muted">${list.length}</span></summary>
                    <ul class="anilist-import-entry-list">
                        ${list.map(entry => `
                            <li class="anilist-import-entry">
                                <label>
                                    <input type="checkbox" class="anilist-import-entry-cb" data-id="${entry.anilist_id}" ${excludedIds.has(entry.anilist_id) ? '' : 'checked'}>
                                    <span class="anilist-import-entry-title">${esc(entry.title)}${entry.is_adult ? ' <span class="mature-badge">🔞</span>' : ''}</span>
                                    <span class="anilist-import-entry-meta">${esc(entry.format_label)} · ${esc(entry.list_status_label)}${entry.already_title ? ' · déjà « ' + esc(entry.already_title) + ' »' : ''}</span>
                                </label>
                            </li>
                        `).join('')}
                    </ul>
                </details>`;
        });

        if (!html) {
            html = '<p class="incomplete-empty-msg">Aucune série ne correspond aux réglages ou à la recherche.</p>';
        }

        groupsEl.innerHTML = html;
        groupsEl.querySelectorAll('.anilist-import-entry-cb').forEach(cb => {
            cb.addEventListener('change', function () {
                const id = parseInt(this.dataset.id, 10);
                if (this.checked) excludedIds.delete(id);
                else excludedIds.add(id);
                updateEstimatedCount();
            });
        });
    }

    searchInput?.addEventListener('input', renderGroups);

    // ── Abandon de l'aperçu ───────────────────────────────────────────────

    discardBtn?.addEventListener('click', function () {
        showCustomConfirm('Abandonner cet aperçu', "Cet aperçu sera perdu et il faudra relancer une récupération. Continuer ?")
            .then(ok => {
                if (!ok) return;
                post({ tool_action: 'anilist_import_discard' }).then(() => {
                    preview = null;
                    excludedIds = new Set();
                    usernameInput.value = '';
                    showStep(stepUsername);
                });
            });
    });

    // ── Étape 3 → 4 : lancement de l'import (SSE) ────────────────────────

    function launchImport() {
        const settings = currentSettings();

        if (settings.statuses.length === 0 || settings.formats.length === 0) {
            showErrorModal("Sélectionnez au moins un statut et un format à importer.");
            return;
        }

        launchBtn.disabled = true;
        launchText.style.display = 'none';
        launchSpinner.style.display = '';

        // Les réglages (dont les décochages individuels, potentiellement
        // nombreux) sont d'abord persistés côté serveur via un POST classique :
        // l'EventSource de la phase 2 n'a alors qu'une URL courte à ouvrir,
        // sans risquer de dépasser la longueur d'URL maximale d'un serveur.
        const body = new URLSearchParams();
        body.set('tool_action', 'anilist_import_settings_save');
        settings.statuses.forEach(s => body.append('statuses[]', s));
        settings.formats.forEach(f => body.append('formats[]', f));
        body.set('include_adult', settings.includeAdult ? '1' : '0');
        body.set('update_existing', settings.updateExisting ? '1' : '0');
        Array.from(excludedIds).forEach(id => body.append('excluded_ids[]', id));
        settings.favLists.forEach(l => body.append('favourite_lists[]', l));
        body.set('favourite_native', settings.favNative ? '1' : '0');

        fetch('page-outils.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(r => r.json())
            .then(d => {
                if (!d.success) {
                    launchBtn.disabled = false;
                    launchText.style.display = '';
                    launchSpinner.style.display = 'none';
                    showErrorModal(d.message || "Impossible d'enregistrer les réglages.");
                    return;
                }
                runImportStream();
            })
            .catch(() => {
                launchBtn.disabled = false;
                launchText.style.display = '';
                launchSpinner.style.display = 'none';
                showErrorModal("Une erreur est survenue lors de l'enregistrement des réglages.");
            });
    }

    function runImportStream() {
        showStep(stepRunning);
        runProgressEl.innerHTML =
            `<p class="analysis-progress"><span class="progress-spinner"></span>Préparation de l'import…</p>`;

        const es = new EventSource('page-outils.php?action=anilist_import_run_stream');
        currentEs = es;

        es.addEventListener('progress', e => {
            const d = JSON.parse(e.data);
            const pct = d.total > 0 ? Math.round((d.current / d.total) * 100) : 0;
            runProgressEl.innerHTML = `
                <p class="analysis-progress">
                    <span class="progress-spinner"></span>
                    <span class="progress-count">(${d.current} / ${d.total} — ~${pct}%)</span>
                    Import en cours : <strong>${esc(d.title || '…')}</strong>
                </p>`;
        });

        es.addEventListener('done', e => {
            es.close();
            currentEs = null;
            launchBtn.disabled = false;
            launchText.style.display = '';
            launchSpinner.style.display = 'none';

            const d = JSON.parse(e.data);
            renderRunResults(d);
            showStep(stepDone);
        });

        es.onerror = () => {
            es.close();
            currentEs = null;
            launchBtn.disabled = false;
            launchText.style.display = '';
            launchSpinner.style.display = 'none';
            runResultsEl.innerHTML = `<p class="incomplete-empty-msg">La connexion avec le serveur a été interrompue pendant l'import. Certaines séries ont peut-être déjà été enregistrées : vérifiez votre vidéothèque avant de relancer.</p>`;
            showStep(stepDone);
        };
    }

    launchBtn?.addEventListener('click', launchImport);

    function renderRunResults(d) {
        if (!d.success) {
            runResultsEl.innerHTML = `<p class="incomplete-empty-msg">${esc(d.message || "L'import a échoué.")}</p>`;
            return;
        }

        const created    = d.created    || [];
        const updated    = d.updated    || [];
        const wishlisted = d.wishlisted || [];
        const skipped    = d.skipped    || [];
        const errors     = d.errors     || [];

        let html = '<div class="analysis-summary"><h3 class="summary-title">Récapitulatif de la campagne</h3>';
        html += `<div class="anilist-import-dest-counts">
            <div class="anilist-import-dest-count anilist-import-dest-count--library"><span class="anilist-import-dest-count-value">${created.length}</span><span class="anilist-import-dest-count-label">Ajoutées</span></div>
            <div class="anilist-import-dest-count anilist-import-dest-count--existing"><span class="anilist-import-dest-count-value">${updated.length}</span><span class="anilist-import-dest-count-label">Mises à jour</span></div>
            <div class="anilist-import-dest-count anilist-import-dest-count--wishlist"><span class="anilist-import-dest-count-value">${wishlisted.length}</span><span class="anilist-import-dest-count-label">Vers la liste d'envies</span></div>
            <div class="anilist-import-dest-count anilist-import-dest-count--error"><span class="anilist-import-dest-count-value">${errors.length}</span><span class="anilist-import-dest-count-label">En erreur</span></div>
        </div>`;

        if (d.favourite_count) {
            html += `<p class="hint">${d.favourite_count} série${d.favourite_count > 1 ? 's' : ''} marquée${d.favourite_count > 1 ? 's' : ''} favorite${d.favourite_count > 1 ? 's' : ''} à la création.</p>`;
        }

        if (skipped.length) {
            html += `<details class="summary-group"><summary><span class="summary-badge summary-badge--muted">— ${skipped.length}</span> Ignorées (déjà en liste d'envies)</summary>
                <ul class="summary-list">${skipped.map(t => `<li>${esc(t)}</li>`).join('')}</ul></details>`;
        }

        if (errors.length) {
            html += `<details class="summary-group" open><summary><span class="summary-badge summary-badge--warn">⚠ ${errors.length}</span> En erreur</summary>
                <ul class="summary-list">${errors.map(e => `<li><strong>${esc(e.title)}</strong> — <span class="summary-reason">${esc(e.message)}</span></li>`).join('')}</ul></details>`;
        }

        html += '</div>';

        if (d.message) {
            html += `<p class="hint">${esc(d.message)}</p>`;
        }

        runResultsEl.innerHTML = html;
    }

    restartBtn?.addEventListener('click', function () {
        preview = null;
        excludedIds = new Set();
        usernameInput.value = '';
        fetchFeedback.textContent = '';
        showStep(stepUsername);
    });

    // ── Initialisation ────────────────────────────────────────────────────
    showStep(stepUsername);
    checkExistingState();
})();
