<?php
// includes/status_filter.php
// Filtre de statuts multi-critères (cases à cocher regroupées par catégorie),
// partagé entre index.php (public) et admin.php.
// Catégories : publication, âge, favoris, lecture, lues ailleurs, critique.
// OU à l'intérieur d'une catégorie ; entre catégories, OU ou ET selon le mode.
// Aucun critère coché => tout afficher.

if (!function_exists('status_filter_categories')) {

function status_filter_categories() {
    return [
        'pub' => [
            'label' => 'Statut de publication',
            'multi' => true,
            'items' => [
                'en cours'   => 'En cours ▶️',
                'terminée'   => 'Terminée ✅',
                'en pause'   => 'En pause ⏳',
                'abandonnée' => 'Abandonnée ⛔',
            ],
        ],
        'age' => [
            'label' => 'Classe d\'âge',
            'multi' => true,
            'items' => [
                'mature'     => 'Mature 🔞',
                'non_mature' => 'Non mature 👐',
            ],
        ],
        'favorite' => [
            'label' => 'Favoris',
            'multi' => true,
            'items' => [
                'favorite'     => 'Mes favoris ❤️',
                'not_favorite' => 'Non favoris 🤍',
            ],
        ],
        'reading' => [
            'label' => 'Statut de lecture',
            'multi' => true,
            'items' => [
                'reading_not_started' => 'À débuter 📖',
                'reading_in_progress' => 'En cours 📘',
                'reading_completed'   => 'Terminée 📗',
                'reading_abandoned'   => 'Abandonnée 📕',
            ],
        ],
        'read_elsewhere' => [
            'label' => 'Lues ailleurs',
            'multi' => true,
            'items' => [
                'read_elsewhere' => 'Lues ailleurs 📚',
                'in_library'     => 'Dans la bibliothèque 🏠',
            ],
        ],
        'review' => [
            'label' => 'Critique',
            'multi' => true,
            'requires_reviews' => true,
            'items' => [
                'has_review' => 'Avec critique ✏️',
                'no_review'  => 'Sans critique 📝',
            ],
        ],
        'rating' => [
            'label' => 'Notation',
            'multi' => true,
            'items' => [
                'rating_apprecie' => "J'ai apprécié ☺️",
                'rating_mitige'   => 'Mi-figue mi-raisin 😑',
                'rating_deteste'  => "Je n'ai pas aimé 😠",
                'rating_none'     => 'Sans note ➖',
            ],
        ],
    ];
}

// Teste si UN critère unique correspond à une série.
function series_matches_status_token($series, $token, $has_review) {
    switch ($token) {
        case 'has_review':
            return $has_review;
        case 'no_review':
            return !$has_review;
        case 'mature':
            return !empty($series['mature']);
        case 'non_mature':
            return empty($series['mature']);
        case 'favorite':
            return !empty($series['favorite']);
        case 'not_favorite':
            return empty($series['favorite']);
        case 'read_elsewhere':
            return !empty($series['read_elsewhere']);
        case 'in_library':
            return empty($series['read_elsewhere']);
        case 'reading_not_started':
            if (!empty($series['reading_abandoned'])) return false;
            foreach ($series['volumes'] ?? [] as $volume) {
                if ($volume['status'] === 'terminé') return false;
            }
            return true;
        case 'reading_in_progress':
            if (!empty($series['reading_abandoned'])) return false;
            $has_read = false; $is_pub_finished = false;
            foreach ($series['volumes'] ?? [] as $volume) {
                if ($volume['status'] === 'terminé') $has_read = true;
                if (!empty($volume['last'])) $is_pub_finished = true;
            }
            return $has_read && !$is_pub_finished;
        case 'reading_completed':
            if (!empty($series['reading_abandoned'])) return false;
            $volumes = $series['volumes'] ?? [];
            if (empty($volumes)) return false;
            $has_last = false;
            foreach ($volumes as $volume) {
                if ($volume['status'] !== 'terminé') return false;
                if (!empty($volume['last'])) $has_last = true;
            }
            return $has_last;
        case 'reading_abandoned':
            return !empty($series['reading_abandoned']);
        case 'rating_apprecie':
            return ($series['rating'] ?? '') === 'apprecie';
        case 'rating_mitige':
            return ($series['rating'] ?? '') === 'mitige';
        case 'rating_deteste':
            return ($series['rating'] ?? '') === 'deteste';
        case 'rating_none':
            return empty($series['rating']);
        default:
            // Statut de publication
            $status = 'en cours';
            if (!empty($series['volumes'])) {
                foreach ($series['volumes'] as $volume) {
                    if (!empty($volume['last'])) { $status = 'terminée'; break; }
                }
            }
            if ($status === 'en cours' && !empty($series['status'])) {
                $status = $series['status'];
            }
            return $status === $token;
    }
}

// Applique le filtre multi-critères à un tableau de séries.
function apply_status_filter($data, $raw, $mode, callable $has_review_fn) {
    $tokens = array_filter(array_map('trim', explode(',', (string)$raw)), 'strlen');
    if (empty($tokens)) return $data;

    $mode = ($mode === 'and') ? 'and' : 'or';

    // token => clé de catégorie
    $token_to_cat = [];
    foreach (status_filter_categories() as $cat => $def) {
        foreach (array_keys($def['items']) as $tok) {
            $token_to_cat[$tok] = $cat;
        }
    }

    $selected_by_cat = [];
    foreach ($tokens as $tok) {
        if (isset($token_to_cat[$tok])) {
            $selected_by_cat[$token_to_cat[$tok]][] = $tok;
        }
    }
    if (empty($selected_by_cat)) return $data;

    return array_filter($data, function($series) use ($selected_by_cat, $mode, $has_review_fn) {
        $has_review = $has_review_fn($series);
        $cat_results = [];
        foreach ($selected_by_cat as $cat => $toks) {
            $ok = false;
            foreach ($toks as $tok) {
                if (series_matches_status_token($series, $tok, $has_review)) { $ok = true; break; }
            }
            $cat_results[] = $ok;
        }
        if ($mode === 'and') {
            return !in_array(false, $cat_results, true);
        }
        return in_array(true, $cat_results, true);
    });
}

// Rendu du widget de filtre (cases à cocher regroupées + bascule OU/ET).
// $raw : "a,b,c" (cases initialement cochées) ; vide => TOUT coché par défaut.
function render_status_filter($raw, $mode, $reviews_public) {
    $tokens = array_filter(array_map('trim', explode(',', (string)$raw)), 'strlen');
    $all_default = empty($tokens); // rien fourni => tout coché
    $checked = array_fill_keys($tokens, true);
    $mode = ($mode === 'and') ? 'and' : 'or';
    $cats = status_filter_categories();
    ?>
    <div class="status-filter" id="status-filter" data-status-mode="<?= $mode ?>">
        <button type="button" class="status-filter-toggle" aria-expanded="false">
            <span class="status-filter-label">Statuts</span>
            <span class="status-filter-caret">▾</span>
        </button>
        <div class="status-filter-panel" hidden>
            <div class="status-filter-head">
                <label class="status-filter-mode-switch" title="OU : au moins une catégorie. ET : toutes les catégories.">
                    <span>Combinaison :</span>
                    <select class="status-filter-mode">
                        <option value="or"  <?= $mode === 'or'  ? 'selected' : '' ?>>OU (au moins une)</option>
                        <option value="and" <?= $mode === 'and' ? 'selected' : '' ?>>ET (toutes)</option>
                    </select>
                </label>
                <button type="button" class="status-filter-toggle-all" data-state="uncheck">Tout décocher</button>
            </div>
            <div class="status-filter-groups">
                <?php foreach ($cats as $cat => $def):
                    if (!empty($def['requires_reviews']) && !$reviews_public) continue; ?>
                    <fieldset class="status-filter-group" data-cat="<?= htmlspecialchars($cat) ?>" data-multi="<?= !empty($def['multi']) ? '1' : '0' ?>">
                        <legend><?= htmlspecialchars($def['label']) ?></legend>
                        <?php foreach ($def['items'] as $value => $text):
                            $is_checked = $all_default ? true : !empty($checked[$value]); ?>
                            <label class="status-filter-option">
                                <input type="checkbox" class="status-filter-cb" value="<?= htmlspecialchars($value) ?>" <?= $is_checked ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($text) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
}

} // end function_exists guard
