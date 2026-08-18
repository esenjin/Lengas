<?php
// ──────────────────────────────────────────────────────────────────────────────
// includes/syngas.php — Intégration Syngas (base commune des mangathèques Lengas)
//
// Syngas mutualise les fiches déjà saisies par les différents utilisateurs de
// Lengas : une même série n'a besoin d'être documentée précisément qu'une
// seule fois. Application web séparée, hébergée par le développeur de Lengas,
// avec sa propre API — ce fichier ne fait qu'y consommer.
//
// Contrairement à Vestikan/Babengas, Syngas N'A PAS d'option marche/arrêt : il
// n'existe qu'un seul Syngas, centralisé, dont l'URL est fixe (constante
// SYNGAS_API_URL, config.php). La fonctionnalité est simplement disponible si
// l'utilisateur choisit de s'en servir — rien à activer, rien à configurer.
// Seule une clé d'API, obtenue automatiquement au premier appel (voir
// syngas_ensure_registered() plus bas), est stockée localement (options).
//
// Lien TOUJOURS consultatif, jamais autoritaire : contrairement à Anilist sur
// l'Animethèque, aucun champ n'est verrouillé après liaison — l'utilisateur
// garde toujours la main sur sa fiche manga.
//
// Périmètre : Mangathèque uniquement (mangas et light-novels). L'Animethèque
// n'est pas concernée.
//
// ── Distinction de typage IMPORTANTE ────────────────────────────────────────
// Lengas typage `type`  : 'manga' | 'anime'  (cloisonnement Mangathèque/Anime-
//                          thèque, jamais touché par Syngas).
// Lengas champ `categories` : texte libre (ex. "manga, Shonen" ou "light-novel").
// Syngas typage `type`  : 'manga' | 'light-novel' (SON typage à lui, sans
//                          notion d'anime — hors sujet pour lui).
// Le pont entre les deux se fait uniquement via `categories` : c'est de là
// qu'on DÉRIVE le type Syngas à l'envoi (syngas_type_from_categories()), et
// c'est LÀ qu'on écrit le type Syngas à la réception (jamais dans `type`).
//
// Contrat API consommé (header X-Syngas-Key, sauf /instances/register et
// /sante) — voir le document « Création de Syngas » pour le détail exact :
//   POST /instances/register  → { api_key }              (aucune authentif.)
//   GET  /sante                → { statut, version }      (aucune authentif.)
//   GET  /series/search?q=&type=
//   GET  /series/{id}
//   POST /series/submit        → 202 { submission_id, status: 'en_attente' }
//   GET  /submissions/{id}     → suivi du devenir d'une soumission (ajouté
//                                 après la V1 initiale, voir le commentaire
//                                 de syngas_submission_status() plus bas)
//
// Erreurs communes : 401 (clé absente/invalide), 403 (instance bannie,
// { error: 'banned', reason, banned_at }), 429 (limite de débit, Retry-After).
// ──────────────────────────────────────────────────────────────────────────────

// ── Configuration ───────────────────────────────────────────────────────────

// Clé API stockée localement ('' si pas encore provisionnée).
function syngas_api_key(): string {
    $opts = function_exists('load_options') ? load_options() : [];
    return trim((string)($opts['syngas_api_key'] ?? ''));
}

