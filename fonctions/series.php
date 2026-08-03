<?php
// Construit la représentation "light" d'une série (mêmes champs que
// l'endpoint get_paginated_series&light=true), pour qu'une carte puisse être
// (re)générée côté client de façon identique, que ce soit lors du défilement
// infini ou juste après la mise à jour d'une série (sans recharger la page).
//
// $review_series_ids : ensemble des IDs de séries possédant une critique
// (array_flip(get_review_series_ids())).
// $loaned_volumes     : prêts de CETTE série uniquement (loans_by_series(...)[$id] ?? []).
function build_light_series(array $series, array $review_series_ids, array $loaned_volumes): array {
    // Détermine le statut de publication
    $status = 'en cours';
    $has_last = false;
    if (isset($series['volumes']) && is_array($series['volumes'])) {
        foreach ($series['volumes'] as $volume) {
            if (!empty($volume['last'])) {
                $has_last = true;
                $status = 'terminée';
                break;
            }
        }
    }
    if (isset($series['status'])) {
        $status = $series['status'];
    }

    // Calcule le statut de lecture — ou de visionnage pour un animé : même
    // jeu de valeurs, badges et filtres restent communs.
    if (is_anime($series)) {
        $reading_status = anime_watching_status($series);
    } else {
        $reading_status = 'not_started';
        if (!empty($series['reading_abandoned'])) {
            $reading_status = 'abandoned';
        } else {
            $read_count = 0;
            $total_count = 0;
            foreach ($series['volumes'] ?? [] as $volume) {
                $total_count++;
                if ($volume['status'] === 'terminé') $read_count++;
            }
            if ($total_count > 0 && $read_count === $total_count && $has_last) {
                $reading_status = 'completed';
            } elseif ($read_count > 0 && !$has_last) {
                $reading_status = 'in_progress';
            } elseif ($read_count > 0) {
                // Des tomes lus mais publication terminée sans tous avoir lu
                $reading_status = 'in_progress';
            }
        }
    }

    return [
        'id' => $series['id'],
        'name' => $series['name'],
        'type' => series_type($series),
        'author' => $series['author'],
        'publisher' => $series['publisher'],
        'other_contributors' => $series['other_contributors'] ?? [],
        'categories' => $series['categories'] ?? [],
        'genres' => $series['genres'] ?? [],
        // Vignette déjà résolue par la cascade perso → Anilist → défaut :
        // le front affiche ce qu'on lui donne, il n'arbitre rien.
        'image' => series_thumbnail($series),
        'volumes_count' => count($series['volumes']),
        'favorite' => $series['favorite'] ?? false,
        'mature' => $series['mature'] ?? false,
        'status' => $status,
        'reading_status' => $reading_status,
        'mangaupdates_url'           => $series['mangaupdates_url'] ?? '',
        'babelio_url'                => $series['babelio_url'] ?? '',
        'read_elsewhere'             => (bool)($series['read_elsewhere'] ?? false),
        'reading_abandoned'          => (bool)($series['reading_abandoned'] ?? false),
        'rating'                     => $series['rating'] ?? '',
        'has_review'                 => isset($review_series_ids[$series['id']]),
        'reread_count'               => (int)($series['reread_count'] ?? 0),
        'reread_last_date'           => $series['reread_last_date'] ?? '',
        // ── Champs animé (vides ou nuls sur un manga) ────────────────
        'anilist_id'                 => $series['anilist_id'] ?? '',
        'anilist_url'                => $series['anilist_url'] ?? '',
        'studios'                    => $series['studios'] ?? [],
        'studios_text'               => series_studios_text($series),
        'anime_format'               => $series['anime_format'] ?? '',
        'format_label'               => is_anime($series)
                                            ? anilist_format_label($series['anime_format'] ?? '')
                                            : '',
        'alt_titles'                 => series_alt_titles($series),
        'watching_abandoned'         => (bool)($series['watching_abandoned'] ?? false),
        'rewatch_count'              => (int)($series['rewatch_count'] ?? 0),
        'rewatch_last_date'          => $series['rewatch_last_date'] ?? '',
        'editions'                   => series_edition_comments($series),
        // Vignette personnalisée seule : la modale d'édition doit savoir
        // s'il y en a une à proposer de supprimer, indépendamment de la
        // vignette Anilist qui, elle, ne s'efface jamais à la main.
        'custom_image'               => $series['image'] ?? '',
        'anilist_image'              => $series['anilist_image'] ?? '',
        // ── Synchronisation automatique ──────────────────────────────
        // Le front décide lui-même s'il doit déclencher une synchro à
        // l'affichage de la carte : sync_due lui épargne un aller-retour
        // pour rien sur une série déjà à jour ou hors verrou.
        'anilist_synced_at'          => (int)($series['anilist_synced_at'] ?? 0),
        'sync_due'                   => is_anime($series) && anilist_sync_is_due($series, false),
        // Tomes/épisodes déjà rendus en HTML : affichés directement à la
        // création de la carte, sans aller-retour AJAX supplémentaire.
        'volumes_html'               => render_volumes_html(
            $series,
            [],
            $loaned_volumes
        ),
    ];
}

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

    $new_series = [
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
        // Un nombre de relectures renseigné dès la création (import manuel
        // d'une série déjà relue par le passé) n'a pas de date réelle connue :
        // conformément à la règle générale (cf. config.php), on ne date que
        // les AUGMENTATIONS ultérieures du compteur, jamais rétroactivement.
        'reread_last_date' => '',
        'volumes' => $volumes
    ];

    // Écriture ciblée : seule la nouvelle série est écrite en base, via un
    // upsert sur `series` + un remplacement des tomes qui lui appartiennent.
    // Aucune autre série n'est lue ni réécrite.
    upsert_series_row($new_series);
    replace_series_volumes($new_series['id'], $new_series['volumes']);

    $data[] = $new_series;

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
        $new_reread_count = max(0, (int)$reread_count);
        $previous_reread_count = (int)($data[$series_key]['reread_count'] ?? 0);
        $data[$series_key]['reread_count'] = $new_reread_count;
        // Ne date que les AUGMENTATIONS du compteur : une baisse (correction
        // d'une saisie erronée) ou une valeur inchangée ne touche jamais la
        // date de dernière relecture, qui alimente la page « Historique ».
        if ($new_reread_count > $previous_reread_count) {
            $data[$series_key]['reread_last_date'] = date('Y-m-d');
        }
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
    unset($volume); // referme la dernière boucle par référence ci-dessus

    // Écriture ciblée : upsert sur la seule ligne `series` modifiée +
    // remplacement des tomes qui lui appartiennent. Aucune autre série
    // n'est touchée.
    upsert_series_row($data[$series_key]);
    replace_series_volumes($series_id, $data[$series_key]['volumes']);

    return ['success' => true, 'data' => $data];
}

// Supprimer une série
//
// Bloc 1 (historique) de la migration des écritures ciblées (cf.
// MIGRATION_SAVE_DATA.md) : la suppression est effective en base dès cet
// appel, via delete_series_row() (DELETE ciblé + ON DELETE CASCADE sur
// volumes/series_editions). $data n'est renvoyé que pour permettre de
// réafficher la collection à jour côté admin, il ne sert à aucune écriture.
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
    // éditions) en base. Aucune autre série n'est lue ni réécrite.
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