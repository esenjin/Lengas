// ──────────────────────────────────────────────────────────────────────────────
// assets/js/admin/reviews.js
// Gestion de la page des critiques : liste, éditeur Markdown split + aperçu.
// L'aperçu est rendu côté serveur (endpoint review_action=preview) pour être
// STRICTEMENT identique à l'affichage public et éviter tout XSS côté client.
// ──────────────────────────────────────────────────────────────────────────────
(function () {
    'use strict';

    const ENDPOINT = 'page-critiques.php';

    const listView   = document.getElementById('reviews-list-view');
    const editorView = document.getElementById('reviews-editor-view');

    const listContainer = document.getElementById('reviews-list');
    const searchInput   = document.getElementById('reviews-search');
    const newBtn        = document.getElementById('new-review-btn');
    const countLabel    = document.getElementById('reviews-count');
    const sortBySelect  = document.getElementById('reviews-sort-by');
    const sortOrderSelect = document.getElementById('reviews-sort-order');

    // Dernière liste reçue de l'API (non triée) : sert de source pour le tri,
    // plutôt que de reparser les cartes déjà rendues dans le DOM.
    let allReviews = [];

    const backBtn       = document.getElementById('review-back-btn');
    const saveBtn       = document.getElementById('review-save-btn');
    const deleteBtn     = document.getElementById('review-delete-btn');
    const previewToggle = document.getElementById('review-preview-toggle');

    const seriesSearch  = document.getElementById('review-series-search');
    const seriesResults = document.getElementById('review-series-results');
    const seriesIdInput = document.getElementById('review-series-id');

    const textarea      = document.getElementById('review-content');
    const previewBox    = document.getElementById('review-preview');
    const splitEl       = document.getElementById('review-split');
    const readingAlert  = document.getElementById('review-reading-alert');

    // Filtre de type de la vue liste (Les deux / Mangas / Animés). Jamais
    // mémorisé d'une visite à l'autre : toujours « Les deux » au chargement.
    const typeToggle = document.getElementById('reviews-type-toggle');
    let currentTypeFilter = '';

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
    // ── Historique undo/redo ─────────────────────────────────────────────────
    // Le textarea natif perd son historique dès qu'on modifie .value par script
    // (boutons de la barre d'outils). On maintient donc notre propre pile.
    const history = { stack: [], index: -1, lastPush: 0 };

    function historyReset(value) {
        history.stack = [{ value: value, start: 0, end: 0 }];
        history.index = 0;
    }
    function historyPush(force) {
        const now = Date.now();
        const cur = { value: textarea.value, start: textarea.selectionStart, end: textarea.selectionEnd };
        const top = history.stack[history.index];
        if (top && top.value === cur.value) { // rien de neuf, on met à jour le curseur
            top.start = cur.start; top.end = cur.end;
            return;
        }
        // Regroupe la frappe rapide (coalescing) sauf si on force (action toolbar).
        if (!force && top && (now - history.lastPush) < 500 && history.index === history.stack.length - 1) {
            history.stack[history.index] = cur;
            history.lastPush = now;
            return;
        }
        // Tronque la branche "redo" si on avait annulé.
        history.stack = history.stack.slice(0, history.index + 1);
        history.stack.push(cur);
        history.index = history.stack.length - 1;
        history.lastPush = now;
        // Limite la taille de la pile.
        if (history.stack.length > 200) {
            history.stack.shift();
            history.index--;
        }
    }
    function historyApply(entry) {
        textarea.value = entry.value;
        textarea.focus();
        textarea.setSelectionRange(entry.start, entry.end);
        schedulePreview();
    }
    // Capture l'état courant du textarea s'il diffère du sommet de pile
    // (utile avant une navigation undo/redo déclenchée par bouton, hors frappe).
    function historyFlush() {
        const top = history.stack[history.index];
        if (!top || top.value !== textarea.value) {
            history.stack = history.stack.slice(0, history.index + 1);
            history.stack.push({ value: textarea.value, start: textarea.selectionStart, end: textarea.selectionEnd });
            history.index = history.stack.length - 1;
        }
    }
    function undo() {
        historyFlush();
        if (history.index > 0) {
            history.index--;
            historyApply(history.stack[history.index]);
        }
    }
    function redo() {
        if (history.index < history.stack.length - 1) {
            history.index++;
            historyApply(history.stack[history.index]);
        }
    }

    async function api(action, extra) {
        const fd = new URLSearchParams();
        fd.append('review_action', action);
        for (const k in (extra || {})) fd.append(k, extra[k]);
        const res = await fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        });
        return res.json();
    }

    // ── Vue liste ────────────────────────────────────────────────────────────
    function showList() {
        editorView.style.display = 'none';
        listView.style.display = '';
        loadList();
    }

    // Remet le filtre de type sur « Les deux », jamais mémorisé (appelé au
    // chargement initial de la page — pas à chaque retour depuis l'éditeur,
    // pour ne pas surprendre l'utilisateur en pleine session de tri).
    function resetTypeFilter() {
        currentTypeFilter = '';
        if (typeToggle) {
            typeToggle.querySelectorAll('.reviews-type-btn').forEach(b => {
                const active = (b.dataset.type || '') === '';
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }
    }
    function showEditor() {
        listView.style.display = 'none';
        editorView.style.display = '';
    }

    async function loadList() {
        listContainer.innerHTML = '<p class="reviews-empty">Chargement…</p>';
        const data = await api('list');
        if (!data.success) {
            listContainer.innerHTML = '<p class="reviews-empty">Erreur de chargement.</p>';
            return;
        }
        allReviews = data.reviews || [];
        renderList(sortReviews(allReviews));
    }

    // ── Tri de la vue liste (nom / date, ascendant / descendant) ────────────
    function sortReviews(reviews) {
        const by    = sortBySelect ? sortBySelect.value : 'date';
        const order = sortOrderSelect ? sortOrderSelect.value : 'desc';
        const sorted = reviews.slice();
        sorted.sort((a, b) => {
            let cmp;
            if (by === 'name') {
                cmp = String(a.name || '').localeCompare(String(b.name || ''), 'fr', { sensitivity: 'base' });
            } else {
                cmp = String(a.updated_at || '').localeCompare(String(b.updated_at || ''));
            }
            return order === 'asc' ? cmp : -cmp;
        });
        return sorted;
    }

    function renderList(reviews) {
        if (!reviews.length) {
            listContainer.innerHTML = '<p class="reviews-empty">Aucune critique pour le moment. ✏️</p>';
            updateCount(0);
            return;
        }
        listContainer.innerHTML = '';
        reviews.forEach(r => {
            const card = document.createElement('div');
            const type = r.type || 'manga';
            card.className = 'review-card';
            card.dataset.seriesId = r.series_id;
            card.dataset.type = type;
            const img = '../' + ((r.image && r.image !== '') ? htmlEscape(r.image) : 'assets/img/logo.png');
            const typeDef = (window.seriesTypes && window.seriesTypes[type]) || null;
            const typeBadge = typeDef
                ? `<span class="suggestion-type-badge review-card-type-badge" style="--type-color:${typeDef.color}">${htmlEscape(typeDef.label)}</span>`
                : '';
            card.innerHTML = `
                <button type="button" class="review-card-delete" title="Supprimer la critique" aria-label="Supprimer la critique">&times;</button>
                <img class="review-card-thumb" src="${img}" alt="" loading="lazy">
                <div class="review-card-body">
                    <h3 class="review-card-title">${htmlEscape(r.name)} ${typeBadge}</h3>
                    <p class="review-card-author">${htmlEscape(r.author || '')}</p>
                    <p class="review-card-excerpt">${htmlEscape(r.excerpt || '')}</p>
                    <p class="review-card-date">Modifiée le ${htmlEscape(formatDate(r.updated_at))}</p>
                </div>
            `;
            card.addEventListener('click', () => openEditor(r.series_id));
            card.querySelector('.review-card-delete').addEventListener('click', async (e) => {
                e.stopPropagation(); // ne pas ouvrir l'éditeur
                const ok = await showCustomConfirm(
                    'Confirmation',
                    `Êtes-vous sûr de vouloir supprimer la critique de « ${r.name} » ?`
                );
                if (!ok) return;
                const res = await api('delete', { series_id: r.series_id });
                if (res.success) {
                    card.remove();
                    allReviews = allReviews.filter(x => x.series_id !== r.series_id);
                    if (!listContainer.querySelector('.review-card')) {
                        listContainer.innerHTML = '<p class="reviews-empty">Aucune critique pour le moment. ✏️</p>';
                    }
                    updateCount(listContainer.querySelectorAll('.review-card').length);
                } else {
                    showCustomAlert('Erreur', res.message || 'Suppression impossible.');
                }
            });
            listContainer.appendChild(card);
        });
        updateCount(reviews.length);
    }

    function formatDate(s) {
        if (!s) return '—';
        const d = new Date(s.replace(' ', 'T'));
        if (isNaN(d)) return s;
        return d.toLocaleDateString('fr-FR') + ' ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    }

    // ── Filtre de type : recherche texte + toggle ────────────────────────────
    function applyListFilters() {
        const term = normalizeString(searchInput ? searchInput.value : '');
        let visible = 0;
        listContainer.querySelectorAll('.review-card').forEach(card => {
            const title = normalizeString(card.querySelector('.review-card-title')?.textContent || '');
            const auth  = normalizeString(card.querySelector('.review-card-author')?.textContent || '');
            const matchesTerm = term === '' || title.includes(term) || auth.includes(term);
            const matchesType = currentTypeFilter === '' || card.dataset.type === currentTypeFilter;
            const show = matchesTerm && matchesType;
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        updateCount(visible);
    }

    // ── Compteur de critiques (total + détail par type) ─────────────────────
    function updateCount(visible) {
        if (!countLabel) return;
        const total = allReviews.length;
        if (total === 0) {
            countLabel.textContent = '';
            return;
        }
        const mangaCount = allReviews.filter(r => (r.type || 'manga') === 'manga').length;
        const animeCount = allReviews.filter(r => r.type === 'anime').length;
        const parts = [];
        if (mangaCount > 0) parts.push(`${mangaCount} manga${mangaCount > 1 ? 's' : ''}`);
        if (animeCount > 0) parts.push(`${animeCount} animé${animeCount > 1 ? 's' : ''}`);
        const detail = parts.length ? ` (${parts.join(', ')})` : '';
        const filtered = (typeof visible === 'number' && visible !== total)
            ? `${visible} affichée${visible > 1 ? 's' : ''} sur `
            : '';
        countLabel.textContent = `${filtered}${total} critique${total > 1 ? 's' : ''}${detail}`;
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyListFilters);
    }

    // ── Tri : re-rend la liste dans le nouvel ordre, puis réapplique
    //    recherche/type (le tri ne doit jamais réinitialiser un filtre actif).
    function resortAndRender() {
        renderList(sortReviews(allReviews));
        applyListFilters();
    }
    if (sortBySelect)    sortBySelect.addEventListener('change', resortAndRender);
    if (sortOrderSelect) sortOrderSelect.addEventListener('change', resortAndRender);

    if (typeToggle) {
        typeToggle.querySelectorAll('.reviews-type-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                typeToggle.querySelectorAll('.reviews-type-btn').forEach(b => {
                    b.classList.remove('is-active');
                    b.setAttribute('aria-selected', 'false');
                });
                this.classList.add('is-active');
                this.setAttribute('aria-selected', 'true');
                currentTypeFilter = this.dataset.type || '';
                applyListFilters();
            });
        });
    }

    // ── Sélecteur de série (autocomplétion, calqué sur page-prets) ───────────
    function setupSeriesSearch() {
        if (!seriesSearch || !seriesResults) return;
        seriesResults.style.display = 'none';

        seriesSearch.addEventListener('focus', () => { seriesResults.style.display = 'block'; filterSeries(); });
        seriesSearch.addEventListener('input', filterSeries);

        function filterSeries() {
            const term = normalizeString(seriesSearch.value);
            let found = 0;
            seriesResults.querySelectorAll('div').forEach(div => {
                const match = normalizeString(div.textContent).includes(term);
                div.style.display = match ? '' : 'none';
                if (match) found++;
            });
            seriesResults.style.display = found > 0 ? 'block' : 'none';
        }

        seriesResults.querySelectorAll('div').forEach(div => {
            div.addEventListener('click', () => {
                seriesSearch.value = div.childNodes[0].textContent.trim();
                seriesIdInput.value = div.dataset.id;
                seriesResults.style.display = 'none';
                onSeriesChosen(div.dataset.id);
            });
        });

        document.addEventListener('click', e => {
            if (!seriesSearch.contains(e.target) && !seriesResults.contains(e.target)) {
                seriesResults.style.display = 'none';
            }
        });
    }

    async function onSeriesChosen(seriesId) {
        const data = await api('reading_state', { series_id: seriesId });
        if (data.success) updateReadingAlert(data.reading_state, seriesTypeOf(seriesId));
    }

    // Type de la série choisie, lu depuis les data-attributes du sélecteur
    // (posés par page-critiques.php à partir du registre central des types).
    function seriesTypeOf(seriesId) {
        const opt = seriesResults.querySelector(`div[data-id="${cssEscape(seriesId)}"]`);
        return (opt && opt.dataset.type) || 'manga';
    }

    function updateReadingAlert(state, type) {
        const vocab = (window.seriesTypes && window.seriesTypes[type || 'manga'] && window.seriesTypes[type || 'manga'].vocab) || {};
        const items      = vocab.items      || 'tomes';
        const doneLong    = vocab.done_long   || 'lue';
        const activityVerb = vocab.activity_verb || 'lire';
        if (state === 'none') {
            readingAlert.textContent = `⚠️ Aucun ${vocab.item || 'tome'} de cette série n'est encore marqué comme ${vocab.done_short || 'lu'}. Êtes-vous sûr de vouloir écrire une critique ?`;
            readingAlert.className = 'review-reading-alert review-reading-alert--danger';
            readingAlert.style.display = '';
        } else if (state === 'partial') {
            readingAlert.textContent = `⚠️ Tous les ${items} de cette série ne sont pas encore ${doneLong === 'vue' ? 'vus' : 'lus'}.`;
            readingAlert.className = 'review-reading-alert review-reading-alert--warning';
            readingAlert.style.display = '';
        } else {
            readingAlert.style.display = 'none';
            readingAlert.textContent = '';
        }
    }

    // ── Ouverture éditeur ────────────────────────────────────────────────────
    async function openEditor(seriesId) {
        showEditor();
        resetEditor();
        if (!seriesId) return;

        const data = await api('get', { series_id: seriesId });
        if (!data.success) return;

        seriesIdInput.value = seriesId;
        // Retrouve le libellé de la série dans la liste de résultats
        const opt = seriesResults.querySelector(`div[data-id="${cssEscape(seriesId)}"]`);
        seriesSearch.value = opt ? opt.childNodes[0].textContent.trim() : '';

        textarea.value = data.content || '';
        historyReset(textarea.value);
        deleteBtn.style.display = (data.content && data.content.trim() !== '') ? '' : 'none';
        updateReadingAlert(data.reading_state || 'none', seriesTypeOf(seriesId));
        schedulePreview();
    }

    function cssEscape(s) {
        return String(s).replace(/["\\]/g, '\\$&');
    }

    function resetEditor() {
        seriesIdInput.value = '';
        seriesSearch.value = '';
        textarea.value = '';
        previewBox.innerHTML = '<p class="review-preview-placeholder">L\'aperçu s\'affichera ici.</p>';
        deleteBtn.style.display = 'none';
        readingAlert.style.display = 'none';
        splitEl.classList.remove('show-preview');
        historyReset('');
    }

    // ── Aperçu serveur (débounce) ────────────────────────────────────────────
    let previewTimer = null;
    function schedulePreview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(renderPreview, 350);
    }
    async function renderPreview() {
        const md = textarea.value;
        if (md.trim() === '') {
            previewBox.innerHTML = '<p class="review-preview-placeholder">L\'aperçu s\'affichera ici.</p>';
            return;
        }
        const data = await api('preview', { content: md });
        if (data.success) {
            previewBox.innerHTML = data.html || '';
        }
    }
    if (textarea) {
        textarea.addEventListener('input', function () {
            historyPush(false);
            schedulePreview();
        });
        textarea.addEventListener('keydown', function (e) {
            const mod = e.ctrlKey || e.metaKey;
            if (!mod) return;
            const k = e.key.toLowerCase();
            if (k === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
            else if ((k === 'y') || (k === 'z' && e.shiftKey)) { e.preventDefault(); redo(); }
        });
    }

    // ── Barre d'outils Markdown ──────────────────────────────────────────────
    // Enveloppe la sélection avec les marqueurs `before`/`after`.
    // Si la sélection (ou le texte qui l'entoure immédiatement) porte déjà ce
    // marquage, un nouvel appel le RETIRE — la mise en forme devient donc une
    // bascule (ex. sélectionner « test » dans « *test* » puis re-cliquer sur
    // Italique enlève les astérisques).
    function wrapSelection(before, after, placeholder) {
        historyPush(true);
        after = after === undefined ? before : after;
        const start = textarea.selectionStart;
        const end   = textarea.selectionEnd;
        const val   = textarea.value;
        let sel     = val.slice(start, end);

        // Cas 1 : la sélection englobe déjà les marqueurs (« *test* » sélectionné).
        // Même garde-fou que le cas 2 : ne pas confondre `*` (italique) avec `**`.
        const innerNotLonger =
            !(before === after && before.length === 1 &&
              sel.length >= 2 && sel[before.length] === before);
        if (sel.length >= before.length + after.length && innerNotLonger &&
            sel.startsWith(before) && sel.endsWith(after)) {
            const inner = sel.slice(before.length, sel.length - after.length);
            textarea.value = val.slice(0, start) + inner + val.slice(end);
            textarea.focus();
            textarea.setSelectionRange(start, start + inner.length);
            historyPush(true);
            schedulePreview();
            return;
        }

        // Cas 2 : les marqueurs entourent la sélection (« test » sélectionné dans « *test* »).
        const beforeStart = start - before.length;
        const afterEnd    = end + after.length;
        // Garde-fou : `*` (italique) est un préfixe de `**` (gras). On refuse de
        // retirer un `*` isolé si le caractère adjacent prolonge en fait un `**`,
        // pour ne pas « casser » un gras en cliquant sur italique.
        const notPartOfLonger =
            !(before === after && before.length === 1 &&
              (val[beforeStart - 1] === before || val[afterEnd] === after));
        if (beforeStart >= 0 && afterEnd <= val.length && notPartOfLonger &&
            val.slice(beforeStart, start) === before &&
            val.slice(end, afterEnd) === after) {
            textarea.value = val.slice(0, beforeStart) + sel + val.slice(afterEnd);
            textarea.focus();
            textarea.setSelectionRange(beforeStart, beforeStart + sel.length);
            historyPush(true);
            schedulePreview();
            return;
        }

        // Cas 3 : application normale du marquage.
        sel = sel || (placeholder || '');
        const insert = before + sel + after;
        textarea.value = val.slice(0, start) + insert + val.slice(end);
        const cursor = start + before.length;
        textarea.focus();
        textarea.setSelectionRange(cursor, cursor + sel.length);
        historyPush(true);
        schedulePreview();
    }

    function prefixLines(prefix) {
        historyPush(true);
        const start = textarea.selectionStart;
        const end   = textarea.selectionEnd;
        const val   = textarea.value;
        // Étend au début de ligne
        let lineStart = val.lastIndexOf('\n', start - 1) + 1;
        const block = val.slice(lineStart, end);
        const replaced = block.split('\n').map((l, idx) => {
            if (prefix === 'ol') return (idx + 1) + '. ' + l;
            return prefix + l;
        }).join('\n');
        textarea.value = val.slice(0, lineStart) + replaced + val.slice(end);
        textarea.focus();
        historyPush(true);
        schedulePreview();
    }

    // Empêche la perte de focus/sélection du textarea au clic sur un bouton.
    document.getElementById('review-toolbar')?.addEventListener('mousedown', function (e) {
        if (e.target.closest('.rt-btn')) e.preventDefault();
    });

    document.getElementById('review-toolbar')?.addEventListener('click', function (e) {
        const btn = e.target.closest('.rt-btn');
        if (!btn) return;
        const cmd = btn.dataset.md;
        switch (cmd) {
            case 'undo':      undo(); break;
            case 'redo':      redo(); break;
            case 'bold':      wrapSelection('**', '**', 'texte en gras'); break;
            case 'italic':    wrapSelection('*', '*', 'texte en italique'); break;
            case 'underline': wrapSelection('++', '++', 'texte souligné'); break;
            case 'strike':    wrapSelection('~~', '~~', 'texte barré'); break;
            case 'code':      wrapSelection('`', '`', 'code'); break;
            case 'h1':        prefixLines('# '); break;
            case 'h2':        prefixLines('## '); break;
            case 'h3':        prefixLines('### '); break;
            case 'ul':        prefixLines('- '); break;
            case 'ol':        prefixLines('ol'); break;
            case 'quote':     prefixLines('> '); break;
            case 'link':      openInsert('link', getSelection()); break;
            case 'image':     openInsert('image', getSelection()); break;
            case 'media':     openInsert('media'); break;
        }
    });

    // ── Modale d'insertion (lien / image / média) ────────────────────────────
    const insertModal = document.getElementById('review-insert-modal');
    const insertUrl   = document.getElementById('review-insert-url');
    const insertText  = document.getElementById('review-insert-text');
    const insertTextWrap = document.getElementById('review-insert-text-wrap');
    const insertTitle = document.getElementById('review-insert-title');
    const insertHint  = document.getElementById('review-insert-hint');
    const insertError = document.getElementById('review-insert-error');
    let insertMode = 'link';

    function getSelection() {
        return textarea.value.slice(textarea.selectionStart, textarea.selectionEnd);
    }

    function openInsert(mode, prefillText) {
        insertMode = mode;
        insertUrl.value = '';
        insertText.value = prefillText || '';
        insertError.textContent = '';
        insertError.style.display = 'none';
        if (mode === 'link') {
            insertTitle.textContent = 'Insérer un lien';
            insertTextWrap.style.display = '';
            insertHint.textContent = 'Le lien s\'ouvrira dans un nouvel onglet.';
        } else if (mode === 'image') {
            insertTitle.textContent = 'Insérer une image';
            insertTextWrap.style.display = '';
            insertHint.textContent = 'Lien direct vers l\'image (jpg, png, gif, webp…). Non hébergée sur le site.';
        } else {
            insertTitle.textContent = 'Insérer un média';
            insertTextWrap.style.display = 'none';
            insertHint.textContent = 'YouTube, Vimeo, SoundCloud, ou lien direct (mp3, mp4…). Non hébergé sur le site.';
        }
        insertModal.classList.add('modal-active');
        setTimeout(() => insertUrl.focus(), 50);
    }
    function closeInsert() { insertModal.classList.remove('modal-active'); }

    document.getElementById('review-insert-cancel')?.addEventListener('click', closeInsert);
    document.getElementById('review-insert-close')?.addEventListener('click', closeInsert);
    insertModal?.addEventListener('click', e => { if (e.target === insertModal) closeInsert(); });

    document.getElementById('review-insert-ok')?.addEventListener('click', function () {
        const url = insertUrl.value.trim();
        if (url === '') { closeInsert(); return; }
        if (!/^https?:\/\//i.test(url)) {
            insertError.textContent = 'L\'URL doit commencer par http:// ou https://';
            insertError.style.display = '';
            insertUrl.focus();
            return;
        }
        let snippet = '';
        if (insertMode === 'link') {
            const label = insertText.value.trim() || url;
            snippet = `[${label}](${url})`;
            insertAtCursor(snippet);
        } else if (insertMode === 'image') {
            const alt = insertText.value.trim();
            snippet = `![${alt}](${url})`;
            insertAtCursor(snippet);
        } else {
            // Média : sur sa propre ligne
            snippet = `\n@[média](${url})\n`;
            insertAtCursor(snippet);
        }
        closeInsert();
    });

    function insertAtCursor(text) {
        historyPush(true);
        const start = textarea.selectionStart;
        const end   = textarea.selectionEnd;
        const val   = textarea.value;
        textarea.value = val.slice(0, start) + text + val.slice(end);
        const pos = start + text.length;
        textarea.focus();
        textarea.setSelectionRange(pos, pos);
        historyPush(true);
        schedulePreview();
    }

    // ── Toggle aperçu (mobile) ───────────────────────────────────────────────
    previewToggle?.addEventListener('click', function () {
        splitEl.classList.toggle('show-preview');
        this.textContent = splitEl.classList.contains('show-preview') ? 'Éditer' : 'Aperçu';
        if (splitEl.classList.contains('show-preview')) renderPreview();
    });

    // ── Enregistrer / Supprimer / Retour ─────────────────────────────────────
    saveBtn?.addEventListener('click', async function () {
        const seriesId = seriesIdInput.value;
        const content  = textarea.value;
        if (!seriesId) { showCustomAlert('Série manquante', 'Veuillez choisir une série.'); return; }
        if (content.trim() === '') { showCustomAlert('Contenu vide', 'La critique est vide.'); return; }
        const data = await api('save', { series_id: seriesId, content: content });
        if (data.success) {
            showCustomAlert('Succès', 'Critique enregistrée.');
            deleteBtn.style.display = '';
        } else {
            showCustomAlert('Erreur', data.message || 'Enregistrement impossible.');
        }
    });

    deleteBtn?.addEventListener('click', async function () {
        const seriesId = seriesIdInput.value;
        if (!seriesId) return;
        const ok = await showCustomConfirm('Confirmation', 'Supprimer cette critique ?');
        if (!ok) return;
        const data = await api('delete', { series_id: seriesId });
        if (data.success) {
            showCustomAlert('Supprimée', 'Critique supprimée.');
            showList();
        } else {
            showCustomAlert('Erreur', data.message || 'Suppression impossible.');
        }
    });

    backBtn?.addEventListener('click', showList);

    newBtn?.addEventListener('click', function () {
        showEditor();
        resetEditor();
        setTimeout(() => seriesSearch.focus(), 50);
    });

    // ── Init ─────────────────────────────────────────────────────────────────
    resetTypeFilter();
    setupSeriesSearch();
    if (window.reviewPrefillSeriesId) {
        openEditor(window.reviewPrefillSeriesId);
    } else {
        showList();
    }
})();
