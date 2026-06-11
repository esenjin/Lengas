<?php
// ──────────────────────────────────────────────────────────────────────────────
// mangaupdates.php — Intégration de l'API officielle MangaUpdates
// Remplace l'ancien includes/anilist.php.
//
// Principe : chaque série possède une URL MangaUpdates complète stockée en BDD
// (ex: https://www.mangaupdates.com/series/iewtc7h/silent-witch-...).
// Le « slug » présent dans l'URL (ici « iewtc7h ») est l'identifiant numérique
// de la série encodé en base 36. On le décode pour obtenir le series_id, puis
// on interroge l'API : GET /v1/series/{series_id} renvoie la fiche.
//
// (L'API attend l'ID NUMÉRIQUE, pas le slug : c'est pour cela que le décodage
//  base 36 est indispensable.)
//
// L'API renvoie un champ « status » du type "6 Volumes (Ongoing)",
// "12 Volumes (Complete)" ou, pour plusieurs pays,
// "12 Volumes (Ongoing)\n8 Volumes (Complete, France)".
// On en extrait le nombre de tomes et le statut, en PRIVILÉGIANT l'édition
// française (VF) lorsqu'elle est indiquée, sinon l'édition d'origine (VO).
// ──────────────────────────────────────────────────────────────────────────────

// ── Extraire le slug MangaUpdates depuis une URL ──────────────────────────────
// "https://www.mangaupdates.com/series/iewtc7h/silent-witch-..." → "iewtc7h"
function mangaupdates_get_slug_from_url(string $url): ?string {
    if (preg_match('#mangaupdates\.com/series/([a-z0-9]+)#i', trim($url), $m)) {
        return strtolower($m[1]);
    }
    return null;
}

// ── Décoder un slug (base 36) en series_id numérique ──────────────────────────
// "iewtc7h" → 40083725069
function mangaupdates_decode_slug(string $slug): ?int {
    $slug = strtolower(trim($slug));
    if ($slug === '' || !preg_match('/^[a-z0-9]+$/', $slug)) return null;
    $id = intval($slug, 36);
    return $id > 0 ? $id : null;
}

// ── URL MangaUpdates → series_id numérique ────────────────────────────────────
function mangaupdates_get_id_from_url(string $url): ?int {
    $slug = mangaupdates_get_slug_from_url($url);
    if ($slug === null) return null;
    return mangaupdates_decode_slug($slug);
}

// ── Requête cURL réutilisable vers l'API MangaUpdates ─────────────────────────
// Retourne [$body, $http_code, $curl_error] ($curl_error vide si aucune erreur).
function mangaupdates_curl(string $url, ?string $post_json = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => 'Lengas (gestion de collection de mangas)',
    ];
    if ($post_json !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $post_json;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$resp === false ? '' : $resp, $code, $err];
}

