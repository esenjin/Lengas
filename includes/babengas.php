<?php
// ──────────────────────────────────────────────────────────────────────────────
// includes/babengas.php — Intégration du microservice Babengas (données Manga News)
//
// Babengas est un microservice Docker tournant sur un homelab (IP résidentielle)
// qui interroge Manga News pour connaître le nombre de tomes VF RÉELLEMENT PARUS
// d'une série, ainsi que son statut de publication. Une seule page par série
// suffit : la fiche Manga News porte le décompte VF, le statut VF/VO et les
// dates de sortie.
//
// L'intégration est ENTIÈREMENT FACULTATIVE (même principe que Vestikan) :
// sans les trois options `babengas_url`, `babengas_key` et `babengas_enabled`,
// la fonctionnalité reste invisible et Lengas fonctionne exactement comme avant.
//
// Contrat API consommé (header X-Babengas-Key sauf /sante) :
//   GET    /sante            → état du service
//   POST   /campagne         → crée une campagne, retourne un campagne_id
//   GET    /campagne/{id}    → avancement + résultats (nb_tomes, nb_reference,
//                              statut_vf, statut_vo, …)
//   DELETE /campagne/{id}    → annule
//
// Le statut de publication VF de Manga News est jugé fiable : il est conservé
// dans le cache (colonnes statut_vf / statut_vo) et fait foi pour l'édition
// française. MangaUpdates reste la référence pour les genres et titres
// alternatifs.
// ──────────────────────────────────────────────────────────────────────────────

// ── Configuration ─────────────────────────────────────────────────────────────

// URL de base du service, sans barre oblique finale ('' si non configuré).
function babengas_url(): string {
    $opts = function_exists('load_options') ? load_options() : [];
    return rtrim(trim((string)($opts['babengas_url'] ?? '')), '/');
}

// Clé partagée avec le service ('' si non configurée).
function babengas_key(): string {
    $opts = function_exists('load_options') ? load_options() : [];
    return trim((string)($opts['babengas_key'] ?? ''));
}

// L'intégration est-elle utilisable ? (case cochée + URL + clé renseignées)
function babengas_enabled(): bool {
    $opts = function_exists('load_options') ? load_options() : [];
    if (empty($opts['babengas_enabled'])) return false;
    return babengas_url() !== '' && babengas_key() !== '';
}

// ── Validation des URL Manga News ─────────────────────────────────────────────
//
// Seules les fiches SÉRIE VF sont acceptées : /index.php/serie/Nom-de-la-serie
// (le « /index.php » est facultatif). Les sous-pages d'une série
// (/serie/critique/Nom, /serie/editions/Nom…) sont acceptées et ramenées à la
// fiche principale. Les fiches VO (/serie-vo/…) et les fiches de tome
// (/manga/…/vol-N) sont refusées — comme le fait Babengas (url_invalide).
// Les one-shots ont, sur Manga News, une fiche série comme les autres.

// Sous-pages d'une fiche série (même liste que Babengas, src/MangaNews.php).
const MANGANEWS_SOUS_PAGES = 'critique|news|video|infos|editions|editionsVo|images|personnages|avis';

// Extrait le slug d'une fiche série : …/serie/March-comes-in-like-a-lion →
// "March-comes-in-like-a-lion". null si l'URL n'est pas une fiche série.
function manganews_serie_slug_from_url(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    if (!preg_match('#^https?://(?:www\.)?manga-news\.com(?::\d+)?/#i', $url)) return null;

    $chemin = (string)parse_url($url, PHP_URL_PATH);
    if (preg_match('#/(?:index\.php/)?serie/(?:(?:' . MANGANEWS_SOUS_PAGES . ')/)?([^/?\#\s]+)/?$#', $chemin, $m)) {
        $slug = rawurldecode($m[1]);
        return $slug !== '' ? $slug : null;
    }
    return null;
}

