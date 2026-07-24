// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/coherence.js — Outil « Incohérences »
//
// Analyse des anomalies de la collection, rendu de la liste des problèmes,
// filtres par type et modale d'édition rapide d'une série.
// ──────────────────────────────────────────────────────────────────────────

// La page « Outils » lance l'analyse dès son chargement, et propose de la relancer.
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('coherences-results')) loadCoherences();
});

document.getElementById('reload-coherences-btn')?.addEventListener('click', () => loadCoherences());

function loadCoherences() {
    const container = document.getElementById('coherences-results');
    container.innerHTML = '<p class="loading-text">Analyse en cours…</p>';

    fetch('page-outils.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'tool_action=check_coherence'
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            container.innerHTML = '<p class="error-text">Erreur lors de l\'analyse.</p>';
            return;
        }
        renderCoherences(data.issues);
    })
    .catch(() => {
        container.innerHTML = '<p class="error-text">Une erreur est survenue.</p>';
    });
}

const COHERENCE_LABELS = {
    no_volumes:                 { icon: '📭', label: 'Série vide' },
    multiple_last:              { icon: '🔁', label: 'Plusieurs « derniers »' },
    wrong_last:                 { icon: '🏷️', label: 'Dernier mal placé' },
    missing_volumes:            { icon: '🕳️', label: 'Tomes manquants' },
    duplicate_volumes:          { icon: '👯', label: 'Doublons' },
    invalid_number:             { icon: '⚠️', label: 'Numéro invalide' },
    finished_no_last:           { icon: '🏁', label: 'Terminée sans dernier' },
    last_but_not_finished:      { icon: '🔖', label: 'Dernier sans fin' },
    sequence_not_starting_at_1: { icon: '1️⃣',  label: 'Ne commence pas à 1' },
    read_elsewhere_unread:      { icon: '👁️', label: 'Lue ailleurs — tomes non lus' },
    mu_still_ongoing:           { icon: '🔄', label: 'Publication en cours (MangaUpdates)' },
    mu_complete_unmarked:       { icon: '✔️', label: 'Terminée selon MangaUpdates' },
    mu_more_volumes:            { icon: '📦', label: 'Plus de tomes que MangaUpdates' },
    loan_deleted_series:        { icon: '👻', label: 'Prêt — série supprimée' },
    loan_read_elsewhere:        { icon: '📤', label: 'Prêt — lue ailleurs' },
};

