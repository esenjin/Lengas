<?php
// ──────────────────────────────────────────────────────────────────────────────
// REGISTRE CENTRAL DES TYPES DE SÉRIES
// ──────────────────────────────────────────────────────────────────────────────
// Chaque série porte un type (colonne `type` de la table `series`). Ce registre
// est l'unique endroit où un type est décrit : libellé, couleur, icône et
// vocabulaire associé (tome/épisode, lecture/visionnage, publication/diffusion).
//
// AJOUTER UN TYPE = AJOUTER UNE ENTRÉE ICI, et rien d'autre. Aucun libellé, aucune
// couleur et aucun mot de vocabulaire ne doit être écrit en dur ailleurs dans le
// site : tout se lit via type_label(), type_color(), type_icon() et type_vocab().
//
// Clés de vocabulaire disponibles :
//   item / items / item_cap / items_cap  → tome(s) ou épisode(s)
//   activity / activity_cap              → lecture / visionnage
//   activity_verb                        → lire / voir
//   done_short / done_long               → lu / lue — vu / vue
//   todo / doing / done                  → statuts littéraux d'un tome ou épisode
//   release / release_cap                → publication / diffusion
//   status_label                         → « Statut de publication|diffusion »
//   progress_label                       → « Statut de lecture|visionnage »
//   backlog_label                        → « Séries à lire » / « À finaliser »
//   collection                           → Mangathèque / Animethèque
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('series_type_registry')) {

function series_type_registry(): array {
    static $registry = null;
    if ($registry !== null) {
        return $registry;
    }

    $registry = [
        'manga' => [
            'label'        => 'Manga',
            'label_plural' => 'Mangas',
            'color'        => '#c94e93',
            'icon'         => 'mdi:bookshelf',
            'vocab'        => [
                'item'           => 'tome',
                'items'          => 'tomes',
                'item_cap'       => 'Tome',
                'items_cap'      => 'Tomes',
                'activity'       => 'lecture',
                'activity_cap'   => 'Lecture',
                'activity_verb'  => 'lire',
                'done_short'     => 'lu',
                'done_long'      => 'lue',
                'todo'           => 'à lire',
                'doing'          => 'en cours',
                'done'           => 'terminé',
                'release'        => 'publication',
                'release_cap'    => 'Publication',
                'status_label'   => 'Statut de publication',
                'progress_label' => 'Statut de lecture',
                'backlog_label'  => 'Séries à lire',
                'collection'     => 'Mangathèque',
            ],
        ],
        'anime' => [
            'label'        => 'Animé',
            'label_plural' => 'Animés',
            'color'        => '#38bdf8',
            'icon'         => 'mdi:television-classic',
            'vocab'        => [
                'item'           => 'épisode',
                'items'          => 'épisodes',
                'item_cap'       => 'Épisode',
                'items_cap'      => 'Épisodes',
                'activity'       => 'visionnage',
                'activity_cap'   => 'Visionnage',
                'activity_verb'  => 'voir',
                'done_short'     => 'vu',
                'done_long'      => 'vue',
                'todo'           => 'à voir',
                'doing'          => 'en cours',
                'done'           => 'terminé',
                'release'        => 'diffusion',
                'release_cap'    => 'Diffusion',
                'status_label'   => 'Statut de diffusion',
                'progress_label' => 'Statut de visionnage',
                'backlog_label'  => 'À finaliser',
                'collection'     => 'Animethèque',
            ],
        ],
    ];

    return $registry;
}

// Type retenu quand aucun n'est précisé (rétro-compatibilité : tout l'existant
// est du manga).
function default_series_type(): string {
    return 'manga';
}

// Liste des clés de types connues.
function series_type_keys(): array {
    return array_keys(series_type_registry());
}

// Le type existe-t-il dans le registre ?
function series_type_exists($type): bool {
    return is_string($type) && isset(series_type_registry()[$type]);
}

// Normalise une valeur de type : renvoie le type de repli si elle est inconnue.
function sanitize_series_type($type, ?string $fallback = null): string {
    $type = trim((string)$type);
    if (series_type_exists($type)) {
        return $type;
    }
    $fallback = $fallback ?? default_series_type();
    return series_type_exists($fallback) ? $fallback : default_series_type();
}

// Type d'une série. Accepte une série (tableau) ou directement une clé de type.
function series_type($series): string {
    if (is_array($series)) {
        return sanitize_series_type($series['type'] ?? '');
    }
    return sanitize_series_type($series);
}

// La série (ou le type) relève-t-elle de l'Animethèque ?
function is_anime($series): bool {
    return series_type($series) === 'anime';
}

// Libellé affichable d'un type. $plural = true pour la forme au pluriel.
function type_label($type, bool $plural = false): string {
    $def = series_type_registry()[series_type($type)];
    return $plural ? $def['label_plural'] : $def['label'];
}

// Couleur hexadécimale associée à un type (badges, titres, sections du menu).
function type_color($type): string {
    return series_type_registry()[series_type($type)]['color'];
}

// Nom d'icône Iconify associé à un type (ex. « mdi:bookshelf »).
function type_icon($type): string {
    return series_type_registry()[series_type($type)]['icon'];
}

// Vocabulaire d'un type. Sans $key, renvoie tout le tableau ; avec $key, la
// seule entrée demandée ('' si la clé est inconnue).
function type_vocab($type, ?string $key = null) {
    $vocab = series_type_registry()[series_type($type)]['vocab'];
    if ($key === null) {
        return $vocab;
    }
    return $vocab[$key] ?? '';
}

// ── Filtrage par type ─────────────────────────────────────────────────────────
// ⚠️ À N'UTILISER QUE POUR L'AFFICHAGE (liste, recherche, tri, pagination,
// compteurs) ou dans une fonction d'analyse en lecture seule. Le tableau rendu
// est INCOMPLET : ne jamais le passer à save_data(), qui supprime de la base
// toute série absente du tableau reçu. Voir l'avertissement de save_data().
function series_of_type(array $data, $type): array {
    $type = sanitize_series_type($type);
    return array_values(array_filter($data, function ($series) use ($type) {
        return series_type($series) === $type;
    }));
}

// Décompte des séries par type, tous les types connus étant représentés
// (valeur 0 si aucune série). Pratique pour les compteurs et les onglets.
function series_type_counts(array $data): array {
    $counts = array_fill_keys(series_type_keys(), 0);
    foreach ($data as $series) {
        $counts[series_type($series)]++;
    }
    return $counts;
}

// Registre allégé destiné au JavaScript (window.seriesTypes) : uniquement ce
// dont le front a besoin pour afficher un badge de type.
function series_types_for_js(): array {
    $out = [];
    foreach (series_type_registry() as $key => $def) {
        $out[$key] = [
            'label'  => $def['label'],
            'plural' => $def['label_plural'],
            'color'  => $def['color'],
        ];
    }
    return $out;
}

} // end function_exists guard

