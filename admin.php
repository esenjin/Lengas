<?php
require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require_once 'includes/status_filter.php';
require 'includes/mangaupdates.php';
require_once 'includes/babengas.php';
require_once 'includes/anilist.php';
require_once 'includes/syngas.php';
require 'fonctions/series.php';
require 'fonctions/anime.php';
require 'fonctions/volumes.php';
require 'fonctions/episodes.php';
require 'fonctions/loans.php';
require 'fonctions/read.php';
require 'fonctions/options.php';
require 'fonctions/tools.php';
require 'fonctions/reviews.php';
require 'includes/custom_icons.php';
require 'includes/themes.php';
require_once 'includes/opengraph.php';
require_once 'vestikan/vestikan.php';

// Rétro-compatibilité V4 : type 'manga' pour les séries héritées (une seule fois).
backfill_series_types();

$data = load_data();
$options = load_options();

// ── Type affiché (Mangathèque / Animethèque) ─────────────────────────────────
// $data reste la collection COMPLÈTE : c'est elle que reçoivent les handlers
// d'écriture, mais chaque handler n'écrit désormais en base que sur la ou les
// séries qu'il a réellement modifiées (upsert_series_row(),
// replace_series_volumes(), replace_series_editions(), delete_series_row()) —
// plus aucune resynchronisation globale de la collection. Le cloisonnement
// par type (Mangathèque / Animethèque) se fait en aval, sur des copies
// d'affichage dédiées ($filtered_data et consorts) : $data lui-même n'est
// jamais filtré avant d'être transmis à un handler.
$current_type = sanitize_series_type($_GET['type'] ?? '');

// Ensemble des IDs de séries possédant une critique (pour badges / filtre)
$review_series_ids = array_flip(get_review_series_ids());

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page_admin = 90;
$offset = ($page - 1) * $per_page_admin;

// Gestion des actions pour les séries
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_series'])) {
    $name = trim($_POST['name'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $publisher = trim($_POST['publisher'] ?? '');
    $other_contributors = trim($_POST['other_contributors'] ?? '');
    $categories = trim($_POST['categories'] ?? '');
    $genres = trim($_POST['genres'] ?? '');
    $mangaupdates_url = trim($_POST['mangaupdates_url'] ?? '');
    $babelio_url = trim($_POST['babelio_url'] ?? '');
    $mature = !empty($_POST['mature']);
    $favorite = !empty($_POST['favorite']);
    $volumes_count = (int)($_POST['volumes_count'] ?? 1);
    $volumes_status = $_POST['volumes_status'] ?? 'à lire';
    $all_collector = !empty($_POST['all_collector']);
    $last_volume = !empty($_POST['last_volume']);
    $status        = $_POST['series_status'] ?? 'en cours';
    $read_elsewhere = !empty($_POST['read_elsewhere']);
    $reading_abandoned = !empty($_POST['reading_abandoned']);
    $rating = sanitize_rating($_POST['rating'] ?? '');
    $reread_count = max(0, (int)($_POST['reread_count'] ?? 0));
    // Posé par la section « Recherche Syngas » de la modale d'ajout, quand une
    // correspondance a été validée avant la soumission du formulaire (voir
    // includes/syngas.php et l'endpoint syngas_validate).
    $syngas_uid = trim($_POST['syngas_uid'] ?? '');
    $syngas_volumes_count = trim($_POST['syngas_volumes_count'] ?? '');
    $syngas_volumes_count = ($syngas_volumes_count !== '') ? (int)$syngas_volumes_count : null;

    // Initialiser $image à null par défaut
    $image = null;
    $error_message = null;

    // Une vignette Syngas déjà téléchargée localement (validation avant
    // soumission du formulaire) sert de repli si aucun fichier n'est
    // explicitement téléversé par l'utilisateur.
    $syngas_thumbnail_path = trim($_POST['syngas_thumbnail_path'] ?? '');

    // Si une image est uploadée, essayer de la traiter
    if (!empty($_FILES['image']['name'])) {
        $image = upload_image($_FILES['image'], $error_message);
        if ($image === false) {
            $_SESSION['error_message'] = $error_message ?: "Erreur inconnue lors du téléversement de l'image.";
            // Ne pas bloquer l'ajout de la série si l'image échoue
        }
    } elseif ($syngas_thumbnail_path !== '' && is_file($syngas_thumbnail_path)) {
        $image = $syngas_thumbnail_path;
    }

    // Appeler add_series avec $image (qui peut être null)
    // Cette modale ne crée que des mangas et light-novels (cf. registre de types).
    //
    // Écriture ciblée : add_series() écrit directement en base
    // (upsert_series_row() + replace_series_volumes()) au moment de
    // l'appel. $result['data'] ne sert qu'à réafficher la collection à
    // jour côté admin.
    $result = add_series($data, $name, $author, $publisher, $other_contributors, $categories, $genres, $mangaupdates_url, $babelio_url, $mature, $favorite, $volumes_count, $volumes_status, $all_collector, $last_volume, $image, $status, $read_elsewhere, $reading_abandoned, $rating, 'manga', $reread_count, $syngas_uid);

    if ($result['success'] && $syngas_volumes_count !== null) {
        // Cache local pour coherence_reference_volumes() (section 6.4) : la
        // série vient d'être créée par add_series(), on complète sa ligne.
        $created = end($result['data']);
        if ($created !== false) {
            $created['syngas_volumes_count'] = $syngas_volumes_count;
            upsert_series_row($created);
        }
    }

    if ($result['success']) {
        // Réchauffer le cache MangaUpdates pour la nouvelle série
        if ($mangaupdates_url !== '') {
            $mu_id = mangaupdates_get_id_from_url($mangaupdates_url);
            if ($mu_id !== null) @mangaupdates_get_volumes($mu_id, true);
        }
        $_SESSION['success_message'] = $result['message'];
    } else {
        $_SESSION['error_message'] = $result['message'];
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// ── Fabrique une fiche allégée pour le front, à partir d'une fiche Anilist ──
// normalisée. Format PARTAGÉ par les deux endpoints de recherche (par titre
// et par identifiant), en admin comme depuis la page de liste d'envies.
function anilist_result_payload(array $media, array $known): array {
    return [
        'anilist_id'       => $media['anilist_id'],
        'title'            => $media['title'],
        'title_english'    => $media['title_english'],
        'title_native'     => $media['title_native'],
        'cover'            => $media['cover'],
        'year'             => $media['start_year'] ?: $media['season_year'],
        'format_label'     => $media['format_label'],
        'status_label'     => $media['status_label'],
        'episodes'         => $media['episodes'],
        'studios_text'     => $media['studios_text'],
        'is_adult'         => $media['is_adult'],
        'not_yet_released' => $media['not_yet_released'],
        'already_present'  => isset($known[$media['anilist_id']]),
        'present_as'       => $known[$media['anilist_id']] ?? '',
    ];
}

// Identifiants déjà présents dans la vidéothèque : les résultats de recherche
// grisent les fiches correspondantes plutôt que de laisser cliquer pour rien.
// Fabriqué une fois, réutilisé par les deux endpoints ci-dessous.
function anilist_known_ids(array $data): array {
    $known = [];
    foreach ($data as $series) {
        $aid = (int)($series['anilist_id'] ?? 0);
        if ($aid > 0) $known[$aid] = $series['name'];
    }
    return $known;
}

// ── Endpoint : recherche Anilist par titre (modale d'ajout d'une série
// animée, et panneau d'ajout animé de la liste d'envies). Renvoie au plus 10
// fiches normalisées. Aucune écriture : la sélection d'un résultat déclenche
// ensuite un POST add_anime_series (vidéothèque) ou add_to_wishlist (wishlist).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['anilist_search'])) {
    header('Content-Type: application/json');

    $term = trim($_GET['q'] ?? '');
    if ($term === '') {
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }

    $res = anilist_search($term, 10);
    if (!$res['ok']) {
        echo json_encode(['success' => false, 'message' => $res['error'], 'results' => []]);
        exit;
    }

    $known   = anilist_known_ids($data);
    $results = [];
    foreach ($res['results'] as $media) {
        $results[] = anilist_result_payload($media, $known);
    }

    echo json_encode(['success' => true, 'results' => $results]);
    exit;
}

// ── Endpoint : recherche Anilist par identifiant direct ─────────────────────
// Pour l'utilisateur qui connaît déjà l'anilist_id de la série visée, ou ne la
// retrouve pas par titre. Même format de sortie que la recherche par titre, un
// seul résultat au maximum, pour que le front réutilise exactement le même
// gabarit d'affichage et le même bouton « Ajouter ».
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['anilist_lookup'])) {
    header('Content-Type: application/json');

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => "Identifiant Anilist invalide.", 'results' => []]);
        exit;
    }

    $fetch = anilist_fetch_media($id);
    if (!$fetch['ok']) {
        echo json_encode(['success' => false, 'message' => $fetch['error'], 'results' => []]);
        exit;
    }

    $known = anilist_known_ids($data);
    echo json_encode(['success' => true, 'results' => [anilist_result_payload($fetch['media'], $known)]]);
    exit;
}

// ── Endpoint : recherche Syngas (section 4 du cahier des charges) ───────────
// Mangathèque uniquement, appelée depuis la section « Recherche Syngas » des
// modales d'ajout ET d'édition d'une série manga. Recherche EXPLICITE
// uniquement (bouton « Chercher »), jamais au fil de la frappe. Au plus 5
// résultats — la limite est déjà appliquée côté Syngas.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['syngas_search'])) {
    header('Content-Type: application/json');

    $term = trim($_GET['q'] ?? '');
    if ($term === '') {
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }

    // Libère le verrou de session avant l'appel réseau à Syngas : sans ça,
    // toute autre requête authentifiée (autre onglet, rafraîchissement du
    // menu latéral…) reste bloquée en attente tant que cette recherche n'a
    // pas répondu. Rien n'est plus écrit en session dans cette branche.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $res = syngas_search($term);
    if (!$res['ok']) {
        echo json_encode([
            'success' => false,
            'message' => $res['error'],
            'banned'  => syngas_is_banned(),
            'results' => [],
        ]);
        exit;
    }

    echo json_encode(['success' => true, 'results' => $res['results']]);
    exit;
}

