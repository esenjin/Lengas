<?php
// Charger la liste d'envies
function load_wishlist(): array {
    $db   = get_db();
    $rows = $db->query("SELECT * FROM wishlist ORDER BY id")->fetchAll();
    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'name'      => $r['name'],
            'author'    => $r['author'],
            'publisher' => $r['publisher'],
        ];
    }
    return $result;
}

// Sauvegarder la liste d'envies (remplacement complet)
function save_wishlist(array $wishlist): void {
    $db = get_db();
    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM wishlist");
        $stmt = $db->prepare("INSERT INTO wishlist (name, author, publisher) VALUES (?, ?, ?)");
        foreach ($wishlist as $item) {
            $stmt->execute([$item['name'], $item['author'] ?? '', $item['publisher'] ?? '']);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// Ajouter une série à la liste d'envies
function add_to_wishlist(array $wishlist, string $name, string $author, string $publisher): array {
    $series_exists = false;
    foreach ($wishlist as $item) {
        if (strcasecmp($item['name'], $name) === 0) {
            $series_exists = true;
            break;
        }
    }

    if (!$series_exists && $name && $author && $publisher) {
        $db = get_db();
        $db->prepare("INSERT INTO wishlist (name, author, publisher) VALUES (?, ?, ?)")
           ->execute([$name, $author, $publisher]);
        $wishlist[] = ['name' => $name, 'author' => $author, 'publisher' => $publisher];
        return ['success' => true, 'wishlist' => $wishlist];
    } else {
        return ['success' => false, 'message' => "La série est déjà présente dans la liste d'envies."];
    }
}

// Éditer une série dans la liste d'envies
function edit_wishlist_item(array $wishlist, int $index, string $name, string $author, string $publisher): array {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    // Récupérer l'id réel depuis la BDD (la liste est ordonnée par id)
    $db  = get_db();
    $ids = $db->query("SELECT id FROM wishlist ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

    if (!isset($ids[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $db->prepare("UPDATE wishlist SET name = ?, author = ?, publisher = ? WHERE id = ?")
       ->execute([$name, $author, $publisher, $ids[$index]]);

    $wishlist[$index] = ['name' => $name, 'author' => $author, 'publisher' => $publisher];
    return ['success' => true, 'wishlist' => $wishlist];
}

// Supprimer une série de la liste d'envies
function remove_from_wishlist(array $wishlist, int $index): array {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $db  = get_db();
    $ids = $db->query("SELECT id FROM wishlist ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

    if (!isset($ids[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $db->prepare("DELETE FROM wishlist WHERE id = ?")->execute([$ids[$index]]);
    array_splice($wishlist, $index, 1);
    return ['success' => true, 'wishlist' => $wishlist];
}

// Ajouter une série à la collection principale depuis la liste d'envies
function add_from_wishlist(array $data, array $wishlist, int $index): array {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $series    = $wishlist[$index];
    $name      = $series['name'];
    $author    = $series['author'];
    $publisher = $series['publisher'];

    $series_exists = false;
    foreach ($data as $existing_series) {
        if (strcasecmp($existing_series['name'], $name) === 0) {
            $series_exists = true;
            break;
        }
    }

    if (!$series_exists) {
        $new_id = generate_uuid();
        $db     = get_db();
        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO series (id, name, author, publisher, categories, image, status)
                VALUES (?, ?, ?, ?, '', '', 'en cours')
            ")->execute([$new_id, $name, $author, $publisher]);

            $db->prepare("
                INSERT INTO volumes (series_id, number, status, collector, last, added_at)
                VALUES (?, 1, 'à lire', 0, 0, ?)
            ")->execute([$new_id, date('Y-m-d')]);

            // Supprimer de la wishlist
            $ids = $db->query("SELECT id FROM wishlist ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
            if (isset($ids[$index])) {
                $db->prepare("DELETE FROM wishlist WHERE id = ?")->execute([$ids[$index]]);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

        // Mettre à jour les tableaux PHP
        $data[] = [
            'id'                 => $new_id,
            'name'               => $name,
            'author'             => $author,
            'publisher'          => $publisher,
            'other_contributors' => [''],
            'categories'         => [''],
            'genres'             => [''],
            'image'              => '',
            'anilist_id'         => '',
            'mature'             => false,
            'favorite'           => false,
            'status'             => 'en cours',
            'volumes'            => [['number' => 1, 'status' => 'à lire', 'collector' => false, 'last' => false, 'added_at' => date('Y-m-d')]],
        ];
        array_splice($wishlist, $index, 1);
        return ['success' => true, 'data' => $data, 'wishlist' => $wishlist];
    } else {
        return ['success' => false, 'message' => 'Une série avec ce nom existe déjà dans votre collection.'];
    }
}
