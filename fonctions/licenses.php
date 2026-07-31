<?php
// ──────────────────────────────────────────────────────────────────────────────
// fonctions/licenses.php
// Gestion des « Licences » : regroupements libres de séries (mangas et/ou
// animés) sous un même nom choisi par l'utilisateur (ex. « Frieren » regroupant
// le manga et les saisons animées). Une série n'appartient jamais qu'à une
// seule licence à la fois : l'ajouter à une licence B la retire silencieusement
// d'une éventuelle licence A.
//
// Schéma (SQLite, créé de façon idempotente comme reviews_init_table()) :
//   licenses(id TEXT PK, name TEXT, created_at TEXT, updated_at TEXT)
//   license_series(license_id TEXT, series_id TEXT UNIQUE, position INTEGER)
// `series_id` est UNIQUE : c'est ce qui garantit qu'une série n'est jamais
// membre de deux licences en même temps (INSERT OR REPLACE suffit à la
// déplacer d'une licence à l'autre). `updated_at` est mis à jour par
// touch_license() à chaque modification perçue par l'utilisateur (renommage,
// ajout/retrait/réordonnancement d'une série) ; il alimente le tri « dernière
// modification » de la page de gestion des licences.
// ──────────────────────────────────────────────────────────────────────────────

// ── Création des tables (idempotent) ─────────────────────────────────────────
function licenses_init_tables(): void {
    $db = get_db();
    $db->exec("
        CREATE TABLE IF NOT EXISTS licenses (
            id          TEXT PRIMARY KEY,
            name        TEXT NOT NULL,
            created_at  TEXT NOT NULL DEFAULT ''
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS license_series (
            license_id  TEXT NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
            series_id   TEXT NOT NULL UNIQUE REFERENCES series(id) ON DELETE CASCADE,
            position    INTEGER NOT NULL DEFAULT 0
        )
    ");

    // Migration : colonne `updated_at`, ajoutée après coup (tri « dernière
    // modification »). Idempotente comme les ALTER TABLE de config.php : sur
    // une base déjà migrée, la colonne existe déjà et l'ALTER échoue
    // silencieusement (capturé par le try/catch).
    try {
        $db->exec("ALTER TABLE licenses ADD COLUMN updated_at TEXT NOT NULL DEFAULT ''");
        // Sur une base préexistante, initialise updated_at à created_at pour
        // que le tri « dernière modification » ait un point de départ sensé
        // plutôt qu'une chaîne vide en tête ou en fin de liste.
        $db->exec("UPDATE licenses SET updated_at = created_at WHERE updated_at = ''");
    } catch (Exception $e) { /* colonne déjà présente */ }
}

// Marque une licence comme modifiée maintenant (nom ou contenu). Appelée par
// toute action qui change ce que l'utilisateur perçoit comme « éditer la
// licence » : renommage, ajout/retrait/réordonnancement d'une série. Pas par
// create_license(), où created_at fait déjà foi.
function touch_license(string $license_id): void {
    $db = get_db();
    $db->prepare("UPDATE licenses SET updated_at = ? WHERE id = ?")
       ->execute([date('Y-m-d H:i:s'), $license_id]);
}

// ── Résolution de la vignette d'une licence ──────────────────────────────────
// Parcourt les séries membres, DÉJÀ TRIÉES par position, et renvoie la
// vignette de la première qui en possède une valide (cascade image ->
// anilist_image -> rien). Si aucune série membre n'a de vignette, on retombe
// sur le logo par défaut du site — jamais une image cassée.
function license_thumbnail(array $member_series, string $default = 'assets/img/logo.png'): string {
    foreach ($member_series as $s) {
        foreach (['image', 'anilist_image'] as $field) {
            $path = trim((string)($s[$field] ?? ''));
            if ($path !== '' && file_exists($path)) {
                return $path;
            }
        }
    }
    return $default;
}

// ── Liste de toutes les licences, avec métadonnées d'affichage ───────────────
// $data doit contenir TOUTES les séries (mangas + animés), exactement comme
// list_reviews() : pas de tableau déjà cloisonné par type.
//
// $sort_by  : 'name' | 'created_at' | 'updated_at' | 'count' (défaut 'created_at')
// $sort_order : 'asc' | 'desc' (défaut 'desc', comportement historique)
function list_licenses(array $data, string $sort_by = 'created_at', string $sort_order = 'desc'): array {
    licenses_init_tables();
    $db = get_db();

    $licenses = $db->query("SELECT id, name, created_at, updated_at FROM licenses")->fetchAll();
    if (empty($licenses)) return [];

    $by_id = [];
    foreach ($data as $s) {
        $by_id[$s['id']] = $s;
    }

    $rows = $db->query("SELECT license_id, series_id, position FROM license_series ORDER BY license_id, position, series_id")->fetchAll();
    $members_by_license = [];
    foreach ($rows as $r) {
        if (!isset($by_id[$r['series_id']])) continue; // série supprimée entre-temps
        $members_by_license[$r['license_id']][] = $by_id[$r['series_id']];
    }

    $result = [];
    foreach ($licenses as $lic) {
        $members = $members_by_license[$lic['id']] ?? [];
        $result[] = [
            'id'           => $lic['id'],
            'name'         => $lic['name'],
            'created_at'   => $lic['created_at'],
            'updated_at'   => $lic['updated_at'] ?: $lic['created_at'],
            'count'        => count($members),
            'thumbnail'    => license_thumbnail($members),
            // Noms des séries membres : permet à la recherche de la page de
            // gestion des licences de matcher aussi sur le contenu d'une
            // licence, pas seulement sur son propre nom.
            'series_names' => array_values(array_map(fn($s) => $s['name'], $members)),
        ];
    }

    sort_licenses($result, $sort_by, $sort_order);

    return $result;
}

// Tri en place de la liste des licences (déjà décorées par list_licenses()).
// Fait en PHP plutôt qu'en SQL : 'count' n'existe qu'une fois les séries
// membres jointes et comptées, un ORDER BY SQL direct serait plus complexe
// pour un gain nul vu le nombre de licences en jeu (jamais plus que quelques
// dizaines/centaines).
function sort_licenses(array &$licenses, string $sort_by, string $sort_order): void {
    $sort_by    = in_array($sort_by, ['name', 'created_at', 'updated_at', 'count'], true) ? $sort_by : 'created_at';
    $sort_order = ($sort_order === 'asc') ? 'asc' : 'desc';

    usort($licenses, function ($a, $b) use ($sort_by) {
        if ($sort_by === 'name') {
            return strnatcasecmp($a['name'], $b['name']);
        }
        if ($sort_by === 'count') {
            return $a['count'] <=> $b['count'];
        }
        // created_at / updated_at : chaînes 'Y-m-d H:i:s', comparables telles quelles.
        return strcmp((string)$a[$sort_by], (string)$b[$sort_by]);
    });

    if ($sort_order === 'desc') {
        $licenses = array_reverse($licenses);
    }
}

// ── Détail d'une licence : ses séries membres, dans l'ordre, décorées ───────
// pour l'affichage (vignette déjà arbitrée, studios en texte, etc.).
function get_license_detail(array $data, string $license_id): ?array {
    licenses_init_tables();
    $db = get_db();

    $stmt = $db->prepare("SELECT id, name, created_at FROM licenses WHERE id = ?");
    $stmt->execute([$license_id]);
    $lic = $stmt->fetch();
    if (!$lic) return null;

    $by_id = [];
    foreach ($data as $s) {
        $by_id[$s['id']] = $s;
    }

    $stmt = $db->prepare("SELECT series_id, position FROM license_series WHERE license_id = ? ORDER BY position, series_id");
    $stmt->execute([$license_id]);
    $rows = $stmt->fetchAll();

    $members = [];
    foreach ($rows as $r) {
        if (!isset($by_id[$r['series_id']])) continue;
        $s = decorate_series_for_display($by_id[$r['series_id']]);
        $members[] = [
            'id'       => $s['id'],
            'name'     => $s['name'],
            'type'     => series_type($s),
            'author'   => is_anime($s) ? series_studios_text($s) : (string)($s['author'] ?? ''),
            'category' => is_anime($s) ? ($s['format_label'] ?? '') : (string)(($s['categories'][0] ?? '') ?: ''),
            'image'    => $s['thumbnail'],
        ];
    }

    return [
        'id'         => $lic['id'],
        'name'       => $lic['name'],
        'created_at' => $lic['created_at'],
        'series'     => $members,
    ];
}

// ── Liste des séries éligibles à rejoindre une licence ───────────────────────
// Une série n'est éligible que si elle est EN COLLECTION (elle est forcément
// dans $data, qui vient de load_data()) et n'appartient à AUCUNE licence pour
// l'instant — sauf si elle appartient déjà à $exclude_license_id : dans ce cas
// on l'exclut simplement de la liste "à ajouter" (elle y est déjà).
function get_licensable_series(array $data, string $exclude_license_id = ''): array {
    licenses_init_tables();
    $db = get_db();

    $rows = $db->query("SELECT series_id, license_id FROM license_series")->fetchAll();
    $taken = [];
    foreach ($rows as $r) {
        if ($exclude_license_id !== '' && $r['license_id'] === $exclude_license_id) continue;
        $taken[$r['series_id']] = true;
    }

    $out = [];
    foreach ($data as $s) {
        if (isset($taken[$s['id']])) continue;
        $out[] = [
            'id'     => $s['id'],
            'name'   => $s['name'],
            'type'   => series_type($s),
            'author' => is_anime($s) ? series_studios_text($s) : (string)($s['author'] ?? ''),
            // Vignette déjà résolue (perso -> Anilist -> défaut) : permet au
            // front de mettre à jour la vignette de la carte de licence dès
            // l'ajout d'une série, sans attendre un rechargement de page.
            'image'  => series_thumbnail($s),
        ];
    }
    return $out;
}

// ── Création d'une licence ───────────────────────────────────────────────────
function create_license(string $name): array {
    licenses_init_tables();
    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'message' => 'Le nom de la licence est vide.'];
    }
    if (mb_strlen($name) > 120) {
        $name = mb_substr($name, 0, 120);
    }

    $id = generate_uuid();
    $db = get_db();
    $now = date('Y-m-d H:i:s');
    $db->prepare("INSERT INTO licenses (id, name, created_at, updated_at) VALUES (?, ?, ?, ?)")
       ->execute([$id, $name, $now, $now]);

    return ['success' => true, 'message' => 'Licence créée.', 'id' => $id];
}

