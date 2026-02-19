<?php
// Charger les séries non lues
function get_unread_series($data) {
    $unread_series = [];
    foreach ($data as $series) {
        $has_unread = false;
        $last_read_volume = null;
        $unread_count = 0;
        $total_volumes = count($series['volumes']);

        foreach ($series['volumes'] as $volume) {
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
            ];
        }
    }
    return $unread_series;
}
?>