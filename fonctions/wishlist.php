<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/wishlist.php — Liste d'envies, typée
//
// Une entrée peut être :
//   • un manga (type = 'manga') : fiche « légère » mais complète — nom, liste
//     de contributeurs (même format que series.contributors : tous rôles,
//     plusieurs lignes possibles), vignette locale facultative, URL
//     MangaUpdates, URL Manga News et identifiant Syngas facultatifs. Tout
//     est repris tel quel au passage en collection (modale d'ajout
//     préremplie, vignette comprise) ;
//   • un animé (type = 'anime') : `studio` remplace `author` (pas d'éditeur),
//     et un `anilist_id` est mémorisé dès que l'utilisateur a choisi une fiche
//     Anilist (recherche par titre ou par identifiant). Ce champ est ce qui
//     permet, au passage en collection, un import immédiat sans nouvelle
//     recherche : cf. add_from_wishlist().
//
// Vignettes :
//   • `image` : fichier LOCAL dans uploads/ (téléversé depuis la modale de la
//     liste d'envies, ou copié depuis la collection par « Déplacer dans la
//     liste »). Protégé du nettoyage des images orphelines (cf.
//     wishlist_used_images(), lu par fonctions/tools/cleanup.php et
//     fonctions/tools/integrity.php), supprimé avec l'entrée — sauf quand il
//     devient la vignette de la série créée au passage en collection.
//   • animés : la couverture Anilist est TÉLÉCHARGÉE dans `image` dès l'ajout
//     à la liste (et rattrapée pour les entrées plus anciennes, cf.
//     wishlist_anime_backfill()). Au passage en collection, ce même fichier
//     devient la vignette Anilist de la série, sans nouveau téléchargement.
//   • `cover_url` : URL distante de cette couverture, mémorisée en repli
//     d'affichage si le téléchargement a échoué.
//
// `author` / `publisher` restent écrits en base pour les mangas : ce sont
// désormais des résumés texte (noms des contributeurs Auteur / Éditeur, joints
// par virgule), recalculés à chaque écriture, que le tri, la recherche,
// l'export Anilist et le nettoyage des doublons lisent sans modification.
// ────────────────────────────────────────────────────────────────────────────

// Motif d'un identifiant Syngas (UUID v4), même règle que
// assets/js/admin/syngas-search.js (SYNGAS_UUID_PATTERN).
const WISHLIST_SYNGAS_UID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

// ── Contributeurs ────────────────────────────────────────────────────────────

// Nettoie une liste de contributeurs { name, role, role_custom } : même
// règle que sanitize_contributor_entry() (fonctions/series.php) quand elle
// est chargée, repli équivalent sinon (ce fichier est aussi inclus par des
// pages qui ne chargent pas forcément fonctions/series.php en premier).
function wishlist_sanitize_contributors($list): array {
    if (!is_array($list)) return [];
    $out = [];
    foreach ($list as $entry) {
        if (!is_array($entry)) continue;
        if (function_exists('sanitize_contributor_entry')) {
            $c = sanitize_contributor_entry($entry);
        } else {
            $role = trim((string)($entry['role'] ?? ''));
            $c = [
                'name'        => trim((string)($entry['name'] ?? '')),
                'role'        => $role,
                'role_custom' => $role === 'autre' ? trim((string)($entry['role_custom'] ?? '')) : '',
            ];
        }
        if ($c['name'] !== '') $out[] = $c;
    }
    return $out;
}

// Contributeurs d'une ligne de la table wishlist : la colonne JSON fait foi
// dès qu'elle est renseignée ; sinon (entrée antérieure à cette version, ou
// animé), reconstruction depuis author/publisher.
function wishlist_contributors_from_row(array $row): array {
    $raw = $row['contributors'] ?? '';
    if (is_array($raw)) return wishlist_sanitize_contributors($raw);
    $raw = trim((string)$raw);
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return wishlist_sanitize_contributors($decoded);
    }
    $list = [];
    foreach (['author' => 'auteur', 'publisher' => 'editeur'] as $field => $role) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value === '') continue;
        $list[] = ['name' => $value, 'role' => $role, 'role_custom' => ''];
    }
    return $list;
}

// Noms des contributeurs d'un rôle donné, joints par virgule (résumé
// author / publisher).
function wishlist_contributors_names(array $contributors, string $role): string {
    $names = [];
    foreach ($contributors as $c) {
        if (($c['role'] ?? '') === $role && trim((string)($c['name'] ?? '')) !== '') {
            $names[] = trim((string)$c['name']);
        }
    }
    return implode(', ', array_values(array_unique($names)));
}

// Au moins un Auteur ET un Éditeur — même règle que la création d'une série
// (admin.php), pour que le passage en collection ne bute jamais dessus.
function wishlist_contributors_are_complete(array $contributors): bool {
    return wishlist_contributors_names($contributors, 'auteur') !== ''
        && wishlist_contributors_names($contributors, 'editeur') !== '';
}

// ── Vignettes ────────────────────────────────────────────────────────────────

// Une URL de couverture distante n'est acceptée que si elle est en https et ne
// contient aucun caractère susceptible de sortir d'un attribut HTML.
function wishlist_sanitize_cover_url(?string $url): string {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (!preg_match('#^https://[^\s"\'<>`]+$#i', $url)) return '';
    return $url;
}

