<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/_tools-modals.php — Modales partagées entre plusieurs outils
//
// Chaque modale est utilisée par plus d'une page (ou nécessite $data pour
// savoir si elle doit s'afficher). Une page outil inclut ce fichier UNE FOIS,
// après avoir défini les drapeaux dont elle a besoin — tous à false par
// défaut, donc omettre un drapeau revient à ne pas inclure la modale
// correspondante :
//
//   $tm_coherence_edit       (bool) modale « Corriger la série » (mangas)
//   $tm_anime_coherence_edit (bool) modale « Corriger le visionnage » (animés)
//   $tm_add_mu_url           (bool) modale « Ajouter une URL MangaUpdates »
//   $tm_add_babelio_url      (bool) modale « Ajouter une URL Babelio »
//
// Les alertes/confirmations personnalisées (#custom-alert-modal et
// #custom-confirm-modal) sont toujours incluses : page.js s'appuie dessus
// (showCustomAlert/showCustomConfirm) sur toutes les pages outils.
//
// Requiert que _bootstrap.php ait déjà été inclus ($data doit exister si
// $tm_anime_coherence_edit est utilisé).
// ────────────────────────────────────────────────────────────────────────────
$tm_coherence_edit       = $tm_coherence_edit       ?? false;
$tm_anime_coherence_edit = $tm_anime_coherence_edit ?? false;
$tm_add_mu_url           = $tm_add_mu_url           ?? false;
$tm_add_babelio_url      = $tm_add_babelio_url      ?? false;
?>

<!-- Édition rapide depuis l'outil « Vérification des mangas » -->
<?php if ($tm_coherence_edit): ?>
<div class="modal" id="coherence-edit-modal">
    <div class="modal-content modal-content--wide">
        <span class="close-modal" id="close-coherence-edit-modal">&times;</span>
        <h2>Corriger la série</h2>

        <input type="hidden" id="cedit-series-id">

        <!-- Infos lecture seule -->
        <div class="cedit-info-grid">
            <div class="cedit-info-item">
                <span class="cedit-info-label">Titre</span>
                <span class="cedit-info-value" id="cedit-name"></span>
            </div>
            <div class="cedit-info-item">
                <span class="cedit-info-label">Auteur</span>
                <span class="cedit-info-value" id="cedit-author"></span>
            </div>
            <div class="cedit-info-item">
                <span class="cedit-info-label">Éditeur</span>
                <span class="cedit-info-value" id="cedit-publisher"></span>
            </div>
            <div class="cedit-info-item">
                <span class="cedit-info-label">Catégories</span>
                <span class="cedit-info-value" id="cedit-categories"></span>
            </div>
        </div>

        <hr class="cedit-divider">

        <!-- Champs éditables -->
        <div class="cedit-field-group">
            <label class="cedit-label" for="cedit-status">Statut de publication</label>
            <select id="cedit-status" class="cedit-select">
                <option value="en cours">En cours</option>
                <option value="terminée">Terminée</option>
                <option value="en pause">En pause</option>
                <option value="abandonnée">Abandonnée</option>
            </select>
        </div>

        <div class="cedit-field-group">
            <label class="cedit-label cedit-label--checkbox">
                <input type="checkbox" id="cedit-read-elsewhere">
                Lue ailleurs
            </label>
            <p class="hint">La série est lue en dehors de la collection physique.</p>
        </div>

        <hr class="cedit-divider">

        <!-- Liste des tomes -->
        <div class="cedit-volumes-header">
            <span class="cedit-label">Tomes</span>
            <button type="button" class="button button-sm button-ats" id="cedit-add-volume-btn">+ Ajouter un tome</button>
        </div>
        <div id="cedit-volumes-list" class="cedit-volumes-list">
            <!-- Tomes injectés dynamiquement -->
        </div>

        <div class="modal-actions cedit-actions">
            <button type="button" class="button button-ats" id="cedit-save-btn">
                <span id="cedit-save-text">Enregistrer</span>
                <span id="cedit-save-spinner" class="spinner" style="display:none;"></span>
            </button>
        </div>
        <p id="cedit-feedback" class="cedit-feedback"></p>
    </div>
</div>
<?php endif; ?>

<!-- Édition rapide d'une série animée depuis l'outil « Vérification des mangas » -->
<!-- Volontairement plus étroite que la modale manga ci-dessus : seuls le statut de
     visionnage et sa date sont modifiables (pas d'ajout/suppression d'épisode, pas de
     case « dernier épisode » — Anilist est la seule source, le tag se réévalue seul). -->