// ── Parser le champ « status » de MangaUpdates ────────────────────────────────
// Le champ peut contenir PLUSIEURS lignes (une par pays/région) :
//   "12 Volumes (Ongoing)"                               → VO seule
//   "12 Volumes (Ongoing)\n8 Volumes (Complete, France)" → VO + VF
// Priorité : France (VF) > entrée sans pays (VO) > première entrée trouvée.
// Sortie : ['volumes'=>8,'status_text'=>'Complete','completed'=>true,
//           'country'=>'France','is_france'=>true]  ou null si rien d'exploitable.
function mangaupdates_parse_status(string $status): ?array {
    $raw = trim($status);
    if ($raw === '') return null;

    // Normaliser les séparateurs éventuels (<br>, balises) puis tout aplatir
    $raw = preg_replace('#<br\s*/?>#i', "\n", $raw);
    $raw = strip_tags($raw);

    // Capturer TOUTES les occurrences "X Volume(s) (…)" quel que soit le séparateur
    if (!preg_match_all('/(\d+)\s+Volumes?\s+\(([^)]+)\)/i', $raw, $all, PREG_SET_ORDER)) {
        return null;
    }

    $entries = [];
    foreach ($all as $m) {
        $inside = trim($m[2]);                       // "Complete, France" | "Ongoing"
        $parts  = array_map('trim', explode(',', $inside));
        $status_text = $parts[0];                    // "Complete" | "Ongoing"
        $country     = trim(implode(', ', array_slice($parts, 1))); // "France" | ""
        $is_france   = $country !== '' &&
                       (stripos($country, 'france') !== false || stripos($country, 'french') !== false);
        $entries[] = [
            'volumes'     => (int)$m[1],
            'status_text' => $status_text,
            'country'     => $country,
            'completed'   => stripos($status_text, 'complete') !== false,
            'is_france'   => $is_france,
            'has_country' => $country !== '',
        ];
    }
    if (empty($entries)) return null;

    // Choix prioritaire
    $chosen = null;
    foreach ($entries as $e) { if ($e['is_france'])  { $chosen = $e; break; } }
    if ($chosen === null) {
        foreach ($entries as $e) { if (!$e['has_country']) { $chosen = $e; break; } }
    }
    if ($chosen === null) $chosen = $entries[0];

    return [
        'volumes'     => $chosen['volumes'],
        'status_text' => $chosen['status_text'],
        'completed'   => $chosen['completed'],
        'country'     => $chosen['country'],
        'is_france'   => $chosen['is_france'],
    ];
}