function renderCoherences(issues) {
    const container = document.getElementById('coherences-results');
    container.innerHTML = '';

    const summaryDiv = document.createElement('div');
    summaryDiv.className = 'coherences-summary';

    if (issues.length === 0) {
        summaryDiv.innerHTML = '<p class="coherences-ok">✅ Aucune incohérence détectée dans votre collection.</p>';
        container.appendChild(summaryDiv);
        return;
    }

    const totalProblems = issues.reduce((acc, s) => acc + s.problems.length, 0);
    summaryDiv.innerHTML = `<p class="coherences-count">
        <strong>${issues.length}</strong> série(s) concernée(s) — 
        <strong>${totalProblems}</strong> incohérence(s) au total.
    </p>`;
    container.appendChild(summaryDiv);

    const allTypes = [...new Set(issues.flatMap(s => s.problems.map(p => p.type)))];
    if (allTypes.length > 1) {
        const filterDiv = document.createElement('div');
        filterDiv.className = 'coherences-filters';

        const allBtn = document.createElement('button');
        allBtn.className = 'button button-sm filter-btn active';
        allBtn.dataset.filter = 'all';
        allBtn.textContent = 'Tous';
        filterDiv.appendChild(allBtn);

        allTypes.forEach(type => {
            const btn = document.createElement('button');
            btn.className = 'button button-sm filter-btn';
            btn.dataset.filter = type;
            const meta = COHERENCE_LABELS[type] || { icon: '❓', label: type };
            btn.textContent = `${meta.icon} ${meta.label}`;
            filterDiv.appendChild(btn);
        });

        filterDiv.addEventListener('click', e => {
            const btn = e.target.closest('.filter-btn');
            if (!btn) return;
            filterDiv.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            applyCoherenceFilter(btn.dataset.filter);
        });

        container.appendChild(filterDiv);
    }

    const listDiv = document.createElement('div');
    listDiv.id = 'coherences-list';

    issues.forEach(item => {
        const block = document.createElement('div');
        block.className = 'coherence-series-block';
        block.dataset.types = item.problems.map(p => p.type).join(' ');
        block.dataset.seriesId = item.series_id || '';

        const header = document.createElement('div');
        header.className = 'coherence-series-name';

        const nameSpan = document.createElement('span');
        nameSpan.textContent = item.series;
        header.appendChild(nameSpan);

        if (item.mangaupdates_url) {
            const muBadge = document.createElement('a');
            muBadge.href = item.mangaupdates_url;
            muBadge.target = '_blank';
            muBadge.rel = 'noopener';
            muBadge.className = 'mu-badge';
            muBadge.title = 'Voir sur MangaUpdates';
            muBadge.innerHTML = '<img src="assets/img/mulogo.png" alt="MangaUpdates" class="mu-logo">';
            header.appendChild(muBadge);
        }

        if (item.series_id) {
            // Issues exclusivement liées aux prêts → lien vers page-prets
            const loanTypes = new Set(['loan_deleted_series', 'loan_read_elsewhere']);
            const isLoanIssue = item.problems.every(p => loanTypes.has(p.type));

            if (isLoanIssue) {
                const loansLink = document.createElement('a');
                loansLink.href = 'page-prets.php';
                loansLink.className = 'button button-sm button-otl';
                loansLink.textContent = 'Gérer les prêts';
                header.appendChild(loansLink);
            } else {
                const editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'button button-sm cedit-open-btn';
                editBtn.dataset.seriesId = item.series_id;
                editBtn.textContent = 'Modifier';
                header.appendChild(editBtn);
            }
        }

        block.appendChild(header);

        const ul = document.createElement('ul');
        ul.className = 'coherence-problems';
        item.problems.forEach(prob => {
            const meta = COHERENCE_LABELS[prob.type] || { icon: '❓', label: prob.type };
            const li = document.createElement('li');
            li.className = `coherence-item coherence-type-${prob.type}`;
            li.innerHTML = `<span class="coherence-icon">${meta.icon}</span>
                            <span class="coherence-tag">${meta.label}</span>
                            <span class="coherence-msg">${prob.message}</span>`;
            ul.appendChild(li);
        });
        block.appendChild(ul);
        listDiv.appendChild(block);
    });

    container.appendChild(listDiv);
}

// ──────────────────────────────────────────────────────────────────────────────
// Modale d'édition rapide depuis Incohérences
// ──────────────────────────────────────────────────────────────────────────────

// Délégation : clic sur un bouton « Modifier » dans la liste des incohérences
document.getElementById('coherences-results').addEventListener('click', (e) => {
    const btn = e.target.closest('.cedit-open-btn');
    if (!btn) return;
    const seriesId = btn.dataset.seriesId;
    openCoherenceEdit(seriesId);
});

function openCoherenceEdit(seriesId) {
    // Chercher la série dans window.seriesData
    const series = (window.seriesData || []).find(s => s.id === seriesId);
    if (!series) {
        showErrorModal('Données de la série introuvables. Veuillez recharger la page.');
        return;
    }

    // Remplir les infos lecture seule
    document.getElementById('cedit-series-id').value = seriesId;
    document.getElementById('cedit-name').textContent       = series.name || '';
    document.getElementById('cedit-author').textContent     = series.author || '—';
    document.getElementById('cedit-publisher').textContent  = series.publisher || '—';
    const cats = Array.isArray(series.categories)
        ? series.categories.filter(c => c && c.trim()).join(', ')
        : (series.categories || '');
    document.getElementById('cedit-categories').textContent = cats || '—';

    // Statut de publication
    const statusSel = document.getElementById('cedit-status');
    statusSel.value = series.status || 'en cours';

    // Lue ailleurs
    document.getElementById('cedit-read-elsewhere').checked = !!(series.read_elsewhere);

    // Feedback
    document.getElementById('cedit-feedback').textContent = '';

    // Construire la liste des tomes
    buildCeditVolumesList(series.volumes || []);

    modals['coherence-edit'].modal.classList.add('modal-active');
}

