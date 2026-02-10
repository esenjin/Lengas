<?php
// Ajouter une série
function add_series($data, $name, $author, $publisher, $other_contributors, $categories, $genres, $anilist_id, $mature, $favorite, $volumes_count, $volumes_status, $all_collector, $last_volume, $image) {
    $series_exists = false;
    foreach ($data as $series) {
        if (strcasecmp($series['name'], $name) === 0) {
            $series_exists = true;
            break;
        }
    }

    if ($series_exists) {
        return ['success' => false, 'message' => "Une série avec ce nom existe déjà."];
    }

    $volumes = [];
    for ($i = 1; $i <= $volumes_count; $i++) {
        $volumes[] = [
            'number' => $i,
            'status' => $volumes_status,
            'collector' => $all_collector,
            'last' => ($last_volume && $i == $volumes_count),
            'added_at' => date('Y-m-d')
        ];
    }

    $data[] = [
        'id' => generate_uuid(),
        'name' => $name,
        'author' => $author,
        'publisher' => $publisher,
        'other_contributors' => explode(',', $other_contributors),
        'categories' => explode(',', $categories),
        'genres' => explode(',', $genres),
        'image' => $image ?? '',
        'anilist_id' => $anilist_id,
        'mature' => $mature,
        'favorite' => $favorite,
        'volumes' => $volumes
    ];

    return ['success' => true, 'data' => $data];
}

// Mettre à jour une série
function update_series($data, $series_id, $name, $author, $other_contributors, $publisher, $categories, $genres, $anilist_id, $mature, $favorite, $remove_image, $new_volumes_count, $new_volumes_status, $new_volumes_collector, $new_volumes_last, $new_image = null) {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['success' => false, 'message' => "Série introuvable."];
    }

    $series_index = $series['index'];

    $data[$series_index]['name'] = $name;
    $data[$series_index]['author'] = $author;
    $data[$series_index]['publisher'] = $publisher;
    $data[$series_index]['other_contributors'] = explode(',', $other_contributors);
    $data[$series_index]['categories'] = explode(',', $categories);
    $data[$series_index]['genres'] = explode(',', $genres);
    $data[$series_index]['anilist_id'] = $anilist_id;
    $data[$series_index]['mature'] = $mature;
    $data[$series_index]['favorite'] = $favorite;

    if ($remove_image && !empty($data[$series_index]['image']) && file_exists($data[$series_index]['image'])) {
        unlink($data[$series_index]['image']);
        $data[$series_index]['image'] = '';
    }

    if ($new_image) {
        if (!empty($data[$series_index]['image']) && file_exists($data[$series_index]['image'])) {
            unlink($data[$series_index]['image']);
        }
        $data[$series_index]['image'] = $new_image;
    }

    if ($new_volumes_count > 0) {
        $current_volumes = $data[$series_index]['volumes'];
        $max_volume_number = !empty($current_volumes) ? max(array_column($current_volumes, 'number')) : 0;

        for ($i = 1; $i <= $new_volumes_count; $i++) {
            $new_volume_number = $max_volume_number + $i;
            $data[$series_index]['volumes'][] = [
                'number' => $new_volume_number,
                'status' => $new_volumes_status,
                'collector' => $new_volumes_collector,
                'last' => ($new_volumes_last && $i == $new_volumes_count),
                'added_at' => date('Y-m-d')
            ];
        }
    }

    return ['success' => true, 'data' => $data];
}

// Supprimer une série
function delete_series($data, $series_id) {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['success' => false, 'message' => "Série introuvable."];
    }

    $series_index = $series['index'];
    $image_path = $data[$series_index]['image'];
    if (file_exists($image_path)) {
        unlink($image_path);
    }

    array_splice($data, $series_index, 1);
    return ['success' => true, 'data' => $data];
}