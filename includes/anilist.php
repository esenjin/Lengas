<?php
// ──────────────────────────────────────────────────────────────────────────────
// includes/anilist.php — Connecteur de l'API Anilist (GraphQL)
//
// Couche technique isolée, sans aucune interface : ce fichier interroge Anilist,
// normalise et traduit ses réponses, puis les rend exploitables par le reste du
// site. Il n'écrit JAMAIS dans la table `series` ni dans `volumes` — ce sont les
// blocs suivants (fiche animé, épisodes, import de masse) qui s'en chargent.
//
// Principe directeur de la V4 : ANILIST FAIT AUTORITÉ. On interroge, on récupère
// et on prend pour argent comptant. Aucune donnée factuelle d'animé n'est saisie
// à la main dans Lengas : une erreur constatée se corrige à la source.
//
// Endpoint unique et public : https://graphql.anilist.co — pas de clé d'API,
// pas d'authentification, uniquement des données publiques (une liste
// utilisateur privée est donc inaccessible, et signalée comme telle).
//
// ⚠️ CONTRAT DE ROBUSTESSE : aucune fonction de ce fichier ne lève d'exception ni
// ne provoque de fatale. Toutes les fonctions réseau renvoient une enveloppe
// uniforme :
//     ['ok' => bool, … , 'error' => string, 'error_type' => string, 'http' => int]
// où 'error' est un message déjà rédigé en français, affichable tel quel, et
// 'error_type' l'une des valeurs suivantes :
//     ''           succès
//     'network'    cURL absent, DNS, connexion refusée…
//     'timeout'    délai dépassé
//     'forbidden'  403 (Anilist bloque l'appelant)
//     'not_found'  utilisateur ou fiche introuvable
//     'private'    liste utilisateur non publique
//     'rate_limit' quota dépassé malgré le limiteur (429)
//     'http'       autre code HTTP inattendu
//     'json'       réponse illisible
//     'graphql'    requête refusée par l'API
//
// Sur le quota : Anilist autorise 90 requêtes par minute. Le limiteur intégré
// (fenêtre glissante + intervalle minimal + respect de `Retry-After`) rend le
// dépassement pratiquement impossible ; les appelants n'ont donc rien à gérer.
// ──────────────────────────────────────────────────────────────────────────────

// ──────────────────────────────────────────────────────────────────────────────
// 1. Configuration
// ──────────────────────────────────────────────────────────────────────────────

function anilist_endpoint(): string {
    return 'https://graphql.anilist.co';
}

function anilist_user_agent(): string {
    return 'Lengas (gestion de collection de mangas et animés)';
}

// Paramètres du limiteur de quota.
//   per_minute   : plafond annoncé par Anilist (90 requêtes/minute)
//   window       : durée de la fenêtre glissante, en secondes
//   min_interval : temporisation minimale entre deux requêtes, en microsecondes
//                  (700 ms ≈ 85 req/min : on reste sous le plafond même en rafale)
//   max_retry    : nombre total de tentatives pour une même requête
function anilist_rate_config(): array {
    return [
        'per_minute'   => 90,
        'window'       => 60,
        'min_interval' => 700000,
        'max_retry'    => 3,
    ];
}

// Durée de validité du cache des fiches, en secondes (24 h).
function anilist_cache_ttl(): int {
    return 86400;
}

// ──────────────────────────────────────────────────────────────────────────────
// 2. Limiteur de quota (fenêtre glissante + temporisation + blocage après 429)
// ──────────────────────────────────────────────────────────────────────────────

// État partagé du limiteur, renvoyé PAR RÉFÉRENCE pour que les appelants
// puissent le modifier. Durée de vie = celle du processus PHP, ce qui couvre
// exactement le cas critique : une campagne d'import qui enchaîne les requêtes.
function &anilist_rate_state(): array {
    static $state = [
        'stamps'        => [], // horodatages (float) des requêtes de la fenêtre
        'blocked_until' => 0.0, // fin d'un blocage imposé par un 429
        'total'         => 0,  // nombre de requêtes émises depuis le début
    ];
    return $state;
}

// Enregistre un blocage imposé par Anilist (en-tête `Retry-After`).
function anilist_rate_block(int $seconds): void {
    if ($seconds <= 0) return;
    $state =& anilist_rate_state();
    $until = microtime(true) + $seconds;
    if ($until > $state['blocked_until']) {
        $state['blocked_until'] = $until;
    }
}

// Attend le temps nécessaire, puis comptabilise la requête sur le point d'être
// émise. Appelée systématiquement par anilist_graphql() : rien d'autre à faire
// côté appelant.
function anilist_rate_wait(): void {
    $cfg    = anilist_rate_config();
    $state =& anilist_rate_state();

    // ── Blocage en cours (suite à un 429) ────────────────────────────────────
    $now = microtime(true);
    if ($state['blocked_until'] > $now) {
        $sleep = (int)ceil(($state['blocked_until'] - $now) * 1000000);
        usleep(min($sleep, 60000000)); // jamais plus d'une minute d'un coup
    }

    // ── Temporisation minimale entre deux requêtes ───────────────────────────
    if (!empty($state['stamps'])) {
        $last  = (float)end($state['stamps']);
        $delta = (microtime(true) - $last) * 1000000;
        if ($delta < $cfg['min_interval']) {
            usleep((int)($cfg['min_interval'] - $delta));
        }
    }

    // ── Fenêtre glissante : au plus `per_minute` requêtes par minute ─────────
    $state['stamps'] = anilist_rate_prune($state['stamps'], $cfg['window']);
    if (count($state['stamps']) >= $cfg['per_minute']) {
        $oldest = (float)$state['stamps'][0];
        $wait   = (int)ceil(($oldest + $cfg['window'] - microtime(true)) * 1000000) + 100000;
        if ($wait > 0) {
            usleep(min($wait, 65000000));
        }
        $state['stamps'] = anilist_rate_prune($state['stamps'], $cfg['window']);
    }

    $state['stamps'][] = microtime(true);
    $state['total']++;
}

