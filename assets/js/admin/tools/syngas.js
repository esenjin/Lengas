// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/syngas.js — Outil « Synchronisation Syngas »
//
// Section 6 du cahier des charges : envoi (récap → confirmation → flux SSE)
// et réception (flux SSE → diff → validation sélective).
// ──────────────────────────────────────────────────────────────────────────

function syEscHtml(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

const SYNGAS_DIFF_LABELS = {
    name: 'Nom',
    author: 'Auteur',
    publisher: 'Éditeur',
    other_contributors: 'Autres contributeurs',
    genres: 'Genres',
    categories: 'Catégories',
    status: 'Statut de publication',
    mangaupdates_url: 'URL MangaUpdates',
    babelio_url: 'URL Babelio',
    mature: 'Contenu mature',
    thumbnail: 'Vignette',
    volumes_count: 'Nombre de tomes VF (Syngas)',
};

// ── Résolution automatique au chargement de la page ────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    fetch('outil-syngas.php?action=syngas_resolve_pending')
        .then(r => r.json())
        .then(data => {
            if (data.banned) {
                syShowBannedBanner(data.message, data.reason);
                return;
            }
            if (data.success && data.resolved > 0) {
                showSuccessModal(`${data.resolved} série(s) automatiquement liée(s) suite à une modération Syngas.`);
            }
        })
        .catch(() => { /* silencieux : purement une amélioration de confort */ });
});

function syShowBannedBanner(message, reason) {
    const banner = document.getElementById('syngas-banned-banner');
    if (!banner) return;
    banner.hidden = false;
    const reasonEl = document.getElementById('syngas-banned-reason');
    if (reasonEl) reasonEl.textContent = reason || '';
}

document.addEventListener('click', (e) => {
    const btn = e.target.closest('#syngas-banned-recheck-btn');
    if (!btn) return;
    const originalLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Vérification…';

    fetch('outil-syngas.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'tool_action=syngas_recheck_ban'
    })
    .then(r => r.json())
    .then(data => {
        if (!data.banned) {
            const banner = document.getElementById('syngas-banned-banner');
            if (banner) banner.hidden = true;
            showSuccessModal('La connexion à Syngas est de nouveau active.');
        } else {
            const reasonEl = document.getElementById('syngas-banned-reason');
            if (reasonEl) reasonEl.textContent = data.reason || '';
            showCustomAlert('Syngas', 'La connexion est toujours suspendue.' + (data.reason ? ' ' + data.reason : ''));
        }
    })
    .catch(() => {
        showCustomAlert('Erreur', 'La vérification a échoué : le serveur n\'a pas répondu.');
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = originalLabel;
    });
});

// ──────────────────────────────────────────────────────────────────────────
// Envoi
// ──────────────────────────────────────────────────────────────────────────

document.addEventListener('click', (e) => {
    if (e.target.closest('#syngas-send-btn')) {
        syPrepareSend();
    } else if (e.target.closest('#syngas-send-confirm-btn')) {
        syConfirmSend();
    } else if (e.target.closest('#syngas-receive-btn')) {
        syLoadReceive();
    } else if (e.target.closest('#syngas-receive-save-btn')) {
        syReceiveSaveAll();
    }
});

