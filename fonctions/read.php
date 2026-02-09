<?php
// Charger les séries "lues ailleurs"
function load_read() {
    if (file_exists(READ_FILE)) {
        $content = file_get_contents(READ_FILE);
        if ($content === false) {
            return [];
        }
        $read = json_decode($content, true);
        return is_array($read) ? $read : [];
    }
    return [];
}

// Sauvegarder les séries "lues ailleurs"
function save_read($read) {
    file_put_contents(READ_FILE, json_encode($read, JSON_PRETTY_PRINT));
}

// Ajouter une série à "lues ailleurs"
function add_to_read($read, $name, $author, $publisher, $volumes_read, $status) {
    $series_exists = false;
    foreach ($read as $item) {
        if (strcasecmp($item['name'], $name) === 0) {
            $series_exists = true;
            break;
        }
    }

    if (!$series_exists && $name && $author && $publisher && $volumes_read > 0) {
        $read[] = [
            'name' => $name,
            'author' => $author,
            'publisher' => $publisher,
            'volumes_read' => $volumes_read,
            'status' => $status,
            'added_at' => date('Y-m-d')
        ];
        return ['success' => true, 'read' => $read];
    } else {
        return ['success' => false, 'message' => 'La série est déjà présente ou les données sont incomplètes.'];
    }
}

// Éditer une série dans "lues ailleurs"
function edit_read_item($read, $index, $name, $author, $publisher, $volumes_read, $status) {
    if (!isset($read[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $read[$index] = [
        'name' => $name,
        'author' => $author,
        'publisher' => $publisher,
        'volumes_read' => $volumes_read,
        'status' => $status,
        'added_at' => $read[$index]['added_at'] // Conserver la date d'ajout
    ];

    return ['success' => true, 'read' => $read];
}

// Supprimer une série de "lues ailleurs"
function remove_from_read($read, $index) {
    if (isset($read[$index])) {
        array_splice($read, $index, 1);
        return ['success' => true, 'read' => $read];
    } else {
        return ['success' => false, 'message' => 'Index invalide.'];
    }
}

// Ajouter une série de "lues ailleurs" à la collection principale
function add_from_read($data, $read, $index) {
    if (!isset($read[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $series = $read[$index];
    $name = $series['name'];
    $author = $series['author'];
    $publisher = $series['publisher'];
    $volumes_read = $series['volumes_read'];
    $status = $series['status'];

    // Vérifier si une série avec le même nom existe déjà dans la collection principale
    $series_exists = false;
    foreach ($data as $existing_series) {
        if (strcasecmp($existing_series['name'], $name) === 0) {
            $series_exists = true;
            break;
        }
    }

    if (!$series_exists) {
        // Ajouter la série à la collection principale
        $data[] = [
            'id' => generate_uuid(),
            'name' => $name,
            'author' => $author,
            'publisher' => $publisher,
            'categories' => [''], // Catégorie par défaut
            'image' => '', // Image par défaut
            'volumes' => [],
        ];

        // Ajouter les tomes lus
        for ($i = 1; $i <= $volumes_read; $i++) {
            $is_last = (trim(mb_strtolower($status)) === 'terminé' && $i === $volumes_read); // Seulement si "terminé"
            $data[count($data) - 1]['volumes'][] = [
                'number' => $i,
                'status' => $status,
                'collector' => false,
                'last' => $is_last,
                'added_at' => $series['added_at']
            ];
        }

        // Supprimer la série de "lues ailleurs"
        array_splice($read, $index, 1);
        return ['success' => true, 'data' => $data, 'read' => $read];
    } else {
        return ['success' => false, 'message' => 'Une série avec ce nom existe déjà dans votre collection.'];
    }
}

// Déplacer une série de la bibliothèque vers "lues ailleurs"
function move_series_to_read($data, $read, $series_id) {
    // Trouver la série dans la bibliothèque
    $series_index = null;
    foreach ($data as $index => $series) {
        if (isset($series['id']) && $series['id'] === $series_id) {
            $series_index = $index;
            break;
        }
    }

    if ($series_index === null) {
        return ['success' => false, 'message' => 'Série non trouvée.'];
    }

    $series = $data[$series_index];

    // Calculer le nombre de tomes lus (tous les tomes de la série)
    $volumes_read = count($series['volumes']);

    // Déterminer le statut (si tous les tomes sont "terminé", alors "terminé", sinon "en cours")
    $status = 'terminé';
    foreach ($series['volumes'] as $volume) {
        if ($volume['status'] !== 'terminé') {
            $status = 'en cours';
            break;
        }
    }

    // Ajouter la série à "Lues ailleurs"
    $read[] = [
        'name' => $series['name'],
        'author' => $series['author'],
        'publisher' => $series['publisher'],
        'volumes_read' => $volumes_read,
        'status' => $status,
        'added_at' => date('Y-m-d')
    ];

    // Supprimer la série de la bibliothèque
    array_splice($data, $series_index, 1);

    return ['success' => true, 'data' => $data, 'read' => $read];
}