// Vignette affichable d'une entrée : chemin local (relatif à la racine du
// projet) si le fichier existe, sinon URL distante (couverture Anilist), sinon
// chaîne vide (au front d'afficher son espace réservé).
function wishlist_item_thumbnail(array $item): string {
    $image = trim((string)($item['image'] ?? ''));
    if ($image !== '' && file_exists($image)) return $image;
    return wishlist_sanitize_cover_url($item['cover_url'] ?? '');
}

// Supprime une vignette locale de la liste d'envies. Ne touche qu'à un
// fichier situé dans uploads/ (jamais un chemin arbitraire stocké en base).
function wishlist_delete_local_image(string $path): void {
    $path = trim($path);
    if ($path === '') return;
    $dir = rtrim(UPLOAD_DIR, '/') . '/';
    if (strpos($path, $dir) !== 0 || strpos($path, '..') !== false) return;
    if (is_file($path)) @unlink($path);
}

// Copie une vignette existante (série de la collection) vers un nouveau
// fichier de uploads/, pour qu'elle survive à la suppression de la série
// (« Déplacer dans la liste »). Renvoie le nouveau chemin, ou '' en cas
// d'échec — sans jamais bloquer le déplacement pour autant.
function wishlist_copy_image(string $source): string {
    $source = trim($source);
    if ($source === '' || !is_file($source)) return '';
    $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpeg', 'jpg', 'png', 'gif', 'webp'], true)) return '';
    $dir = rtrim(UPLOAD_DIR, '/') . '/';
    if (!is_dir($dir) || !is_writable($dir)) return '';
    $target = $dir . bin2hex(random_bytes(16)) . '.' . $ext;
    return @copy($source, $target) ? $target : '';
}

