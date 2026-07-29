<?php
// includes/status_filter.php
// Filtre de statuts multi-critères (cases à cocher regroupées par catégorie),
// partagé entre index.php (public) et admin.php.
// Catégories : publication/diffusion, âge, favoris, lecture/visionnage,
// lues ailleurs (mangas uniquement), critique.
// OU à l'intérieur d'une catégorie ; entre catégories, OU ou ET selon le mode.
// Aucun critère coché => tout afficher.
//
// ⚠️ Le panneau est construit pour un type de série donné.
// Les libellés viennent exclusivement du registre de types (includes/helpers.php,
// type_vocab()) — aucun mot en dur ici. Le bloc « Lues ailleurs » n'a pas
// d'équivalent côté animé (aucune notion de lecture externe) et est retiré du
// panneau pour ce type ; le reste des catégories est transverse aux deux types.

if (!function_exists('status_filter_categories')) {

function status_filter_categories($type = null) {
    $type = sanitize_series_type($type ?? default_series_type());
    $is_anime = is_anime($type);
    $vocab = type_vocab($type);

    $categories = [
        'pub' => [
            'label' => $vocab['status_label'],
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
            'label' => $vocab['progress_label'],
            'multi' => true,
            'items' => [
                'reading_not_started' => $vocab['filter_not_started'],
                'reading_in_progress' => $vocab['filter_in_progress'],
                'reading_completed'   => $vocab['filter_completed'],
                'reading_abandoned'   => $vocab['filter_abandoned'],
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

    // Bloc sans objet pour les animés : aucune notion de « lue ailleurs ».
    if ($is_anime) {
        unset($categories['read_elsewhere']);
    }

    return $categories;
}

// Teste si UN critère unique correspond à une série.
//
// ⚠️ Typage : les critères d'avancement (« statut de lecture ») portent sur les
// tomes ET sur les épisodes. Pour un animé, l'abandon se lit sur
// `watching_abandoned` et non sur `reading_abandoned`, et le calcul complet est
// déjà fait par anime_watching_status() : on s'y branche plutôt que de le
// refaire. Le chemin manga, lui, est laissé rigoureusement intact.
function series_matches_status_token($series, $token, $has_review) {
    $is_anime = function_exists('is_anime') && is_anime($series);
    if ($is_anime && in_array($token, ['reading_not_started', 'reading_in_progress',
                                       'reading_completed', 'reading_abandoned'], true)) {
        $watching = anime_watching_status($series);
        switch ($token) {
            case 'reading_not_started': return $watching === 'not_started';
            case 'reading_in_progress': return $watching === 'in_progress';
            case 'reading_completed':   return $watching === 'completed';
            case 'reading_abandoned':   return $watching === 'abandoned';
        }
    }

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
            // Statut de publication / diffusion : toujours le statut RÉEL stocké
            // sur la série (`$series['status']`), jamais déduit de la présence
            // d'un tome ou épisode tagué « dernier ». Un tel tag ne dit rien du
            // statut réel : une série abandonnée ou en pause peut très bien
            // avoir son dernier tome/épisode paru tagué comme tel sans que la
            // série soit « terminée » pour autant — la confondre avec la
            // publication effectivement achevée masquait par exemple les séries
            // abandonnées possédant ce tag. Vrai pour un animé comme pour un
            // manga : même lecture directe du champ, sans recalcul.
            return (($series['status'] ?? 'en cours') === $token);
    }
}

// Applique le filtre multi-critères à un tableau de séries.
//
// $type détermine l'ensemble de catégories valides (voir status_filter_categories) :
// un token envoyé pour une catégorie absente du type courant (ex. "read_elsewhere"
// pour un animé, ou un jeton d'une ancienne sélection manga réutilisé sur un lien
// d'animé) est simplement ignoré plutôt que provoquer une correspondance fausse.
function apply_status_filter($data, $raw, $mode, callable $has_review_fn, $type = null) {
    $tokens = array_filter(array_map('trim', explode(',', (string)$raw)), 'strlen');
    if (empty($tokens)) return $data;

    $mode = ($mode === 'and') ? 'and' : 'or';

    // token => clé de catégorie
    $token_to_cat = [];
    foreach (status_filter_categories($type) as $cat => $def) {
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
// $type : type de série affiché (conditionne les catégories proposées).
function render_status_filter($raw, $mode, $reviews_public, $type = null) {
    $tokens = array_filter(array_map('trim', explode(',', (string)$raw)), 'strlen');
    $all_default = empty($tokens); // rien fourni => tout coché
    $checked = array_fill_keys($tokens, true);
    $mode = ($mode === 'and') ? 'and' : 'or';
    $cats = status_filter_categories($type);
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