function buildCeditVolumesList(volumes) {
    const container = document.getElementById('cedit-volumes-list');
    container.innerHTML = '';

    if (!volumes || volumes.length === 0) {
        container.innerHTML = '<p class="cedit-no-volumes">Aucun tome dans cette série.</p>';
        return;
    }

    // Trier par numéro
    const sorted = [...volumes].sort((a, b) => a.number - b.number);

    sorted.forEach((vol, i) => {
        // Trouver l'index réel dans le tableau original (volumes n'est pas forcément trié)
        const realIndex = volumes.indexOf(vol);

        const row = document.createElement('div');
        row.className = 'cedit-volume-row';
        row.dataset.volIndex = realIndex;
        row.dataset.volNumber = vol.number;

        const numLabel = document.createElement('span');
        numLabel.className = 'cedit-vol-num';
        numLabel.textContent = `Tome ${vol.number}`;
        row.appendChild(numLabel);

        // Statut de lecture
        const statusSel = document.createElement('select');
        statusSel.className = 'cedit-select cedit-select--sm cedit-vol-status';
        ['à lire', 'en cours', 'terminé'].forEach(s => {
            const opt = document.createElement('option');
            opt.value = s;
            opt.textContent = s.charAt(0).toUpperCase() + s.slice(1);
            if (vol.status === s) opt.selected = true;
            statusSel.appendChild(opt);
        });
        row.appendChild(statusSel);

        // Tag "dernier tome"
        const lastLabel = document.createElement('label');
        lastLabel.className = 'cedit-inline-check';
        const lastChk = document.createElement('input');
        lastChk.type = 'checkbox';
        lastChk.className = 'cedit-vol-last';
        lastChk.checked = !!vol.last;
        lastLabel.appendChild(lastChk);
        lastLabel.appendChild(document.createTextNode(' Dernier ✅'));
        row.appendChild(lastLabel);

        // Bouton supprimer
        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'button button-sm cedit-vol-delete';
        delBtn.title = 'Supprimer ce tome';
        delBtn.textContent = '✕';
        delBtn.addEventListener('click', () => {
            row.classList.toggle('cedit-vol-pending-delete');
            delBtn.classList.toggle('cedit-vol-delete--active');
        });
        row.appendChild(delBtn);

        container.appendChild(row);
    });
}

// Bouton « + Ajouter un tome » — insère directement le prochain numéro (max + 1)
document.getElementById('cedit-add-volume-btn').addEventListener('click', () => {
    const container = document.getElementById('cedit-volumes-list');

    // Calculer le prochain numéro : max de tous les tomes visibles (y compris les "new") + 1
    const allNums = [...container.querySelectorAll('.cedit-volume-row')]
        .map(r => parseInt(r.dataset.volNumber, 10))
        .filter(n => !isNaN(n));
    const nextNum = allNums.length > 0 ? Math.max(...allNums) + 1 : 1;

    // Retirer le placeholder "aucun tome" s'il est affiché
    const placeholder = container.querySelector('.cedit-no-volumes');
    if (placeholder) placeholder.remove();

    // Créer la ligne directement
    const row = document.createElement('div');
    row.className = 'cedit-volume-row cedit-vol-pending-add';
    row.dataset.volIndex  = 'new';
    row.dataset.volNumber = nextNum;

    const numLabel = document.createElement('span');
    numLabel.className = 'cedit-vol-num';
    numLabel.textContent = `Tome ${nextNum} (nouveau)`;
    row.appendChild(numLabel);

    const statusSel = document.createElement('select');
    statusSel.className = 'cedit-select cedit-select--sm cedit-vol-status';
    ['à lire', 'en cours', 'terminé'].forEach(s => {
        const opt = document.createElement('option');
        opt.value = s;
        opt.textContent = s.charAt(0).toUpperCase() + s.slice(1);
        if (s === 'à lire') opt.selected = true;
        statusSel.appendChild(opt);
    });
    row.appendChild(statusSel);

    const lastLabel = document.createElement('label');
    lastLabel.className = 'cedit-inline-check';
    const lastChk = document.createElement('input');
    lastChk.type = 'checkbox';
    lastChk.className = 'cedit-vol-last';
    lastChk.checked = false;
    lastLabel.appendChild(lastChk);
    lastLabel.appendChild(document.createTextNode(' Dernier ✅'));
    row.appendChild(lastLabel);

    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'button button-sm cedit-vol-delete';
    delBtn.title = 'Annuler cet ajout';
    delBtn.textContent = '✕';
    delBtn.addEventListener('click', () => row.remove());
    row.appendChild(delBtn);

    container.appendChild(row);
    document.getElementById('cedit-feedback').textContent = '';
});

