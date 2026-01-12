<?php
// anilist.php
function fetch_from_anilist($query, $variables = [], $retry = 3, $delay = 2) {
    $url = 'https://graphql.anilist.co';
    $attempt = 0;
    $last_error = null;

    while ($attempt < $retry) {
        $data = [
            'query' => $query,
            'variables' => $variables
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
            ],
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response !== false) {
            $result = json_decode($response, true);
            if (isset($result['errors'])) {
                $last_error = $result['errors'][0]['message'] ?? 'Erreur inconnue de l\'API Anilist.';
            } else {
                return $result;
            }
        } else {
            $last_error = 'Impossible de contacter l\'API Anilist.';
        }

        // Si erreur 429 (Too Many Requests), attendre avant de réessayer
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

// Fonction pour récupérer les volumes de plusieurs séries en une seule requête (par lots)
function fetch_volumes_for_series_batch($series_ids, $batch_size = 10) {
    $results = [];
    $batches = array_chunk($series_ids, $batch_size);

    foreach ($batches as $batch) {
        $query_parts = [];
        $variables = [];
        $i = 0;

        foreach ($batch as $id) {
            $query_parts[] = "media_$i: Media(id: $id, type: MANGA) { volumes }";
            $i++;
        }

        $query = 'query { ' . implode(' ', $query_parts) . ' }';
        $data = fetch_from_anilist($query, $variables);

        if ($data && isset($data['data'])) {
            foreach ($batch as $index => $id) {
                $key = "media_$index";
                if (isset($data['data'][$key]['volumes'])) {
                    $results[$id] = $data['data'][$key]['volumes'];
                }
            }
        }
    }

    return $results;
}

// Fonction pour récupérer les volumes d'une série (avec cache)
function get_series_volumes_from_anilist($anilist_id, $force_refresh = false) {
    if (!$anilist_id) {
        return null;
    }

    $cache_file = 'bdd/anilist.json';
    $cache_key = "media_volumes_$anilist_id";
    $cache_ttl = 86400; // 24h

    // Charger le cache
    $cache = file_exists($cache_file) ? json_decode(file_get_contents($cache_file), true) : [];

    // Vérifier si le cache est valide
    if (isset($cache[$cache_key]) && !$force_refresh) {
        $entry = $cache[$cache_key];
        if (time() - $entry['timestamp'] < $cache_ttl) {
            return $entry['volumes'];
        }
    }

    // Sinon, faire la requête
    $query = '
    query ($id: Int) {
        Media (id: $id, type: MANGA) {
            volumes
        }
    }
    ';

    $variables = ['id' => (int)$anilist_id];
    $data = fetch_from_anilist($query, $variables);

    if ($data && isset($data['data']['Media']['volumes'])) {
        $volumes = $data['data']['Media']['volumes'];

        // Mettre à jour le cache
        $cache[$cache_key] = [
            'volumes' => $volumes,
            'timestamp' => time()
        ];

        if (!file_exists('bdd')) {
            mkdir('bdd', 0777, true);
        }

        file_put_contents($cache_file, json_encode($cache, JSON_PRETTY_PRINT));
        return $volumes;
    }

    return null;
}

// Fonction pour obtenir les séries incomplètes (optimisée)
function get_incomplete_series($data) {
    $incomplete_series = [];
    $series_with_more_volumes = [];
    $anilist_ids = [];

    // Collecter les IDs Anilist uniques
    foreach ($data as $series) {
        if (isset($series['anilist_id']) && !empty($series['anilist_id'])) {
            $anilist_ids[] = $series['anilist_id'];
        }
    }

    // Récupérer les volumes par lots
    $volumes_by_id = fetch_volumes_for_series_batch(array_unique($anilist_ids));

    foreach ($data as $series) {
        if (isset($series['anilist_id']) && !empty($series['anilist_id'])) {
            $anilist_id = $series['anilist_id'];
            $anilist_volumes = $volumes_by_id[$anilist_id] ?? null;

            if ($anilist_volumes !== null) {
                $owned_volumes = count($series['volumes']);
                if ($owned_volumes < $anilist_volumes) {
                    $missing_volumes = [];
                    for ($i = $owned_volumes + 1; $i <= $anilist_volumes; $i++) {
                        $missing_volumes[] = $i;
                    }
                    $series['missing_volumes'] = $missing_volumes;
                    $incomplete_series[] = $series;
                } elseif ($owned_volumes > $anilist_volumes) {
                    $series['has_more_volumes'] = true;
                    $series['missing_volumes'] = [];
                    $series_with_more_volumes[] = $series;
                }
            }
        }
    }

    $result = array_merge($incomplete_series, $series_with_more_volumes);
    foreach ($result as &$serie) {
        if (!isset($serie['missing_volumes'])) {
            $serie['missing_volumes'] = [];
        }
    }

    return $result;
}

// Fonction pour ajouter un tome à une série
function add_volume_to_series(&$data, $series_id, $volume_number, $status = 'à lire', $is_collector = false, $is_last = false) {
    foreach ($data as &$series) {
        if ($series['id'] === $series_id) {
            // Vérifier si le tome existe déjà
            foreach ($series['volumes'] as $volume) {
                if ($volume['number'] == $volume_number) {
                    return false; // Le tome existe déjà
                }
            }

            // Ajouter le nouveau tome
            $series['volumes'][] = [
                'number' => $volume_number,
                'status' => $status,
                'collector' => $is_collector,
                'last' => $is_last
            ];

            // Trier les tomes par numéro
            usort($series['volumes'], function($a, $b) {
                return $a['number'] - $b['number'];
            });

            // Marquer le dernier tome comme 'dernier'
            $last_volume = end($series['volumes']);
            foreach ($series['volumes'] as &$volume) {
                $volume['last'] = ($volume['number'] == $last_volume['number']);
            }

            return true;
        }
    }
    return false;
}

// Fonction pour ajouter tous les tomes manquants à une série
function add_all_missing_volumes_to_series(&$data, $series_id, $missing_volumes, $status = 'à lire', $is_collector = false) {
    $series_index = null;
    foreach ($data as $index => &$series) {
        if ($series['id'] === $series_id) {
            $series_index = $index;
            break;
        }
    }

    if ($series_index !== null) {
        $last_volume_number = !empty($data[$series_index]['volumes']) ? max(array_map(function($v) { return $v['number']; }, $data[$series_index]['volumes'])) : 0;

        foreach ($missing_volumes as $volume_number) {
            if ($volume_number > $last_volume_number) {
                $data[$series_index]['volumes'][] = [
                    'number' => $volume_number,
                    'status' => $status,
                    'collector' => $is_collector,
                    'last' => false // On ne marque pas comme dernier tome, car on ajoute plusieurs tomes
                ];
            }
        }

        // Mettre à jour le dernier tome si nécessaire
        if (!empty($data[$series_index]['volumes'])) {
            $last_volume = max(array_map(function($v) { return $v['number']; }, $data[$series_index]['volumes']));
            foreach ($data[$series_index]['volumes'] as &$volume) {
                $volume['last'] = ($volume['number'] == $last_volume);
            }
        }

        return true;
    }

    return false;
}
?>