// Un bannissement est-il actif ? (posé par syngas_handle_response() dès qu'un
// appel authentifié renvoie 403 { error: 'banned' }).
//
// Le flag expire de lui-même après SYNGAS_BAN_FLAG_TTL secondes : un vrai
// bannissement de domaine est un état durable côté Syngas (jusqu'à
// débannissement manuel par un administrateur), donc le revoir confirmé à
// chaque nouvelle tentative ne coûte qu'un appel réseau occasionnel — alors
// qu'un flag qui resterait bloqué indéfiniment suite à un incident ponctuel
// (timeout, aléa réseau, redémarrage du service Syngas...) empêcherait
// silencieusement toute utilisation de l'intégration jusqu'à ce que
// quelqu'un pense à cliquer sur « Revérifier maintenant ». Ce TTL est donc un
// filet de sécurité, pas une garantie que le bannissement a réellement cessé
// entre deux vérifications actives (syngas_reverify_ban()).
function syngas_is_banned(): bool {
    $opts = function_exists('load_options') ? load_options() : [];
    if (empty($opts['syngas_banned'])) return false;

    $since = (int)($opts['syngas_banned_at'] ?? 0);
    if ($since > 0 && (time() - $since) > SYNGAS_BAN_FLAG_TTL) {
        // Expiré : on ne le déclare pas "non banni" à l'aveugle pour autant,
        // on force une vraie revérification avant de répondre — c'est
        // syngas_reverify_ban() qui décide en dernier ressort, jamais une
        // simple absence de contrôle. syngas_reverify_ban() relit l'état via
        // syngas_banned_flag_raw() en sortie, jamais via cette fonction-ci :
        // pas de risque de cycle.
        return syngas_reverify_ban();
    }

    return true;
}

// Le flag local peut rester bloqué à "banni" plus longtemps qu'il ne le
// devrait : il ne se lève que sur un appel AUTHENTIFIÉ réussi, et un simple
// souci transitoire (timeout, verrou de session contesté par un autre
// onglet…) peut empêcher un tel appel d'aboutir sans que l'instance soit
// réellement bannie.
//
// Repose sur syngas_request() (voir plus bas dans ce fichier) plutôt que sur
// syngas_curl() directement : elle gère déjà le retry après reprovisionnement
// automatique si la clé stockée s'avère invalide (401 — le cas typique d'une
// réinstallation de Syngas avec une base vierge). Jamais de recréation
// d'instance à la légère pour autant : syngas_ensure_registered(), appelée
// par syngas_request(), ne (re)provisionne que si aucune clé n'est stockée —
// POST /instances/register créant systématiquement une NOUVELLE ligne
// `instances` à chaque appel, le faire à chaque simple vérification
// invaliderait inutilement une clé pourtant valide.
function syngas_reverify_ban(): bool {
    syngas_request('GET', '/series/search?q=' . rawurlencode('__syngas_ban_check__'), null, 10);

    // syngas_handle_response() a déjà mis à jour le flag local à chaque appel
    // interne à syngas_request() (403 banned → true ; succès → false) : on
    // relit l'état BRUT qu'il vient de poser, jamais via syngas_is_banned()
    // — celle-ci peut à son tour rappeler syngas_reverify_ban() sur un flag
    // expiré, ce qui bouclerait indéfiniment.
    return syngas_banned_flag_raw();
}

// Lecture brute du flag, SANS logique d'expiration (voir syngas_is_banned()).
// Réservée aux fonctions qui doivent éviter tout risque de récursion avec
// syngas_is_banned() / syngas_reverify_ban() ci-dessus.
function syngas_banned_flag_raw(): bool {
    $opts = function_exists('load_options') ? load_options() : [];
    return !empty($opts['syngas_banned']);
}

function syngas_banned_reason(): string {
    $opts = function_exists('load_options') ? load_options() : [];
    return trim((string)($opts['syngas_banned_reason'] ?? ''));
}

// Marque/lève le bannissement. Appelé par syngas_handle_response() ; peut
// aussi être levé par un appel réussi ultérieur (le blocage peut être temporaire
// côté modération Syngas). Pose syngas_banned_at à l'activation, utilisé par
// syngas_is_banned() pour faire expirer le flag après SYNGAS_BAN_FLAG_TTL.
function syngas_set_banned(bool $banned, string $reason = ''): void {
    save_options([
        'syngas_banned'        => $banned,
        'syngas_banned_reason' => $banned ? $reason : '',
        'syngas_banned_at'     => $banned ? time() : 0,
    ]);
}

