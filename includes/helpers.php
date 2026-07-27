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

// ── Statut d'avancement d'une série animée ───────────────────────────────────
// Statut de VISIONNAGE, calculé depuis les épisodes. Renvoie 'abandoned',
// 'completed', 'in_progress' ou 'not_started' : exactement les mêmes clés que le
// statut de lecture des mangas, pour que badges et filtres s'appliquent sans
// distinction de type.
//
// Vit ici, et non dans fonctions/anime.php, parce qu'il ne s'agit que de LIRE
// une série : la page publique et le filtre de statuts en ont besoin sans rien
// charger de la mécanique d'écriture des animés.
function anime_watching_status(array $series): string {
    if (!empty($series['watching_abandoned'])) {
        return 'abandoned';
    }
    $episodes = $series['volumes'] ?? [];
    $total    = count($episodes);
    if ($total === 0) {
        return 'not_started';
    }

    $done     = type_vocab('anime', 'done');   // « terminé »
    $watched  = 0;
    $has_last = false;
    foreach ($episodes as $episode) {
        if (($episode['status'] ?? '') === $done) $watched++;
        if (!empty($episode['last']))             $has_last = true;
    }
    if ($watched === 0)                   return 'not_started';
    if ($watched === $total && $has_last) return 'completed';
    return 'in_progress';
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

// Registre allégé destiné au JavaScript (window.seriesTypes) : de quoi afficher
// un badge de type, et le vocabulaire du type pour que le front n'écrive lui non
// plus aucun libellé en dur (« Épisode 3 » vient d'ici, pas d'une chaîne perdue
// au milieu d'un fichier .js).
function series_types_for_js(): array {
    $out = [];
    foreach (series_type_registry() as $key => $def) {
        $out[$key] = [
            'label'  => $def['label'],
            'plural' => $def['label_plural'],
            'color'  => $def['color'],
            'vocab'  => $def['vocab'],
        ];
    }
    return $out;
}

// ──────────────────────────────────────────────────────────────────────────────
// Vignette d'une série — cascade d'affichage
// ──────────────────────────────────────────────────────────────────────────────
// Ordre de priorité, sans exception :
//   1. vignette personnalisée (`image`), téléversée par l'utilisateur ;
//   2. vignette Anilist (`anilist_image`), téléchargée à l'import d'un animé ;
//   3. vignette par défaut du site.
//
// Conséquence directe, et voulue : supprimer la vignette personnalisée d'un
// animé fait automatiquement réapparaître celle d'Anilist. Le fichier n'ayant
// jamais été effacé, il n'y a rien à retélécharger.
//
// Le fichier est vérifié à chaque niveau : une vignette dont le fichier a
// disparu ne doit pas produire une image cassée, mais laisser la main au niveau
// suivant de la cascade.
function series_thumbnail($series, string $default = 'assets/img/logo.png'): string {
    foreach (['image', 'anilist_image'] as $field) {
        $path = trim((string)($series[$field] ?? ''));
        if ($path !== '' && file_exists($path)) {
            return $path;
        }
    }
    return $default;
}

// ──────────────────────────────────────────────────────────────────────────────
// Titres alternatifs (animés)
// ──────────────────────────────────────────────────────────────────────────────
// Liste des titres proposés au sélecteur d'édition : romaji, anglais, natif et
// synonymes, tels que récupérés sur Anilist. Le titre courant y est réinjecté en
// tête s'il n'y figure pas — cas d'un titre choisi puis retiré d'Anilist, qui ne
// doit jamais disparaître du sélecteur sous les yeux de l'utilisateur.
function series_alt_titles($series): array {
    $titles = [];
    $current = trim((string)($series['name'] ?? ''));
    if ($current !== '') $titles[] = $current;

    $raw = $series['alt_titles'] ?? [];
    if (is_string($raw)) {
        $raw = function_exists('decode_alt_titles') ? decode_alt_titles($raw) : [];
    }
    foreach ((array)$raw as $title) {
        $title = trim((string)$title);
        if ($title !== '' && !in_array($title, $titles, true)) {
            $titles[] = $title;
        }
    }
    return $titles;
}

// Studios d'une série animée, sous forme de texte affichable.
function series_studios_text($series): string {
    $studios = $series['studios'] ?? [];
    if (is_string($studios)) {
        $studios = $studios !== '' ? explode(',', $studios) : [];
    }
    $studios = array_values(array_filter(array_map('trim', (array)$studios), fn($s) => $s !== ''));
    return implode(', ', $studios);
}

// ──────────────────────────────────────────────────────────────────────────────
// Éditions physiques (animés)
// ──────────────────────────────────────────────────────────────────────────────
// Un commentaire libre = une édition (« Coffret Blu-ray collector », « DVD
// import japonais »…). Plafonds : 5 éditions par série, 100 caractères chacune.
function series_editions_max(): int {
    return 5;
}

function series_edition_comment_max(): int {
    return 100;
}

// Normalise une liste de commentaires : suppression des vides, découpe à
// 100 caractères, plafond à 5 entrées. Renvoie une liste de chaînes.
function sanitize_edition_comments($comments): array {
    $out = [];
    foreach ((array)$comments as $comment) {
        if (is_array($comment)) {
            $comment = $comment['comment'] ?? '';
        }
        $comment = trim(preg_replace('/\s+/u', ' ', (string)$comment));
        if ($comment === '') continue;
        $out[] = mb_substr($comment, 0, series_edition_comment_max());
        if (count($out) >= series_editions_max()) break;
    }
    return $out;
}

// ──────────────────────────────────────────────────────────────────────────────
// Décoration d'une série pour l'affichage (JSON transmis au front)
// ──────────────────────────────────────────────────────────────────────────────
// Ajoute les champs que le navigateur ne doit pas avoir à calculer : vignette
// déjà arbitrée par la cascade, studios en texte, libellé du format, éditions
// aplaties. Le front affiche, il n'arbitre pas.
//
// Ne modifie aucun champ existant : c'est un enrichissement, jamais une
// réécriture — le tableau reste utilisable tel quel par le reste du site.
function decorate_series_for_display(array $series): array {
    $series['thumbnail']    = series_thumbnail($series);
    $series['custom_image'] = $series['image'] ?? '';
    $series['studios_text'] = series_studios_text($series);
    $series['format_label'] = (is_anime($series) && function_exists('anilist_format_label'))
        ? anilist_format_label($series['anime_format'] ?? '')
        : '';
    $series['editions']     = series_edition_comments($series);
    $series['alt_titles']   = series_alt_titles($series);
    return $series;
}

// Liste des commentaires d'éditions d'une série, sous forme de simples chaînes.
function series_edition_comments($series): array {
    $out = [];
    foreach ((array)($series['editions'] ?? []) as $edition) {
        $comment = is_array($edition) ? ($edition['comment'] ?? '') : $edition;
        $comment = trim((string)$comment);
        if ($comment !== '') $out[] = $comment;
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