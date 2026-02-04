<?php
// Fonction pour générer un UUID unique
function generate_uuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Fonction pour vérifier si une image existe
function check_image_exists($image_path) {
    return !empty($image_path) && file_exists($image_path);
}

// Fonction pour obtenir les valeurs uniques d'un champ spécifique
function get_unique_values($data, $field) {
    $values = [];
    foreach ($data as $series) {
        if (isset($series[$field])) {
            if (is_array($series[$field])) {
                foreach ($series[$field] as $value) {
                    $value = trim($value);
                    if (!empty($value) && !in_array($value, $values, true)) {
                        $values[] = $value;
                    }
                }
            } else {
                $value = trim($series[$field]);
                if (!empty($value) && !in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
        }
    }
    return $values;
}

// Fonction pour trier les séries
function sort_series(&$data, $sort_by, $sort_order) {
    usort($data, function($a, $b) use ($sort_by, $sort_order) {
        if ($sort_by === 'added_at') {
            $a_val = end($a['volumes'])['added_at'] ?? '0000-00-00';
            $b_val = end($b['volumes'])['added_at'] ?? '0000-00-00';
            return $sort_order === 'asc' ? strcmp($a_val, $b_val) : strcmp($b_val, $a_val);
        }
        if ($sort_by === 'volumes') {
            return $sort_order === 'asc'
                ? count($a['volumes']) - count($b['volumes'])
                : count($b['volumes']) - count($a['volumes']);
        } elseif ($sort_by === 'categories') {
            $a_categories = implode(', ', $a['categories'] ?? []);
            $b_categories = implode(', ', $b['categories'] ?? []);
            return $sort_order === 'asc'
                ? strcasecmp($a_categories, $b_categories)
                : strcasecmp($b_categories, $a_categories);
        } else {
            return $sort_order === 'asc'
                ? strcasecmp($a[$sort_by], $b[$sort_by])
                : strcasecmp($b[$sort_by], $a[$sort_by]);
        }
    });
}

// Fonction pour trier les tomes par numéro
function sort_volumes(&$volumes) {
    usort($volumes, function($a, $b) {
        return $a['number'] - $b['number'];
    });
}

// Fonction pour trouver une série par son ID
function find_series_by_id($data, $series_id) {
    foreach ($data as $index => $series) {
        if (isset($series['id']) && $series['id'] === $series_id) {
            return ['index' => $index, 'series' => $series];
        }
    }
    return null;
}

// Fonction pour normaliser une chaîne de caractères pour la recherche (insensible aux accents et à la casse).
function normalize_string($string) {
    // Remplace les caractères accentués par leurs équivalents non accentués
    $table = [
        'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'AE',
        'Ç'=>'C', 'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I',
        'Î'=>'I', 'Ï'=>'I', 'Ð'=>'D', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O',
        'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U',
        'Ý'=>'Y', 'ß'=>'s', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a',
        'å'=>'a', 'æ'=>'ae', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e',
        'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'d', 'ñ'=>'n', 'ò'=>'o',
        'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u',
        'û'=>'u', 'ü'=>'u', 'ý'=>'y', 'ÿ'=>'y', 'Ŕ'=>'R', 'ŕ'=>'r',
    ];
    $string = strtr($string, $table);
    // Convertit en minuscules et supprime les caractères non alphanumériques
    $string = mb_strtolower($string, 'UTF-8');
    $string = preg_replace('/[^a-z0-9]/', '', $string);
    return $string;
}