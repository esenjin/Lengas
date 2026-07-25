<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/integrity.php — Outil « Vérification d'intégrité »
//
// Contrôle des fichiers requis, des fichiers interdits, des permissions,
// des doublons, des images orphelines, des thèmes personnalisés, des
// fichiers Vestikan, de l'accès externe aux dossiers sensibles, de la
// version, de la structure de la base et de l'API MangaUpdates.
// Contient aussi les helpers d'informations serveur (tailles, versions…).
// ────────────────────────────────────────────────────────────────────────────

// Fonction pour récupérer la dernière version depuis Gitea
function get_latest_version_from_gitea(): ?string {
    $url = "https://git.crystalyx.net/api/v1/repos/Esenjin_Asakha/Lengas/releases/latest";
    $ch  = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Lengas-Version-Checker");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        if (isset($data['tag_name'])) {
            return ltrim($data['tag_name'], 'v');
        }
    }
    return null;
}

// Fonction pour vérifier l'intégrité du site
function check_site_integrity(array $data): array {
    $results = [
        'file_existence'  => [],
        'forbidden_files' => [],
        'permissions'     => [],
        'duplicates'      => [],
        'orphaned_images' => [],
        'custom_themes'   => [],
        'vestikan_files'  => [],
        'babengas_files'  => [],
        'version'         => null,
        'site_info'       => [],
    ];

    // 1. Existence des fichiers/dossiers
    $required_files = [
        'index.php', 'admin.php', 'stats.php', 'config.php', 'login.php', 'logout.php', '.htaccess',
        'page-prets.php', 'page-wishlist.php', 'page-critiques.php', 'page-outils.php', 'page-options.php',
        'assets/css/main.css', 'assets/js/public.js', 'assets/js/stats.js',
        'assets/img/', 'assets/img/logo.png', 'assets/img/favicon.ico', 'assets/img/mulogo.png',
        'assets/js/admin/', 'assets/js/admin/tools/',
        'fonctions/loans.php', 'fonctions/options.php', 'fonctions/tools.php', 'fonctions/read.php',
        'fonctions/series.php', 'fonctions/wishlist.php', 'fonctions/volumes.php',
        'fonctions/stats_compute.php', 'fonctions/reviews.php',
        'includes/mangaupdates.php', 'includes/auth.php', 'includes/helpers.php', 'includes/sidebar.php',
        'includes/public-sidebar.php', 'includes/custom_icons.php',
        'includes/themes.php', 'includes/status_filter.php',
        'includes/', 'fonctions/', 'fonctions/tools/', 'uploads/', 'saves/', 'bdd/',
    ];

    // Fichiers de fonctions des outils (un fichier par outil)
    $required_tool_files = [
        'fonctions/tools/backups.php', 'fonctions/tools/integrity.php', 'fonctions/tools/cleanup.php',
        'fonctions/tools/mangaupdates_assoc.php', 'fonctions/tools/incomplete.php',
        'fonctions/tools/coherence.php',
    ];
    $required_files = array_merge($required_files, $required_tool_files);
    foreach ($required_files as $file) {
        $results['file_existence'][$file] = file_exists($file);
    }

    $required_css_files = [
        'assets/css/_admin.css', 'assets/css/_base.css', 'assets/css/_buttons.css',
        'assets/css/_forms.css', 'assets/css/_layout.css', 'assets/css/_modals.css',
        'assets/css/_public.css', 'assets/css/_responsive.css', 'assets/css/_series.css',
        'assets/css/_stats.css', 'assets/css/_utils.css', 'assets/css/_variables.css',
        'assets/css/_sidebar.css', 'assets/css/_pages.css', 'assets/css/_reviews.css',
        'assets/css/_variables-light.css',
    ];
    foreach ($required_css_files as $file) {
        $results['file_existence'][$file] = file_exists($file);
    }

    $required_js_files = [
        'assets/js/admin/series.js', 'assets/js/admin/volumes.js', 'assets/js/admin/wishlist.js',
        'assets/js/admin/loans.js',  'assets/js/admin/autocomplete.js', 'assets/js/admin/modals.js',
        'assets/js/admin/pagination.js', 'assets/js/admin/reviews.js',
        'assets/js/admin/main.js',
        // Scripts des outils (un fichier par outil)
        'assets/js/admin/tools/backups.js', 'assets/js/admin/tools/integrity.js',
        'assets/js/admin/tools/mangaupdates-assoc.js', 'assets/js/admin/tools/incomplete.js',
        'assets/js/admin/tools/coherence.js',
    ];
    foreach ($required_js_files as $file) {
        $results['file_existence'][$file] = file_exists($file);
    }

    // Fichier BDD SQLite
    $results['file_existence']['bdd/lengas.db'] = file_exists('bdd/lengas.db');

    // 2. Fichiers interdits
    $results['forbidden_files']['generate_password.php'] = !file_exists(__DIR__ . '/../generate_password.php');
    $results['forbidden_files']['migrate.php']           = !file_exists(__DIR__ . '/../migrate.php');
    $results['forbidden_files']['fix_series_status.php'] = !file_exists(__DIR__ . '/../fix_series_status.php');

    // 3. Permissions
    $checks = [
        'uploads/'     => '0774',
        'bdd/'         => '0774',
        'saves/'       => '0774',
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

    // 4. Doublons
    $wishlist       = load_wishlist();
    $loans          = load_loans();
    $series_names   = array_map(fn($s) => strtolower($s['name']), $data);
    $wishlist_names = array_map(fn($s) => strtolower($s['name']), $wishlist);
    $loan_series_ids = array_unique(array_column($loans, 'series_id'));

    $results['duplicates']['collection_wishlist'] = array_intersect($series_names, $wishlist_names);

    $results['duplicates']['deleted_loans'] = [];
    foreach ($loan_series_ids as $id) {
        $found = false;
        foreach ($data as $series) {
            if ($series['id'] === $id) { $found = true; break; }
        }
        if (!$found) {
            $results['duplicates']['deleted_loans'][] = $id;
        }
    }

    // 5. Images orphelines
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
    }
    $results['orphaned_images'] = array_values(array_diff($uploaded_images, $used_images));

    // 5bis. Thèmes personnalisés présents
    $results['custom_themes'] = [];
    if (function_exists('list_themes')) {
        foreach (list_themes() as $__t) {
            if (!empty($__t['custom'])) {
                $results['custom_themes'][] = [
                    'label' => $__t['label'],
                    'file'  => 'assets/css/' . $__t['file'],
                ];
            }
        }
    }

    // 5ter. Fichiers Vestikan (facultatifs — absence non bloquante)
    $vestikan_files = [
        'includes/vestikan-config.php',
        'includes/vestikan-sdk.php',
        'includes/vestikan.php',
        'vestikan-callback.php',
        'vestikan-login.php',
    ];
    foreach ($vestikan_files as $file) {
        $results['vestikan_files'][$file] = file_exists($file);
    }

    // 5quater. Fichiers Babengas (facultatifs — absence non bloquante)
    $babengas_files = [
        'includes/babengas.php',
        'fonctions/tools/babengas-helpers.php',
        'babengas-ping.php',
        'assets/js/admin/tools/babengas.js',
        'assets/css/_babengas.css',
        'assets/img/babelogo.png',
    ];
    foreach ($babengas_files as $file) {
        $results['babengas_files'][$file] = file_exists($file);
    }

    // 6. Accès externe aux dossiers sensibles
    $results['external_access'] = check_external_access();

    // 7. Version
    $latest_version  = get_latest_version_from_gitea();
    $results['version'] = [
        'current'      => SITE_VERSION,
        'latest'       => $latest_version,
        'needs_update' => ($latest_version !== null && version_compare(SITE_VERSION, $latest_version, '<')),
    ];

    // 8. Infos serveur
    $results['site_info'] = [
        'site_url'                  => get_site_url(),
        'uses_https'                => uses_https(),
        'uploads_size'              => get_uploads_size(),
        'max_upload_size'           => get_max_upload_size(),
        'effective_max_upload_size' => get_effective_max_upload_size(),
        'server_info'               => get_server_info(),
    ];

    // 9. Structure de la base de données (intégration MangaUpdates)
    $results['db_structure'] = [];
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

    // 10. Connectivité de l'API MangaUpdates
    if (function_exists('mangaupdates_check_api')) {
        $api = mangaupdates_check_api();
        $api['cache_count'] = $cache_count;
        $results['mangaupdates_api'] = $api;
    }

    return $results;
}

// Vérifie que saves/ et bdd/ ne sont pas accessibles depuis l'extérieur
function check_external_access(): array {
    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST']
        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

    // Pour chaque dossier, on tente d'accéder à un fichier connu qui doit être bloqué
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
            // Impossible de joindre le serveur (ex: localhost sans accès externe) — indéterminé
            $results[$label] = ['status' => $http_code, 'ok' => null, 'label' => 'Indéterminé'];
        } else {
            // 403 Forbidden ou 404 Not Found = accès bloqué = OK
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