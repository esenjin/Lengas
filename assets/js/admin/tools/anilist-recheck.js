// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/anilist-recheck.js — Outil « Vérification des
// animés »
//
// Compare en flux (SSE) chaque série animée à sa fiche Anilist actuelle et
// affiche un rapport détaillé, ligne par ligne, des écarts détectés. Rien
// n'est écrit tant que l'administrateur n'a pas coché les cases voulues et
// cliqué sur « Appliquer les modifications sélectionnées ».
//
// Gabarit repris d'anilist-sync.js et anilist-import.js : mêmes classes CSS
// (.analysis-progress, .analysis-summary, .summary-group, .summary-badge…),
// pour rester visuellement cohérent avec les autres outils de la page.
// ──────────────────────────────────────────────────────────────────────────

(function () {
    const launchBtn      = document.getElementById('anilist-recheck-launch');
    const launchForceBtn = document.getElementById('anilist-recheck-launch-force');
    const progressEl     = document.getElementById('anilist-recheck-progress');
    const resultsEl      = document.getElementById('anilist-recheck-results');
    const applyRow       = document.getElementById('anilist-recheck-apply-row');
    const applyBtn       = document.getElementById('anilist-recheck-apply-btn');
    const applyText      = document.getElementById('anilist-recheck-apply-text');
    const applySpinner   = document.getElementById('anilist-recheck-apply-spinner');
    const applyResultsEl = document.getElementById('anilist-recheck-apply-results');

    // Onglet absent (aucune série animée dans la collection) : rien à faire.
    if (!launchBtn || !launchForceBtn || !progressEl || !resultsEl || !applyBtn) return;

    let currentEs = null;

    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setLaunchDisabled(disabled) {
        launchBtn.disabled = disabled;
        launchForceBtn.disabled = disabled;
    }

    function renderProgress(current, total, title) {
        const pct = total > 0 ? Math.round((current / total) * 100) : 0;
        progressEl.innerHTML = `
            <p class="analysis-progress">
                <span class="progress-spinner"></span>
                <span class="progress-count">(${current} / ${total}${total > 0 ? ' — ~' + pct + '%' : ''})</span>
                Vérification : <strong>${esc(title || '…')}</strong>
            </p>`;
    }

    // ── Rendu d'une ligne d'écart (avant → après) ───────────────────────
    function renderDiffRow(diff) {
        if (diff.info_only) {
            return `
                <li class="anilist-recheck-diff anilist-recheck-diff--info">
                    <span class="anilist-recheck-diff-label">${esc(diff.label)}</span>
                    <span class="anilist-recheck-diff-values">
                        <span class="anilist-recheck-diff-after">${esc(diff.after)}</span>
                    </span>
                    <span class="anilist-recheck-diff-note">Mis à jour par la synchronisation, pas par cet outil</span>
                </li>`;
        }
        return `
            <li class="anilist-recheck-diff">
                <label>
                    <input type="checkbox" class="anilist-recheck-field-cb" data-field="${esc(diff.field)}" checked>
                    <span class="anilist-recheck-diff-label">${esc(diff.label)}</span>
                    <span class="anilist-recheck-diff-values">
                        <span class="anilist-recheck-diff-before">${esc(diff.before)}</span>
                        <span class="anilist-recheck-diff-arrow">→</span>
                        <span class="anilist-recheck-diff-after">${esc(diff.after)}</span>
                    </span>
                </label>
            </li>`;
    }

    // ── Rendu d'une série du rapport (bloc dépliable) ───────────────────
    function renderSeriesBlock(entry) {
        const diffRows = (entry.diffs || []).map(renderDiffRow).join('');

        let altTitlesRow = '';
        if (entry.new_alt_titles && entry.new_alt_titles.length) {
            altTitlesRow = `
                <li class="anilist-recheck-diff">
                    <label>
                        <input type="checkbox" class="anilist-recheck-alt-titles-cb" checked>
                        <span class="anilist-recheck-diff-label">Nouveaux titres alternatifs</span>
                        <span class="anilist-recheck-diff-values">
                            <span class="anilist-recheck-diff-after">${esc(entry.new_alt_titles.join(', '))}</span>
                        </span>
                    </label>
                </li>`;
        }

        const linkBtn = entry.anilist_url
            ? `<a href="${esc(entry.anilist_url)}" target="_blank" rel="noopener" class="anilist-recheck-link">Voir sur Anilist</a>`
            : '';

        return `
            <details class="anilist-recheck-series" data-series-id="${esc(entry.series_id)}" open>
                <summary>
                    <span class="anilist-recheck-series-name">${esc(entry.name)}</span>
                    <span class="summary-badge">${(entry.diffs || []).filter(d => !d.info_only).length + (entry.new_alt_titles && entry.new_alt_titles.length ? 1 : 0)}</span>
                    ${linkBtn}
                </summary>
                <ul class="anilist-recheck-diff-list">
                    ${diffRows}
                    ${altTitlesRow}
                </ul>
            </details>`;
    }

    function renderResults(d) {
        applyRow.style.display = 'none';
        applyResultsEl.innerHTML = '';

        if (!d.success) {
            const msg = (d.errors && d.errors[0] && d.errors[0].message) || d.message || "La vérification a échoué.";
            resultsEl.innerHTML = `<p class="incomplete-empty-msg">${esc(msg)}</p>`;
            return;
        }

        const report  = d.report  || [];
        const missing = d.missing || [];
        const errors  = d.errors  || [];

        let html = '<div class="analysis-summary"><h3 class="summary-title">Récapitulatif de la vérification</h3>';
        html += `<div class="anilist-import-dest-counts">
            <div class="anilist-import-dest-count anilist-import-dest-count--library"><span class="anilist-import-dest-count-value">${report.length}</span><span class="anilist-import-dest-count-label">Avec écarts</span></div>
            <div class="anilist-import-dest-count anilist-import-dest-count--existing"><span class="anilist-import-dest-count-value">${d.unchanged_count || 0}</span><span class="anilist-import-dest-count-label">À jour</span></div>
            <div class="anilist-import-dest-count anilist-import-dest-count--error"><span class="anilist-import-dest-count-value">${errors.length + missing.length}</span><span class="anilist-import-dest-count-label">En erreur</span></div>
        </div></div>`;

        if (missing.length) {
            html += `<div class="analysis-summary"><details class="summary-group" open><summary><span class="summary-badge summary-badge--warn">⚠ ${missing.length}</span> Non vérifiées</summary>
                <ul class="summary-list">${missing.map(m => `<li><strong>${esc(m.name)}</strong> — <span class="summary-reason">${esc(m.reason || 'Non récupérée.')}</span></li>`).join('')}</ul>
            </details></div>`;
        }

        if (errors.length) {
            html += `<div class="analysis-summary"><details class="summary-group" open><summary><span class="summary-badge summary-badge--warn">⚠ ${errors.length}</span> Erreur de récupération</summary>
                <ul class="summary-list">${errors.map(e => `<li><strong>${esc(e.title)}</strong> — <span class="summary-reason">${esc(e.message)}</span></li>`).join('')}</ul>
            </details></div>`;
        }

        if (report.length === 0) {
            html += `<p class="incomplete-empty-msg">${missing.length || errors.length ? '' : "Aucun écart détecté : toutes les séries animées sont à jour."}</p>`;
            resultsEl.innerHTML = html;
            return;
        }

        html += '<div class="anilist-recheck-series-list">' + report.map(renderSeriesBlock).join('') + '</div>';
        resultsEl.innerHTML = html;

        applyRow.style.display = 'flex';
    }

    function launchRecheck(force) {
        if (currentEs) return; // une vérification est déjà en cours

        setLaunchDisabled(true);
        resultsEl.innerHTML = '';
        applyRow.style.display = 'none';
        applyResultsEl.innerHTML = '';
        renderProgress(0, 0, 'Recensement des séries animées…');

        const url = 'outil-anilist-recheck.php?action=anilist_recheck_stream' + (force ? '&force=1' : '');
        const es = new EventSource(url);
        currentEs = es;

        es.addEventListener('progress', e => {
            const d = JSON.parse(e.data);
            renderProgress(d.current, d.total, d.title);
        });

        es.addEventListener('done', e => {
            es.close();
            currentEs = null;
            setLaunchDisabled(false);
            progressEl.innerHTML = '';
            renderResults(JSON.parse(e.data));
        });

        es.onerror = () => {
            es.close();
            currentEs = null;
            setLaunchDisabled(false);
            progressEl.innerHTML = '';
            resultsEl.innerHTML = `<p class="incomplete-empty-msg">La connexion avec le serveur a été interrompue pendant la vérification.</p>`;
        };
    }

    launchBtn.addEventListener('click', () => launchRecheck(false));
    launchForceBtn.addEventListener('click', () => launchRecheck(true));

    // ── Validation : construit les sélections et envoie l'application ───
    applyBtn.addEventListener('click', function () {
        const selections = {};

        resultsEl.querySelectorAll('.anilist-recheck-series').forEach(block => {
            const seriesId = block.dataset.seriesId;
            const fields = [];
            block.querySelectorAll('.anilist-recheck-field-cb').forEach(cb => {
                if (cb.checked) fields.push(cb.dataset.field);
            });
            const altCb = block.querySelector('.anilist-recheck-alt-titles-cb');
            const acceptNewTitles = !!(altCb && altCb.checked);

            if (fields.length || acceptNewTitles) {
                selections[seriesId] = { fields: fields, accept_new_titles: acceptNewTitles };
            }
        });

        if (Object.keys(selections).length === 0) {
            applyResultsEl.innerHTML = `<p class="incomplete-empty-msg">Aucune case cochée : rien à appliquer.</p>`;
            return;
        }

        applyBtn.disabled = true;
        applyText.style.display = 'none';
        applySpinner.style.display = 'inline-block';
        applyResultsEl.innerHTML = '';

        fetch('outil-anilist-recheck.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'tool_action=anilist_recheck_apply&selections=' + encodeURIComponent(JSON.stringify(selections))
        })
        .then(r => r.json())
        .then(d => {
            applyBtn.disabled = false;
            applyText.style.display = '';
            applySpinner.style.display = 'none';

            if (!d.success) {
                applyResultsEl.innerHTML = `<p class="incomplete-empty-msg">${esc(d.message || "L'application des modifications a échoué.")}</p>`;
                return;
            }

            const applied = d.applied || [];
            const errors  = d.errors  || [];
            let html = '<div class="analysis-summary"><h3 class="summary-title">Modifications appliquées</h3>';

            if (applied.length) {
                html += `<details class="summary-group" open><summary><span class="summary-badge">${applied.length}</span> Séries mises à jour</summary>
                    <ul class="summary-list">${applied.map(m => `<li>${esc(m)}</li>`).join('')}</ul></details>`;
            }
            if (errors.length) {
                html += `<details class="summary-group" open><summary><span class="summary-badge summary-badge--warn">⚠ ${errors.length}</span> En erreur</summary>
                    <ul class="summary-list">${errors.map(e => `<li><strong>${esc(e.title)}</strong> — <span class="summary-reason">${esc(e.message)}</span></li>`).join('')}</ul></details>`;
            }
            if (!applied.length && !errors.length) {
                html += '<p class="incomplete-empty-msg">Aucune modification appliquée.</p>';
            }
            html += '</div>';
            html += '<p class="hint">Relancez une vérification (bouton ci-dessus) pour obtenir un rapport à jour.</p>';
            applyResultsEl.innerHTML = html;
            // Pas de relance automatique ici : elle effacerait ce récapitulatif
            // avant même que l'administrateur ait pu le lire. C'est à lui de
            // relancer une vérification quand il le souhaite.
        })
        .catch(() => {
            applyBtn.disabled = false;
            applyText.style.display = '';
            applySpinner.style.display = 'none';
            applyResultsEl.innerHTML = `<p class="incomplete-empty-msg">Une erreur est survenue lors de l'application des modifications.</p>`;
        });
    });
})();