// URL canonique de la fiche d'une série, depuis son slug.
// Les « : » du slug sont conservés tels quels : le site les emploie ainsi
// dans ses propres liens (ex. Re:zero).
function manganews_url_from_slug(string $slug): string {
    return 'https://www.manga-news.com/index.php/serie/' . str_replace('%3A', ':', rawurlencode($slug));
}

// L'URL est-elle une fiche série exploitable ?
function manganews_url_is_valid(string $url): bool {
    return manganews_serie_slug_from_url($url) !== null;
}

// Ramène une URL valide à sa forme canonique (fiche principale, sans
// paramètres ni sous-page). null si l'URL n'est pas valide.
function manganews_normalize_url(string $url): ?string {
    $slug = manganews_serie_slug_from_url($url);
    return $slug === null ? null : manganews_url_from_slug($slug);
}

// ── Requête cURL vers Babengas ────────────────────────────────────────────────
// Retourne ['ok'=>bool, 'http'=>int, 'data'=>array|null, 'error'=>string].
function babengas_request(string $method, string $path, ?array $payload = null, int $timeout = 15): array {
    $base = babengas_url();
    $key  = babengas_key();

    if ($base === '' || $key === '') {
        return ['ok' => false, 'http' => 0, 'data' => null,
                'error' => 'Babengas n\'est pas configuré (URL ou clé manquante).'];
    }

    $ch   = curl_init($base . '/' . ltrim($path, '/'));
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Babengas-Key: ' . $key,
        ],
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
                'error' => 'Réponse illisible du service (JSON invalide).'];
    }

    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'http' => $code, 'data' => $data,
                'error' => (string)($data['message'] ?? $data['erreur'] ?? "Erreur HTTP $code")];
    }

    return ['ok' => true, 'http' => $code, 'data' => $data, 'error' => ''];
}

// ── Sonde /sante (sans authentification côté service, mais on réutilise cURL) ──
// Retourne ['ok'=>bool,'http'=>int,'version'=>string,'actif'=>bool,'error'=>string].
function babengas_check_service(): array {
    $base = babengas_url();
    if ($base === '') {
        return ['ok' => false, 'http' => 0, 'version' => '', 'actif' => false,
                'error' => 'Aucune URL Babengas renseignée.'];
    }

    $ch = curl_init($base . '/sante');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Lengas (gestion de collection de mangas)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '' || $code !== 200) {
        return ['ok' => false, 'http' => $code, 'version' => '', 'actif' => false,
                'error' => $err !== '' ? $err : "Erreur HTTP $code"];
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data) || ($data['statut'] ?? '') !== 'ok') {
        return ['ok' => false, 'http' => $code, 'version' => '', 'actif' => false,
                'error' => 'Réponse inattendue du service.'];
    }

    return [
        'ok'      => true,
        'http'    => $code,
        'version' => (string)($data['version'] ?? ''),
        'actif'   => !empty($data['actif']),
        'error'   => '',
    ];
}

// ── Campagnes ─────────────────────────────────────────────────────────────────

// Crée une campagne. $series = [['id'=>…, 'url'=>…], …]
// Retourne ['ok'=>bool,'campagne_id'=>string,'total'=>int,'duree_estimee'=>string,'error'=>string].
function babengas_create_campaign(array $series, ?string $callback_url = null): array {
    if ($series === []) {
        return ['ok' => false, 'error' => 'Aucune série à vérifier.'];
    }

    $payload = ['series' => array_values($series)];
    if ($callback_url !== null && $callback_url !== '') {
        $payload['callback_url'] = $callback_url;
    }

    $res = babengas_request('POST', '/campagne', $payload, 20);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error']];
    }

    $d = $res['data'];
    return [
        'ok'            => true,
        'campagne_id'   => (string)($d['campagne_id'] ?? ''),
        'statut'        => (string)($d['statut'] ?? ''),
        'total'         => (int)($d['total'] ?? 0),
        'deja_traites'  => (int)($d['deja_traites'] ?? 0),
        'duree_estimee' => (string)($d['duree_estimee'] ?? ''),
        'error'         => '',
    ];
}