// ── Provisionnement automatique de la clé API (section 8 du cahier des charges) ─
//
// Au premier appel Syngas (recherche ou synchronisation, ce qui arrive en
// premier), si aucune clé n'est stockée : on s'enregistre auprès de Syngas
// avec les informations d'identification de l'instance, puis on stocke la
// clé reçue. Cette clé n'est jamais réaffichée ensuite par Syngas : la perdre
// localement impose de se réenregistrer (comportement assumé, cf. section 8).
//
// Retourne la clé utilisable, ou '' en cas d'échec (voir $error par référence).
function syngas_ensure_registered(?string &$error = null): string {
    $error = '';
    $key = syngas_api_key();
    if ($key !== '') {
        return $key;
    }

    $opts        = load_options();
    $site_name   = trim((string)($opts['site_name'] ?? 'Lengas'));
    $admin_pseudo = trim((string)($opts['admin_pseudo'] ?? ''));
    $instance_url = syngas_instance_url();

    $res = syngas_curl('POST', '/instances/register', [
        'site_name'    => $site_name,
        'admin_pseudo' => $admin_pseudo,
        'instance_url' => $instance_url,
    ], 15, false);

    if (!$res['ok']) {
        $error = $res['error'];
        return '';
    }

    $new_key = trim((string)($res['data']['api_key'] ?? ''));
    if ($new_key === '') {
        $error = "Syngas n'a pas renvoyé de clé API.";
        return '';
    }

    save_options(['syngas_api_key' => $new_key]);
    // Un enregistrement réussi prouve que l'instance n'est plus bannie (un
    // domaine banni est rejeté dès l'enregistrement, voir syngas_curl()).
    syngas_set_banned(false);

    return $new_key;
}

// Meilleure estimation de l'URL publique de l'instance, pour l'enregistrement.
// Pas de garantie de fiabilité absolue (dépend de la configuration serveur) :
// Syngas ne s'en sert que pour l'affichage/le bannissement par domaine, jamais
// comme identifiant technique (c'est la clé API qui authentifie chaque appel).
//
// Inclut le sous-répertoire de Lengas (ex. "/lengas"), pas seulement le nom
// de domaine : sans lui, deux instances Lengas hébergées sous des
// sous-répertoires différents d'un même domaine partageraient la même
// instance_url affichée côté Syngas, ce qui serait trompeur. On réutilise le
// même calcul que lengas_session_base_path() (config.php), qui s'est avéré
// fiable pour déterminer le sous-répertoire réel de l'application.
function syngas_instance_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    if ($host === '') return '';
    $path = function_exists('lengas_session_base_path') ? lengas_session_base_path() : '/';
    if ($path === '/') $path = '';
    return $scheme . '://' . $host . $path;
}

// ── Journal local des soumissions en attente (table syngas_submissions) ────
// Voir le commentaire de la table dans config.php::init_db(). Permet à
// l'outil de synchronisation de résoudre automatiquement, à la relance, les
// soumissions déjà traitées côté Syngas sans repasser par une recherche
// manuelle pour chaque série.

function syngas_track_submission(string $submission_id, string $series_id): void {
    if ($submission_id === '' || $series_id === '') return;
    get_db()->prepare("
        INSERT OR REPLACE INTO syngas_submissions (submission_id, series_id, created_at)
        VALUES (?, ?, ?)
    ")->execute([$submission_id, $series_id, time()]);
}

function syngas_untrack_submission(string $submission_id): void {
    get_db()->prepare("DELETE FROM syngas_submissions WHERE submission_id = ?")->execute([$submission_id]);
}

// Toutes les soumissions encore suivies : [['submission_id'=>…, 'series_id'=>…, 'created_at'=>int], …]
function syngas_tracked_submissions(): array {
    return get_db()->query("SELECT * FROM syngas_submissions ORDER BY created_at ASC")->fetchAll();
}

// ── Requête cURL vers Syngas ────────────────────────────────────────────────
// $authenticated : ajoute l'en-tête X-Syngas-Key (tous les appels sauf
// /instances/register et /sante).
// Retourne ['ok'=>bool, 'http'=>int, 'data'=>array|null, 'error'=>string].
function syngas_curl(string $method, string $path, ?array $payload = null, int $timeout = 15, bool $authenticated = true): array {
    $base = rtrim(SYNGAS_API_URL, '/');

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($authenticated) {
        $key = syngas_api_key();
        if ($key === '') {
            return ['ok' => false, 'http' => 0, 'data' => null,
                     'error' => "Syngas n'est pas encore provisionné (aucune clé API)."];
        }
        $headers[] = 'X-Syngas-Key: ' . $key;
    }

    $ch = curl_init($base . '/' . ltrim($path, '/'));
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => 'Lengas (gestion de collection de mangas)',
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        return ['ok' => false, 'http' => $code, 'data' => null, 'error' => $err];
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'http' => $code, 'data' => null,
                'error' => 'Réponse illisible de Syngas (JSON invalide).'];
    }

    return syngas_handle_response($code, $data);
}

