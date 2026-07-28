<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/anime.php — Séries animées : création, édition, vignettes
//
// Pendant de fonctions/series.php pour le type `anime`. Les deux types
// partagent la table `series`, mais pas les règles : un animé ne se saisit pas,
// il s'importe. Anilist fait autorité (cf. feuille de route V4), et tout ce qui
// relève du fait — titre, studios, format, genres, statut de diffusion, nombre
// d'épisodes — n'est jamais modifiable ici. Une erreur constatée se corrige sur
// Anilist, pas dans Lengas.
//
// Reste à la main de l'utilisateur, et n'est JAMAIS écrasé par une synchro ou
// une revérification :
//   • le titre CHOISI parmi les titres alternatifs récupérés ;
//   • la vignette personnalisée ;
//   • la note, les coches mature / favorite / visionnage abandonné ;
//   • les éditions physiques.
//
// Dépendances : includes/anilist.php (connecteur), includes/helpers.php
// (registre des types, vignettes, éditions, statut de visionnage),
// fonctions/episodes.php (création et tenue de la liste des épisodes),
// config.php (load_data/save_data).
// ────────────────────────────────────────────────────────────────────────────

// ────────────────────────────────────────────────────────────────────────────
// 1. Vignette Anilist
// ────────────────────────────────────────────────────────────────────────────

// Télécharge la vignette d'une fiche Anilist dans uploads/.
//
// Ne lève jamais d'exception : un échec renvoie simplement ['ok' => false], et
// l'appelant continue sans vignette — la cascade d'affichage se repliera sur la
// vignette par défaut du site. Perdre une image ne doit pas faire perdre un
// import.
//
// Retour : ['ok' => bool, 'path' => string, 'error' => string]
function anime_download_cover(string $url): array {
    $fail = fn(string $msg) => ['ok' => false, 'path' => '', 'error' => $msg];

    $url = trim($url);
    if ($url === '') {
        return $fail("Aucune vignette n'est proposée par Anilist.");
    }
    if (!preg_match('#^https?://#i', $url)) {
        return $fail("Adresse de vignette invalide.");
    }
    if (!function_exists('curl_init')) {
        return $fail("L'extension cURL de PHP est absente : téléchargement impossible.");
    }

    // Le chemin stocké en base sert de source à des balises <img> et de clé de
    // comparaison ailleurs dans le site (cascade d'affichage, détection des
    // images orphelines) : toujours un slash "/", jamais DIRECTORY_SEPARATOR.
    // Sur un serveur Windows, ce dernier vaut "\" et produirait un chemin
    // bâtard ("uploads/\xxxx.jpg") qui reste affichable (Windows accepte les
    // deux séparateurs) mais ne correspond plus jamais au format "uploads/xxxx.jpg"
    // produit par scandir() ailleurs dans le site — d'où des vignettes Anilist
    // pourtant actives signalées à tort comme orphelines.
    $target_dir = rtrim(UPLOAD_DIR, '/') . '/';
    if (!is_dir($target_dir) || !is_writable($target_dir)) {
        return $fail("Le dossier des vignettes est inaccessible en écriture.");
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, function_exists('anilist_user_agent') ? anilist_user_agent() : 'Lengas');
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $body === '' || $http < 200 || $http >= 300) {
        return $fail("Téléchargement de la vignette impossible (code " . $http . ").");
    }
    // Même plafond que les téléversements manuels : 5 Mo.
    if (strlen($body) > 5 * 1024 * 1024) {
        return $fail("La vignette dépasse 5 Mo.");
    }

    // On ne se fie ni à l'extension de l'URL ni au type annoncé : le contenu
    // téléchargé est vérifié pour ce qu'il est.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return $fail("Impossible de vérifier le type de la vignette.");
    }
    $mime = finfo_buffer($finfo, $body);
    finfo_close($finfo);

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        return $fail("Le fichier téléchargé n'est pas une image exploitable.");
    }

    $path = $target_dir . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (file_put_contents($path, $body) === false) {
        return $fail("Écriture de la vignette impossible.");
    }
    // Dernier contrôle : le fichier écrit doit être une image lisible.
    if (getimagesize($path) === false) {
        @unlink($path);
        return $fail("Le fichier téléchargé n'est pas une image valide.");
    }

    return ['ok' => true, 'path' => $path, 'error' => ''];
}

// Supprime la vignette Anilist d'une série (fichier + champ).
// Appelée à la suppression d'une série ; les éditions physiques, elles, partent
// toutes seules par ON DELETE CASCADE.
function anime_purge_cover($series): void {
    $path = trim((string)($series['anilist_image'] ?? ''));
    if ($path !== '' && file_exists($path)) {
        @unlink($path);
    }
}

// ────────────────────────────────────────────────────────────────────────────
// 2. Recherche de doublons
// ────────────────────────────────────────────────────────────────────────────