// Ne conserve que les horodatages encore dans la fenêtre.
function anilist_rate_prune(array $stamps, int $window): array {
    $limit = microtime(true) - $window;
    $out   = [];
    foreach ($stamps as $t) {
        if ((float)$t > $limit) $out[] = (float)$t;
    }
    return $out;
}

// Nombre de requêtes émises depuis le début du processus (diagnostic, journaux).
function anilist_request_count(): int {
    $state =& anilist_rate_state();
    return (int)$state['total'];
}

// Estimation, en secondes, du temps que prendra un lot de N requêtes en
// respectant le quota. Sert à l'estimation de durée d'une campagne d'import.
function anilist_estimate_duration(int $requests): int {
    if ($requests <= 0) return 0;
    $cfg = anilist_rate_config();
    // Temporisation minimale d'un côté, plafond par minute de l'autre : c'est le
    // plus contraignant des deux qui donne la cadence réelle.
    $by_interval = $requests * ($cfg['min_interval'] / 1000000);
    $by_quota    = ($requests / max(1, $cfg['per_minute'])) * $cfg['window'];
    return (int)ceil(max($by_interval, $by_quota));
}

// ──────────────────────────────────────────────────────────────────────────────
// 3. Requête GraphQL de bas niveau
// ──────────────────────────────────────────────────────────────────────────────

// Enveloppe d'erreur normalisée.
function anilist_fail(string $type, string $message, int $http = 0, array $extra = []): array {
    return array_merge([
        'ok'          => false,
        'data'        => null,
        'http'        => $http,
        'error'       => $message,
        'error_type'  => $type,
        'retry_after' => 0,
    ], $extra);
}

// Exécute une requête GraphQL. Ne lève jamais d'exception.
// Retour : ['ok','data','http','error','error_type','retry_after'].
// $attempt est interne (relances automatiques) : ne pas le renseigner.
function anilist_graphql(string $query, array $variables = [], int $timeout = 20, int $attempt = 1): array {
    if (!function_exists('curl_init')) {
        return anilist_fail('network', "L'extension cURL de PHP est absente : impossible de contacter Anilist.");
    }

    $payload = json_encode(
        ['query' => $query, 'variables' => empty($variables) ? new stdClass() : $variables],
        JSON_UNESCAPED_UNICODE
    );
    if ($payload === false) {
        return anilist_fail('json', "Requête Anilist impossible à encoder.");
    }

    $cfg = anilist_rate_config();
    anilist_rate_wait();

    $headers_in = [];
    $ch = curl_init(anilist_endpoint());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => anilist_user_agent(),
        CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers_in) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers_in[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    $body  = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $cerr  = curl_error($ch);
    curl_close($ch);

    // ── Échec réseau (DNS, connexion, délai dépassé) ─────────────────────────
    if ($body === false || $errno !== 0) {
        $is_timeout = ($errno === 28);
        if ($attempt < $cfg['max_retry']) {
            usleep(900000);
            return anilist_graphql($query, $variables, $timeout, $attempt + 1);
        }
        return anilist_fail(
            $is_timeout ? 'timeout' : 'network',
            $is_timeout
                ? "Anilist n'a pas répondu dans le délai imparti. Réessayez dans quelques instants."
                : "Impossible de joindre Anilist" . ($cerr !== '' ? ' (' . $cerr . ')' : '') . '.',
            $code
        );
    }

    // ── Quota dépassé : respect strict de Retry-After ────────────────────────
    if ($code === 429) {
        $retry = isset($headers_in['retry-after']) ? (int)$headers_in['retry-after'] : 60;
        if ($retry <= 0) $retry = 60;
        anilist_rate_block($retry);
        if ($attempt < $cfg['max_retry'] && $retry <= 60) {
            sleep($retry);
            return anilist_graphql($query, $variables, $timeout, $attempt + 1);
        }
        return anilist_fail(
            'rate_limit',
            "Quota Anilist atteint. Nouvelle tentative possible dans " . $retry . " secondes.",
            $code,
            ['retry_after' => $retry]
        );
    }

    // ── 403 : Anilist refuse l'appelant (filtrage, blocage d'IP…) ────────────
    if ($code === 403) {
        return anilist_fail(
            'forbidden',
            "Anilist a refusé la requête (403). Le service est peut-être temporairement inaccessible depuis ce serveur.",
            $code
        );
    }

    // ── Panne serveur : une relance, puis on abandonne proprement ────────────
    if ($code >= 500) {
        if ($attempt < $cfg['max_retry']) {
            sleep(2);
            return anilist_graphql($query, $variables, $timeout, $attempt + 1);
        }
        return anilist_fail('http', "Anilist rencontre un problème (erreur " . $code . "). Réessayez plus tard.", $code);
    }

    // ── Réponse illisible ────────────────────────────────────────────────────
    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        return anilist_fail('json', "Réponse d'Anilist illisible (contenu inattendu).", $code);
    }

    // ── Erreurs GraphQL ──────────────────────────────────────────────────────
    $gql_error = '';
    $gql_status = 0;
    if (!empty($json['errors']) && is_array($json['errors'])) {
        $first      = is_array($json['errors'][0] ?? null) ? $json['errors'][0] : [];
        $gql_error  = trim((string)($first['message'] ?? ''));
        $gql_status = (int)($first['status'] ?? 0);
    }

    $data = $json['data'] ?? null;

    // Données absentes : c'est un échec, quel que soit le code HTTP.
    if (!is_array($data) || anilist_all_null($data)) {
        if ($gql_status === 404 || stripos($gql_error, 'not found') !== false) {
            return anilist_fail('not_found', "Ressource introuvable sur Anilist.", $code ?: 404);
        }
        if ($gql_status === 400 && $gql_error !== '') {
            return anilist_fail('graphql', "Anilist a refusé la requête : " . $gql_error, $code);
        }
        if ($code !== 200 && $code !== 0) {
            return anilist_fail('http', "Anilist a répondu de façon inattendue (code " . $code . ").", $code);
        }
        return anilist_fail(
            'graphql',
            $gql_error !== '' ? "Anilist : " . $gql_error : "Anilist n'a renvoyé aucune donnée."
        );
    }

    // Données présentes (éventuellement accompagnées d'erreurs partielles).
    return [
        'ok'          => true,
        'data'        => $data,
        'http'        => $code,
        'error'       => $gql_error,
        'error_type'  => '',
        'retry_after' => 0,
    ];
}

