<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/integrity.php — Outil « Vérification d'intégrité du site »
//
// Compare l'instance locale au dépôt Gitea, au TAG correspondant à la version
// installée (SITE_VERSION). Pour chaque fichier versionné : présence + contenu
// (comparaison du hash git-blob). Repère aussi les fichiers présents localement
// mais absents du dépôt (« intrus »). Gère à part les fichiers facultatifs
// (Vestikan / Babengas : absence non bloquante) et les fichiers interdits
// (à supprimer après installation).
//
// Contrôle également les permissions, les doublons, les images orphelines,
// les thèmes personnalisés, l'accès externe aux dossiers sensibles, la
// structure de la base et l'API MangaUpdates.
// Contient aussi les helpers d'informations serveur (tailles, versions…).
// ────────────────────────────────────────────────────────────────────────────

// ────────────────────────────────────────────────────────────────────────────
// Accès à l'API Gitea
// ────────────────────────────────────────────────────────────────────────────

// Déduit "owner" et "repo" depuis la constante URL_GITEA.
// Retourne ['base' => 'https://host', 'owner' => '...', 'repo' => '...'] ou null.
function gitea_repo_info(): ?array {
    if (!defined('URL_GITEA')) return null;
    $url = rtrim(URL_GITEA, '/');
    // Format attendu : https://host/owner/repo
    if (!preg_match('#^(https?://[^/]+)/([^/]+)/([^/]+)$#', $url, $m)) {
        return null;
    }
    return ['base' => $m[1], 'owner' => $m[2], 'repo' => $m[3]];
}