// Bouton « Tout cocher / tout décocher » du récapitulatif d'envoi
document.addEventListener('click', (e) => {
    const btn = e.target.closest('#syngas-send-toggle-all');
    if (!btn) return;
    const checkboxes = document.querySelectorAll('#syngas-send-form .syngas-send-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    checkboxes.forEach(cb => { cb.checked = !allChecked; });
    btn.textContent = allChecked ? 'Tout cocher' : 'Tout décocher';
});

// Étape 1 : récapitulatif des séries qui seront envoyées, avant tout appel réseau à Syngas.
function syPrepareSend() {
    const btn      = document.getElementById('syngas-send-btn');
    const textEl   = document.getElementById('syngas-send-text');
    const spinner  = document.getElementById('syngas-send-spinner');
    const progress = document.getElementById('syngas-send-progress');
    const results  = document.getElementById('syngas-send-results');
    if (!results) return;

    if (btn) btn.disabled = true;
    if (textEl) textEl.textContent = 'Préparation...';
    if (spinner) spinner.style.display = 'inline-block';
    results.innerHTML = '';
    if (progress) progress.innerHTML = '';

    fetch('outil-syngas.php?action=syngas_send_preview')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                results.innerHTML = `<p class="error-text">${syEscHtml(data.message || 'Erreur.')}</p>`;
                return;
            }
            syRenderSendPreview(data.targets || [], data.excluded_count || 0);
        })
        .catch(() => {
            results.innerHTML = '<p class="error-text">Impossible de préparer l\'envoi : le serveur n\'a pas répondu.</p>';
        })
        .finally(() => {
            if (btn) btn.disabled = false;
            if (textEl) textEl.textContent = 'Préparer l\'envoi';
            if (spinner) spinner.style.display = 'none';
        });
}

function syRenderSendPreview(targets, excludedCount) {
    const results = document.getElementById('syngas-send-results');
    if (!results) return;

    if (targets.length === 0) {
        let html = '<p class="mu-associate-empty">Aucune série à envoyer — toutes vos séries éligibles sont déjà liées à Syngas. ✅</p>';
        if (excludedCount > 0) {
            html += `<p class="syngas-sync-excluded">${excludedCount} série(s) ne seront pas envoyées : ajoutez le tag "manga" ou "light-novel" dans leurs catégories pour les inclure.</p>`;
        }
        results.innerHTML = html;
        return;
    }

    let html = `
        <form id="syngas-send-form" class="syngas-sync-form">
            <div class="tools-actions">
                <button type="button" id="syngas-send-toggle-all" class="button button-opt">Tout décocher</button>
            </div>
    `;
    targets.forEach(t => {
        html += `
            <label class="syngas-sync-checkbox-row">
                <input type="checkbox" class="syngas-send-checkbox" value="${syEscHtml(t.id)}" checked>
                <span>${syEscHtml(t.name)}${t.author ? ` <small>${syEscHtml(t.author)}</small>` : ''}${t.publisher ? ` <small>· ${syEscHtml(t.publisher)}</small>` : ''}</span>
            </label>
        `;
    });
    html += `</form>`;
    if (excludedCount > 0) {
        html += `<p class="syngas-sync-excluded">${excludedCount} série(s) ne seront pas envoyées : ajoutez le tag "manga" ou "light-novel" dans leurs catégories pour les inclure.</p>`;
    }
    html += `
        <div class="tools-actions">
            <button type="button" id="syngas-send-confirm-btn" class="button button-ats">Confirmer l'envoi</button>
        </div>
    `;
    results.innerHTML = html;
}