<?php if ($tm_anime_coherence_edit && !empty(series_of_type($data, 'anime'))): ?>
<div class="modal" id="anime-coherence-edit-modal">
    <div class="modal-content modal-content--wide">
        <span class="close-modal" id="close-anime-coherence-edit-modal">&times;</span>
        <h2>Corriger le visionnage</h2>

        <input type="hidden" id="acedit-series-id">

        <div class="cedit-info-grid">
            <div class="cedit-info-item">
                <span class="cedit-info-label">Titre</span>
                <span class="cedit-info-value" id="acedit-name"></span>
            </div>
            <div class="cedit-info-item">
                <span class="cedit-info-label">Statut de diffusion</span>
                <span class="cedit-info-value" id="acedit-status"></span>
            </div>
        </div>

        <p class="hint">Seuls le statut de visionnage et sa date se corrigent ici. Le titre, les studios, le format, les genres et le statut de diffusion viennent d'Anilist : une erreur constatée sur ces champs se corrige à la source, via le lien Anilist de la fiche.</p>

        <hr class="cedit-divider">

        <div class="cedit-volumes-header">
            <span class="cedit-label">Épisodes</span>
        </div>
        <div id="acedit-episodes-list" class="cedit-volumes-list">
            <!-- Épisodes injectés dynamiquement -->
        </div>

        <div class="modal-actions cedit-actions">
            <button type="button" class="button button-ats" id="acedit-save-btn">
                <span id="acedit-save-text">Enregistrer</span>
                <span id="acedit-save-spinner" class="spinner" style="display:none;"></span>
            </button>
        </div>
        <p id="acedit-feedback" class="cedit-feedback"></p>
    </div>
</div>
<?php endif; ?>

<!-- Ajouter une URL MangaUpdates (depuis l'outil des tomes manquants) -->
<?php if ($tm_add_mu_url): ?>
<div class="modal" id="add-mu-url-modal">
    <div class="modal-content modal-content--narrow">
        <span class="close-modal" id="close-add-mu-url-modal">&times;</span>
        <h2>Ajouter une URL MangaUpdates</h2>
        <p id="add-mu-url-series-name" class="add-mu-url-series-name"></p>
        <input type="hidden" id="add-mu-url-series-id">
        <input type="text" id="add-mu-url-input" placeholder="https://www.mangaupdates.com/series/xxxxxxx/nom-de-la-serie" autocomplete="off">
        <p class="hint">Collez l'URL de la fiche MangaUpdates de cette série.</p>
        <div class="modal-actions">
            <button id="save-add-mu-url-btn" class="button button-ats">Enregistrer</button>
        </div>
        <p id="add-mu-url-feedback" class="add-mu-url-feedback"></p>
    </div>
</div>
<?php endif; ?>

<!-- Ajout d'une URL Babelio depuis le récapitulatif de campagne -->
<?php if ($tm_add_babelio_url): ?>
<div class="modal" id="add-babelio-url-modal">
    <div class="modal-content modal-content--narrow">
        <span class="close-modal" id="close-add-babelio-url-modal">&times;</span>
        <h2>Ajouter une URL Babelio</h2>
        <p id="add-babelio-url-series-name" class="add-mu-url-series-name"></p>
        <input type="hidden" id="add-babelio-url-series-id">
        <input type="text" id="add-babelio-url-input" placeholder="https://www.babelio.com/serie/… (ou …/livres/… pour un one-shot)" autocomplete="off">
        <p class="hint">Collez l'URL de la fiche <strong>série</strong> (<code>/serie/…</code>). Pour un <strong>one-shot</strong> — un seul tome, sans fiche série sur Babelio — collez l'adresse de la fiche du tome (<code>/livres/…</code>).</p>
        <div class="modal-actions">
            <button id="save-add-babelio-url-btn" class="button button-ats">Enregistrer</button>
        </div>
        <p id="add-babelio-url-feedback" class="add-mu-url-feedback"></p>
    </div>
</div>
<?php endif; ?>

<!-- Alertes personnalisées -->
<div class="modal" id="custom-alert-modal">
    <div class="modal-content">
        <h2 id="custom-alert-title">Avertissement</h2>
        <p id="custom-alert-message"></p>
        <button id="custom-alert-ok" class="button">OK</button>
    </div>
</div>

<!-- Confirmations personnalisées -->
<div class="modal" id="custom-confirm-modal">
    <div class="modal-content">
        <h2 id="custom-confirm-title">Confirmation</h2>
        <p id="custom-confirm-message"></p>
        <div class="modal-actions">
            <button id="custom-confirm-ok" class="button">OK</button>
            <button id="custom-confirm-cancel" class="button">Annuler</button>
        </div>
    </div>
</div>
