<?php
// Ajouter une série
// $type : clé du registre de types (includes/helpers.php). Par défaut 'manga',
// la modale d'ajout de cette page ne créant que des mangas et light-novels.
function add_series($data, $name, $author, $publisher, $other_contributors, $categories, $genres, $mangaupdates_url, $babelio_url, $mature, $favorite, $volumes_count, $volumes_status, $all_collector, $last_volume, $image, $status = 'en cours', $read_elsewhere = false, $reading_abandoned = false, $rating = '', $type = 'manga', $reread_count = 0) {
    $volumes = [];
    for ($i = 1; $i <= $volumes_count; $i++) {
        $volumes[] = [
            'number' => $i,
            'status' => $volumes_status,
            'collector' => $all_collector,
            'last' => false,
            'added_at' => date('Y-m-d'),
            'read_at' => ($volumes_status === 'terminé') ? date('Y-m-d') : ''
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

    // Vérifie si une série du même nom ET du même type existe déjà. Un manga et
    // un animé homonymes (ex. « One Piece » des deux côtés) ne sont pas un
    // doublon : ce sont deux œuvres différentes qui partagent un titre. Cette
    // fonction ne crée que des mangas (voir le commentaire de tête), donc la
    // comparaison se limite explicitement au type 'manga'.
    $existing_series = array_filter($data, function($s) use ($name) {
        return strtolower(trim($s['name'])) === strtolower(trim($name)) && series_type($s) === 'manga';
    });

    $message = "Série ajoutée avec succès.";
    if (!empty($existing_series)) {
        $message = "Série créée, attention, une autre du même nom existe déjà.";
    }

    $data[] = [
        'id' => generate_uuid(),
        'name' => $name,
        'type' => sanitize_series_type($type),
        'author' => $author,
        'publisher' => $publisher,
        'other_contributors' => explode(',', $other_contributors),
        'categories' => explode(',', $categories),
        'genres' => explode(',', $genres),
        'image' => $image ?? '',
        'mangaupdates_url' => $mangaupdates_url,
        'babelio_url' => $babelio_url,
        'mature' => $mature,
        'favorite' => $favorite,
        'status' => $status,
        'read_elsewhere' => (bool)$read_elsewhere,
        'reading_abandoned' => (bool)$reading_abandoned,
        'rating' => sanitize_rating($rating),
        'reread_count' => max(0, (int)$reread_count),
        'volumes' => $volumes
    ];

    return ['success' => true, 'data' => $data, 'message' => $message];
}

// Mettre à jour une série
function update_series($data, $series_id, $name, $author, $other_contributors, $publisher, $categories, $genres, $mangaupdates_url, $babelio_url, $mature, $favorite, $remove_image, $new_volumes_count, $new_volumes_status, $new_volumes_collector, $new_volumes_last, $new_image = null, $new_status = null, $read_elsewhere = null, $reading_abandoned = null, $rating = null, $reread_count = null) {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['success' => false, 'message' => "Série introuvable."];
    }

    $series_key = $series['key'];  // Utilise la clé associative
    $series_data = $series['data'];

    // Garde-fou V4 : cette fonction est celle de la modale d'édition des mangas.
    // Appliquée à un animé, elle écraserait des champs qu'Anilist seul renseigne
    // (auteur, éditeur, catégories, genres) et créerait des tomes là où seuls
    // des épisodes importés ont leur place. Les animés passent par
    // update_anime_series() (fonctions/anime.php).
    if (function_exists('is_anime') && is_anime($series_data)) {
        return ['success' => false, 'message' => "Une série animée se modifie depuis sa propre fiche."];
    }

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
    $data[$series_key]['mangaupdates_url'] = $mangaupdates_url;
    $data[$series_key]['babelio_url'] = $babelio_url;
    $data[$series_key]['mature'] = $mature;
    $data[$series_key]['favorite'] = $favorite;
    if ($read_elsewhere !== null) {
        $data[$series_key]['read_elsewhere'] = (bool)$read_elsewhere;
    }
    if ($reading_abandoned !== null) {
        $data[$series_key]['reading_abandoned'] = (bool)$reading_abandoned;
    }
    if ($rating !== null) {
        $data[$series_key]['rating'] = sanitize_rating($rating);
    }
    if ($reread_count !== null) {
        $data[$series_key]['reread_count'] = max(0, (int)$reread_count);
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
                'added_at' => date('Y-m-d'),
                'read_at' => ($new_volumes_status === 'terminé') ? date('Y-m-d') : ''
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
//
// Bloc 1 de la migration save_data() → écritures ciblées (cf.
// MIGRATION_SAVE_DATA.md) : la suppression est effective en base dès cet
// appel, via delete_series_row() (DELETE ciblé + ON DELETE CASCADE sur
// volumes/series_editions). L'appelant n'a plus besoin d'appeler save_data()
// derrière — $data n'est renvoyé que pour permettre de réafficher la
// collection à jour côté admin, il ne sert plus à répercuter la suppression.
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

    // Vignette Anilist : purgée avec la série, au même titre que la vignette
    // personnalisée. Sans quoi le fichier resterait indéfiniment dans uploads/.
    // Les éditions physiques, elles, partent d'elles-mêmes : la table
    // series_editions est en ON DELETE CASCADE sur series(id).
    $anilist_image = trim((string)($data[$series_key]['anilist_image'] ?? ''));
    if ($anilist_image !== '' && file_exists($anilist_image)) {
        @unlink($anilist_image);
    }

    // Écriture ciblée : supprime UNIQUEMENT cette série (+ cascade tomes/
    // éditions) en base. Plus de transit par un save_data($data_sans_la_série).
    delete_series_row($series_id);

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