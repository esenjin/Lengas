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
            'last' => $is_last
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
                'last' => ($i == $volumes_count) ? $is_last : false
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
function update_volume($data, $series_id, $volume_index, $status, $is_collector, $is_last) {
    $series = find_series_by_id($data, $series_id);
    if (!$series || !isset($data[$series['index']]['volumes'][$volume_index])) {
        return ['success' => false, 'message' => "Série ou volume introuvable."];
    }

    $data[$series['index']]['volumes'][$volume_index] = [
        'number' => $data[$series['index']]['volumes'][$volume_index]['number'],
        'status' => $status,
        'collector' => $is_collector,
        'last' => $is_last
    ];

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