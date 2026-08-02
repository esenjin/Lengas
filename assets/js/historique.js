// assets/js/historique.js
// Page publique « Historique » : filtre manga/anime/tout, chargement des
// jours suivants par lot de 30 ("Afficher plus"), ouverture de la modale de
// détail d'une série au clic sur une carte (réutilise fillSeriesDetailModal
// et openModal, fournies par public.js).

document.addEventListener('DOMContentLoaded', function () {
    const timeline   = document.getElementById('history-timeline');
    const loadMoreBtn = document.getElementById('history-load-more');
    const spinner     = document.getElementById('history-loading-spinner');
    const typeButtons = document.querySelectorAll('.history-type-btn');

    if (!timeline || !window.HISTORY) return;

    let offset     = window.HISTORY.offset || 0;
    let hasMore    = !!window.HISTORY.hasMore;
    let typeFilter = window.HISTORY.typeFilter || '';
    let isLoading  = false;

    function setLoading(state) {
        isLoading = state;
        if (spinner) spinner.classList.toggle('active', state);
        if (loadMoreBtn) loadMoreBtn.disabled = state;
    }

    function showEmptyMessageIfNeeded() {
        if (timeline.children.length === 0) {
            const p = document.createElement('p');
            p.className = 'history-empty';
            p.id = 'history-empty-message';
            p.textContent = 'Rien à afficher pour le moment.';
            timeline.appendChild(p);
        }
    }

    // Retire le message "rien à afficher" s'il est encore présent (cas d'un
    // changement de filtre qui fait apparaître des résultats).
    function removeEmptyMessage() {
        const existing = document.getElementById('history-empty-message');
        if (existing) existing.remove();
    }

    // Attache l'ouverture de la modale de détail sur chaque carte série
    // nouvellement insérée dans le DOM.
    function bindCardClicks(container) {
        container.querySelectorAll('.history-card').forEach(card => {
            if (card.__historyBound) return;
            card.__historyBound = true;
            card.addEventListener('click', function () {
                const seriesId = this.dataset.seriesId;
                const pool = Array.isArray(window.allSeriesData) ? window.allSeriesData : [];
                const series = pool.find(s => s.id === seriesId);
                if (!series || typeof window.fillSeriesDetailModal !== 'function') return;
                window.fillSeriesDetailModal(series);
                window.openModal('series-detail-modal');
            });
        });
    }

    bindCardClicks(timeline);

    function loadMoreDays() {
        if (isLoading || !hasMore) return;
        setLoading(true);

        const url = `historique.php?get_more_days=1&offset=${encodeURIComponent(offset)}&type=${encodeURIComponent(typeFilter)}`;
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.html) {
                    removeEmptyMessage();
                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = data.html;
                    // Déplace chaque section .history-day dans la timeline
                    // (évite d'insérer le wrapper temporaire lui-même).
                    Array.from(wrapper.children).forEach(child => timeline.appendChild(child));
                    bindCardClicks(timeline);
                    offset += data.count || 0;
                    hasMore = !!data.has_more;
                } else {
                    hasMore = false;
                }
                if (loadMoreBtn) loadMoreBtn.hidden = !hasMore;
                showEmptyMessageIfNeeded();
            })
            .catch(() => {
                hasMore = false;
                if (loadMoreBtn) loadMoreBtn.hidden = true;
            })
            .finally(() => setLoading(false));
    }

    loadMoreBtn?.addEventListener('click', loadMoreDays);

    // Filtre manga / anime / tout : redirige simplement vers l'URL filtrée
    // (rechargement complet, cohérent avec la navigation par lien de la
    // sidebar publique, et plus simple/robuste qu'un rechargement AJAX de
    // toute la timeline).
    typeButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const type = this.dataset.historyType || '';
            const url = new URL(window.location.href);
            if (type === '') {
                url.searchParams.delete('type');
            } else {
                url.searchParams.set('type', type);
            }
            window.location.href = url.toString();
        });
    });
});
