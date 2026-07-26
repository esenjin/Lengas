// ──────────────────────────────────────────────────────────────────────────────
// assets/js/admin/profil.js
// Page « Profil » de l'administrateur :
//   1. Éditeur Markdown de la biographie (barre d'outils + aperçu split),
//      calqué sur l'éditeur des critiques. L'aperçu est rendu côté serveur
//      (endpoint profil_action=preview) pour être STRICTEMENT identique au
//      rendu public et éviter tout XSS côté client.
//   2. Liens sociaux : ajout/suppression à la volée + sélecteur d'icône et de
//      couleur, réutilisant le même système que les liens personnalisés.
//   3. Aperçu de la photo de profil au choix d'un fichier.
// ──────────────────────────────────────────────────────────────────────────────
(function () {
    'use strict';

    const ENDPOINT = 'page-profil.php';

    // ═══════════════════════════════════════════════════════════════════════
    //  APERÇU DE LA PHOTO DE PROFIL
    // ═══════════════════════════════════════════════════════════════════════
    (function () {
        const input   = document.getElementById('admin_avatar');
        const img     = document.getElementById('profil-avatar-img');
        const removeCb = document.querySelector('input[name="remove_avatar"]');
        if (!input || !img) return;

        input.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = e => { img.src = e.target.result; };
            reader.readAsDataURL(file);
            // Choisir une nouvelle image annule une éventuelle suppression cochée.
            if (removeCb) removeCb.checked = false;
        });
    })();

    // ═══════════════════════════════════════════════════════════════════════
    //  ÉDITEUR MARKDOWN DE LA BIOGRAPHIE
    // ═══════════════════════════════════════════════════════════════════════
    (function () {
        const textarea   = document.getElementById('bio-content');
        const previewBox = document.getElementById('bio-preview');
        const splitEl    = document.getElementById('bio-split');
        const toolbar    = document.getElementById('bio-toolbar');
        const previewToggle = document.getElementById('bio-preview-toggle');
        if (!textarea) return;

        async function api(action, extra) {
            const fd = new URLSearchParams();
            fd.append('profil_action', action);
            for (const k in (extra || {})) fd.append(k, extra[k]);
            const res = await fetch(ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: fd.toString()
            });
            return res.json();
        }

        // ── Historique undo/redo (le textarea perd son historique natif dès
        //    qu'on modifie .value par script depuis la barre d'outils) ────────
        const history = { stack: [], index: -1, lastPush: 0 };
        function historyReset(value) {
            history.stack = [{ value: value, start: 0, end: 0 }];
            history.index = 0;
        }
        function historyPush(force) {
            const now = Date.now();
            const cur = { value: textarea.value, start: textarea.selectionStart, end: textarea.selectionEnd };
            const top = history.stack[history.index];
            if (top && top.value === cur.value) {
                top.start = cur.start; top.end = cur.end;
                return;
            }
            if (!force && top && (now - history.lastPush) < 500 && history.index === history.stack.length - 1) {
                history.stack[history.index] = cur;
                history.lastPush = now;
                return;
            }
            history.stack = history.stack.slice(0, history.index + 1);
            history.stack.push(cur);
            history.index = history.stack.length - 1;
            history.lastPush = now;
            if (history.stack.length > 200) { history.stack.shift(); history.index--; }
        }
        function historyApply(entry) {
            textarea.value = entry.value;
            textarea.focus();
            textarea.setSelectionRange(entry.start, entry.end);
            schedulePreview();
        }
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
            if (history.index > 0) { history.index--; historyApply(history.stack[history.index]); }
        }
        function redo() {
            if (history.index < history.stack.length - 1) { history.index++; historyApply(history.stack[history.index]); }
        }

        // ── Aperçu serveur (débounce) ────────────────────────────────────────
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
            if (data.success) previewBox.innerHTML = data.html || '';
        }

        textarea.addEventListener('input', function () { historyPush(false); schedulePreview(); });
        textarea.addEventListener('keydown', function (e) {
            const mod = e.ctrlKey || e.metaKey;
            if (!mod) return;
            const k = e.key.toLowerCase();
            if (k === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
            else if ((k === 'y') || (k === 'z' && e.shiftKey)) { e.preventDefault(); redo(); }
        });

        // ── Barre d'outils Markdown (bascule le marquage) ────────────────────
        function wrapSelection(before, after, placeholder) {
            historyPush(true);
            after = after === undefined ? before : after;
            const start = textarea.selectionStart;
            const end   = textarea.selectionEnd;
            const val   = textarea.value;
            let sel     = val.slice(start, end);

            const innerNotLonger =
                !(before === after && before.length === 1 &&
                  sel.length >= 2 && sel[before.length] === before);
            if (sel.length >= before.length + after.length && innerNotLonger &&
                sel.startsWith(before) && sel.endsWith(after)) {
                const inner = sel.slice(before.length, sel.length - after.length);
                textarea.value = val.slice(0, start) + inner + val.slice(end);
                textarea.focus();
                textarea.setSelectionRange(start, start + inner.length);
                historyPush(true); schedulePreview();
                return;
            }

            const beforeStart = start - before.length;
            const afterEnd    = end + after.length;
            const notPartOfLonger =
                !(before === after && before.length === 1 &&
                  (val[beforeStart - 1] === before || val[afterEnd] === after));
            if (beforeStart >= 0 && afterEnd <= val.length && notPartOfLonger &&
                val.slice(beforeStart, start) === before &&
                val.slice(end, afterEnd) === after) {
                textarea.value = val.slice(0, beforeStart) + sel + val.slice(afterEnd);
                textarea.focus();
                textarea.setSelectionRange(beforeStart, beforeStart + sel.length);
                historyPush(true); schedulePreview();
                return;
            }

            sel = sel || (placeholder || '');
            const insert = before + sel + after;
            textarea.value = val.slice(0, start) + insert + val.slice(end);
            const cursor = start + before.length;
            textarea.focus();
            textarea.setSelectionRange(cursor, cursor + sel.length);
            historyPush(true); schedulePreview();
        }

        function prefixLines(prefix) {
            historyPush(true);
            const start = textarea.selectionStart;
            const end   = textarea.selectionEnd;
            const val   = textarea.value;
            let lineStart = val.lastIndexOf('\n', start - 1) + 1;
            const block = val.slice(lineStart, end);
            const replaced = block.split('\n').map((l, idx) => {
                if (prefix === 'ol') return (idx + 1) + '. ' + l;
                return prefix + l;
            }).join('\n');
            textarea.value = val.slice(0, lineStart) + replaced + val.slice(end);
            textarea.focus();
            historyPush(true); schedulePreview();
        }

        // Empêche la perte de focus/sélection du textarea au clic sur un bouton.
        toolbar?.addEventListener('mousedown', function (e) {
            if (e.target.closest('.rt-btn')) e.preventDefault();
        });

        toolbar?.addEventListener('click', function (e) {
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

        // ── Modale d'insertion (lien / image / média) ────────────────────────
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
            historyPush(true); schedulePreview();
        }

        // ── Toggle aperçu (mobile) ───────────────────────────────────────────
        previewToggle?.addEventListener('click', function () {
            splitEl.classList.toggle('show-preview');
            this.textContent = splitEl.classList.contains('show-preview') ? 'Éditer' : 'Aperçu';
            if (splitEl.classList.contains('show-preview')) renderPreview();
        });

        // ── Init ─────────────────────────────────────────────────────────────
        historyReset(textarea.value);
        if (textarea.value.trim() !== '') renderPreview();
    })();

    // ═══════════════════════════════════════════════════════════════════════
    //  LIENS SOCIAUX : ajout / suppression + sélecteur icône & couleur
    //  (adapté du script inline de page-options.php)
    // ═══════════════════════════════════════════════════════════════════════
    (function () {
        const COLORS = window.profilColors || {};
        const DEFAULT_COLOR = window.profilDefaultColor || 'green';

        const list    = document.getElementById('social-links-list');
        const tpl      = document.getElementById('social-link-template');
        const addBtn  = document.getElementById('add-social-link-btn');
        const emptyEl = document.getElementById('social-links-empty');

        const modal   = document.getElementById('icon-picker-modal');
        const closeEl = document.getElementById('close-icon-picker-modal');
        const search  = document.getElementById('icon-picker-search');
        const emptyIc = document.getElementById('icon-picker-empty');
        const colorRow = document.getElementById('icon-picker-colors');

        if (!list || !modal) return;

        function hex(colorKey) { return COLORS[colorKey] || COLORS[DEFAULT_COLOR]; }
        function iconUrl(path, colorKey) {
            return 'https://api.iconify.design/' + path + '.svg?color=' + encodeURIComponent(hex(colorKey));
        }

        function refreshEmpty() {
            if (!emptyEl) return;
            emptyEl.style.display = list.querySelector('.custom-link-card') ? 'none' : '';
        }

        function activateCard(card) {
            card.querySelector('.cl-name').setAttribute('name', 'social_link_name[]');
            card.querySelector('.cl-url').setAttribute('name', 'social_link_url[]');
            card.querySelector('.cl-icon').setAttribute('name', 'social_link_icon[]');
            card.querySelector('.cl-color').setAttribute('name', 'social_link_color[]');
            card.removeAttribute('data-template');
        }

        function wireCard(card) {
            const removeBtn = card.querySelector('.custom-link-remove');
            if (removeBtn) removeBtn.addEventListener('click', function () {
                card.remove();
                refreshEmpty();
            });
            const trigger = card.querySelector('.custom-icon-trigger');
            if (trigger) trigger.addEventListener('click', function () { openModal(card); });
        }

        if (addBtn && tpl) {
            addBtn.addEventListener('click', function () {
                const frag = tpl.content.cloneNode(true);
                const card = frag.querySelector('.custom-link-card');
                activateCard(card);
                list.appendChild(card);
                wireCard(card);
                refreshEmpty();
                card.querySelector('.cl-name').focus();
            });
        }

        list.querySelectorAll('.custom-link-card').forEach(wireCard);
        refreshEmpty();

        // ── Modale icône + couleur ───────────────────────────────────────────
        let activeCard = null;

        function currentColor() {
            if (!activeCard) return DEFAULT_COLOR;
            const c = activeCard.querySelector('.cl-color').value;
            return COLORS[c] ? c : DEFAULT_COLOR;
        }

        function paintPreviewItems(colorKey) {
            modal.querySelectorAll('.icon-picker-item').forEach(function (item) {
                const img = item.querySelector('img');
                if (img) img.src = iconUrl(item.dataset.iconPath, colorKey);
            });
        }

        function markSelectedColor(colorKey) {
            if (!colorRow) return;
            colorRow.querySelectorAll('.icon-picker-color').forEach(function (sw) {
                sw.classList.toggle('is-selected', sw.dataset.color === colorKey);
            });
        }

        function openModal(card) {
            activeCard = card;
            const iconKey  = card.querySelector('.cl-icon').value || 'link';
            const colorKey = currentColor();

            search.value = '';
            filterIcons('');
            markSelectedColor(colorKey);
            paintPreviewItems(colorKey);
            modal.querySelectorAll('.icon-picker-item').forEach(function (item) {
                item.classList.toggle('is-selected', item.dataset.key === iconKey);
            });

            modal.classList.add('modal-active');
            setTimeout(function () { search.focus(); }, 50);
        }

        function closeModal() {
            modal.classList.remove('modal-active');
            activeCard = null;
        }

        function applyPreview() {
            if (!activeCard) return;
            const iconKey  = activeCard.querySelector('.cl-icon').value || 'link';
            const colorKey = currentColor();
            const item = modal.querySelector('.icon-picker-item[data-key="' + iconKey + '"]');
            const path = item ? item.dataset.iconPath : 'mdi/link-variant';
            const img  = activeCard.querySelector('.custom-icon-preview');
            if (img) img.src = iconUrl(path, colorKey);
        }

        function chooseColor(colorKey) {
            if (!activeCard) return;
            activeCard.querySelector('.cl-color').value = colorKey;
            markSelectedColor(colorKey);
            paintPreviewItems(colorKey);
            applyPreview();
        }

        function chooseIcon(item) {
            if (!activeCard) { closeModal(); return; }
            activeCard.querySelector('.cl-icon').value = item.dataset.key;
            const lbl = activeCard.querySelector('.custom-icon-label');
            if (lbl) lbl.textContent = item.dataset.label;
            applyPreview();
            closeModal();
        }

        function filterIcons(term) {
            term = (term || '').trim().toLowerCase();
            let anyVisible = false;
            modal.querySelectorAll('[data-group]').forEach(function (group) {
                let groupHasMatch = false;
                group.querySelectorAll('.icon-picker-item').forEach(function (item) {
                    const match = !term || (item.dataset.search || '').indexOf(term) !== -1;
                    item.style.display = match ? '' : 'none';
                    if (match) { groupHasMatch = true; anyVisible = true; }
                });
                group.style.display = groupHasMatch ? '' : 'none';
            });
            if (emptyIc) emptyIc.style.display = anyVisible ? 'none' : 'block';
        }

        if (colorRow) colorRow.querySelectorAll('.icon-picker-color').forEach(function (sw) {
            sw.addEventListener('click', function () { chooseColor(sw.dataset.color); });
        });

        modal.querySelectorAll('.icon-picker-item').forEach(function (item) {
            item.addEventListener('click', function () { chooseIcon(item); });
        });

        if (search) search.addEventListener('input', function () { filterIcons(search.value); });

        if (closeEl) closeEl.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('modal-active')) closeModal();
        });
    })();
})();
