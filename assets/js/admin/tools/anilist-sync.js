// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/anilist-sync.js — Sous-onglet « Vérification via
// Anilist »
//
// Synchronisation en flux (SSE) des séries animées dont la diffusion et le
// visionnage sont tous deux « en cours » : nouveaux épisodes diffusés et
// statut de diffusion. Réutilise exactement le moteur qui tourne aussi en
// arrière-plan à l'affichage de l'Animethèque (fonctions/tools/
// anilist_sync.php) — cet outil ne fait qu'offrir un déclenchement explicite,
// avec un bouton de forçage qui ignore le verrou de 24h habituel.
//
// Gabarit repris tel quel de assets/js/admin/tools/incomplete.js et
// anilist-import.js : même structure de progression (SSE « progress » /
// « done »), mêmes classes CSS (.analysis-progress, .analysis-summary,
// .summary-group, .summary-badge…), pour rester visuellement cohérent avec
// les autres sous-onglets de « Complétude des séries ».
// ──────────────────────────────────────────────────────────────────────────

(function () {
    const launchBtn      = document.getElementById('anilist-sync-launch');
    const launchForceBtn = document.getElementById('anilist-sync-launch-force');
    const progressEl     = document.getElementById('anilist-sync-progress');
    const resultsEl      = document.getElementById('anilist-sync-results');

    // Sous-onglet absent (aucune série animée dans la collection) : rien à
    // faire, comme les autres outils conditionnés à la même règle.
    if (!launchBtn || !launchForceBtn || !progressEl || !resultsEl) return;

    let currentEs = null;

    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setButtonsDisabled(disabled) {
        launchBtn.disabled = disabled;
        launchForceBtn.disabled = disabled;
    }

    function renderProgress(current, total, title) {
        const pct = total > 0 ? Math.round((current / total) * 100) : 0;
        progressEl.innerHTML = `
            <p class="analysis-progress">
                <span class="progress-spinner"></span>
                <span class="progress-count">(${current} / ${total}${total > 0 ? ' — ~' + pct + '%' : ''})</span>
                Synchronisation : <strong>${esc(title || '…')}</strong>
            </p>`;
    }

    function renderResults(d) {
        if (!d.success) {
            resultsEl.innerHTML = `<p class="incomplete-empty-msg">${esc(d.message || "La synchronisation a échoué.")}</p>`;
            return;
        }

        const synced    = d.synced    || [];
        const unchanged = d.unchanged || [];
        const skipped   = d.skipped   || [];
        const errors    = d.errors    || [];

        if ((d.processed || 0) === 0 && !errors.length) {
            resultsEl.innerHTML = `<p class="incomplete-empty-msg">${esc(d.message || "Aucune série à synchroniser pour le moment.")}</p>`;
            return;
        }

        let html = '<div class="analysis-summary"><h3 class="summary-title">Récapitulatif de la synchronisation</h3>';
        html += `<div class="anilist-import-dest-counts">
            <div class="anilist-import-dest-count anilist-import-dest-count--library"><span class="anilist-import-dest-count-value">${synced.length}</span><span class="anilist-import-dest-count-label">Mises à jour</span></div>
            <div class="anilist-import-dest-count anilist-import-dest-count--existing"><span class="anilist-import-dest-count-value">${unchanged.length}</span><span class="anilist-import-dest-count-label">Déjà à jour</span></div>
            <div class="anilist-import-dest-count anilist-import-dest-count--error"><span class="anilist-import-dest-count-value">${errors.length}</span><span class="anilist-import-dest-count-label">En erreur</span></div>
        </div>`;

        if (synced.length) {
            html += `<details class="summary-group" open><summary><span class="summary-badge">${synced.length}</span> Épisodes ou statut mis à jour</summary>
                <ul class="summary-list">${synced.map(m => `<li>${esc(m)}</li>`).join('')}</ul></details>`;
        }

        if (unchanged.length) {
            html += `<details class="summary-group"><summary><span class="summary-badge summary-badge--muted">— ${unchanged.length}</span> Rien de nouveau</summary>
                <ul class="summary-list">${unchanged.map(m => `<li>${esc(m)}</li>`).join('')}</ul></details>`;
        }

        if (errors.length) {
            html += `<details class="summary-group" open><summary><span class="summary-badge summary-badge--warn">⚠ ${errors.length}</span> En erreur (verrou reporté à 1 h)</summary>
                <ul class="summary-list">${errors.map(e => `<li><strong>${esc(e.title)}</strong> — <span class="summary-reason">${esc(e.message)}</span></li>`).join('')}</ul></details>`;
        }

        html += '</div>';
        resultsEl.innerHTML = html;
    }

    function launchSync(force) {
        if (currentEs) return; // une campagne est déjà en cours

        setButtonsDisabled(true);
        resultsEl.innerHTML = '';
        renderProgress(0, 0, force ? 'Recensement des séries éligibles…' : 'Recensement des séries dues…');

        const url = 'outil-anilist-sync.php?action=anilist_sync_stream' + (force ? '&force=1' : '');
        const es = new EventSource(url);
        currentEs = es;

        es.addEventListener('progress', e => {
            const d = JSON.parse(e.data);
            renderProgress(d.current, d.total, d.title);
        });

        es.addEventListener('done', e => {
            es.close();
            currentEs = null;
            setButtonsDisabled(false);
            progressEl.innerHTML = '';
            renderResults(JSON.parse(e.data));
        });

        es.onerror = () => {
            es.close();
            currentEs = null;
            setButtonsDisabled(false);
            progressEl.innerHTML = '';
            resultsEl.innerHTML = `<p class="incomplete-empty-msg">La connexion avec le serveur a été interrompue pendant la synchronisation. Les séries déjà traitées ont bien été enregistrées.</p>`;
        };
    }

    launchBtn.addEventListener('click', () => launchSync(false));
    launchForceBtn.addEventListener('click', () => launchSync(true));
})();