// Retrouve une série par son identifiant Anilist. Renvoie la série ou null.
// L'identifiant est stocké en TEXT : la comparaison se fait sur l'entier pour
// éviter qu'un « 21 » et un « 021 » passent pour deux séries différentes.
function find_series_by_anilist_id(array $data, $anilist_id): ?array {
    $needle = (int)$anilist_id;
    if ($needle <= 0) return null;
    foreach ($data as $series) {
        if ((int)($series['anilist_id'] ?? 0) === $needle) {
            return $series;
        }
    }
    return null;
}

// ────────────────────────────────────────────────────────────────────────────
// 3. Création d'une série animée
// ────────────────────────────────────────────────────────────────────────────

// Crée une série de type `anime` à partir d'une fiche Anilist NORMALISÉE
// (telle que produite par anilist_normalize_media()).
//
// Refus possibles :
//   • fiche inexploitable ;
//   • série déjà présente (même anilist_id) ;
//   • série non encore diffusée — elle relève de la liste d'envies.
//     ⚠️ Ce refus est PROVISOIRE : au bloc 7, la liste d'envies devient typée et
//     ces séries y sont routées d'office au lieu d'être rejetées. Le message
//     temporaire ci-dessous est alors à retirer.
//
// Les épisodes sont créés dans la foulée, à partir du nombre d'épisodes
// RÉELLEMENT DIFFUSÉS (fonctions/episodes.php) : ni plus, ni moins, et jamais à
// la main ensuite.
//
// Retour : ['success', 'data', 'message', 'series_id', 'warning']
function add_anime_series(array $data, array $media, bool $download_cover = true): array {
    if (empty($media['anilist_id'])) {
        return ['success' => false, 'data' => $data, 'message' => "Fiche Anilist inexploitable."];
    }

    $existing = find_series_by_anilist_id($data, $media['anilist_id']);
    if ($existing !== null) {
        return [
            'success' => false,
            'data'    => $data,
            'message' => "Cette série est déjà dans la vidéothèque, sous le titre « " . $existing['name'] . " ».",
        ];
    }

    if (!empty($media['not_yet_released'])) {
        return [
            'success' => false,
            'data'    => $data,
            'message' => "« " . $media['title'] . " » n'est pas encore diffusée : "
                       . "elle relève de la liste d'envies, pas de la vidéothèque.",
        ];
    }

    // Vignette : téléchargée à l'ajout, une fois pour toutes. Un échec n'annule
    // jamais l'import, il remonte seulement en avertissement.
    $anilist_image = '';
    $warning       = '';
    if ($download_cover && !empty($media['cover'])) {
        $cover = anime_download_cover($media['cover']);
        if ($cover['ok']) {
            $anilist_image = $cover['path'];
        } else {
            $warning = "Série ajoutée, mais la vignette Anilist n'a pas pu être récupérée ("
                     . $cover['error'] . ")";
        }
    }

    $series_id = generate_uuid();

    // Épisodes : créés ici, une fois pour toutes, à partir des seuls épisodes
    // déjà diffusés. Le tag « dernier épisode » est posé dans la foulée si la
    // diffusion est terminée et le compte complet.
    $episodes = anime_episodes_from_media($media);

    $data[] = [
        'id'   => $series_id,
        // Titre par défaut : romaji. Modifiable ensuite, mais uniquement par
        // sélection parmi les titres alternatifs.
        'name' => $media['title'],
        'type' => 'anime',
        // Champs sans objet pour un animé : laissés vides, et masqués à
        // l'affichage comme dans les modales.
        'author'             => '',
        'publisher'          => '',
        'other_contributors' => [''],
        // La catégorie d'un animé, c'est son format. On alimente le champ
        // existant avec le libellé français : recherche, tri par catégorie et
        // autocomplétion fonctionnent alors sans traitement particulier. Le code
        // brut d'Anilist reste dans `anime_format`, seul champ faisant foi.
        'categories'         => [$media['format_label'] ?? ''],
        'genres'             => $media['genres_fr'] ?? [],
        'image'              => '',
        'mangaupdates_url'   => '',
        'babelio_url'        => '',
        // Coche « Contenu mature » posée d'après isAdult. L'utilisateur peut la
        // décocher : elle ne sera jamais recochée derrière lui.
        'mature'             => !empty($media['is_adult']),
        'favorite'           => false,
        'status'             => $media['status_tag'] ?? 'en cours',
        'read_elsewhere'     => false,
        'reading_abandoned'  => false,
        'rating'             => '',
        // ── Champs Anilist ──────────────────────────────────────────────────
        'anilist_id'         => (string)$media['anilist_id'],
        'anilist_url'        => $media['site_url'] ?? '',
        'studios'            => $media['studios'] ?? [],
        'anime_format'       => $media['format'] ?? '',
        'alt_titles'         => $media['alt_titles'] ?? [],
        'anilist_image'      => $anilist_image,
        'watching_abandoned' => false,
        'rewatch_count'      => 0,
        'anilist_synced_at'  => 0,
        'editions'           => [],
        // Épisodes déjà diffusés, numérotés 1..N (cf. fonctions/episodes.php).
        'volumes'            => $episodes,
    ];

    $count   = count($episodes);
    $created = $count > 0
        ? "Série animée ajoutée avec succès (" . $count . " épisode" . ($count > 1 ? 's' : '') . " diffusé" . ($count > 1 ? 's' : '') . ")."
        : "Série animée ajoutée avec succès, mais aucun épisode n'a encore été diffusé.";

    return [
        'success'   => true,
        'data'      => $data,
        'message'   => $warning !== '' ? $warning : $created,
        'series_id' => $series_id,
        'warning'   => $warning,
    ];
}

