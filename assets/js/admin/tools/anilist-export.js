// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/anilist-export.js — Outil « Export vers Anilist »
//
// 1. Préparation (SSE) : association de chaque série à sa fiche Anilist
//    (récupération groupée pour les animés, recherche par titre pour les
//    mangas). Les associations sont mémorisées côté serveur.
// 2. Aperçu : un panneau par type (animés / mangas), cases à cocher, filtres,
//    correction manuelle d'une association, téléchargement du fichier XML.
//
// Au chargement, l'aperçu est affiché directement (sans appel réseau côté
// serveur) si des associations ont déjà été mémorisées.
// ──────────────────────────────────────────────────────────────────────────

(function () {
    'use strict';

    const stepSetup = document.getElementById('anilist-export-step-setup');
    if (!stepSetup) return;

    const stepPreview     = document.getElementById('anilist-export-step-preview');
    const panelsEl        = document.getElementById('anilist-export-panels');
    const includeWishlist = document.getElementById('anilist-export-include-wishlist');
    const includeScores   = document.getElementById('anilist-export-include-scores');
    const estimateEl      = document.getElementById('anilist-export-estimate');
    const prepareBtn      = document.getElementById('anilist-export-prepare-btn');
    const prepareText     = document.getElementById('anilist-export-prepare-text');
    const prepareSpinner  = document.getElementById('anilist-export-prepare-spinner');
    const retryBtn        = document.getElementById('anilist-export-retry-btn');
    const resetBtn        = document.getElementById('anilist-export-reset-btn');
    const progressEl      = document.getElementById('anilist-export-progress');
    const feedbackEl      = document.getElementById('anilist-export-feedback');
    const downloadForm    = document.getElementById('anilist-export-download-form');

    const ENDPOINT = 'outil-anilist-export.php';

    const KIND_LABELS = {
        anime: { title: 'Animés', items: 'épisodes', done: 'vus', file: 'le fichier des animés' },
        manga: { title: 'Mangas et light-novels', items: 'tomes', done: 'lus', file: 'le fichier des mangas' },
    };
    const FORMAT_LABELS = {
        TV: 'Série TV', TV_SHORT: 'Format court', MOVIE: 'Film', SPECIAL: 'Spécial',
        OVA: 'OAV', ONA: 'ONA', MUSIC: 'Clip musical',
        MANGA: 'Manga', NOVEL: 'Light-novel', ONE_SHOT: 'One-shot',
    };
    const STATE_FILTERS = [
        ['all',       'Toutes'],
        ['checked',   'Cochées'],
        ['uncertain', 'À vérifier'],
        ['no_mal',    'Non exportables'],
        ['none',      'Sans correspondance'],
    ];

    // ── État local ────────────────────────────────────────────────────────
    let preview = null;          // dernier aperçu renvoyé par le serveur
    let checked = new Map();     // key → bool (case cochée)
    let busy    = false;

    // ── Utilitaires ───────────────────────────────────────────────────────
    function esc(str) {
        return String(str ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function normalize(str) {
        return String(str || '').toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu, '');
    }

    // Les vignettes sont des chemins relatifs à la racine du site ; la page vit
    // deux crans plus bas (pages/outils/).
    function thumbUrl(path) {
        if (!path) return '../../assets/img/logo.png';
        if (/^https?:\/\//i.test(path)) return path;
        return '../../' + String(path).replace(/^\/+/, '');
    }

    // N'accepte que des URL http(s) pour les liens vers Anilist.
    function safeUrl(url) {
        return /^https:\/\/anilist\.co\//i.test(String(url || '')) ? url : '';
    }

    function wishlistParam() {
        return includeWishlist.checked ? '1' : '0';
    }

    function post(params) {
        return fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params).toString()
        }).then(r => r.json());
    }

    function setBusy(state) {
        busy = state;
        prepareBtn.disabled = state;
        retryBtn.disabled   = state;
        resetBtn.disabled   = state;
        includeWishlist.disabled = state;
        prepareText.style.display    = state ? 'none' : '';
        prepareSpinner.style.display = state ? '' : 'none';
    }

    function formatDuration(requests) {
        // Le connecteur temporise à ~0,7 s par requête.
        const seconds = Math.ceil(requests * 0.75);
        if (seconds < 60) return `${seconds} s`;
        const minutes = Math.ceil(seconds / 60);
        return `${minutes} min`;
    }

    // ── Chargement de l'aperçu mémorisé ──────────────────────────────────
    function loadPreview() {
        return fetch(`${ENDPOINT}?action=anilist_export_preview&include_wishlist=${wishlistParam()}`, { cache: 'no-store' })
            .then(r => r.json())
            .then(d => {
                if (!d || !d.success) return;
                updateEstimate(d);
                resetBtn.style.display = d.has_matches ? '' : 'none';
                retryBtn.style.display = d.has_matches ? '' : 'none';
                if (d.has_matches) {
                    openPreview(d, true);
                } else {
                    stepPreview.style.display = 'none';
                }
            })
            .catch(() => { /* aperçu indisponible : on reste sur l'étape de préparation */ });
    }

    function updateEstimate(d) {
        const n = Number(d.pending_requests || 0);
        if (n <= 0) {
            estimateEl.textContent = d.has_matches
                ? "Toutes les séries ont déjà été traitées : une nouvelle préparation sera instantanée."
                : '';
            return;
        }
        estimateEl.textContent = `Environ ${n} requête${n > 1 ? 's' : ''} Anilist à effectuer (≈ ${formatDuration(n)}). Vous pouvez quitter la page : les recherches déjà faites sont conservées.`;
    }

    // ── Préparation (SSE) ────────────────────────────────────────────────
    function launchPrepare(retry) {
        if (busy) return;
        setBusy(true);
        feedbackEl.textContent = '';
        feedbackEl.className = 'anilist-import-feedback';
        progressEl.innerHTML = `<p class="analysis-progress"><span class="progress-spinner"></span>Préparation…</p>`;

        const url = `${ENDPOINT}?action=anilist_export_prepare_stream&include_wishlist=${wishlistParam()}&retry=${retry ? '1' : '0'}`;
        const es  = new EventSource(url);

        es.addEventListener('progress', e => {
            const d = JSON.parse(e.data);
            const total = Number(d.total || 0);
            const current = Number(d.current || 0);
            const pct = total > 0 ? Math.round(current / total * 100) : 0;
            progressEl.innerHTML =
                `<p class="analysis-progress"><span class="progress-spinner"></span>` +
                `<span>${esc(d.message || 'Préparation…')}</span>` +
                (total > 0 ? ` <span class="progress-count">(${current} / ${total} — ${pct}%)</span>` : '') +
                `</p>`;
        });

        es.addEventListener('done', e => {
            es.close();
            setBusy(false);
            progressEl.innerHTML = '';
            const d = JSON.parse(e.data);
            if (d.errors && d.errors.length) {
                feedbackEl.textContent = "Préparation interrompue : " + d.errors.join(' ') + " Les associations déjà trouvées sont conservées, relancez plus tard pour compléter.";
                feedbackEl.className = 'anilist-import-feedback is-error';
            } else {
                feedbackEl.textContent = 'Préparation terminée.';
                feedbackEl.className = 'anilist-import-feedback is-success';
            }
            resetBtn.style.display = '';
            retryBtn.style.display = '';
            updateEstimate(d);
            openPreview(d, false);
        });

        es.onerror = () => {
            es.close();
            setBusy(false);
            progressEl.innerHTML = '';
            feedbackEl.textContent = "La connexion avec le serveur a été interrompue. Les associations déjà trouvées sont conservées : relancez la préparation pour compléter.";
            feedbackEl.className = 'anilist-import-feedback is-error';
            loadPreview();
        };
    }

    // ── Aperçu ───────────────────────────────────────────────────────────
    function openPreview(d, keepChecks) {
        preview = d;
        const previous = checked;
        checked = new Map();
        (d.entries || []).forEach(entry => {
            const keep = keepChecks && previous.has(entry.key) && entry.exportable;
            checked.set(entry.key, keep ? previous.get(entry.key) : !!entry.checked);
        });
        renderPanels();
        stepPreview.style.display = '';
    }

    function entriesOf(kind) {
        return (preview && preview.entries ? preview.entries : []).filter(e => e.kind === kind);
    }

    function checkedCount(kind) {
        return entriesOf(kind).filter(e => e.exportable && checked.get(e.key)).length;
    }

    function renderPanels() {
        const html = ['anime', 'manga'].map(kind => {
            const list = entriesOf(kind);
            if (list.length === 0) return '';
            const c = preview.counts[kind] || {};
            const labels = KIND_LABELS[kind];
            return `
                <section class="anilist-export-panel anilist-export-panel--${kind}" data-kind="${kind}">
                    <h3 class="anilist-export-panel-title">${esc(labels.title)} <span class="anilist-import-count-badge">(${list.length})</span></h3>
                    <div class="anilist-import-dest-counts">
                        <div class="anilist-import-dest-count anilist-import-dest-count--library">
                            <span class="anilist-import-dest-count-value" data-role="checked-count">${checkedCount(kind)}</span>
                            <span class="anilist-import-dest-count-label">À exporter (cochées)</span>
                        </div>
                        <div class="anilist-import-dest-count anilist-export-dest-count--uncertain">
                            <span class="anilist-import-dest-count-value">${c.uncertain || 0}</span>
                            <span class="anilist-import-dest-count-label">À vérifier</span>
                        </div>
                        <div class="anilist-import-dest-count anilist-import-dest-count--existing">
                            <span class="anilist-import-dest-count-value">${c.no_mal || 0}</span>
                            <span class="anilist-import-dest-count-label">Non exportables</span>
                        </div>
                        <div class="anilist-import-dest-count anilist-import-dest-count--error">
                            <span class="anilist-import-dest-count-value">${(c.none || 0) + (c.pending || 0)}</span>
                            <span class="anilist-import-dest-count-label">Sans correspondance</span>
                        </div>
                    </div>
                    <div class="anilist-export-toolbar">
                        <select class="anilist-export-filter" data-role="filter" aria-label="Filtrer">
                            ${STATE_FILTERS.map(([v, l]) => `<option value="${v}">${esc(l)}</option>`).join('')}
                        </select>
                        <input type="text" class="incomplete-search-input anilist-export-search" data-role="search" placeholder="Filtrer par titre…" autocomplete="off">
                        <button type="button" class="button button-opt button-sm" data-role="check-all">Tout cocher</button>
                        <button type="button" class="button button-opt button-sm" data-role="uncheck-all">Tout décocher</button>
                    </div>
                    <ul class="anilist-export-list">
                        ${list.map(rowHtml).join('')}
                    </ul>
                    <p class="hint anilist-export-empty" data-role="empty" style="display:none;">Aucune série ne correspond au filtre.</p>
                    <div class="tools-actions">
                        <button type="button" class="button button-ats" data-role="download">Télécharger ${esc(labels.file)} (<span data-role="download-count">${checkedCount(kind)}</span>)</button>
                    </div>
                </section>`;
        }).join('');

        panelsEl.innerHTML = html || '<p class="incomplete-empty-msg">Votre collection est vide : rien à exporter.</p>';
    }

    function matchBadge(entry) {
        switch (entry.state) {
            case 'ok':
                if (entry.match_type === 'exact')  return '<span class="summary-badge">Fiche liée</span>';
                if (entry.match_type === 'manual') return '<span class="summary-badge">Associée à la main</span>';
                return `<span class="summary-badge">Confiance ${Math.round(entry.anilist.score)} %</span>`;
            case 'uncertain':
                return `<span class="summary-badge summary-badge--warn">À vérifier — confiance ${Math.round(entry.anilist.score)} %</span>`;
            case 'no_mal':
                return '<span class="summary-badge summary-badge--muted">Non exportable : fiche sans équivalent MyAnimeList</span>';
            case 'pending':
                return '<span class="summary-badge summary-badge--muted">Pas encore recherchée</span>';
            default:
                return entry.match_type === 'manual_none'
                    ? '<span class="summary-badge summary-badge--muted">Exclue (aucune correspondance)</span>'
                    : '<span class="summary-badge summary-badge--warn">Aucune correspondance trouvée</span>';
        }
    }

    function rowHtml(entry) {
        const labels  = KIND_LABELS[entry.kind];
        const isWish  = entry.source === 'wishlist';
        const canEdit = entry.match_type !== 'exact';
        const a       = entry.anilist;
        const url     = a ? safeUrl(a.url) : '';
        const meta    = [];
        if (a && a.format) meta.push(FORMAT_LABELS[a.format] || a.format);
        if (a && a.year)   meta.push(a.year);

        const progress = isWish
            ? "liste d'envies"
            : `${entry.progress} / ${entry.owned} ${labels.items} ${labels.done}`;

        const searchText = normalize(entry.name + ' ' + (a ? a.title : ''));

        return `
            <li class="anilist-export-row is-state-${esc(entry.state)}" data-key="${esc(entry.key)}" data-state="${esc(entry.state)}" data-search="${esc(searchText)}">
                <input type="checkbox" class="anilist-export-check" data-role="check"
                       ${entry.exportable ? '' : 'disabled'} ${entry.exportable && checked.get(entry.key) ? 'checked' : ''}
                       aria-label="Exporter ${esc(entry.name)}">
                <img class="anilist-export-thumb" src="${esc(thumbUrl(entry.thumbnail))}" alt="" loading="lazy">
                <div class="anilist-export-info">
                    <div class="anilist-export-name">
                        ${esc(entry.name)}
                        ${isWish ? '<span class="summary-badge summary-badge--muted">Liste d\'envies</span>' : ''}
                    </div>
                    <div class="anilist-export-meta">${esc(entry.status_label)} · ${esc(progress)}</div>
                    <div class="anilist-export-match">
                        ${a
                            ? `→ ${url ? `<a href="${esc(url)}" target="_blank" rel="noopener noreferrer">${esc(a.title || ('#' + a.id))}</a>` : esc(a.title)}${meta.length ? ` <span class="anilist-import-entry-meta">(${esc(meta.join(', '))})</span>` : ''}`
                            : ''}
                        ${matchBadge(entry)}
                        ${canEdit ? '<button type="button" class="anilist-export-edit-btn" data-role="edit">Modifier</button>' : ''}
                    </div>
                    ${canEdit ? editHtml(entry) : ''}
                </div>
            </li>`;
    }

    function editHtml(entry) {
        const candidates = (entry.candidates || []).filter(c => !entry.anilist || c.id !== entry.anilist.id);
        return `
            <div class="anilist-export-edit" data-role="edit-box" hidden>
                ${candidates.length ? `
                    <p class="hint">Autres résultats de la recherche :</p>
                    <ul class="anilist-export-candidates">
                        ${candidates.map(c => `
                            <li>
                                <button type="button" class="button button-opt button-sm" data-role="pick" data-id="${Number(c.id)}">Choisir</button>
                                ${safeUrl(c.url) ? `<a href="${esc(c.url)}" target="_blank" rel="noopener noreferrer">${esc(c.title)}</a>` : esc(c.title)}
                                <span class="anilist-import-entry-meta">(${esc([FORMAT_LABELS[c.format] || c.format, c.year || ''].filter(Boolean).join(', '))} — ${Math.round(c.score)} %${c.id_mal ? '' : ', sans équivalent MyAnimeList'})</span>
                            </li>`).join('')}
                    </ul>` : ''}
                <div class="anilist-export-edit-row">
                    <input type="text" data-role="manual-input" placeholder="URL de la fiche Anilist ou identifiant…" autocomplete="off">
                    <button type="button" class="button button-ats button-sm" data-role="manual-save">Valider</button>
                    <button type="button" class="button button-opt button-sm" data-role="manual-none">Aucune correspondance</button>
                </div>
                <p class="anilist-import-feedback" data-role="edit-feedback"></p>
            </div>`;
    }

    function refreshCounts(panel) {
        const kind = panel.dataset.kind;
        const n = checkedCount(kind);
        panel.querySelectorAll('[data-role="checked-count"], [data-role="download-count"]').forEach(el => { el.textContent = n; });
    }

    function applyFilter(panel) {
        const filter = panel.querySelector('[data-role="filter"]').value;
        const term   = normalize(panel.querySelector('[data-role="search"]').value.trim());
        let visible = 0;
        panel.querySelectorAll('.anilist-export-row').forEach(row => {
            const state = row.dataset.state;
            let ok = true;
            if (filter === 'checked')        ok = !!checked.get(row.dataset.key);
            else if (filter === 'none')      ok = state === 'none' || state === 'pending';
            else if (filter !== 'all')       ok = state === filter;
            if (ok && term !== '')           ok = row.dataset.search.includes(term);
            row.style.display = ok ? '' : 'none';
            if (ok) visible++;
        });
        panel.querySelector('[data-role="empty"]').style.display = visible === 0 ? '' : 'none';
    }

    // Remplace une entrée de l'aperçu (après correction manuelle) et
    // re-rend uniquement sa ligne.
    function replaceEntry(entry) {
        if (!preview) return;
        const idx = preview.entries.findIndex(e => e.key === entry.key);
        if (idx === -1) return;
        const before = preview.entries[idx];
        preview.entries[idx] = entry;

        // Décomptes par état
        const counts = preview.counts[entry.kind];
        if (counts) {
            counts[before.state] = Math.max(0, (counts[before.state] || 0) - 1);
            counts[entry.state]  = (counts[entry.state] || 0) + 1;
        }
        // Une association choisie à la main est cochée d'office si exportable.
        checked.set(entry.key, entry.exportable && entry.state === 'ok');

        // Les compteurs « À vérifier / Non exportables / Sans correspondance »
        // sont recalculés en re-rendant les panneaux : plus simple et sûr que
        // de les patcher un à un. Filtre et recherche sont préservés.
        const states = {};
        panelsEl.querySelectorAll('.anilist-export-panel').forEach(p => {
            states[p.dataset.kind] = {
                filter: p.querySelector('[data-role="filter"]').value,
                search: p.querySelector('[data-role="search"]').value,
            };
        });
        renderPanels();
        panelsEl.querySelectorAll('.anilist-export-panel').forEach(p => {
            const s = states[p.dataset.kind];
            if (s) {
                p.querySelector('[data-role="filter"]').value = s.filter;
                p.querySelector('[data-role="search"]').value = s.search;
                applyFilter(p);
            }
        });
    }

    function saveManual(row, value) {
        const fb = row.querySelector('[data-role="edit-feedback"]');
        fb.textContent = 'Vérification sur Anilist…';
        fb.className = 'anilist-import-feedback';
        row.querySelectorAll('[data-role="edit-box"] button, [data-role="edit-box"] input').forEach(el => { el.disabled = true; });

        post({ tool_action: 'anilist_export_set_match', key: row.dataset.key, value: value })
            .then(d => {
                if (!d || !d.success) {
                    fb.textContent = (d && d.message) || "Association impossible.";
                    fb.className = 'anilist-import-feedback is-error';
                    row.querySelectorAll('[data-role="edit-box"] button, [data-role="edit-box"] input').forEach(el => { el.disabled = false; });
                    return;
                }
                replaceEntry(d.entry);
            })
            .catch(() => {
                fb.textContent = "Erreur de communication avec le serveur.";
                fb.className = 'anilist-import-feedback is-error';
                row.querySelectorAll('[data-role="edit-box"] button, [data-role="edit-box"] input').forEach(el => { el.disabled = false; });
            });
    }

    function download(kind) {
        const excluded = entriesOf(kind).filter(e => !checked.get(e.key)).map(e => e.key);
        if (entriesOf(kind).filter(e => e.exportable && checked.get(e.key)).length === 0) {
            showCustomAlert('Export vers Anilist', "Aucune série cochée : rien à exporter.");
            return;
        }

        // Réinitialise le formulaire (seul tool_action est permanent).
        downloadForm.querySelectorAll('input:not([name="tool_action"])').forEach(el => el.remove());
        const add = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            downloadForm.appendChild(input);
        };
        add('kind', kind);
        add('include_scores', includeScores.checked ? '1' : '0');
        add('include_wishlist', wishlistParam());
        excluded.forEach(k => add('excluded[]', k));
        downloadForm.submit();
    }

    // ── Délégation d'événements sur les panneaux ─────────────────────────
    panelsEl.addEventListener('change', e => {
        const panel = e.target.closest('.anilist-export-panel');
        if (!panel) return;
        if (e.target.matches('[data-role="check"]')) {
            const row = e.target.closest('.anilist-export-row');
            checked.set(row.dataset.key, e.target.checked);
            refreshCounts(panel);
        } else if (e.target.matches('[data-role="filter"]')) {
            applyFilter(panel);
        }
    });

    panelsEl.addEventListener('input', e => {
        if (e.target.matches('[data-role="search"]')) {
            applyFilter(e.target.closest('.anilist-export-panel'));
        }
    });

    panelsEl.addEventListener('keydown', e => {
        if (e.key === 'Enter' && e.target.matches('[data-role="manual-input"]')) {
            e.preventDefault();
            const row = e.target.closest('.anilist-export-row');
            const value = e.target.value.trim();
            if (value !== '') saveManual(row, value);
        }
    });

    panelsEl.addEventListener('click', e => {
        const btn = e.target.closest('button');
        if (!btn) return;
        const panel = btn.closest('.anilist-export-panel');
        const row   = btn.closest('.anilist-export-row');
        const role  = btn.dataset.role;

        if (role === 'download') {
            download(panel.dataset.kind);
        } else if (role === 'check-all' || role === 'uncheck-all') {
            const value = role === 'check-all';
            // N'agit que sur les lignes actuellement visibles (filtre/recherche).
            panel.querySelectorAll('.anilist-export-row').forEach(r => {
                if (r.style.display === 'none') return;
                const cb = r.querySelector('[data-role="check"]');
                if (cb.disabled) return;
                cb.checked = value;
                checked.set(r.dataset.key, value);
            });
            refreshCounts(panel);
        } else if (role === 'edit' && row) {
            const box = row.querySelector('[data-role="edit-box"]');
            if (box) {
                box.hidden = !box.hidden;
                if (!box.hidden) {
                    const input = box.querySelector('[data-role="manual-input"]');
                    if (input) input.focus();
                }
            }
        } else if (role === 'pick' && row) {
            saveManual(row, String(btn.dataset.id));
        } else if (role === 'manual-save' && row) {
            const value = row.querySelector('[data-role="manual-input"]').value.trim();
            if (value === '') {
                const fb = row.querySelector('[data-role="edit-feedback"]');
                fb.textContent = "Saisissez une URL ou un identifiant Anilist.";
                fb.className = 'anilist-import-feedback is-error';
                return;
            }
            saveManual(row, value);
        } else if (role === 'manual-none' && row) {
            saveManual(row, '');
        }
    });

    // ── Boutons de l'étape 1 ─────────────────────────────────────────────
    prepareBtn.addEventListener('click', () => launchPrepare(false));
    retryBtn.addEventListener('click', () => launchPrepare(true));

    resetBtn.addEventListener('click', async () => {
        const ok = await showCustomConfirm(
            'Oublier les associations',
            "Toutes les associations mémorisées (y compris vos corrections manuelles) seront oubliées. La prochaine préparation refera toutes les recherches. Continuer ?"
        );
        if (!ok) return;
        post({ tool_action: 'anilist_export_reset' })
            .then(() => {
                preview = null;
                checked = new Map();
                panelsEl.innerHTML = '';
                stepPreview.style.display = 'none';
                feedbackEl.textContent = 'Associations oubliées.';
                feedbackEl.className = 'anilist-import-feedback is-success';
                loadPreview();
            })
            .catch(() => showCustomAlert('Erreur', "Erreur de communication avec le serveur."));
    });

    includeWishlist.addEventListener('change', () => { if (!busy) loadPreview(); });

    loadPreview();
})();
