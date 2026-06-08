<?php
// Ajouter une série
function add_series($data, $name, $author, $publisher, $other_contributors, $categories, $genres, $anilist_id, $mature, $favorite, $volumes_count, $volumes_status, $all_collector, $last_volume, $image, $status = 'en cours', $nautiljon_url = '') {
    $volumes = [];
    for ($i = 1; $i <= $volumes_count; $i++) {
        $volumes[] = [
            'number' => $i,
            'status' => $volumes_status,
            'collector' => $all_collector,
            'last' => false,
            'added_at' => date('Y-m-d')
        ];
    }

    // Si la série est terminée, ou si l'utilisateur a coché "dernier tome",
    // on tag le dernier tome comme tel
    if ($volumes_count > 0 && ($status === 'terminée' || $last_volume)) {
        $volumes[$volumes_count - 1]['last'] = true;
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
        'status' => $status,
        'nautiljon_url' => $nautiljon_url,
        'volumes' => $volumes
    ];

    return ['success' => true, 'data' => $data, 'message' => $message];
}

// Mettre à jour une série
function update_series($data, $series_id, $name, $author, $other_contributors, $publisher, $categories, $genres, $anilist_id, $mature, $favorite, $remove_image, $new_volumes_count, $new_volumes_status, $new_volumes_collector, $new_volumes_last, $new_image = null, $new_status = null, $nautiljon_url = null) {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['success' => false, 'message' => "Série introuvable."];
    }

    $series_key = $series['key'];  // Utilise la clé associative
    $series_data = $series['data'];

    // Détermine le statut actuel si non fourni
    if ($new_status === null) {
        $has_last_volume = false;
        foreach ($series_data['volumes'] as $volume) {
            if (!empty($volume['last'])) {
                $has_last_volume = true;
                break;
            }
        }
        $new_status = $has_last_volume ? 'terminée' : ($series_data['status'] ?? 'en cours');
    }

    // Met à jour directement via la clé
    $data[$series_key]['status'] = $new_status;
    $data[$series_key]['name'] = $name;
    $data[$series_key]['author'] = $author;
    $data[$series_key]['publisher'] = $publisher;
    $data[$series_key]['other_contributors'] = explode(',', clean_comma_separated($other_contributors));
    $data[$series_key]['categories'] = explode(',', clean_comma_separated($categories));
    $data[$series_key]['genres'] = explode(',', clean_comma_separated($genres));
    $data[$series_key]['anilist_id'] = $anilist_id;
    $data[$series_key]['mature'] = $mature;
    $data[$series_key]['favorite'] = $favorite;

    // Nautiljon URL : si modifiée, invalider le cache
    if ($nautiljon_url !== null) {
        $old_nj_url = $data[$series_key]['nautiljon_url'] ?? '';
        if ($nautiljon_url !== $old_nj_url) {
            // Invalider le cache pour forcer un refresh
            $data[$series_key]['nautiljon_last_checked'] = 0;
        }
        $data[$series_key]['nautiljon_url'] = $nautiljon_url;
    }

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

    // Ajout de nouveaux tomes (sans tag "last" pour l'instant)
    if ($new_volumes_count > 0) {
        $current_volumes = $data[$series_key]['volumes'];
        $max_volume_number = !empty($current_volumes) ? max(array_column($current_volumes, 'number')) : 0;

        for ($i = 1; $i <= $new_volumes_count; $i++) {
            $new_volume_number = $max_volume_number + $i;
            $data[$series_key]['volumes'][] = [
                'number' => $new_volume_number,
                'status' => $new_volumes_status,
                'collector' => $new_volumes_collector,
                'last' => false,
                'added_at' => date('Y-m-d')
            ];
        }
    }

    // Gestion du tag "last" APRÈS l'ajout des nouveaux tomes
    if ($new_status === 'terminée') {
        // D'abord on retire tous les tags "last" existants
        foreach ($data[$series_key]['volumes'] as &$volume) {
            $volume['last'] = false;
        }
        // Puis on tag le dernier tome de la liste complète (incluant les nouveaux)
        $last_index = count($data[$series_key]['volumes']) - 1;
        if ($last_index >= 0) {
            $data[$series_key]['volumes'][$last_index]['last'] = true;
        }
    } elseif ($new_volumes_last && $new_volumes_count > 0) {
        // Statut non "terminée" mais l'utilisateur a coché "dernier tome" :
        // on retire les anciens tags "last" et on tag le dernier des nouveaux
        foreach ($data[$series_key]['volumes'] as &$volume) {
            $volume['last'] = false;
        }
        $last_index = count($data[$series_key]['volumes']) - 1;
        if ($last_index >= 0) {
            $data[$series_key]['volumes'][$last_index]['last'] = true;
        }
    } else {
        // Statut non "terminée" et pas de tag "last" demandé : on retire tous les tags
        foreach ($data[$series_key]['volumes'] as &$volume) {
            $volume['last'] = false;
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
    $series_name = $data[$series_key]['name'];
    $image_path = $data[$series_key]['image'];

    if (file_exists($image_path)) {
        unlink($image_path);
    }

    unset($data[$series_key]);
    return [
        'success' => true,
        'data' => $data,
        'message' => "La série $series_name a été supprimée avec succès."
    ];
}

// Fonction pour nettoyer les espaces après les virgules
function clean_comma_separated($string) {
    return preg_replace('/\s*,\s*/', ',', trim($string));
}