// ────────────────────────────────────────────────────────────────────────────
// 4. Édition d'une série animée
// ────────────────────────────────────────────────────────────────────────────

// Met à jour les SEULS champs personnalisables d'un animé.
//
// Tout le reste (studios, format, genres, statut de diffusion, lien Anilist,
// nombre de visionnages) est délibérément absent de cette fonction : ces champs
// ne sont pas éditables, et ne doivent pas pouvoir l'être par un POST forgé.
//
// $fields accepte :
//   name                 titre choisi — REFUSÉ s'il ne figure pas dans les
//                        titres alternatifs connus de la série
//   mature, favorite, watching_abandoned   booléens
//   rating               note du site ('' = aucune)
//   editions             liste de commentaires d'éditions physiques
//   new_image            chemin d'une vignette fraîchement téléversée
//   remove_image         true pour effacer la vignette personnalisée
//
// Retour : ['success', 'data', 'message']
function update_anime_series(array $data, string $series_id, array $fields): array {
    $found = find_series_by_id($data, $series_id);
    if (!$found) {
        return ['success' => false, 'data' => $data, 'message' => "Série introuvable."];
    }

    $key    = $found['key'];
    $series = $found['data'];

    if (!is_anime($series)) {
        return ['success' => false, 'data' => $data, 'message' => "Cette série n'est pas une série animée."];
    }

    $messages = [];

    // ── Titre : sélection seulement, jamais de saisie libre ─────────────────
    if (array_key_exists('name', $fields)) {
        $wanted = trim((string)$fields['name']);
        $known  = series_alt_titles($series);
        if ($wanted !== '' && in_array($wanted, $known, true)) {
            $data[$key]['name'] = $wanted;
        } elseif ($wanted !== '' && $wanted !== $series['name']) {
            $messages[] = "Titre ignoré : il ne fait pas partie des titres connus d'Anilist.";
        }
    }

    // ── Coches et note ──────────────────────────────────────────────────────
    if (array_key_exists('mature', $fields)) {
        $data[$key]['mature'] = (bool)$fields['mature'];
    }
    if (array_key_exists('favorite', $fields)) {
        $data[$key]['favorite'] = (bool)$fields['favorite'];
    }
    if (array_key_exists('watching_abandoned', $fields)) {
        $data[$key]['watching_abandoned'] = (bool)$fields['watching_abandoned'];
    }
    if (array_key_exists('rating', $fields)) {
        $data[$key]['rating'] = sanitize_rating($fields['rating']);
    }

    // ── Éditions physiques ──────────────────────────────────────────────────
    if (array_key_exists('editions', $fields)) {
        $submitted = sanitize_edition_comments($fields['editions']);
        $data[$key]['editions'] = array_map(
            fn($comment, $i) => ['comment' => $comment, 'position' => $i],
            $submitted,
            array_keys($submitted)
        );
    }

    // ── Vignette personnalisée ──────────────────────────────────────────────
    // Sa suppression ne touche pas à la vignette Anilist : celle-ci réapparaît
    // d'elle-même, la cascade de series_thumbnail() faisant le reste.
    if (!empty($fields['remove_image'])) {
        $current = trim((string)($data[$key]['image'] ?? ''));
        if ($current !== '' && file_exists($current)) {
            @unlink($current);
        }
        $data[$key]['image'] = '';
    }
    if (!empty($fields['new_image'])) {
        $current = trim((string)($data[$key]['image'] ?? ''));
        if ($current !== '' && file_exists($current)) {
            @unlink($current);
        }
        $data[$key]['image'] = $fields['new_image'];
    }

    return [
        'success' => true,
        'data'    => $data,
        'message' => implode(' ', $messages),
    ];
}

// ────────────────────────────────────────────────────────────────────────────
// 5. Statut de visionnage
// ────────────────────────────────────────────────────────────────────────────
//
// anime_watching_status() a rejoint includes/helpers.php, aux côtés du registre
// des types. Raison : le filtre de statuts (includes/status_filter.php) en a
// besoin sur la page publique, qui ne charge pas ce fichier-ci — index.php
// n'écrit jamais dans la collection et n'a donc que faire des fonctions de
// création et d'édition. Une fonction qui ne fait que lire une série n'avait de
// toute façon rien à faire dans un fichier d'écriture.
