<?php
// ──────────────────────────────────────────────────────────────────────────────
// includes/opengraph.php — Balises OpenGraph communes à toutes les pages
//
// Point d'entrée unique pour l'aperçu généré lorsqu'un lien du site est
// partagé (Discord, Twitter/X, messageries, etc.). Reprend le même principe
// que includes/themes.php::theme_link_tag() : une fonction PHP qui construit
// le HTML, appelée depuis le head de chaque page.
//
// ── Pages publiques vs pages d'administration ─────────────────────────────
// Seules les pages réellement publiques (accueil, statistiques, historique)
// génèrent un aperçu qui leur est propre. Toutes les autres pages — connexion
// et tout ce qui vit derrière l'authentification admin (admin.php et les
// pages/ : outils, options, profil, critiques, licences, prêts, liste
// d'envies…) sont, par nature, sans intérêt pour un visiteur extérieur et ne
// doivent surtout pas laisser fuiter leur contenu dans un aperçu de lien.
// Pour ces pages, l'aperçu pointe systématiquement vers l'accueil du site
// (og:url ET les infos affichées — titre, description, vignette — sont celles
// de l'accueil, jamais celles de la page admin elle-même) : quiconque partage
// par erreur un lien admin obtient un aperçu propre et générique du site.
//
// Utilisation, dans le head d'une page :
//
//   Page publique (index/stats/historique), appel avec 'public' => true et
//   les infos de la page (title, description, data = collection déjà
//   chargée par load_data()).
//
//   Page d'administration (tout le reste), simple appel opengraph_tags($options)
//   sans autre paramètre : l'aperçu de l'accueil est utilisé tel quel.
//
// ATTENTION : ce fichier ne doit contenir aucune séquence "fermeture de balise
// PHP" (point d'interrogation suivi d'un chevron fermant) dans ses
// commentaires, pas même à l'intérieur d'un bloc /* */ ou d'un //. Le
// parseur PHP repère cette séquence indépendamment du fait qu'elle soit dans
// un commentaire ou non, et bascule alors en sortie HTML brute au milieu du
// fichier : la fonction opengraph_tags() ne serait alors plus jamais
// déclarée, et chaque page qui l'appelle plante avec une erreur fatale.
// ──────────────────────────────────────────────────────────────────────────────

// Dossier des images (relatif à la racine du site — les pages sont à la racine)
if (!defined('OG_DEFAULT_IMAGE')) {
    define('OG_DEFAULT_IMAGE', 'assets/img/logo.png');
}

/**
 * URL absolue d'une ressource du site (image, favicon…), à partir d'un chemin
 * relatif à la racine (ex. "assets/img/logo.png"). Nécessaire pour og:image :
 * un chemin relatif n'est pas fiable une fois sorti du contexte du navigateur
 * (robots d'aperçu Discord, Twitter/X, messageries…).
 */
function og_absolute_url(string $root_relative_path): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Racine du site = dossier contenant config.php/index.php, déduit de
    // PHP_SELF en retirant tout ce qui suit (et y compris) le premier "/pages/".
    $self = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '/');
    $pos  = strpos($self, '/pages/');
    $base_path = $pos !== false ? substr($self, 0, $pos) : rtrim(dirname($self), '/');
    if ($base_path === '' || $base_path === '.') {
        $base_path = '';
    }
    return $scheme . '://' . $host . $base_path . '/' . ltrim($root_relative_path, '/');
}

/**
 * URL absolue de la page actuellement affichée (og:url), construite
 * directement depuis HTTP_HOST + REQUEST_URI — contrairement à
 * og_absolute_url(), REQUEST_URI est déjà un chemin complet depuis la racine
 * du serveur web (avec sa query string éventuelle), il ne doit pas être
 * recombiné avec la racine du site.
 */
function og_current_page_url(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    return $scheme . '://' . $host . $uri;
}

/**
 * Formate un nombre avec un espace insécable comme séparateur de milliers
 * (ex. 1 234), plutôt que l'espace normal renvoyé par number_format() : dans
 * une balise <meta content="...">, un espace normal peut être recomposé
 * différemment par le lecteur (retour à la ligne au milieu du nombre) selon
 * l'endroit où l'aperçu est affiché.
 */
function og_format_number(int $value): string {
    return number_format($value, 0, ',', "\u{00A0}");
}

/**
 * Compte les séries et les tomes/épisodes d'une collection déjà chargée
 * (format load_data()), en respectant le mode privé : une collection en mode
 * privé ne doit rien laisser fuiter de sa taille dans l'aperçu public, donc
 * ses compteurs sont simplement omis (pas mis à zéro, omis).
 *
 * Retourne un tableau ['manga' => ['series' => int, 'items' => int], 'anime' => [...]],
 * ne contenant que les types non masqués.
 */
function og_collection_counts(array $data, array $options): array {
    $counts = [];
    foreach (series_type_keys() as $type) {
        if (is_private_mode($options, $type)) {
            continue;
        }
        $series_count = 0;
        $items_count  = 0;
        foreach ($data as $s) {
            if (series_type($s) !== $type) continue;
            // Respecte aussi le masquage des séries matures : ces séries ne
            // doivent pas non plus gonfler un compteur exposé publiquement.
            if (is_hide_mature($options, $type) && !empty($s['mature'])) continue;
            $series_count++;
            $items_count += count($s['volumes'] ?? []);
        }
        $counts[$type] = ['series' => $series_count, 'items' => $items_count];
    }
    return $counts;
}

/**
 * Construit la phrase de synthèse de la collection (ex. "1 234 tomes
 * (87 séries) - 456 épisodes (32 séries)", avec des espaces insécables), à
 * ajouter à la description OpenGraph. Vide si les deux collections sont
 * privées ou vides (rien à afficher).
 */