// ── Renommage d'une licence ──────────────────────────────────────────────────
function rename_license(string $license_id, string $name): array {
    licenses_init_tables();
    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'message' => 'Le nom de la licence est vide.'];
    }
    if (mb_strlen($name) > 120) {
        $name = mb_substr($name, 0, 120);
    }

    $db   = get_db();
    $stmt = $db->prepare("SELECT id FROM licenses WHERE id = ?");
    $stmt->execute([$license_id]);
    if (!$stmt->fetch()) {
        return ['success' => false, 'message' => 'Licence introuvable.'];
    }

    $db->prepare("UPDATE licenses SET name = ? WHERE id = ?")->execute([$name, $license_id]);
    touch_license($license_id);
    return ['success' => true, 'message' => 'Licence renommée.'];
}

// ── Suppression d'une licence ────────────────────────────────────────────────
// Les entrées de license_series partent avec (ON DELETE CASCADE) ; les séries
// elles-mêmes ne sont jamais touchées, seul le regroupement disparaît.
function delete_license(string $license_id): array {
    licenses_init_tables();
    $db   = get_db();
    $stmt = $db->prepare("SELECT id FROM licenses WHERE id = ?");
    $stmt->execute([$license_id]);
    if (!$stmt->fetch()) {
        return ['success' => false, 'message' => 'Licence introuvable.'];
    }
    $db->prepare("DELETE FROM licenses WHERE id = ?")->execute([$license_id]);
    return ['success' => true, 'message' => 'Licence supprimée.'];
}