// Normalise la réponse et traite en particulier le bannissement (section 6.5
// du cahier des charges) : dès qu'un appel authentifié renvoie 403
// { error: 'banned' }, on persiste l'état pour l'affichage du bandeau — sur
// la page de l'outil ET dans la section « Recherche Syngas » — tant que le
// statut n'a pas changé.
function syngas_handle_response(int $code, array $data): array {
    if ($code === 403 && ($data['error'] ?? '') === 'banned') {
        $reason = (string)($data['reason'] ?? '');
        syngas_set_banned(true, $reason);
        return ['ok' => false, 'http' => $code, 'data' => $data,
                'error' => 'La connexion de ce site à Syngas a été suspendue.' . ($reason !== '' ? ' ' . $reason : '')];
    }

    if ($code === 429) {
        $retry = isset($data['retry_after']) ? (int)$data['retry_after'] : null;
        return ['ok' => false, 'http' => $code, 'data' => $data,
                'error' => 'Syngas limite temporairement les requêtes.' . ($retry !== null ? " Réessayez dans {$retry}s." : '')];
    }

    if ($code === 401) {
        // Clé invalide/perdue : on l'efface pour qu'un prochain appel
        // déclenche un réenregistrement automatique (syngas_ensure_registered()).
        save_options(['syngas_api_key' => '']);
        return ['ok' => false, 'http' => $code, 'data' => $data,
                'error' => "Clé Syngas invalide ou expirée : réenregistrement nécessaire."];
    }

    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'http' => $code, 'data' => $data,
                'error' => (string)($data['message'] ?? "Erreur HTTP $code")];
    }

    // Un appel authentifié réussi prouve que l'instance n'est plus bannie.
    // On écrit l'état directement plutôt que de repasser par
    // syngas_is_banned() : cette dernière peut elle-même déclencher
    // syngas_reverify_ban() (flag expiré, voir plus haut), qui rappelle ce
    // même syngas_handle_response() — relire le flag ici créerait un cycle.
    // save_options() est un simple UPSERT idempotent : l'appeler même quand
    // le flag était déjà à false ne coûte qu'une écriture SQL superflue,
    // sans aucun effet de bord.
    save_options(['syngas_banned' => false, 'syngas_banned_reason' => '', 'syngas_banned_at' => 0]);

    return ['ok' => true, 'http' => $code, 'data' => $data, 'error' => ''];
}

// Requête authentifiée avec provisionnement automatique de la clé si besoin.
// C'est le point d'entrée à utiliser depuis le reste du site (recherche,
// synchronisation…) plutôt que syngas_curl() directement.
//
// Retente UNE fois si le premier essai échoue en 401 : syngas_ensure_registered()
// ne vérifie que la PRÉSENCE d'une clé stockée, jamais sa validité réelle —
// une clé présente mais devenue invalide côté Syngas (réinstallation de
// Syngas avec une base vierge, révocation manuelle...) est donc renvoyée
// telle quelle sans second regard, l'appel échoue alors en 401, et
// syngas_handle_response() l'efface. Sans ce retry, l'échec s'arrêtait là :
// la clé finissait bien par être effacée, mais l'appel EN COURS n'aboutissait
// jamais avec la nouvelle — il fallait attendre un appel complètement
// distinct pour, cette fois, repartir d'une clé vide et se reprovisionner.
function syngas_request(string $method, string $path, ?array $payload = null, int $timeout = 15): array {
    $error = null;
    $key = syngas_ensure_registered($error);
    if ($key === '') {
        return ['ok' => false, 'http' => 0, 'data' => null,
                'error' => $error ?: "Impossible de provisionner l'accès à Syngas."];
    }

    $res = syngas_curl($method, $path, $payload, $timeout, true);

    if (!$res['ok'] && $res['http'] === 401) {
        // syngas_handle_response() vient d'effacer la clé invalide : on
        // retente une fois avec une clé fraîchement provisionnée. Si ce
        // second essai échoue aussi (Syngas injoignable entre-temps, etc.),
        // on renvoie son résultat tel quel plutôt que de boucler.
        $key = syngas_ensure_registered($error);
        if ($key !== '') {
            $res = syngas_curl($method, $path, $payload, $timeout, true);
        }
    }

    return $res;
}

