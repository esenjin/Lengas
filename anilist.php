<?php
// anilist.php

// Fonction pour faire des requêtes à l'API Anilist
function fetch_from_anilist($query, $variables = []) {
    $url = 'https://graphql.anilist.co';

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

    $context  = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        // Gestion des erreurs
        error_log("Erreur lors de la requête à l'API Anilist");
        return null;
    }

    return json_decode($response, true);
}

// Fonction pour obtenir le nombre de volumes d'une série à partir de son ID Anilist
function get_series_volumes_from_anilist($anilist_id) {
    if (!$anilist_id) {
        return null;
    }

    $query = '
    query ($id: Int) {
        Media (id: $id, type: MANGA) {
            volumes
        }
    }
    ';

    $variables = [
        'id' => (int)$anilist_id
    ];

    $data = fetch_from_anilist($query, $variables);

    if (isset($data['data']['Media']['volumes'])) {
        return $data['data']['Media']['volumes'];
    }

    return null;
}

// Fonction pour obtenir les séries incomplètes
function get_incomplete_series($data) {
    $incomplete_series = [];

    foreach ($data as $series) {
        if (isset($series['anilist_id']) && !empty($series['anilist_id'])) {
            $anilist_volumes = get_series_volumes_from_anilist($series['anilist_id']);
            if ($anilist_volumes !== null) {
                $owned_volumes = count($series['volumes']);
                if ($owned_volumes < $anilist_volumes) {
                    $missing_volumes = [];
                    for ($i = $owned_volumes + 1; $i <= $anilist_volumes; $i++) {
                        $missing_volumes[] = $i;
                    }
                    $series['missing_volumes'] = $missing_volumes;
                    $incomplete_series[] = $series;
                }
            }
        }
    }

    return $incomplete_series;
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