// Petit wrapper cURL renvoyant le corps décodé (JSON) ou null.
function gitea_api_get(string $endpoint): ?array {
    $repo = gitea_repo_info();
    if ($repo === null) return null;

    $url = $repo['base'] . '/api/v1/repos/' . $repo['owner'] . '/' . $repo['repo'] . $endpoint;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Lengas-Integrity-Checker");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response  = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $http_code < 200 || $http_code >= 300) {
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

// Liste des tags du dépôt : [ ['name' => '3.9.0', 'sha' => '...'], ... ]
function gitea_list_tags(): array {
    $tags = [];
    // On pagine par prudence (50 par page).
    for ($page = 1; $page <= 5; $page++) {
        $chunk = gitea_api_get("/tags?limit=50&page={$page}");
        if (empty($chunk)) break;
        foreach ($chunk as $t) {
            if (!isset($t['name'])) continue;
            $sha = $t['commit']['sha'] ?? ($t['id'] ?? null);
            if ($sha === null) continue;
            $tags[] = ['name' => $t['name'], 'sha' => $sha];
        }
        if (count($chunk) < 50) break;
    }
    return $tags;
}

// Normalise un nom de version/tag pour comparaison (retire un éventuel "v").
function normalize_version(string $v): string {
    return ltrim(trim($v), 'vV');
}

// Récupère l'arbre git (récursif) d'un commit/tag donné.
// Retourne un tableau associatif : chemin => sha (git blob), uniquement pour les
// blobs (fichiers). Les dossiers (type "tree") sont ignorés ici.
function gitea_get_tree(string $sha): array {
    $files = [];
    // L'API git/trees pagine (page / per_page). On boucle par prudence.
    for ($page = 1; $page <= 20; $page++) {
        $tree = gitea_api_get('/git/trees/' . rawurlencode($sha) . '?recursive=true&per_page=1000&page=' . $page);
        if ($tree === null || empty($tree['tree'])) break;
        foreach ($tree['tree'] as $entry) {
            if (($entry['type'] ?? '') !== 'blob') continue;
            if (!isset($entry['path'], $entry['sha'])) continue;
            $files[$entry['path']] = $entry['sha'];
        }
        // Gitea indique la pagination via total_count / page_size ; à défaut, on
        // s'arrête dès qu'une page renvoie moins d'entrées que la taille demandée
        // ou que l'arbre n'est plus signalé comme tronqué.
        $truncated = !empty($tree['truncated']);
        if (!$truncated && count($tree['tree']) < 1000) break;
    }
    return $files;
}

// Calcule le hash "git blob" d'un fichier local, pour le comparer au sha de
// l'arbre Gitea. Format git : sha1("blob <taille>\0<contenu>").
function git_blob_hash(string $path): ?string {
    if (!is_file($path)) return null;
    $content = @file_get_contents($path);
    if ($content === false) return null;
    $header = 'blob ' . strlen($content) . "\0";
    return sha1($header . $content);
}

// ────────────────────────────────────────────────────────────────────────────
// Classement des fichiers du dépôt
// ────────────────────────────────────────────────────────────────────────────

// Fichiers versionnés mais qui NE doivent PAS se trouver sur une instance en
// production (à supprimer après installation). On les sort de la vérification
// « fichiers requis » : ils sont traités dans la section « Fichiers interdits ».
function integrity_forbidden_files(): array {
    return ['generate_password.php', 'migrate.php', 'fix_series_status.php'];
}

// Fichiers/chemins versionnés considérés comme facultatifs : leur absence est
// signalée en orange (« Absent »), non bloquante. On teste par préfixe.
function integrity_is_optional_path(string $path): bool {
    // Tout le dossier Vestikan est facultatif.
    if (strpos($path, 'vestikan/') === 0) return true;

    // Fichiers Babengas (facultatifs).
    $babengas = [
        'includes/babengas.php',
        'fonctions/tools/babengas-helpers.php',
        'babengas-ping.php',
        'assets/js/admin/tools/babengas.js',
        'assets/css/_babengas.css',
        'assets/img/babelogo.png',
    ];
    return in_array($path, $babengas, true);
}

// Fichiers versionnés purement informatifs, non installés sur le serveur et
// dont l'absence ne doit rien signaler du tout (ni requis, ni facultatif).
function integrity_is_ignored_repo_file(string $path): bool {
    $ignored = [
        'README.md', 'LICENSE', '.gitignore', '.gitattributes',
        'CHANGELOG.md', 'CONTRIBUTING.md',
    ];
    return in_array($path, $ignored, true);
}

// Classe un chemin du dépôt dans une catégorie d'affichage lisible.
function integrity_categorize_path(string $path): string {
    if (strpos($path, 'vestikan/') === 0)                 return 'Vestikan';
    if (integrity_is_optional_path($path))                return 'Babengas';
    if (strpos($path, 'pages/') === 0)                    return 'Pages';
    if (strpos($path, 'includes/') === 0)                 return 'Includes';
    if (strpos($path, 'fonctions/tools/') === 0)          return 'Fonctions (outils)';
    if (strpos($path, 'fonctions/') === 0)                return 'Fonctions';
    if (strpos($path, 'assets/js/admin/tools/') === 0)    return 'JS (outils)';
    if (strpos($path, 'assets/js/admin/') === 0)          return 'JS (admin)';
    if (strpos($path, 'assets/js/') === 0)                return 'JS (général)';
    if (strpos($path, 'assets/css/') === 0)               return 'CSS';
    if (strpos($path, 'assets/img/') === 0)               return 'Images';
    if (strpos($path, 'assets/') === 0)                   return 'Assets';
    if (strpos($path, '/') === false)                     return 'Fichiers racines';
    return 'Autres';
}

// ────────────────────────────────────────────────────────────────────────────
// Détection des fichiers « intrus » (présents localement, absents du dépôt)
// ────────────────────────────────────────────────────────────────────────────

// Renvoie true si un chemin local doit être ignoré par la détection d'intrus
// (données runtime, config non versionnée, thèmes perso, métadonnées git…).
function integrity_is_runtime_path(string $rel, array $custom_theme_files, string $admin_avatar): bool {
    // Dossiers de données / runtime : tout leur contenu est légitime.
    $runtime_dirs = ['uploads/', 'saves/', 'bdd/', '.git/'];
    foreach ($runtime_dirs as $dir) {
        if (strpos($rel, $dir) === 0) return true;
    }

    // Config Vestikan (jamais versionnée).
    if ($rel === 'vestikan/vestikan-config.php') return true;

    // Fichiers de config Babengas éventuels (non versionnés).
    if ($rel === 'includes/babengas-config.php') return true;

    // Thèmes personnalisés déposés par l'utilisateur.
    if (in_array($rel, $custom_theme_files, true)) return true;

    // Photo de profil de l'admin (dans uploads/, déjà couvert, mais par sécurité).
    if ($admin_avatar !== '' && $rel === $admin_avatar) return true;

    // Fichiers système fréquents.
    $basename = basename($rel);
    $ignore_basenames = ['.htaccess', '.DS_Store', 'Thumbs.db', '.gitkeep', '.user.ini'];
    // .htaccess est versionné : on ne l'ignore que s'il n'est pas suivi ailleurs.
    // (Il figure normalement dans l'arbre du dépôt, donc pas signalé de toute façon.)
    if (in_array($basename, ['.DS_Store', 'Thumbs.db', '.gitkeep'], true)) return true;

    return false;
}

// Parcourt récursivement l'instance locale et renvoie la liste des fichiers
// (chemins relatifs) qui ne figurent PAS dans l'arbre du dépôt et ne sont pas
// des fichiers runtime légitimes.
function integrity_find_extra_files(array $repo_tree, array $custom_theme_files, string $admin_avatar): array {
    $extras   = [];
    $root     = realpath('.');
    if ($root === false) return $extras;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($it as $fileinfo) {
        if (!$fileinfo->isFile()) continue;
        // Chemin relatif normalisé avec des "/".
        $rel = ltrim(str_replace('\\', '/', substr($fileinfo->getPathname(), 1)), '/');
        if ($rel === '') continue;

        // Dans l'arbre du dépôt → légitime.
        if (isset($repo_tree[$rel])) continue;

        // Runtime / config / thèmes perso / avatar → légitime.
        if (integrity_is_runtime_path($rel, $custom_theme_files, $admin_avatar)) continue;

        // Fichiers interdits : déjà signalés dans leur propre section.
        if (in_array($rel, integrity_forbidden_files(), true)) continue;

        $extras[] = $rel;
    }

    sort($extras);
    return $extras;
}

// ────────────────────────────────────────────────────────────────────────────
// État des modules facultatifs (Vestikan / Babengas)
//
// Pour chaque module : présence des fichiers nécessaires, activation réelle
// (config / options), et — si activé — test de fonctionnement en interrogeant
// le service distant.
// ────────────────────────────────────────────────────────────────────────────

// Vestikan : SSO. Activé = fichier vestikan/vestikan-config.php présent et
// complet. Fonctionnel = le serveur Vestikan (base_url) répond.
function integrity_check_vestikan(): array {
    $status = [
        'installed'  => false,   // fichiers présents
        'enabled'    => false,   // configuré (config complète)
        'functional' => null,    // null = non testé ; true/false sinon
        'detail'     => '',       // message lisible
        'base_url'   => '',
    ];

    // Fichiers minimaux pour faire tourner l'intégration.
    $entry = 'vestikan/vestikan.php';
    $sdk   = 'vestikan/vestikan-sdk.php';
    $status['installed'] = (is_file($entry) && is_file($sdk));

    if (!$status['installed']) {
        $status['detail'] = 'Fichiers Vestikan absents : SSO non installé (connexion par mot de passe uniquement).';
        return $status;
    }

    // On inclut le point d'entrée de façon défensive pour disposer de
    // vestikan_enabled() / vestikan_config() sans casser la page s'il manque.
    if (!function_exists('vestikan_enabled')) {
        // Le require peut échouer si un fichier est corrompu : on protège.
        try {
            require_once $entry;
        } catch (\Throwable $e) {
            $status['detail'] = 'Impossible de charger Vestikan : ' . $e->getMessage();
            return $status;
        }
    }

    if (!function_exists('vestikan_enabled') || !function_exists('vestikan_config')) {
        $status['detail'] = 'Fichiers Vestikan présents mais illisibles (fonctions manquantes).';
        return $status;
    }

    $status['enabled'] = vestikan_enabled();
    if (!$status['enabled']) {
        $status['detail'] = 'Fichiers présents mais configuration absente ou incomplète : SSO désactivé.';
        return $status;
    }

    $cfg = vestikan_config();
    $status['base_url'] = (string)($cfg['base_url'] ?? '');

    // Test de fonctionnement : le serveur Vestikan répond-il ?
    // On sonde base_url/authorize sans suivre la redirection : toute réponse
    // HTTP du serveur (y compris 302/400) prouve qu'il est joignable.
    $probe = integrity_http_probe(rtrim($status['base_url'], '/') . '/authorize');
    if ($probe['reachable']) {
        $status['functional'] = true;
        $status['detail']     = 'Configuré et serveur Vestikan joignable (HTTP ' . $probe['http'] . ').';
    } else {
        $status['functional'] = false;
        $status['detail']     = 'Configuré mais serveur Vestikan injoignable' .
            ($probe['error'] !== '' ? ' : ' . $probe['error'] : '.') ;
    }

    return $status;
}

// Babengas : décompte VF via Babelio. Activé = option cochée + URL + clé.
// Fonctionnel = la sonde /sante répond « ok ».
function integrity_check_babengas(): array {
    $status = [
        'installed'  => false,
        'enabled'    => false,
        'functional' => null,
        'detail'     => '',
        'version'    => '',
    ];

    $status['installed'] = is_file('includes/babengas.php');
    if (!$status['installed']) {
        $status['detail'] = 'Fichiers Babengas absents : vérification VF non installée.';
        return $status;
    }

    if (!function_exists('babengas_enabled') || !function_exists('babengas_check_service')) {
        $status['detail'] = 'Fichiers Babengas présents mais fonctions indisponibles.';
        return $status;
    }

    $status['enabled'] = babengas_enabled();
    if (!$status['enabled']) {
        $status['detail'] = 'Fichiers présents mais intégration désactivée (URL/clé manquante ou case décochée).';
        return $status;
    }

    // Test de fonctionnement : sonde /sante du microservice.
    $health = babengas_check_service();
    if (!empty($health['ok'])) {
        $status['functional'] = true;
        $status['version']    = (string)($health['version'] ?? '');
        $status['detail']     = 'Configuré et service Babengas en ligne'
            . ($status['version'] !== '' ? ' (version ' . $status['version'] . ')' : '')
            . (!empty($health['actif']) ? '.' : ' — mais signalé inactif.');
    } else {
        $status['functional'] = false;
        $status['detail']     = 'Configuré mais service Babengas injoignable'
            . (!empty($health['error']) ? ' : ' . $health['error'] : '.');
    }

    return $status;
}

// Syngas : base commune des mangathèques Lengas. Contrairement à Vestikan et
// Babengas, pas d'option marche/arrêt — l'intégration fait partie de Lengas
// lui-même (voir includes/syngas.php). « installed » vaut donc toujours vrai
// si les fichiers sont là ; « enabled » reflète le PROVISIONNEMENT (clé API
// obtenue), pas une case à cocher. Un bannissement actif (section 6.5) est
// signalé distinctement, y compris si le service répond par ailleurs.
function integrity_check_syngas(): array {
    $status = [
        'installed'  => false,
        'enabled'    => false,
        'functional' => null,
        'detail'     => '',
        'version'    => '',
        'banned'     => false,
    ];

    $status['installed'] = is_file('includes/syngas.php');
    if (!$status['installed']) {
        $status['detail'] = 'Fichiers Syngas absents : intégration non installée.';
        return $status;
    }

    if (!function_exists('syngas_api_key') || !function_exists('syngas_check_service')) {
        $status['detail'] = 'Fichiers Syngas présents mais fonctions indisponibles.';
        return $status;
    }

    $status['banned'] = function_exists('syngas_is_banned') && syngas_is_banned();
    $status['enabled'] = syngas_api_key() !== '';

    // Test de fonctionnement : sonde /sante, indépendante de la clé API
    // (aucune authentification requise) — répond même si l'instance n'est
    // pas encore provisionnée.
    $health = syngas_check_service();
    if (!empty($health['ok'])) {
        $status['functional'] = true;
        $status['version']    = (string)($health['version'] ?? '');
        $detail = 'Service Syngas en ligne' . ($status['version'] !== '' ? ' (version ' . $status['version'] . ')' : '') . '.';
        $detail .= $status['enabled'] ? ' Clé API enregistrée.' : ' Clé API pas encore provisionnée (se fera automatiquement au premier usage).';
        if ($status['banned']) {
            $detail .= ' ⚠️ Connexion actuellement suspendue' . (syngas_banned_reason() !== '' ? ' : ' . syngas_banned_reason() : '') . '.';
        }
        $status['detail'] = $detail;
    } else {
        $status['functional'] = false;
        $status['detail']     = 'Service Syngas injoignable' . (!empty($health['error']) ? ' : ' . $health['error'] : '.');
    }

    return $status;
}

// Petite sonde HTTP générique : le serveur répond-il quoi que ce soit ?
// Retourne ['reachable'=>bool, 'http'=>int, 'error'=>string].
function integrity_http_probe(string $url, int $timeout = 8): array {
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return ['reachable' => false, 'http' => 0, 'error' => 'URL invalide'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true,   // HEAD : on ne déclenche aucun flow
        CURLOPT_FOLLOWLOCATION => false,  // une 302 = serveur joignable
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Lengas-Integrity-Checker',
    ]);
    curl_exec($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    // Toute réponse HTTP (même 4xx/5xx) prouve que le serveur est joignable.
    if ($errno === 0 && $http > 0) {
        return ['reachable' => true, 'http' => $http, 'error' => ''];
    }
    return ['reachable' => false, 'http' => $http, 'error' => $error !== '' ? $error : 'Aucune réponse'];
}

// ────────────────────────────────────────────────────────────────────────────
// Vérification principale
// ────────────────────────────────────────────────────────────────────────────
function check_site_integrity(array $data): array {
    $options       = load_options();
    $admin_avatar  = trim($options['admin_avatar'] ?? '');

    $results = [
        'repo'            => [],   // état de la récupération Gitea + version
        'files'           => [],   // fichiers requis (présence + hash)
        'optional_files'  => [],   // Vestikan / Babengas (absence non bloquante)
        'modules_status'  => [],   // activation + fonctionnement Vestikan / Babengas
        'extra_files'     => [],   // intrus (présents localement, absents du dépôt)
        'forbidden_files' => [],
        'permissions'     => [],
        'duplicates'      => [],
        'orphaned_images' => [],
        'custom_themes'   => [],
        'external_access' => [],
        'version'         => null,
        'site_info'       => [],
        'db_structure'         => [],
        'db_structure_anilist' => [],   // V4 : colonnes/tables propres aux animés
    ];

    // ── 0. Thèmes personnalisés (sert aussi pour la détection d'intrus) ────────
    $custom_theme_files = [];
    if (function_exists('list_themes')) {
        foreach (list_themes() as $__t) {
            if (!empty($__t['custom'])) {
                $file_rel = 'assets/css/' . $__t['file'];
                $results['custom_themes'][] = ['label' => $__t['label'], 'file' => $file_rel];
                $custom_theme_files[] = $file_rel;
            }
        }
    }

    // ── 1. Récupération de l'arbre du dépôt au bon tag ─────────────────────────
    $current_version = defined('SITE_VERSION') ? SITE_VERSION : '';
    $tags            = gitea_list_tags();

    $latest_version  = null;
    $matched_tag     = null;   // tag correspondant à la version installée
    $latest_tag      = null;   // tag le plus récent

    if (!empty($tags)) {
        // Détermine le tag le plus récent (tri par version décroissante).
        usort($tags, fn($a, $b) => version_compare(normalize_version($b['name']), normalize_version($a['name'])));
        $latest_tag     = $tags[0];
        $latest_version = normalize_version($latest_tag['name']);

        // Cherche le tag correspondant à la version installée.
        foreach ($tags as $t) {
            if (normalize_version($t['name']) === normalize_version($current_version)) {
                $matched_tag = $t;
                break;
            }
        }
    }

    // Le tag à utiliser pour la comparaison des fichiers : celui de la version
    // installée si trouvé, sinon le plus récent (avec avertissement).
    $tree_tag        = $matched_tag ?? $latest_tag;
    $repo_tree       = [];
    $repo_reachable  = false;
    $used_fallback   = ($matched_tag === null && $latest_tag !== null);

    if ($tree_tag !== null) {
        $repo_tree      = gitea_get_tree($tree_tag['sha']);
        $repo_reachable = !empty($repo_tree);
    }

    $results['repo'] = [
        'reachable'        => $repo_reachable,
        'checked_tag'      => $tree_tag['name'] ?? null,
        'matched_tag'      => $matched_tag['name'] ?? null,
        'used_fallback'    => $used_fallback,
        'file_count'       => count($repo_tree),
    ];

    // ── 2. Comparaison fichier par fichier (présence + hash) ───────────────────
    // On ne compare que si l'arbre a bien été récupéré.
    if ($repo_reachable) {
        $forbidden = integrity_forbidden_files();

        foreach ($repo_tree as $path => $repo_sha) {
            // Ignorer les fichiers purement informatifs (README, LICENSE…).
            if (integrity_is_ignored_repo_file($path)) continue;

            // Les fichiers interdits sont gérés dans leur propre section.
            if (in_array($path, $forbidden, true)) continue;

            $exists      = is_file($path);
            $local_sha   = $exists ? git_blob_hash($path) : null;
            $hash_ok     = ($exists && $local_sha !== null && $local_sha === $repo_sha);
            $optional    = integrity_is_optional_path($path);

            $entry = [
                'exists'    => $exists,
                'hash_ok'   => $hash_ok,
                'optional'  => $optional,
                'category'  => integrity_categorize_path($path),
            ];

            if ($optional) {
                $results['optional_files'][$path] = $entry;
            } else {
                $results['files'][$path] = $entry;
            }
        }
    }

    // ── 3. Fichiers intrus (présents localement, absents du dépôt) ─────────────
    if ($repo_reachable) {
        $results['extra_files'] = integrity_find_extra_files($repo_tree, $custom_theme_files, $admin_avatar);
    }

    // ── 4. Fichiers interdits ──────────────────────────────────────────────────
    foreach (integrity_forbidden_files() as $file) {
        $results['forbidden_files'][$file] = !file_exists($file);
    }

    // ── 4bis. État des modules facultatifs (activés ? fonctionnels ?) ──────────
    $results['modules_status'] = [
        'vestikan' => integrity_check_vestikan(),
        'babengas' => integrity_check_babengas(),
        'syngas'   => integrity_check_syngas(),
    ];

    // ── 5. Permissions ─────────────────────────────────────────────────────────
    $checks = [
        'uploads/'      => '0774',
        'bdd/'          => '0774',
        'saves/'        => '0774',
        'bdd/lengas.db' => '0660',
    ];
    foreach ($checks as $path => $expected) {
        if (file_exists($path)) {
            $current = substr(sprintf('%o', fileperms($path)), -4);
            $results['permissions'][$path] = [
                'current'  => $current,
                'expected' => $expected,
                'ok'       => ($current === $expected),
            ];
        } else {
            $results['permissions'][$path] = [
                'current'  => 'N/A',
                'expected' => $expected,
                'ok'       => false,
            ];
        }
    }

    // ── 6. Doublons ────────────────────────────────────────────────────────────
    // La clé nom+type (series_wishlist_duplicate_key(), includes/helpers.php)
    // évite de confondre un manga et un animé du même nom (ex. « One Piece »
    // présent des deux côtés sous les deux types n'est pas un doublon).
    $wishlist        = load_wishlist();
    $loans           = load_loans();
    $series_keys     = array_map('series_wishlist_duplicate_key', $data);
    $wishlist_keys   = array_map('series_wishlist_duplicate_key', $wishlist);
    $loan_series_ids = array_unique(array_column($loans, 'series_id'));

    // Le rapport affiche des noms, pas des clés techniques : on retrouve le nom
    // d'affichage à partir de la clé retenue par l'intersection.
    $duplicate_keys = array_intersect($series_keys, $wishlist_keys);
    $duplicate_names = [];
    foreach ($data as $i => $series) {
        if (in_array($series_keys[$i], $duplicate_keys, true)) {
            $duplicate_names[] = $series['name'];
        }
    }
    $results['duplicates']['collection_wishlist'] = array_values(array_unique($duplicate_names));
    $results['duplicates']['deleted_loans'] = [];
    foreach ($loan_series_ids as $id) {
        $found = false;
        foreach ($data as $series) {
            if ($series['id'] === $id) { $found = true; break; }
        }
        if (!$found) $results['duplicates']['deleted_loans'][] = $id;
    }

    // ── 7. Images orphelines (l'avatar admin n'est PAS orphelin) ───────────────
    $uploaded_images = [];
    $used_images     = [];
    if (file_exists('uploads/') && is_dir('uploads/')) {
        foreach (scandir('uploads/') as $file) {
            if ($file !== '.' && $file !== '..' && !is_dir('uploads/' . $file)) {
                $uploaded_images[] = 'uploads/' . $file;
            }
        }
    }
    foreach ($data as $series) {
        if (!empty($series['image'])) $used_images[] = $series['image'];
        // V4 : la vignette Anilist d'une série existante n'est pas orpheline,
        // même quand une vignette personnalisée la masque (cf. la même règle
        // dans clean_orphaned_images(), fonctions/tools/cleanup.php).
        if (!empty($series['anilist_image'])) $used_images[] = $series['anilist_image'];
    }
    // Ne jamais considérer la photo de profil de l'admin comme orpheline.
    if ($admin_avatar !== '') $used_images[] = $admin_avatar;

    $results['orphaned_images'] = array_values(array_diff($uploaded_images, $used_images));

    // ── 8. Accès externe aux dossiers sensibles ────────────────────────────────
    $results['external_access'] = check_external_access();

    // ── 9. Version ─────────────────────────────────────────────────────────────
    $results['version'] = [
        'current'      => $current_version,
        'latest'       => $latest_version,
        'needs_update' => ($latest_version !== null && version_compare(normalize_version($current_version), $latest_version, '<')),
    ];

    // ── 10. Infos serveur ──────────────────────────────────────────────────────
    $results['site_info'] = [
        'site_url'                  => get_site_url(),
        'uses_https'                => uses_https(),
        'uploads_size'              => get_uploads_size(),
        'max_upload_size'           => get_max_upload_size(),
        'effective_max_upload_size' => get_effective_max_upload_size(),
        'server_info'               => get_server_info(),
    ];

    // ── 11. Structure de la base de données (intégration MangaUpdates) ─────────
    $cache_count = 0;
    try {
        $db = get_db();
        $col_names = array_column($db->query("PRAGMA table_info(series)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $results['db_structure']['Colonne series.mangaupdates_url'] = in_array('mangaupdates_url', $col_names, true);
        $tbl = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='mangaupdates_cache'")->fetchColumn();
        $results['db_structure']['Table mangaupdates_cache'] = ($tbl !== false);
        if ($tbl !== false) {
            $cache_count = (int)$db->query("SELECT COUNT(*) FROM mangaupdates_cache")->fetchColumn();
        }
    } catch (Exception $e) {
        $results['db_structure']['Lecture impossible'] = false;
    }

    // ── 11bis. Structure de la base de données (intégration Anilist, V4) ───────
    // Colonnes et tables introduites par la V4 pour les séries animées :
    // typage, champs Anilist, éditions physiques, cache des fiches. Une
    // absence signale une migration incomplète (config.php non exécuté, base
    // restaurée depuis une version antérieure à la V4…).
    $anilist_cache_count = 0;
    try {
        $db = get_db();
        $col_names = array_column($db->query("PRAGMA table_info(series)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $anilist_columns = [
            'type', 'anilist_id', 'anilist_url', 'studios', 'anime_format',
            'alt_titles', 'anilist_image', 'watching_abandoned', 'rewatch_count',
            'rewatch_last_date', 'reread_last_date',
            'anilist_synced_at', 'rating',
        ];
        foreach ($anilist_columns as $col) {
            $results['db_structure_anilist']['Colonne series.' . $col] = in_array($col, $col_names, true);
        }

        $wishlist_cols = array_column($db->query("PRAGMA table_info(wishlist)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $results['db_structure_anilist']['Colonne wishlist.type']       = in_array('type', $wishlist_cols, true);
        $results['db_structure_anilist']['Colonne wishlist.anilist_id'] = in_array('anilist_id', $wishlist_cols, true);
        $results['db_structure_anilist']['Colonne wishlist.studio']     = in_array('studio', $wishlist_cols, true);

        $tables = ['series_editions', 'anilist_cache'];
        foreach ($tables as $table) {
            $tbl = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $table . "'")->fetchColumn();
            $results['db_structure_anilist']['Table ' . $table] = ($tbl !== false);
        }

        $cache_tbl = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='anilist_cache'")->fetchColumn();
        if ($cache_tbl !== false) {
            $anilist_cache_count = (int)$db->query("SELECT COUNT(*) FROM anilist_cache")->fetchColumn();
        }
    } catch (Exception $e) {
        $results['db_structure_anilist']['Lecture impossible'] = false;
    }

    // ── 12. Connectivité de l'API MangaUpdates ─────────────────────────────────
    if (function_exists('mangaupdates_check_api')) {
        $api = mangaupdates_check_api();
        $api['cache_count'] = $cache_count;
        $results['mangaupdates_api'] = $api;
    }

    // ── 12bis. Connectivité de l'API Anilist (V4) ───────────────────────────────
    // Ne sonde le réseau que si la collection contient au moins une série
    // animée : sans quoi le connecteur n'est d'aucune utilité, inutile de
    // solliciter Anilist à chaque vérification d'intégrité.
    if (function_exists('anilist_check_api') && !empty(array_filter($data, 'is_anime'))) {
        $api = anilist_check_api();
        $api['cache_count'] = $anilist_cache_count;
        $results['anilist_api'] = $api;
    }

    return $results;
}

// ────────────────────────────────────────────────────────────────────────────
// Accès externe aux dossiers sensibles (inchangé)
// ────────────────────────────────────────────────────────────────────────────
function check_external_access(): array {
    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST']
        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

    $targets = [
        'saves/' => $base_url . '/saves/',
        'bdd/'   => $base_url . '/bdd/',
    ];

    $results = [];
    foreach ($targets as $label => $url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);       // HEAD uniquement
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_errno($ch);
        curl_close($ch);

        if ($error) {
            $results[$label] = ['status' => $http_code, 'ok' => null, 'label' => 'Indéterminé'];
        } else {
            $blocked = in_array($http_code, [403, 404]);
            $results[$label] = [
                'status' => $http_code,
                'ok'     => $blocked,
                'label'  => $blocked ? 'Bloqué' : 'Accessible',
            ];
        }
    }
    return $results;
}

// ────────────────────────────────────────────────────────────────────────────
// Helpers d'informations serveur (inchangés)
// ────────────────────────────────────────────────────────────────────────────
function get_site_url(): string {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $uri      = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return "$protocol://$host$uri";
}

function uses_https(): bool {
    return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
}

function get_uploads_size(): string {
    $size = 0;
    if (file_exists('uploads/') && is_dir('uploads/')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('uploads/'));
        foreach ($files as $file) {
            if ($file->isFile()) $size += $file->getSize();
        }
    }
    return format_size($size);
}

function get_max_upload_size(): string {
    return ini_get('upload_max_filesize');
}

function get_effective_max_upload_size(): int {
    $max_upload   = parse_size(ini_get('upload_max_filesize'));
    $max_post     = parse_size(ini_get('post_max_size'));
    $memory_limit = parse_size(ini_get('memory_limit'));
    return min($max_upload, $max_post, $memory_limit);
}

function get_server_info(): array {
    return [
        'server_architecture' => php_uname('m'),
        'server_software'     => $_SERVER['SERVER_SOFTWARE'],
        'php_version'         => phpversion(),
        'max_execution_time'  => ini_get('max_execution_time'),
        'memory_limit'        => ini_get('memory_limit'),
    ];
}

function parse_size(string $size): int {
    $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
    $size = preg_replace('/[^0-9\\.]/', '', $size);
    if ($unit) {
        return (int)round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
    }
    return (int)round($size);
}

function format_size(int $bytes): string {
    if ($bytes >= 1073741824)      return number_format($bytes / 1073741824, 2) . ' GB';
    elseif ($bytes >= 1048576)     return number_format($bytes / 1048576,    2) . ' MB';
    elseif ($bytes >= 1024)        return number_format($bytes / 1024,       2) . ' KB';
    elseif ($bytes > 1)            return $bytes . ' bytes';
    elseif ($bytes === 1)          return '1 byte';
    else                           return '0 bytes';
}