function og_collection_summary(array $counts): string {
    $parts = [];
    foreach ($counts as $type => $c) {
        if ($c['series'] === 0) continue;
        $registry = series_type_registry();
        $vocab    = $registry[$type]['vocab'] ?? [];
        $items_label  = $c['items'] > 1 ? ($vocab['items'] ?? 'tomes') : ($vocab['item'] ?? 'tome');
        $series_label = $c['series'] > 1 ? ($registry[$type]['label_plural'] ?? 'séries') : 'série';
        $parts[] = og_format_number($c['items']) . ' ' . $items_label
            . ' (' . og_format_number($c['series']) . ' ' . $series_label . ')';
    }
    if (empty($parts)) {
        return '';
    }
    return implode(' - ', $parts);
}

/**
 * Construit et retourne le bloc de balises meta OpenGraph/Twitter Card à
 * insérer dans le head de n'importe quelle page du site.
 *
 * $options doit être le résultat de load_options().
 *
 * $args (tous facultatifs) :
 *   - 'public'          bool, false par défaut. Mettre à true UNIQUEMENT sur
 *                        les pages réellement publiques (index.php, stats.php,
 *                        historique.php) pour générer un aperçu propre à la
 *                        page. Sur toute autre page (connexion,
 *                        administration), laisser à false : l'aperçu de
 *                        l'accueil est utilisé tel quel, les autres
 *                        paramètres ci-dessous sont alors ignorés.
 *   - 'title'            titre de la page publique (repli sur site_name)
 *   - 'description'      description de la page publique (repli sur
 *                        site_description, à laquelle la synthèse de
 *                        collection est ajoutée automatiquement sauf si
 *                        include_counts vaut false)
 *   - 'image'            chemin relatif à la racine d'une vignette spécifique
 *                        à la page publique — repli sur le logo du site
 *   - 'data'             collection déjà chargée (load_data()) ; si absente,
 *                        elle est chargée ici pour calculer les compteurs
 *   - 'include_counts'   bool, true par défaut sur les pages publiques —
 *                        mettre à false pour une page publique où les
 *                        compteurs de collection n'ont pas de sens
 *   - 'type'             type OpenGraph (og:type), "website" par défaut
 */
function opengraph_tags(array $options, array $args = []): string {
    $is_public = !empty($args['public']);

    $site_name = trim($options['site_name'] ?? '') ?: 'Lengas';

    if ($is_public) {
        $title       = trim($args['title'] ?? '') ?: $site_name;
        $description = trim($args['description'] ?? '') ?: trim($options['site_description'] ?? '');
        $include_counts = $args['include_counts'] ?? true;

        if ($include_counts) {
            $data    = $args['data'] ?? (function_exists('load_data') ? load_data() : []);
            $counts  = og_collection_counts($data, $options);
            $summary = og_collection_summary($counts);
            if ($summary !== '') {
                $description = $description !== '' ? ($description . ' - ' . $summary) : $summary;
            }
        }

        $image_path = $args['image'] ?? OG_DEFAULT_IMAGE;
        $page_url   = og_current_page_url();
    } else {
        // Page non publique (connexion, administration…) : l'aperçu partagé
        // est TOUJOURS celui de l'accueil, quelle que soit la page réellement
        // ouverte — jamais le contenu (potentiellement sensible) de la page
        // admin elle-même.
        $title       = trim($options['index_page_title'] ?? '') ?: $site_name;
        $description = trim($options['site_description'] ?? '');
        $data    = function_exists('load_data') ? load_data() : [];
        $counts  = og_collection_counts($data, $options);
        $summary = og_collection_summary($counts);
        if ($summary !== '') {
            $description = $description !== '' ? ($description . ' - ' . $summary) : $summary;
        }
        $image_path = OG_DEFAULT_IMAGE;
        $page_url   = og_absolute_url('index.php');
    }

    // Une image déjà absolue (ex. vignette Anilist re-servie depuis une URL
    // externe, cas rarissime) n'est jamais réécrite ; sinon on la résout à
    // la racine du site puis on la rend absolue pour les robots d'aperçu.
    $image_url = preg_match('#^https?://#i', $image_path)
        ? $image_path
        : og_absolute_url($image_path);

    $html  = '';
    $html .= '<meta property="og:site_name" content="' . htmlspecialchars($site_name, ENT_QUOTES) . '">' . "\n    ";
    $html .= '<meta property="og:type" content="' . htmlspecialchars($args['type'] ?? 'website', ENT_QUOTES) . '">' . "\n    ";
    $html .= '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES) . '">' . "\n    ";
    if ($description !== '') {
        $html .= '<meta property="og:description" content="' . htmlspecialchars($description, ENT_QUOTES) . '">' . "\n    ";
    }
    $html .= '<meta property="og:url" content="' . htmlspecialchars($page_url, ENT_QUOTES) . '">' . "\n    ";
    $html .= '<meta property="og:image" content="' . htmlspecialchars($image_url, ENT_QUOTES) . '">' . "\n    ";

    // Twitter Card : reprend les mêmes valeurs, format "summary" avec grande
    // image (rendu correct pour un logo/vignette carré ou proche du carré).
    $html .= '<meta name="twitter:card" content="summary_large_image">' . "\n    ";
    $html .= '<meta name="twitter:title" content="' . htmlspecialchars($title, ENT_QUOTES) . '">' . "\n    ";
    if ($description !== '') {
        $html .= '<meta name="twitter:description" content="' . htmlspecialchars($description, ENT_QUOTES) . '">' . "\n    ";
    }
    $html .= '<meta name="twitter:image" content="' . htmlspecialchars($image_url, ENT_QUOTES) . '">';

    return $html;
}