// ── Endpoint : validation d'une correspondance Syngas ───────────────────────
// Au clic sur « Valider » d'un résultat de recherche (section 4) : les
// champs Syngas non vides remplacent intégralement les champs Lengas
// correspondants, syngas_uid est posé immédiatement. Fonctionne aussi bien
// depuis la modale d'ajout (série pas encore créée : $_POST['series_id']
// absent, on ne renvoie que les champs à pré-remplir côté client) que depuis
// la modale d'édition (série existante : écriture ciblée immédiate).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['syngas_validate'])) {
    header('Content-Type: application/json');

    $syngas_id = trim($_POST['syngas_id'] ?? '');
    if ($syngas_id === '') {
        echo json_encode(['success' => false, 'message' => "Identifiant Syngas manquant."]);
        exit;
    }

    // Même raison que syngas_search ci-dessus : libère le verrou de session
    // avant les appels réseau à Syngas (fiche + éventuel téléchargement de
    // vignette). L'écriture en base de la série (upsert_series_row) qui suit
    // n'a pas besoin de la session PHP.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $fetch = syngas_get_series($syngas_id);
    if (!$fetch['ok']) {
        echo json_encode([
            'success' => false,
            'message' => $fetch['error'],
            'banned'  => syngas_is_banned(),
        ]);
        exit;
    }

    $series_id = trim($_POST['series_id'] ?? '');
    $local_categories = [];
    $target_key = null;

    if ($series_id !== '') {
        $found = find_series_by_id($data, $series_id);
        if (!$found) {
            echo json_encode(['success' => false, 'message' => "Série introuvable."]);
            exit;
        }
        $local_categories = $found['data']['categories'] ?? [];
        $target_key = $found['key'];
    }

    $mapped = syngas_map_to_lengas_fields($fetch['series'], $local_categories);
    $fields = $mapped['fields'];

    // Vignette : téléchargée localement dès la validation (jamais différée,
    // contrairement à l'envoi) — voir la note vignette de la section 4.
    $thumbnail_path = '';
    if ($mapped['thumbnail_url'] !== '') {
        $dl = syngas_download_thumbnail($mapped['thumbnail_url']);
        if ($dl['ok']) {
            $thumbnail_path = $dl['path'];
        }
        // Échec de téléchargement : on ignore silencieusement ce champ,
        // même principe que Syngas lui-même à la fusion d'une soumission.
    }

    if ($target_key !== null) {
        // Série existante (modale d'édition) : écriture ciblée immédiate.
        foreach ($fields as $k => $v) {
            $data[$target_key][$k] = $v;
        }
        if ($thumbnail_path !== '') {
            $old_image = $data[$target_key]['image'] ?? '';
            if ($old_image !== '' && file_exists($old_image)) {
                @unlink($old_image);
            }
            $data[$target_key]['image'] = $thumbnail_path;
        }
        $data[$target_key]['syngas_uid'] = $syngas_id;
        // Cache local pour coherence_reference_volumes() (section 6.4) —
        // jamais écrit dans le nombre de tomes réel de la série.
        $data[$target_key]['syngas_volumes_count'] = $mapped['volumes_count'];

        upsert_series_row($data[$target_key]);

        if (!empty($fields['mangaupdates_url'])) {
            $mu_id = mangaupdates_get_id_from_url($fields['mangaupdates_url']);
            if ($mu_id !== null) @mangaupdates_get_volumes($mu_id, true);
        }

        $loaned_by_series = loans_by_series(load_loans());
        echo json_encode([
            'success' => true,
            'series'  => build_light_series($data[$target_key], $review_series_ids, $loaned_by_series[$series_id] ?? []),
        ]);
        exit;
    }

    // Pas encore de série (modale d'ajout) : on renvoie juste les champs
    // pré-remplis, la création se fera au clic sur « Ajouter » comme
    // d'habitude — syngas_uid transite alors en champ caché du formulaire.
    echo json_encode([
        'success'        => true,
        'fields'         => $fields,
        'syngas_uid'     => $syngas_id,
        'thumbnail_path' => $thumbnail_path,
        'volumes_count'  => $mapped['volumes_count'],
    ]);
    exit;
}

// ── Ajout d'une série animée (import complet depuis Anilist) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_anime_series'])) {
    header('Content-Type: application/json');

    $anilist_id = (int)($_POST['anilist_id'] ?? 0);
    if ($anilist_id <= 0) {
        echo json_encode(['success' => false, 'message' => "Aucune série sélectionnée."]);
        exit;
    }

    // On ne fait aucune confiance aux données envoyées par le navigateur : la
    // fiche est rechargée depuis Anilist (ou son cache), seul l'identifiant
    // transite. Anilist fait autorité, y compris face au front du site.
    $fetch = anilist_fetch_media($anilist_id);
    if (!$fetch['ok']) {
        echo json_encode(['success' => false, 'message' => $fetch['error']]);
        exit;
    }

    $result = add_anime_series($data, $fetch['media']);
    if ($result['success']) {
        // Écriture ciblée : add_anime_series() upserte déjà la série
        // (+ ses épisodes) en base au moment de l'appel. $result['data']
        // ne sert plus qu'à l'affichage.
        $_SESSION['success_message'] = $result['message'];
    }

    echo json_encode([
        'success' => $result['success'],
        'message' => $result['message'],
    ]);
    exit;
}

