// ─────────────────────────────────────────────────────────────────────────────
// assets/js/admin/episodes.js — Épisodes des séries animées (admin)
//
// Pendant de volumes.js pour l'Animethèque. Deux gestes seulement, parce qu'il
// n'y en a pas d'autres à offrir :
//   • éditer le statut de visionnage d'un épisode (et sa date) ;
//   • marquer l'épisode suivant comme vu, via le bouton « + » de la carte.
//
// Ni ajout, ni suppression : la liste des épisodes vient d'Anilist. Le fichier
// n'écrit donc jamais un numéro d'épisode, il ne fait que déplacer un statut.
//
// Les libellés (« Épisode », « visionnage », « terminé ») sont lus dans le
// registre des types exposé par le PHP (window.seriesTypes), jamais écrits en
// dur : ajouter un type reste une seule entrée dans includes/helpers.php.
// ─────────────────────────────────────────────────────────────────────────────

// Vocabulaire du type `anime`, avec repli si le registre n'est pas exposé.
function animeVocab(key, fallback) {
    const def = (window.seriesTypes && window.seriesTypes.anime) || null;
    const vocab = def && def.vocab ? def.vocab : null;
    return (vocab && vocab[key]) ? vocab[key] : fallback;
}

// Retrouve une série dans window.seriesData (tableau ou objet indexé).
function findSeriesById(seriesId) {
    const list = Array.isArray(window.seriesData)
        ? window.seriesData
        : Object.values(window.seriesData || {});
    return list.find(s => s && s.id === seriesId) || null;
}

// Date ISO → JJ/MM/AAAA (les infobulles sont écrites en clair).
function episodeFormatDate(value) {
    if (!value) return '';
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
    return m ? `${m[3]}/${m[2]}/${m[1]}` : value;
}

// Infobulle d'un épisode, à l'identique de celle générée côté serveur : ni date
// d'ajout à la collection, ni tag collector — un épisode ne s'achète pas.
function episodeTooltip(episode) {
    const lines = [];
    if (episode.status === animeVocab('done', 'terminé')) {
        const watched = episodeFormatDate(episode.read_at);
        if (watched) {
            lines.push(`Date de ${animeVocab('activity', 'visionnage')} : ${watched}`);
        }
    }
    if (episode.last) {
        lines.push(`Dernier ${animeVocab('item', 'épisode')} de la série !`);
    }
    return lines.join('\n');
}

// ─────────────────────────────────────────────────────────────────────────────
// Modale « Éditer l'épisode »
// ─────────────────────────────────────────────────────────────────────────────

// La date de visionnage n'a de sens que sur un épisode terminé : ailleurs, le
// champ disparaît plutôt que de proposer une date qui serait effacée.
function updateWatchedAtVisibility() {
    const status = document.getElementById('edit-episode-status');
    const label  = document.getElementById('edit-episode-watched-at-label');
    if (!status || !label) return;
    label.style.display = (status.value === animeVocab('done', 'terminé')) ? '' : 'none';
}

function openEpisodeModal(series, episodeIndex) {
    const modal = document.getElementById('edit-episode-modal');
    if (!modal || !series || !series.volumes) return;

    const episode = series.volumes[episodeIndex];
    if (!episode) return;

    document.getElementById('edit-episode-series-id').value = series.id;
    document.getElementById('edit-episode-index').value = episodeIndex;
    document.getElementById('edit-episode-number-display').textContent =
        `${animeVocab('item_cap', 'Épisode')} ${episode.number}`;

    const status = document.getElementById('edit-episode-status');
    status.value = episode.status || '';
    // Statut hérité d'une base antérieure au typage (« à lire ») : le sélecteur
    // resterait vide et le formulaire refuserait de partir. On retombe alors sur
    // le statut de départ plutôt que de laisser l'utilisateur devant un champ
    // muet.
    if (status.value === '') status.value = animeVocab('todo', 'à voir');

    document.getElementById('edit-episode-watched-at').value = episode.read_at || '';

    const applyAll = document.getElementById('edit-episode-apply-status-all');
    if (applyAll) applyAll.checked = false;

    updateWatchedAtVisibility();
    modal.classList.add('modal-active');
}

document.getElementById('edit-episode-status')?.addEventListener('change', updateWatchedAtVisibility);

// ─────────────────────────────────────────────────────────────────────────────
// Bouton « + » : épisode suivant marqué comme vu
// ─────────────────────────────────────────────────────────────────────────────

// Séries dont une requête est en cours : sans cela, deux clics rapides
// marqueraient deux épisodes pour un seul geste voulu.
const episodeMarkPending = new Set();

// Répercute dans la page l'épisode que le serveur vient de marquer, sans
// rechargement : c'est ce qui permet d'enchaîner les clics pour rattraper
// plusieurs épisodes d'affilée.
function applyEpisodeUpdate(seriesId, payload) {
    const series = findSeriesById(seriesId);
    if (series && series.volumes && series.volumes[payload.episode_index]) {
        series.volumes[payload.episode_index].status  = payload.episode.status;
        series.volumes[payload.episode_index].read_at = payload.episode.read_at;
        series.volumes[payload.episode_index].last    = !!payload.episode.last;
    }

    const container = document.querySelector(`.volumes-container[data-series-id="${seriesId}"]`);
    if (!container) return;

    const li = container.querySelector(`li[data-volume-index="${payload.episode_index}"]`);
    if (li) {
        // La classe de statut suit la convention du serveur : espaces en tirets.
        const statusClass = 'status-' + String(payload.episode.status).replace(/ /g, '-');
        li.className = statusClass + (payload.episode.last ? ' volume-last' : '');
        const tip = episodeTooltip(payload.episode);
        if (tip) li.setAttribute('data-title', tip);
        else li.removeAttribute('data-title');
    }

    // Plus rien à marquer : le bouton n'a plus d'objet.
    if (payload.counts && payload.counts.remaining === 0) {
        container.querySelector('.episode-mark-btn')?.remove();
    }
}

async function markNextEpisode(seriesId) {
    if (!seriesId || episodeMarkPending.has(seriesId)) return;
    episodeMarkPending.add(seriesId);

    try {
        const response = await fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'mark_next_episode=1&series_id=' + encodeURIComponent(seriesId)
        });
        const data = await response.json();

        if (!data.success) {
            showCustomAlert('Épisodes', data.message || "L'épisode n'a pas pu être marqué.");
            return;
        }
        applyEpisodeUpdate(seriesId, data);
    } catch (error) {
        console.error('Erreur:', error);
        showCustomAlert('Épisodes', "Le serveur n'a pas répondu : l'épisode n'a pas été marqué.");
    } finally {
        episodeMarkPending.delete(seriesId);
    }
}
