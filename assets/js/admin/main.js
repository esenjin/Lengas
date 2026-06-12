// Fonction pour afficher une alerte personnalisée
function showCustomAlert(title, message) {
    const modal = document.getElementById('custom-alert-modal');
    const titleElement = document.getElementById('custom-alert-title');
    const messageElement = document.getElementById('custom-alert-message');
    const okButton = document.getElementById('custom-alert-ok');

    titleElement.textContent = title;
    messageElement.textContent = message;
    modal.classList.add('modal-active');

    return new Promise((resolve) => {
        okButton.onclick = () => {
            modal.classList.remove('modal-active');
            resolve();
        };
    });
}

// Fonction pour afficher une confirmation personnalisée
function showCustomConfirm(title, message) {
    const modal = document.getElementById('custom-confirm-modal');
    const titleElement = document.getElementById('custom-confirm-title');
    const messageElement = document.getElementById('custom-confirm-message');
    const okButton = document.getElementById('custom-confirm-ok');
    const cancelButton = document.getElementById('custom-confirm-cancel');

    titleElement.textContent = title;
    messageElement.textContent = message;
    modal.classList.add('modal-active');

    return new Promise((resolve) => {
        okButton.onclick = () => {
            modal.classList.remove('modal-active');
            resolve(true);
        };
        cancelButton.onclick = () => {
            modal.classList.remove('modal-active');
            resolve(false);
        };
    });
}

// Remplacer les alert/confirm natifs
window.alert = function(message) {
    showCustomAlert('Avertissement', message);
};

window.confirm = function(message) {
    return showCustomConfirm('Confirmation', message);
};

// Afficher un message d'erreur dans une modale
function showErrorModal(message) {
    showCustomAlert('Erreur', message);
}

// Afficher un message de succès dans une modale
function showSuccessModal(message) {
    showCustomAlert('Succès', message);
}

