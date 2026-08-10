<?php
// Génère le HTML de la liste des tomes (ou épisodes) d'une série, tel
// qu'affiché dans une carte de série. Factorisé pour être appelable aussi
// bien depuis l'endpoint de rafraîchissement ponctuel (get_series_volumes)
// que depuis la liste paginée, qui l'inclut désormais directement dans
// chaque carte (plus de chargement à la demande depuis le passage à SQLite,
// qui rend ce contenu déjà bon marché à générer).
//
// $loaned_volumes : tableau [numéro_de_tome => nom_emprunteur] déjà filtré
// sur la série concernée (le prêt ne concerne jamais les animés).
function render_volumes_html(array $series, array $notifications = [], array $loaned_volumes = []): string {
    $series_id       = $series['id'];
    $series_is_anime = is_anime($series);
    $vocab           = type_vocab($series);
    $volumes         = $series['volumes'] ?? [];

    $format_date = function ($d) {
        if (empty($d)) return '';
        $ts = strtotime($d);
        return $ts ? date('d/m/Y', $ts) : '';
    };

    $volumes_html = '<ul class="volumes-list">';
    foreach ($volumes as $volume_index => $volume) {
        $is_loaned = isset($loaned_volumes[$volume['number']]);

        // Construire l'infobulle (survol)
        $tooltip_lines = [];

        $added_at = $format_date($volume['added_at'] ?? '');
        if ($added_at !== '' && !$series_is_anime) {
            $tooltip_lines[] = "Date d'ajout à la collection : $added_at";
        }

        if (($volume['status'] ?? '') === 'terminé') {
            $read_at = $format_date($volume['read_at'] ?? '');
            if ($read_at !== '') {
                // « Date de lecture » ou « Date de visionnage »
                $tooltip_lines[] = 'Date de ' . $vocab['activity'] . " : $read_at";
            }
        }

        // Le collector n'a pas de sens pour un épisode : c'est l'édition
        // physique de la série entière qui est collector, pas l'épisode.
        if (!empty($volume['collector']) && !$series_is_anime) {
            $tooltip_lines[] = 'Tome collector !';
        }

        if (!empty($volume['last'])) {
            $tooltip_lines[] = 'Dernier ' . $vocab['item'] . ' de la série !';
        }

        if ($is_loaned) {
            $tooltip_lines[] = 'Prêté à ' . $loaned_volumes[$volume['number']];
        }

        $title_attr = !empty($tooltip_lines)
            ? ' data-title="' . htmlspecialchars(implode("\n", $tooltip_lines), ENT_QUOTES) . '"'
            : '';

        $volumes_html .= sprintf(
            '<li class="status-%s%s%s%s"%s data-series-id="%s" data-volume-index="%d">%d%s</li>',
            str_replace(' ', '-', strtolower($volume['status'])),
            !empty($volume['collector']) ? ' volume-collector' : '',
            !empty($volume['last']) ? ' volume-last' : '',
            $is_loaned ? ' volume-loaned' : '',
            $title_attr,
            $series_id,
            $volume_index,
            $volume['number'],
            $is_loaned ? '<span class="volume-loan-badge" aria-label="En prêt">🤝</span>' : ''
        );
    }

    // Bouton « + » de fin de liste. Même geste, deux sens selon la collection :
    //
    //   • manga  → ouvre la modale « Ajouter des tomes », série pré-sélectionnée.
    //     Masqué si le dernier tome porte le tag « dernier tome de la série »
    //     (collection réputée complète) ;
    //   • animé  → fait passer le PREMIER épisode non terminé en « terminé ».
    //     Il n'ajoute donc rien : les épisodes viennent d'Anilist. Masqué une
    //     fois tous les épisodes vus, faute d'épisode suivant à marquer.
    if ($series_is_anime) {
        if (anime_next_episode_index($series) >= 0) {
            $volumes_html .= sprintf(
                '<li class="volume-add-btn episode-mark-btn" data-series-id="%s" title="Marquer l\'épisode suivant comme %s" aria-label="Marquer l\'épisode suivant comme %s">+</li>',
                htmlspecialchars($series_id, ENT_QUOTES),
                htmlspecialchars($vocab['done_short'], ENT_QUOTES),
                htmlspecialchars($vocab['done_short'], ENT_QUOTES)
            );
        }
    } else {
        $last_volume = !empty($volumes) ? end($volumes) : null;
        $series_is_complete = $last_volume !== null && !empty($last_volume['last']);
        if (!$series_is_complete) {
            $volumes_html .= sprintf(
                '<li class="volume-add-btn" data-series-id="%s" title="Ajouter des tomes à cette série" aria-label="Ajouter des tomes">+</li>',
                htmlspecialchars($series_id, ENT_QUOTES)
            );
        }
    }
    $volumes_html .= '</ul>';

    // Une série animée sans épisode n'est pas une série vide à compléter : c'est
    // une série dont rien n'a encore été diffusé. On le dit, plutôt que de
    // laisser une liste vide et sans explication.
    if ($series_is_anime && empty($volumes)) {
        $volumes_html = '<p class="hint">Aucun épisode diffusé pour le moment.</p>' . $volumes_html;
    }

    // Ajouter les notifications si nécessaire
    if (!empty($notifications)) {
        $volumes_html = '<div class="issues-list"><span class="warning-icon">⚠️</span><span class="issues-text">' . implode(' ', $notifications) . '</span></div>' . $volumes_html;
    }

    return $volumes_html;
}