// ── Ajout d'une série à une licence ──────────────────────────────────────────
// $data sert à vérifier que la série existe bien en collection. La série est
// ajoutée en dernière position. Comme series_id est UNIQUE dans
// license_series, un INSERT OR REPLACE déplace automatiquement la série
// depuis une éventuelle autre licence — cohérent avec la règle « une seule
// licence par série ».
function add_series_to_license(array $data, string $license_id, string $series_id): array {
    licenses_init_tables();

    $exists = false;
    foreach ($data as $s) {
        if ($s['id'] === $series_id) { $exists = true; break; }
    }
    if (!$exists) {
        return ['success' => false, 'message' => "Cette série n'existe pas dans votre collection."];
    }

    $db   = get_db();
    $stmt = $db->prepare("SELECT id FROM licenses WHERE id = ?");
    $stmt->execute([$license_id]);
    if (!$stmt->fetch()) {
        return ['success' => false, 'message' => 'Licence introuvable.'];
    }

    $stmt = $db->prepare("SELECT COALESCE(MAX(position), -1) AS maxpos FROM license_series WHERE license_id = ?");
    $stmt->execute([$license_id]);
    $next_position = (int)$stmt->fetch()['maxpos'] + 1;

    $db->prepare("INSERT OR REPLACE INTO license_series (license_id, series_id, position) VALUES (?, ?, ?)")
       ->execute([$license_id, $series_id, $next_position]);
    touch_license($license_id);

    return ['success' => true, 'message' => 'Série ajoutée à la licence.'];
}