// ── Sonde /sante (sans authentification) ────────────────────────────────────
// Retourne ['ok'=>bool,'http'=>int,'version'=>string,'error'=>string].
function syngas_check_service(): array {
    $res = syngas_curl('GET', '/sante', null, 10, false);
    if (!$res['ok']) {
        return ['ok' => false, 'http' => $res['http'], 'version' => '', 'error' => $res['error']];
    }
    $data = $res['data'];
    if (($data['statut'] ?? '') !== 'ok') {
        return ['ok' => false, 'http' => $res['http'], 'version' => '', 'error' => 'Réponse inattendue du service.'];
    }
    return ['ok' => true, 'http' => $res['http'], 'version' => (string)($data['version'] ?? ''), 'error' => ''];
}

// ── Dérivation du type Syngas depuis les catégories Lengas ──────────────────
//
// Syngas type = 'manga' | 'light-novel', un typage qui lui est propre (sans
// notion d'anime). Lengas n'a pas ce typage directement : le champ
// `categories` (texte libre) doit contenir littéralement le mot "manga" ou
// "light-novel" pour qu'une série soit reconnue par Syngas (section 5 du
// cahier des charges). C'est cette dérivation qui détermine l'éligibilité
// d'une série à l'envoi (section 6.1) : null si aucun des deux tags n'est
// présent.
function syngas_type_from_categories($categories): ?string {
    if (!is_array($categories)) $categories = [$categories];
    $found_ln = false;
    $found_manga = false;
    foreach ($categories as $c) {
        $c = mb_strtolower(trim((string)$c));
        if ($c === 'light-novel' || $c === 'light novel') $found_ln = true;
        if ($c === 'manga') $found_manga = true;
    }
    // Un light-novel reste un light-novel même si "manga" apparaît aussi par
    // erreur de saisie : light-novel est le tag le plus spécifique des deux.
    if ($found_ln) return 'light-novel';
    if ($found_manga) return 'manga';
    return null;
}

// Une série est-elle éligible à un envoi vers Syngas ? (section 6.1)
function syngas_series_is_eligible(array $series): bool {
    return syngas_type_from_categories($series['categories'] ?? []) !== null;
}

// Fusionne le type Syngas ('manga'|'light-novel') dans le champ `categories`
// Lengas SANS écraser les autres catégories déjà saisies par l'utilisateur
// (genres démographiques mal rangés y compris, on n'y touche pas). N'ajoute le
// tag que s'il est absent ; ne retire jamais l'autre tag s'il était déjà là
// (rare mais laissé tel quel — pas à Lengas de trancher une contradiction que
// l'utilisateur a lui-même saisie).
function syngas_merge_type_into_categories($categories, string $syngas_type): array {
    if (!is_array($categories)) $categories = $categories !== '' ? [$categories] : [];
    $categories = array_values(array_filter(array_map('trim', $categories), fn($c) => $c !== ''));

    $already_present = false;
    foreach ($categories as $c) {
        if (mb_strtolower($c) === mb_strtolower($syngas_type)) { $already_present = true; break; }
    }
    if (!$already_present) {
        $categories[] = $syngas_type;
    }
    return $categories;
}

