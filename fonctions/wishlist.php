<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/wishlist.php — Liste d'envies, typée
//
// Une entrée peut être :
//   • un manga (type = 'manga') : saisie libre, comportement identique aux
//     versions antérieures — nom, auteur, éditeur ;
//   • un animé (type = 'anime') : `studio` remplace `author` (pas d'éditeur),
//     et un `anilist_id` est mémorisé dès que l'utilisateur a choisi une fiche
//     Anilist (recherche par titre ou par identifiant). Ce champ est ce qui
//     permet, au passage en collection, un import immédiat sans nouvelle
//     recherche : cf. add_from_wishlist().
//
// La vignette Anilist n'est JAMAIS téléchargée au stade de la liste d'envies
// (affichage purement textuel) : seul le passage en collection déclenche le
// téléchargement, via add_anime_series().
// ────────────────────────────────────────────────────────────────────────────

// Charger la liste d'envies
function load_wishlist(): array {
    $db   = get_db();
    $rows = $db->query("SELECT * FROM wishlist ORDER BY id")->fetchAll();
    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'name'       => $r['name'],
            // Repli 'manga' : couvre les entrées créées avant le typage de la
            // liste d'envies.
            'type'       => (isset($r['type']) && trim($r['type']) !== '') ? $r['type'] : 'manga',
            'author'     => $r['author'],
            'publisher'  => $r['publisher'],
            'studio'     => $r['studio'] ?? '',
            'anilist_id' => $r['anilist_id'] ?? '',
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
        $stmt = $db->prepare("
            INSERT INTO wishlist (name, type, author, publisher, studio, anilist_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($wishlist as $item) {
            $stmt->execute([
                $item['name'],
                sanitize_series_type($item['type'] ?? ''),
                $item['author']     ?? '',
                $item['publisher']  ?? '',
                $item['studio']     ?? '',
                $item['anilist_id'] ?? '',
            ]);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// Sous-ensemble de la wishlist pour un type donné (affichage / filtre
// uniquement — même mise en garde que series_of_type() : ne jamais repasser
// ce tableau à save_wishlist(), qui écrit ce qu'on lui donne comme la
// collection complète).
function wishlist_of_type(array $wishlist, $type): array {
    $type = sanitize_series_type($type);
    return array_values(array_filter($wishlist, function ($item) use ($type) {
        return sanitize_series_type($item['type'] ?? '') === $type;
    }));
}

// Ajouter une entrée à la liste d'envies.
//
// $type = 'manga' : $author et $publisher sont utilisés tels quels, comme
// pour toute entrée manga — aucune recherche Anilist n'entre en jeu.
// $type = 'anime' : $studio remplace $author (pas d'éditeur), et
// $anilist_id doit provenir d'une fiche Anilist choisie (recherche par titre
// ou par identifiant) — jamais saisi librement.
//
// Anti-doublon : par anilist_id si l'entrée en a un (un animé ne se
// dédoublonne pas sur un nom, qui peut varier d'une fiche à l'autre), sinon
// par nom (insensible à la casse), comme auparavant.
function add_to_wishlist(
    array $wishlist,
    string $name,
    string $author,
    string $publisher,
    string $type = 'manga',
    string $studio = '',
    string $anilist_id = ''
): array {
    $type = sanitize_series_type($type);
    $anilist_id = ($type === 'anime') ? trim($anilist_id) : '';

    foreach ($wishlist as $item) {
        if ($anilist_id !== '' && (int)($item['anilist_id'] ?? 0) === (int)$anilist_id) {
            return ['success' => false, 'message' => "Cette série est déjà dans la liste d'envies."];
        }
        // Comparaison par nom : un manga et un animé homonymes ne sont pas un
        // doublon (ex. « One Piece » côté manga ET côté animé) — on exige donc
        // aussi le même type.
        if ($anilist_id === '' && strcasecmp($item['name'], $name) === 0 && sanitize_series_type($item['type'] ?? '') === $type) {
            return ['success' => false, 'message' => "La série est déjà présente dans la liste d'envies."];
        }
    }

    if ($type === 'anime') {
        if ($name === '' || $anilist_id === '') {
            return ['success' => false, 'message' => "Sélectionnez une série animée via la recherche Anilist."];
        }
    } else {
        if ($name === '' || $author === '' || $publisher === '') {
            return ['success' => false, 'message' => "Veuillez remplir le nom, l'auteur et l'éditeur."];
        }
    }

    $db = get_db();
    $db->prepare("
        INSERT INTO wishlist (name, type, author, publisher, studio, anilist_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $name,
        $type,
        $type === 'manga' ? $author : '',
        $type === 'manga' ? $publisher : '',
        $type === 'anime' ? $studio : '',
        $anilist_id,
    ]);

    $wishlist[] = [
        'name'       => $name,
        'type'       => $type,
        'author'     => $type === 'manga' ? $author : '',
        'publisher'  => $type === 'manga' ? $publisher : '',
        'studio'     => $type === 'anime' ? $studio : '',
        'anilist_id' => $anilist_id,
    ];
    return ['success' => true, 'wishlist' => $wishlist];
}

// Éditer une entrée de la liste d'envies.
//
// Le type d'une entrée ne se change pas ici : une entrée animée ne devient
// jamais un manga (et inversement) par simple édition. Pour un animé, seul
// le studio est éditable ; le nom et l'anilist_id restent ceux de la fiche
// Anilist choisie à l'ajout (Anilist fait autorité, y compris en wishlist).
function edit_wishlist_item(array $wishlist, int $index, string $name, string $author, string $publisher, string $studio = ''): array {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    // Récupérer l'id réel depuis la BDD (la liste est ordonnée par id)
    $db  = get_db();
    $ids = $db->query("SELECT id FROM wishlist ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

    if (!isset($ids[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $type = sanitize_series_type($wishlist[$index]['type'] ?? '');

    if ($type === 'anime') {
        // Titre et anilist_id figés : seul le studio se corrige à la main
        // (un studio additionnel oublié par Anilist, par exemple).
        $db->prepare("UPDATE wishlist SET studio = ? WHERE id = ?")
           ->execute([$studio, $ids[$index]]);
        $wishlist[$index]['studio'] = $studio;
    } else {
        $db->prepare("UPDATE wishlist SET name = ?, author = ?, publisher = ? WHERE id = ?")
           ->execute([$name, $author, $publisher, $ids[$index]]);
        $wishlist[$index]['name']      = $name;
        $wishlist[$index]['author']    = $author;
        $wishlist[$index]['publisher'] = $publisher;
    }

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

// Ajouter une entrée de la liste d'envies à la collection principale.
//
// Manga : comportement strictement inchangé depuis les versions
// antérieures — insertion directe en base, un tome, statut « en cours ».
//
// Animé : délègue à add_anime_from_wishlist(), qui importe intégralement la
// fiche depuis Anilist via l'anilist_id mémorisé.
//
// Retour : ['success', 'data'?, 'wishlist'?, 'message']
function add_from_wishlist(array $data, array $wishlist, int $index): array {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $item = $wishlist[$index];
    $type = sanitize_series_type($item['type'] ?? '');

    if ($type === 'anime') {
        return add_anime_from_wishlist($data, $wishlist, $index);
    }

    $name      = $item['name'];
    $author    = $item['author'];
    $publisher = $item['publisher'];

    // Un animé homonyme (ou l'inverse) n'est pas un doublon : ce sont deux
    // œuvres différentes qui partagent un titre. Cette branche ne traite que
    // le type 'manga' (voir plus haut), la comparaison se limite donc aux
    // séries mangas déjà en collection.
    $series_exists = false;
    foreach ($data as $existing_series) {
        if (strcasecmp($existing_series['name'], $name) === 0 && series_type($existing_series) === 'manga') {
            $series_exists = true;
            break;
        }
    }

    if ($series_exists) {
        return ['success' => false, 'message' => 'Une série avec ce nom existe déjà dans votre collection.'];
    }

    $new_id = generate_uuid();
    $db     = get_db();
    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO series (id, name, type, author, publisher, categories, image, status)
            VALUES (?, ?, 'manga', ?, ?, '', '', 'en cours')
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
        'type'               => 'manga',
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
}

// Passage en collection d'une entrée animée : import complet depuis Anilist,
// sur le même moteur que l'ajout direct (fonctions/anime.php::add_anime_series()).
//
// Anilist fait autorité même ici : seul l'anilist_id mémorisé transite, la
// fiche est intégralement rechargée (le studio ou le titre affichés en
// wishlist ne servaient qu'à cet affichage-là, jamais réinjectés tels quels).
//
// Ne retire l'entrée de la wishlist qu'en cas de succès de l'import : un échec
// (série non encore diffusée entre-temps, Anilist injoignable, doublon
// détecté...) laisse l'entrée intacte plutôt que de la faire disparaître pour
// rien.
//
// Dépend de includes/anilist.php (anilist_fetch_media) et
// fonctions/anime.php (add_anime_series) : ces deux fichiers doivent être
// chargés par l'appelant.
function add_anime_from_wishlist(array $data, array $wishlist, int $index): array {
    if (!isset($wishlist[$index])) {
        return ['success' => false, 'message' => 'Index invalide.'];
    }

    $item       = $wishlist[$index];
    $anilist_id = (int)($item['anilist_id'] ?? 0);
    if ($anilist_id <= 0) {
        return ['success' => false, 'message' => "Aucun identifiant Anilist mémorisé pour cette entrée."];
    }

    if (!function_exists('anilist_fetch_media') || !function_exists('add_anime_series')) {
        return ['success' => false, 'message' => "Le connecteur Anilist n'est pas disponible."];
    }

    $fetch = anilist_fetch_media($anilist_id);
    if (!$fetch['ok']) {
        return ['success' => false, 'message' => $fetch['error']];
    }

    $result = add_anime_series($data, $fetch['media']);
    if (!$result['success']) {
        return ['success' => false, 'message' => $result['message']];
    }

    $remove = remove_from_wishlist($wishlist, $index);
    return [
        'success'   => true,
        'data'      => $result['data'],
        'wishlist'  => $remove['success'] ? $remove['wishlist'] : $wishlist,
        'message'   => $result['message'],
        // Propagé jusqu'à page-wishlist.php pour la redirection vers
        // admin.php avec la modale d'édition de cette série déjà ouverte.
        'series_id' => $result['series_id'] ?? '',
    ];
}