// ── Retrait d'une série d'une licence ────────────────────────────────────────
function remove_series_from_license(string $license_id, string $series_id): array {
    licenses_init_tables();
    $db = get_db();
    $db->prepare("DELETE FROM license_series WHERE license_id = ? AND series_id = ?")
       ->execute([$license_id, $series_id]);
    touch_license($license_id);
    return ['success' => true, 'message' => 'Série retirée de la licence.'];
}

// ── Réordonnancement des séries d'une licence ────────────────────────────────
// $ordered_series_ids : liste complète des identifiants de séries de la
// licence, dans le nouvel ordre souhaité. Toute entrée absente de la liste
// (incohérence front/back) conserve sa position actuelle plutôt que de
// disparaître silencieusement.
function reorder_license_series(string $license_id, array $ordered_series_ids): array {
    licenses_init_tables();
    $db = get_db();

    $stmt = $db->prepare("SELECT id FROM licenses WHERE id = ?");
    $stmt->execute([$license_id]);
    if (!$stmt->fetch()) {
        return ['success' => false, 'message' => 'Licence introuvable.'];
    }

    $update = $db->prepare("UPDATE license_series SET position = ? WHERE license_id = ? AND series_id = ?");
    $position = 0;
    foreach ($ordered_series_ids as $series_id) {
        $series_id = trim((string)$series_id);
        if ($series_id === '') continue;
        $update->execute([$position, $license_id, $series_id]);
        $position++;
    }
    touch_license($license_id);

    return ['success' => true, 'message' => 'Ordre mis à jour.'];
}

// ── Totaux d'affichage (page de gestion des licences) ───────────────────────
// Nombre total de licences et nombre total de séries regroupées dans une
// licence (tous types confondus, chaque série ne comptant qu'une fois — elle
// n'appartient jamais à plus d'une licence à la fois, cf. tête de fichier).
function license_totals(): array {
    licenses_init_tables();
    $db = get_db();

    $licenses_count = (int)$db->query("SELECT COUNT(*) AS n FROM licenses")->fetch()['n'];
    $series_count   = (int)$db->query("SELECT COUNT(DISTINCT series_id) AS n FROM license_series")->fetch()['n'];

    return ['licenses_count' => $licenses_count, 'series_count' => $series_count];
}

// ── Identifiant de licence d'une série, ou '' si elle n'en a aucune ──────────
// Utilisé par index.php pour décorer les séries envoyées au front public
// (bouton « Licence » de la modale de détail).
function get_series_license_map(): array {
    licenses_init_tables();
    $db   = get_db();
    $rows = $db->query("
        SELECT ls.series_id, ls.license_id, l.name
        FROM license_series ls
        JOIN licenses l ON l.id = ls.license_id
    ")->fetchAll();

    $map = [];
    foreach ($rows as $r) {
        $map[$r['series_id']] = ['license_id' => $r['license_id'], 'license_name' => $r['name']];
    }
    return $map;
}

// ── Détail public d'une licence (endpoint index.php) ─────────────────────────
// Version allégée de get_license_detail(), pensée pour le front public : pas
// de champ superflu, respecte le mode privé / masquage mature de la
// collection appelante (filtrage fait par l'appelant, cette fonction ne fait
// que lire et mettre en forme).
function get_public_license_series(array $data, string $license_id): array {
    return get_license_detail($data, $license_id)['series'] ?? [];
}