// ── Mapping des champs Syngas → Lengas (section 4 du cahier des charges) ────
//
// $syngas_series : fiche telle que renvoyée par GET /series/search (résumé)
// ou GET /series/{id} (détail complet) ou l'objet `series` de
// GET /submissions/{id}.
// $local_categories : catégories ACTUELLES de la série Lengas ciblée (pour la
// fusion du type Syngas, voir syngas_merge_type_into_categories() ci-dessus).
//
// Règle constante (sections 4 et 6.2) : un champ Syngas VIDE ne touche JAMAIS
// au champ Lengas correspondant — écrasement total mais seulement pour les
// champs non vides.
//
// Ne fait AUCUNE écriture ; retourne un tableau de champs Lengas prêts à être
// fusionnés dans une série existante par l'appelant (recherche modale, ou
// outil de synchronisation en réception).
function syngas_map_to_lengas_fields(array $syngas_series, $local_categories = []): array {
    $fields = [];

    $set_if_not_empty = function (string $lengas_key, $value) use (&$fields) {
        if ($value === null) return;
        if (is_string($value) && trim($value) === '') return;
        $fields[$lengas_key] = $value;
    };

    $set_if_not_empty('name', $syngas_series['name'] ?? null);
    $set_if_not_empty('author', $syngas_series['author'] ?? null);
    $set_if_not_empty('publisher', $syngas_series['publisher'] ?? null);
    $set_if_not_empty('other_contributors', $syngas_series['other_contributors'] ?? null);
    $set_if_not_empty('status', $syngas_series['status'] ?? null);
    $set_if_not_empty('mangaupdates_url', $syngas_series['mangaupdates_url'] ?? null);
    $set_if_not_empty('babelio_url', $syngas_series['babelio_url'] ?? null);

    if (isset($syngas_series['mature'])) {
        $fields['mature'] = (bool)$syngas_series['mature'];
    }

    // Genres : Syngas renvoie une liste déjà contrainte à la traduction
    // française connue de Lengas (mangaupdates_genre_translation_map()) —
    // aucune validation supplémentaire nécessaire à la réception (section 4).
    $genres = $syngas_series['genres'] ?? null;
    if ($genres !== null && $genres !== '') {
        $fields['genres'] = is_array($genres) ? implode(',', $genres) : (string)$genres;
    }

    // Catégories : fusion du type Syngas (manga/light-novel) dans les
    // catégories locales existantes, jamais un remplacement pur — voir
    // syngas_merge_type_into_categories(). Le champ `type` Lengas (manga/
    // anime) n'est JAMAIS concerné par ce mapping.
    $syngas_type = trim((string)($syngas_series['type'] ?? ''));
    if ($syngas_type === 'manga' || $syngas_type === 'light-novel') {
        $fields['categories'] = syngas_merge_type_into_categories($local_categories, $syngas_type);
    }

    // Nombre de tomes VF : n'écrase jamais une donnée de la fiche série
    // elle-même — alimente uniquement les outils de cohérence
    // (coherence_reference_volumes(), voir fonctions/tools/coherence.php).
    // Exposé ici à part, pas dans $fields, pour que l'appelant sache que ce
    // champ ne se fusionne pas comme les autres.
    $volumes_count = $syngas_series['volumes_count'] ?? null;

    // syngas_uid n'est jamais un champ affiché/mappé ici : il est posé
    // explicitement par l'appelant (syngas_uid ne vient pas d'un champ
    // Syngas visible, cf. section 4, tableau de mapping).

    return [
        'fields'        => $fields,
        'volumes_count' => ($volumes_count !== null) ? (int)$volumes_count : null,
        'thumbnail_url' => trim((string)($syngas_series['thumbnail_url'] ?? '')),
    ];
}

