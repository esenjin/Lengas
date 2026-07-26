// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/babengas.js — Vérification via Babengas (Babelio)
//
// Contrairement à MangaUpdates (réponse immédiate), Babengas travaille en
// arrière-plan : il interroge Babelio toutes les cinq minutes pour rester
// courtois. Une campagne de 50 séries prend donc plusieurs heures.
//
// L'interface se contente donc de lancer la campagne puis d'en sonder
// l'avancement. Le suivi survit à un rechargement de page : l'identifiant de
// campagne est stocké côté serveur, dans les options.
// ──────────────────────────────────────────────────────────────────────────

(function () {
    'use strict';

    const panel = document.querySelector('[data-subtab-panel="babengas"]');
    if (!panel) return; // Intégration non configurée : rien à faire

    const resultsDiv  = document.getElementById('babengas-results');
    const progressDiv = document.getElementById('babengas-progress');
    const launchBtn   = document.getElementById('babengas-launch');
    const launchAllBtn = document.getElementById('babengas-launch-all');
    const launchForceBtn = document.getElementById('babengas-launch-force');
    const cancelBtn   = document.getElementById('babengas-cancel');

    let pollTimer = null;

    // Sondage toutes les 60 s : la campagne avance d'une série toutes les
    // cinq minutes, inutile d'interroger plus souvent.
    const POLL_INTERVAL = 60000;

    // ── Utilitaires ─────────────────────────────────────────────────────────

    function esc(str) {
        return String(str ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function post(params) {
        return fetch('page-outils.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params).toString()
        }).then(r => r.json());
    }

    function setBusy(busy) {
        if (launchBtn)      launchBtn.disabled      = busy;
        if (launchAllBtn)   launchAllBtn.disabled   = busy;
        if (launchForceBtn) launchForceBtn.disabled = busy;
        if (cancelBtn)      cancelBtn.style.display = busy ? '' : 'none';
    }

    // ── Progression ─────────────────────────────────────────────────────────

    function renderProgress(state) {
        if (!progressDiv) return;

        const pct     = Math.max(0, Math.min(100, state.progression || 0));
        const traites = state.traites ?? 0;
        const total   = state.total   ?? 0;

        progressDiv.innerHTML = `
            <div class="babengas-progress">
                <p class="analysis-progress">
                    <span class="progress-spinner"></span>
                    Traitement en cours : <strong>~${pct} %</strong>
                    <span class="progress-count">(${traites} / ${total})</span>
                </p>
                <div class="babengas-progress-bar">
                    <div class="babengas-progress-fill" style="width:${pct}%"></div>
                </div>
                <p class="hint">Babengas interroge Babelio toutes les cinq minutes pour rester courtois.
                Vous pouvez fermer cette page : la campagne continue et le suivi reprendra à votre retour.</p>
            </div>`;
    }

    function clearProgress() {
        if (progressDiv) progressDiv.innerHTML = '';
    }

    // ── Résultats ───────────────────────────────────────────────────────────

    function renderResults(state) {
        if (!resultsDiv) return;

        const incomplete = state.incomplete_series   || [];
        const failed     = state.failed_series       || [];
        const noRef      = state.no_reference_series || [];

        let html = '';

        // Récapitulatif : séries en échec et sans URL Babelio.
        // Un échec ne met rien à jour en base : l'ancienne valeur est conservée
        // et la série sera retentée à la campagne suivante.
        if (failed.length > 0 || noRef.length > 0) {
            html += '<div class="analysis-summary">';
            html += '<h3 class="summary-title">Récapitulatif de la campagne</h3>';

            if (failed.length > 0) {
                html += `
                    <details class="summary-group" open>
                        <summary>
                            <span class="summary-badge summary-badge--warn">⚠ ${failed.length}</span>
                            Non analysées — problème rencontré sur Babelio
                        </summary>
                        <ul class="summary-list">
                            ${failed.map(s => {
                                const badge = s.babelio_url
                                    ? ` <a class="babelio-badge" href="${esc(s.babelio_url)}" target="_blank" rel="noopener" title="Voir la fiche sur Babelio"><img src="../assets/img/babelogo.png" alt="Babelio" class="babelio-logo"></a>`
                                    : '';
                                return `<li><strong>${esc(s.name)}</strong>${s.read_elsewhere ? ' <span class="read-elsewhere-badge">Lue ailleurs</span>' : ''}${s.author ? ' — ' + esc(s.author) : ''} <span class="summary-reason">${esc(s.reason)}</span>${badge}</li>`;
                            }).join('')}
                        </ul>
                    </details>`;
            }

            if (noRef.length > 0) {
                html += `
                    <details class="summary-group">
                        <summary>
                            <span class="summary-badge summary-badge--muted">— ${noRef.length}</span>
                            Non analysées — aucune URL Babelio renseignée
                        </summary>
                        <ul class="summary-list">
                            ${noRef.map(s =>
                                `<li><strong>${esc(s.name)}</strong>${s.read_elsewhere ? ' <span class="read-elsewhere-badge">Lue ailleurs</span>' : ''}${s.author ? ' — ' + esc(s.author) : ''}${s.invalid_url ? ' <span class="summary-reason">URL Babelio invalide (attendu : /serie/… ou /livres/…)</span>' : ''}${s.id ? ` <button class="add-babelio-url-btn summary-edit-btn" data-series-id="${esc(s.id)}" data-series-name="${esc(s.name)}">Ajouter</button>` : ''}</li>`
                            ).join('')}
                        </ul>
                    </details>`;
            }

            html += '</div>';
        }

        // Séries incomplètes
        if (incomplete.length === 0) {
            html += '<p class="incomplete-empty-msg">Aucune série incomplète détectée par Babelio.</p>';
        } else {
            incomplete.forEach(series => {
                const ref     = series.ref_volumes ?? '?';
                const nbRef   = series.ref_reference;
                const owned   = (series.volumes || []).length;
                const missing = series.missing_volumes || [];

                const readElsewhere = series.read_elsewhere
                    ? ' <span class="read-elsewhere-badge" title="Série marquée comme lue ailleurs">Lue ailleurs</span>'
                    : '';

                // Écart entre tomes référencés et tomes parus : Babelio liste les
                // tomes avant leur sortie, Babengas les a décomptés.
                const upcoming = (typeof nbRef === 'number' && nbRef > ref)
                    ? ` <small style="opacity:.6">(${nbRef - ref} tome${nbRef - ref > 1 ? 's' : ''} à paraître)</small>`
                    : '';

                // Étiquette de source : Babelio (fiche série) ou one-shot (fiche
                // de tome, décomptée localement à 1 exemplaire).
                const srcLabel = series.ref_volumes_source === 'babelio-oneshot'
                    ? '(one-shot)'
                    : '(Babelio)';

                html += `
                    <div class="incomplete-series-item">
                        <div class="incomplete-series-header">
                            <h3>${esc(series.name)}${readElsewhere}</h3>
                        </div>
                        <p><strong>Auteur :</strong> ${esc(series.author)}</p>
                        <p><strong>Éditeur :</strong> ${esc(series.publisher)}</p>
                        <p><strong>${series.read_elsewhere ? 'Tomes lus' : 'Tomes possédés'} :</strong> ${owned} / ${ref} <small style="opacity:.6">${srcLabel}</small>${upcoming}</p>`;

                if (missing.length > 0) {
                    html += `<p><strong>Tomes manquants :</strong> ${missing.join(', ')}</p>`;
                    html += '<div class="missing-volumes-actions">';
                    missing.forEach(vol => {
                        html += `<button class="add-missing-volume" data-series-id="${esc(series.id)}" data-volume-number="${vol}">+ Tome ${vol}</button>`;
                    });
                    html += `<button class="add-all-missing-volumes" data-series-id="${esc(series.id)}" data-missing-volumes="${missing.join(',')}">Tout ajouter</button>`;
                    html += '</div>';
                } else if (series.has_more_volumes) {
                    html += '<p><strong>Tomes manquants :</strong> Aucun</p>';
                    html += '<p class="issues-list"><strong>Attention :</strong> Vous possédez plus de tomes que le décompte Babelio.</p>';
                }

                html += '</div>';
            });
        }

        resultsDiv.innerHTML = html;
        bindVolumeButtons();
    }

    // Réutilise les endpoints existants de l'outil « Séries incomplètes »
    function bindVolumeButtons() {
        resultsDiv.querySelectorAll('.add-missing-volume').forEach(btn => {
            btn.addEventListener('click', function () {
                post({
                    action: 'add_missing_volume',
                    series_id: this.dataset.seriesId,
                    volume_number: this.dataset.volumeNumber
                }).then(d => {
                    if (d.success) {
                        showSuccessModal('Tome ajouté avec succès !');
                        this.disabled = true;
                    } else {
                        showErrorModal(d.message || "Une erreur est survenue lors de l'ajout du tome.");
                    }
                }).catch(() => showErrorModal("Une erreur est survenue lors de l'ajout du tome."));
            });
        });

        resultsDiv.querySelectorAll('.add-all-missing-volumes').forEach(btn => {
            btn.addEventListener('click', function () {
                post({
                    action: 'add_all_missing_volumes',
                    series_id: this.dataset.seriesId,
                    missing_volumes: this.dataset.missingVolumes
                }).then(d => {
                    if (d.success) {
                        showSuccessModal('Tomes ajoutés avec succès !');
                        this.disabled = true;
                    } else {
                        showErrorModal(d.message || "Une erreur est survenue lors de l'ajout des tomes.");
                    }
                }).catch(() => showErrorModal("Une erreur est survenue lors de l'ajout des tomes."));
            });
        });
    }

    // ── Cycle de vie de la campagne ─────────────────────────────────────────

    function poll() {
        post({ tool_action: 'babengas_status' })
            .then(d => {
                if (!d.success) {
                    stopPolling();
                    setBusy(false);
                    clearProgress();
                    if (resultsDiv) resultsDiv.innerHTML = `<p>${esc(d.message || 'Suivi impossible.')}</p>`;
                    return;
                }

                if (d.none) {
                    stopPolling();
                    setBusy(false);
                    clearProgress();
                    return;
                }

                if (d.termine) {
                    stopPolling();
                    setBusy(false);
                    clearProgress();
                    renderResults(d);
                    if (d.statut === 'annulee') {
                        showSuccessModal('Campagne annulée. Les séries déjà traitées ont conservé leur résultat.');
                    } else {
                        showSuccessModal('Campagne terminée : les résultats sont disponibles.');
                    }
                    return;
                }

                // En cours : progression + résultats partiels déjà obtenus
                setBusy(true);
                renderProgress(d);
                renderResults(d);
            })
            .catch(() => { /* réseau momentané : on retentera au prochain tour */ });
    }

    function startPolling() {
        if (pollTimer !== null) return;
        pollTimer = setInterval(poll, POLL_INTERVAL);
    }

    function stopPolling() {
        if (pollTimer === null) return;
        clearInterval(pollTimer);
        pollTimer = null;
    }

    function launch(all, force) {
        setBusy(true);
        if (resultsDiv)  resultsDiv.innerHTML = '';
        if (progressDiv) progressDiv.innerHTML = '<p class="analysis-progress"><span class="progress-spinner"></span>Création de la campagne…</p>';

        post({ tool_action: 'babengas_launch', all: (all || force) ? '1' : '0', force: force ? '1' : '0' })
            .then(d => {
                if (!d.success) {
                    clearProgress();
                    setBusy(false);
                    if (d.in_progress) {
                        // Une campagne est déjà enregistrée. Soit elle tourne
                        // vraiment, soit son état n'a pas pu être vérifié : dans
                        // les deux cas on reprend le suivi plutôt que d'en
                        // lancer une seconde. Le message du serveur précise
                        // lequel des deux cas s'applique.
                        showErrorModal(d.message || 'Une campagne est déjà en cours.');
                        setBusy(true);
                        startPolling();
                        poll();
                        return;
                    }
                    showErrorModal(d.message || 'Impossible de lancer la campagne.');
                    return;
                }

                // Cas « one-shots seulement » : le serveur a tout résolu
                // localement, il n'y a pas de campagne à suivre.
                if (d.local_only) {
                    clearProgress();
                    setBusy(false);
                    renderResults(d);
                    showSuccessModal(d.message);
                    return;
                }

                showSuccessModal(d.message);
                startPolling();
                poll();
            })
            .catch(() => {
                clearProgress();
                setBusy(false);
                showErrorModal('Une erreur est survenue lors du lancement de la campagne.');
            });
    }

    // ── Écouteurs ───────────────────────────────────────────────────────────

    launchBtn?.addEventListener('click', () => launch(false, false));
    launchAllBtn?.addEventListener('click', () => launch(true, false));
    launchForceBtn?.addEventListener('click', () => {
        showCustomConfirm(
            'Forcer toutes les séries',
            'Vérifier l\'intégralité des séries ayant une URL de fiche série Babelio, sans aucune exception (y compris les séries terminées ou possédant un tome tagué « dernier ») ? La campagne peut être longue.'
        ).then(ok => { if (ok) launch(false, true); });
    });

    cancelBtn?.addEventListener('click', function () {
        showCustomConfirm('Confirmation', 'Annuler la campagne en cours ? Les séries déjà traitées garderont leur résultat.')
            .then(ok => {
                if (!ok) return;
                post({ tool_action: 'babengas_cancel' }).then(d => {
                    stopPolling();
                    setBusy(false);
                    clearProgress();
                    if (d.success) showSuccessModal(d.message);
                    else showErrorModal(d.message || "L'annulation a échoué.");
                });
            });
    });

    // Au chargement : reprendre le suivi si une campagne tourne toujours.
    poll();
    post({ tool_action: 'babengas_status' }).then(d => {
        if (d.success && !d.none && !d.termine) startPolling();
    }).catch(() => {});

    // ── Modale « Ajouter une URL Babelio » ──────────────────────────────────

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.add-babelio-url-btn');
        if (!btn) return;

        const idField   = document.getElementById('add-babelio-url-series-id');
        const nameField = document.getElementById('add-babelio-url-series-name');
        const input     = document.getElementById('add-babelio-url-input');
        const feedback  = document.getElementById('add-babelio-url-feedback');

        if (idField)   idField.value = btn.dataset.seriesId || '';
        if (nameField) nameField.textContent = btn.dataset.seriesName || '';
        if (input)     input.value = '';
        if (feedback)  { feedback.textContent = ''; feedback.className = 'add-mu-url-feedback'; }

        document.getElementById('add-babelio-url-modal')?.classList.add('modal-active');
    });

    document.getElementById('save-add-babelio-url-btn')?.addEventListener('click', function () {
        const id       = document.getElementById('add-babelio-url-series-id')?.value || '';
        const url      = (document.getElementById('add-babelio-url-input')?.value || '').trim();
        const feedback = document.getElementById('add-babelio-url-feedback');
        if (!id) return;

        if (!url) {
            if (feedback) { feedback.textContent = 'Veuillez saisir une URL.'; feedback.className = 'add-mu-url-feedback is-error'; }
            return;
        }

        const btn = this;
        btn.disabled = true;

        const params = new URLSearchParams();
        params.set('tool_action', 'babelio_associate_save');
        params.append('associations[' + id + ']', url);

        fetch('page-outils.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        })
        .then(r => r.json())
        .then(d => {
            if (d.success && d.saved > 0) {
                if (feedback) { feedback.textContent = 'URL enregistrée ✅'; feedback.className = 'add-mu-url-feedback is-success'; }
                setTimeout(() => document.getElementById('add-babelio-url-modal')?.classList.remove('modal-active'), 900);
            } else if (feedback) {
                feedback.textContent = 'URL invalide. Attendu : une fiche série (/serie/…) ou, pour un one-shot, une fiche tome (/livres/…).';
                feedback.className = 'add-mu-url-feedback is-error';
            }
        })
        .catch(() => {
            if (feedback) { feedback.textContent = 'Une erreur est survenue.'; feedback.className = 'add-mu-url-feedback is-error'; }
        })
        .finally(() => { btn.disabled = false; });
    });
})();