// Avancement + résultats d'une campagne.
function babengas_get_campaign(string $campagne_id): array {
    $campagne_id = trim($campagne_id);
    if ($campagne_id === '' || !preg_match('/^[A-Za-z0-9_]+$/', $campagne_id)) {
        return ['ok' => false, 'error' => 'Identifiant de campagne invalide.'];
    }

    $res = babengas_request('GET', '/campagne/' . $campagne_id, null, 20);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error']];
    }

    $d = $res['data'];
    return [
        'ok'          => true,
        'campagne_id' => (string)($d['campagne_id'] ?? $campagne_id),
        'statut'      => (string)($d['statut'] ?? ''),
        'total'       => (int)($d['total'] ?? 0),
        'traites'     => (int)($d['traites'] ?? 0),
        'progression' => (int)($d['progression'] ?? 0),
        'fini_le'     => isset($d['fini_le']) ? (int)$d['fini_le'] : null,
        'resultats'   => is_array($d['resultats'] ?? null) ? $d['resultats'] : [],
        'error'       => '',
    ];
}

// Annule une campagne.
function babengas_cancel_campaign(string $campagne_id): array {
    $campagne_id = trim($campagne_id);
    if ($campagne_id === '' || !preg_match('/^[A-Za-z0-9_]+$/', $campagne_id)) {
        return ['ok' => false, 'error' => 'Identifiant de campagne invalide.'];
    }
    $res = babengas_request('DELETE', '/campagne/' . $campagne_id, null, 15);
    return ['ok' => $res['ok'], 'error' => $res['error']];
}

// ── Cache SQLite des décomptes Manga News ─────────────────────────────────────
// TTL de 30 jours, aligné sur le seuil de rafraîchissement des campagnes.
// Les échecs ne sont JAMAIS mis en cache : la série sera retentée à la campagne
// suivante, et l'ancienne valeur est conservée. De même, un statut absent
// (null) n'écrase jamais un statut déjà connu.
// La clé du cache est le slug Manga News de la série (texte).

if (!defined('BABENGAS_CACHE_TTL')) {
    define('BABENGAS_CACHE_TTL', 30 * 24 * 3600); // 30 jours
}