// ── Vignette : téléchargement local (section 4, note vignette) ─────────────
// Même principe que anime_download_cover() (fonctions/anime.php) : Syngas
// héberge lui-même ses vignettes, mais Lengas les rapatrie localement à la
// validation pour rester disponible hors ligne ou si Syngas est injoignable.
function syngas_download_thumbnail(string $url): array {
    $fail = fn(string $msg) => ['ok' => false, 'path' => '', 'error' => $msg];

    $url = trim($url);
    if ($url === '') {
        return $fail('Aucune vignette proposée par Syngas.');
    }
    if (!preg_match('#^https?://#i', $url)) {
        return $fail('Adresse de vignette invalide.');
    }
    if (!function_exists('curl_init')) {
        return $fail("L'extension cURL de PHP est absente : téléchargement impossible.");
    }

    // Toujours un slash "/", jamais DIRECTORY_SEPARATOR (voir la même remarque
    // dans anime_download_cover() et upload_image()) : ce chemin sert de
    // source à des balises <img> et de clé de comparaison ailleurs dans le
    // site (vérification d'intégrité, nettoyage des images orphelines).
    $target_dir = rtrim(UPLOAD_DIR, '/') . '/';
    if (!is_dir($target_dir) || !is_writable($target_dir)) {
        return $fail('Le dossier des vignettes est inaccessible en écriture.');
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Lengas (gestion de collection de mangas)');
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $body === '' || $http < 200 || $http >= 300) {
        return $fail("Téléchargement de la vignette impossible (code $http).");
    }
    if (strlen($body) > 5 * 1024 * 1024) {
        return $fail('La vignette dépasse 5 Mo.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return $fail('Impossible de vérifier le type de la vignette.');
    }
    $mime = finfo_buffer($finfo, $body);
    finfo_close($finfo);

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        return $fail("Le fichier téléchargé n'est pas une image exploitable.");
    }

    $path = $target_dir . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (file_put_contents($path, $body) === false) {
        return $fail('Écriture de la vignette impossible.');
    }
    if (getimagesize($path) === false) {
        @unlink($path);
        return $fail("Le fichier téléchargé n'est pas une image valide.");
    }

    return ['ok' => true, 'path' => $path, 'error' => ''];
}

// ── Recherche (section 4) ───────────────────────────────────────────────────
// $type facultatif ('manga'|'light-novel') pour restreindre côté Syngas.
// Retourne ['ok'=>bool, 'results'=>array, 'error'=>string].
function syngas_search(string $query, ?string $type = null): array {
    $query = trim($query);
    if ($query === '') {
        return ['ok' => true, 'results' => [], 'error' => ''];
    }
    $path = '/series/search?q=' . rawurlencode($query);
    if ($type !== null && $type !== '') {
        $path .= '&type=' . rawurlencode($type);
    }
    $res = syngas_request('GET', $path, null, 15);
    if (!$res['ok']) {
        return ['ok' => false, 'results' => [], 'error' => $res['error']];
    }
    return ['ok' => true, 'results' => $res['data']['results'] ?? [], 'error' => ''];
}

// Fiche complète d'une série Syngas par identifiant. $timeout réduit possible
// pour les appels en boucle (outil de synchronisation) : sur un hébergement
// mutualisé, un timeout de 15s répété série par série peut faire tourner un
// script SSE anormalement longtemps et saturer les workers PHP disponibles.
function syngas_get_series(string $syngas_id, int $timeout = 15): array {
    $syngas_id = trim($syngas_id);
    if ($syngas_id === '') {
        return ['ok' => false, 'series' => null, 'error' => 'Identifiant Syngas manquant.'];
    }
    $res = syngas_request('GET', '/series/' . rawurlencode($syngas_id), null, $timeout);
    if (!$res['ok']) {
        return ['ok' => false, 'series' => null, 'error' => $res['error']];
    }
    return ['ok' => true, 'series' => $res['data'], 'error' => ''];
}