// Le bloc `data` ne contient-il que des valeurs nulles ? (cas d'un utilisateur
// inexistant : Anilist renvoie data.User = null avec un code 404.)
function anilist_all_null(array $data): bool {
    foreach ($data as $value) {
        if ($value !== null) return false;
    }
    return true;
}

// ──────────────────────────────────────────────────────────────────────────────
// 4. Correspondances Anilist → Lengas
// ──────────────────────────────────────────────────────────────────────────────

// ── Genres ───────────────────────────────────────────────────────────────────
// Anilist expose 19 genres officiels, et eux seuls. La colonne `genres` de la
// table `series` étant partagée avec la Mangathèque, les libellés retenus sont
// alignés sur ceux de mangaupdates.php (« Meccha », « Science fiction »…) afin
// que les filtres regroupent correctement mangas et animés.
//
// ⚠️ TOUT GENRE INCONNU EST IGNORÉ : pas de verbatim, contrairement à
// MangaUpdates. La liste d'Anilist est fermée, un genre absent de ce tableau
// signalerait une évolution de leur côté — à traiter ici, pas dans les données.
function anilist_genre_map(): array {
    return [
        'action'         => 'Action',
        'adventure'      => 'Aventure',
        'comedy'         => 'Comédie',
        'drama'          => 'Drame',
        'ecchi'          => 'Ecchi',
        'fantasy'        => 'Fantaisie',
        'hentai'         => 'Hentai',
        'horror'         => 'Horreur',
        'mahou shoujo'   => 'Petite fille magique',
        'mecha'          => 'Meccha',
        'music'          => 'Musique',
        'mystery'        => 'Mystère',
        'psychological'  => 'Psychologique',
        'romance'        => 'Romance',
        'sci-fi'         => 'Science fiction',
        'slice of life'  => 'Tranche de vie',
        'sports'         => 'Sport',
        'supernatural'   => 'Surnaturel',
        'thriller'       => 'Suspense',
    ];
}

// Traduit un genre Anilist. Renvoie '' si le genre est inconnu (donc à ignorer).
function anilist_translate_genre(string $genre): string {
    $key = mb_strtolower(trim($genre));
    if ($key === '') return '';
    $map = anilist_genre_map();
    return $map[$key] ?? '';
}

// Traduit une liste de genres : inconnus écartés, doublons supprimés, ordre
// d'origine conservé.
function anilist_translate_genres(array $genres): array {
    $out  = [];
    $seen = [];
    foreach ($genres as $g) {
        $fr = anilist_translate_genre((string)$g);
        if ($fr === '') continue;
        $k = mb_strtolower($fr);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $fr;
    }
    return $out;
}

// ── Formats ──────────────────────────────────────────────────────────────────
// Les formats de type manga (MANGA, NOVEL, ONE_SHOT) ne remontent jamais ici :
// toutes les requêtes sont filtrées sur `type: ANIME`.
function anilist_format_map(): array {
    return [
        'TV'       => 'Série TV',
        'TV_SHORT' => 'Format court',
        'MOVIE'    => 'Film',
        'SPECIAL'  => 'Spécial',
        'OVA'      => 'OAV',
        'ONA'      => 'ONA',
        'MUSIC'    => 'Clip musical',
    ];
}

// Clés de formats, dans l'ordre d'affichage attendu (cases d'exclusion de
// l'import, réglages de statistiques par format).
function anilist_format_keys(): array {
    return array_keys(anilist_format_map());
}

// Libellé français d'un format. Format absent ou inconnu → 'Format inconnu'.
function anilist_format_label(?string $format): string {
    $key = strtoupper(trim((string)$format));
    if ($key === '') return 'Format inconnu';
    $map = anilist_format_map();
    return $map[$key] ?? 'Format inconnu';
}

// Le format désigne-t-il une œuvre unitaire (un seul « épisode »
// par nature) ? Sert au bloc « Épisodes » pour créer d'office 1 épisode.
function anilist_format_is_single(?string $format): bool {
    return in_array(strtoupper(trim((string)$format)), ['MOVIE', 'MUSIC'], true);
}

// ── Statuts de diffusion ─────────────────────────────────────────────────────
// Vers les tags de statut déjà utilisés par la Mangathèque, à l'identique.
// NOT_YET_RELEASED n'a volontairement PAS d'équivalent : une série non encore
// diffusée relève de la liste d'envies, jamais de la vidéothèque. Le connecteur
// se contente de la signaler (voir anilist_is_not_yet_released()).
function anilist_status_map(): array {
    return [
        'RELEASING' => 'en cours',
        'FINISHED'  => 'terminée',
        'HIATUS'    => 'en pause',
        'CANCELLED' => 'abandonnée',
    ];
}

// Tag de statut du site correspondant au statut Anilist, ou null si aucun
// (NOT_YET_RELEASED, statut vide ou inconnu).
function anilist_status_tag(?string $status): ?string {
    $key = strtoupper(trim((string)$status));
    $map = anilist_status_map();
    return $map[$key] ?? null;
}

// La série n'est-elle pas encore diffusée ? (cas particulier structurant)
function anilist_is_not_yet_released(?string $status): bool {
    return strtoupper(trim((string)$status)) === 'NOT_YET_RELEASED';
}