// ── Mise à jour d'une série animée (champs personnalisables uniquement) ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_anime_series'])) {
    $series_id = $_POST['series_id'] ?? '';

    // Requête AJAX (édition sans rechargement de page) : la réponse devient
    // du JSON contenant la carte "light" à jour, plutôt qu'une redirection.
    // $_POST['ajax'] est ajouté par edit-anime-form (anime.js).
    $is_ajax = !empty($_POST['ajax']);

    $new_image = null;
    if (!empty($_FILES['anime_image']['name'])) {
        $error_message = null;
        $new_image = upload_image($_FILES['anime_image'], $error_message);
        if ($new_image === false) {
            $message = $error_message ?: "Erreur inconnue lors du téléversement de l'image.";
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
            $_SESSION['error_message'] = $message;
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }

    $result = update_anime_series($data, $series_id, [
        'name'               => trim($_POST['anime_name'] ?? ''),
        'mature'             => !empty($_POST['anime_mature']),
        'favorite'           => !empty($_POST['anime_favorite']),
        'watching_abandoned' => !empty($_POST['anime_watching_abandoned']),
        'rating'             => $_POST['anime_rating'] ?? '',
        'rewatch_count'      => max(0, (int)($_POST['anime_rewatch_count'] ?? 0)),
        // Cocher « Éditions physiques » sans rien saisir revient à n'en déclarer
        // aucune : le tableau vide efface alors les commentaires existants.
        'editions'           => !empty($_POST['anime_has_editions'])
                                    ? ($_POST['anime_editions'] ?? [])
                                    : [],
        'new_image'          => $new_image,
        'remove_image'       => !empty($_POST['anime_remove_image']),
    ]);

    if ($is_ajax) {
        header('Content-Type: application/json');
        if (!$result['success']) {
            echo json_encode(['success' => false, 'message' => $result['message'] ?? "La mise à jour a échoué."]);
            exit;
        }
        $updated = find_series_by_id($result['data'], $series_id);
        if (!$updated) {
            echo json_encode(['success' => false, 'message' => "Série introuvable après mise à jour."]);
            exit;
        }
        $loaned_by_series = loans_by_series(load_loans());
        echo json_encode([
            'success' => true,
            // Avertissement éventuel (ex. champ ignoré) sans que ce soit un échec.
            'warning' => trim($result['message'] ?? '') !== '' ? $result['message'] : '',
            'series'  => build_light_series($updated['data'], $review_series_ids, $loaned_by_series[$series_id] ?? []),
        ]);
        exit;
    }

    if ($result['success']) {
        // Écriture ciblée : update_anime_series() upserte elle-même la
        // série (+ ses éditions si la clé est fournie) en base au moment de
        // l'appel.
        if (trim($result['message']) !== '') {
            $_SESSION['error_message'] = $result['message'];
        }
    } else {
        $_SESSION['error_message'] = $result['message'];
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// Gestion des actions pour les tomes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_multiple_volumes'])) {
    $series_id = $_POST['series_id'] ?? '';
    $volumes_count = (int)($_POST['volumes_count'] ?? 0);
    $status = $_POST['status'] ?? 'à lire';
    $is_collector = isset($_POST['is_collector']) ? (bool)$_POST['is_collector'] : false;
    $is_last = isset($_POST['is_last']) ? (bool)$_POST['is_last'] : false;

    // Les épisodes d'un animé viennent d'Anilist et de nulle part ailleurs :
    // ni ajout ni suppression manuels, où que ce soit dans le site.
    $target = find_series_by_id($data, $series_id);
    if ($target && is_anime($target['data'])) {
        $_SESSION['error_message'] = "Les épisodes d'une série animée sont gérés par Anilist : ils ne s'ajoutent pas à la main.";
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($volumes_count > 0) {
        // Écriture ciblée : add_multiple_volumes_to_series() écrit
        // directement en base (replace_series_volumes()) au moment de
        // l'appel. $result['data'] ne sert qu'à réafficher la collection à
        // jour côté admin.
        $result = add_multiple_volumes_to_series($data, $series_id, $volumes_count, $status, $is_collector, $is_last);
        if (!$result['success']) {
            $_SESSION['error_message'] = $result['message'];
        }
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// Mettre à jour un tome
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_volume'])) {
    $series_id = $_POST['series_id'] ?? '';
    $volume_index = (int)($_POST['volume_index'] ?? 0);

    // Requête AJAX (édition sans rechargement de page) : la réponse devient
    // du JSON contenant la carte "light" à jour, plutôt qu'une redirection.
    // $_POST['ajax'] est ajouté par edit-volume-modal (volumes.js) ; son
    // absence préserve le comportement historique pour tout appelant non-JS.
    $is_ajax = !empty($_POST['ajax']);

    // Un épisode ne passe jamais par ici : update_volume() sait cocher
    // « collector » et « dernier tome », et fait basculer le statut de la série
    // au passage — trois choses qu'Anilist seul décide pour un animé. Le front
    // n'envoie jamais ce formulaire pour une série animée ; le garde-fou vaut
    // pour un POST forgé.
    $target = find_series_by_id($data, $series_id);
    if ($target && is_anime($target['data'])) {
        $message = "Un épisode se modifie depuis sa propre fiche.";
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
        $_SESSION['error_message'] = $message;
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    $status = $_POST['status'] ?? 'à lire';
    $is_collector = !empty($_POST['is_collector']);
    $is_last = !empty($_POST['is_last']);
    $read_at = trim($_POST['read_at'] ?? '');
    // Validation basique du format de date (évite d'enregistrer une valeur invalide)
    if ($read_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $read_at)) {
        $read_at = null;
    }

    // Écriture ciblée : update_volume() (et, le cas échéant,
    // apply_status_to_all_volumes()) écrivent directement en base
    // (replace_series_volumes() + upsert_series_row() pour le statut de la
    // série) au moment de l'appel. $result['data'] ne sert qu'à réafficher
    // la collection à jour côté admin.
    $result = update_volume($data, $series_id, $volume_index, $status, $is_collector, $is_last, $read_at);
    if ($result['success']) {
        // Option : propager le statut de lecture à tous les tomes de la série.
        if (!empty($_POST['apply_status_all'])) {
            $batch = apply_status_to_all_volumes($result['data'], $series_id, $status, $read_at);
            if ($batch['success']) {
                $result['data'] = $batch['data'];
            }
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        if (!$result['success']) {
            echo json_encode(['success' => false, 'message' => $result['message'] ?? "La mise à jour a échoué."]);
            exit;
        }
        $updated = find_series_by_id($result['data'], $series_id);
        if (!$updated) {
            echo json_encode(['success' => false, 'message' => "Série introuvable après mise à jour."]);
            exit;
        }
        $loaned_by_series = loans_by_series(load_loans());
        echo json_encode([
            'success' => true,
            'series'  => build_light_series($updated['data'], $review_series_ids, $loaned_by_series[$series_id] ?? []),
        ]);
        exit;
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// ── Mettre à jour un épisode (statut de visionnage et date) ─────────────────
// Pendant de update_volume pour l'Animethèque, volontairement plus étroit : ni
// collector, ni « dernier épisode », ni répercussion sur le statut de la série.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_episode'])) {
    $series_id     = $_POST['series_id'] ?? '';
    $episode_index = (int)($_POST['episode_index'] ?? 0);
    $status        = $_POST['status'] ?? '';
    $watched_at    = trim($_POST['watched_at'] ?? '');
    // Même contrôle de format que pour les tomes : une date invalide est
    // ignorée plutôt qu'enregistrée telle quelle.
    if ($watched_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $watched_at)) {
        $watched_at = null;
    }

    // Requête AJAX (édition sans rechargement de page) : la réponse devient
    // du JSON contenant la carte "light" à jour, plutôt qu'une redirection.
    // $_POST['ajax'] est ajouté par edit-episode-modal (episodes.js) ; son
    // absence préserve le comportement historique pour tout appelant non-JS.
    $is_ajax = !empty($_POST['ajax']);

    $result = update_episode($data, $series_id, $episode_index, $status, $watched_at);
    if ($result['success']) {
        // Option « tout marquer » : le statut de visionnage (et sa date) est
        // recopié sur tous les épisodes de la série.
        if (!empty($_POST['apply_status_all'])) {
            $batch = apply_status_to_all_episodes($result['data'], $series_id, $status, $watched_at);
            if ($batch['success']) {
                $result['data'] = $batch['data'];
            }
        }
        // Écriture ciblée : update_episode() et apply_status_to_all_episodes()
        // écrivent elles-mêmes les épisodes de la série en base
        // (replace_series_volumes()), aucune écriture supplémentaire n'est
        // donc nécessaire ici.
    } else {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $result['message'] ?? "La mise à jour a échoué."]);
            exit;
        }
        $_SESSION['error_message'] = $result['message'];
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        $updated = find_series_by_id($result['data'], $series_id);
        if (!$updated) {
            echo json_encode(['success' => false, 'message' => "Série introuvable après mise à jour."]);
            exit;
        }
        $loaned_by_series = loans_by_series(load_loans());
        echo json_encode([
            'success' => true,
            'series'  => build_light_series($updated['data'], $review_series_ids, $loaned_by_series[$series_id] ?? []),
        ]);
        exit;
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// ── Bouton « + » d'une carte animée : épisode suivant marqué comme vu ───────
// En AJAX, sans rechargement : des clics successifs font progresser le
// visionnage épisode par épisode.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_next_episode'])) {
    header('Content-Type: application/json');

    $result = anime_mark_next_episode($data, $_POST['series_id'] ?? '');
    // Écriture ciblée : anime_mark_next_episode() délègue à update_episode(),
    // qui écrit déjà l'épisode concerné en base.

    echo json_encode([
        'success'       => $result['success'],
        'message'       => $result['message'] ?? '',
        'episode_index' => $result['episode_index'] ?? null,
        'episode'       => $result['episode'] ?? null,
        'counts'        => $result['counts'] ?? null,
    ]);
    exit;
}

// ── Endpoint AJAX : liste des séries dues pour la synchro automatique ───────
// Indépendant de la pagination/tri/recherche affichés : le front l'appelle
// une fois au chargement de la page Animethèque pour connaître le
// périmètre RÉEL à traiter, sans dépendre des cartes effectivement
// présentes dans le DOM à cet instant (voir assets/js/admin/anime.js).
// Avant ce endpoint, la synchro ne portait que sur les cartes déjà
// chargées/visibles : une série due mais triée loin dans la liste (au-delà
// des cartes chargées par le défilement infini) n'était jamais traitée tant
// que l'utilisateur n'avait pas scrollé jusqu'à elle.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_anime_sync_due_ids'])) {
    header('Content-Type: application/json');
    $due_ids = ($current_type === 'anime') ? anilist_sync_due_series_ids($data) : [];
    echo json_encode(['success' => true, 'ids' => array_values($due_ids)]);
    exit;
}

// ── Endpoint AJAX : synchronisation automatique d'une carte animée ──────────
// Déclenché par le front à l'affichage d'une carte éligible (diffusion ET
// visionnage « en cours »), une par une, espacées d'1 à 2 secondes (voir
// assets/js/admin/anime.js). La page s'affiche donc immédiatement, la
// synchro part ensuite en arrière-plan.
//
// Verrou d'1h par série (sauf contournement déjà écoulé), respecté ici même
// si le front ne rappelle que des cartes qu'il croit dues : la vérité reste
// celle du serveur. Échec API : report du verrou à 1h, jamais de fatale.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_anime_series'])) {
    header('Content-Type: application/json');

    $series_id = $_POST['series_id'] ?? '';
    $target    = find_series_by_id($data, $series_id);

    if (!$target || !is_anime($target['data'])) {
        echo json_encode(['success' => false, 'status' => 'error', 'message' => "Série animée introuvable."]);
        exit;
    }

    $result = anilist_sync_series_now($data, $series_id, false);
    $data   = $result['data'];

    if ($result['status'] === 'error' && !empty($result['retry_lock'])) {
        $data = anilist_sync_apply_retry_lock($data, $series_id);
    }
    // Écriture ciblée : anilist_sync_series_now() ('synced'/'unchanged') et
    // anilist_sync_apply_retry_lock() ('error') écrivent déjà directement en
    // base (upsert_series_row() + replace_series_volumes()). $data ne sert
    // plus qu'à relire l'état à jour pour la réponse JSON ci-dessous.

    // Relue APRÈS le report de verrou éventuel : anilist_synced_at doit
    // refléter ce qui vient d'être écrit, pas l'état d'avant l'échec.
    $refetched = find_series_by_id($data, $series_id);
    $series    = $refetched ? $refetched['data'] : ($result['series'] ?? $target['data']);

    echo json_encode([
        'success'            => in_array($result['status'], ['synced', 'unchanged'], true),
        'status'             => $result['status'], // 'synced' | 'unchanged' | 'error' | 'skipped'
        'message'            => $result['message'],
        'series_id'          => $series_id,
        'anime_status'       => $series['status'] ?? '',
        'volumes_count'      => count($series['volumes'] ?? []),
        'anilist_synced_at'  => (int)($series['anilist_synced_at'] ?? 0),
    ]);
    exit;
}

