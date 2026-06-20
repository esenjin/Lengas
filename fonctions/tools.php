<?php
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
        'version'         => null,
        'site_info'       => [],
    ];

    // 1. Existence des fichiers/dossiers
    $required_files = [
        'index.php', 'admin.php', 'stats.php', 'config.php', 'login.php', 'logout.php', '.htaccess',
        'page-prets.php', 'page-wishlist.php',
        'assets/css/main.css', 'assets/js/public.js', 'assets/js/stats.js',
        'assets/img/', 'assets/img/logo.png', 'assets/img/favicon.ico', 'assets/img/mulogo.png',
        'assets/js/admin/',
        'fonctions/loans.php', 'fonctions/options.php', 'fonctions/tools.php', 'fonctions/read.php',
        'fonctions/series.php', 'fonctions/wishlist.php', 'fonctions/volumes.php', 'fonctions/stats_compute.php',
        'includes/mangaupdates.php', 'includes/auth.php', 'includes/helpers.php', 'includes/sidebar.php',
        'includes/', 'fonctions/', 'uploads/', 'saves/', 'bdd/',
    ];
    foreach ($required_files as $file) {
        $results['file_existence'][$file] = file_exists($file);
    }

    $required_css_files = [
        'assets/css/_admin.css', 'assets/css/_base.css', 'assets/css/_buttons.css',
        'assets/css/_forms.css', 'assets/css/_layout.css', 'assets/css/_modals.css',
        'assets/css/_public.css', 'assets/css/_responsive.css', 'assets/css/_series.css',
        'assets/css/_stats.css', 'assets/css/_utils.css', 'assets/css/_variables.css',
        'assets/css/_sidebar.css', 'assets/css/_pages.css',
    ];
    foreach ($required_css_files as $file) {
        $results['file_existence'][$file] = file_exists($file);
    }

    $required_js_files = [
        'assets/js/admin/series.js', 'assets/js/admin/volumes.js', 'assets/js/admin/wishlist.js',
        'assets/js/admin/loans.js',  'assets/js/admin/tools.js',   'assets/js/admin/autocomplete.js',
        'assets/js/admin/modals.js', 'assets/js/admin/pagination.js', 'assets/js/admin/main.js',
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

// Nettoyer les doublons
function clean_duplicates(): array {
    global $data;
    $wishlist = load_wishlist();
    $loans    = load_loans();
    $messages = [];

    $series_names   = array_map(fn($s) => strtolower($s['name']), $data);
    $wishlist_names = array_map(fn($s) => strtolower($s['name']), $wishlist);
    $duplicates     = array_intersect($series_names, $wishlist_names);

    if (!empty($duplicates)) {
        $new_wishlist = array_values(array_filter($wishlist, fn($item) => !in_array(strtolower($item['name']), $series_names)));
        save_wishlist($new_wishlist);
        $messages[] = "Doublons collection/envies nettoyés.";
    }

    $series_ids   = array_column($data, 'id');
    $deleted_loans = array_filter($loans, fn($loan) => !in_array($loan['series_id'], $series_ids));

    if (!empty($deleted_loans)) {
        $new_loans = array_values(array_filter($loans, fn($loan) => in_array($loan['series_id'], $series_ids)));
        save_loans($new_loans);
        $messages[] = "Prêts de séries supprimées nettoyés.";
    }

    return ['success' => true, 'message' => implode(' ', $messages) ?: 'Aucun doublon à nettoyer.'];
}

// Nettoyer les images orphelines
function clean_orphaned_images(): array {
    global $data;
    $uploaded_images = [];
    $used_images     = [];
    $deleted_images  = [];

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

    foreach (array_diff($uploaded_images, $used_images) as $image) {
        if (file_exists($image) && unlink($image)) $deleted_images[] = $image;
    }

    return [
        'success' => true,
        'message' => !empty($deleted_images)
            ? 'Images orphelines supprimées : ' . implode(', ', $deleted_images)
            : 'Aucune image orpheline à supprimer.',
    ];
}

// Supprimer les fichiers interdits
function clean_forbidden_files(): array {
    $forbidden_files = ['generate_password.php', 'migrate.php', 'fix_series_status.php'];
    $deleted_files   = [];
    $failed_files    = [];

    foreach ($forbidden_files as $file) {
        $path = __DIR__ . '/../' . $file;
        if (file_exists($path)) {
            if (unlink($path)) {
                $deleted_files[] = $file;
            } else {
                $failed_files[] = $file;
            }
        }
    }

    if (!empty($failed_files)) {
        return [
            'success' => false,
            'message' => 'Impossible de supprimer : ' . implode(', ', $failed_files) . '. Vérifiez les permissions du fichier sur le serveur.',
        ];
    }

    return [
        'success' => true,
        'message' => !empty($deleted_files)
            ? 'Fichiers interdits supprimés : ' . implode(', ', $deleted_files)
            : 'Aucun fichier interdit à supprimer.',
    ];
}

// Gestion des sauvegardes — sauvegarde maintenant le fichier SQLite
function create_backup(): array {
    $backup_dir = 'saves';
    if (!file_exists($backup_dir)) {
        $old_umask = umask(0);
        $success   = mkdir($backup_dir, 0774, true);
        umask($old_umask);
        if (!$success) {
            return ['success' => false, 'message' => "Impossible de créer le dossier 'saves/'. Veuillez vérifier les permissions."];
        }
    }

    if (!is_writable($backup_dir)) {
        return ['success' => false, 'message' => "Le dossier 'saves/' n'est pas accessible en écriture."];
    }

    $timestamp   = time();
    $backup_name = "save_$timestamp.zip";
    $backup_path = "$backup_dir/$backup_name";

    $zip = new ZipArchive();
    if ($zip->open($backup_path, ZipArchive::CREATE) === TRUE) {
        // Ajouter le fichier SQLite
        if (file_exists(DB_FILE)) {
            $zip->addFile(DB_FILE, 'bdd/lengas.db');
        }

        // Ajouter le dossier uploads/
        $uploads_dir = 'uploads/';
        if (file_exists($uploads_dir) && is_dir($uploads_dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploads_dir),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $file_path     = $file->getRealPath();
                    $relative_path = substr($file_path, strlen(realpath($uploads_dir)) + 1);
                    $zip->addFile($file_path, 'uploads/' . $relative_path);
                }
            }
        }

        $zip->close();
        return ['success' => true, 'message' => 'Sauvegarde créée avec succès.'];
    }

    return ['success' => false, 'message' => 'Impossible de créer la sauvegarde.'];
}