// Étape 2 : envoi effectif après confirmation, via flux SSE.
let syngasSendSource = null;
function syConfirmSend() {
    const checked = Array.from(document.querySelectorAll('#syngas-send-form .syngas-send-checkbox:checked'))
        .map(cb => cb.value);
    if (checked.length === 0) {
        showCustomAlert('Information', 'Aucune série cochée.');
        return;
    }

    const progress = document.getElementById('syngas-send-progress');
    const results  = document.getElementById('syngas-send-results');
    if (!progress || !results) return;

    if (syngasSendSource) { syngasSendSource.close(); syngasSendSource = null; }
    results.innerHTML = '';

    let current = 0, total = 0, currentName = '';
    const renderProgress = () => {
        const countText = total > 0 ? `${current} / ${total}` : `${current}`;
        progress.innerHTML =
            `<p class="analysis-progress"><span class="progress-spinner"></span>` +
            `Envoi : <strong>${syEscHtml(currentName) || '…'}</strong> ` +
            `<span class="progress-count">(${countText})</span></p>`;
    };
    renderProgress();

    const params = checked.map(id => `ids[]=${encodeURIComponent(id)}`).join('&');
    const source = new EventSource(`outil-syngas.php?action=syngas_send_stream&${params}`);
    syngasSendSource = source;

    source.addEventListener('progress', (ev) => {
        const d = JSON.parse(ev.data);
        current = d.current; total = d.total; currentName = d.name;
        renderProgress();
    });

    source.addEventListener('banned', (ev) => {
        const d = JSON.parse(ev.data);
        syShowBannedBanner(d.message, d.reason);
    });

    source.addEventListener('done', (ev) => {
        const d = JSON.parse(ev.data);
        source.close(); syngasSendSource = null;
        progress.innerHTML = '';

        let html = `<p class="hint ok">✔️ ${d.sent} série(s) mise(s) en file d'attente sur Syngas. Elles seront liées automatiquement dès validation par la modération (ou détectées à la prochaine visite de cette page).</p>`;
        if (d.failed && d.failed.length > 0) {
            html += `<details class="mu-associate-noresults mu-associate-failed">
                <summary>⚠️ ${d.failed.length} série(s) en échec</summary>
                <ul>${d.failed.map(f => `<li>${syEscHtml(f.name)} — ${syEscHtml(f.error)}</li>`).join('')}</ul>
            </details>`;
        }
        results.innerHTML = html;
    });

    source.onerror = () => {
        source.close(); syngasSendSource = null;
        progress.innerHTML = '';
        results.innerHTML = '<p class="error-text">L\'envoi a été interrompu. Rechargez la page pour voir où il s\'est arrêté.</p>';
    };
}

// ──────────────────────────────────────────────────────────────────────────
// Réception
// ──────────────────────────────────────────────────────────────────────────

let syngasReceiveSource = null;
const syngasReceiveDiffs = {}; // series_id -> { fields, thumbnail_url }

function syLoadReceive() {
    const btn      = document.getElementById('syngas-receive-btn');
    const textEl   = document.getElementById('syngas-receive-text');
    const spinner  = document.getElementById('syngas-receive-spinner');
    const progress = document.getElementById('syngas-receive-progress');
    const results  = document.getElementById('syngas-receive-results');
    if (!results || !progress) return;

    if (syngasReceiveSource) { syngasReceiveSource.close(); syngasReceiveSource = null; }
    Object.keys(syngasReceiveDiffs).forEach(k => delete syngasReceiveDiffs[k]);

    if (btn) btn.disabled = true;
    if (textEl) textEl.textContent = 'Vérification en cours...';
    if (spinner) spinner.style.display = 'inline-block';

    results.innerHTML = '<div class="syngas-sync-form" id="syngas-receive-form"></div>';

    let current = 0, total = 0, currentName = '';
    const renderProgress = () => {
        const countText = total > 0 ? `${current} / ${total}` : `${current}`;
        progress.innerHTML =
            `<p class="analysis-progress"><span class="progress-spinner"></span>` +
            `Vérification : <strong>${syEscHtml(currentName) || '…'}</strong> ` +
            `<span class="progress-count">(${countText})</span></p>`;
    };
    renderProgress();

    let anyMatch = false;
    const source = new EventSource('outil-syngas.php?action=syngas_receive_stream');
    syngasReceiveSource = source;

    source.addEventListener('progress', (ev) => {
        const d = JSON.parse(ev.data);
        current = d.current; total = d.total; currentName = d.name;
        renderProgress();
    });

    source.addEventListener('banned', (ev) => {
        const d = JSON.parse(ev.data);
        syShowBannedBanner(d.message, d.reason);
    });

    source.addEventListener('match', (ev) => {
        const d = JSON.parse(ev.data);
        if (d.series) { syAppendReceiveSeries(d.series); anyMatch = true; }
    });

    source.addEventListener('done', (ev) => {
        source.close(); syngasReceiveSource = null;
        progress.innerHTML = '';
        syFinalizeReceive(anyMatch);
        if (btn) btn.disabled = false;
        if (textEl) textEl.textContent = 'Relancer la vérification';
        if (spinner) spinner.style.display = 'none';
    });

    source.onerror = () => {
        source.close(); syngasReceiveSource = null;
        progress.innerHTML = '';
        if (!anyMatch) results.innerHTML = '<p class="error-text">La vérification a été interrompue. Veuillez réessayer.</p>';
        if (btn) btn.disabled = false;
        if (textEl) textEl.textContent = 'Vérifier les mises à jour';
        if (spinner) spinner.style.display = 'none';
    };
}