// Libellé lisible d'un statut de diffusion, y compris pour NOT_YET_RELEASED.
function anilist_status_label(?string $status): string {
    if (anilist_is_not_yet_released($status)) return 'À venir';
    $tag = anilist_status_tag($status);
    if ($tag === null) return 'Statut inconnu';
    return ucfirst($tag);
}

// ── Statuts de liste (MediaList) ─────────────────────────────────────────────
// Utilisés par l'écran d'aperçu de l'import : décomptes et cases à cocher.
// L'aiguillage vers la vidéothèque ou la liste d'envies relève de l'outil
// d'import, pas du connecteur.
function anilist_list_status_map(): array {
    return [
        'CURRENT'   => 'En cours de visionnage',
        'COMPLETED' => 'Terminées',
        'REPEATING' => 'En revisionnage',
        'PAUSED'    => 'En pause',
        'DROPPED'   => 'Abandonnées',
        'PLANNING'  => 'En projet',
    ];
}

function anilist_list_status_keys(): array {
    return array_keys(anilist_list_status_map());
}

function anilist_list_status_label(?string $status): string {
    $key = strtoupper(trim((string)$status));
    $map = anilist_list_status_map();
    return $map[$key] ?? 'Statut inconnu';
}

// ── Notation ─────────────────────────────────────────────────────────────────
// Toutes les requêtes demandent le score au format POINT_100, quel que soit le
// réglage d'affichage du compte : la conversion part donc toujours d'un 0–100.
//   ≥ 70    → apprecie    40–69 → mitige
//   1–39    → deteste     0     → pas de note ('')
function anilist_score_to_rating($score): string {
    if ($score === null || $score === '') return '';
    $value = (int)round((float)$score);
    if ($value <= 0)  return '';
    if ($value >= 70) return 'apprecie';
    if ($value >= 40) return 'mitige';
    return 'deteste';
}

