// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/contributors.js — Bloc dynamique « Contributeurs »
//
// Remplace les anciens champs séparés Auteur / Éditeur / Autres contributeurs
// par une liste de lignes { nom, rôle }, éditable via un bouton "+" et un
// bouton de suppression par ligne. Utilisé dans la modale d'ajout
// (#add-series-contributors, préfixe POST "contrib") et la modale d'édition
// (#edit-series-contributors, préfixe POST "edit_contrib") d'une série manga.
//
// Chaque ligne soumet trois champs de formulaire indexés pareil :
//   {prefix}_name[], {prefix}_role[], {prefix}_role_custom[]
// lus côté serveur par admin_read_contributors_from_post() (admin.php).
//
// Rôles : registre fermé, dupliqué ici depuis includes/helpers.php
// (contributor_roles()) — voir CONTRIBUTOR_ROLES ci-dessous. Toute
// modification du registre PHP doit être répercutée ici.
// ──────────────────────────────────────────────────────────────────────────

const CONTRIBUTOR_ROLES = [
    ['auteur',           'Auteur'],
    ['scenariste',       'Scénariste'],
    ['dessinateur',      'Dessinateur'],
    ['illustrateur',     'Illustrateur'],
    ['coloriste',        'Coloriste'],
    ['traducteur',       'Traducteur'],
    ['adaptateur',       'Adaptateur'],
    ['lettreur',         'Lettreur'],
    ['editeur',          'Éditeur'],
    ['autre',            'Autre'],
];

