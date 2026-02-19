<?php
// Charger les séries non lues
function get_unread_series($data) {
    $unread_series = [];
    foreach ($data as $series) {
        $has_unread = false;
        $last_read_volume = null;
        $unread_count = 0;
        $total_volumes = count($series['volumes']);
        $has_last_volume = false;

        foreach ($series['volumes'] as $volume) {
            if (isset($volume['last']) && $volume['last']) {
                $has_last_volume = true;
            }
            if ($volume['status'] !== 'terminé') {
                $has_unread = true;
                $unread_count++;
            } else {
                $last_read_volume = $volume['number'];
            }
        }

        if ($has_unread) {
            $unread_series[] = [
                'id' => $series['id'],
                'name' => $series['name'],
                'author' => $series['author'],
                'publisher' => $series['publisher'],
                'last_read_volume' => ($last_read_volume !== null) ? $last_read_volume : 'aucun',
                'unread_count' => $unread_count,
                'total_volumes' => $total_volumes,
                'soon_finished' => is_series_soon_finished($series),
                'status' => $has_last_volume ? 'terminée' : 'en cours',
            ];
        }
    }
    return $unread_series;
}

// Marquer le premier tome non-lu d'une série comme lu
function mark_first_unread_volume_as_read($data, $series_id) {
    foreach ($data as &$series) {
        if ($series['id'] === $series_id) {
            foreach ($series['volumes'] as &$volume) {
                if ($volume['status'] !== 'terminé') {
                    $volume['status'] = 'terminé';
                    return ['success' => true, 'data' => $data];
                }
            }
            return ['success' => false, 'message' => 'Aucun tome non-lu trouvé.'];
        }
    }
    return ['success' => false, 'message' => 'Série non trouvée.'];
}

// Vérifier si une série est bientôt terminée
function is_series_soon_finished($series) {
    $last_volume = null;
    $unread_count = 0;
    $has_last_volume = false;

    foreach ($series['volumes'] as $volume) {
        if (isset($volume['last']) && $volume['last']) {
            $has_last_volume = true;
        }
        if ($volume['status'] !== 'terminé') {
            $unread_count++;
        }
    }

    return $has_last_volume && $unread_count <= 2;
}
?>