// Calcule les notifications (tomes manquants / mal étiquetés) d'une série
// manga. Toujours vide pour un animé : le décompte vient d'Anilist, rien ne
// manque jamais à une liste d'épisodes qu'on ne remplit pas à la main.
//
// $mu_volumes_cache : tableau optionnel [id_mangaupdates => résultat] déjà
// préchargé en une seule fois pour toute la page (mangaupdates_get_volumes_batch),
// pour éviter une requête de cache par série lors du rendu d'une liste entière.
function series_notifications(array $series, array $mu_volumes_cache = []): array {
    if (is_anime($series)) {
        return [];
    }

    $ref_volumes = null;
    if (!empty($series['mangaupdates_url'])) {
        $mu_id = mangaupdates_get_id_from_url($series['mangaupdates_url']);
        if ($mu_id !== null) {
            $mu = $mu_volumes_cache[$mu_id] ?? mangaupdates_get_volumes($mu_id);
            if ($mu !== null && $mu['volumes'] !== null && (int)$mu['volumes'] > 0) {
                $ref_volumes = (int)$mu['volumes'];
            }
        }
    }
    return generate_notifications($series['volumes'] ?? [], $ref_volumes);
}

// Regroupe les prêts en cours par série, sous la forme attendue par
// render_volumes_html() : [série_id => [numéro_de_tome => nom_emprunteur]].
// Une seule requête pour toute la collection plutôt qu'une par série.
function loans_by_series(array $all_loans): array {
    $by_series = [];
    foreach ($all_loans as $loan) {
        $by_series[$loan['series_id']][(int)$loan['volume_number']] = $loan['borrower_name'];
    }
    return $by_series;
}

// Ajouter un tome
//
// Écriture ciblée : seuls les tomes de la série concernée sont réécrits
// en base, via replace_series_volumes(). Aucune autre série n'est lue ni
// réécrite.
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

        // Synchroniser le statut de la série avec le tag "dernier tome",
        // comme le fait update_volume() ci-dessous — sans quoi un tome ajouté
        // ici en cochant "dernier" laisse la série en "en cours" et se fait
        // aussitôt signaler par l'outil « Vérification des mangas ».
        if ($is_last && ($data[$series_index]['status'] ?? 'en cours') === 'en cours') {
            $data[$series_index]['status'] = 'terminée';
        }

        replace_series_volumes($series_id, $data[$series_index]['volumes']);
        upsert_series_row($data[$series_index]);
        return ['success' => true, 'data' => $data];
    } else {
        return ['success' => false, 'message' => "Le tome $volume_number existe déjà."];
    }
}

