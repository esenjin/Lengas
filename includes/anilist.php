<?php
// anilist.php
function fetch_from_anilist(string $query, array $variables = [], int $retry = 3, int $delay = 2): ?array {
    $url     = 'https://graphql.anilist.co';
    $attempt = 0;
    $last_error = null;

    while ($attempt < $retry) {
        $data = ['query' => $query, 'variables' => $variables];
        $options = [
            'http' => [
                'header'  => "Content-type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
            ],
        ];

        $context  = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response !== false) {
            $result = json_decode($response, true);
            if (isset($result['errors'])) {
                $last_error = $result['errors'][0]['message'] ?? "Erreur inconnue de l'API Anilist.";
            } else {
                return $result;
            }
        } else {
            $last_error = "Impossible de contacter l'API Anilist.";
        }

        if (isset($http_response_header) && strpos($http_response_header[0], '429') !== false) {
            sleep($delay);
            $attempt++;
            continue;
        }

        break;
    }

    error_log("Erreur Anilist API: " . $last_error);
    return null;
}

function fetch_volumes_for_series_batch(array $series_ids, int $batch_size = 10): array {
    $results = [];
    $batches  = array_chunk($series_ids, $batch_size);

    foreach ($batches as $batch) {
        $query_parts = [];
        $i = 0;
        foreach ($batch as $id) {
            $query_parts[] = "media_$i: Media(id: $id, type: MANGA) { volumes }";
            $i++;
        }

        $query = 'query { ' . implode(' ', $query_parts) . ' }';
        $data  = fetch_from_anilist($query, []);

        if ($data && isset($data['data'])) {
            foreach ($batch as $index => $id) {
                $key = "media_$index";
                // array_key_exists conserve les null (série en cours, volumes non renseignés)
                // contrairement à isset() qui les ignorerait
                if (array_key_exists($key, $data['data'])) {
                    $results[$id] = $data['data'][$key]['volumes'] ?? null;
                }
            }
        }
    }

    return $results;
}

// Fonction pour récupérer les volumes d'une série (avec cache SQLite)
function get_series_volumes_from_anilist($anilist_id, bool $force_refresh = false): ?int {
    if (!$anilist_id) return null;

    $cache_key = "media_volumes_$anilist_id";
    $cache_ttl = 86400; // 24h
    $db        = get_db();

    if (!$force_refresh) {
        $row = $db->prepare("SELECT volumes, timestamp FROM anilist_cache WHERE cache_key = ?");
        $row->execute([$cache_key]);
        $cached = $row->fetch();
        if ($cached && (time() - (int)$cached['timestamp']) < $cache_ttl) {
            return $cached['volumes'] !== null ? (int)$cached['volumes'] : null;
        }
    }

    $query = '
    query ($id: Int) {
        Media (id: $id, type: MANGA) {
            volumes
        }
    }
    ';

    $result = fetch_from_anilist($query, ['id' => (int)$anilist_id]);

    if ($result && isset($result['data']['Media']['volumes'])) {
        $volumes = $result['data']['Media']['volumes'];

        $db->prepare("
            INSERT OR REPLACE INTO anilist_cache (cache_key, volumes, timestamp)
            VALUES (?, ?, ?)
        ")->execute([$cache_key, $volumes, time()]);

        return (int)$volumes;
    }

    return null;
}

// Fonction pour obtenir les séries incomplètes
// Retourne un tableau associatif avec 3 catégories :
//   incomplete   → séries avec tomes manquants ou en surplus
//   no_reference → séries sans ID Anilist
//   failed       → séries avec ID Anilist mais analyse impossible
function get_incomplete_series(array $data): array {
    $incomplete_series        = [];
    $series_with_more_volumes = [];
    $no_reference_series      = [];
    $failed_series            = [];

    // Collecter tous les anilist_ids
    $anilist_ids_needed = [];
    foreach ($data as $series) {
        if (!empty($series['anilist_id'])) {
            $anilist_ids_needed[] = $series['anilist_id'];
        }
    }
    $volumes_by_anilist = fetch_volumes_for_series_batch(array_unique($anilist_ids_needed));

    foreach ($data as $series) {
        $ref_volumes = null;
        $source      = null;

        // ── Cas 1 : aucun ID Anilist ──────────────────────────────────────────
        if (empty($series['anilist_id'])) {
            $no_reference_series[] = [
                'name'   => $series['name'],
                'author' => $series['author'] ?? '',
            ];
            continue;
        }

        // ── Cas 2 : ID Anilist présent ───────────────────────────────────────
        $av = $volumes_by_anilist[$series['anilist_id']] ?? null;
        if ($av !== null) {
            $ref_volumes = (int)$av;
            $source      = 'anilist';
        } else {
            $failed_series[] = [
                'name'   => $series['name'],
                'author' => $series['author'] ?? '',
                'ref'    => 'anilist',
                'reason' => 'Données Anilist indisponibles',
            ];
            continue;
        }

        if ($ref_volumes === null) continue;

        $owned_volumes               = count($series['volumes']);
        $series['ref_volumes_source'] = $source;
        $series['ref_volumes']        = $ref_volumes;

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
        // else: série complète → non retournée
    }

    $incomplete = array_merge($incomplete_series, $series_with_more_volumes);
    foreach ($incomplete as &$s) {
        if (!isset($s['missing_volumes'])) $s['missing_volumes'] = [];
    }

    return [
        'incomplete'   => $incomplete,
        'no_reference' => $no_reference_series,
        'failed'       => $failed_series,
    ];
}

// Fonction pour ajouter tous les tomes manquants à une série
function add_all_missing_volumes_to_series(array &$data, string $series_id, array $missing_volumes, string $status = 'à lire', bool $is_collector = false): bool {
    $series_index = null;
    foreach ($data as $index => &$series) {
        if ($series['id'] === $series_id) {
            $series_index = $index;
            break;
        }
    }

    if ($series_index === null) return false;

    $last_volume_number = !empty($data[$series_index]['volumes'])
        ? max(array_map(fn($v) => $v['number'], $data[$series_index]['volumes']))
        : 0;

    $db   = get_db();
    $stmt = $db->prepare("
        INSERT OR IGNORE INTO volumes (series_id, number, status, collector, last, added_at)
        VALUES (?, ?, ?, ?, 0, ?)
    ");

    foreach ($missing_volumes as $volume_number) {
        if ((int)$volume_number > $last_volume_number) {
            $data[$series_index]['volumes'][] = [
                'number'    => (int)$volume_number,
                'status'    => $status,
                'collector' => $is_collector,
                'last'      => false,
                'added_at'  => date('Y-m-d'),
            ];
            $stmt->execute([$series_id, (int)$volume_number, $status, (int)$is_collector, date('Y-m-d')]);
        }
    }

    // Mettre à jour le dernier tome
    if (!empty($data[$series_index]['volumes'])) {
        $last_volume = max(array_map(fn($v) => $v['number'], $data[$series_index]['volumes']));
        $db->prepare("UPDATE volumes SET last = 0 WHERE series_id = ?")->execute([$series_id]);
        $db->prepare("UPDATE volumes SET last = 1 WHERE series_id = ? AND number = ?")->execute([$series_id, $last_volume]);

        foreach ($data[$series_index]['volumes'] as &$volume) {
            $volume['last'] = ($volume['number'] == $last_volume);
        }
    }

    return true;
}
