/**
 * assets/js/admin/nautiljon.js
 * Gestion du refresh asynchrone des tomes VF Nautiljon.
 *
 * Affiche un indicateur ⏳ + texte en clair à côté du bouton de déconnexion,
 * mis à jour série par série pendant le refresh de la queue.
 */

(function () {
    'use strict';

    const QUEUE      = window.nautiljonRefreshQueue || [];
    const ENABLED    = window.nautiljonEnabled === true;
    const INDICATOR  = document.getElementById('nautiljon-indicator');
    const LABEL      = document.getElementById('nautiljon-indicator-label');

    if (!ENABLED || QUEUE.length === 0) return;

    // ── Helpers indicateur ────────────────────────────────────────────────────

    function indicatorShow(text) {
        if (INDICATOR) INDICATOR.style.display = 'flex';
        if (LABEL)     LABEL.textContent = text;
    }

    function indicatorHide() {
        if (INDICATOR) INDICATOR.style.display = 'none';
        if (LABEL)     LABEL.textContent = '';
    }

    // ── Requête AJAX ──────────────────────────────────────────────────────────

    async function refreshSeries(seriesId) {
        const body = new URLSearchParams({ action: 'nautiljon_refresh', series_id: seriesId });
        const res  = await fetch('admin.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    // ── Mise à jour du badge dans la carte ────────────────────────────────────

    function updateCardBadge(seriesId, vfVolumes) {
        const card = document.querySelector(`.series-card[data-series-id="${CSS.escape(seriesId)}"]`);
        if (!card) return;
        const badge = card.querySelector('.nautiljon-badge');
        if (!badge) return;
        if (vfVolumes !== null && vfVolumes !== undefined) {
            badge.title = vfVolumes + ' tomes VF · Voir sur Nautiljon';
        }
    }

    // ── Traitement de la queue ────────────────────────────────────────────────

    async function processQueue() {
        for (let i = 0; i < QUEUE.length; i++) {
            const seriesId = QUEUE[i];
            const card     = document.querySelector(`.series-card[data-series-id="${CSS.escape(seriesId)}"]`);
            const name     = card ? (card.querySelector('h2')?.textContent?.trim() || seriesId) : seriesId;

            indicatorShow(`Nautiljon (${i + 1}/${QUEUE.length}) — ${name}`);

            try {
                const data = await refreshSeries(seriesId);
                if (data.success) updateCardBadge(seriesId, data.vf_volumes);
            } catch (err) {
                console.warn('[Nautiljon] Refresh échoué pour', seriesId, ':', err.message);
            }

            if (i < QUEUE.length - 1) {
                await new Promise(r => setTimeout(r, 800));
            }
        }

        indicatorHide();
    }

    // Démarrage différé pour laisser les cartes se rendre
    setTimeout(processQueue, 2000);

})();
