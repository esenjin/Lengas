<?php
// Ajouter un tome
function add_volume_to_series($data, $series_id, $volume_number, $status, $is_collector, $is_last) {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['success' => false, 'message' => "Série introuvable."];
    }

    $series_index = $series['index'];
    $volume_exists = false;
    foreach ($data[$series_index]['volumes'] as $volume) {
        if ((int)$volume['number'] === $volume_number) {
            $volume_exists = true;
            break;
        }
    }

    if (!$volume_exists) {
        $data[$series_index]['volumes'][] = [
            'number' => $volume_number,
            'status' => $status,
            'collector' => $is_collector,
            'last' => $is_last,
            'added_at' => date('Y-m-d'),
            'read_at' => ($status === 'terminé') ? date('Y-m-d') : ''
        ];
        return ['success' => true, 'data' => $data];
    } else {
        return ['success' => false, 'message' => "Le tome $volume_number existe déjà."];
    }
}

// Ajouter plusieurs tomes
function add_multiple_volumes_to_series($data, $series_id, $volumes_count, $status, $is_collector, $is_last) {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['success' => false, 'message' => "Série introuvable."];
    }

    $series_index = $series['index'];
    $current_volumes = $data[$series_index]['volumes'];
    $max_volume_number = !empty($current_volumes) ? max(array_column($current_volumes, 'number')) : 0;
    $existing_volumes = [];

    // Convertir explicitement en booléen
    $is_collector = (bool)$is_collector;
    $is_last = (bool)$is_last;

    for ($i = 1; $i <= $volumes_count; $i++) {
        $new_volume_number = $max_volume_number + $i;
        $volume_exists = false;
        foreach ($data[$series_index]['volumes'] as $volume) {
            if ((int)$volume['number'] === $new_volume_number) {
                $volume_exists = true;
                break;
            }
        }

        if (!$volume_exists) {
            $data[$series_index]['volumes'][] = [
                'number' => $new_volume_number,
                'status' => $status,
                'collector' => $is_collector,
                'last' => ($is_last && $i == $volumes_count),
                'added_at' => date('Y-m-d'),
                'read_at' => ($status === 'terminé') ? date('Y-m-d') : ''
            ];
        } else {
            $existing_volumes[] = $new_volume_number;
        }
    }

    if (!empty($existing_volumes)) {
        return ['success' => false, 'message' => "Les tomes " . implode(', ', $existing_volumes) . " existent déjà."];
    }

    return ['success' => true, 'data' => $data];
}

// Mettre à jour un tome
function update_volume($data, $series_id, $volume_index, $status, $is_collector, $is_last, $read_at = null) {
    $series = find_series_by_id($data, $series_id);
    if (!$series || !isset($data[$series['index']]['volumes'][$volume_index])) {
        return ['success' => false, 'message' => "Série ou volume introuvable."];
    }

    $idx = $series['index'];
    $previous_status  = $data[$idx]['volumes'][$volume_index]['status'] ?? '';
    $previous_read_at = $data[$idx]['volumes'][$volume_index]['read_at'] ?? '';

    // Détermination de read_at :
    // - si une date a été fournie explicitement (édition manuelle), elle prime, à condition que le statut reste "terminé"
    // - sinon, si on passe de non-"terminé" à "terminé", on date au jour
    // - si on était déjà "terminé" et qu'on le reste, on conserve la date existante
    // - si on quitte le statut "terminé", on efface la date
    if ($status === 'terminé') {
        if ($read_at !== null && $read_at !== '') {
            $new_read_at = $read_at;
        } elseif ($previous_status === 'terminé' && $previous_read_at !== '') {
            $new_read_at = $previous_read_at;
        } else {
            // Soit on vient de passer à "terminé", soit le tome était déjà
            // "terminé" mais sans date connue (ancienne donnée jamais migrée) :
            // dans les deux cas on date au jour plutôt que de laisser un trou.
            $new_read_at = date('Y-m-d');
        }
    } else {
        $new_read_at = '';
    }

    $data[$idx]['volumes'][$volume_index] = [
        'number'    => $data[$idx]['volumes'][$volume_index]['number'],
        'status'    => $status,
        'collector' => $is_collector,
        'last'      => $is_last,
        'added_at'  => $data[$idx]['volumes'][$volume_index]['added_at'] ?? date('Y-m-d'),
        'read_at'   => $new_read_at,
    ];

    // Synchroniser le statut de la série avec l'état du tag "dernier tome"
    $current_series_status = $data[$idx]['status'] ?? 'en cours';
    $has_last = false;
    foreach ($data[$idx]['volumes'] as $v) {
        if (!empty($v['last'])) { $has_last = true; break; }
    }

    // Si on vient de cocher "dernier" et que la série n'est pas déjà terminée/abandonnée/en pause,
    // on la passe à "terminée"
    if ($has_last && $current_series_status === 'en cours') {
        $data[$idx]['status'] = 'terminée';
    }

    // Si on vient de décocher le seul "dernier" et que la série était "terminée",
    // on repasse à "en cours"
    if (!$has_last && $current_series_status === 'terminée') {
        $data[$idx]['status'] = 'en cours';
    }

    return ['success' => true, 'data' => $data];
}

// Supprimer un tome
function delete_volume($data, $series_id, $volume_index) {
    $series = find_series_by_id($data, $series_id);
    if (!$series || !isset($data[$series['index']]['volumes'][$volume_index])) {
        return ['success' => false, 'message' => "Série ou volume introuvable."];
    }

    array_splice($data[$series['index']]['volumes'], $volume_index, 1);
    return ['success' => true, 'data' => $data];
}
