<?php
// Ajouter une série
function add_series($data, $name, $author, $publisher, $other_contributors, $categories, $genres, $anilist_id, $mature, $favorite, $volumes_count, $volumes_status, $all_collector, $last_volume, $image) {
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

    $other_contributors = clean_comma_separated($other_contributors);
    $categories = clean_comma_separated($categories);
    $genres = clean_comma_separated($genres);

    // Vérifie si une série du même nom existe déjà
    $existing_series = array_filter($data, function($s) use ($name) {
        return strtolower(trim($s['name'])) === strtolower(trim($name));
    });

    $message = "Série ajoutée avec succès.";
    if (!empty($existing_series)) {
        $message = "Série créée, attention, une autre du même nom existe déjà.";
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

    return ['success' => true, 'data' => $data, 'message' => $message];
}

// Mettre à jour une série
function update_series($data, $series_id, $name, $author, $other_contributors, $publisher, $categories, $genres, $anilist_id, $mature, $favorite, $remove_image, $new_volumes_count, $new_volumes_status, $new_volumes_collector, $new_volumes_last, $new_image = null) {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['success' => false, 'message' => "Série introuvable."];
    }

    $series_key = $series['key'];  // Utilise la clé associative
    $series_data = $series['data'];

    // Met à jour directement via la clé
    $data[$series_key]['name'] = $name;
    $data[$series_key]['author'] = $author;
    $data[$series_key]['publisher'] = $publisher;
    $data[$series_key]['other_contributors'] = explode(',', clean_comma_separated($other_contributors));
    $data[$series_key]['categories'] = explode(',', clean_comma_separated($categories));
    $data[$series_key]['genres'] = explode(',', clean_comma_separated($genres));
    $data[$series_key]['anilist_id'] = $anilist_id;
    $data[$series_key]['mature'] = $mature;
    $data[$series_key]['favorite'] = $favorite;

    // Gestion de l'image
    if ($remove_image && !empty($data[$series_key]['image']) && file_exists($data[$series_key]['image'])) {
        unlink($data[$series_key]['image']);
        $data[$series_key]['image'] = '';
    }

    if ($new_image) {
        if (!empty($data[$series_key]['image']) && file_exists($data[$series_key]['image'])) {
            unlink($data[$series_key]['image']);
        }
        $data[$series_key]['image'] = $new_image;
    }

    // Ajout de nouveaux tomes
    if ($new_volumes_count > 0) {
        $current_volumes = $data[$series_key]['volumes'];
        $max_volume_number = !empty($current_volumes) ? max(array_column($current_volumes, 'number')) : 0;

        for ($i = 1; $i <= $new_volumes_count; $i++) {
            $new_volume_number = $max_volume_number + $i;
            $data[$series_key]['volumes'][] = [
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

    $series_key = $series['key'];
    $image_path = $data[$series_key]['image'];

    if (file_exists($image_path)) {
        unlink($image_path);
    }

    unset($data[$series_key]);
    return ['success' => true, 'data' => $data];
}

// Fonction pour nettoyer les espaces après les virgules
function clean_comma_separated($string) {
    return preg_replace('/\s*,\s*/', ',', trim($string));
}