// Supprimer un tome
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_volume'])) {
    $series_id = $_POST['series_id'] ?? '';
    $volume_index = (int)($_POST['volume_index'] ?? 0);

    // Les épisodes ne se suppriment pas : leur liste est le reflet de ce qu'a
    // diffusé la série, pas un inventaire que l'on tient à la main.
    $target = find_series_by_id($data, $series_id);
    if ($target && is_anime($target['data'])) {
        $_SESSION['error_message'] = "Les épisodes d'une série animée viennent d'Anilist : ils ne se suppriment pas à la main.";
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    // Écriture ciblée : delete_volume() écrit directement en base
    // (replace_series_volumes()) au moment de l'appel.
    $result = delete_volume($data, $series_id, $volume_index);
    if ($result['success']) {
        $_SESSION['success_message'] = "Tome supprimé avec succès";
    } else {
        $_SESSION['error_message'] = $result['message'];
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// Mettre à jour une série
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_series'])) {
    $series_id = $_POST['series_id'] ?? '';
    $name = trim($_POST['edit_name'] ?? '');
    $author = trim($_POST['edit_author'] ?? '');
    $publisher = trim($_POST['edit_publisher'] ?? '');
    $other_contributors = trim($_POST['edit_other_contributors'] ?? '');
    $categories = trim($_POST['edit_categories'] ?? '');
    $genres = trim($_POST['edit_genres'] ?? '');
    $mangaupdates_url = trim($_POST['edit_mangaupdates_url'] ?? '');
    $babelio_url = trim($_POST['edit_babelio_url'] ?? '');
    $mature = !empty($_POST['edit_mature']);
    $favorite = !empty($_POST['edit_favorite']);
    $remove_image = !empty($_POST['remove_image']);
    $new_volumes_count = (int)($_POST['new_volumes_count'] ?? 0);
    $new_volumes_status = $_POST['new_volumes_status'] ?? 'à lire';
    $new_volumes_collector = !empty($_POST['new_volumes_collector']);
    $new_volumes_last = !empty($_POST['new_volumes_last']);
    $new_status         = $_POST['series_status'] ?? null;
    $edit_read_elsewhere = !empty($_POST['edit_read_elsewhere']);
    $edit_reading_abandoned = !empty($_POST['edit_reading_abandoned']);
    $edit_rating = sanitize_rating($_POST['edit_rating'] ?? '');
    $edit_reread_count = max(0, (int)($_POST['edit_reread_count'] ?? 0));

    // Requête AJAX (édition sans rechargement de page) : la réponse devient
    // du JSON contenant la carte "light" à jour, plutôt qu'une redirection.
    // $_POST['ajax'] est ajouté par edit-series-form (series.js) ; son absence
    // préserve le comportement historique pour tout appelant non-JS.
    $is_ajax = !empty($_POST['ajax']);

    $new_image = null;
    if (!empty($_FILES['edit_image']['name'])) {
        $error_message = null;
        $new_image = upload_image($_FILES['edit_image'], $error_message);
        if ($new_image === false) {
            $message = $error_message ?: "Erreur inconnue lors du téléversement de l'image.";
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
            $_SESSION['error_message'] = $message;
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }

    // Écriture ciblée : update_series() écrit directement en base
    // (upsert_series_row() + replace_series_volumes()) au moment de
    // l'appel. $result['data'] ne sert qu'à réafficher la collection à
    // jour côté admin.
    $result = update_series($data, $series_id, $name, $author, $other_contributors, $publisher, $categories, $genres, $mangaupdates_url, $babelio_url, $mature, $favorite, $remove_image, $new_volumes_count, $new_volumes_status, $new_volumes_collector, $new_volumes_last, $new_image, $new_status, $edit_read_elsewhere, $edit_reading_abandoned, $edit_rating, $edit_reread_count);
    if ($result['success']) {
        // Réchauffer le cache MangaUpdates pour la série modifiée
        if ($mangaupdates_url !== '') {
            $mu_id = mangaupdates_get_id_from_url($mangaupdates_url);
            if ($mu_id !== null) @mangaupdates_get_volumes($mu_id, true);
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        if (!$result['success']) {
            echo json_encode(['success' => false, 'message' => $result['message'] ?? "La mise à jour a échoué."]);
            exit;
        }
        $updated = find_series_by_id($result['data'], $series_id);
        if (!$updated) {
            echo json_encode(['success' => false, 'message' => "Série introuvable après mise à jour."]);
            exit;
        }
        $loaned_by_series = loans_by_series(load_loans());
        echo json_encode([
            'success' => true,
            'series'  => build_light_series($updated['data'], $review_series_ids, $loaned_by_series[$series_id] ?? []),
        ]);
        exit;
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// Supprimer une série
//
// Écriture ciblée : delete_series() écrit directement en base
// (delete_series_row()) au moment de l'appel — la suppression est déjà
// effective, et $result['data'] n'est plus qu'une collection d'affichage.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_series'])) {
    $series_id = $_POST['series_id'] ?? '';
    $result = delete_series($data, $series_id);
    if ($result['success']) {
        $_SESSION['success_message'] = $result['message'];
        echo "OK";
    } else {
        $_SESSION['error_message'] = $result['message'];
        echo $result['message'];
    }
    exit;
}

// Note : la mise à jour des options du site est désormais gérée par la page
// dédiée « Options » (page-options.php), à l'image de la page « Outils ».

// La gestion complète de la liste d'envies (ajout, édition, suppression,
// passage en collection) vit exclusivement dans pages/page-wishlist.php
// depuis la V4 : ce fichier a sa propre logique AJAX autonome, admin.php n'a
// plus à en dupliquer les handlers.

// Gestion des actions pour les prêts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loan_action'])) {
    $response = ['success' => false];
    $action = $_POST['loan_action'];

    switch ($action) {
        case 'add_single_loan':
            $series_id = $_POST['series_id'] ?? '';
            $volume_number = (int)($_POST['volume_number'] ?? 0);
            $borrower_name = trim($_POST['borrower_name'] ?? '');

            if ($series_id && $volume_number > 0 && $borrower_name) {
                $response = add_loan($data, $series_id, $volume_number, $borrower_name);
            } else {
                $response['message'] = 'La série sélectionnée n\'existe pas dans votre base. Veuillez vérifier votre sélection.';
            }
            break;

        case 'add_multiple_loans':
            $series_id = $_POST['series_id'] ?? '';
            $start_volume = (int)($_POST['start_volume'] ?? 0);
            $end_volume = (int)($_POST['end_volume'] ?? 0);
            $borrower_name = trim($_POST['borrower_name'] ?? '');

            if ($series_id && $start_volume > 0 && $end_volume >= $start_volume && $borrower_name) {
                $response = add_multiple_loans($data, $series_id, $start_volume, $end_volume, $borrower_name);
            }
            break;

        case 'remove_loan':
            $series_id = $_POST['series_id'] ?? '';
            $volume_number = (int)($_POST['volume_number'] ?? 0);

            if ($series_id && $volume_number > 0) {
                $response['success'] = remove_loan($series_id, $volume_number);
            }
            break;

        case 'remove_all_loans':
            $series_id = $_POST['series_id'] ?? '';
            if ($series_id) {
                $response['success'] = remove_all_loans($series_id);
            }
            break;

        case 'get_loans':
            $loans_by_series = get_loans_by_series($data);
            $response['success'] = true;
            $response['loans'] = $loans_by_series;
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Gestion de la pagination des séries
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_paginated_series'])) {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 9;
    $search_term = $_GET['search'] ?? '';
    $sort_by = $_GET['sort_by'] ?? 'name';
    $sort_order = $_GET['sort_order'] ?? 'asc';
    $light_mode = isset($_GET['light']) && $_GET['light'] === 'true';
    $status_filter = $_GET['status_filter'] ?? '';
    $status_mode   = $_GET['status_mode'] ?? 'and';
    $refine_categories = $_GET['refine_categories'] ?? '';
    $refine_genres     = $_GET['refine_genres'] ?? '';
    $refine_mode       = $_GET['refine_mode'] ?? 'and';
    $type_filter   = sanitize_series_type($_GET['type'] ?? '');

    // Copie d'affichage cloisonnée par type : recherche, tri et pagination ne
    // voient que la collection courante.
    $filtered_data = series_of_type($data, $type_filter);
    if ($search_term) {
        $normalized_search = normalize_string($search_term);
        $filtered_data = array_filter($filtered_data, function($series) use ($normalized_search) {
            return series_matches_search($series, $normalized_search);
        });
    }
    $filtered_data = apply_status_filter(
        $filtered_data,
        $status_filter,
        $status_mode,
        function($series) use ($review_series_ids) {
            return isset($review_series_ids[$series['id']]);
        },
        $type_filter
    );
    // Filtre « Affiner » (catégories / genres) : toujours combiné en ET avec
    // le filtre de statuts ci-dessus (deux filtres indépendants successifs).
    $filtered_data = apply_refine_filter($filtered_data, $refine_categories, $refine_genres, $refine_mode);
    sort_series($filtered_data, $sort_by, $sort_order);

    $offset = ($page - 1) * $per_page;
    $paginated_data = array_slice($filtered_data, $offset, $per_page);

    // En mode "light", on ne renvoie que les métadonnées — mais désormais
    // accompagnées du HTML des tomes/épisodes déjà rendu : depuis SQLite, ce
    // contenu ne coûte plus assez cher à générer pour justifier d'attendre un
    // clic. Les prêts sont préchargés ici UNE SEULE fois pour toute la page
    // plutôt qu'une requête par série.
    //
    // Pas de vérification MangaUpdates ici : comparer le décompte de chaque
    // série à sa fiche MangaUpdates implique un appel réseau (mangaupdates_get_volumes)
    // dès que son cache local a expiré, ce qui rendrait le simple affichage de
    // la liste bien plus lent — pouvant aller jusqu'à une requête HTTP par
    // série de la page, en série. Ce contrôle reste disponible à la demande
    // dans l'outil « Vérification des mangas » (page Outils), qui reste le bon endroit
    // pour ce genre de vérification ponctuelle.
    if ($light_mode) {
        $loaned_by_series = loans_by_series(load_loans());

        $light_series = array_map(function($series) use ($review_series_ids, $loaned_by_series) {
            return build_light_series($series, $review_series_ids, $loaned_by_series[$series['id']] ?? []);
        }, $paginated_data);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'series' => array_values($light_series),
            'has_more' => ($offset + $per_page) < count($filtered_data),
            // Nombre total de séries correspondant aux filtres actuels (toutes
            // pages confondues) : alimente le compteur « Séries visibles ».
            'total' => count($filtered_data)
        ]);
        exit;
    }
}

// Gestion de la récupération des tomes d'une série
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_series_volumes'])) {
    $series_id = $_GET['series_id'] ?? '';

    $series = null;
    foreach ($data as $key => $s) {
        if ($s['id'] === $series_id) {
            $series = $s;
            break;
        }
    }

    if (!$series) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Série introuvable.']);
        exit;
    }

    // Notifications via MangaUpdates — mangas uniquement : le décompte d'un
    // animé vient d'Anilist, et rien ne manque jamais à une liste d'épisodes
    // qu'on ne remplit pas à la main.
    $notifications = series_notifications($series);

    // Charger les prêts pour cette série — jamais pour un animé, même possédé
    // en édition physique : on ne prête pas un épisode.
    $loaned_volumes = [];
    if (!is_anime($series)) {
        $loaned_volumes = loans_by_series(load_loans())[$series_id] ?? [];
    }

    // Générer le HTML des tomes (ou des épisodes)
    $volumes_html = render_volumes_html($series, $notifications, $loaned_volumes);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'volumes_html' => $volumes_html,
        'notifications' => $notifications
    ]);
    exit;
}