function contribEscHtml(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Construit une ligne de contributeur (DOM) et l'attache au conteneur.
// $prefill : { name, role, role_custom } — valeurs initiales (édition ou
// ligne par défaut à l'ajout).
function contributorsAddRow(container, prefill = {}) {
    const prefix = container.dataset.prefix;
    const row = document.createElement('div');
    row.className = 'contributor-row';

    const name = prefill.name ?? '';
    const role = prefill.role ?? '';
    const roleCustom = prefill.role_custom ?? '';

    const optionsHtml = CONTRIBUTOR_ROLES.map(([value, label]) =>
        `<option value="${value}" ${role === value ? 'selected' : ''}>${contribEscHtml(label)}</option>`
    ).join('');

    row.innerHTML = `
        <input type="text" class="contributor-name" name="${prefix}_name[]" placeholder="Nom du contributeur" autocomplete="off" value="${contribEscHtml(name)}">
        <select class="contributor-role" name="${prefix}_role[]">
            <option value="">— Rôle non précisé —</option>
            ${optionsHtml}
        </select>
        <input type="text" class="contributor-role-custom" name="${prefix}_role_custom[]" placeholder="Préciser le rôle" autocomplete="off" value="${contribEscHtml(roleCustom)}" ${role === 'autre' ? '' : 'hidden'}>
        <button type="button" class="contributor-remove-btn" title="Supprimer cette ligne" aria-label="Supprimer cette ligne">&times;</button>
    `;

    container.appendChild(row);
    contributorsSetupRowBehavior(row);
    return row;
}

// Affiche/masque le champ de précision libre selon le rôle sélectionné, et
// branche l'autocomplétion du nom sur ce champ précis (voir
// contributorsSetupNameAutocomplete ci-dessous).
function contributorsSetupRowBehavior(row) {
    const roleSelect = row.querySelector('.contributor-role');
    const customInput = row.querySelector('.contributor-role-custom');
    roleSelect.addEventListener('change', () => {
        const isOther = roleSelect.value === 'autre';
        customInput.hidden = !isOther;
        if (!isOther) customInput.value = '';
    });

    const nameInput = row.querySelector('.contributor-name');
    contributorsSetupNameAutocomplete(nameInput);

    row.querySelector('.contributor-remove-btn').addEventListener('click', () => {
        row.remove();
    });
}

// Autocomplétion dédiée : contrairement à setupAutocomplete() (autocomplete.js,
// conçu pour un input fixe et unique par ID), une ligne de contributeur est
// créée dynamiquement et n'a pas d'ID stable — on reproduit ici la même
// logique (fetchSuggestionsForFields / buildSuggestionItem / addKeyboardNav,
// toutes définies dans autocomplete.js, chargé avant ce fichier) sur un
// conteneur généré à la volée.
function contributorsSetupNameAutocomplete(input) {
    const container = document.createElement('div');
    container.className = 'autocomplete-container';
    input.parentNode.insertBefore(container, input);
    container.appendChild(input);

    const suggestionsList = document.createElement('div');
    suggestionsList.className = 'autocomplete-suggestions';
    container.appendChild(suggestionsList);

    function applySuggestion(item) {
        input.value = item.value;
        suggestionsList.style.display = 'none';
    }

    input.addEventListener('input', async function () {
        const term = this.value.trim();
        if (term.length < 2) {
            suggestionsList.style.display = 'none';
            return;
        }
        try {
            const suggestions = normalizeSuggestions(
                await fetchSuggestionsForFields(term, ['contributors'], { restrictType: currentViewType() })
            );
            suggestionsList.innerHTML = '';
            const normalizedTerm = normalizeString(term);
            const filtered = suggestions.filter(item => normalizeString(item.value).includes(normalizedTerm));
            if (filtered.length > 0) {
                filtered.forEach(item => {
                    suggestionsList.appendChild(buildSuggestionItem(item, false, applySuggestion));
                });
                suggestionsList.style.display = 'block';
            } else {
                suggestionsList.style.display = 'none';
            }
        } catch (error) {
            console.error('Erreur:', error);
        }
    });

    addKeyboardNav(input, suggestionsList, (activeItem) => {
        applySuggestion(activeItem.__suggestion || { value: activeItem.textContent, types: [] });
    });

    document.addEventListener('click', (e) => {
        if (e.target !== input) {
            suggestionsList.style.display = 'none';
            suggestionsList.querySelectorAll('div').forEach(d => d.classList.remove('autocomplete-active'));
        }
    });
}

// Vide le conteneur puis le repeuple avec la liste de contributeurs fournie
// (édition d'une série existante), ou avec les deux lignes par défaut
// (Auteur + Éditeur, toutes deux requises) si la liste est vide — cas de la
// modale d'ajout, ou d'une série sans aucun contributeur.
function contributorsRenderList(container, contributors) {
    container.innerHTML = '';
    const list = Array.isArray(contributors) ? contributors : [];
    if (list.length === 0) {
        const rowAuthor = contributorsAddRow(container, { role: 'auteur' });
        rowAuthor.querySelector('.contributor-name').required = true;
        const rowPublisher = contributorsAddRow(container, { role: 'editeur' });
        rowPublisher.querySelector('.contributor-name').required = true;
        return;
    }
    list.forEach(c => contributorsAddRow(container, c));
}

// Lecture de l'état actuel du bloc (utilisé par coherence.js le cas échéant,
// et pour du debug) : renvoie [{name, role, role_custom}, ...].
function contributorsReadList(container) {
    return [...container.querySelectorAll('.contributor-row')].map(row => ({
        name: row.querySelector('.contributor-name').value.trim(),
        role: row.querySelector('.contributor-role').value,
        role_custom: row.querySelector('.contributor-role-custom').value.trim(),
    })).filter(c => c.name !== '');
}

// Bouton "+ Ajouter un contributeur" : délégation, un seul écouteur pour tous
// les blocs de la page (ajout ET édition).
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.contributors-add-btn');
    if (!btn) return;
    const container = document.getElementById(btn.dataset.contributorsTarget);
    if (container) contributorsAddRow(container);
});

// ── Initialisation modale d'AJOUT ───────────────────────────────────────────
// Deux lignes par défaut (Auteur + Éditeur, requises) à chaque ouverture de
// la modale — cohérent avec le reset du reste du formulaire à l'ouverture.
document.getElementById('open-add-series-modal')?.addEventListener('click', () => {
    const container = document.getElementById('add-series-contributors');
    if (container) contributorsRenderList(container, []);
});

// Rendu initial (chargement de page, avant toute ouverture de modale) : le
// conteneur doit déjà contenir les deux lignes par défaut si la modale est
// ouverte par un lien direct ou un état déjà affiché.
document.addEventListener('DOMContentLoaded', () => {
    const addContainer = document.getElementById('add-series-contributors');
    if (addContainer && !addContainer.children.length) {
        contributorsRenderList(addContainer, []);
    }
});

// ── Initialisation modale d'ÉDITION ─────────────────────────────────────────
// series.js peuple #edit-series-contributors via contributorsRenderList()
// (voir sa fonction d'ouverture de la modale, où series.contributors est
// disponible) — rien à faire ici au-delà de l'exposer globalement.
window.contributorsRenderList = contributorsRenderList;
window.contributorsReadList = contributorsReadList;
