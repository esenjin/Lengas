<?php
// ────────────────────────────────────────────────────────────────────────────
// includes/syngas_search_section.php — Section « Recherche Syngas » partagée
//
// Insérée dans la modale d'ajout ET la modale d'édition d'une série manga
// (jamais pour les animés) — section 4 du cahier des charges. Un seul gabarit
// pour les deux contextes : $__syngas_context vaut 'add' (défaut) ou 'edit',
// et détermine le préfixe des identifiants DOM ainsi que le comportement au
// clic sur « Valider » (assets/js/admin/tools/syngas-search.js s'appuie sur
// ce préfixe et sur la présence — ou non — d'un series_id renseigné).
//
// Recherche EXPLICITE uniquement : un champ texte + un bouton « Chercher »,
// jamais de recherche au fil de la frappe, jamais d'intégration à
// l'autocomplétion existante.
// ────────────────────────────────────────────────────────────────────────────
$__syngas_context = $__syngas_context ?? 'add';
$__syngas_prefix  = $__syngas_context === 'edit' ? 'edit-series-syngas' : 'add-series-syngas';
?>
<div class="syngas-search-section" id="<?= htmlspecialchars($__syngas_prefix) ?>-section" data-context="<?= htmlspecialchars($__syngas_context) ?>">
    <h3 class="syngas-search-title">🔎 Recherche Syngas</h3>
    <p class="hint">Cherchez si cette série existe déjà sur Syngas pour pré-remplir sa fiche automatiquement.</p>
    <div class="syngas-search-banned-banner" id="<?= htmlspecialchars($__syngas_prefix) ?>-banned" hidden>
        La connexion de ce site à Syngas a été suspendue.
        <span class="syngas-search-banned-reason"></span>
    </div>
    <div class="syngas-search-row">
        <input type="text" class="syngas-search-input" id="<?= htmlspecialchars($__syngas_prefix) ?>-input" placeholder="Nom de la série sur Syngas" autocomplete="off">
        <button type="button" class="button button-opt syngas-search-btn" id="<?= htmlspecialchars($__syngas_prefix) ?>-btn">
            <span class="syngas-search-btn-text">Chercher</span>
            <span class="spinner syngas-search-spinner" hidden></span>
        </button>
    </div>
    <button type="button" class="syngas-search-toggle-id" id="<?= htmlspecialchars($__syngas_prefix) ?>-toggle-id">Chercher par identifiant Syngas</button>
    <div class="syngas-search-row syngas-search-row--id" id="<?= htmlspecialchars($__syngas_prefix) ?>-id-row" hidden>
        <input type="text" class="syngas-search-input" id="<?= htmlspecialchars($__syngas_prefix) ?>-id-input" placeholder="Identifiant Syngas (ex. abc123)" autocomplete="off">
        <button type="button" class="button button-opt syngas-search-btn" id="<?= htmlspecialchars($__syngas_prefix) ?>-id-btn">
            <span class="syngas-search-btn-text">Chercher</span>
            <span class="spinner syngas-search-spinner" hidden></span>
        </button>
    </div>
    <div class="syngas-search-results" id="<?= htmlspecialchars($__syngas_prefix) ?>-results"></div>
</div>
