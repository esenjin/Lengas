// ──────────────────────────────────────────────────────────────────────────────
// assets/js/admin/highlights.js
// Page « Profil » de l'administrateur : bloc « Mise en lumière ».
//
// Permet de choisir, par collection (manga / anime), jusqu'à
// window.highlightsMax séries de sa bibliothèque à mettre en avant sur son
// profil. Recherche instantanée (client, sur window.highlightCandidates) +
// panier de « slots » réordonnables (mêmes boutons ↑/▲ ▼/▼ que les liens
// personnalisés et sociaux). Chaque changement (ajout, retrait, réordre) est
// enregistré immédiatement en AJAX (profil_action=save_highlights), sans
// attendre le bouton « Enregistrer le profil » du reste de la page.
// ──────────────────────────────────────────────────────────────────────────────
(function () {
    'use strict';

    const ENDPOINT = 'page-profil.php';
    const field = document.getElementById('highlights-field');
    if (!field) return;

    const MAX = window.highlightsMax || 5;
    const candidates = window.highlightCandidates || {};
    const selected   = window.highlightSelected   || {};

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

    async function saveHighlights(type, ids) {
        const fd = new URLSearchParams();
        fd.append('profil_action', 'save_highlights');
        // Envoie toujours les DEUX types (l'autre inchangé lu depuis le DOM)
        // pour ne jamais écraser silencieusement l'autre collection.
        document.querySelectorAll('.highlights-group').forEach(group => {
            const t = group.dataset.highlightType;
            const list = (t === type) ? ids : currentIds(group);
            fd.append('highlights_' + t, list.join(','));
        });
        try {
            const res = await fetch(ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: fd.toString()
            });
            await res.json();
        } catch (err) {
            console.error('Erreur d\u2019enregistrement de la mise en lumière :', err);
        }
    }

    function currentIds(group) {
        return Array.from(group.querySelectorAll('[data-slots] .highlights-slot'))
            .map(el => el.dataset.id);
    }

    function candidateById(type, id) {
        return (candidates[type] || []).find(c => c.id === id);
    }

    function buildSlot(type, item) {
        const slot = document.createElement('div');
        slot.className = 'highlights-slot';
        slot.dataset.id = item.id;
        const thumb = item.thumb ? ('../' + item.thumb) : '../assets/img/logo.png';
        slot.innerHTML = `
            <img class="highlights-slot-thumb" src="${htmlEscape(thumb)}" alt="" loading="lazy">
            <span class="highlights-slot-name">${htmlEscape(item.name)}</span>
            <div class="custom-link-actions">
                <button type="button" class="custom-link-move highlights-slot-up" title="Monter" aria-label="Monter">▲</button>
                <button type="button" class="custom-link-move highlights-slot-down" title="Descendre" aria-label="Descendre">▼</button>
                <button type="button" class="custom-link-remove highlights-slot-remove" title="Retirer" aria-label="Retirer">&times;</button>
            </div>
        `;
        return slot;
    }

    function refreshGroup(group) {
        const type      = group.dataset.highlightType;
        const slotsWrap = group.querySelector('[data-slots]');
        const countEl   = group.querySelector('.highlights-count-value');
        const emptyHint = group.querySelector('[data-empty-hint]');
        const searchInput = group.querySelector('.highlights-search');
        const matureWarning = group.querySelector('[data-mature-warning]');

        const ids = currentIds(group);
        countEl.textContent = ids.length;
        emptyHint.style.display = ids.length ? 'none' : '';
        searchInput.disabled = ids.length >= MAX;
        searchInput.placeholder = ids.length >= MAX
            ? `Maximum de ${MAX} séries atteint`
            : 'Rechercher une série à ajouter…';

        // Avertissement « série mature masquée » : affiché uniquement si au
        // moins une des séries actuellement sélectionnées est mature (le
        // masquage mature, lui, est déjà su statiquement — cf. PHP — donc ce
        // bloc n'existe dans le DOM que si ce réglage est actif pour ce type).
        if (matureWarning) {
            const hasMature = ids.some(id => {
                const item = candidateById(type, id);
                return item && item.mature;
            });
            matureWarning.hidden = !hasMature;
        }

        // Boutons monter/descendre : désactivés en bout de liste.
        const slots = Array.from(slotsWrap.querySelectorAll('.highlights-slot'));
        slots.forEach((slot, i) => {
            slot.querySelector('.highlights-slot-up').disabled   = (i === 0);
            slot.querySelector('.highlights-slot-down').disabled = (i === slots.length - 1);
        });
    }

    function addToGroup(group, item) {
        const type = group.dataset.highlightType;
        const slotsWrap = group.querySelector('[data-slots]');
        if (currentIds(group).includes(item.id)) return;
        if (currentIds(group).length >= MAX) return;
        slotsWrap.appendChild(buildSlot(type, item));
        refreshGroup(group);
        saveHighlights(type, currentIds(group));
    }

    function removeFromGroup(group, id) {
        const type = group.dataset.highlightType;
        const slotsWrap = group.querySelector('[data-slots]');
        const slot = slotsWrap.querySelector(`.highlights-slot[data-id="${cssEscape(id)}"]`);
        if (slot) slot.remove();
        refreshGroup(group);
        saveHighlights(type, currentIds(group));
    }

    function moveSlot(group, slot, dir) {
        const type = group.dataset.highlightType;
        const slotsWrap = group.querySelector('[data-slots]');
        const slots = Array.from(slotsWrap.querySelectorAll('.highlights-slot'));
        const idx = slots.indexOf(slot);
        const targetIdx = idx + dir;
        if (targetIdx < 0 || targetIdx >= slots.length) return;
        if (dir < 0) {
            slotsWrap.insertBefore(slot, slots[targetIdx]);
        } else {
            slotsWrap.insertBefore(slots[targetIdx], slot);
        }
        refreshGroup(group);
        saveHighlights(type, currentIds(group));
    }

    function cssEscape(s) {
        return String(s).replace(/["\\]/g, '\\$&');
    }

    // ── Rendu initial des slots déjà sélectionnés ────────────────────────────
    document.querySelectorAll('.highlights-group').forEach(group => {
        const type = group.dataset.highlightType;
        const slotsWrap = group.querySelector('[data-slots]');
        (selected[type] || []).forEach(id => {
            const item = candidateById(type, id);
            if (!item) return; // série supprimée depuis, on l'ignore simplement
            slotsWrap.appendChild(buildSlot(type, item));
        });
        refreshGroup(group);

        // ── Recherche instantanée ────────────────────────────────────────────
        const searchInput = group.querySelector('.highlights-search');
        const resultsBox  = group.querySelector('.highlights-search-results');

        function renderResults() {
            const term = normalizeString(searchInput.value);
            const already = currentIds(group);
            const pool = (candidates[type] || []).filter(c => !already.includes(c.id));
            const matches = term === '' ? [] : pool.filter(c => normalizeString(c.name).includes(term)).slice(0, 8);

            if (matches.length === 0) {
                resultsBox.style.display = 'none';
                resultsBox.innerHTML = '';
                return;
            }
            resultsBox.innerHTML = '';
            matches.forEach(c => {
                const row = document.createElement('div');
                row.className = 'highlights-search-row';
                const thumb = c.thumb ? ('../' + c.thumb) : '../assets/img/logo.png';
                row.innerHTML = `
                    <img class="highlights-search-thumb" src="${htmlEscape(thumb)}" alt="" loading="lazy">
                    <span>${htmlEscape(c.name)}</span>
                `;
                row.addEventListener('click', () => {
                    addToGroup(group, c);
                    searchInput.value = '';
                    resultsBox.style.display = 'none';
                    resultsBox.innerHTML = '';
                });
                resultsBox.appendChild(row);
            });
            resultsBox.style.display = 'block';
        }

        searchInput.addEventListener('input', renderResults);
        searchInput.addEventListener('focus', renderResults);
        document.addEventListener('click', e => {
            if (!searchInput.contains(e.target) && !resultsBox.contains(e.target)) {
                resultsBox.style.display = 'none';
            }
        });

        // ── Actions sur un slot (retrait, déplacement) ───────────────────────
        slotsWrap.addEventListener('click', e => {
            const slot = e.target.closest('.highlights-slot');
            if (!slot) return;
            if (e.target.closest('.highlights-slot-remove')) {
                removeFromGroup(group, slot.dataset.id);
            } else if (e.target.closest('.highlights-slot-up')) {
                moveSlot(group, slot, -1);
            } else if (e.target.closest('.highlights-slot-down')) {
                moveSlot(group, slot, 1);
            }
        });
    });
})();