// Bouton Enregistrer de la modale d'édition rapide
document.getElementById('cedit-save-btn').addEventListener('click', () => {
    const seriesId = document.getElementById('cedit-series-id').value;
    if (!seriesId) return;

    const saveBtn    = document.getElementById('cedit-save-btn');
    const saveText   = document.getElementById('cedit-save-text');
    const saveSpinner= document.getElementById('cedit-save-spinner');
    const feedback   = document.getElementById('cedit-feedback');

    saveBtn.disabled = true;
    saveText.textContent = 'Enregistrement…';
    saveSpinner.style.display = 'inline-block';
    feedback.textContent = '';

    // Collecter les données
    const newStatus      = document.getElementById('cedit-status').value;
    const readElsewhere  = document.getElementById('cedit-read-elsewhere').checked ? '1' : '0';

    const deleteIndexes  = [];
    const volumesUpdates = [];
    const addVolumes     = [];

    document.querySelectorAll('#cedit-volumes-list .cedit-volume-row').forEach(row => {
        const idx    = row.dataset.volIndex;
        const num    = parseInt(row.dataset.volNumber, 10);
        const status = row.querySelector('.cedit-vol-status')?.value || 'à lire';
        const isLast = !!(row.querySelector('.cedit-vol-last')?.checked);

        if (idx === 'new') {
            // Tome à ajouter
            if (!row.classList.contains('cedit-vol-pending-delete')) {
                addVolumes.push({ number: num, status, last: isLast });
            }
        } else {
            if (row.classList.contains('cedit-vol-pending-delete')) {
                // Tome à supprimer
                deleteIndexes.push(parseInt(idx, 10));
            } else {
                // Tome à mettre à jour
                volumesUpdates.push({ index: parseInt(idx, 10), status, last: isLast });
            }
        }
    });

    const params = new URLSearchParams({
        tool_action:     'coherence_quick_edit',
        series_id:       seriesId,
        series_status:   newStatus,
        read_elsewhere:  readElsewhere,
        delete_volumes:  JSON.stringify(deleteIndexes),
        volumes_updates: JSON.stringify(volumesUpdates),
        add_volumes:     JSON.stringify(addVolumes),
    });

    fetch('page-outils.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    params.toString(),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Mettre à jour window.seriesData pour que la liste principale soit cohérente
            if (data.series && window.seriesData) {
                const idx = window.seriesData.findIndex(s => s.id === seriesId);
                if (idx !== -1) window.seriesData[idx] = data.series;
            }
            window.coherenceEditDirty = true;
            feedback.style.color = 'var(--success-color)';
            feedback.textContent = '✓ Modifications enregistrées.';
            // Rebâtir la liste des tomes avec les données fraîches
            if (data.series && data.series.volumes) {
                buildCeditVolumesList(data.series.volumes);
            }
        } else {
            feedback.style.color = 'var(--error-color)';
            feedback.textContent = data.message || 'Une erreur est survenue.';
        }
    })
    .catch(() => {
        feedback.style.color = 'var(--error-color)';
        feedback.textContent = 'Erreur réseau. Veuillez réessayer.';
    })
    .finally(() => {
        saveBtn.disabled = false;
        saveText.textContent = 'Enregistrer';
        saveSpinner.style.display = 'none';
    });
});

function applyCoherenceFilter(filter) {
    document.querySelectorAll('#coherences-list .coherence-series-block').forEach(block => {
        if (filter === 'all') {
            block.style.display = '';
        } else {
            block.style.display = block.dataset.types.split(' ').includes(filter) ? '' : 'none';
        }
    });
}
