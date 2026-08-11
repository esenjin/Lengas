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
        'physical' => [
            'label' => 'Édition physique',
            'multi' => true,
            'requires_anime' => true,
            'items' => [
                'has_physical' => 'Avec édition physique 📀',
                'no_physical'  => 'Sans édition physique ➖',
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
    // Bloc sans objet pour les mangas : aucune notion d'édition physique
    // suivie sur la fiche (contrairement aux animés, cf. fonctions/anime.php).
    if (!$is_anime) {
        unset($categories['physical']);
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
        case 'has_physical':
            return count($series['editions'] ?? []) > 0;
        case 'no_physical':
            return count($series['editions'] ?? []) === 0;
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
// $raw : "a,b,c" (cases initialement cochées) ; vide => TOUT décoché par défaut
// (aucun critère sélectionné = aucun filtrage = tout est affiché).
// $type : type de série affiché (conditionne les catégories proposées).
function render_status_filter($raw, $mode, $reviews_public, $type = null) {
    $tokens = array_filter(array_map('trim', explode(',', (string)$raw)), 'strlen');
    $checked = array_fill_keys($tokens, true);
    // Par défaut (aucun paramètre fourni dans l'URL), le mode est "et" et
    // aucune case n'est cochée : $raw === '' et $mode === '' distinguent ce
    // cas initial d'un état explicitement choisi par l'utilisateur (qui, lui,
    // peut tout à fait avoir tout décoché en mode "ou").
    $mode = ($mode === 'or') ? 'or' : 'and';
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
                <button type="button" class="status-filter-toggle-all" data-state="check">Tout cocher</button>
            </div>
            <div class="status-filter-groups">
                <?php foreach ($cats as $cat => $def):
                    if (!empty($def['requires_reviews']) && !$reviews_public) continue; ?>
                    <fieldset class="status-filter-group" data-cat="<?= htmlspecialchars($cat) ?>" data-multi="<?= !empty($def['multi']) ? '1' : '0' ?>">
                        <legend><?= htmlspecialchars($def['label']) ?></legend>
                        <?php foreach ($def['items'] as $value => $text):
                            $is_checked = !empty($checked[$value]); ?>
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

// ─────────────────────────────────────────────────────────────────────────────
// Filtre « Affiner » : Catégories / Genres, à côté de « Statuts ».
// Même mécanique (cases à cocher regroupées, mode OU/ET, tout décoché par
// défaut), mais les options sont calculées dynamiquement à partir des séries
// réellement présentes dans la collection affichée plutôt que d'une liste
// figée — catégories et genres sont des champs libres. Se combine TOUJOURS en
// ET avec le filtre « Statuts » (deux filtres indépendants), chacun gardant
// son propre mode OU/ET interne entre ses catégories.
// ─────────────────────────────────────────────────────────────────────────────

// Liste triée (insensible à la casse) des valeurs distinctes d'un champ liste
// (categories ou genres) présentes dans $data.
function refine_filter_values(array $data, string $field): array {
    $values = [];
    foreach ($data as $series) {
        foreach ((array)($series[$field] ?? []) as $v) {
            $v = trim((string)$v);
            if ($v === '') continue;
            if (!isset($values[$v])) $values[$v] = true;
        }
    }
    $out = array_keys($values);
    natcasesort($out);
    return array_values($out);
}

// Catégories du widget « Affiner », au même format que status_filter_categories() :
// clé => ['label'=>.., 'multi'=>true, 'items'=>[valeur => libellé]].
// Les valeurs elles-mêmes servent à la fois de clé technique et de libellé
// affiché (contrairement aux jetons de statuts, un nom de catégorie/genre
// n'a pas de jeton court dédié).
function refine_filter_categories(array $data): array {
    $cats = [
        'refine_categories' => [
            'label' => 'Catégories',
            'multi' => true,
            'items' => [],
        ],
        'refine_genres' => [
            'label' => 'Genres',
            'multi' => true,
            'items' => [],
        ],
    ];
    foreach (refine_filter_values($data, 'categories') as $v) {
        $cats['refine_categories']['items'][$v] = $v;
    }
    foreach (refine_filter_values($data, 'genres') as $v) {
        $cats['refine_genres']['items'][$v] = $v;
    }
    return $cats;
}

// Applique le filtre « Affiner » à un tableau de séries.
// $raw_categories / $raw_genres : "a,b,c" (valeurs cochées) ; vide => aucun
// filtrage sur ce champ. Les deux champs se combinent toujours en ET l'un
// avec l'autre (chacun en OU en interne, sauf si $mode = 'and').
function apply_refine_filter(array $data, string $raw_categories, string $raw_genres, string $mode): array {
    $cat_tokens = array_filter(array_map('trim', explode(',', $raw_categories)), 'strlen');
    $genre_tokens = array_filter(array_map('trim', explode(',', $raw_genres)), 'strlen');
    if (empty($cat_tokens) && empty($genre_tokens)) return $data;

    $mode = ($mode === 'or') ? 'or' : 'and';

    $matches_list = function ($series_values, $tokens, $op) {
        if (empty($tokens)) return null; // champ non filtré : ignoré du calcul
        $series_values = array_map('trim', (array)$series_values);
        $hits = 0;
        foreach ($tokens as $t) {
            if (in_array($t, $series_values, true)) $hits++;
        }
        return $op === 'and' ? ($hits === count($tokens)) : ($hits > 0);
    };

    return array_filter($data, function ($series) use ($cat_tokens, $genre_tokens, $mode, $matches_list) {
        $results = [];
        $c = $matches_list($series['categories'] ?? [], $cat_tokens, $mode);
        if ($c !== null) $results[] = $c;
        $g = $matches_list($series['genres'] ?? [], $genre_tokens, $mode);
        if ($g !== null) $results[] = $g;
        if (empty($results)) return true;
        if ($mode === 'and') return !in_array(false, $results, true);
        return in_array(true, $results, true);
    });
}

// Rendu du widget « Affiner » (même structure visuelle que « Statuts »).
// $raw_categories / $raw_genres : valeurs cochées ("a,b,c") ; vide => tout
// décoché. $pool : séries (déjà filtrées sur le type affiché) sur lesquelles
// calculer les options disponibles (catégories/genres réellement présents) —
// sert uniquement à énumérer ces valeurs, jamais à un filtrage réel (fait par
// apply_refine_filter()).
function render_refine_filter($raw_categories, $raw_genres, $mode, array $pool = []) {
    $cats = refine_filter_categories($pool);

    $checked_cats   = array_fill_keys(array_filter(array_map('trim', explode(',', (string)$raw_categories)), 'strlen'), true);
    $checked_genres = array_fill_keys(array_filter(array_map('trim', explode(',', (string)$raw_genres)), 'strlen'), true);
    $mode = ($mode === 'or') ? 'or' : 'and';
    ?>
    <div class="status-filter refine-filter" id="refine-filter" data-status-mode="<?= $mode ?>">
        <button type="button" class="status-filter-toggle" aria-expanded="false">
            <span class="status-filter-label">Affiner</span>
            <span class="status-filter-caret">▾</span>
        </button>
        <div class="status-filter-panel" hidden>
            <div class="status-filter-head">
                <label class="status-filter-mode-switch" title="OU : au moins une valeur. ET : toutes les valeurs.">
                    <span>Combinaison :</span>
                    <select class="status-filter-mode">
                        <option value="or"  <?= $mode === 'or'  ? 'selected' : '' ?>>OU (au moins une)</option>
                        <option value="and" <?= $mode === 'and' ? 'selected' : '' ?>>ET (toutes)</option>
                    </select>
                </label>
                <button type="button" class="status-filter-toggle-all" data-state="check">Tout cocher</button>
            </div>
            <div class="status-filter-groups">
                <?php foreach ($cats as $cat => $def): ?>
                    <fieldset class="status-filter-group" data-cat="<?= htmlspecialchars($cat) ?>" data-multi="1">
                        <legend><?= htmlspecialchars($def['label']) ?></legend>
                        <?php if (empty($def['items'])): ?>
                            <p class="hint">Aucune valeur disponible.</p>
                        <?php endif; ?>
                        <?php foreach ($def['items'] as $value => $text):
                            $checked_map = ($cat === 'refine_categories') ? $checked_cats : $checked_genres;
                            $is_checked = !empty($checked_map[$value]); ?>
                            <label class="status-filter-option">
                                <input type="checkbox" class="status-filter-cb" data-refine-field="<?= $cat === 'refine_categories' ? 'categories' : 'genres' ?>" value="<?= htmlspecialchars($value) ?>" <?= $is_checked ? 'checked' : '' ?>>
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
