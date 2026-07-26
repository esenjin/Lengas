<?php
// ──────────────────────────────────────────────────────────────────────────────
// Gestion des thèmes
//
// Un thème est un fichier CSS "assets/css/_variables-<clé>.css" qui redéfinit
// les variables :root. Le thème sombre historique est le fichier de base
// "assets/css/_variables.css" (clé spéciale "dark").
//
// Les thèmes "de base" (fournis avec Lengas) sont : dark et light.
// Tout autre fichier _variables-xxx.css déposé par l'utilisateur est considéré
// comme un thème "personnalisé".
// ──────────────────────────────────────────────────────────────────────────────

// Dossier des CSS (relatif à la racine du site — les pages sont à la racine)
if (!defined('THEMES_CSS_DIR')) {
    define('THEMES_CSS_DIR', 'assets/css');
}

// Clés des thèmes fournis d'origine (non "personnalisés")
function theme_base_keys(): array {
    return ['dark', 'light'];
}

// Libellés lisibles pour les thèmes de base
function theme_base_labels(): array {
    return [
        'dark'  => 'Sombre',
        'light' => 'Clair',
    ];
}

// Transforme une clé de thème ("dark", "light", "perso"…) en nom de fichier CSS
function theme_key_to_file(string $key): string {
    if ($key === 'dark' || $key === '') {
        return '_variables.css';
    }
    return '_variables-' . $key . '.css';
}

// Transforme un nom de fichier ("_variables.css", "_variables-perso.css") en clé
function theme_file_to_key(string $file): string {
    if ($file === '_variables.css') {
        return 'dark';
    }
    if (preg_match('/^_variables-([a-z0-9_-]+)\.css$/i', $file, $m)) {
        return strtolower($m[1]);
    }
    return '';
}

// Libellé lisible d'une clé de thème
function theme_label(string $key): string {
    $labels = theme_base_labels();
    if (isset($labels[$key])) {
        return $labels[$key];
    }
    // Thème personnalisé : on "humanise" la clé (perso-doux → Perso doux)
    $label = str_replace(['-', '_'], ' ', $key);
    return function_exists('mb_convert_case')
        ? mb_convert_case($label, MB_CASE_TITLE, 'UTF-8')
        : ucwords($label);
}

// Liste tous les thèmes disponibles.
// Retourne un tableau de ['key', 'label', 'file', 'custom' (bool)].
// Les thèmes de base d'abord, puis les personnalisés (triés).
function list_themes(): array {
    $base_keys = theme_base_keys();
    $themes    = [];
    $seen      = [];

    // 1) Thèmes de base (dans l'ordre défini), seulement s'ils existent
    foreach ($base_keys as $key) {
        $file = theme_key_to_file($key);
        if (file_exists(THEMES_CSS_DIR . '/' . $file)) {
            $themes[] = [
                'key'    => $key,
                'label'  => theme_label($key),
                'file'   => $file,
                'custom' => false,
            ];
            $seen[$key] = true;
        }
    }

    // 2) Thèmes personnalisés découverts dans le dossier CSS
    $customs = [];
    if (is_dir(THEMES_CSS_DIR)) {
        foreach (scandir(THEMES_CSS_DIR) as $f) {
            if ($f === '_variables.css') continue; // = thème sombre déjà géré
            if (preg_match('/^_variables-([a-z0-9_-]+)\.css$/i', $f)) {
                $key = theme_file_to_key($f);
                if ($key === '' || isset($seen[$key])) continue;
                $customs[] = [
                    'key'    => $key,
                    'label'  => theme_label($key),
                    'file'   => $f,
                    'custom' => true,
                ];
                $seen[$key] = true;
            }
        }
    }
    // Tri alphabétique des personnalisés par libellé
    usort($customs, fn($a, $b) => strcasecmp($a['label'], $b['label']));

    return array_merge($themes, $customs);
}

// Vérifie qu'une clé de thème correspond bien à un fichier existant
function theme_exists(string $key): bool {
    foreach (list_themes() as $t) {
        if ($t['key'] === $key) return true;
    }
    return false;
}

// Récupère la clé du thème actif (depuis les options), avec repli "dark"
function current_theme_key(?array $options = null): string {
    if ($options === null && function_exists('load_options')) {
        $options = load_options();
    }
    $key = $options['theme'] ?? 'dark';
    $key = is_string($key) && $key !== '' ? strtolower($key) : 'dark';
    return theme_exists($key) ? $key : 'dark';
}

// Chemin CSS (relatif) du thème actif — à insérer dans un <link>
// APRÈS main.css, afin de surcharger les variables :root.
// Renvoie null si le thème actif est le thème sombre par défaut
// (déjà chargé via main.css → _variables.css).
function current_theme_css_href(?array $options = null): ?string {
    $key = current_theme_key($options);
    if ($key === 'dark') {
        return null; // déjà pris en charge par main.css
    }
    return THEMES_CSS_DIR . '/' . theme_key_to_file($key);
}

// Émet la balise <link> du thème actif (avec cache-busting léger).
// À appeler dans le <head>, juste après le <link> de main.css.
function theme_link_tag(?array $options = null): string {
    $href = current_theme_css_href($options);
    if ($href === null) {
        return '';
    }
    // $href est relatif à la racine du projet (ex. "assets/css/_variables-x.css").
    // La lecture disque se fait depuis la racine (CWD rétabli par les pages),
    // mais l'URL envoyée au navigateur doit tenir compte de la profondeur de la
    // page appelante : les pages de pages/ sont un cran plus bas.
    $v = @filemtime($href) ?: time();
    $in_pages = strpos(str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? ''), '/pages/') !== false;
    $url = ($in_pages ? '../' : '') . $href;
    return '<link rel="stylesheet" id="theme-css" href="'
        . htmlspecialchars($url, ENT_QUOTES)
        . '?v=' . $v . '">';
}
