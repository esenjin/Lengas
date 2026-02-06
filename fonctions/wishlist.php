<?php
// Charger la liste d'envies
function load_wishlist() {
    if (file_exists(WISHLIST_FILE)) {
        $wishlist = json_decode(file_get_contents(WISHLIST_FILE), true);
        return $wishlist ?: [];
    }
    return [];
}

// Sauvegarder la liste d'envies
function save_wishlist($wishlist) {
    file_put_contents(WISHLIST_FILE, json_encode($wishlist, JSON_PRETTY_PRINT));
}

// Ajouter une série à la liste d'envies
function add_to_wishlist($wishlist, $name, $author, $publisher) {
    $series_exists = false;
    foreach ($wishlist as $item) {
        if (strcasecmp($item['name'], $name) === 0) {
            $series_exists = true;
            break;
        }
    }

    if (!$series_exists && $name && $author && $publisher) {
        $wishlist[] = [
            'name' => $name,
            'author' => $author,
            'publisher' => $publisher
        ];
        return ['success' => true, 'wishlist' => $wishlist];
    } else {
        return ['success' => false, 'message' => 'La série est déjà présente dans la liste d\'envies.'];
    }
}

// Éditer une série dans la liste d'envies
function edit_wishlist_item($wishlist, $index, $name, $author, $publisher) {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $wishlist[$index] = [
        'name' => $name,
        'author' => $author,
        'publisher' => $publisher
    ];

    return ['success' => true, 'wishlist' => $wishlist];
}

// Supprimer une série de la liste d'envies
function remove_from_wishlist($wishlist, $index) {
    if (isset($wishlist[$index])) {
        array_splice($wishlist, $index, 1);
        return ['success' => true, 'wishlist' => $wishlist];
    } else {
        return ['success' => false, 'message' => 'Index invalide.'];
    }
}

// Ajouter une série à la collection principale depuis la liste d'envies
function add_from_wishlist($data, $wishlist, $index) {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $series = $wishlist[$index];
    $name = $series['name'];
    $author = $series['author'];
    $publisher = $series['publisher'];

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
            'categories' => [''], // Catégorie par défaut, à modifier par l'utilisateur
            'image' => '', // Image par défaut, à modifier par l'utilisateur
            'volumes' => [
                [
                    'number' => 1,
                    'status' => 'à lire',
                    'collector' => false,
                    'last' => false
                ]
            ]
        ];

        // Supprimer la série de la liste d'envies
        array_splice($wishlist, $index, 1);
        return ['success' => true, 'data' => $data, 'wishlist' => $wishlist];
    } else {
        return ['success' => false, 'message' => 'Une série avec ce nom existe déjà dans votre collection.'];
    }
}