// ── Cache SQLite : écriture (clé = series_id numérique) ────────────────────────
function mangaupdates_cache_store(int $series_id, ?int $volumes, ?string $status_text): void {
    if ($series_id <= 0) return;
    $db = get_db();
    $db->prepare("
        INSERT OR REPLACE INTO mangaupdates_cache (series_id, volumes, status_text, timestamp)
        VALUES (?, ?, ?, ?)
    ")->execute([(string)$series_id, $volumes, $status_text, time()]);
}

// ── Cache SQLite : lecture SANS appel réseau ──────────────────────────────────
// $max_age = 0 → ignore l'âge (renvoie toujours l'entrée si elle existe).
// Retourne ['volumes'=>int|null,'status'=>string|null,'completed'=>bool] ou null.
function mangaupdates_get_cached_status(int $series_id, int $max_age = 0): ?array {
    if ($series_id <= 0) return null;
    $db   = get_db();
    $stmt = $db->prepare("SELECT volumes, status_text, timestamp FROM mangaupdates_cache WHERE series_id = ?");
    $stmt->execute([(string)$series_id]);
    $c = $stmt->fetch();
    if (!$c) return null;
    if ($max_age > 0 && (time() - (int)$c['timestamp']) >= $max_age) return null;
    $st = $c['status_text'];
    return [
        'volumes'   => $c['volumes'] !== null ? (int)$c['volumes'] : null,
        'status'    => $st,
        'completed' => $st !== null && stripos($st, 'complete') !== false,
    ];
}

// ── Récupérer volumes + statut pour un series_id, avec cache SQLite 24h ────────
// Retourne ['volumes'=>int|null,'status'=>string|null,'completed'=>bool,
//           'country'=>string,'is_france'=>bool] si la fiche a pu être récupérée
// (volumes peut être null = pas de nombre de tomes renseigné), ou null en cas
// d'échec (réseau, code HTTP non 200, JSON invalide). Les échecs ne sont PAS
// mis en cache afin de réessayer au prochain appel.
function mangaupdates_get_volumes(int $series_id, bool $force = false): ?array {
    if ($series_id <= 0) return null;
    $cache_ttl = 86400; // 24h

    if (!$force) {
        $cached = mangaupdates_get_cached_status($series_id, $cache_ttl);
        if ($cached !== null) {
            $cached['country']   = $cached['country']   ?? '';
            $cached['is_france'] = $cached['is_france'] ?? false;
            return $cached;
        }
    }

    [$body, $code, $err] = mangaupdates_curl('https://api.mangaupdates.com/v1/series/' . $series_id);
    if ($err !== '' || $code !== 200) {
        return null; // échec : non mis en cache
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }

    $parsed      = mangaupdates_parse_status($data['status'] ?? '');
    $volumes     = $parsed['volumes']     ?? null;
    $status_text = $parsed['status_text'] ?? null;

    mangaupdates_cache_store($series_id, $volumes, $status_text);

    return [
        'volumes'   => $volumes,
        'status'    => $status_text,
        'completed' => $parsed['completed'] ?? false,
        'country'   => $parsed['country']   ?? '',
        'is_france' => $parsed['is_france'] ?? false,
    ];
}

// ── Récupérer les volumes en lot pour une liste de series_id (avec cache) ──────
// Retourne [series_id => ['volumes'=>N,'status'=>'…','completed'=>bool], …]
// Les series_id dont la récupération échoue sont absents du tableau retourné
// (ce qui permet de distinguer « échec réseau » de « pas de nombre de tomes »).
function mangaupdates_get_volumes_batch(array $series_ids): array {
    $out = [];
    foreach (array_unique($series_ids) as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $res = mangaupdates_get_volumes($id);
        if ($res !== null) {
            $out[$id] = $res;
        }
    }
    return $out;
}

// ── Recherche de séries (pour l'outil d'association automatique) ──────────────
// Réchauffe le cache au passage (les résultats contiennent déjà le statut).
// Retourne un tableau de candidats :
//   ['series_id','slug','title','status','volumes','status_text','completed',
//    'country','url','year','type']
function mangaupdates_search(string $query, int $perpage = 5): array {
    $query = trim($query);
    if ($query === '') return [];

    [$body, $code, $err] = mangaupdates_curl(
        'https://api.mangaupdates.com/v1/series/search',
        json_encode(['search' => $query, 'perpage' => $perpage])
    );
    if ($err !== '' || $code !== 200) return [];

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['results'])) return [];

    $results = [];
    foreach ($data['results'] as $r) {
        $rec    = $r['record'] ?? $r;
        $parsed = mangaupdates_parse_status($rec['status'] ?? '');
        $url    = $rec['url'] ?? '';
        $sid    = isset($rec['series_id']) ? (int)$rec['series_id'] : 0;

        // Réchauffer le cache avec les données de recherche
        if ($sid > 0 && $parsed !== null) {
            mangaupdates_cache_store($sid, $parsed['volumes'] ?? null, $parsed['status_text'] ?? null);
        }

        $results[] = [
            'series_id'   => $sid ?: null,
            'slug'        => $url !== '' ? mangaupdates_get_slug_from_url($url) : null,
            'title'       => $rec['title'] ?? '',
            'status'      => $rec['status'] ?? '',
            'volumes'     => $parsed['volumes']     ?? null,
            'status_text' => $parsed['status_text'] ?? null,
            'completed'   => $parsed['completed']   ?? false,
            'country'     => $parsed['country']     ?? '',
            'url'         => $url,
            'year'        => $rec['year'] ?? '',
            'type'        => $rec['type'] ?? '',
        ];
    }
    return $results;
}

// ── Test de connectivité de l'API MangaUpdates (pour l'outil d'intégrité) ─────
// Retourne ['ok'=>bool,'http'=>int,'error'=>string].
function mangaupdates_check_api(): array {
    [$body, $code, $err] = mangaupdates_curl(
        'https://api.mangaupdates.com/v1/series/search',
        json_encode(['search' => 'one piece', 'perpage' => 1])
    );
    return [
        'ok'    => ($err === '' && $code === 200),
        'http'  => $code,
        'error' => $err,
    ];
}