// ── Dates ────────────────────────────────────────────────────────────────────
// Anilist renvoie des « fuzzy dates » {year, month, day} dont chaque composant
// peut être nul. On n'accepte qu'une date complète ; sinon '' (l'import repliera
// sur `updatedAt`).
function anilist_fuzzy_date($date): string {
    if (!is_array($date)) return '';
    $y = (int)($date['year']  ?? 0);
    $m = (int)($date['month'] ?? 0);
    $d = (int)($date['day']   ?? 0);
    if ($y <= 0 || $m <= 0 || $d <= 0) return '';
    if ($m > 12 || $d > 31) return '';
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

// Horodatage Unix → date 'Y-m-d' ('' si absent).
function anilist_timestamp_date($ts): string {
    $ts = (int)$ts;
    return $ts > 0 ? date('Y-m-d', $ts) : '';
}

// ──────────────────────────────────────────────────────────────────────────────
// 5. Cache SQLite des fiches (clé = anilist_id)
// ──────────────────────────────────────────────────────────────────────────────
// La table `anilist_cache` est (re)créée par init_db() dans config.php. Elle
// stocke la fiche NORMALISÉE, pas la réponse brute : ce qu'on relit est
// directement exploitable.

// Écrit (ou remplace) une fiche en cache. Les échecs ne sont jamais mis en cache.
function anilist_cache_store(int $anilist_id, array $record): void {
    if ($anilist_id <= 0) return;
    $payload = json_encode($record, JSON_UNESCAPED_UNICODE);
    if ($payload === false) return;
    try {
        get_db()->prepare("
            INSERT OR REPLACE INTO anilist_cache (anilist_id, payload, timestamp)
            VALUES (?, ?, ?)
        ")->execute([$anilist_id, $payload, time()]);
    } catch (Exception $e) {
        // Cache indisponible : sans conséquence, on retournera sur le réseau.
    }
}

// Relit une fiche en cache, SANS appel réseau.
// $max_age = 0 → ignore l'âge. Retourne la fiche normalisée ou null.
function anilist_get_cached_media(int $anilist_id, int $max_age = 0): ?array {
    if ($anilist_id <= 0) return null;
    try {
        $stmt = get_db()->prepare("SELECT payload, timestamp FROM anilist_cache WHERE anilist_id = ?");
        $stmt->execute([$anilist_id]);
        $row = $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
    if (!$row) return null;
    if ($max_age > 0 && (time() - (int)$row['timestamp']) >= $max_age) return null;
    $record = json_decode((string)$row['payload'], true);
    return is_array($record) ? $record : null;
}

// Oublie une fiche (forçage d'une revérification).
function anilist_cache_forget(int $anilist_id): void {
    if ($anilist_id <= 0) return;
    try {
        get_db()->prepare("DELETE FROM anilist_cache WHERE anilist_id = ?")->execute([$anilist_id]);
    } catch (Exception $e) { /* sans conséquence */ }
}

// Nombre de fiches en cache (diagnostic).
function anilist_cache_count(): int {
    try {
        return (int)get_db()->query("SELECT COUNT(*) FROM anilist_cache")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// 6. Normalisation d'une fiche
// ──────────────────────────────────────────────────────────────────────────────

// Champs demandés pour toute fiche d'animé. Un seul endroit à modifier pour
// enrichir ce que le site récupère.
function anilist_media_fields(): string {
    return '
        id
        title { romaji english native userPreferred }
        synonyms
        format
        status
        episodes
        duration
        genres
        isAdult
        siteUrl
        seasonYear
        startDate { year month day }
        endDate { year month day }
        coverImage { extraLarge large medium }
        nextAiringEpisode { episode airingAt }
        studios { edges { isMain node { name } } }
    ';
}

// Convertit une fiche brute d'Anilist en structure exploitable par le site.
// Retourne null si la fiche est inutilisable (pas d'identifiant).
//
// Clés produites :
//   anilist_id, title_romaji, title_english, title_native, title_preferred,
//   title (= romaji, titre par défaut du site), synonyms, alt_titles,
//   studios, studios_text, format, format_label, is_single,
//   status, status_tag, status_label, not_yet_released,
//   genres (EN brut), genres_fr,
//   episodes (annoncés, null si inconnu), aired_episodes (déjà diffusés),
//   next_episode, next_airing_at, duration,
//   is_adult, cover, site_url, season_year, start_date, end_date, start_year
function anilist_normalize_media($media): ?array {
    if (!is_array($media)) return null;
    $id = (int)($media['id'] ?? 0);
    if ($id <= 0) return null;

    $titles    = is_array($media['title'] ?? null) ? $media['title'] : [];
    $romaji    = trim((string)($titles['romaji']        ?? ''));
    $english   = trim((string)($titles['english']       ?? ''));
    $native    = trim((string)($titles['native']        ?? ''));
    $preferred = trim((string)($titles['userPreferred'] ?? ''));

    $synonyms = [];
    foreach ((array)($media['synonyms'] ?? []) as $syn) {
        $syn = trim((string)$syn);
        if ($syn !== '') $synonyms[] = $syn;
    }

    // Titres alternatifs : romaji en tête (titre par défaut du site), puis
    // english, native et synonymes. Dédoublonnés, ordre conservé.
    $alt_titles = anilist_unique_strings(array_merge([$romaji, $english, $native], $synonyms));

    // Studios : les studios d'animation (isMain) priment ; à défaut, on garde
    // ce qui est disponible plutôt que rien.
    $main  = [];
    $other = [];
    $edges = $media['studios']['edges'] ?? [];
    if (is_array($edges)) {
        foreach ($edges as $edge) {
            if (!is_array($edge)) continue;
            $name = trim((string)($edge['node']['name'] ?? ''));
            if ($name === '') continue;
            if (!empty($edge['isMain'])) $main[] = $name;
            else                         $other[] = $name;
        }
    }
    $studios = anilist_unique_strings(!empty($main) ? $main : $other);

    $format = strtoupper(trim((string)($media['format'] ?? '')));
    $status = strtoupper(trim((string)($media['status'] ?? '')));

    $episodes = (isset($media['episodes']) && $media['episodes'] !== null)
        ? max(0, (int)$media['episodes'])
        : null;
    $next_episode = isset($media['nextAiringEpisode']['episode'])
        ? (int)$media['nextAiringEpisode']['episode']
        : null;

    $genres_raw = [];
    foreach ((array)($media['genres'] ?? []) as $g) {
        $g = trim((string)$g);
        if ($g !== '') $genres_raw[] = $g;
    }

    $cover = '';
    if (is_array($media['coverImage'] ?? null)) {
        foreach (['extraLarge', 'large', 'medium'] as $size) {
            $url = trim((string)($media['coverImage'][$size] ?? ''));
            if ($url !== '') { $cover = $url; break; }
        }
    }

    return [
        'anilist_id'       => $id,
        'title_romaji'     => $romaji,
        'title_english'    => $english,
        'title_native'     => $native,
        'title_preferred'  => $preferred,
        // Titre par défaut du site : romaji, avec repli si Anilist n'en a pas.
        'title'            => $romaji !== '' ? $romaji : ($preferred !== '' ? $preferred : ($english !== '' ? $english : $native)),
        'synonyms'         => $synonyms,
        'alt_titles'       => $alt_titles,
        'studios'          => $studios,
        'studios_text'     => implode(', ', $studios),
        'format'           => $format,
        'format_label'     => anilist_format_label($format),
        'is_single'        => anilist_format_is_single($format),
        'status'           => $status,
        'status_tag'       => anilist_status_tag($status),
        'status_label'     => anilist_status_label($status),
        'not_yet_released' => anilist_is_not_yet_released($status),
        'genres'           => $genres_raw,
        'genres_fr'        => anilist_translate_genres($genres_raw),
        'episodes'         => $episodes,
        'aired_episodes'   => anilist_aired_episodes($episodes, $next_episode, $format, $status),
        'next_episode'     => $next_episode,
        'next_airing_at'   => isset($media['nextAiringEpisode']['airingAt']) ? (int)$media['nextAiringEpisode']['airingAt'] : null,
        'duration'         => (isset($media['duration']) && $media['duration'] !== null) ? (int)$media['duration'] : null,
        'is_adult'         => !empty($media['isAdult']),
        'cover'            => $cover,
        'site_url'         => trim((string)($media['siteUrl'] ?? '')),
        'season_year'      => (int)($media['seasonYear'] ?? 0),
        'start_date'       => anilist_fuzzy_date($media['startDate'] ?? null),
        'end_date'         => anilist_fuzzy_date($media['endDate'] ?? null),
        'start_year'       => (int)($media['startDate']['year'] ?? 0),
    ];
}

// Nombre d'épisodes RÉELLEMENT DIFFUSÉS à ce jour.
// Règles, dans l'ordre :
//   1. série non encore diffusée → 0, même si le total est déjà annoncé
//   2. `nextAiringEpisode` présent → le prochain moins un (plafonné au total)
//   3. total annoncé connu → ce total
//   4. film ou clip → 1 (Anilist laisse parfois `episodes` à null)
function anilist_aired_episodes(?int $episodes, ?int $next_episode, string $format, string $status): int {
    // Un total annoncé n'est PAS un total diffusé : une série à venir affiche
    // souvent ses 12 épisodes prévus sans qu'aucun n'ait été diffusé.
    if (anilist_is_not_yet_released($status)) return 0;

    if ($next_episode !== null && $next_episode > 0) {
        $aired = $next_episode - 1;
        if ($episodes !== null && $episodes > 0 && $aired > $episodes) {
            $aired = $episodes;
        }
        return max(0, $aired);
    }
    if ($episodes !== null && $episodes > 0) return $episodes;
    if (anilist_format_is_single($format))   return 1;
    return 0;
}

// Dédoublonne une liste de chaînes (comparaison insensible à la casse et aux
// espaces) en conservant l'ordre et la casse d'origine.
function anilist_unique_strings(array $values): array {
    $out  = [];
    $seen = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '') continue;
        $key = mb_strtolower(preg_replace('/\s+/u', ' ', $value));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $value;
    }
    return $out;
}

// ──────────────────────────────────────────────────────────────────────────────
// 7. Recherche par titre
// ──────────────────────────────────────────────────────────────────────────────

// Recherche d'animés par titre. 10 résultats au maximum (plafond du site).
// Chaque résultat est une fiche complète normalisée : la modale d'ajout dispose
// donc de tout ce qu'il faut (vignette, titre romaji, année, format, statut)
// et l'import se fait sans second appel.
// Retour : ['ok','results','error','error_type','http'].
function anilist_search(string $title, int $limit = 10): array {
    $title = trim($title);
    if ($title === '') {
        return ['ok' => true, 'results' => [], 'error' => '', 'error_type' => '', 'http' => 0];
    }
    $limit = max(1, min(10, $limit));

    $query = '
        query ($search: String, $perPage: Int) {
            Page(page: 1, perPage: $perPage) {
                media(search: $search, type: ANIME, sort: [SEARCH_MATCH, POPULARITY_DESC]) {
                    ' . anilist_media_fields() . '
                }
            }
        }
    ';

    $res = anilist_graphql($query, ['search' => $title, 'perPage' => $limit]);
    if (!$res['ok']) {
        return [
            'ok' => false, 'results' => [],
            'error' => $res['error'], 'error_type' => $res['error_type'], 'http' => $res['http'],
        ];
    }

    $results = [];
    foreach ((array)($res['data']['Page']['media'] ?? []) as $media) {
        $record = anilist_normalize_media($media);
        if ($record === null) continue;
        anilist_cache_store($record['anilist_id'], $record); // le cache profite de la recherche
        $results[] = $record;
    }

    return ['ok' => true, 'results' => $results, 'error' => '', 'error_type' => '', 'http' => $res['http']];
}

// ──────────────────────────────────────────────────────────────────────────────
// 8. Fiche complète par identifiant
// ──────────────────────────────────────────────────────────────────────────────

// Récupère une fiche par son anilist_id, cache de 24 h.
// $force = true → ignore le cache (revérification, synchronisation forcée).
// Retour : ['ok','media','cached','error','error_type','http'].
function anilist_fetch_media(int $anilist_id, bool $force = false): array {
    if ($anilist_id <= 0) {
        return ['ok' => false, 'media' => null, 'cached' => false,
                'error' => "Identifiant Anilist invalide.", 'error_type' => 'not_found', 'http' => 0];
    }

    if (!$force) {
        $cached = anilist_get_cached_media($anilist_id, anilist_cache_ttl());
        if ($cached !== null) {
            return ['ok' => true, 'media' => $cached, 'cached' => true,
                    'error' => '', 'error_type' => '', 'http' => 0];
        }
    }

    $query = '
        query ($id: Int) {
            Media(id: $id, type: ANIME) {
                ' . anilist_media_fields() . '
            }
        }
    ';

    $res = anilist_graphql($query, ['id' => $anilist_id]);
    if (!$res['ok']) {
        $message = ($res['error_type'] === 'not_found')
            ? "Fiche Anilist introuvable (identifiant " . $anilist_id . ")."
            : $res['error'];
        return ['ok' => false, 'media' => null, 'cached' => false,
                'error' => $message, 'error_type' => $res['error_type'], 'http' => $res['http']];
    }

    $record = anilist_normalize_media($res['data']['Media'] ?? null);
    if ($record === null) {
        return ['ok' => false, 'media' => null, 'cached' => false,
                'error' => "Fiche Anilist introuvable (identifiant " . $anilist_id . ").",
                'error_type' => 'not_found', 'http' => $res['http']];
    }

    anilist_cache_store($record['anilist_id'], $record);
    return ['ok' => true, 'media' => $record, 'cached' => false,
            'error' => '', 'error_type' => '', 'http' => $res['http']];
}

// Récupère plusieurs fiches en une seule requête (50 au maximum par appel,
// plafond d'Anilist). Économise massivement le quota lors d'une campagne.
// Retour : ['ok','media' => [anilist_id => fiche], 'missing' => [ids],
//           'error','error_type','http'].
function anilist_fetch_media_batch(array $anilist_ids, bool $force = false): array {
    $wanted = [];
    foreach ($anilist_ids as $id) {
        $id = (int)$id;
        if ($id > 0) $wanted[$id] = true;
    }
    $wanted = array_keys($wanted);
    if (empty($wanted)) {
        return ['ok' => true, 'media' => [], 'missing' => [], 'error' => '', 'error_type' => '', 'http' => 0];
    }

    $out     = [];
    $to_fetch = [];

    if (!$force) {
        foreach ($wanted as $id) {
            $cached = anilist_get_cached_media($id, anilist_cache_ttl());
            if ($cached !== null) $out[$id] = $cached;
            else                  $to_fetch[] = $id;
        }
    } else {
        $to_fetch = $wanted;
    }

    $query = '
        query ($ids: [Int], $perPage: Int) {
            Page(page: 1, perPage: $perPage) {
                media(id_in: $ids, type: ANIME) {
                    ' . anilist_media_fields() . '
                }
            }
        }
    ';

    foreach (array_chunk($to_fetch, 50) as $chunk) {
        $res = anilist_graphql($query, ['ids' => array_values($chunk), 'perPage' => count($chunk)]);
        if (!$res['ok']) {
            return [
                'ok' => false, 'media' => $out,
                'missing' => array_values(array_diff($wanted, array_keys($out))),
                'error' => $res['error'], 'error_type' => $res['error_type'], 'http' => $res['http'],
            ];
        }
        foreach ((array)($res['data']['Page']['media'] ?? []) as $media) {
            $record = anilist_normalize_media($media);
            if ($record === null) continue;
            anilist_cache_store($record['anilist_id'], $record);
            $out[$record['anilist_id']] = $record;
        }
    }

    return [
        'ok'         => true,
        'media'      => $out,
        // Identifiants sans correspondance sur Anilist (fiche supprimée, id erroné).
        'missing'    => array_values(array_diff($wanted, array_keys($out))),
        'error'      => '',
        'error_type' => '',
        'http'       => 200,
    ];
}

// ──────────────────────────────────────────────────────────────────────────────
// 9. Liste d'un utilisateur (par pseudo public)
// ──────────────────────────────────────────────────────────────────────────────

// Récupère l'intégralité de la liste ANIME d'un utilisateur, par tranches.
//
// ⚠️ DÉDOUBLONNAGE : les listes personnalisées d'Anilist sont ADDITIVES. Une même
// série remonte dans sa liste de statut ET dans chacune des listes personnalisées
// où l'utilisateur l'a rangée. Le tableau renvoyé est donc INDEXÉ PAR anilist_id :
// une série = une entrée, l'appartenance aux listes personnalisées n'étant qu'un
// attribut (`custom_lists`). Le statut de liste reste l'axe principal.
//
// $on_progress : callable(int $entrees_recuperees, int $tranche) — facultatif,
// appelé après chaque tranche pour alimenter une progression SSE.
//
// Retour :
//   ['ok','entries','custom_lists','count','error','error_type','http']
// où chaque entrée vaut :
//   ['anilist_id','list_status','list_status_label','progress','score','rating',
//    'repeat','started_at','completed_at','updated_at','updated_at_date',
//    'watched_at' (completedAt, repli updatedAt), 'custom_lists', 'private',
//    'media' => fiche normalisée]
function anilist_fetch_user_list(string $username, $on_progress = null): array {
    $username = trim($username);
    if ($username === '') {
        return ['ok' => false, 'entries' => [], 'custom_lists' => [], 'count' => 0,
                'error' => "Aucun pseudo Anilist saisi.", 'error_type' => 'not_found', 'http' => 0];
    }

    $query = '
        query ($name: String, $chunk: Int, $perChunk: Int) {
            MediaListCollection(userName: $name, type: ANIME, chunk: $chunk, perChunk: $perChunk, forceSingleCompletedList: true) {
                hasNextChunk
                lists {
                    name
                    status
                    isCustomList
                    entries {
                        status
                        progress
                        score(format: POINT_100)
                        repeat
                        private
                        updatedAt
                        startedAt { year month day }
                        completedAt { year month day }
                        customLists
                        media {
                            ' . anilist_media_fields() . '
                        }
                    }
                }
            }
        }
    ';

    $entries      = [];
    $custom_lists = [];
    $chunk        = 1;

    do {
        $res = anilist_graphql($query, ['name' => $username, 'chunk' => $chunk, 'perChunk' => 250], 30);
        if (!$res['ok']) {
            return [
                'ok' => false, 'entries' => $entries, 'custom_lists' => array_values($custom_lists),
                'count' => count($entries),
                'error' => anilist_user_error_message($res, $username),
                'error_type' => ($res['error_type'] === 'not_found') ? 'private' : $res['error_type'],
                'http' => $res['http'],
            ];
        }

        $collection = $res['data']['MediaListCollection'] ?? null;
        if (!is_array($collection)) {
            return [
                'ok' => false, 'entries' => [], 'custom_lists' => [], 'count' => 0,
                'error' => "La liste de « " . $username . " » est introuvable ou privée.",
                'error_type' => 'private', 'http' => $res['http'],
            ];
        }

        foreach ((array)($collection['lists'] ?? []) as $list) {
            $is_custom = !empty($list['isCustomList']);
            $list_name = trim((string)($list['name'] ?? ''));
            if ($is_custom && $list_name !== '') {
                $custom_lists[mb_strtolower($list_name)] = $list_name;
            }

            foreach ((array)($list['entries'] ?? []) as $raw) {
                $media = anilist_normalize_media($raw['media'] ?? null);
                if ($media === null) continue;
                $id = $media['anilist_id'];

                // Listes personnalisées de CETTE entrée (objet {nom: bool}).
                $entry_custom = [];
                if (isset($raw['customLists']) && is_array($raw['customLists'])) {
                    foreach ($raw['customLists'] as $name => $enabled) {
                        if (!$enabled) continue;
                        $name = trim((string)$name);
                        if ($name === '') continue;
                        $entry_custom[] = $name;
                        $custom_lists[mb_strtolower($name)] = $name;
                    }
                }

                if (isset($entries[$id])) {
                    // Doublon (même série vue depuis une autre liste) : on ne
                    // fusionne que l'appartenance aux listes personnalisées.
                    $entries[$id]['custom_lists'] = anilist_unique_strings(
                        array_merge($entries[$id]['custom_lists'], $entry_custom)
                    );
                    continue;
                }

                anilist_cache_store($id, $media);

                $completed = anilist_fuzzy_date($raw['completedAt'] ?? null);
                $updated   = (int)($raw['updatedAt'] ?? 0);

                $entries[$id] = [
                    'anilist_id'        => $id,
                    'list_status'       => strtoupper(trim((string)($raw['status'] ?? ''))),
                    'list_status_label' => anilist_list_status_label($raw['status'] ?? ''),
                    'progress'          => max(0, (int)($raw['progress'] ?? 0)),
                    'score'             => (float)($raw['score'] ?? 0),
                    'rating'            => anilist_score_to_rating($raw['score'] ?? 0),
                    'repeat'            => max(0, (int)($raw['repeat'] ?? 0)),
                    'private'           => !empty($raw['private']),
                    'started_at'        => anilist_fuzzy_date($raw['startedAt'] ?? null),
                    'completed_at'      => $completed,
                    'updated_at'        => $updated,
                    'updated_at_date'   => anilist_timestamp_date($updated),
                    // Date de visionnage retenue par le site : completedAt,
                    // repli sur updatedAt (décision structurante du bloc 8).
                    'watched_at'        => $completed !== '' ? $completed : anilist_timestamp_date($updated),
                    'custom_lists'      => $entry_custom,
                    'media'             => $media,
                ];
            }
        }

        if (is_callable($on_progress)) {
            call_user_func($on_progress, count($entries), $chunk);
        }

        $has_next = !empty($collection['hasNextChunk']);
        $chunk++;
    } while ($has_next && $chunk <= 40); // garde-fou : 10 000 entrées au maximum

    return [
        'ok'           => true,
        'entries'      => $entries,
        'custom_lists' => array_values($custom_lists),
        'count'        => count($entries),
        'error'        => '',
        'error_type'   => '',
        'http'         => 200,
    ];
}

// Message d'erreur adapté quand la requête porte sur un utilisateur : Anilist
// répond « Not Found » aussi bien pour un pseudo inexistant que pour une liste
// non publique, sans jamais distinguer les deux cas.
function anilist_user_error_message(array $res, string $username): string {
    if (($res['error_type'] ?? '') === 'not_found') {
        return "Aucune liste publique trouvée pour « " . $username . " ». "
             . "Vérifiez l'orthographe du pseudo, et que la liste n'est pas privée dans les réglages du compte Anilist.";
    }
    return (string)($res['error'] ?? '');
}

// ──────────────────────────────────────────────────────────────────────────────
// 10. Listes personnalisées déclarées et favoris natifs
// ──────────────────────────────────────────────────────────────────────────────

// Listes personnalisées DÉCLARÉES par l'utilisateur dans ses réglages Anilist
// (`User.mediaListOptions.animeList.customLists`). Elles peuvent être vides de
// toute série : c'est la liste des choix possibles, pas leur contenu. Alimente
// le sélecteur « séries favorites » de l'écran d'aperçu de l'import.
// Retour : ['ok','lists','user','error','error_type','http'].
function anilist_fetch_user_custom_lists(string $username): array {
    $username = trim($username);
    if ($username === '') {
        return ['ok' => false, 'lists' => [], 'user' => null,
                'error' => "Aucun pseudo Anilist saisi.", 'error_type' => 'not_found', 'http' => 0];
    }

    $query = '
        query ($name: String) {
            User(name: $name) {
                id
                name
                mediaListOptions { animeList { customLists } }
            }
        }
    ';

    $res = anilist_graphql($query, ['name' => $username]);
    if (!$res['ok']) {
        return ['ok' => false, 'lists' => [], 'user' => null,
                'error' => anilist_user_error_message($res, $username),
                'error_type' => $res['error_type'], 'http' => $res['http']];
    }

    $user  = $res['data']['User'] ?? null;
    $lists = [];
    foreach ((array)($user['mediaListOptions']['animeList']['customLists'] ?? []) as $name) {
        $name = trim((string)$name);
        if ($name !== '') $lists[] = $name;
    }

    return [
        'ok'         => true,
        'lists'      => anilist_unique_strings($lists),
        'user'       => is_array($user) ? ['id' => (int)($user['id'] ?? 0), 'name' => (string)($user['name'] ?? $username)] : null,
        'error'      => '',
        'error_type' => '',
        'http'       => $res['http'],
    ];
}

// Favoris NATIFS d'Anilist (les cœurs), distincts des listes personnalisées.
// Retour : ['ok','ids','anime','count','error','error_type','http'] où `anime`
// vaut [['anilist_id','title'], …] et `ids` la seule liste des identifiants.
function anilist_fetch_user_favourites(string $username): array {
    $username = trim($username);
    if ($username === '') {
        return ['ok' => false, 'ids' => [], 'anime' => [], 'count' => 0,
                'error' => "Aucun pseudo Anilist saisi.", 'error_type' => 'not_found', 'http' => 0];
    }

    $query = '
        query ($name: String, $page: Int) {
            User(name: $name) {
                favourites {
                    anime(page: $page, perPage: 50) {
                        pageInfo { hasNextPage }
                        nodes { id title { romaji english native userPreferred } }
                    }
                }
            }
        }
    ';

    $ids   = [];
    $anime = [];
    $page  = 1;

    do {
        $res = anilist_graphql($query, ['name' => $username, 'page' => $page]);
        if (!$res['ok']) {
            return ['ok' => false, 'ids' => $ids, 'anime' => $anime, 'count' => count($ids),
                    'error' => anilist_user_error_message($res, $username),
                    'error_type' => $res['error_type'], 'http' => $res['http']];
        }

        $block = $res['data']['User']['favourites']['anime'] ?? null;
        if (!is_array($block)) break;

        foreach ((array)($block['nodes'] ?? []) as $node) {
            $id = (int)($node['id'] ?? 0);
            if ($id <= 0 || isset($ids[$id])) continue;
            $ids[$id] = true;
            $titles   = is_array($node['title'] ?? null) ? $node['title'] : [];
            $title    = trim((string)($titles['romaji'] ?? ''));
            if ($title === '') $title = trim((string)($titles['userPreferred'] ?? ''));
            if ($title === '') $title = trim((string)($titles['english'] ?? ''));
            if ($title === '') $title = trim((string)($titles['native'] ?? ''));
            $anime[] = ['anilist_id' => $id, 'title' => $title];
        }

        $has_next = !empty($block['pageInfo']['hasNextPage']);
        $page++;
    } while ($has_next && $page <= 40); // garde-fou : 2 000 favoris au maximum

    return [
        'ok'         => true,
        'ids'        => array_map('intval', array_keys($ids)),
        'anime'      => $anime,
        'count'      => count($ids),
        'error'      => '',
        'error_type' => '',
        'http'       => 200,
    ];
}

// ──────────────────────────────────────────────────────────────────────────────
// 11. Diagnostic
// ──────────────────────────────────────────────────────────────────────────────

// Test de connectivité de l'API Anilist (outil de vérification d'intégrité).
// Retour : ['ok','http','error'].
function anilist_check_api(): array {
    $res = anilist_graphql('query { Media(id: 1, type: ANIME) { id } }', [], 10);
    return [
        'ok'    => (bool)$res['ok'],
        'http'  => (int)$res['http'],
        'error' => (string)$res['error'],
    ];
}