// Ajouter plusieurs tomes
//
// Écriture ciblée : seuls les tomes de la série concernée sont réécrits
// en base, via replace_series_volumes(). Aucune autre série n'est lue ni
// réécrite.
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

    // Synchroniser le statut de la série avec le tag "dernier tome", comme le
    // fait update_volume() ci-dessous — sans quoi une série dont le dernier
    // tome ajouté est coché "dernier" reste "en cours" et se fait aussitôt
    // signaler par l'outil « Vérification des mangas ».
    if ($is_last && ($data[$series_index]['status'] ?? 'en cours') === 'en cours') {
        $data[$series_index]['status'] = 'terminée';
    }

    replace_series_volumes($series_id, $data[$series_index]['volumes']);
    upsert_series_row($data[$series_index]);

    return ['success' => true, 'data' => $data];
}

// Mettre à jour un tome
//
// Écriture ciblée : les tomes de la série concernée sont réécrits via
// replace_series_volumes(). Attention particulière ici : cette fonction peut
// aussi faire basculer le statut de la SÉRIE (passage auto en « terminée » /
// « en cours » selon le tag « dernier tome ») — un upsert_series_row() est
// donc systématiquement fait en plus des tomes, même si $data[$idx]['status']
// n'a en réalité pas changé (upsert idempotent, sans coût de correction
// supplémentaire).
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

    // Écriture ciblée : les tomes de la série concernée + la ligne `series`
    // elle-même (son `status` peut avoir changé ci-dessus). Aucune autre
    // série n'est lue ni réécrite.
    replace_series_volumes($series_id, $data[$idx]['volumes']);
    upsert_series_row($data[$idx]);

    return ['success' => true, 'data' => $data];
}

// Applique un statut de lecture (et sa date le cas échéant) à TOUS les tomes d'une série.
// Ne touche pas aux tags collector / dernier tome ni aux numéros.
//
// Écriture ciblée : seuls les tomes de la série concernée sont réécrits en
// base, via replace_series_volumes(). Cette fonction ne touche jamais au
// statut de la série elle-même (contrairement à update_volume()), donc pas
// d'upsert_series_row() nécessaire ici.
function apply_status_to_all_volumes($data, $series_id, $status, $read_at = null) {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['success' => false, 'message' => "Série introuvable."];
    }
    $idx = $series['index'];
    if (empty($data[$idx]['volumes'])) {
        return ['success' => true, 'data' => $data];
    }

    foreach ($data[$idx]['volumes'] as $vi => $volume) {
        $previous_read_at = $volume['read_at'] ?? '';
        if ($status === 'terminé') {
            if ($read_at !== null && $read_at !== '') {
                // Une date explicite a été fournie : on l'applique à tous les tomes.
                $new_read_at = $read_at;
            } elseif ($previous_read_at !== '') {
                // On conserve la date déjà connue du tome.
                $new_read_at = $previous_read_at;
            } else {
                $new_read_at = date('Y-m-d');
            }
        } else {
            $new_read_at = '';
        }

        $data[$idx]['volumes'][$vi]['status']  = $status;
        $data[$idx]['volumes'][$vi]['read_at'] = $new_read_at;
        if (!isset($data[$idx]['volumes'][$vi]['added_at'])) {
            $data[$idx]['volumes'][$vi]['added_at'] = date('Y-m-d');
        }
    }

    replace_series_volumes($series_id, $data[$idx]['volumes']);

    return ['success' => true, 'data' => $data];
}

// Supprimer un tome
//
// Écriture ciblée : seuls les tomes de la série concernée sont réécrits en
// base, via replace_series_volumes(). Aucune autre série n'est lue ni
// réécrite.
function delete_volume($data, $series_id, $volume_index) {
    $series = find_series_by_id($data, $series_id);
    if (!$series || !isset($data[$series['index']]['volumes'][$volume_index])) {
        return ['success' => false, 'message' => "Série ou volume introuvable."];
    }

    array_splice($data[$series['index']]['volumes'], $volume_index, 1);
    replace_series_volumes($series_id, $data[$series['index']]['volumes']);
    return ['success' => true, 'data' => $data];
}