// ── Soumission d'une nouvelle série (section 6.1) ───────────────────────────
// $series : fiche Lengas complète. Construit le payload attendu par
// POST /series/submit à partir du mapping inverse (Lengas → Syngas).
// $timeout : voir la note de syngas_get_series() ci-dessus.
// Retourne ['ok'=>bool, 'submission_id'=>string, 'status'=>string, 'error'=>string].
function syngas_submit_series(array $series, int $timeout = 15): array {
    $syngas_type = syngas_type_from_categories($series['categories'] ?? []);
    if ($syngas_type === null) {
        return ['ok' => false, 'submission_id' => '', 'status' => '',
                'error' => 'Catégories sans tag "manga" ou "light-novel".'];
    }

    $payload = [
        'type'               => $syngas_type,
        'name'               => (string)($series['name'] ?? ''),
        'author'             => (string)($series['author'] ?? ''),
        'publisher'          => (string)($series['publisher'] ?? ''),
        'other_contributors' => is_array($series['other_contributors'] ?? null)
                                  ? implode(',', array_filter($series['other_contributors']))
                                  : (string)($series['other_contributors'] ?? ''),
        'genres'             => is_array($series['genres'] ?? null)
                                  ? implode(',', array_filter($series['genres']))
                                  : (string)($series['genres'] ?? ''),
        'status'             => (string)($series['status'] ?? ''),
        'mangaupdates_url'   => (string)($series['mangaupdates_url'] ?? ''),
        'babelio_url'        => (string)($series['babelio_url'] ?? ''),
        'mature'             => (bool)($series['mature'] ?? false),
    ];

    $volumes_count = count($series['volumes'] ?? []);
    if ($volumes_count > 0) {
        $payload['volumes_count'] = $volumes_count;
    }

    // Vignette : on transmet l'URL locale résolue (perso ou Anilist n'a pas
    // lieu d'être ici, Mangathèque uniquement) — Syngas la télécharge lui-même
    // à titre d'aperçu, seulement si la soumission est validée par un
    // modérateur (voir la note SSRF du document « Création de Syngas »).
    // Une vignette purement locale (fichier sur ce serveur, jamais publié
    // ailleurs) n'est pas exploitable par Syngas depuis l'extérieur : on ne
    // l'envoie donc que si elle ressemble à une URL absolue.
    $local_thumb = function_exists('series_thumbnail') ? series_thumbnail($series, '') : ($series['image'] ?? '');
    if ($local_thumb !== '' && preg_match('#^https?://#i', $local_thumb)) {
        $payload['thumbnail_source_url'] = $local_thumb;
    }

    $res = syngas_request('POST', '/series/submit', $payload, $timeout);
    if (!$res['ok']) {
        return ['ok' => false, 'submission_id' => '', 'status' => '', 'error' => $res['error']];
    }

    return [
        'ok'            => true,
        'submission_id' => (string)($res['data']['submission_id'] ?? ''),
        'status'        => (string)($res['data']['status'] ?? 'en_attente'),
        'error'         => '',
    ];
}

// ── Suivi d'une soumission (GET /submissions/{id}) ──────────────────────────
//
// Endpoint ABSENT du cahier des charges initial de Syngas (« une fois
// validée, la série redevient trouvable via /series/search ») : la V1
// livrée de Syngas l'a ajouté après coup pour donner aux instances Lengas un
// retour explicite sur le devenir de leurs soumissions, plutôt que de les
// laisser reformuler une recherche par nom (fragile : le nom en base peut
// différer de celui proposé si un modérateur a choisi la valeur existante à
// la fusion). Toujours scopé à l'instance appelante côté Syngas — un
// identifiant appartenant à une autre instance renvoie 404.
//
// Retourne ['ok'=>bool, 'status'=>'en_attente'|'creee'|'fusionnee'|'rejetee',
//           'series'=>array|null, 'reviewed_at'=>?string, 'created_at'=>?string,
//           'error'=>string].
function syngas_submission_status(string $submission_id, int $timeout = 15): array {
    $submission_id = trim($submission_id);
    if ($submission_id === '') {
        return ['ok' => false, 'status' => '', 'series' => null,
                'reviewed_at' => null, 'created_at' => null, 'error' => 'Identifiant de soumission manquant.'];
    }
    $res = syngas_request('GET', '/submissions/' . rawurlencode($submission_id), null, $timeout);
    if (!$res['ok']) {
        return ['ok' => false, 'status' => '', 'series' => null,
                'reviewed_at' => null, 'created_at' => null, 'error' => $res['error']];
    }
    $d = $res['data'];
    return [
        'ok'          => true,
        'status'      => (string)($d['status'] ?? ''),
        'series'      => $d['series'] ?? null,
        'reviewed_at' => $d['reviewed_at'] ?? null,
        'created_at'  => $d['created_at'] ?? null,
        'error'       => '',
    ];
}
