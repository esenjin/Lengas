// ──────────────────────────────────────────────────────────────────────────
// assets/js/personnalites.js — Page publique « Personnalités »
//
// Tout le rendu de l'annuaire (cartes, tri, filtre par rôle, compteur) se
// fait ici, côté client, à partir de window.personalitiesData (une entrée
// par personnalité, voir personnalites.php). La fiche individuelle d'une
// personnalité s'ouvre dans une modale (#personality-detail-modal) plutôt
// que de naviguer vers une page séparée — le clic sur une série de cette
// modale ouvre à son tour la modale de détail série habituelle
// (#series-detail-modal, fillSeriesDetailModal(), fournie par public.js).
// ──────────────────────────────────────────────────────────────────────────

function persoEscHtml(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', function () {
    const grid = document.getElementById('personalities-grid');
    const roleFilter = document.getElementById('personalities-role-filter');
    const sortSelect = document.getElementById('personalities-sort');
    const countEl = document.getElementById('personalities-count');
    const all = Array.isArray(window.personalitiesData) ? window.personalitiesData : [];

    if (!grid || all.length === 0) return; // rien à afficher, ou page en mode privé

    // ── Rendu d'une carte ────────────────────────────────────────────────────
    function renderCard(p) {
        const a = document.createElement('button');
        a.type = 'button';
        a.className = 'personality-card';
        a.dataset.name = p.name;
        a.innerHTML = `
            <img src="${persoEscHtml(p.thumbnail)}" alt="" loading="lazy">
            <div class="personality-card-body">
                <strong>${persoEscHtml(p.name)}</strong>
                <p class="personality-card-count">${p.series_count} série${p.series_count > 1 ? 's' : ''}</p>
                <p class="personality-card-roles">${persoEscHtml(p.role_labels.join(', '))}</p>
            </div>
        `;
        a.addEventListener('click', () => openPersonalityModal(p));
        return a;
    }

    // ── Filtre + tri + compteur ─────────────────────────────────────────────
    function applyFilterAndSort() {
        const role = roleFilter ? roleFilter.value : '';
        const sort = sortSelect ? sortSelect.value : 'name_asc';

        let list = role === ''
            ? all.slice()
            : all.filter(p => p.role_keys.includes(role));

        const comparators = {
            name_asc:    (a, b) => a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }),
            name_desc:   (a, b) => b.name.localeCompare(a.name, 'fr', { sensitivity: 'base' }),
            series_desc: (a, b) => b.series_count - a.series_count || a.name.localeCompare(b.name, 'fr'),
            series_asc:  (a, b) => a.series_count - b.series_count || a.name.localeCompare(b.name, 'fr'),
            roles_desc:  (a, b) => b.roles_count - a.roles_count || a.name.localeCompare(b.name, 'fr'),
            roles_asc:   (a, b) => a.roles_count - b.roles_count || a.name.localeCompare(b.name, 'fr'),
        };
        list.sort(comparators[sort] || comparators.name_asc);

        grid.innerHTML = '';
        if (list.length === 0) {
            grid.innerHTML = '<p class="reviews-empty">Aucune personnalité pour ce filtre.</p>';
        } else {
            list.forEach(p => grid.appendChild(renderCard(p)));
        }

        if (countEl) {
            countEl.textContent = `${list.length} personnalité${list.length > 1 ? 's' : ''}`;
        }
    }

    roleFilter?.addEventListener('change', applyFilterAndSort);
    sortSelect?.addEventListener('change', applyFilterAndSort);
    applyFilterAndSort();

    // Lien profond ?nom=... (ex. depuis la modale de détail d'une série,
    // assets/js/public.js, qui pointe vers un contributeur précis) : ouvre
    // directement sa fiche en modale au chargement, sans changer la vue de
    // l'annuaire en arrière-plan — remplace l'ancienne page dédiée par
    // contributeur, retirée au profit de cette modale.
    const requestedName = new URLSearchParams(window.location.search).get('nom');
    if (requestedName) {
        const match = all.find(p => p.name === requestedName);
        if (match) openPersonalityModal(match);
    }

    // ── Modale de fiche individuelle ────────────────────────────────────────
    function openPersonalityModal(p) {
        document.getElementById('personality-modal-thumb').src = p.thumbnail;
        document.getElementById('personality-modal-name').textContent = p.name;
        document.getElementById('personality-modal-roles').innerHTML = p.role_labels
            .map(label => `<span class="personality-role-badge">${persoEscHtml(label)}</span>`)
            .join('');
        document.getElementById('personality-modal-count').textContent =
            `${p.series_count} série${p.series_count > 1 ? 's' : ''}`;

        const seriesList = document.getElementById('personality-modal-series');
        seriesList.innerHTML = '';
        p.series.forEach(s => {
            const card = document.createElement('button');
            card.type = 'button';
            card.className = 'personality-series-card';
            card.dataset.seriesId = s.id;
            card.innerHTML = `
                <img src="${persoEscHtml(s.thumbnail)}" alt="" loading="lazy">
                <div>
                    <strong>${persoEscHtml(s.name)}</strong>
                    <p>(${persoEscHtml(s.roles.join(', '))})</p>
                </div>
            `;
            card.addEventListener('click', () => {
                const pool = Array.isArray(window.allSeriesData) ? window.allSeriesData : [];
                const series = pool.find(x => x.id === s.id);
                if (!series || typeof window.fillSeriesDetailModal !== 'function') return;
                window.fillSeriesDetailModal(series);
                window.openModal('series-detail-modal');
            });
            seriesList.appendChild(card);
        });

        window.openModal('personality-detail-modal');
    }
});