// ──────────────────────────────────────────────────────────────────────────────
// Notation subjective des séries (facultative)
// Valeurs stockées : 'apprecie', 'mitige', 'deteste' (ou '' = pas de note).
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('rating_definitions')) {
    function rating_definitions() {
        return [
            'apprecie' => ['emoji' => '☺️', 'label' => "J'ai apprécié"],
            'mitige'   => ['emoji' => '😑', 'label' => 'Mi-figue mi-raisin'],
            'deteste'  => ['emoji' => '😠', 'label' => "Je n'ai pas aimé"],
        ];
    }

    // Normalise une valeur de note ; renvoie '' si invalide.
    function sanitize_rating($value) {
        $value = trim((string)$value);
        return array_key_exists($value, rating_definitions()) ? $value : '';
    }
}

// Fonction pour générer un UUID unique
function generate_uuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Fonction pour vérifier si une image existe
function check_image_exists($image_path) {
    return !empty($image_path) && file_exists($image_path);
}

// Fonction pour obtenir les valeurs uniques d'un champ spécifique
function get_unique_values($data, $field) {
    $values = [];
    foreach ($data as $series) {
        if (isset($series[$field])) {
            if (is_array($series[$field])) {
                foreach ($series[$field] as $value) {
                    $value = trim($value);
                    if (!empty($value) && !in_array($value, $values, true)) {
                        $values[] = $value;
                    }
                }
            } else {
                $value = trim($series[$field]);
                if (!empty($value) && !in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
        }
    }
    return $values;
}

// ── Fonctions également définies localement par index.php ────────────────────
// index.php possède ses propres implémentations (recherche publique). Les
// déclarations de haut niveau y étant compilées avant l'exécution du require,
// ces gardes laissent la version de la page en place et évitent la fatale.
if (!function_exists('series_latest_date')) {

// Récupère la date la plus récente (added_at ou read_at) parmi les tomes d'une série
function series_latest_date($series, $field) {
    $dates = [];
    foreach ($series['volumes'] ?? [] as $v) {
        if (!empty($v[$field])) {
            $dates[] = $v[$field];
        }
    }
    if (empty($dates)) {
        return '0000-00-00';
    }
    return max($dates);
}

// Fonction pour trier les séries
function sort_series(&$data, $sort_by, $sort_order) {
    usort($data, function($a, $b) use ($sort_by, $sort_order) {
        if ($sort_by === 'added_at' || $sort_by === 'read_at') {
            $a_val = series_latest_date($a, $sort_by);
            $b_val = series_latest_date($b, $sort_by);
            return $sort_order === 'asc' ? strcmp($a_val, $b_val) : strcmp($b_val, $a_val);
        }
        if ($sort_by === 'volumes') {
            return $sort_order === 'asc'
                ? count($a['volumes']) - count($b['volumes'])
                : count($b['volumes']) - count($a['volumes']);
        } elseif ($sort_by === 'categories') {
            $a_categories = implode(', ', $a['categories'] ?? []);
            $b_categories = implode(', ', $b['categories'] ?? []);
            return $sort_order === 'asc'
                ? strcasecmp($a_categories, $b_categories)
                : strcasecmp($b_categories, $a_categories);
        } else {
            return $sort_order === 'asc'
                ? strcasecmp($a[$sort_by], $b[$sort_by])
                : strcasecmp($b[$sort_by], $a[$sort_by]);
        }
    });
}

} // end function_exists guard (series_latest_date, sort_series)

// Fonction pour trier les tomes par numéro
function sort_volumes(&$volumes) {
    usort($volumes, function($a, $b) {
        return $a['number'] - $b['number'];
    });
}

// Fonction pour trouver une série par son ID
function find_series_by_id($data, $series_id) {
    foreach ($data as $key => $series) {
        if ($series['id'] === $series_id) {
            return [
                'key' => $key,
                'index' => array_search($series, $data),
                'data' => $series
            ];
        }
    }
    return null;
}

// Fonction pour normaliser une chaîne de caractères pour la recherche (insensible aux accents et à la casse).
// (garde function_exists : index.php fournit sa propre version)
if (!function_exists('normalize_string')) {
function normalize_string($string) {
    // Remplace les caractères accentués par leurs équivalents non accentués
    $table = [
        'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'AE',
        'Ç'=>'C', 'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I',
        'Î'=>'I', 'Ï'=>'I', 'Ð'=>'D', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O',
        'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U',
        'Ý'=>'Y', 'ß'=>'s', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a',
        'å'=>'a', 'æ'=>'ae', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e',
        'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'d', 'ñ'=>'n', 'ò'=>'o',
        'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u',
        'û'=>'u', 'ü'=>'u', 'ý'=>'y', 'ÿ'=>'y', 'Ŕ'=>'R', 'ŕ'=>'r',
    ];
    $string = strtr($string, $table);
    // Convertit en minuscules et supprime les caractères non alphanumériques
    $string = mb_strtolower($string, 'UTF-8');
    $string = preg_replace('/[^a-z0-9]/', '', $string);
    return $string;
}

} // end function_exists guard (normalize_string)

// Statut de la série
function get_status_icon($status) {
    switch ($status) {
        case 'terminee': return '✅';
        case 'enpause': return '⏳';
        case 'abandonnee': return '⛔';
        default: return '▶️';
    }
}

// Récupère la dernière version publiée sur Gitea (null si indisponible).
if (!function_exists('get_latest_version_from_gitea')) {
    function get_latest_version_from_gitea() {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init('https://git.crystalyx.net/api/v1/repos/Esenjin_Asakha/Lengas/releases/latest');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Lengas-Version-Checker');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response) {
            $decoded = json_decode($response, true);
            if (isset($decoded['tag_name'])) return ltrim($decoded['tag_name'], 'v');
        }
        return null;
    }
}