// ── Endpoint : suggestions d'autocomplétion ─────────────────────────────────
// Deux usages, distingués par les paramètres :
//   • champs des modales → restrict_type=<type>, réponse au format historique
//     (tableau de chaînes), limitée à la collection concernée ;
//   • barre de recherche principale → with_types=1, sans restrict_type : la
//     suggestion TRAVERSE les types et chacune indique ceux où elle apparaît,
//     ce qui permet au front d'afficher un badge et de basculer de vue.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_suggestions'])) {
    $field = $_GET['field'] ?? '';
    $term = trim($_GET['term'] ?? '');
    $normalizedTerm = normalize_string($term);

    // Absent ou vide => aucune restriction, on balaie tous les types.
    $restrict_type = (isset($_GET['restrict_type']) && $_GET['restrict_type'] !== '')
        ? sanitize_series_type($_GET['restrict_type'])
        : '';
    $with_types = !empty($_GET['with_types']);

    $pool = ($restrict_type !== '') ? series_of_type($data, $restrict_type) : $data;

    // valeur => liste des types où elle apparaît (ordre de première rencontre)
    $suggestions = [];

    if (in_array($field, ['name', 'author', 'publisher', 'other_contributors', 'categories', 'genres', 'studios', 'alt_titles'], true)) {
        foreach ($pool as $series) {
            $series_type = series_type($series);

            // Studios et titres alternatifs sont propres aux animés : pas une
            // colonne directement lisible comme les autres champs (le premier
            // passe par une fonction de mise en forme, le second peut être
            // stocké en JSON), d'où ces deux cas à part plutôt qu'un simple
            // accès à $series[$field].
            if ($field === 'studios') {
                if (!is_anime($series)) continue;
                $values = (array)($series['studios'] ?? []);
            } elseif ($field === 'alt_titles') {
                if (!is_anime($series)) continue;
                $values = series_alt_titles($series);
            } else {
                if (!isset($series[$field])) continue;
                // Le champ est soit un tableau (contributeurs, genres, catégories),
                // soit une chaîne (nom, auteur, éditeur).
                $values = is_array($series[$field]) ? $series[$field] : [$series[$field]];
            }

            foreach ($values as $value) {
                $value = (string)$value;
                if (trim($value) === '') continue;
                if (!str_contains(normalize_string($value), $normalizedTerm)) continue;

                if (!isset($suggestions[$value])) {
                    $suggestions[$value] = [];
                }
                if (!in_array($series_type, $suggestions[$value], true)) {
                    $suggestions[$value][] = $series_type;
                }
            }
        }
    }

    header('Content-Type: application/json');
    if ($with_types) {
        $out = [];
        foreach ($suggestions as $value => $types) {
            $out[] = ['value' => (string)$value, 'types' => $types];
        }
        echo json_encode($out);
    } else {
        // Format historique : simple tableau de chaînes.
        echo json_encode(array_map('strval', array_keys($suggestions)));
    }
    exit;
}

