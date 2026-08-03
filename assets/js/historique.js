// assets/js/historique.js
// Page publique « Historique » : filtre manga/anime/tout, recherche par nom
// de série (titres alternatifs inclus), chargement des jours suivants par
// lot de 30 ("Afficher plus" ou déclenché automatiquement par la recherche),
// ouverture de la modale de détail d'une série au clic sur une carte
// (réutilise fillSeriesDetailModal et openModal, fournies par public.js).

document.addEventListener('DOMContentLoaded', function () {
    const timeline    = document.getElementById('history-timeline');
    const loadMoreBtn = document.getElementById('history-load-more');
    const spinner     = document.getElementById('history-loading-spinner');
    const typeButtons = document.querySelectorAll('.history-type-btn');
    const searchInput  = document.getElementById('history-search-input');
    const searchWrap   = document.getElementById('history-search-wrap');
    const searchClear  = document.getElementById('history-search-clear');
    const searchStatus = document.getElementById('history-search-status');

    if (!timeline || !window.HISTORY) return;

    let offset     = window.HISTORY.offset || 0;
    let hasMore    = !!window.HISTORY.hasMore;
    let typeFilter = window.HISTORY.typeFilter || '';
    let isLoading  = false;

    // Recherche par série : terme normalisé courant ('' = pas de recherche).
    let searchTerm = '';
    // Empêche deux boucles de chargement automatique concurrentes (une
    // saisie rapide relance sinon plusieurs recherches en parallèle).
    let searchAutoLoadToken = 0;
    // Sécurité : nombre de lots de 30 jours qu'une même recherche peut
    // charger automatiquement avant de s'arrêter et de laisser la main à
    // "Afficher plus". Un historique très volumineux sans la moindre
    // correspondance chargerait sinon indéfiniment des jours en DOM.
    const SEARCH_AUTO_LOAD_MAX_BATCHES = 20; // ~600 jours
    let searchAutoLoadBatchCount = 0;

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

    // ── Recherche par nom de série (titre choisi + titres alternatifs) ─────
    // Même normalisation (accents, casse, espaces conservés) que
    // history_normalize_search() côté PHP (historique.php), pour que le
    // data-search généré par le serveur et le terme tapé ici se comparent
    // correctement.
    const ACCENTS_MAP = {
        'à':'a','á':'a','â':'a','ã':'a','ä':'a','å':'a','æ':'ae','ç':'c',
        'è':'e','é':'e','ê':'e','ë':'e','ì':'i','í':'i','î':'i','ï':'i',
        'ð':'d','ñ':'n','ò':'o','ó':'o','ô':'o','õ':'o','ö':'o','ø':'o',
        'ù':'u','ú':'u','û':'u','ü':'u','ý':'y','ÿ':'y','ŕ':'r','ß':'s'
    };
    function normalizeSearchText(str) {
        return (str || '')
            .toLowerCase()
            .replace(/[àáâãäåæçèéêëìíîïðñòóôõöøùúûüýÿŕß]/g, ch => ACCENTS_MAP[ch] || ch)
            .trim();
    }

    // Applique le filtre de recherche courant aux cartes déjà en DOM : montre
    // ou cache chaque carte selon son data-search, puis masque les jours
    // entièrement vides. Ne déclenche PAS le chargement automatique de plus
    // de jours (rôle de maybeAutoLoadForSearch, appelée séparément).
    function applySearchFilter() {
        let anyVisible = false;
        timeline.querySelectorAll('.history-day').forEach(daySection => {
            let dayHasVisibleCard = false;
            daySection.querySelectorAll('.history-card').forEach(card => {
                const haystack = card.dataset.search || '';
                const matches = searchTerm === '' || haystack.indexOf(searchTerm) !== -1;
                card.hidden = !matches;
                if (matches) dayHasVisibleCard = true;
            });
            daySection.hidden = !dayHasVisibleCard;
            if (dayHasVisibleCard) anyVisible = true;
        });
        return anyVisible;
    }

    // Tant que la recherche est active, qu'aucune carte visible n'a été
    // trouvée dans les jours déjà chargés et qu'il reste des jours plus
    // anciens à charger, enchaîne les chargements par lot de 30 (comme
    // "Afficher plus"), avec un indicateur "Recherche en cours…".
    //
    // `token` identifie la recherche en cours : si l'utilisateur retape
    // pendant qu'une chaîne de chargements est en vol, le jeton courant
    // change et cette fonction s'arrête AVANT de lancer le fetch suivant
    // (et pas seulement après coup) — sans cette vérification en tête de
    // fonction, plusieurs chaînes de récursion tournaient en parallèle à
    // chaque frappe et empilaient des centaines de jours en double dans le
    // DOM, jusqu'à geler l'onglet.
    function maybeAutoLoadForSearch(token) {
        if (token !== searchAutoLoadToken) return; // recherche annulée entre-temps
        if (searchTerm === '') {
            if (searchStatus) searchStatus.textContent = '';
            return;
        }

        const anyVisible = applySearchFilter();
        removeEmptyMessage();

        if (anyVisible) {
            if (searchStatus) searchStatus.textContent = '';
            return;
        }

        if (!hasMore) {
            if (searchStatus) searchStatus.textContent = 'Aucune série trouvée dans tout l\'historique.';
            return;
        }

        if (searchAutoLoadBatchCount >= SEARCH_AUTO_LOAD_MAX_BATCHES) {
            if (searchStatus) searchStatus.textContent = 'Aucune série trouvée dans les jours les plus récents. Utilisez « Afficher plus » pour continuer la recherche.';
            if (loadMoreBtn) loadMoreBtn.hidden = false;
            return;
        }

        if (searchStatus) searchStatus.textContent = 'Recherche en cours…';
        searchAutoLoadBatchCount++;
        loadMoreDays().then(() => {
            maybeAutoLoadForSearch(token);
        });
    }

    // Renvoie une Promise résolue une fois le lot suivant inséré (ou
    // immédiatement si aucun chargement n'était possible), pour pouvoir
    // s'enchaîner proprement dans maybeAutoLoadForSearch().
    function loadMoreDays() {
        if (isLoading || !hasMore) return Promise.resolve();
        setLoading(true);

        const url = `historique.php?get_more_days=1&offset=${encodeURIComponent(offset)}&type=${encodeURIComponent(typeFilter)}`;
        return fetch(url)
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
                if (searchTerm === '') showEmptyMessageIfNeeded();
            })
            .catch(() => {
                hasMore = false;
                if (loadMoreBtn) loadMoreBtn.hidden = true;
            })
            .finally(() => setLoading(false));
    }

    loadMoreBtn?.addEventListener('click', function () {
        loadMoreDays().then(() => {
            // Un nouveau lot vient d'arriver : la recherche en cours doit lui
            // être appliquée aussi (sinon ses cartes resteraient visibles
            // sans filtrage tant qu'on ne retape rien dans le champ).
            if (searchTerm !== '') applySearchFilter();
        });
    });

    let searchDebounceTimer = null;

    searchInput?.addEventListener('input', function () {
        const raw = this.value;
        searchTerm = normalizeSearchText(raw);
        searchWrap?.classList.toggle('has-value', raw.length > 0);

        // Invalide immédiatement toute chaîne de chargement automatique en
        // cours (cf. maybeAutoLoadForSearch) : sans cela, une frappe rapide
        // pouvait laisser plusieurs chaînes tourner en parallèle et empiler
        // des jours en double dans le DOM jusqu'à geler l'onglet.
        searchAutoLoadToken++;
        searchAutoLoadBatchCount = 0;
        if (searchDebounceTimer) clearTimeout(searchDebounceTimer);

        if (searchTerm === '') {
            removeEmptyMessage();
            applySearchFilter(); // réaffiche tout
            showEmptyMessageIfNeeded();
            if (searchStatus) searchStatus.textContent = '';
            return;
        }

        // Filtrage instantané sur ce qui est déjà en DOM, sans attendre.
        applySearchFilter();

        // Le déclenchement d'un éventuel chargement automatique de jours
        // supplémentaires, lui, est différé (debounce) : pas la peine de
        // lancer un fetch à chaque caractère tapé pendant une saisie rapide.
        searchDebounceTimer = setTimeout(function () {
            maybeAutoLoadForSearch(searchAutoLoadToken);
        }, 350);
    });

    searchClear?.addEventListener('click', function () {
        if (!searchInput) return;
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
        searchInput.focus();
    });

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
