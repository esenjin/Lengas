// ──────────────────────────────────────────────────────────────────────────
// assets/js/personnalites.js — Page publique « Personnalités » (annuaire)
//
// Rendu, tri et filtre des cartes de l'annuaire, à partir de
// window.personalitiesData (une entrée par personnalité, calculée côté
// serveur — voir personnalites.php). L'ouverture de la fiche individuelle
// (modale #personality-detail-modal) est mutualisée avec le reste du site
// via openPersonalityModalByName(), fournie par assets/js/public.js — cette
// page n'a donc PAS sa propre logique de remplissage de modale : cliquer sur
// une carte ici se comporte exactement comme cliquer sur un nom de
// contributeur depuis index.php ou historique.php.
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
        // Fonction commune (assets/js/public.js) : recalcule la fiche à
        // partir de window.allSeriesData plutôt que de réutiliser l'entrée
        // `p` telle quelle, pour un comportement identique quel que soit le
        // point d'entrée (cette carte, ou un lien "Nom (Rôle)" ailleurs sur
        // le site).
        a.addEventListener('click', () => window.openPersonalityModalByName(p.name));
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

    // Lien profond ?nom=... (ex. un signet, un lien partagé) : ouvre
    // directement la fiche en modale au chargement, sans changer la vue de
    // l'annuaire en arrière-plan.
    const requestedName = new URLSearchParams(window.location.search).get('nom');
    if (requestedName && typeof window.openPersonalityModalByName === 'function') {
        window.openPersonalityModalByName(requestedName);
    }
});