// ── Séries incomplètes ────────────────────────────────────────────────────────
// Même forme de retour qu'avec Anilist, mais s'appuie sur l'URL MangaUpdates.
//   incomplete   → séries avec tomes manquants ou en surplus
//   no_reference → séries sans URL MangaUpdates  (avec 'id' pour le bouton Éditer)
//   failed       → séries avec URL mais analyse impossible (réseau / pas de tomes)
function get_incomplete_series(array $data): array {
    $incomplete_series        = [];
    $series_with_more_volumes = [];
    $no_reference_series      = [];
    $failed_series            = [];

    // Pré-charger les volumes en lot (profite du cache SQLite)
    $ids_needed = [];
    foreach ($data as $series) {
        $url = $series['mangaupdates_url'] ?? '';
        if ($url !== '') {
            $id = mangaupdates_get_id_from_url($url);
            if ($id !== null) $ids_needed[] = $id;
        }
    }
    $volumes_by_id = mangaupdates_get_volumes_batch($ids_needed);

    foreach ($data as $series) {
        $url = $series['mangaupdates_url'] ?? '';

        // ── Cas 1 : aucune URL MangaUpdates ───────────────────────────────────
        if ($url === '') {
            $no_reference_series[] = [
                'id'     => $series['id'],
                'name'   => $series['name'],
                'author' => $series['author'] ?? '',
            ];
            continue;
        }

        // ── URL présente mais invalide ────────────────────────────────────────
        $id = mangaupdates_get_id_from_url($url);
        if ($id === null) {
            $failed_series[] = [
                'id'     => $series['id'],
                'name'   => $series['name'],
                'author' => $series['author'] ?? '',
                'ref'    => 'mangaupdates',
                'reason' => 'URL MangaUpdates invalide',
            ];
            continue;
        }

        // ── Clé absente = échec de récupération (réseau / service indisponible) ─
        if (!array_key_exists($id, $volumes_by_id)) {
            $failed_series[] = [
                'id'     => $series['id'],
                'name'   => $series['name'],
                'author' => $series['author'] ?? '',
                'ref'    => 'mangaupdates',
                'reason' => 'Erreur de récupération MangaUpdates (réseau ou service indisponible)',
            ];
            continue;
        }

        $info        = $volumes_by_id[$id];
        $ref_volumes = $info['volumes'];
        if ($ref_volumes === null || (int)$ref_volumes <= 0) {
            $failed_series[] = [
                'id'     => $series['id'],
                'name'   => $series['name'],
                'author' => $series['author'] ?? '',
                'ref'    => 'mangaupdates',
                'reason' => 'Nombre de tomes non renseigné sur MangaUpdates',
            ];
            continue;
        }

        $ref_volumes   = (int)$ref_volumes;
        $owned_volumes = count($series['volumes']);
        $series['ref_volumes_source'] = 'mangaupdates';
        $series['ref_volumes']        = $ref_volumes;
        $series['ref_status']         = $info['status']    ?? null;
        $series['ref_completed']      = $info['completed'] ?? false;
        $series['ref_country']        = $info['country']   ?? '';

        if ($owned_volumes < $ref_volumes) {
            $missing = [];
            for ($i = $owned_volumes + 1; $i <= $ref_volumes; $i++) {
                $missing[] = $i;
            }
            $series['missing_volumes'] = $missing;
            $incomplete_series[] = $series;
        } elseif ($owned_volumes > $ref_volumes) {
            $series['has_more_volumes'] = true;
            $series['missing_volumes']  = [];
            $series_with_more_volumes[] = $series;
        }
        // else : série complète → non retournée
    }

    $incomplete = array_merge($incomplete_series, $series_with_more_volumes);
    foreach ($incomplete as &$s) {
        if (!isset($s['missing_volumes'])) $s['missing_volumes'] = [];
    }
    unset($s);

    return [
        'incomplete'   => $incomplete,
        'no_reference' => $no_reference_series,
        'failed'       => $failed_series,
    ];
}
