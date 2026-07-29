<?php
// Configuration du site
define('SITE_VERSION', '4.0.0');
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

    // ──────────────────────────────────────────────────────────────────────────
    // V4 — Colonnes propres aux séries animées
    // ──────────────────────────────────────────────────────────────────────────
    // Toutes ces valeurs viennent d'Anilist et ne sont JAMAIS saisies à la main :
    // une erreur constatée se corrige à la source. Elles restent vides sur les
    // mangas, qui n'en ont pas l'usage.
    //
    // `anilist_id` n'est pas créée ici : la colonne existe depuis la 3.1 (vestige
    // d'une intégration abandonnée), la V4 la recycle telle quelle.

    // ── URL de la fiche Anilist (lien cliquable de la carte, non éditable) ─────
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN anilist_url TEXT NOT NULL DEFAULT ''");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Studios d'animation, séparés par des virgules (pendant de `author`) ────
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN studios TEXT NOT NULL DEFAULT ''");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Format brut d'Anilist : TV, TV_SHORT, MOVIE, SPECIAL, OVA, ONA, MUSIC ──
    // On stocke le code d'origine, pas le libellé : la traduction est faite à
    // l'affichage par anilist_format_label(), ce qui la rend corrigeable.
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN anime_format TEXT NOT NULL DEFAULT ''");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Titres alternatifs (JSON) : romaji, english, natif et synonymes ────────
    // Seule source autorisée pour changer le titre d'un animé : l'utilisateur
    // choisit dans cette liste, il ne saisit rien.
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN alt_titles TEXT NOT NULL DEFAULT ''");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Vignette téléchargée depuis Anilist (chemin dans uploads/) ─────────────
    // Distincte de `image`, qui reste la vignette personnalisée : la cascade
    // d'affichage est perso → Anilist → vignette par défaut du site.
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN anilist_image TEXT NOT NULL DEFAULT ''");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Visionnage abandonné (pendant strict de reading_abandoned) ─────────────
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN watching_abandoned INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Nombre de revisionnages (champ `repeat` d'une entrée de liste Anilist) ─
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN rewatch_count INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Horodatage de la dernière synchronisation Anilist (verrou 24h) ───────
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN anilist_synced_at INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Durée d'un épisode en minutes (champ `duration` d'Anilist) ─────────────
    // Alimente le temps de visionnage des statistiques Animethèque. Vide (0)
    // quand Anilist ne la fournit pas : le calcul retombe alors sur le réglage
    // par format (stats_get_anime_settings()).
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN episode_duration INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Nombre de relectures (pendant manga de rewatch_count) ──────────────────
    // Contrairement à rewatch_count (alimenté par Anilist via le champ `repeat`
    // d'une entrée de liste), reread_count n'a pas de source externe : c'est une
    // saisie manuelle exclusivement, à l'ajout ou à la modification d'une série
    // manga. Vaut 0 pour un animé (le revisionnage y suit rewatch_count).
    try {
        $pdo->exec("ALTER TABLE series ADD COLUMN reread_count INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Garde-fou anti-doublon sur l'identifiant Anilist ───────────────────────
    // Index PARTIEL : la contrainte ne porte que sur les séries qui ont un
    // identifiant. Les mangas, tous à '', ne se gênent donc pas entre eux.
    // Un échec (base contenant déjà des doublons) n'est pas bloquant : le
    // contrôle applicatif d'add_anime_series() reste la première barrière.
    try {
        $pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_series_anilist_id
            ON series(anilist_id) WHERE anilist_id <> ''
        ");
    } catch (Exception $e) { /* doublons préexistants : index non créé */ }

    // ──────────────────────────────────────────────────────────────────────────
    // Typage de la liste d'envies
    // ──────────────────────────────────────────────────────────────────────────
    // Une entrée de wishlist peut désormais être un manga (comportement inchangé,
    // saisie libre) ou un animé (recherche Anilist, anilist_id mémorisé). Le
    // défaut 'manga' assure la rétro-compatibilité des entrées existantes.

    // ── Colonne type (manga / anime, registre défini dans includes/helpers.php) ─
    try {
        $pdo->exec("ALTER TABLE wishlist ADD COLUMN type TEXT NOT NULL DEFAULT 'manga'");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Identifiant Anilist mémorisé pour un animé en projet ───────────────────
    // Vide pour un manga. Permet un import immédiat et sans nouvelle recherche
    // au passage en collection (cf. fonctions/wishlist.php::add_from_wishlist).
    try {
        $pdo->exec("ALTER TABLE wishlist ADD COLUMN anilist_id TEXT NOT NULL DEFAULT ''");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Studio(s) d'un animé en projet (pendant d'`author` pour les mangas) ────
    // Champ dédié plutôt que recyclage d'`author` : la colonne existante reste
    // strictement réservée aux mangas, sans condition supplémentaire à lire.
    try {
        $pdo->exec("ALTER TABLE wishlist ADD COLUMN studio TEXT NOT NULL DEFAULT ''");
    } catch (Exception $e) { /* colonne déjà présente */ }

    // ── Éditions physiques d'une série animée (coffret DVD, Blu-ray…) ─────────
    // Un commentaire libre = une édition, 100 caractères maximum, 5 éditions au
    // plus par série (plafond appliqué côté PHP, cf. series_editions_max()).
    // ON DELETE CASCADE : la suppression d'une série emporte ses éditions.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS series_editions (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            series_id   TEXT NOT NULL REFERENCES series(id) ON DELETE CASCADE,
            comment     TEXT NOT NULL DEFAULT '',
            position    INTEGER NOT NULL DEFAULT 0
        )
    ");
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_series_editions_series
        ON series_editions(series_id)
    ");

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

    // ── Migration corrective : chemins d'image bâtards sur serveur Windows ─────
    // upload_image() (ce fichier) et anime_download_cover() (fonctions/anime.php)
    // construisaient jusqu'ici leur chemin avec DIRECTORY_SEPARATOR, qui vaut
    // "\" sur un serveur Windows. Résultat : un chemin enregistré du type
    // "uploads/\abcd1234.jpg" (mélange slash + backslash) — affichable tel quel
    // (Windows accepte les deux séparateurs) mais qui ne correspond plus au
    // format "uploads/abcd1234.jpg" attendu ailleurs dans le site (vérification
    // d'intégrité, nettoyage des images orphelines), d'où des vignettes
    // pourtant actives signalées à tort comme orphelines. Corrigé à la source
    // dans le code ; cette migration nettoie les valeurs déjà enregistrées.
    // Non destructive : un simple remplacement de texte, rejouable sans risque.
    try {
        // chr(92) = le caractère backslash, exprimé ainsi pour éviter toute
        // ambiguïté d'échappement PHP/SQL imbriqués sur ce caractère.
        $bslash = chr(92);
        $pdo->prepare("UPDATE series SET image = REPLACE(image, ?, '/') WHERE image LIKE ?")
            ->execute(['/' . $bslash, '%/' . $bslash . '%']);
        $pdo->prepare("UPDATE series SET anilist_image = REPLACE(anilist_image, ?, '/') WHERE anilist_image LIKE ?")
            ->execute(['/' . $bslash, '%/' . $bslash . '%']);
    } catch (Exception $e) { /* migration non bloquante */ }

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
// Décode la colonne `alt_titles` (JSON) en tableau de chaînes. Une valeur vide,
// illisible ou non conforme rend simplement un tableau vide : le titre reste
// alors figé, ce qui est toujours préférable à une fatale.
function decode_alt_titles($raw): array {
    $raw = trim((string)$raw);
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $title) {
        $title = trim((string)$title);
        if ($title !== '' && !in_array($title, $out, true)) {
            $out[] = $title;
        }
    }
    return $out;
}

function load_data(): array {
    $db      = get_db();
    $series  = $db->query("SELECT * FROM series ORDER BY rowid")->fetchAll();
    $volStmt = $db->prepare("SELECT * FROM volumes WHERE series_id = ? ORDER BY number");

    // Éditions physiques : une seule requête pour toute la collection, groupée
    // en mémoire. Elles ne concernent qu'une poignée de séries, inutile d'en
    // faire une requête par fiche.
    $editions_by_series = [];
    try {
        $rows = $db->query("SELECT * FROM series_editions ORDER BY series_id, position, id")->fetchAll();
        foreach ($rows as $e) {
            $editions_by_series[$e['series_id']][] = [
                'id'       => (int)$e['id'],
                'comment'  => (string)$e['comment'],
                'position' => (int)$e['position'],
            ];
        }
    } catch (Exception $e) { /* table absente : collection sans éditions */ }

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
            // ── Champs animé (vides sur les mangas) ──────────────────────────
            'anilist_url'            => $s['anilist_url'] ?? '',
            'studios'                => $s['studios'] !== null && $s['studios'] !== ''
                                        ? explode(',', $s['studios']) : [],
            'anime_format'           => $s['anime_format'] ?? '',
            'alt_titles'             => decode_alt_titles($s['alt_titles'] ?? ''),
            'anilist_image'          => $s['anilist_image'] ?? '',
            'watching_abandoned'     => (bool)($s['watching_abandoned'] ?? false),
            'rewatch_count'          => (int)($s['rewatch_count'] ?? 0),
            'anilist_synced_at'      => (int)($s['anilist_synced_at'] ?? 0),
            'episode_duration'       => (int)($s['episode_duration'] ?? 0),
            // ── Relectures (mangas) ───────────────────────────────────────────
            'reread_count'           => (int)($s['reread_count'] ?? 0),
            'editions'               => $editions_by_series[$s['id']] ?? [],
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
            INSERT INTO series (id, name, type, author, publisher, other_contributors, categories, genres, image, anilist_id, mature, favorite, status, mangaupdates_url, babelio_url, read_elsewhere, reading_abandoned, rating, anilist_url, studios, anime_format, alt_titles, anilist_image, watching_abandoned, rewatch_count, anilist_synced_at, episode_duration, reread_count)
            VALUES (:id,:name,:type,:author,:publisher,:other_contributors,:categories,:genres,:image,:anilist_id,:mature,:favorite,:status,:mangaupdates_url,:babelio_url,:read_elsewhere,:reading_abandoned,:rating,:anilist_url,:studios,:anime_format,:alt_titles,:anilist_image,:watching_abandoned,:rewatch_count,:anilist_synced_at,:episode_duration,:reread_count)
            ON CONFLICT(id) DO UPDATE SET
                name=excluded.name, type=excluded.type,
                author=excluded.author, publisher=excluded.publisher,
                other_contributors=excluded.other_contributors, categories=excluded.categories,
                genres=excluded.genres, image=excluded.image, anilist_id=excluded.anilist_id,
                mature=excluded.mature, favorite=excluded.favorite, status=excluded.status,
                mangaupdates_url=excluded.mangaupdates_url, babelio_url=excluded.babelio_url,
                read_elsewhere=excluded.read_elsewhere,
                reading_abandoned=excluded.reading_abandoned,
                rating=excluded.rating,
                anilist_url=excluded.anilist_url, studios=excluded.studios,
                anime_format=excluded.anime_format, alt_titles=excluded.alt_titles,
                anilist_image=excluded.anilist_image,
                watching_abandoned=excluded.watching_abandoned,
                rewatch_count=excluded.rewatch_count,
                anilist_synced_at=excluded.anilist_synced_at,
                episode_duration=excluded.episode_duration,
                reread_count=excluded.reread_count
        ");

        $deleteVols  = $db->prepare("DELETE FROM volumes WHERE series_id = ?");
        $insertVol   = $db->prepare("
            INSERT OR IGNORE INTO volumes (series_id, number, status, collector, last, added_at, read_at)
            VALUES (?,?,?,?,?,?,?)
        ");

        // Éditions physiques : réécrites en bloc, à l'image des tomes. Contrairement
        // aux tomes, elles ne sont réécrites que si la clé `editions` est présente :
        // un tableau venu d'un import JSON antérieur à la V4 n'en a pas, et ne doit
        // surtout pas effacer celles déjà en base.
        $deleteEditions = $db->prepare("DELETE FROM series_editions WHERE series_id = ?");
        $insertEdition  = $db->prepare("
            INSERT INTO series_editions (series_id, comment, position) VALUES (?,?,?)
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
                ':anilist_url'        => $s['anilist_url'] ?? '',
                ':studios'            => is_array($s['studios'] ?? null)
                                          ? implode(',', $s['studios'])
                                          : (string)($s['studios'] ?? ''),
                ':anime_format'       => $s['anime_format'] ?? '',
                ':alt_titles'         => is_array($s['alt_titles'] ?? null)
                                          ? json_encode(array_values($s['alt_titles']), JSON_UNESCAPED_UNICODE)
                                          : (string)($s['alt_titles'] ?? ''),
                ':anilist_image'      => $s['anilist_image'] ?? '',
                ':watching_abandoned' => (int)($s['watching_abandoned'] ?? false),
                ':rewatch_count'      => max(0, (int)($s['rewatch_count'] ?? 0)),
                ':anilist_synced_at'  => max(0, (int)($s['anilist_synced_at'] ?? 0)),
                ':episode_duration'   => max(0, (int)($s['episode_duration'] ?? 0)),
                ':reread_count'       => max(0, (int)($s['reread_count'] ?? 0)),
            ]);

            if (array_key_exists('editions', $s)) {
                $deleteEditions->execute([$s['id']]);
                $position = 0;
                foreach (array_slice((array)$s['editions'], 0, 5) as $edition) {
                    $comment = is_array($edition) ? ($edition['comment'] ?? '') : $edition;
                    $comment = mb_substr(trim((string)$comment), 0, 100);
                    if ($comment === '') continue;
                    $insertEdition->execute([$s['id'], $comment, $position++]);
                }
            }

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

    // Toujours un slash "/" ici, jamais DIRECTORY_SEPARATOR : ce chemin sert de
    // source à des balises <img> et de clé de comparaison ailleurs dans le site
    // (vérification d'intégrité, nettoyage des images orphelines...). Sur un
    // serveur Windows, DIRECTORY_SEPARATOR vaut "\" et produirait un chemin
    // bâtard qui reste affichable mais ne correspond plus au format généré par
    // scandir() ("uploads/xxxx.ext") — la vignette serait alors signalée à tort
    // comme orpheline.
    $target_dir = rtrim(UPLOAD_DIR, '/') . '/';
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