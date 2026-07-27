<?php
// Configuration du site
define('SITE_VERSION', '3.9.1');
define('URL_GITEA', 'https://git.crystalyx.net/Esenjin_Asakha/Lengas');

// Chemin vers la base de données SQLite
define('DB_FILE', 'bdd/lengas.db');

// Chemin vers le dossier d'upload
define('UPLOAD_DIR', 'uploads/');

// ──────────────────────────────────────────────────────────────────────────────
// Connexion PDO SQLite (singleton)
// ──────────────────────────────────────────────────────────────────────────────
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!file_exists('bdd')) {
            mkdir('bdd', 0774, true);
        }
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Performances SQLite
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        init_db($pdo);
    }
    return $pdo;
}

// ──────────────────────────────────────────────────────────────────────────────
// Initialisation du schéma
// ──────────────────────────────────────────────────────────────────────────────
function init_db(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS series (
            id          TEXT PRIMARY KEY,
            name        TEXT NOT NULL,
            author      TEXT NOT NULL DEFAULT '',
            publisher   TEXT NOT NULL DEFAULT '',
            other_contributors TEXT NOT NULL DEFAULT '',
            categories  TEXT NOT NULL DEFAULT '',
            genres      TEXT NOT NULL DEFAULT '',
            image       TEXT NOT NULL DEFAULT '',
            anilist_id  TEXT NOT NULL DEFAULT '',
            mature      INTEGER NOT NULL DEFAULT 0,
            favorite    INTEGER NOT NULL DEFAULT 0,
            status      TEXT NOT NULL DEFAULT 'en cours'
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS volumes (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            series_id   TEXT NOT NULL REFERENCES series(id) ON DELETE CASCADE,
            number      INTEGER NOT NULL,
            status      TEXT NOT NULL DEFAULT 'à lire',
            collector   INTEGER NOT NULL DEFAULT 0,
            last        INTEGER NOT NULL DEFAULT 0,
            added_at    TEXT NOT NULL DEFAULT '',
            read_at     TEXT NOT NULL DEFAULT '',
            UNIQUE(series_id, number)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wishlist (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL,
            author      TEXT NOT NULL DEFAULT '',
            publisher   TEXT NOT NULL DEFAULT ''
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS loans (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            series_id       TEXT NOT NULL,
            volume_number   INTEGER NOT NULL,
            borrower_name   TEXT NOT NULL,
            loan_date       TEXT NOT NULL,
            UNIQUE(series_id, volume_number)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS read_elsewhere (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            name            TEXT NOT NULL,
            author          TEXT NOT NULL DEFAULT '',
            publisher       TEXT NOT NULL DEFAULT '',
            volumes_read    INTEGER NOT NULL DEFAULT 0,
            status          TEXT NOT NULL DEFAULT '',
            added_at        TEXT NOT NULL DEFAULT ''
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS options (
            key     TEXT PRIMARY KEY,
            value   TEXT NOT NULL DEFAULT ''
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password (
            id      INTEGER PRIMARY KEY CHECK (id = 1),
            hash    TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sessions (
            id          TEXT PRIMARY KEY,
            data        TEXT NOT NULL DEFAULT '',
            last_active INTEGER NOT NULL DEFAULT 0
        )
    ");

    // ── Colonne mangaupdates_url (URL de référence + source du nombre de tomes) ─
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN mangaupdates_url TEXT NOT NULL DEFAULT ''");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Colonne babelio_url (fiche série Babelio, source du décompte VF) ──────
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN babelio_url TEXT NOT NULL DEFAULT ''");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Colonne read_elsewhere (séries lues ailleurs intégrées à la biblio) ────
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN read_elsewhere INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Colonne reading_abandoned (lecture abandonnée par l'utilisateur) ────────
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN reading_abandoned INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Colonne type (typage des séries : manga, anime, …) ─────────────────────
    // Le registre des types vit dans includes/helpers.php. Le défaut 'manga'
    // assure la rétro-compatibilité : toute série antérieure à la V4 en relève.
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN type TEXT NOT NULL DEFAULT 'manga'");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Colonne rating (notation subjective : apprecie / mitige / deteste) ──────
    // Valeur vide = pas de note.
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN rating TEXT NOT NULL DEFAULT ''");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Colonne read_at (date de passage au statut "terminé" d'un tome) ────────
    try {
        $pdo->exec("ALTER TABLE volumes ADD COLUMN read_at TEXT NOT NULL DEFAULT ''");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Cache des appels à l'API MangaUpdates (clé = series_id numérique) ─────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mangaupdates_cache (
            series_id    TEXT PRIMARY KEY,
            volumes      INTEGER,
            status_text  TEXT,
            timestamp    INTEGER NOT NULL
        )
    ");

    // ── Cache des décomptes Babelio remontés par Babengas ─────────────────────
    // nb_tomes = tomes RÉELLEMENT PARUS (après décrémentation des tomes à
    // paraître) ; nb_reference = ce qu'annonce la fiche Babelio, conservé pour
    // information. Les échecs ne sont jamais mis en cache.
    //
    // Pas de colonne « statut » : Babelio affiche « En cours » y compris sur des
    // séries terminées depuis des années. Le statut reste géré par MangaUpdates
    // ou saisi à la main.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS babelio_cache (
            serie_id     TEXT PRIMARY KEY,
            url          TEXT,
            nb_tomes     INTEGER,
            nb_reference INTEGER,
            incertain    INTEGER NOT NULL DEFAULT 0,
            erreur       TEXT,
            timestamp    INTEGER NOT NULL
        )
    ");

    // ── Cache des fiches Anilist (clé = anilist_id) ───────────────────────────
    // Refonte V4 : une table `anilist_cache` existait déjà (colonnes cache_key /
    // volumes), vestige de l'intégration Anilist abandonnée en 3.1. Son schéma ne
    // convient pas au connecteur d'animés, et son contenu n'a plus aucune valeur :
    // on la remplace purement et simplement. Un cache se reconstruit tout seul,
    // la suppression est donc sans conséquence.
    //
    // `payload` contient la fiche NORMALISÉE (JSON) telle que produite par
    // includes/anilist.php, et non la réponse brute de l'API.
    try {
        $cols  = $pdo->query("PRAGMA table_info(anilist_cache)")->fetchAll();
        $names = [];
        foreach ($cols as $col) { $names[] = $col['name']; }
        if (!empty($names) && !in_array('anilist_id', $names, true)) {
            $pdo->exec("DROP TABLE anilist_cache");
        }
    } catch (Exception $e) { /* table absente : rien à migrer */ }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS anilist_cache (
            anilist_id  INTEGER PRIMARY KEY,
            payload     TEXT NOT NULL DEFAULT '',
            timestamp   INTEGER NOT NULL DEFAULT 0
        )
    ");

    // Options par défaut si la table est vide
    $count = $pdo->query("SELECT COUNT(*) FROM options")->fetchColumn();
    if ((int)$count === 0) {
        $defaults = [
            'site_name'        => 'Lengas',
            'site_description' => "Gestion de la collection de mangas d'Esenjin.",
            'index_page_title' => "Lengas - La mangathèque d'Esenjin !",
            'admin_page_title' => 'Gestion de ma collection',
            'stats_page_title' => 'Statistiques de Lengas',
            'private_mode'          => '0',
            'hide_mature'           => '0',
            // Thème du site (clé) : 'dark' (sombre, par défaut) ou 'light', etc.
            'theme'                 => 'dark',
            // Réglages "Statistiques" : temps & valeur moyens par tome (repli global)
            'stats_default_minutes'         => '40',
            'stats_default_value'           => '7',
            'stats_default_value_collector' => '15',
            'stats_category_settings'       => '{}',
            // ── Babengas (vérification du décompte VF via Babelio) ──
            // Vides = intégration inactive, exactement comme Vestikan.
            'babengas_url'                  => '',
            'babengas_key'                  => '',
            'babengas_enabled'              => '0',
        ];
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO options (key, value) VALUES (?, ?)");
        foreach ($defaults as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Typage rétroactif des séries (V4)
// ──────────────────────────────────────────────────────────────────────────────
// Les bases antérieures à la V4 contiennent des séries sans type. On les rattache
// une bonne fois à 'manga', silencieusement, à la première visite de admin.php.
// L'opération est marquée dans les options pour ne pas se rejouer à chaque page ;
// restaurer une ancienne base réinitialise ce marqueur avec elle, et la migration
// se refera d'elle-même.
function backfill_series_types(): void {
    try {
        $db = get_db();

        $done = $db->query("SELECT value FROM options WHERE key = 'series_types_migrated'")
                   ->fetchColumn();
        if ($done === '1') {
            return;
        }

        $db->exec("UPDATE series SET type = 'manga' WHERE type IS NULL OR TRIM(type) = ''");
        $db->prepare("INSERT OR REPLACE INTO options (key, value) VALUES ('series_types_migrated', '1')")
           ->execute();
    } catch (Exception $e) {
        // Migration non bloquante : une base non typée reste lisible, load_data()
        // repliant de toute façon sur 'manga'.
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Gestionnaire de sessions SQLite
// ──────────────────────────────────────────────────────────────────────────────
class SqliteSessionHandler implements SessionHandlerInterface {
    private PDO $db;
    private int $lifetime;

    public function __construct(PDO $db, int $lifetime) {
        $this->db       = $db;
        $this->lifetime = $lifetime;
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false {
        $stmt = $this->db->prepare(
            "SELECT data FROM sessions WHERE id = ? AND last_active >= ?"
        );
        $stmt->execute([$id, time() - $this->lifetime]);
        $row = $stmt->fetch();
        return $row ? $row['data'] : '';
    }

    public function write(string $id, string $data): bool {
        $stmt = $this->db->prepare(
            "INSERT INTO sessions (id, data, last_active)
             VALUES (?, ?, ?)
             ON CONFLICT(id) DO UPDATE SET data = excluded.data, last_active = excluded.last_active"
        );
        return $stmt->execute([$id, $data, time()]);
    }

    public function destroy(string $id): bool {
        return $this->db->prepare("DELETE FROM sessions WHERE id = ?")
                        ->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false {
        $stmt = $this->db->prepare(
            "DELETE FROM sessions WHERE last_active < ?"
        );
        $stmt->execute([time() - $this->lifetime]);
        return $stmt->rowCount();
    }
}

/**
 * A appeler avant tout session_start().
 * Configure le handler SQLite + les parametres du cookie (7 jours, HTTPS).
 */
function register_session_handler(): void {
    $lifetime = 7 * 24 * 60 * 60; // 7 jours
    $handler  = new SqliteSessionHandler(get_db(), $lifetime);
    session_set_save_handler($handler, true);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Ré-émet le cookie de session avec un délai de 7 jours à partir de MAINTENANT.
 *
 * PHP n'envoie le cookie de session qu'une seule fois (à la création) ; il ne le
 * prolonge jamais tout seul. Appelée sur chaque requête authentifiée, cette
 * fonction fait « glisser » le cookie côté navigateur, en phase avec last_active
 * (mis à jour côté serveur par SqliteSessionHandler::write()). Sans elle, le
 * cookie expire 7 jours après la connexion quelle que soit l'activité.
 *
 * On reprend les paramètres (path, domaine, secure, httponly, samesite) posés
 * par register_session_handler() pour ne pas créer de cookie divergent.
 */
function refresh_session_cookie(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $lifetime = 7 * 24 * 60 * 60; // 7 jours (identique à register_session_handler())
    $p = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires'  => time() + $lifetime,
        'path'     => $p['path']   !== '' ? $p['path'] : '/',
        'domain'   => $p['domain'] ?? '',
        'secure'   => (bool)($p['secure']   ?? false),
        'httponly' => (bool)($p['httponly'] ?? true),
        'samesite' => ($p['samesite'] ?? '') !== '' ? $p['samesite'] : 'Lax',
    ]);
}

// ──────────────────────────────────────────────────────────────────────────────
// Mot de passe
// ──────────────────────────────────────────────────────────────────────────────
function load_password_hash(): ?string {
    try {
        $db  = get_db();
        $row = $db->query("SELECT hash FROM password WHERE id = 1")->fetch();
        return $row ? $row['hash'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function check_password(string $password): bool {
    $hash = load_password_hash();
    return $hash !== null && password_verify($password, $hash);
}

function save_password_hash(string $hash): void {
    $db = get_db();
    $db->prepare("INSERT OR REPLACE INTO password (id, hash) VALUES (1, ?)")->execute([$hash]);
}

// ──────────────────────────────────────────────────────────────────────────────
// Séries — chargement / sauvegarde (compatibilité avec l'existant)
// Les données sont renvoyées sous la même forme de tableau PHP qu'avant.
// ──────────────────────────────────────────────────────────────────────────────
function load_data(): array {
    $db      = get_db();
    $series  = $db->query("SELECT * FROM series ORDER BY rowid")->fetchAll();
    $volStmt = $db->prepare("SELECT * FROM volumes WHERE series_id = ? ORDER BY number");

    $result = [];
    foreach ($series as $s) {
        $volStmt->execute([$s['id']]);
        $vols = [];
        foreach ($volStmt->fetchAll() as $v) {
            $vols[] = [
                'number'    => (int)$v['number'],
                'status'    => $v['status'],
                'collector' => (bool)$v['collector'],
                'last'      => (bool)$v['last'],
                'added_at'  => $v['added_at'],
                'read_at'   => $v['read_at'] ?? '',
            ];
        }
        $result[] = [
            'id'                 => $s['id'],
            'name'               => $s['name'],
            // Repli sur 'manga' : couvre les bases non encore migrées.
            'type'               => (isset($s['type']) && trim($s['type']) !== '') ? $s['type'] : 'manga',
            'author'             => $s['author'],
            'publisher'          => $s['publisher'],
            'other_contributors' => $s['other_contributors'] !== '' ? explode(',', $s['other_contributors']) : [''],
            'categories'         => $s['categories']  !== '' ? explode(',', $s['categories'])  : [''],
            'genres'             => $s['genres']       !== '' ? explode(',', $s['genres'])       : [''],
            'image'              => $s['image'],
            'anilist_id'         => $s['anilist_id'],
            'mature'             => (bool)$s['mature'],
            'favorite'           => (bool)$s['favorite'],
            'status'                 => $s['status'],
            'mangaupdates_url'       => $s['mangaupdates_url'] ?? '',
            'babelio_url'            => $s['babelio_url'] ?? '',
            'read_elsewhere'         => (bool)($s['read_elsewhere'] ?? false),
            'reading_abandoned'      => (bool)($s['reading_abandoned'] ?? false),
            'rating'                 => $s['rating'] ?? '',
            'volumes'                => $vols,
        ];
    }
    return $result;
}

/**
 * Enregistre la collection.
 *
 * ⚠️ SYNCHRONISATION INTÉGRALE — À LIRE AVANT TOUT APPEL ⚠️
 * Cette fonction ne fait pas qu'écrire : elle SUPPRIME de la base toute série
 * absente de $data (c'est ce qui fait fonctionner delete_series()). Elle attend
 * donc TOUJOURS la collection COMPLÈTE, tous types confondus.
 *
 * Ne lui passez jamais un tableau filtré — ni par type (series_of_type()), ni par
 * recherche, ni par statut. En vue Mangathèque, un tableau filtré ne contient
 * aucun animé : le premier enregistrement effacerait toute l'Animethèque et ses
 * épisodes, sans le moindre message.
 *
 * Règle pratique : $data reste intact pour l'écriture, le filtrage par type se
 * fait en aval sur une COPIE dédiée à l'affichage ($filtered_data), exactement
 * comme le filtre de statuts.
 */
function save_data(array $data): void {
    $db = get_db();
    $db->beginTransaction();
    try {
        // Reconstruire entièrement : supprimer puis ré-insérer
        $existing_ids = array_column(
            $db->query("SELECT id FROM series")->fetchAll(),
            'id'
        );
        $new_ids = array_column($data, 'id');

        // Supprimer les séries retirées (CASCADE supprime aussi leurs volumes)
        foreach (array_diff($existing_ids, $new_ids) as $del_id) {
            $db->prepare("DELETE FROM series WHERE id = ?")->execute([$del_id]);
        }

        $upsertSeries = $db->prepare("
            INSERT INTO series (id, name, type, author, publisher, other_contributors, categories, genres, image, anilist_id, mature, favorite, status, mangaupdates_url, babelio_url, read_elsewhere, reading_abandoned, rating)
            VALUES (:id,:name,:type,:author,:publisher,:other_contributors,:categories,:genres,:image,:anilist_id,:mature,:favorite,:status,:mangaupdates_url,:babelio_url,:read_elsewhere,:reading_abandoned,:rating)
            ON CONFLICT(id) DO UPDATE SET
                name=excluded.name, type=excluded.type,
                author=excluded.author, publisher=excluded.publisher,
                other_contributors=excluded.other_contributors, categories=excluded.categories,
                genres=excluded.genres, image=excluded.image, anilist_id=excluded.anilist_id,
                mature=excluded.mature, favorite=excluded.favorite, status=excluded.status,
                mangaupdates_url=excluded.mangaupdates_url, babelio_url=excluded.babelio_url,
                read_elsewhere=excluded.read_elsewhere,
                reading_abandoned=excluded.reading_abandoned,
                rating=excluded.rating
        ");

        $deleteVols  = $db->prepare("DELETE FROM volumes WHERE series_id = ?");
        $insertVol   = $db->prepare("
            INSERT OR IGNORE INTO volumes (series_id, number, status, collector, last, added_at, read_at)
            VALUES (?,?,?,?,?,?,?)
        ");

        foreach ($data as $s) {
            $upsertSeries->execute([
                ':id'                  => $s['id'],
                ':name'                => $s['name'],
                ':type'                => (isset($s['type']) && trim($s['type']) !== '') ? $s['type'] : 'manga',
                ':author'              => $s['author'] ?? '',
                ':publisher'           => $s['publisher'] ?? '',
                ':other_contributors'  => implode(',', $s['other_contributors'] ?? ['']),
                ':categories'          => implode(',', $s['categories'] ?? ['']),
                ':genres'              => implode(',', $s['genres'] ?? ['']),
                ':image'               => $s['image'] ?? '',
                ':anilist_id'          => $s['anilist_id'] ?? '',
                ':mature'              => (int)($s['mature'] ?? false),
                ':favorite'            => (int)($s['favorite'] ?? false),
                ':status'              => $s['status'] ?? 'en cours',
                ':mangaupdates_url'    => $s['mangaupdates_url'] ?? '',
                ':babelio_url'         => $s['babelio_url'] ?? '',
                ':read_elsewhere'     => (int)($s['read_elsewhere'] ?? false),
                ':reading_abandoned'  => (int)($s['reading_abandoned'] ?? false),
                ':rating'             => $s['rating'] ?? '',
            ]);

            $deleteVols->execute([$s['id']]);
            foreach ($s['volumes'] ?? [] as $v) {
                $insertVol->execute([
                    $s['id'],
                    (int)$v['number'],
                    $v['status'] ?? 'à lire',
                    (int)($v['collector'] ?? false),
                    (int)($v['last'] ?? false),
                    $v['added_at'] ?? date('Y-m-d'),
                    $v['read_at'] ?? '',
                ]);
            }
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Options
// ──────────────────────────────────────────────────────────────────────────────
function load_options(): array {
    $db   = get_db();
    $rows = $db->query("SELECT key, value FROM options")->fetchAll();
    $opts = [];
    foreach ($rows as $r) {
        $opts[$r['key']] = $r['value'];
    }
    // Convertir les booléens
    $opts['private_mode']        = (bool)($opts['private_mode']        ?? false);
    $opts['hide_mature']         = (bool)($opts['hide_mature']         ?? false);
    $opts['hide_reviews']        = (bool)($opts['hide_reviews']        ?? false);
    return $opts;
}

function save_options(array $options): void {
    $db   = get_db();
    $stmt = $db->prepare("INSERT OR REPLACE INTO options (key, value) VALUES (?, ?)");
    foreach ($options as $k => $v) {
        $stmt->execute([$k, is_bool($v) ? (int)$v : $v]);
    }
}

// Supprime définitivement des clés d'options (ex. anciennes clés migrées).
// save_options ne fait que des INSERT OR REPLACE : cette fonction permet de
// retirer des clés obsolètes du stockage.
function delete_options(array $keys): void {
    if (empty($keys)) return;
    $db   = get_db();
    $stmt = $db->prepare("DELETE FROM options WHERE key = ?");
    foreach ($keys as $k) {
        $stmt->execute([$k]);
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Upload d'image (identique à l'original)
// ──────────────────────────────────────────────────────────────────────────────
function upload_image(array $file, &$error_message = null) {
    if (
        !isset($file['error'], $file['tmp_name'], $file['name'], $file['size']) ||
        $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        $error_message = "Aucun fichier n'a été téléversé.";
        return false;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error_message = "Le fichier est trop volumineux (max. 5 Mo).";
                break;
            case UPLOAD_ERR_PARTIAL:
                $error_message = "Le fichier n'a été que partiellement téléversé.";
                break;
            default:
                $error_message = "Erreur inconnue lors du téléversement.";
        }
        return false;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        $error_message = "Fichier invalide ou corrompu.";
        return false;
    }

    $max_file_size = 5 * 1024 * 1024;
    if ($file['size'] > $max_file_size) {
        $error_message = "Le fichier est trop volumineux (max. 5 Mo).";
        return false;
    }

    $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        $error_message = "Impossible d'initialiser la détection MIME.";
        return false;
    }
    $detected_mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($detected_mime_type === false || !in_array($detected_mime_type, $allowed_mime_types, true)) {
        $error_message = "Type de fichier non autorisé.";
        return false;
    }

    if (getimagesize($file['tmp_name']) === false) {
        $error_message = "Le fichier n'est pas une image valide.";
        return false;
    }

    $allowed_extensions = ['jpeg', 'jpg', 'png', 'gif', 'webp'];
    $file_extension     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_extensions, true)) {
        $error_message = "Extension de fichier non autorisée.";
        return false;
    }

    $target_dir = rtrim(UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!is_dir($target_dir) || !is_writable($target_dir)) {
        $error_message = "Le dossier de destination est invalide ou non accessible.";
        return false;
    }

    $unique_name = bin2hex(random_bytes(16)) . '.' . $file_extension;
    $target_file = $target_dir . $unique_name;

    if (!move_uploaded_file($file['tmp_name'], $target_file)) {
        $error_message = "Impossible de déplacer le fichier téléversé.";
        return false;
    }

    return $target_file;
}