// Gestion du tri et de la recherche
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'asc';
$search_term = $_GET['search'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';
$status_mode = $_GET['status_mode'] ?? 'and';
$refine_categories = $_GET['refine_categories'] ?? '';
$refine_genres = $_GET['refine_genres'] ?? '';
$refine_mode = $_GET['refine_mode'] ?? 'and';

// Copie d'affichage : jamais transmise à une fonction d'écriture, uniquement
// au rendu et à window.seriesData.
$filtered_data = series_of_type($data, $current_type);

sort_series($filtered_data, $sort_by, $sort_order);

if ($search_term) {
    $normalized_search = normalize_string($search_term);
    $filtered_data = array_filter($filtered_data, function($series) use ($normalized_search) {
        return series_matches_search($series, $normalized_search);
    });
}

// (Édition d'une entrée de la liste d'envies : gérée par
// pages/page-wishlist.php, cf. commentaire plus haut.)

// ── Bandeau d'actualisation automatique des épisodes (Anilist) ─────────────
// Décompte, sur TOUTE l'Animethèque (indépendamment du filtre/tri affiché),
// des séries dues pour la synchronisation automatique à l'instant du
// chargement de la page. Sert uniquement à afficher un état initial correct
// avant que le JS (assets/js/admin/anime.js) ne prenne le relais au fil des
// synchros réellement déclenchées. N'est montré que côté Animethèque : la
// synchro ne concerne jamais la Mangathèque.
$anime_sync_due_count = 0;
if ($current_type === 'anime') {
    $anime_sync_due_count = count(anilist_sync_due_series_ids($data));
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($options['admin_page_title']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($options['site_description']) ?>">
    <?= opengraph_tags($options) ?>
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
    <?= theme_link_tag($options) ?>
</head>
<body class="with-sidebar">
    <?php include 'includes/sidebar.php'; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div id="error-message" class="error-message">
            <?= $_SESSION['error_message'] ?>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success_message'])): ?>
        <?php
        $message = $_SESSION['success_message'];
        $is_warning = (strpos($message, 'attention') !== false);
        ?>
        <div class="alert <?php echo $is_warning ? 'alert-warning' : 'alert-success'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="container">
        <h1><?= htmlspecialchars($options['admin_page_title']) ?></h1>

        <!-- Barre de filtres et recherche -->
        <div class="filters">
            <form method="get">
                <!-- Conserve la collection affichée à la soumission du formulaire -->
                <input type="hidden" name="type" value="<?= htmlspecialchars($current_type) ?>">
                <div class="search-row">
                    <input type="text" name="search" autocomplete="off" id="search-all" placeholder="Rechercher une série, un auteur, un éditeur, etc.."
                           value="<?= htmlspecialchars($search_term) ?>">
                    <button type="submit">Appliquer</button>
                </div>
                <div class="sort-options">
                    <select name="sort_by" id="sort-by">
                        <option value="name" <?= $sort_by === 'name' ? 'selected' : '' ?>>Trier par nom</option>
                        <option value="author" <?= $sort_by === 'author' ? 'selected' : '' ?>>Trier par auteur</option>
                        <option value="publisher" <?= $sort_by === 'publisher' ? 'selected' : '' ?>>Trier par éditeur</option>
                        <option value="categories" <?= $sort_by === 'categories' ? 'selected' : '' ?>>Trier par catégories</option>
                        <option value="volumes" <?= $sort_by === 'volumes' ? 'selected' : '' ?>>Trier par nombre <?= htmlspecialchars(french_de_word(type_vocab($current_type, 'items'))) ?></option>
                        <option value="rereads" <?= $sort_by === 'rereads' ? 'selected' : '' ?>>Trier par nombre de <?= htmlspecialchars(is_anime($current_type) ? 'revisionnages' : 'relectures') ?></option>
                        <option value="added_at" <?= $sort_by === 'added_at' ? 'selected' : '' ?>>Trier par date d'ajout</option>
                        <option value="read_at" <?= $sort_by === 'read_at' ? 'selected' : '' ?>>Trier par date de <?= htmlspecialchars(type_vocab($current_type, 'activity')) ?></option>
                    </select>
                    <select name="sort_order" id="sort-order">
                        <option value="asc" <?= $sort_order === 'asc' ? 'selected' : '' ?>>Asc.</option>
                        <option value="desc" <?= $sort_order === 'desc' ? 'selected' : '' ?>>Desc.</option>
                    </select>
                    <?php render_status_filter($status_filter, $status_mode, true, $current_type); ?>
                    <?php render_refine_filter($refine_categories, $refine_genres, $refine_mode, series_of_type($data, $current_type)); ?>
                </div>
            </form>
            <p class="series-count" id="series-count" data-count="0">Séries visibles : <span id="series-count-value">…</span></p>
        </div>

        <!-- Bandeau d'actualisation automatique des épisodes (Animethèque) -->
        <?php if ($current_type === 'anime'): ?>
            <div class="anime-sync-banner<?= $anime_sync_due_count > 0 ? '' : ' anime-sync-banner--idle' ?>"
                 id="anime-sync-banner"
                 data-due-initial="<?= (int)$anime_sync_due_count ?>"
                 role="status" aria-live="polite">
                <span class="spinner" id="anime-sync-banner-spinner" hidden></span>
                <span id="anime-sync-banner-text">
                    <?php if ($anime_sync_due_count > 0): ?>
                        Actualisation des épisodes en cours… (<span id="anime-sync-banner-count">0</span> / <?= (int)$anime_sync_due_count ?> série<?= $anime_sync_due_count > 1 ? 's' : '' ?>)
                    <?php else: ?>
                        Épisodes à jour — aucune série à actualiser pour l'instant.
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>

        <!-- Boutons déclencheurs de modales (cachés — crochet JS uniquement) -->
        <div id="modal-triggers" style="display:none">
            <button id="open-add-series-modal"></button>
            <button id="open-add-multiple-volumes-modal"></button>
            <button id="open-add-anime-modal"></button>
        </div>

        <!-- Modales -->
        <!-- Modale pour ajouter une série -->
        <div class="modal" id="add-series-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-add-series-modal">&times;</span>
                <h2>Ajouter une série</h2>
                <form method="post" enctype="multipart/form-data">
                    <?php require __DIR__ . '/includes/syngas_search_section.php'; ?>
                    <p>Nom :</p>
                    <input type="text" name="name" id="add-series-name" placeholder="Nom de la série (obligatoire)" autocomplete="off" required>
                    <p>Auteur :</p>
                    <input type="text" name="author" id="add-series-author" placeholder="Nom de l'auteur (obligatoire)" autocomplete="off" required>
                    <p>Éditeur :</p>
                    <input type="text" name="publisher" id="add-series-publisher" placeholder="Nom de l'éditeur (obligatoire)" autocomplete="off" required>
                    <p>Autres contributeurs :</p>
                    <input type="text" name="other_contributors" id="add-series-other-contributors" placeholder="Autres contributeurs (séparés par des virgules) (facultatif)" autocomplete="off">
                    <p>Catégories :</p>
                    <input type="text" name="categories" id="add-series-categories" placeholder="Catégories (séparées par des virgules) (obligatoire)" autocomplete="off" required>
                    <p class="hint">Utilisez notamment "manga" ou "light-novel" pour identifier le type de publication — Syngas s'appuie sur ce tag pour reconnaître vos séries.</p>
                    <p>Genres :</p>
                    <input type="text" name="genres" id="add-series-genres" placeholder="Genres (séparés par des virgules) (facultatif)" autocomplete="off">
                    <p class="hint">Les classifications comme Shonen, Seinen, Action, Romance… se saisissent ici, pas dans Catégories.</p>
                    <p>Nombre de tomes à créer :</p>
                    <input type="number" name="volumes_count" id="volumes_count" placeholder="Nombre de tomes" min="1" value="1" autocomplete="off">
                    <p>Statut des tomes :</p>
                    <select name="volumes_status" id="volumes_status" required>
                        <option value="à lire">À lire</option>
                        <option value="en cours">En cours</option>
                        <option value="terminé">Terminé</option>
                    </select>
                    <label>
                        <input type="checkbox" name="all_collector"> Tous en collector ⭐
                    </label>
                    <p>Statut de publication de la série :</p>
                    <select name="series_status" id="add-series-status" required>
                        <option value="en cours">En cours ▶️</option>
                        <option value="terminée">Terminée ✅</option>
                        <option value="en pause">En pause ⏳</option>
                        <option value="abandonnée">Abandonnée ⛔</option>
                    </select>
                    <p>URL MangaUpdates :</p>
                    <input type="text" name="mangaupdates_url" placeholder="https://www.mangaupdates.com/series/xxxxxxx/nom-de-la-serie (facultatif)" autocomplete="off">
                    <p class="hint"><a tabindex="0" data-hint="L'URL MangaUpdates sert à détecter les tomes manquants des séries terminées (outil « Séries incomplètes »). Sur mangaupdates.com, ouvrez la fiche de votre série puis copiez l'URL complète. L'outil « Associer MangaUpdates » (modale Outils) peut aussi remplir ce champ automatiquement.">À quoi ça sert ? Où la trouver ?</a></p>
                    <p>URL Babelio :</p>
                    <input type="text" name="babelio_url" placeholder="https://www.babelio.com/serie/… (ou …/livres/… pour un one-shot)" autocomplete="off">
                    <p class="hint"><a tabindex="0" data-hint="L'URL Babelio permet de connaître le nombre de tomes réellement parus en France, via le service Babengas (onglet « Vérification Babelio » de la page Outils). Sur babelio.com, ouvrez la fiche SÉRIE (adresse en /serie/…) et copiez l'URL complète. Pour un one-shot (un seul tome, sans fiche série), collez l'adresse de la fiche du tome (/livres/…).">À quoi ça sert ? Où la trouver ?</a></p>
                    <label>
                        <input type="checkbox" name="mature"> Contenu mature 🔞
                    </label>
                    <label>
                        <input type="checkbox" name="favorite"> Série favorite ❤️
                    </label>
                    <label>
                        <input type="checkbox" name="read_elsewhere" id="add-series-read-elsewhere"> Lue ailleurs 📖
                    </label>
                    <p class="hint">Cochez si vous avez lu cette série sans la posséder (chez un ami, en bibliothèque, revendue, etc.).</p>
                    <label>
                        <input type="checkbox" name="reading_abandoned" id="add-series-reading-abandoned"> Lecture abandonnée 📕
                    </label>
                    <p class="hint">Cochez si vous avez arrêté de lire cette série.</p>
                    <p>Notation (facultatif) :</p>
                    <select name="rating" id="add-series-rating">
                        <option value="">Aucune note ➖</option>
                        <option value="apprecie">J'ai apprécié ☺️</option>
                        <option value="mitige">Mi-figue mi-raisin 😑</option>
                        <option value="deteste">Je n'ai pas aimé 😠</option>
                    </select>
                    <p>Nombre de relectures (facultatif) :</p>
                    <input type="number" name="reread_count" id="add-series-reread-count" min="0" step="1" value="0" autocomplete="off">
                    <p>Vignette :</p>
                    <input type="file" name="image" accept="image/jpeg, image/jpg, image/png, image/gif, image/webp">
                    <p class="hint">Extensions autorisées : jpeg, jpg, png, gif et webp. Poids maximum : 5 Mo.</p>
                    <input type="hidden" id="add-volume-series-id" name="series_id">
                    <!-- Posés automatiquement par la section « Recherche Syngas » ci-dessus
                         à la validation d'une correspondance — jamais saisis à la main. -->
                    <input type="hidden" id="add-series-syngas-uid" name="syngas_uid" value="">
                    <input type="hidden" id="add-series-syngas-thumbnail-path" name="syngas_thumbnail_path" value="">
                    <input type="hidden" id="add-series-syngas-volumes-count" name="syngas_volumes_count" value="">
                    <button type="submit" name="add_series">Ajouter</button>
                </form>
            </div>
        </div>


        <!-- Modale pour ajouter plusieurs tomes -->
        <div class="modal" id="add-multiple-volumes-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-add-multiple-volumes-modal">&times;</span>
                <h2>Ajouter des tomes</h2>
                <form method="post">
                    <p>Choisir une série :</p>
                    <input type="text" id="multiple-series-search" class="series-search" placeholder="Rechercher une série..." autocomplete="off">
                    <div class="series-results" id="multiple-series-results">
                        <?php // Mangas uniquement : les épisodes d'un animé ne s'ajoutent pas à la main. ?>
                        <?php foreach (series_of_type($data, 'manga') as $series): ?>
                            <div data-id="<?= $series['id'] ?>"><?= $series['name'] ?></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="series_id" id="multiple-selected-series-id" required>
                    <p>Nombre de tomes à ajouter :</p>
                    <input type="number" name="volumes_count" id="volumes_count" placeholder="Nombre de tomes" min="1" value="1" autocomplete="off">
                    <p>Statut des tomes :</p>
                    <select name="status" required>
                        <option value="à lire">À lire</option>
                        <option value="en cours">En cours</option>
                        <option value="terminé">Terminé</option>
                    </select>
                    <label>
                        <input type="checkbox" name="is_collector"> Collector ⭐
                    </label>
                    <p class="hint">Tous seront tagués ainsi.</p>
                    <label>
                        <input type="checkbox" name="is_last"> Dernier tome ✅
                    </label>
                    <p class="hint">Seul le dernier sera tagué comme tel.</p>
                    <button type="submit" name="add_multiple_volumes">Ajouter</button>
                </form>
            </div>
        </div>

        <!-- Modale pour éditer un tome -->
        <div class="modal" id="edit-volume-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-edit-volume-modal">&times;</span>
                <h2>Éditer le tome</h2>
                <form method="post" id="edit-volume-form">
                    <input type="hidden" name="series_id" id="edit-series-id">
                    <input type="hidden" name="volume_index" id="edit-volume-index">
                    <p id="edit-volume-number-display" class="volume-number-display"></p>
                    <select name="status" id="edit-volume-status" required>
                        <option value="à lire">À lire</option>
                        <option value="en cours">En cours</option>
                        <option value="terminé">Terminé</option>
                    </select>
                    <label id="edit-volume-read-at-label" class="volume-read-at-label">
                        Date de lecture
                        <input type="date" name="read_at" id="edit-volume-read-at">
                    </label>
                    <label>
                        <input type="checkbox" name="apply_status_all" id="edit-volume-apply-status-all"> Appliquer ce statut de lecture à tous les tomes de la série 📚
                    </label>
                    <p class="hint">Le statut (et, le cas échéant, la date de lecture) sera copié sur tous les tomes de la série. Les tags collector / dernier tome ne sont pas affectés.</p>
                    <label>
                        <input type="checkbox" name="is_collector"> Collector ⭐
                    </label>
                    <label>
                        <input type="checkbox" name="is_last"> Dernier tome ✅
                    </label>
                    <div class="modal-actions">
                        <button type="submit" name="update_volume">Mettre à jour</button>
                        <button type="button" id="delete-volume-btn" class="delete-btn">Supprimer ce tome</button>
                    </div>
                </form>
            </div>
        </div>

        <?php
        // Modale d'édition d'un épisode. Calquée sur celle des tomes, à trois
        // choses près : pas de bouton de suppression, pas de tag collector, pas
        // de tag « dernier épisode » — ce dernier est posé automatiquement quand
        // Anilist annonce la diffusion terminée. Les libellés viennent du
        // registre des types, rien n'est écrit en dur.
        $__ep_vocab = type_vocab('anime');
        ?>
        <!-- Modale pour éditer un épisode -->
        <div class="modal" id="edit-episode-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-edit-episode-modal">&times;</span>
                <h2>Éditer l'<?= htmlspecialchars($__ep_vocab['item']) ?></h2>
                <form method="post" id="edit-episode-form">
                    <input type="hidden" name="series_id" id="edit-episode-series-id">
                    <input type="hidden" name="episode_index" id="edit-episode-index">
                    <p id="edit-episode-number-display" class="volume-number-display"></p>
                    <select name="status" id="edit-episode-status" required>
                        <option value="<?= htmlspecialchars($__ep_vocab['todo']) ?>"><?= htmlspecialchars(ucfirst($__ep_vocab['todo'])) ?></option>
                        <option value="<?= htmlspecialchars($__ep_vocab['doing']) ?>"><?= htmlspecialchars(ucfirst($__ep_vocab['doing'])) ?></option>
                        <option value="<?= htmlspecialchars($__ep_vocab['done']) ?>"><?= htmlspecialchars(ucfirst($__ep_vocab['done'])) ?></option>
                    </select>
                    <label id="edit-episode-watched-at-label" class="volume-read-at-label">
                        Date de <?= htmlspecialchars($__ep_vocab['activity']) ?>
                        <input type="date" name="watched_at" id="edit-episode-watched-at">
                    </label>
                    <label>
                        <input type="checkbox" name="apply_status_all" id="edit-episode-apply-status-all"> Appliquer ce statut de <?= htmlspecialchars($__ep_vocab['activity']) ?> à tous les épisodes de la série 📺
                    </label>
                    <p class="hint">Le statut (et, le cas échéant, la date de <?= htmlspecialchars($__ep_vocab['activity']) ?>) sera copié sur tous les épisodes de la série.</p>
                    <div class="modal-actions">
                        <button type="submit" name="update_episode">Mettre à jour</button>
                    </div>
                    <p class="hint">Les épisodes viennent d'Anilist : ils ne s'ajoutent ni ne se suppriment à la main. Une erreur de fiche se corrige à la source, sur Anilist.</p>
                </form>
            </div>
        </div>

        <!-- Modale pour modifier une série -->
        <div class="modal" id="edit-series-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-edit-series-modal">&times;</span>
                <h2>Modifier la série</h2>
                <form method="post" enctype="multipart/form-data" id="edit-series-form">
                    <input type="hidden" name="series_id" id="edit-series-id-input">
                    <?php $__syngas_context = 'edit'; require __DIR__ . '/includes/syngas_search_section.php'; unset($__syngas_context); ?>
                    <p>Nom :</p>
                    <input type="text" name="edit_name" id="edit-series-name" placeholder="Nom de la série" autocomplete="off" required>
                    <p>Auteur :</p>
                    <input type="text" name="edit_author" id="edit-series-author" placeholder="Auteur" autocomplete="off" required>
                    <p>Éditeur :</p>
                    <input type="text" name="edit_publisher" id="edit-series-publisher" placeholder="Éditeur" autocomplete="off" required>
                    <p>Autres contributeurs :</p>
                    <input type="text" name="edit_other_contributors" id="edit-series-other-contributors" placeholder="Autres contributeurs (séparés par des virgules) (facultatif)" autocomplete="off">
                    <p>Catégories :</p>
                    <input type="text" name="edit_categories" id="edit-series-categories" placeholder="Catégories (séparées par des virgules)" autocomplete="off" required>
                    <p class="hint">Utilisez notamment "manga" ou "light-novel" pour identifier le type de publication — Syngas s'appuie sur ce tag pour reconnaître vos séries.</p>
                    <p>Genres :</p>
                    <input type="text" name="edit_genres" id="edit-series-genres" placeholder="Genres (séparés par des virgules)" autocomplete="off">
                    <p class="hint">Les classifications comme Shonen, Seinen, Action, Romance… se saisissent ici, pas dans Catégories.</p>
                    <p>URL MangaUpdates (facultatif) :</p>
                    <input type="text" name="edit_mangaupdates_url" id="edit-series-mangaupdates-url" placeholder="https://www.mangaupdates.com/series/xxxxxxx/nom-de-la-serie" autocomplete="off">
                    <p>URL Babelio (facultatif) :</p>
                    <input type="text" name="edit_babelio_url" id="edit-series-babelio-url" placeholder="https://www.babelio.com/serie/… (ou …/livres/… pour un one-shot)" autocomplete="off">
                    <p>Nombre de nouveaux tomes à créer :</p>
                    <input type="number" name="new_volumes_count" id="edit-series-new-volumes-count" placeholder="Nombre de nouveaux tomes" min="0" value="0" autocomplete="off">
                    <p>Statut des nouveaux tomes :</p>
                    <select name="new_volumes_status" id="edit-series-new-volumes-status">
                        <option value="à lire">À lire</option>
                        <option value="en cours">En cours</option>
                        <option value="terminé">Terminé</option>
                    </select>
                    <label>
                        <input type="checkbox" name="new_volumes_collector"> Tous en collector ⭐
                    </label>
                    <p>Statut de publication de la série :</p>
                    <select name="series_status" id="edit-series-status" required>
                        <option value="en cours">En cours ▶️</option>
                        <option value="terminée">Terminée ✅</option>
                        <option value="en pause">En pause ⏳</option>
                        <option value="abandonnée">Abandonnée ⛔</option>
                    </select>
                    <label>
                        <input type="checkbox" name="edit_mature" id="edit-series-mature"> Contenu mature 🔞
                    </label>
                    <label>
                        <input type="checkbox" name="edit_favorite" id="edit-series-favorite" <?= isset($series['favorite']) && $series['favorite'] ? 'checked' : '' ?>> Série favorite ❤️
                    </label>
                    <label>
                        <input type="checkbox" name="edit_read_elsewhere" id="edit-series-read-elsewhere"> Lue ailleurs 📖
                    </label>
                    <p class="hint">Cochez si vous avez lu cette série sans la posséder (chez un ami, en bibliothèque, revendue, etc.).</p>
                    <label>
                        <input type="checkbox" name="edit_reading_abandoned" id="edit-series-reading-abandoned"> Lecture abandonnée 📕
                    </label>
                    <p class="hint">Cochez si vous avez arrêté de lire cette série.</p>
                    <p>Notation (facultatif) :</p>
                    <select name="edit_rating" id="edit-series-rating">
                        <option value="">Aucune note ➖</option>
                        <option value="apprecie">J'ai apprécié ☺️</option>
                        <option value="mitige">Mi-figue mi-raisin 😑</option>
                        <option value="deteste">Je n'ai pas aimé 😠</option>
                    </select>
                    <p>Nombre de relectures (facultatif) :</p>
                    <input type="number" name="edit_reread_count" id="edit-series-reread-count" min="0" step="1" value="0" autocomplete="off">
                    <div class="current-image-container">
                        <p>Vignette actuelle :</p>
                        <img id="current-series-image" src="" alt="Image actuelle" style="max-width: 100px; margin-bottom: 10px;">
                        <input type="checkbox" name="remove_image" id="remove-image-checkbox">
                        <label for="remove-image-checkbox">Supprimer l'image</label>
                    </div>
                    <input type="file" name="edit_image" id="edit-series-image" accept="image/jpeg, image/jpg, image/png, image/gif, image/webp">
                    <p class="hint">Extensions autorisées : jpeg, jpg, png, gif et webp. Poids maximum : 5 Mo.</p>
                    <button type="submit" name="update_series">Mettre à jour</button>
                </form>
            </div>
        </div>

        <!-- ────────────────────────────────────────────────────────────────
             Modale : ajouter une série animée
             Recherche Anilist → 10 résultats maximum → sélection → import
             complet et automatique. Aucun champ à remplir : tout ce qui est
             factuel vient d'Anilist, qui fait autorité.
             ──────────────────────────────────────────────────────────────── -->
        <div class="modal" id="add-anime-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-add-anime-modal">&times;</span>
                <h2>Ajouter une série animée</h2>
                <p class="hint">
                    Cherchez la série sur Anilist, puis choisissez-la : sa fiche est importée
                    telle quelle. Studios, format, genres et statut de diffusion ne sont pas
                    modifiables dans Lengas — une erreur se corrige sur Anilist.
                </p>
                <div class="anime-search-row">
                    <input type="text" id="anime-search-input" placeholder="Titre de la série animée…" autocomplete="off">
                    <button type="button" id="anime-search-btn" class="button">Rechercher</button>
                </div>
                <p class="hint anime-search-or">— ou, si la recherche par titre ne trouve pas la série —</p>
                <div class="anime-search-row">
                    <input type="text" id="anime-lookup-input" placeholder="Identifiant Anilist (ex. 21519)…" autocomplete="off" inputmode="numeric">
                    <button type="button" id="anime-lookup-btn" class="button">Chercher par ID</button>
                </div>
                <div id="anime-search-feedback" class="anime-search-feedback"></div>
                <div id="anime-search-results" class="anime-search-results"></div>
            </div>
        </div>

        <!-- ────────────────────────────────────────────────────────────────
             Modale : modifier une série animée
             Seuls y figurent les champs qui appartiennent à l'utilisateur. Les
             données d'Anilist sont affichées en lecture seule, jamais en champ
             de saisie : ce qui n'est pas éditable ne doit pas en avoir l'air.
             ──────────────────────────────────────────────────────────────── -->
        <div class="modal" id="edit-anime-modal">
            <div class="modal-content">
                <span class="close-modal" id="close-edit-anime-modal">&times;</span>
                <h2>Modifier la série animée</h2>
                <form method="post" enctype="multipart/form-data" id="edit-anime-form">
                    <input type="hidden" name="series_id" id="edit-anime-id">

                    <p>Titre :</p>
                    <select name="anime_name" id="edit-anime-name"></select>
                    <p class="hint">
                        Au choix parmi les titres connus d'Anilist (romaji, anglais, natif,
                        synonymes). Le titre ne se saisit pas librement.
                    </p>

                    <div class="anime-readonly-block">
                        <p class="anime-readonly-title">Données Anilist <span>(non modifiables)</span></p>
                        <div class="anime-readonly-grid">
                            <div class="anime-readonly-item">
                                <span class="anime-readonly-label">Studios</span>
                                <span class="anime-readonly-value" id="edit-anime-studios"></span>
                            </div>
                            <div class="anime-readonly-item">
                                <span class="anime-readonly-label">Catégorie / format</span>
                                <span class="anime-readonly-value" id="edit-anime-format"></span>
                            </div>
                            <div class="anime-readonly-item">
                                <span class="anime-readonly-label">Genres</span>
                                <span class="anime-readonly-value" id="edit-anime-genres"></span>
                            </div>
                            <div class="anime-readonly-item">
                                <span class="anime-readonly-label">Statut de diffusion</span>
                                <span class="anime-readonly-value" id="edit-anime-status"></span>
                            </div>
                            <div class="anime-readonly-item">
                                <span class="anime-readonly-label">Fiche Anilist</span>
                                <span class="anime-readonly-value">
                                    <a id="edit-anime-link" href="#" target="_blank" rel="noopener">Ouvrir la fiche ↗</a>
                                </span>
                            </div>
                        </div>
                    </div>

                    <label>
                        <input type="checkbox" name="anime_mature" id="edit-anime-mature"> Contenu mature 🔞
                    </label>
                    <p class="hint">Cochée automatiquement à l'import si Anilist signale la série comme adulte. Une fois décochée, elle ne sera jamais recochée.</p>
                    <label>
                        <input type="checkbox" name="anime_favorite" id="edit-anime-favorite"> Série favorite ❤️
                    </label>
                    <label>
                        <input type="checkbox" name="anime_watching_abandoned" id="edit-anime-watching-abandoned"> Visionnage abandonné 📕
                    </label>
                    <p class="hint">Cochez si vous avez arrêté de regarder cette série.</p>

                    <p>Notation (facultatif) :</p>
                    <select name="anime_rating" id="edit-anime-rating">
                        <option value="">Aucune note ➖</option>
                        <option value="apprecie">J'ai apprécié ☺️</option>
                        <option value="mitige">Mi-figue mi-raisin 😑</option>
                        <option value="deteste">Je n'ai pas aimé 😠</option>
                    </select>

                    <p>Nombre de revisionnages :</p>
                    <input type="number" name="anime_rewatch_count" id="edit-anime-rewatch-count" min="0" step="1" value="0" autocomplete="off">
                    <p class="hint">Normalement importé depuis Anilist (champ « revisionnages » d'une entrée de liste), mais corrigeable ici à la main.</p>

                    <label>
                        <input type="checkbox" name="anime_has_editions" id="edit-anime-has-editions"> Éditions physiques 📀
                    </label>
                    <p class="hint">Cochez si vous possédez cette série en physique. Un commentaire = une édition, <?= series_editions_max() ?> au maximum, <?= series_edition_comment_max() ?> caractères chacun.</p>
                    <div id="edit-anime-editions" class="anime-editions" hidden>
                        <?php for ($__i = 0; $__i < series_editions_max(); $__i++): ?>
                            <input type="text"
                                   name="anime_editions[]"
                                   class="anime-edition-input"
                                   maxlength="<?= series_edition_comment_max() ?>"
                                   placeholder="Édition <?= $__i + 1 ?> (ex. coffret Blu-ray collector)"
                                   autocomplete="off">
                        <?php endfor; ?>
                    </div>

                    <div class="current-image-container">
                        <p>Vignette actuelle :</p>
                        <img id="edit-anime-image-preview" src="" alt="Vignette de la série" style="max-width: 100px; margin-bottom: 10px;">
                        <div id="edit-anime-remove-image-row">
                            <input type="checkbox" name="anime_remove_image" id="edit-anime-remove-image">
                            <label for="edit-anime-remove-image">Supprimer la vignette personnalisée</label>
                            <p class="hint">La vignette d'Anilist reprend alors sa place, sans rien à retélécharger.</p>
                        </div>
                        <p class="hint" id="edit-anime-image-origin"></p>
                    </div>
                    <input type="file" name="anime_image" id="edit-anime-image" accept="image/jpeg, image/jpg, image/png, image/gif, image/webp">
                    <p class="hint">Extensions autorisées : jpeg, jpg, png, gif et webp. Poids maximum : 5 Mo.</p>

                    <button type="submit" name="update_anime_series">Mettre à jour</button>
                </form>
            </div>
        </div>

        <!-- Modale pour les alertes personnalisées -->
        <div class="modal" id="custom-alert-modal">
            <div class="modal-content">
                <h2 id="custom-alert-title">Avertissement</h2>
                <p id="custom-alert-message"></p>
                <button id="custom-alert-ok" class="button">OK</button>
            </div>
        </div>

        <!-- Modale pour les confirmations personnalisées -->
        <div class="modal" id="custom-confirm-modal">
            <div class="modal-content">
                <h2 id="custom-confirm-title">Confirmation</h2>
                <p id="custom-confirm-message"></p>
                <div class="modal-actions">
                    <button id="custom-confirm-ok" class="button">OK</button>
                    <button id="custom-confirm-cancel" class="button">Annuler</button>
                </div>
            </div>
        </div>

        <!-- Liste des séries -->
         <div class="series-list" id="series-list">
            <!-- Le contenu sera chargé dynamiquement par JavaScript -->
            <?php if (empty($data)): ?>
                <p>Aucune série trouvée.</p>
            <?php endif; ?>
        </div>
        <div class="loading-spinner" id="loading-spinner">
            <p>Chargement en cours...</p>
        </div>

    </div>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <?php
        $series_with_status = array_map(function($series) use ($review_series_ids) {
            $status = $series['status'] ?? 'en cours';
            if (empty($series['status'])) {
                foreach ($series['volumes'] as $volume) {
                    if (!empty($volume['last'])) {
                        $status = 'terminée';
                        break;
                    }
                }
            }
            $series['status'] = $status;
            $series['has_review'] = isset($review_series_ids[$series['id']]);
            // Vignette résolue par la cascade perso → Anilist → défaut, et
            // origine de celle-ci, dont la modale d'édition a besoin.
            $series['thumbnail']    = series_thumbnail($series);
            $series['custom_image'] = $series['image'] ?? '';
            $series['studios_text'] = series_studios_text($series);
            $series['format_label'] = is_anime($series)
                ? anilist_format_label($series['anime_format'] ?? '')
                : '';
            $series['editions']     = series_edition_comments($series);
            $series['alt_titles']   = series_alt_titles($series);
            // sync_due : conservé pour l'affichage local (badge de statut de
            // la carte, si elle est déjà chargée) et pour d'éventuelles
            // lectures futures côté front. La synchro automatique elle-même
            // ne s'appuie plus sur ce champ : elle interroge l'endpoint
            // dédié get_anime_sync_due_ids au chargement de la page (voir
            // assets/js/admin/anime.js, animeSyncStart()), qui reste correct
            // même pour une série pas encore chargée dans le DOM.
            $series['sync_due']     = is_anime($series) && anilist_sync_is_due($series, false);
            return $series;
        }, array_values($filtered_data));
    ?>
    <script>
        window.seriesData = <?= json_encode($series_with_status) ?>;
        // Contexte de typage : collection affichée + registre allégé (libellé et
        // couleur de chaque type), pour les badges de l'autocomplétion.
        window.currentSeriesType = <?= json_encode($current_type) ?>;
        window.seriesTypes = <?= json_encode(series_types_for_js()) ?>;
        // Plafonds des éditions physiques, lus depuis le PHP pour qu'ils ne
        // soient définis qu'à un seul endroit (includes/helpers.php).
        window.animeEditionsMax = <?= json_encode(series_editions_max()) ?>;
        window.animeEditionCommentMax = <?= json_encode(series_edition_comment_max()) ?>;
    </script>
    <script src="assets/js/admin/modals.js"></script>
    <script src="assets/js/admin/autocomplete.js"></script>
    <script src="assets/js/admin/series.js"></script>
    <script src="assets/js/admin/syngas-search.js"></script>
    <script src="assets/js/admin/anime.js"></script>
    <script src="assets/js/admin/volumes.js"></script>
    <script src="assets/js/admin/episodes.js"></script>
    <script src="assets/js/admin/pagination.js"></script>
    <script src="assets/js/admin/main.js"></script>

</body>
</html>