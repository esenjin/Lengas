/**
 * assets/js/admin/nautiljon.js
 * Gestion du refresh asynchrone des tomes VF Nautiljon.
 *
 * Au chargement de la page :
 *  - Lit window.nautiljonRefreshQueue (série IDs dont le cache est périmé)
 *  - Traite la queue une série à la fois via AJAX
 *  - Affiche un sablier animé (⏳) à côté du bouton de déconnexion
 *  - Met à jour le badge Nautiljon de la carte concernée une fois terminé
 */

(function () {
    'use strict';

    // ── Config ─────────────────────────────────────────────────────────────────

    const QUEUE      = window.nautiljonRefreshQueue || [];
    const ENABLED    = window.nautiljonEnabled === true;
    const INDICATOR  = document.getElementById('nautiljon-indicator');

    if (!ENABLED || QUEUE.length === 0) return;

    // ── Indicateur sablier ─────────────────────────────────────────────────────

    let _activeTask = '';

    function indicatorShow(label) {
        _activeTask = label;
        if (INDICATOR) {
            INDICATOR.style.display = 'block';
            INDICATOR.title = label;
        }
    }

    function indicatorHide() {
        _activeTask = '';
        if (INDICATOR) {
            INDICATOR.style.display = 'none';
            INDICATOR.title = '';
        }
    }

    // ── Requête AJAX ───────────────────────────────────────────────────────────

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

    // ── Mise à jour du badge Nautiljon dans la carte ───────────────────────────

    function updateCardBadge(seriesId, vfVolumes) {
        // Cherche la carte par data-series-id
        const card = document.querySelector(`.series-card[data-series-id="${CSS.escape(seriesId)}"]`);
        if (!card) return;

        const badge = card.querySelector('.nautiljon-badge');
        if (!badge) return;

        if (vfVolumes !== null && vfVolumes !== undefined) {
            badge.title = vfVolumes + ' tomes VF · Voir sur Nautiljon';
        }
    }

    // ── Traitement de la queue ─────────────────────────────────────────────────

    async function processQueue() {
        for (let i = 0; i < QUEUE.length; i++) {
            const seriesId = QUEUE[i];

            // Trouver le nom de la série pour le tooltip
            const card = document.querySelector(`.series-card[data-series-id="${CSS.escape(seriesId)}"]`);
            const name = card ? (card.querySelector('h2')?.textContent || seriesId) : seriesId;

            indicatorShow(`Nautiljon · Mise à jour : ${name} (${i + 1}/${QUEUE.length})`);

            try {
                const data = await refreshSeries(seriesId);
                if (data.success) {
                    updateCardBadge(seriesId, data.vf_volumes);
                }
            } catch (err) {
                console.warn('[Nautiljon] Refresh échoué pour', seriesId, ':', err.message);
            }

            // Petite pause entre chaque requête pour ne pas saturer Browserless
            if (i < QUEUE.length - 1) {
                await new Promise(r => setTimeout(r, 800));
            }
        }

        indicatorHide();
    }

    // ── Démarrage différé (après le chargement des cartes) ────────────────────

    // On attend 2 secondes pour laisser le temps aux cartes de se rendre
    setTimeout(processQueue, 2000);

})();
