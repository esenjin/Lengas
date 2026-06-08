<?php
// Charger les séries "lues ailleurs"
function load_read(): array {
    $db   = get_db();
    $rows = $db->query("SELECT * FROM read_elsewhere ORDER BY id")->fetchAll();
    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'name'         => $r['name'],
            'author'       => $r['author'],
            'publisher'    => $r['publisher'],
            'volumes_read' => (int)$r['volumes_read'],
            'status'       => $r['status'],
            'added_at'     => $r['added_at'],
        ];
    }
    return $result;
}

// Sauvegarder les séries "lues ailleurs" (remplacement complet)
function save_read(array $read): void {
    $db = get_db();
    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM read_elsewhere");
        $stmt = $db->prepare("
            INSERT INTO read_elsewhere (name, author, publisher, volumes_read, status, added_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($read as $r) {
            $stmt->execute([
                $r['name'],
                $r['author']       ?? '',
                $r['publisher']    ?? '',
                (int)($r['volumes_read'] ?? 0),
                $r['status']       ?? '',
                $r['added_at']     ?? date('Y-m-d'),
            ]);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// Ajouter une série à "lues ailleurs"
function add_to_read(array $read, string $name, string $author, string $publisher, int $volumes_read, string $status): array {
    $series_exists = false;
    foreach ($read as $item) {
        if (strcasecmp($item['name'], $name) === 0) {
            $series_exists = true;
            break;
        }
    }

    if (!$series_exists && $name && $author && $publisher && $volumes_read > 0) {
        $added_at = date('Y-m-d');
        $db = get_db();
        $db->prepare("
            INSERT INTO read_elsewhere (name, author, publisher, volumes_read, status, added_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$name, $author, $publisher, $volumes_read, $status, $added_at]);

        $read[] = [
            'name'         => $name,
            'author'       => $author,
            'publisher'    => $publisher,
            'volumes_read' => $volumes_read,
            'status'       => $status,
            'added_at'     => $added_at,
        ];
        return ['success' => true, 'read' => $read];
    } else {
        return ['success' => false, 'message' => 'La série est déjà présente ou les données sont incomplètes.'];
    }
}

// Éditer une série dans "lues ailleurs"
function edit_read_item(array $read, int $index, string $name, string $author, string $publisher, int $volumes_read, string $status): array {
    if (!isset($read[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $db  = get_db();
    $ids = $db->query("SELECT id FROM read_elsewhere ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

    if (!isset($ids[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $db->prepare("
        UPDATE read_elsewhere
        SET name = ?, author = ?, publisher = ?, volumes_read = ?, status = ?
        WHERE id = ?
    ")->execute([$name, $author, $publisher, $volumes_read, $status, $ids[$index]]);

    $read[$index] = [
        'name'         => $name,
        'author'       => $author,
        'publisher'    => $publisher,
        'volumes_read' => $volumes_read,
        'status'       => $status,
        'added_at'     => $read[$index]['added_at'],
    ];
    return ['success' => true, 'read' => $read];
}

// Supprimer une série de "lues ailleurs"
function remove_from_read(array $read, int $index): array {
    if (!isset($read[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $db  = get_db();
    $ids = $db->query("SELECT id FROM read_elsewhere ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

    if (!isset($ids[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $db->prepare("DELETE FROM read_elsewhere WHERE id = ?")->execute([$ids[$index]]);
    array_splice($read, $index, 1);
    return ['success' => true, 'read' => $read];
}

// Ajouter une série de "lues ailleurs" à la collection principale
function add_from_read(array $data, array $read, int $index): array {
    if (!isset($read[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $series       = $read[$index];
    $name         = $series['name'];
    $author       = $series['author'];
    $publisher    = $series['publisher'];
    $volumes_read = (int)$series['volumes_read'];
    $status       = $series['status'];

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

            $volStmt = $db->prepare("
                INSERT INTO volumes (series_id, number, status, collector, last, added_at)
                VALUES (?, ?, ?, 0, ?, ?)
            ");
            for ($i = 1; $i <= $volumes_read; $i++) {
                $is_last = (trim(mb_strtolower($status)) === 'terminé' && $i === $volumes_read) ? 1 : 0;
                $volStmt->execute([$new_id, $i, $status, $is_last, $series['added_at'] ?? date('Y-m-d')]);
            }

            // Supprimer de lues ailleurs
            $ids = $db->query("SELECT id FROM read_elsewhere ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
            if (isset($ids[$index])) {
                $db->prepare("DELETE FROM read_elsewhere WHERE id = ?")->execute([$ids[$index]]);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

        // Mettre à jour les tableaux PHP
        $vols = [];
        for ($i = 1; $i <= $volumes_read; $i++) {
            $is_last = (trim(mb_strtolower($status)) === 'terminé' && $i === $volumes_read);
            $vols[] = ['number' => $i, 'status' => $status, 'collector' => false, 'last' => $is_last, 'added_at' => $series['added_at'] ?? date('Y-m-d')];
        }
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
            'volumes'            => $vols,
        ];
        array_splice($read, $index, 1);
        return ['success' => true, 'data' => $data, 'read' => $read];
    } else {
        return ['success' => false, 'message' => 'Une série avec ce nom existe déjà dans votre collection.'];
    }
}

// Déplacer une série de la bibliothèque vers "lues ailleurs"
function move_series_to_read(array $data, array $read, string $series_id): array {
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

    $series       = $data[$series_index];
    $volumes_read = count($series['volumes']);
    $status       = 'terminé';
    foreach ($series['volumes'] as $volume) {
        if ($volume['status'] !== 'terminé') {
            $status = 'en cours';
            break;
        }
    }

    $added_at = date('Y-m-d');
    $db       = get_db();
    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO read_elsewhere (name, author, publisher, volumes_read, status, added_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$series['name'], $series['author'], $series['publisher'], $volumes_read, $status, $added_at]);

        // Supprimer la série (CASCADE supprime aussi les volumes)
        $db->prepare("DELETE FROM series WHERE id = ?")->execute([$series_id]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    $read[] = [
        'name'         => $series['name'],
        'author'       => $series['author'],
        'publisher'    => $series['publisher'],
        'volumes_read' => $volumes_read,
        'status'       => $status,
        'added_at'     => $added_at,
    ];
    array_splice($data, $series_index, 1);

    return ['success' => true, 'data' => $data, 'read' => $read];
}