// Lister les sauvegardes
function list_backups(): array {
    $backup_dir = 'saves';
    $backups    = [];
    if (file_exists($backup_dir)) {
        foreach (scandir($backup_dir) as $file) {
            if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                $timestamp = (int)str_replace(['save_', '.zip'], '', $file);
                $date      = date('d/m/Y H:i', $timestamp);
                $backups[] = ['name' => $file, 'date' => $date, 'timestamp' => $timestamp];
            }
        }
        usort($backups, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    }
    return ['success' => true, 'backups' => $backups];
}

// Supprimer une sauvegarde
function delete_backup(string $backup_file): array {
    if (!empty($backup_file) && file_exists("saves/$backup_file")) {
        unlink("saves/$backup_file");
        return ['success' => true, 'message' => 'Sauvegarde supprimée avec succès.'];
    }
    return ['success' => false, 'message' => 'Fichier de sauvegarde introuvable.'];
}

// Générer les notifications pour une série
function generate_notifications(array $volumes, ?int $ref_volumes = null): array {
    $notifications = [];
    if (empty($volumes)) return $notifications;

    $numbers      = array_map(fn($v) => $v['number'], $volumes);
    $min          = min($numbers);
    $max          = max($numbers);
    $last_volumes = array_filter($volumes, fn($v) => !empty($v['last']));

    $missing = [];
    for ($i = $min; $i <= $max; $i++) {
        if (!in_array($i, $numbers)) $missing[] = $i;
    }
    if (!empty($missing)) {
        $notifications[] = count($missing) == 1
            ? "Attention, le tome " . implode(', ', $missing) . " est manquant."
            : "Attention, les tomes " . implode(', ', $missing) . " sont manquants.";
    }

    if (!empty($last_volumes)) {
        $last_numbers = array_map(fn($v) => $v['number'], $last_volumes);
        foreach ($last_numbers as $num) {
            if ($num != $max) {
                $notifications[] = "Attention, le tome tagué dernier ($num) est incorrect.";
            }
        }
        if (count($last_volumes) > 1) {
            $notifications[] = "Attention, plusieurs tomes sont tagués comme dernier (" . implode(', ', $last_numbers) . ").";
        }
    }

    if ($ref_volumes !== null && $max > $ref_volumes) {
        $notifications[] = "Attention, votre série contient plus de tomes que ce qui est indiqué sur MangaUpdates.";
    }
    if ($ref_volumes !== null && $max < $ref_volumes) {
        $missing = range($max + 1, $ref_volumes);
        $notifications[] = count($missing) == 1
            ? "Attention, il manque le tome " . implode(', ', $missing) . " pour compléter cette série."
            : "Attention, il manque les tomes " . implode(', ', $missing) . " pour compléter cette série.";
    }
    if ($ref_volumes !== null && $max == $ref_volumes && empty($last_volumes)) {
        $notifications[] = "Attention, cette série semble complète mais le dernier tome n'est pas tagué comme tel.";
    }

    return $notifications;
}