// Vignettes locales actuellement utilisées par la liste d'envies : à exclure
// du nettoyage des images orphelines.
function wishlist_used_images(): array {
    try {
        $rows = get_db()->query("SELECT image FROM wishlist WHERE image <> ''")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
    return array_values(array_filter(array_map('trim', $rows)));
}

// Télécharge une couverture Anilist dans uploads/ (via anime_download_cover(),
// fonctions/anime.php). Renvoie le chemin local, ou '' en cas d'échec ou si le
// téléchargeur n'est pas chargé par la page appelante.
function wishlist_download_anime_cover(string $cover_url): string {
    $cover_url = wishlist_sanitize_cover_url($cover_url);
    if ($cover_url === '' || !function_exists('anime_download_cover')) return '';
    $dl = anime_download_cover($cover_url);
    return !empty($dl['ok']) ? (string)$dl['path'] : '';
}

// Une entrée animée a-t-elle besoin d'un rattrapage depuis Anilist (pas de
// vignette locale, ou studio non renseigné) ?
function wishlist_anime_needs_backfill(array $item): bool {
    if (sanitize_series_type($item['type'] ?? '') !== 'anime') return false;
    if ((int)($item['anilist_id'] ?? 0) <= 0) return false;
    $image = trim((string)($item['image'] ?? ''));
    return $image === '' || !is_file($image) || trim((string)($item['studio'] ?? '')) === '';
}

// Rattrapage des entrées animées créées avant le téléchargement automatique
// des vignettes (ou importées sans studio par l'outil d'import Anilist) :
// interroge Anilist par lot (une seule requête pour $limit fiches, cache de
// 24 h compris), télécharge la couverture manquante et complète le studio
// vide. Le titre et l'identifiant ne sont jamais touchés.
//
// $skip_ids : identifiants Anilist déjà traités lors de ce passage (le front
// rappelle la fonction par lots successifs ; une fiche sans couverture ni
// studio côté Anilist n'est ainsi pas retentée en boucle).
//
// Retour : ['ok', 'processed' => [ids], 'remaining' => int, 'updated' => int, 'error']
function wishlist_anime_backfill(array $wishlist, array $skip_ids = [], int $limit = 10): array {
    $skip = array_flip(array_map('intval', $skip_ids));
    $todo = [];
    foreach ($wishlist as $item) {
        if (!wishlist_anime_needs_backfill($item)) continue;
        $aid = (int)$item['anilist_id'];
        if (isset($skip[$aid]) || isset($todo[$aid])) continue;
        $todo[$aid] = $item;
    }
    $remaining = max(0, count($todo) - $limit);
    $todo      = array_slice($todo, 0, $limit, true);

    if (empty($todo)) {
        return ['ok' => true, 'processed' => [], 'remaining' => 0, 'updated' => 0, 'error' => ''];
    }
    if (!function_exists('anilist_fetch_media_batch')) {
        return ['ok' => false, 'processed' => [], 'remaining' => 0, 'updated' => 0, 'error' => "Le connecteur Anilist n'est pas disponible."];
    }

    $fetch = anilist_fetch_media_batch(array_keys($todo));
    $media = (array)($fetch['media'] ?? []);

    $db      = get_db();
    $stmt    = $db->prepare("UPDATE wishlist SET studio = ?, cover_url = ?, image = ? WHERE type = 'anime' AND CAST(anilist_id AS INTEGER) = ?");
    $updated = 0;
    $done    = [];

    foreach ($todo as $aid => $item) {
        if (!isset($media[$aid])) {
            // Fiche absente de la réponse : introuvable (marquée traitée), ou
            // non récupérée à cause d'une erreur réseau / quota (laissée pour
            // un prochain passage).
            if (!empty($fetch['ok'])) $done[] = $aid;
            continue;
        }
        $m      = $media[$aid];
        $studio = trim((string)($item['studio'] ?? ''));
        if ($studio === '') $studio = trim((string)($m['studios_text'] ?? ''));
        $cover  = wishlist_sanitize_cover_url($m['cover'] ?? '') ?: (string)($item['cover_url'] ?? '');
        $image  = trim((string)($item['image'] ?? ''));
        if ($image === '' || !is_file($image)) {
            $image = wishlist_download_anime_cover($cover);
        }
        if ($studio !== ($item['studio'] ?? '') || $cover !== ($item['cover_url'] ?? '') || $image !== ($item['image'] ?? '')) {
            $stmt->execute([$studio, $cover, $image, $aid]);
            $updated++;
        }
        $done[] = $aid;
    }

    return [
        'ok'        => !empty($fetch['ok']),
        'processed' => $done,
        'remaining' => !empty($fetch['ok']) ? $remaining : 0,
        'updated'   => $updated,
        'error'     => !empty($fetch['ok']) ? '' : (string)($fetch['error'] ?? ''),
    ];
}

// ── Liens externes (MangaUpdates, Manga News, Syngas) ────────────────────────

// Valide et normalise les trois liens facultatifs d'une entrée manga.
// Retour : ['ok' => bool, 'message' => string, 'values' => [mu, mn, syngas]]
function wishlist_validate_links(string $mangaupdates_url, string $manganews_url, string $syngas_uid): array {
    $mangaupdates_url = trim($mangaupdates_url);
    $manganews_url    = trim($manganews_url);
    $syngas_uid       = strtolower(trim($syngas_uid));

    if ($mangaupdates_url !== '') {
        $clean = function_exists('mangaupdates_sanitize_url')
            ? mangaupdates_sanitize_url($mangaupdates_url)
            : (preg_match('#^https?://(?:www\.)?mangaupdates\.com/[^\s"\'<>`]*$#i', $mangaupdates_url) ? $mangaupdates_url : '');
        if ($clean === '') {
            return ['ok' => false, 'message' => 'URL MangaUpdates invalide (attendu : https://www.mangaupdates.com/series/xxxxxxx/nom-de-la-serie).', 'values' => []];
        }
        $mangaupdates_url = $clean;
    }

    if ($manganews_url !== '') {
        $norm = function_exists('manganews_normalize_url') ? manganews_normalize_url($manganews_url) : null;
        if ($norm === null) {
            return ['ok' => false, 'message' => 'URL Manga News invalide (attendu : https://www.manga-news.com/index.php/serie/Nom-de-la-serie).', 'values' => []];
        }
        $manganews_url = $norm;
    }

    if ($syngas_uid !== '' && !preg_match(WISHLIST_SYNGAS_UID_PATTERN, $syngas_uid)) {
        return ['ok' => false, 'message' => 'Identifiant Syngas invalide (attendu : un identifiant de la forme xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx).', 'values' => []];
    }

    return ['ok' => true, 'message' => '', 'values' => [$mangaupdates_url, $manganews_url, $syngas_uid]];
}

// ── Lecture / écriture ───────────────────────────────────────────────────────

// Construit une entrée « en mémoire » complète à partir d'une ligne SQL (ou
// d'un tableau de même forme). Point de passage unique : toutes les entrées
// manipulées par ce fichier ont exactement les mêmes clés.
function wishlist_build_item(array $r): array {
    $type = (isset($r['type']) && trim((string)$r['type']) !== '') ? sanitize_series_type($r['type']) : 'manga';
    $contributors = $type === 'manga' ? wishlist_contributors_from_row($r) : [];

    $item = [
        'name'             => (string)($r['name'] ?? ''),
        // Repli 'manga' : couvre les entrées créées avant le typage de la
        // liste d'envies.
        'type'             => $type,
        'author'           => $type === 'manga' ? (wishlist_contributors_names($contributors, 'auteur') ?: (string)($r['author'] ?? '')) : '',
        'publisher'        => $type === 'manga' ? (wishlist_contributors_names($contributors, 'editeur') ?: (string)($r['publisher'] ?? '')) : '',
        'studio'           => (string)($r['studio'] ?? ''),
        'anilist_id'       => (string)($r['anilist_id'] ?? ''),
        'contributors'     => $contributors,
        'image'            => (string)($r['image'] ?? ''),
        'cover_url'        => wishlist_sanitize_cover_url($r['cover_url'] ?? ''),
        'mangaupdates_url' => $type === 'manga' ? (string)($r['mangaupdates_url'] ?? '') : '',
        'manganews_url'    => $type === 'manga' ? (string)($r['manganews_url'] ?? '') : '',
        'syngas_uid'       => $type === 'manga' ? (string)($r['syngas_uid'] ?? '') : '',
    ];
    // Champ calculé, jamais écrit en base (cf. save_wishlist()).
    $item['thumb'] = wishlist_item_thumbnail($item);
    return $item;
}

// Charger la liste d'envies
function load_wishlist(): array {
    $db   = get_db();
    $rows = $db->query("SELECT * FROM wishlist ORDER BY id")->fetchAll();
    return array_map('wishlist_build_item', $rows);
}

// Paramètres SQL d'une entrée, dans l'ordre de WISHLIST_INSERT_SQL.
function wishlist_row_params(array $item): array {
    $type         = sanitize_series_type($item['type'] ?? '');
    $contributors = $type === 'manga' ? wishlist_sanitize_contributors($item['contributors'] ?? []) : [];
    return [
        (string)($item['name'] ?? ''),
        $type,
        $type === 'manga' ? (wishlist_contributors_names($contributors, 'auteur') ?: (string)($item['author'] ?? '')) : '',
        $type === 'manga' ? (wishlist_contributors_names($contributors, 'editeur') ?: (string)($item['publisher'] ?? '')) : '',
        $type === 'anime' ? (string)($item['studio'] ?? '') : '',
        $type === 'anime' ? (string)($item['anilist_id'] ?? '') : '',
        $contributors ? json_encode($contributors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        (string)($item['image'] ?? ''),
        wishlist_sanitize_cover_url($item['cover_url'] ?? ''),
        $type === 'manga' ? (string)($item['mangaupdates_url'] ?? '') : '',
        $type === 'manga' ? (string)($item['manganews_url'] ?? '') : '',
        $type === 'manga' ? (string)($item['syngas_uid'] ?? '') : '',
    ];
}

const WISHLIST_INSERT_SQL = "
    INSERT INTO wishlist (name, type, author, publisher, studio, anilist_id,
                          contributors, image, cover_url, mangaupdates_url, manganews_url, syngas_uid)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

// Sauvegarder la liste d'envies (remplacement complet)
function save_wishlist(array $wishlist): void {
    $db = get_db();
    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM wishlist");
        $stmt = $db->prepare(WISHLIST_INSERT_SQL);
        foreach ($wishlist as $item) {
            $stmt->execute(wishlist_row_params($item));
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// Identifiant SQL réel d'une entrée à partir de son index (la liste est
// ordonnée par id). null si l'index ne correspond à rien.
function wishlist_db_id_at(int $index): ?int {
    $ids = get_db()->query("SELECT id FROM wishlist ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    return isset($ids[$index]) ? (int)$ids[$index] : null;
}

// Sous-ensemble de la wishlist pour un type donné (affichage / filtre
// uniquement — même mise en garde que series_of_type() : ne jamais repasser
// ce tableau à save_wishlist(), qui écrit ce qu'on lui donne comme la
// collection complète).
function wishlist_of_type(array $wishlist, $type): array {
    $type = sanitize_series_type($type);
    return array_values(array_filter($wishlist, function ($item) use ($type) {
        return sanitize_series_type($item['type'] ?? '') === $type;
    }));
}

// Un manga du même nom existe-t-il déjà dans la liste ? ($exclude_index :
// entrée à ignorer, cas d'une édition qui garde son nom.)
function wishlist_manga_name_exists(array $wishlist, string $name, ?int $exclude_index = null): bool {
    foreach ($wishlist as $i => $item) {
        if ($exclude_index !== null && $i === $exclude_index) continue;
        if (sanitize_series_type($item['type'] ?? '') === 'manga' && strcasecmp(trim($item['name']), trim($name)) === 0) {
            return true;
        }
    }
    return false;
}

// Ajouter une entrée à la liste d'envies.
//
// $type = 'manga' : $author et $publisher (saisie rapide) OU $extra
// ['contributors'] (liste complète, prioritaire) — au moins un Auteur et un
// Éditeur dans tous les cas. Aucune recherche Anilist n'entre en jeu.
// $type = 'anime' : $studio remplace $author (pas d'éditeur), et
// $anilist_id doit provenir d'une fiche Anilist choisie (recherche par titre
// ou par identifiant) — jamais saisi librement.
//
// $extra (facultatif) : contributors, image (chemin local déjà téléversé),
// cover_url (animés), mangaupdates_url, manganews_url, syngas_uid (mangas —
// déjà validés par wishlist_validate_links() côté appelant).
//
// Anti-doublon : par anilist_id si l'entrée en a un (un animé ne se
// dédoublonne pas sur un nom, qui peut varier d'une fiche à l'autre), sinon
// par nom (insensible à la casse), comme auparavant.
function add_to_wishlist(
    array $wishlist,
    string $name,
    string $author,
    string $publisher,
    string $type = 'manga',
    string $studio = '',
    string $anilist_id = '',
    array $extra = []
): array {
    $type = sanitize_series_type($type);
    $name = trim($name);
    $anilist_id = ($type === 'anime') ? trim($anilist_id) : '';

    foreach ($wishlist as $item) {
        if ($anilist_id !== '' && (int)($item['anilist_id'] ?? 0) === (int)$anilist_id) {
            return ['success' => false, 'message' => "Cette série est déjà dans la liste d'envies."];
        }
        // Comparaison par nom : un manga et un animé homonymes ne sont pas un
        // doublon (ex. « One Piece » côté manga ET côté animé) — on exige donc
        // aussi le même type.
        if ($anilist_id === '' && strcasecmp(trim($item['name']), $name) === 0 && sanitize_series_type($item['type'] ?? '') === $type) {
            return ['success' => false, 'message' => "La série est déjà présente dans la liste d'envies."];
        }
    }

    $contributors = [];
    if ($type === 'anime') {
        if ($name === '' || $anilist_id === '') {
            return ['success' => false, 'message' => "Sélectionnez une série animée via la recherche Anilist."];
        }
    } else {
        $contributors = isset($extra['contributors'])
            ? wishlist_sanitize_contributors($extra['contributors'])
            : wishlist_contributors_from_row(['author' => trim($author), 'publisher' => trim($publisher)]);
        if ($name === '' || !wishlist_contributors_are_complete($contributors)) {
            return ['success' => false, 'message' => isset($extra['contributors'])
                ? "Veuillez renseigner le nom et au moins un contributeur « Auteur » et un « Éditeur »."
                : "Veuillez remplir le nom, l'auteur et l'éditeur."];
        }
    }

    // Animé : couverture Anilist téléchargée tout de suite (sauf vignette
    // déjà fournie, ex. copiée par « Déplacer dans la liste »). Un échec
    // n'empêche jamais l'ajout : cover_url reste en repli d'affichage, et le
    // rattrapage (wishlist_anime_backfill()) retentera plus tard.
    if ($type === 'anime' && trim((string)($extra['image'] ?? '')) === '') {
        $extra['image'] = wishlist_download_anime_cover((string)($extra['cover_url'] ?? ''));
    }

    $item = wishlist_build_item([
        'name'             => $name,
        'type'             => $type,
        'studio'           => $type === 'anime' ? $studio : '',
        'anilist_id'       => $anilist_id,
        'contributors'     => $contributors ? json_encode($contributors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        'image'            => (string)($extra['image'] ?? ''),
        'cover_url'        => (string)($extra['cover_url'] ?? ''),
        'mangaupdates_url' => (string)($extra['mangaupdates_url'] ?? ''),
        'manganews_url'    => (string)($extra['manganews_url'] ?? ''),
        'syngas_uid'       => (string)($extra['syngas_uid'] ?? ''),
    ]);

    get_db()->prepare(WISHLIST_INSERT_SQL)->execute(wishlist_row_params($item));

    $wishlist[] = $item;
    return ['success' => true, 'wishlist' => $wishlist];
}

// Éditer une entrée de la liste d'envies (ancien point d'entrée, conservé
// pour compatibilité : nom / auteur / éditeur pour un manga, studio pour un
// animé). La fiche complète d'un manga s'édite via save_wishlist_manga_entry().
//
// Le type d'une entrée ne se change pas ici : une entrée animée ne devient
// jamais un manga (et inversement) par simple édition. Pour un animé, seul
// le studio est éditable ; le nom et l'anilist_id restent ceux de la fiche
// Anilist choisie à l'ajout (Anilist fait autorité, y compris en wishlist).
function edit_wishlist_item(array $wishlist, int $index, string $name, string $author, string $publisher, string $studio = ''): array {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $db_id = wishlist_db_id_at($index);
    if ($db_id === null) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $type = sanitize_series_type($wishlist[$index]['type'] ?? '');

    if ($type === 'anime') {
        // Titre et anilist_id figés : seul le studio se corrige à la main
        // (un studio additionnel oublié par Anilist, par exemple).
        get_db()->prepare("UPDATE wishlist SET studio = ? WHERE id = ?")->execute([$studio, $db_id]);
        $wishlist[$index]['studio'] = $studio;
        return ['success' => true, 'wishlist' => $wishlist];
    }

    // Manga : les contributeurs Auteur / Éditeur sont remplacés par les
    // valeurs saisies, les autres rôles sont conservés.
    $others = array_values(array_filter(
        $wishlist[$index]['contributors'] ?? [],
        fn($c) => !in_array($c['role'] ?? '', ['auteur', 'editeur'], true)
    ));
    $contributors = array_merge(
        wishlist_contributors_from_row(['author' => $author, 'publisher' => $publisher]),
        $others
    );
    return save_wishlist_manga_entry($wishlist, $index, [
        'name'             => $name,
        'contributors'     => $contributors,
        'mangaupdates_url' => $wishlist[$index]['mangaupdates_url'] ?? '',
        'manganews_url'    => $wishlist[$index]['manganews_url'] ?? '',
        'syngas_uid'       => $wishlist[$index]['syngas_uid'] ?? '',
    ]);
}

// Créer ($index = null) ou mettre à jour une fiche manga complète de la liste
// d'envies (modale « Fiche de la liste d'envies »).
//
// $fields : name, contributors, mangaupdates_url, manganews_url, syngas_uid
// (déjà validés par wishlist_validate_links()), new_image (chemin local
// fraîchement téléversé, ou null), remove_image (bool).
//
// Vignette : une nouvelle image remplace (et supprime) l'ancienne ; « retirer
// la vignette » supprime le fichier. En cas d'échec de validation, la
// nouvelle image éventuelle est supprimée pour ne rien laisser d'orphelin.
function save_wishlist_manga_entry(array $wishlist, ?int $index, array $fields): array {
    $name         = trim((string)($fields['name'] ?? ''));
    $contributors = wishlist_sanitize_contributors($fields['contributors'] ?? []);
    $new_image    = $fields['new_image'] ?? null;

    $fail = function (string $message) use ($new_image) {
        if ($new_image) wishlist_delete_local_image($new_image);
        return ['success' => false, 'message' => $message];
    };

    if ($name === '') {
        return $fail('Le nom de la série est obligatoire.');
    }
    if (!wishlist_contributors_are_complete($contributors)) {
        return $fail('Au moins un contributeur avec le rôle « Auteur » et un avec le rôle « Éditeur » sont requis.');
    }
    if (wishlist_manga_name_exists($wishlist, $name, $index)) {
        return $fail("Une série manga de ce nom est déjà dans la liste d'envies.");
    }

    $extra = [
        'contributors'     => $contributors,
        'mangaupdates_url' => (string)($fields['mangaupdates_url'] ?? ''),
        'manganews_url'    => (string)($fields['manganews_url'] ?? ''),
        'syngas_uid'       => (string)($fields['syngas_uid'] ?? ''),
    ];

    // ── Création ──
    if ($index === null) {
        $extra['image'] = $new_image ?: '';
        $result = add_to_wishlist($wishlist, $name, '', '', 'manga', '', '', $extra);
        if (!$result['success'] && $new_image) wishlist_delete_local_image($new_image);
        return $result;
    }

    // ── Mise à jour ──
    if (!isset($wishlist[$index]) || sanitize_series_type($wishlist[$index]['type'] ?? '') !== 'manga') {
        return $fail('Entrée introuvable ou invalide.');
    }
    $db_id = wishlist_db_id_at($index);
    if ($db_id === null) {
        return $fail('Index invalide.');
    }

    $old_image = (string)($wishlist[$index]['image'] ?? '');
    $image     = $old_image;
    if ($new_image) {
        $image = $new_image;
    } elseif (!empty($fields['remove_image'])) {
        $image = '';
    }

    $item = wishlist_build_item([
        'name'             => $name,
        'type'             => 'manga',
        'contributors'     => json_encode($contributors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'image'            => $image,
        'mangaupdates_url' => $extra['mangaupdates_url'],
        'manganews_url'    => $extra['manganews_url'],
        'syngas_uid'       => $extra['syngas_uid'],
    ]);
    $p = wishlist_row_params($item);

    get_db()->prepare("
        UPDATE wishlist
           SET name = ?, author = ?, publisher = ?, contributors = ?, image = ?,
               mangaupdates_url = ?, manganews_url = ?, syngas_uid = ?
         WHERE id = ?
    ")->execute([$p[0], $p[2], $p[3], $p[6], $p[7], $p[9], $p[10], $p[11], $db_id]);

    if ($old_image !== '' && $old_image !== $image) {
        wishlist_delete_local_image($old_image);
    }

    $wishlist[$index] = $item;
    return ['success' => true, 'wishlist' => $wishlist];
}

// Supprimer une série de la liste d'envies.
// $delete_image = false : la vignette locale est conservée (cas où elle est
// reprise comme vignette de la série créée au passage en collection).
function remove_from_wishlist(array $wishlist, int $index, bool $delete_image = true): array {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $db_id = wishlist_db_id_at($index);
    if ($db_id === null) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    get_db()->prepare("DELETE FROM wishlist WHERE id = ?")->execute([$db_id]);
    if ($delete_image) {
        wishlist_delete_local_image((string)($wishlist[$index]['image'] ?? ''));
    }
    array_splice($wishlist, $index, 1);
    return ['success' => true, 'wishlist' => $wishlist];
}

// Ajouter une entrée de la liste d'envies à la collection principale.
//
// Manga (chemin historique, plus utilisé par page-wishlist.php qui passe par
// add_series_from_wishlist()) : insertion directe en base, un tome, statut
// « en cours », avec tous les contributeurs, liens et vignette de l'entrée.
//
// Animé : délègue à add_anime_from_wishlist(), qui importe intégralement la
// fiche depuis Anilist via l'anilist_id mémorisé.
//
// Retour : ['success', 'data'?, 'wishlist'?, 'message']
function add_from_wishlist(array $data, array $wishlist, int $index): array {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $item = $wishlist[$index];
    $type = sanitize_series_type($item['type'] ?? '');

    if ($type === 'anime') {
        return add_anime_from_wishlist($data, $wishlist, $index);
    }

    $name = $item['name'];

    // Un animé homonyme (ou l'inverse) n'est pas un doublon : ce sont deux
    // œuvres différentes qui partagent un titre. Cette branche ne traite que
    // le type 'manga' (voir plus haut), la comparaison se limite donc aux
    // séries mangas déjà en collection.
    foreach ($data as $existing_series) {
        if (strcasecmp($existing_series['name'], $name) === 0 && series_type($existing_series) === 'manga') {
            return ['success' => false, 'message' => 'Une série avec ce nom existe déjà dans votre collection.'];
        }
    }

    $new_id           = generate_uuid();
    $new_contributors = wishlist_sanitize_contributors($item['contributors'] ?? []);
    $image            = (trim((string)$item['image']) !== '' && is_file($item['image'])) ? $item['image'] : '';
    $db               = get_db();
    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO series (id, name, type, contributors, categories, image, status, mangaupdates_url, manganews_url)
            VALUES (?, ?, 'manga', ?, '', ?, 'en cours', ?, ?)
        ")->execute([
            $new_id, $name,
            json_encode($new_contributors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $image, $item['mangaupdates_url'] ?? '', $item['manganews_url'] ?? '',
        ]);

        $db->prepare("
            INSERT INTO volumes (series_id, number, status, collector, last, added_at)
            VALUES (?, 1, 'à lire', 0, 0, ?)
        ")->execute([$new_id, date('Y-m-d')]);

        $db_id = wishlist_db_id_at($index);
        if ($db_id !== null) {
            $db->prepare("DELETE FROM wishlist WHERE id = ?")->execute([$db_id]);
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    $data[] = [
        'id'                 => $new_id,
        'name'               => $name,
        'type'               => 'manga',
        'contributors'       => $new_contributors,
        'categories'         => [''],
        'genres'             => [''],
        'image'              => $image,
        'anilist_id'         => '',
        'mature'             => false,
        'favorite'           => false,
        'status'             => 'en cours',
        'mangaupdates_url'   => $item['mangaupdates_url'] ?? '',
        'manganews_url'      => $item['manganews_url'] ?? '',
        'volumes'            => [['number' => 1, 'status' => 'à lire', 'collector' => false, 'last' => false, 'added_at' => date('Y-m-d')]],
    ];
    array_splice($wishlist, $index, 1);
    return ['success' => true, 'data' => $data, 'wishlist' => $wishlist];
}

// ──────────────────────────────────────────────────────────────────────────────
// Déplacer une série de la collection vers la liste d'envies (mouvement
// inverse de add_from_wishlist()) : la série est retirée de sa collection
// (mêmes suppressions que delete_series() — vignette(s), tomes/épisodes en
// cascade) et une entrée équivalente est créée dans la liste d'envies,
// préremplie avec tout ce qui était déjà connu plutôt que de repartir d'une
// fiche vide à ressaisir :
//   • manga : tous les contributeurs, URL MangaUpdates / Manga News,
//     identifiant Syngas ;
//   • animé : studio et identifiant Anilist ;
//   • dans les deux cas, la vignette affichée est COPIÉE (avant la
//     suppression de la série) pour rester visible dans la liste d'envies.
//
// N'a AUCUN effet sur les prêts en cours : à l'appelant de vérifier au
// préalable (cf. series_has_active_loans() ci-dessous) et d'avertir l'admin
// avant d'appeler cette fonction — ce n'est pas cette fonction qui bloque,
// mais le point d'appel qui doit décider en connaissance de cause.
//
// Dépend de fonctions/series.php (delete_series(), find_series_by_id()) :
// ce fichier doit être chargé par l'appelant.
function move_series_to_wishlist(array $data, array $wishlist, string $series_id): array {
    $found = find_series_by_id($data, $series_id);
    if (!$found) {
        return ['success' => false, 'message' => 'Série introuvable.'];
    }
    $series = $found['data'];
    $type   = series_type($series);

    $name       = $series['name'];
    $studio     = ($type === 'anime') ? (function_exists('series_studios_text') ? series_studios_text($series) : '') : '';
    $anilist_id = ($type === 'anime') ? trim((string)($series['anilist_id'] ?? '')) : '';

    $extra = [];
    if ($type === 'manga') {
        $contributors = wishlist_sanitize_contributors(series_contributors($series));
        // Un manga déplacé doit garder un Auteur et un Éditeur pour rester
        // éditable ensuite (add_to_wishlist() les exige pour ce type) ; un
        // rôle manquant est simplement complété par un tiret plutôt que de
        // bloquer le déplacement pour une série mal renseignée à l'origine.
        if (wishlist_contributors_names($contributors, 'auteur') === '') {
            $contributors[] = ['name' => '—', 'role' => 'auteur', 'role_custom' => ''];
        }
        if (wishlist_contributors_names($contributors, 'editeur') === '') {
            $contributors[] = ['name' => '—', 'role' => 'editeur', 'role_custom' => ''];
        }
        $extra = [
            'contributors'     => $contributors,
            'mangaupdates_url' => (string)($series['mangaupdates_url'] ?? ''),
            'manganews_url'    => (string)($series['manganews_url'] ?? ''),
            'syngas_uid'       => (string)($series['syngas_uid'] ?? ''),
        ];
    }

    // Copie de la vignette AVANT delete_series(), qui supprime les fichiers.
    $thumb = function_exists('series_thumbnail') ? series_thumbnail($series, '') : trim((string)($series['image'] ?? ''));
    $extra['image'] = wishlist_copy_image($thumb);

    $add_result = add_to_wishlist($wishlist, $name, '', '', $type, $studio, $anilist_id, $extra);
    if (!$add_result['success']) {
        wishlist_delete_local_image($extra['image']);
        return ['success' => false, 'message' => $add_result['message']];
    }

    // Suppression de la collection : même mécanique que delete_series()
    // (vignette personnalisée + vignette Anilist supprimées, tomes/épisodes
    // et éditions physiques en cascade en base).
    $delete_result = delete_series($data, $series_id);
    if (!$delete_result['success']) {
        return ['success' => false, 'message' => $delete_result['message']];
    }

    return [
        'success'  => true,
        'data'     => $delete_result['data'],
        'wishlist' => $add_result['wishlist'],
        'message'  => "« $name » a été déplacée vers la liste d'envies.",
    ];
}

// Passage en collection d'une entrée manga : création complète de la série
// (mêmes champs que la modale d'ajout d'admin.php, gérée ici par
// add_series()) puis retrait de l'entrée de la wishlist — en une seule
// opération côté appelant, pour que l'entrée ne disparaisse de la liste
// d'envies QUE si la série a effectivement été créée. Les champs de la série
// sont fournis par l'appelant (saisis/édités dans la modale d'ajout,
// préremplie mais modifiable), pas relus depuis l'entrée elle-même.
//
// Vignette : si aucune nouvelle image n'est fournie ($series_fields['image']
// vide) et que $series_fields['use_wishlist_image'] est vrai, la vignette de
// l'entrée devient celle de la série (le fichier est alors conservé au
// retrait de l'entrée). Sinon, elle est supprimée avec l'entrée.
//
// $index doit correspondre à une entrée de type 'manga' : à l'appelant de le
// vérifier (cf. page-wishlist.php), cette fonction ne le revérifie pas.
//
// Dépend de fonctions/series.php (add_series()) : ce fichier doit être
// chargé par l'appelant.
function add_series_from_wishlist(array $data, array $wishlist, int $index, array $series_fields): array {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $image       = $series_fields['image'] ?? null;
    $keep_image  = false;
    $wl_image    = trim((string)($wishlist[$index]['image'] ?? ''));
    if (!$image && !empty($series_fields['use_wishlist_image']) && $wl_image !== '' && is_file($wl_image)) {
        $image      = $wl_image;
        $keep_image = true;
    }

    $result = add_series(
        $data,
        $series_fields['name'],
        $series_fields['contributors'],
        $series_fields['categories'],
        $series_fields['genres'],
        $series_fields['mangaupdates_url'],
        $series_fields['manganews_url'],
        $series_fields['mature'],
        $series_fields['favorite'],
        $series_fields['volumes_count'],
        $series_fields['volumes_status'],
        $series_fields['all_collector'],
        $series_fields['last_volume'],
        $image,
        $series_fields['status'],
        $series_fields['read_elsewhere'],
        $series_fields['reading_abandoned'],
        $series_fields['rating'],
        'manga',
        $series_fields['reread_count'],
        $series_fields['syngas_uid'] ?? ''
    );

    if (!$result['success']) {
        return ['success' => false, 'message' => $result['message'] ?? "L'ajout a échoué."];
    }

    $remove = remove_from_wishlist($wishlist, $index, !$keep_image);

    return [
        'success'   => true,
        'data'      => $result['data'],
        'wishlist'  => $remove['success'] ? $remove['wishlist'] : $wishlist,
        'message'   => $result['message'],
        // Dernière série ajoutée = la nouvelle (add_series() l'append) :
        // permet à l'appelant de connaître son id sans la rechercher par nom.
        'series_id' => $result['data'][count($result['data']) - 1]['id'] ?? '',
    ];
}

// La série a-t-elle au moins un tome actuellement prêté ? (mangas uniquement —
// les animés ne se prêtent jamais, cf. fonctions/loans.php). Sert à avertir
// l'admin avant un déplacement vers la liste d'envies, qui retire la série
// (et donc ses prêts en cours) de la collection.
function series_has_active_loans(string $series_id): bool {
    if (!function_exists('load_loans')) return false;
    foreach (load_loans() as $loan) {
        if (($loan['series_id'] ?? '') === $series_id) return true;
    }
    return false;
}

// Passage en collection d'une entrée animée : import complet depuis Anilist,
// sur le même moteur que l'ajout direct (fonctions/anime.php::add_anime_series()).
//
// Anilist fait autorité même ici : seul l'anilist_id mémorisé transite, la
// fiche est intégralement rechargée (le studio ou le titre affichés en
// wishlist ne servaient qu'à cet affichage-là, jamais réinjectés tels quels).
// Seule exception : la vignette locale de l'entrée, réutilisée telle quelle
// comme vignette Anilist de la série.
//
// Ne retire l'entrée de la wishlist qu'en cas de succès de l'import : un échec
// (série non encore diffusée entre-temps, Anilist injoignable, doublon
// détecté...) laisse l'entrée intacte plutôt que de la faire disparaître pour
// rien.
//
// Dépend de includes/anilist.php (anilist_fetch_media) et
// fonctions/anime.php (add_anime_series) : ces deux fichiers doivent être
// chargés par l'appelant.
function add_anime_from_wishlist(array $data, array $wishlist, int $index): array {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $item       = $wishlist[$index];
    $anilist_id = (int)($item['anilist_id'] ?? 0);
    if ($anilist_id <= 0) {
        return ['success' => false, 'message' => "Aucun identifiant Anilist mémorisé pour cette entrée."];
    }

    if (!function_exists('anilist_fetch_media') || !function_exists('add_anime_series')) {
        return ['success' => false, 'message' => "Le connecteur Anilist n'est pas disponible."];
    }

    $fetch = anilist_fetch_media($anilist_id);
    if (!$fetch['ok']) {
        return ['success' => false, 'message' => $fetch['error']];
    }

    // Vignette déjà téléchargée en liste d'envies : réutilisée telle quelle
    // comme vignette Anilist de la série (pas de second téléchargement).
    $wl_image   = trim((string)($item['image'] ?? ''));
    $reuse      = $wl_image !== '' && is_file($wl_image);

    $result = add_anime_series($data, $fetch['media'], !$reuse);
    if (!$result['success']) {
        return ['success' => false, 'message' => $result['message']];
    }

    if ($reuse && !empty($result['series_id'])) {
        get_db()->prepare("UPDATE series SET anilist_image = ? WHERE id = ?")->execute([$wl_image, $result['series_id']]);
    }

    $remove = remove_from_wishlist($wishlist, $index, !$reuse);
    return [
        'success'   => true,
        'data'      => $result['data'],
        'wishlist'  => $remove['success'] ? $remove['wishlist'] : $wishlist,
        'message'   => $result['message'],
        // Propagé jusqu'à page-wishlist.php pour la redirection vers
        // admin.php avec la modale d'édition de cette série déjà ouverte.
        'series_id' => $result['series_id'] ?? '',
    ];
}