// Bouton "Retour en haut"
window.addEventListener('scroll', function() {
    const backToTop = document.getElementById('back-to-top');
    if (window.pageYOffset > 300) {
        backToTop.style.display = 'block';
    } else {
        backToTop.style.display = 'none';
    }
});
document.getElementById('back-to-top').addEventListener('click', function() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

// Masquer le message d'erreur après 3 secondes
setTimeout(function() {
    var errorMessage = document.getElementById('error-message');
    if (errorMessage) {
        errorMessage.style.display = 'none';
    }
}, 3000);

// Recherche des séries incomplètes
function launchIncompleteSearch(forceUncached) {
    window.incompleteSearchDone = true; // une recherche a été lancée → recharger à la fermeture de la modale
    const resultsDiv = document.getElementById('incomplete-series-results');
    let current = 0, total = 0, currentName = '';

    const renderProgress = () => {
        const countText = total > 0
            ? `${current} / ${total}`
            : `${current}`;
        resultsDiv.innerHTML =
            `<p class="analysis-progress">` +
            `<span class="progress-spinner"></span>` +
            `Analyse : <strong>${currentName || '…'}</strong> ` +
            `<span class="progress-count">(${countText})</span>` +
            `</p>`;
    };
    renderProgress();

    const url = forceUncached
        ? 'admin.php?action=incomplete_series_stream&force_uncached=1'
        : 'admin.php?action=incomplete_series_stream';
    const es = new EventSource(url);

    es.addEventListener('progress', e => {
        const d = JSON.parse(e.data);
        current     = d.current;
        total       = d.total;
        currentName = d.name;
        renderProgress();
    });

    es.addEventListener('done', e => {
        es.close();
        const data = JSON.parse(e.data);
        if (data.success) {
            displayIncompleteSeries(
                data.incomplete_series    || [],
                data.no_reference_series  || [],
                data.failed_series        || []
            );
        } else {
            resultsDiv.innerHTML = '<p>Une erreur est survenue lors de la recherche des séries incomplètes.</p>';
        }
    });

    es.onerror = () => {
        es.close();
        resultsDiv.innerHTML = '<p>Une erreur est survenue lors de la recherche des séries incomplètes. Veuillez réessayer plus tard.</p>';
    };
}

document.getElementById('search-incomplete-series')?.addEventListener('click', function() {
    launchIncompleteSearch(false);
});

document.getElementById('force-incomplete-search')?.addEventListener('click', function() {
    launchIncompleteSearch(true);
});

// Traduit en français le statut de publication MangaUpdates (affichage utilisateur)
function translateMuStatus(status) {
    if (!status) return '';
    const map = {
        'complete': 'Terminé',
        'ongoing': 'En cours',
        'hiatus': 'En pause',
        'cancelled': 'Annulé',
        'canceled': 'Annulé',
        'discontinued': 'Abandonné'
    };
    const key = String(status).trim().toLowerCase();
    return map[key] || status;
}

// Affichage des séries incomplètes
function displayIncompleteSeries(incomplete_series, no_reference_series, failed_series) {
    no_reference_series = no_reference_series || [];
    failed_series       = failed_series       || [];

    const resultsDiv = document.getElementById('incomplete-series-results');
    resultsDiv.innerHTML = '';

    if (incomplete_series.length === 0) {
        resultsDiv.innerHTML += '<p>Aucune série incomplète trouvée.</p>';
    } else {
        incomplete_series.forEach(series => {
            const seriesDiv = document.createElement('div');
            seriesDiv.className = 'incomplete-series-item';

            const srcLabel = 'MangaUpdates';
            const refCount = series.ref_volumes ?? '?';

            let html = `
                <h3>${series.name}</h3>
                <p><strong>Auteur :</strong> ${series.author}</p>
                <p><strong>Éditeur :</strong> ${series.publisher}</p>
                <p><strong>Tomes possédés :</strong> ${series.volumes.length} / ${refCount} <small style="opacity:.6">(${srcLabel}${series.ref_status ? ' · ' + translateMuStatus(series.ref_status) : ''})</small></p>
            `;

            if (series.missing_volumes && series.missing_volumes.length > 0) {
                html += `<p><strong>Tomes manquants :</strong> ${series.missing_volumes.join(', ')}</p>`;
            } else if (series.has_more_volumes) {
                html += `<p><strong>Tomes manquants :</strong> Aucun</p>`;
                html += `<p class="issues-list"><strong>Attention :</strong> Vous possédez plus de tomes que la référence (${srcLabel}).</p>`;
            }

            html += `<div class="missing-volumes-actions">`;
            if (series.missing_volumes && series.missing_volumes.length > 0) {
                series.missing_volumes.forEach(vol => {
                    html += `<button class="add-missing-volume" data-series-id="${series.id}" data-volume-number="${vol}">+ Tome ${vol}</button>`;
                });
                html += `<button class="add-all-missing-volumes" data-series-id="${series.id}" data-missing-volumes="${series.missing_volumes.join(',')}">Tout ajouter</button>`;
            }
            html += `</div>`;

            seriesDiv.innerHTML = html;
            resultsDiv.appendChild(seriesDiv);
        });
    }

    // Récapitulatif : séries en échec + sans référence
    if (failed_series.length > 0 || no_reference_series.length > 0) {
        const summaryDiv = document.createElement('div');
        summaryDiv.className = 'analysis-summary';
        let summaryHtml = "<h3 class=\"summary-title\">Récapitulatif de l'analyse</h3>";

        if (failed_series.length > 0) {
            summaryHtml += `
                <details class="summary-group" open>
                    <summary>
                        <span class="summary-badge summary-badge--warn">⚠ ${failed_series.length}</span>
                        Non analysées — données MangaUpdates indisponibles ou nombre de tomes non renseigné
                    </summary>
                    <ul class="summary-list">
                        ${failed_series.map(s =>
                            `<li><strong>${s.name}</strong>${s.author ? ' — ' + s.author : ''} <span class="summary-reason">${s.reason ?? ''}</span>${s.id && !s.has_mu_url ? ` <button class="add-mu-url-btn summary-edit-btn" data-series-id="${s.id}" data-series-name="${(s.name || '').replace(/"/g, '&quot;')}">Ajouter</button>` : ''}</li>`
                        ).join('')}
                    </ul>
                </details>`;
        }

        if (no_reference_series.length > 0) {
            summaryHtml += `
                <details class="summary-group">
                    <summary>
                        <span class="summary-badge summary-badge--muted">— ${no_reference_series.length}</span>
                        Non analysées — aucune URL MangaUpdates renseignée
                    </summary>
                    <ul class="summary-list">
                        ${no_reference_series.map(s =>
                            `<li><strong>${s.name}</strong>${s.author ? ' — ' + s.author : ''}${s.id ? ` <button class="add-mu-url-btn summary-edit-btn" data-series-id="${s.id}" data-series-name="${(s.name || '').replace(/"/g, '&quot;')}">Ajouter</button>` : ''}</li>`
                        ).join('')}
                    </ul>
                </details>`;
        }

        summaryDiv.innerHTML = summaryHtml;
        resultsDiv.appendChild(summaryDiv);
    }

    // Boutons d'ajout de tomes
    function refreshAfterAdd() {
        fetch('admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_incomplete_series'
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) displayIncompleteSeries(d.incomplete_series || [], d.no_reference_series || [], d.failed_series || []);
        });
    }

    document.querySelectorAll('.add-missing-volume').forEach(btn => {
        btn.addEventListener('click', function() {
            fetch('admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=add_missing_volume&series_id=${this.dataset.seriesId}&volume_number=${this.dataset.volumeNumber}`
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) { alert('Tome ajouté avec succès !'); refreshAfterAdd(); }
                else alert("Une erreur est survenue lors de l'ajout du tome.");
            })
            .catch(() => alert("Une erreur est survenue lors de l'ajout du tome."));
        });
    });

    document.querySelectorAll('.add-all-missing-volumes').forEach(btn => {
        btn.addEventListener('click', function() {
            fetch('admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=add_all_missing_volumes&series_id=${this.dataset.seriesId}&missing_volumes=${this.dataset.missingVolumes}`
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) { alert('Tomes ajoutés avec succès !'); refreshAfterAdd(); }
                else alert("Une erreur est survenue lors de l'ajout des tomes.");
            })
            .catch(() => alert("Une erreur est survenue lors de l'ajout des tomes."));
        });
    });
}