// Écriture d'un décompte validé. $nb_tomes null → on n'écrase rien.
function babengas_cache_store(string $serie_id, string $url, ?int $nb_tomes, ?int $nb_reference, ?string $statut_vf = null, ?string $statut_vo = null, bool $incertain = false): void {
    $serie_id = trim($serie_id);
    if ($serie_id === '') return;

    // Un décompte non fiable ne doit jamais écraser une valeur existante.
    if ($nb_tomes === null || $incertain) return;

    $statut_vf = ($statut_vf !== null && trim($statut_vf) !== '') ? trim($statut_vf) : null;
    $statut_vo = ($statut_vo !== null && trim($statut_vo) !== '') ? trim($statut_vo) : null;

    get_db()->prepare("
        INSERT INTO babengas_cache
            (serie_id, url, nb_tomes, nb_reference, statut_vf, statut_vo, incertain, erreur, timestamp)
        VALUES (?, ?, ?, ?, ?, ?, 0, NULL, ?)
        ON CONFLICT(serie_id) DO UPDATE SET
            url          = excluded.url,
            nb_tomes     = excluded.nb_tomes,
            nb_reference = excluded.nb_reference,
            statut_vf    = COALESCE(excluded.statut_vf, babengas_cache.statut_vf),
            statut_vo    = COALESCE(excluded.statut_vo, babengas_cache.statut_vo),
            incertain    = 0,
            erreur       = NULL,
            timestamp    = excluded.timestamp
    ")->execute([$serie_id, $url, $nb_tomes, $nb_reference, $statut_vf, $statut_vo, time()]);
}

// Lecture du cache. $max_age = 0 → ignore l'âge.
// Retourne ['nb_tomes'=>int,'nb_reference'=>int|null,'statut_vf'=>string|null,
//           'statut_vo'=>string|null,'timestamp'=>int] ou null.
function babengas_get_cached(string $serie_id, int $max_age = 0): ?array {
    $serie_id = trim($serie_id);
    if ($serie_id === '') return null;

    $stmt = get_db()->prepare(
        "SELECT nb_tomes, nb_reference, statut_vf, statut_vo, incertain, timestamp FROM babengas_cache WHERE serie_id = ?"
    );
    $stmt->execute([$serie_id]);
    $row = $stmt->fetch();
    if (!$row) return null;

    if ($max_age > 0 && (time() - (int)$row['timestamp']) >= $max_age) return null;
    if ($row['nb_tomes'] === null || !empty($row['incertain']))       return null;

    return [
        'nb_tomes'     => (int)$row['nb_tomes'],
        'nb_reference' => $row['nb_reference'] !== null ? (int)$row['nb_reference'] : null,
        'statut_vf'    => $row['statut_vf'] !== null && $row['statut_vf'] !== '' ? (string)$row['statut_vf'] : null,
        'statut_vo'    => $row['statut_vo'] !== null && $row['statut_vo'] !== '' ? (string)$row['statut_vo'] : null,
        'timestamp'    => (int)$row['timestamp'],
    ];
}

// Décompte Manga News d'une série de la collection, depuis son URL (cache seul —
// aucun appel réseau : les données viennent des campagnes Babengas).
function manganews_get_volumes_for_url(string $url, int $max_age = BABENGAS_CACHE_TTL): ?array {
    $sid = manganews_serie_slug_from_url($url);
    if ($sid === null) return null;
    return babengas_get_cached($sid, $max_age);
}

// Libellé lisible d'un statut de publication renvoyé par Babengas.
// Valeurs connues : en_cours, termine ; toute autre valeur (ex. « arrete »)
// est affichée telle quelle, sans accent ni majuscule, plutôt que masquée.
function babengas_statut_label(?string $statut): string {
    if ($statut === null || $statut === '') return '';
    switch ($statut) {
        case 'en_cours': return 'En cours';
        case 'termine':  return 'Terminé';
        case 'arrete':   return 'Arrêté';
        default:         return ucfirst(str_replace('_', ' ', $statut));
    }
}


// ── Ciblage des séries à rafraîchir ───────────────────────────────────────────
// Critères :
//   • URL Manga News de série renseignée et valide
//   • EXCLURE les séries possédant un tome tagué « dernier tome » : elles n'ont
//     plus rien à apprendre de Manga News.
//   • EXCLURE celles vérifiées il y a moins d'un mois ET sans tome ajouté depuis
//   • EXCLURE celles dont le dernier statut VF connu est « terminé » ET qui sont
//     complètes dans la collection (autant de tomes que le décompte en cache,
//     voire plus) : inutile de les réinterroger. Un tome ajouté depuis ne change
//     rien à cette règle : la série est déjà complète.
//
// $all = true → ignore les critères d'ancienneté et de statut (mais garde
// l'exclusion du « dernier tome »).
//
// $force = true → vérifie VRAIMENT toutes les séries sans exception : ignore
// l'ancienneté, le statut ET l'exclusion du « dernier tome ». Utilisé par le
// bouton « Forcer toutes les séries ». Implique $all.

function babengas_targets(array $data, bool $all = false, bool $force = false): array {
    if ($force) $all = true;
    $targets = [];

    foreach ($data as $series) {
        $url = trim((string)($series['manganews_url'] ?? ''));
        if ($url === '') continue;

        // Seules les fiches série valides partent à Babengas (il refuserait le
        // reste en « url_invalide »).
        if (!manganews_url_is_valid($url)) continue;

        // Exclusion : un tome est tagué « dernier tome ». Le statut de
        // publication n'entre pas en compte ici : une série peut être
        // « terminée » côté publication mais incomplète dans la collection, et
        // l'on veut alors savoir ce qu'il manque pour la finir. En mode $force,
        // on lève même cette exclusion.
        if (!$force) {
            $has_last = false;
            foreach ($series['volumes'] ?? [] as $v) {
                if (!empty($v['last'])) { $has_last = true; break; }
            }
            if ($has_last) continue;
        }

        if (!$all) {
            $sid    = manganews_serie_slug_from_url($url);
            $cached = $sid !== null ? babengas_get_cached($sid, 0) : null;

            if ($cached !== null) {
                // Terminée côté VF et complète chez soi : rien de plus à apprendre.
                if ($cached['statut_vf'] === 'termine'
                    && count($series['volumes'] ?? []) >= (int)$cached['nb_tomes']) {
                    continue;
                }

                $age = time() - (int)$cached['timestamp'];
                // Vérifiée il y a moins d'un mois : on passe, sauf si un tome a
                // été ajouté depuis la dernière vérification.
                if ($age < BABENGAS_CACHE_TTL && !babengas_volume_added_since($series, (int)$cached['timestamp'])) {
                    continue;
                }
            }
        }

        $targets[] = [
            'id'     => (string)$series['id'],
            'url'    => $url,
            'name'   => (string)($series['name'] ?? ''),
            'author' => series_contributors_names_text($series, 'auteur'),
        ];
    }

    return $targets;
}

// Un tome a-t-il été ajouté à la série depuis le timestamp donné ?
function babengas_volume_added_since(array $series, int $since): bool {
    foreach ($series['volumes'] ?? [] as $v) {
        $added = trim((string)($v['added_at'] ?? ''));
        if ($added === '') continue;
        $ts = strtotime($added);
        if ($ts !== false && $ts > $since) return true;
    }
    return false;
}

// ── Séries incomplètes connues via le cache Manga News ────────────────────────
// Une campagne ne renvoie QUE les séries qu'elle a (re)vérifiées. Les séries
// déjà connues comme incomplètes, mais vérifiées récemment (donc écartées du
// ciblage), disparaîtraient sinon du rapport, donnant une vue partielle de ce
// qu'il manque réellement. Cette fonction rejoue le décompte à partir du cache
// pour offrir une vue complète, sans aucun appel réseau.
//
// $exclude_ids = identifiants déjà présents dans le rapport de campagne, pour
// éviter les doublons (une série fraîchement traitée prime sur son cache).
//
// Mêmes critères d'éligibilité et même forme de sortie que
// babengas_integrate_results : les entrées se fondent dans le même affichage.
function babengas_cached_incomplete(array $data, array $exclude_ids = []): array {
    $exclude    = array_flip(array_map('strval', $exclude_ids));
    $incomplete = [];

    foreach ($data as $series) {
        if (isset($exclude[(string)$series['id']])) continue;

        // Auteur/éditeur dérivés une seule fois ici, posés directement sur
        // $series avant toute branche : cette fonction pousse ensuite la
        // série TELLE QUELLE dans $incomplete plus bas — sans ces deux clés,
        // le front (assets/js/admin/tools/babengas.js) qui lit encore
        // series.author/series.publisher afficherait "undefined" depuis la
        // migration « Personnalités » (voir la même correction dans
        // includes/mangaupdates.php, get_incomplete_series()).
        $series['author']    = series_contributors_names_text($series, 'auteur');
        $series['publisher'] = series_contributors_names_text($series, 'editeur');

        $url = trim((string)($series['manganews_url'] ?? ''));
        if ($url === '') continue;
        if (!manganews_url_is_valid($url)) continue;

        // Même exclusion que le ciblage : un « dernier tome » posé → série
        // considérée finalisée, rien à signaler.
        $has_last = false;
        foreach ($series['volumes'] ?? [] as $v) {
            if (!empty($v['last'])) { $has_last = true; break; }
        }
        if ($has_last) continue;

        $sid = manganews_serie_slug_from_url($url);
        if ($sid === null) continue;

        // Cache seul (aucun appel réseau). $max_age = 0 → on accepte toute
        // valeur en cache, quelle que soit son ancienneté : c'est justement le
        // décompte déjà connu que l'on veut réafficher.
        $cached = babengas_get_cached($sid, 0);
        if ($cached === null) continue;

        $nb_tomes = (int)$cached['nb_tomes'];
        $nb_ref   = $cached['nb_reference'];
        $owned    = count($series['volumes'] ?? []);

        $series['ref_volumes_source'] = 'manganews';
        $series['ref_volumes']        = $nb_tomes;
        $series['ref_reference']      = $nb_ref;
        $series['statut_vf']          = $cached['statut_vf'];
        $series['statut_vo']          = $cached['statut_vo'];
        $series['from_cache']         = true;

        if ($owned < $nb_tomes) {
            $missing = [];
            for ($i = $owned + 1; $i <= $nb_tomes; $i++) $missing[] = $i;
            $series['missing_volumes'] = $missing;
            $incomplete[] = $series;
        } elseif ($owned > $nb_tomes) {
            $series['has_more_volumes'] = true;
            $series['missing_volumes']  = [];
            $incomplete[] = $series;
        }
        // else : série à jour d'après le cache → non retournée
    }

    return ['incomplete' => $incomplete];
}


// ── Intégration des résultats d'une campagne ──────────────────────────────────
// Écrit en cache les décomptes fiables (et les statuts VF/VO), laisse les échecs
// intacts (l'ancienne valeur et l'ancien timestamp sont conservés) et retourne
// un rapport prêt à être affiché : séries incomplètes, en surplus, en échec.
//
// Règle de traitement : mise à jour seulement si statut = « fait » ET
// incertain = false ; sinon on n'écrase rien et on affiche le motif.
//
// $resultats = tableau renvoyé par GET /campagne/{id} (clé « resultats »).
function babengas_integrate_results(array $data, array $resultats): array {
    // Index des séries par ID pour un accès direct
    $by_id = [];
    foreach ($data as $s) {
        $by_id[(string)$s['id']] = $s;
    }

    $incomplete = [];
    $failed     = [];
    $ok_count   = 0;

    foreach ($resultats as $r) {
        $lengas_id = (string)($r['id'] ?? '');
        $statut    = (string)($r['statut'] ?? '');

        // Séries encore en file : rien à intégrer pour l'instant
        if ($statut !== 'fait' && $statut !== 'echec') continue;

        $series = $by_id[$lengas_id] ?? null;
        if ($series === null) continue;

        // Auteur/éditeur dérivés, mêmes raisons que babengas_cached_incomplete()
        // ci-dessus — $series est ensuite poussée telle quelle dans
        // $incomplete plus bas.
        $series['author']    = series_contributors_names_text($series, 'auteur');
        $series['publisher'] = series_contributors_names_text($series, 'editeur');

        $nb_tomes  = isset($r['nb_tomes'])  && $r['nb_tomes']  !== null ? (int)$r['nb_tomes']  : null;
        $nb_ref    = isset($r['nb_reference']) && $r['nb_reference'] !== null ? (int)$r['nb_reference'] : null;
        $statut_vf = isset($r['statut_vf']) && $r['statut_vf'] !== null && $r['statut_vf'] !== '' ? (string)$r['statut_vf'] : null;
        $statut_vo = isset($r['statut_vo']) && $r['statut_vo'] !== null && $r['statut_vo'] !== '' ? (string)$r['statut_vo'] : null;
        $incertain = !empty($r['incertain']);
        $erreur    = $r['erreur'] ?? null;

        // ── Échec ou décompte non fiable : on n'écrase rien ────────────────────
        if ($statut === 'echec' || $nb_tomes === null || $incertain) {
            $failed[] = [
                'id'            => $series['id'],
                'name'          => $series['name'],
                'author'        => series_contributors_names_text($series, 'auteur'),
                'ref'           => 'manganews',
                'reason'        => babengas_error_message($erreur !== null ? (string)$erreur : ($incertain ? 'incertain' : '')),
                'erreur'        => $erreur,
                'manganews_url' => $series['manganews_url'] ?? '',
                'read_elsewhere' => !empty($series['read_elsewhere']),
            ];
            continue;
        }

        // ── Succès : mise en cache du décompte et des statuts ─────────────────
        $sid = manganews_serie_slug_from_url((string)($series['manganews_url'] ?? ''));
        if ($sid !== null) {
            babengas_cache_store($sid, (string)$series['manganews_url'], $nb_tomes, $nb_ref, $statut_vf, $statut_vo, false);
        }
        $ok_count++;

        $owned = count($series['volumes'] ?? []);
        $series['ref_volumes_source'] = 'manganews';
        $series['ref_volumes']        = $nb_tomes;
        $series['ref_reference']      = $nb_ref;
        $series['statut_vf']          = $statut_vf;
        $series['statut_vo']          = $statut_vo;
        $series['manganews_title']    = (string)($r['message'] ?? '');

        if ($owned < $nb_tomes) {
            $missing = [];
            for ($i = $owned + 1; $i <= $nb_tomes; $i++) $missing[] = $i;
            $series['missing_volumes'] = $missing;
            $incomplete[] = $series;
        } elseif ($owned > $nb_tomes) {
            $series['has_more_volumes'] = true;
            $series['missing_volumes']  = [];
            $incomplete[] = $series;
        }
        // else : série à jour → non retournée
    }

    return [
        'incomplete' => $incomplete,
        'failed'     => $failed,
        'ok_count'   => $ok_count,
    ];
}

// Traduit un code d'erreur Babengas en message lisible.
function babengas_error_message(string $code): string {
    switch ($code) {
        case 'url_absente':    return 'Aucune fiche Manga News associée';
        case 'url_invalide':   return 'URL Manga News invalide (attendu : …/serie/Nom-de-la-serie)';
        case 'introuvable':    return 'Fiche introuvable sur Manga News (renommée ou supprimée ?)';
        case 'inaccessible':   return 'Manga News inaccessible, réessai à la prochaine campagne';
        case 'parsing_echoue': return 'Structure de page inattendue, ou série sans édition VF';
        case 'incertain':      return 'Décompte incertain, vérification manuelle conseillée';
        default:               return $code !== '' ? $code : 'Erreur inconnue';
    }
}


// ── Suivi de la campagne en cours (persisté dans les options) ─────────────────
// Une seule campagne active à la fois : on stocke son ID pour pouvoir reprendre
// le suivi après un rechargement de page ou une déconnexion.

function babengas_set_current_campaign(string $campagne_id, int $total): void {
    save_options([
        'babengas_campaign_id'    => $campagne_id,
        'babengas_campaign_total' => (string)$total,
        'babengas_campaign_start' => (string)time(),
    ]);
}

function babengas_get_current_campaign(): ?array {
    $opts = load_options();
    $id   = trim((string)($opts['babengas_campaign_id'] ?? ''));
    if ($id === '') return null;
    return [
        'campagne_id' => $id,
        'total'       => (int)($opts['babengas_campaign_total'] ?? 0),
        'start'       => (int)($opts['babengas_campaign_start'] ?? 0),
    ];
}

function babengas_clear_current_campaign(): void {
    save_options([
        'babengas_campaign_id'    => '',
        'babengas_campaign_total' => '0',
        'babengas_campaign_start' => '0',
    ]);
}

// Dernière progression connue, alimentée par le webhook horaire de Babengas.
function babengas_set_ping(int $traites, int $total, int $progression, string $statut): void {
    save_options([
        'babengas_ping_traites'     => (string)$traites,
        'babengas_ping_total'       => (string)$total,
        'babengas_ping_progression' => (string)$progression,
        'babengas_ping_statut'      => $statut,
        'babengas_ping_time'        => (string)time(),
    ]);
}

function babengas_get_ping(): ?array {
    $opts = load_options();
    if (trim((string)($opts['babengas_ping_time'] ?? '')) === '') return null;
    return [
        'traites'     => (int)($opts['babengas_ping_traites'] ?? 0),
        'total'       => (int)($opts['babengas_ping_total'] ?? 0),
        'progression' => (int)($opts['babengas_ping_progression'] ?? 0),
        'statut'      => (string)($opts['babengas_ping_statut'] ?? ''),
        'time'        => (int)($opts['babengas_ping_time'] ?? 0),
    ];
}