// Vérification des incohérences de la collection
function check_collection_coherence(array $data): array {
    $issues = [];

    // ── Pré-charger le cache MangaUpdates en lot (appel réseau si nécessaire) ──
    // Même logique que get_incomplete_series : on récupère les données MU pour
    // toutes les séries qui ont une URL, afin que les checks mu_* fonctionnent
    // sans avoir eu besoin de lancer "Séries incomplètes" au préalable.
    $mu_cache_map = [];
    if (function_exists('mangaupdates_get_id_from_url') && function_exists('mangaupdates_get_volumes_batch')) {
        $ids_needed = [];
        foreach ($data as $series) {
            $url = $series['mangaupdates_url'] ?? '';
            if ($url !== '') {
                $id = mangaupdates_get_id_from_url($url);
                if ($id !== null) $ids_needed[] = $id;
            }
        }
        $mu_cache_map = mangaupdates_get_volumes_batch($ids_needed);
    }

    foreach ($data as $series) {
        $series_issues = [];
        $name    = $series['name'] ?? '(sans nom)';
        $volumes = $series['volumes'] ?? [];

        if (empty($volumes)) {
            $series_issues[] = ['type' => 'no_volumes', 'message' => 'La série ne possède aucun tome.'];
            $issues[] = ['series' => $name, 'series_id' => $series['id'], 'problems' => $series_issues];
            continue;
        }

        $numbers      = array_map(fn($v) => (int)$v['number'], $volumes);
        $max          = max($numbers);
        $min          = min($numbers);
        $last_volumes = array_values(array_filter($volumes, fn($v) => !empty($v['last'])));

        if (count($last_volumes) > 1) {
            $last_nums = array_map(fn($v) => $v['number'], $last_volumes);
            $series_issues[] = ['type' => 'multiple_last', 'message' => 'Plusieurs tomes sont tagués comme dernier : tome(s) ' . implode(', ', $last_nums) . '.'];
        }

        foreach ($last_volumes as $lv) {
            if ((int)$lv['number'] !== $max) {
                $series_issues[] = ['type' => 'wrong_last', 'message' => 'Le tome ' . $lv['number'] . ' est tagué dernier mais le tome le plus élevé est le ' . $max . '.'];
            }
        }

        $missing = [];
        for ($i = $min; $i <= $max; $i++) {
            if (!in_array($i, $numbers, true)) $missing[] = $i;
        }
        if (!empty($missing)) {
            $series_issues[] = ['type' => 'missing_volumes', 'message' => 'Tome(s) manquant(s) dans la séquence : ' . implode(', ', $missing) . '.'];
        }

        $duplicates = array_keys(array_filter(array_count_values($numbers), fn($c) => $c > 1));
        if (!empty($duplicates)) {
            $series_issues[] = ['type' => 'duplicate_volumes', 'message' => 'Numéro(s) de tome en double : ' . implode(', ', $duplicates) . '.'];
        }

        $invalid = array_filter($numbers, fn($n) => $n <= 0);
        if (!empty($invalid)) {
            $series_issues[] = ['type' => 'invalid_number', 'message' => 'Tome(s) avec un numéro invalide (≤ 0) : ' . implode(', ', $invalid) . '.'];
        }

        $status = $series['status'] ?? '';
        if ($status === 'terminée' && empty($last_volumes)) {
            $series_issues[] = ['type' => 'finished_no_last', 'message' => "La série est marquée comme terminée mais aucun tome n'est tagué dernier."];
        }

        if (!empty($last_volumes) && $status !== 'terminée' && $status !== 'abandonnée' && $status !== 'en pause') {
            $series_issues[] = ['type' => 'last_but_not_finished', 'message' => "Un tome est tagué dernier mais la série n'est pas marquée comme terminée."];
        }

        if ($min > 1) {
            $series_issues[] = ['type' => 'sequence_not_starting_at_1', 'message' => 'La collection ne commence pas au tome 1 (premier tome possédé : ' . $min . ').'];
        }

        // ── Tomes non lus dans une série "lue ailleurs" ──────────────────────
        if (!empty($series['read_elsewhere'])) {
            $unread = array_values(array_filter($volumes, fn($v) => ($v['status'] ?? '') !== 'terminé'));
            if (!empty($unread)) {
                $unread_nums = array_map(fn($v) => $v['number'], $unread);
                $series_issues[] = [
                    'type'    => 'read_elsewhere_unread',
                    'message' => 'Série marquée « lue ailleurs » mais ' . count($unread_nums) . ' tome(s) non lu(s) : ' . implode(', ', $unread_nums) . '.',
                ];
            }
        }

        // ── Cohérence avec le statut de publication MangaUpdates ────────────────
        // On utilise d'abord mu_cache_map (pré-chargé en lot, avec appels réseau
        // si nécessaire), puis on se rabat sur mangaupdates_get_cached_status en
        // lecture seule si la série n'y figure pas (URL invalide, échec réseau…).
        if (!empty($series['mangaupdates_url']) && function_exists('mangaupdates_get_id_from_url')) {
            $mu_id = mangaupdates_get_id_from_url($series['mangaupdates_url']);
            if ($mu_id !== null) {
                $mu_info = $mu_cache_map[$mu_id]
                    ?? (function_exists('mangaupdates_get_volumes') ? mangaupdates_get_volumes($mu_id) : null);

                if ($mu_info !== null) {
                    $mu_completed     = !empty($mu_info['completed']);
                    $mu_volumes       = $mu_info['volumes'] ?? null;
                    $mu_status_text   = $mu_info['status'] ?? null;
                    $is_finished_here = ($status === 'terminée') || !empty($last_volumes);

                    // mu_still_ongoing et mu_complete_unmarked nécessitent le statut textuel
                    if ($mu_status_text !== null && $mu_status_text !== '') {
                        if ($is_finished_here && !$mu_completed) {
                            $series_issues[] = ['type' => 'mu_still_ongoing', 'message' => 'Vous avez marqué la série comme terminée (ou tagué un tome comme dernier), mais MangaUpdates indique une publication toujours en cours (« ' . $mu_status_text . ' »).'];
                        }

                        if ($mu_completed && !$is_finished_here && $mu_volumes !== null && $max >= (int)$mu_volumes) {
                            $series_issues[] = ['type' => 'mu_complete_unmarked', 'message' => 'MangaUpdates indique la série comme terminée (« ' . $mu_status_text . ' », ' . (int)$mu_volumes . ' tomes) et vous semblez la posséder entièrement, mais elle n\'est pas marquée comme terminée.'];
                        }
                    }

                    // mu_more_volumes : le nombre de tomes seul suffit (pas besoin du statut textuel)
                    // On utilise count($volumes) pour être cohérent avec la modale "Séries incomplètes"
                    $owned_count = count($volumes);
                    if ($mu_volumes !== null && $owned_count > (int)$mu_volumes) {
                        $series_issues[] = ['type' => 'mu_more_volumes', 'message' => 'Vous possédez plus de tomes (' . $owned_count . ') que ce qu\'indique MangaUpdates (' . (int)$mu_volumes . ').'];
                    }
                }
            }
        }

        if (!empty($series_issues)) {
            $issues[] = [
                'series'           => $name,
                'series_id'        => $series['id'],
                'mangaupdates_url' => $series['mangaupdates_url'] ?? '',
                'problems'         => $series_issues,
            ];
        }
    }

    // ── Prêts vers des séries inexistantes ou "lues ailleurs" ────────────────
    if (function_exists('load_loans')) {
        $loans          = load_loans();
        $loans_by_series = [];
        foreach ($loans as $loan) {
            $loans_by_series[$loan['series_id']][] = $loan;
        }

        // Indexer les séries existantes par ID pour recherche rapide
        $series_map = [];
        foreach ($data as $s) {
            $series_map[$s['id']] = $s;
        }

        foreach ($loans_by_series as $sid => $sid_loans) {
            $n = count($sid_loans);
            $vols = implode(', ', array_map(fn($l) => 'T' . $l['volume_number'], $sid_loans));

            if (!isset($series_map[$sid])) {
                // Série supprimée
                $issues[] = [
                    'series'    => '(Série supprimée)',
                    'series_id' => $sid,
                    'problems'  => [[
                        'type'    => 'loan_deleted_series',
                        'message' => $n . ' tome(s) prêté(s) (' . $vols . ') pour une série qui n\'existe plus dans la collection.',
                    ]],
                ];
            } elseif (!empty($series_map[$sid]['read_elsewhere'])) {
                // Série marquée "lue ailleurs" (physiquement absente)
                $issues[] = [
                    'series'    => $series_map[$sid]['name'],
                    'series_id' => $sid,
                    'problems'  => [[
                        'type'    => 'loan_read_elsewhere',
                        'message' => $n . ' tome(s) prêté(s) (' . $vols . ') pour une série marquée « lue ailleurs » — elle n\'est pas physiquement dans votre collection.',
                    ]],
                ];
            }
        }
    }

    return $issues;
}