function syAppendReceiveSeries(series) {
    const form = document.getElementById('syngas-receive-form');
    if (!form) return;

    // Mémorisé pour l'enregistrement final (évite un nouvel appel réseau à
    // Syngas au moment de valider : on réutilise ce qui a déjà été reçu).
    syngasReceiveDiffs[series.series_id] = {
        fields: series.fields || {},
        thumbnail_url: series.thumbnail_url || '',
    };

    const wrap = document.createElement('div');
    wrap.className = 'syngas-sync-series';
    wrap.dataset.seriesId = series.series_id;

    let html = `
        <label class="syngas-sync-checkbox-row">
            <input type="checkbox" class="syngas-receive-checkbox" checked>
            <span class="syngas-sync-series-name">${syEscHtml(series.name)}</span>
        </label>
        <div class="syngas-sync-diff">
    `;
    Object.keys(series.diff).forEach(field => {
        const label = SYNGAS_DIFF_LABELS[field] || field;
        const entry = series.diff[field];
        const isThumb = field === 'thumbnail';
        html += `
            <div class="syngas-sync-diff-row">
                <span class="syngas-sync-diff-field">${syEscHtml(label)}</span>
                <span class="syngas-sync-diff-values">
                    <span class="syngas-sync-diff-old">${isThumb ? (entry.old ? 'Vignette actuelle' : '(aucune)') : syEscHtml(entry.old || '(vide)')}</span>
                    <span class="syngas-sync-diff-new">${isThumb ? 'Nouvelle vignette Syngas' : syEscHtml(entry.new)}</span>
                </span>
            </div>
        `;
    });
    html += `</div>`;
    wrap.innerHTML = html;
    form.appendChild(wrap);
}

function syFinalizeReceive(anyMatch) {
    const results = document.getElementById('syngas-receive-results');
    if (!results) return;

    if (!anyMatch) {
        results.innerHTML = '<p class="mu-associate-empty">Aucune mise à jour disponible — vos séries liées à Syngas sont déjà à jour. ✅</p>';
        return;
    }

    const saveBtn = document.createElement('button');
    saveBtn.id = 'syngas-receive-save-btn';
    saveBtn.className = 'button button-ats';
    saveBtn.textContent = 'Enregistrer les modifications cochées';
    results.appendChild(saveBtn);
}

function syReceiveSaveAll() {
    const blocks = document.querySelectorAll('#syngas-receive-form .syngas-sync-series');
    const selections = {};
    let count = 0;

    blocks.forEach(block => {
        const id = block.dataset.seriesId;
        const checkbox = block.querySelector('.syngas-receive-checkbox');
        if (checkbox && checkbox.checked) {
            selections[id] = true;
            count++;
        }
    });

    if (count === 0) {
        showCustomAlert('Information', 'Aucune série cochée.');
        return;
    }

    const saveBtn = document.getElementById('syngas-receive-save-btn');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Enregistrement...'; }

    const params = new URLSearchParams();
    params.set('tool_action', 'syngas_receive_save');
    params.set('selections', JSON.stringify(selections));
    params.set('diffs', JSON.stringify(syngasReceiveDiffs));

    fetch('outil-syngas.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showSuccessModal(`${data.saved} série(s) mise(s) à jour.`);
            setTimeout(() => window.location.reload(), 900);
        } else {
            showErrorModal(data.message || "Erreur lors de l'enregistrement.");
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Enregistrer les modifications cochées'; }
        }
    })
    .catch(() => {
        showErrorModal('Une erreur est survenue.');
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Enregistrer les modifications cochées'; }
    });
}