// Gestion du menu mobile
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuButton = document.getElementById('mobile-menu-button');
    const adminMenu = document.getElementById('admin-menu');

    if (mobileMenuButton && adminMenu) {
        mobileMenuButton.addEventListener('click', function() {
            adminMenu.classList.toggle('active');
        });

        adminMenu.addEventListener('click', function(e) {
            if (e.target === adminMenu) {
                adminMenu.classList.remove('active');
            }
        });
    }
});

// ──────────────────────────────────────────────────────────────────────────────
// Modale « Ajouter une URL MangaUpdates » (depuis l'outil des tomes manquants)
// ──────────────────────────────────────────────────────────────────────────────

// Ouverture : bouton « Ajouter » des listes de séries sans référence / en échec
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.add-mu-url-btn');
    if (!btn) return;
    const id   = btn.dataset.seriesId || '';
    const name = btn.dataset.seriesName || '';
    const idField   = document.getElementById('add-mu-url-series-id');
    const nameField = document.getElementById('add-mu-url-series-name');
    const input     = document.getElementById('add-mu-url-input');
    const feedback  = document.getElementById('add-mu-url-feedback');
    if (idField)   idField.value = id;
    if (nameField) nameField.textContent = name;
    if (input)     input.value = '';
    if (feedback)  { feedback.textContent = ''; feedback.className = 'add-mu-url-feedback'; }
    const modal = document.getElementById('add-mu-url-modal');
    if (modal) modal.classList.add('modal-active');
});

// Enregistrement de l'URL (AJAX, sans recharger la page)
document.getElementById('save-add-mu-url-btn')?.addEventListener('click', function () {
    const id       = document.getElementById('add-mu-url-series-id')?.value || '';
    const url      = (document.getElementById('add-mu-url-input')?.value || '').trim();
    const feedback = document.getElementById('add-mu-url-feedback');
    if (!id) return;
    if (!url) {
        if (feedback) { feedback.textContent = 'Veuillez saisir une URL.'; feedback.className = 'add-mu-url-feedback is-error'; }
        return;
    }

    const btn = this;
    btn.disabled = true;

    const params = new URLSearchParams();
    params.set('tool_action', 'mu_associate_save');
    params.append('associations[' + id + ']', url);

    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(d => {
        if (d.success && d.saved > 0) {
            window.incompleteSearchDone = true; // recharger à la fermeture de la modale de vérification
            if (feedback) { feedback.textContent = 'URL enregistrée ✅'; feedback.className = 'add-mu-url-feedback is-success'; }
            setTimeout(function () {
                document.getElementById('add-mu-url-modal')?.classList.remove('modal-active');
            }, 900);
        } else if (feedback) {
            feedback.textContent = 'URL invalide ou non enregistrée. Vérifiez le lien MangaUpdates.';
            feedback.className = 'add-mu-url-feedback is-error';
        }
    })
    .catch(function () {
        if (feedback) { feedback.textContent = 'Une erreur est survenue.'; feedback.className = 'add-mu-url-feedback is-error'; }
    })
    .finally(function () { btn.disabled = false; });
});
