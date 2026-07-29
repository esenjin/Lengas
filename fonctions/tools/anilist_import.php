<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/anilist_import.php — Outil « Import Anilist »
//
// Import de masse de la liste ANIME d'un compte Anilist, par pseudo public.
// Se déroule en deux temps, strictement séparés :
//
//   Phase 1 — RÉCUPÉRATION ET APERÇU : aucune écriture en base. On récupère la
//   liste complète (includes/anilist.php::anilist_fetch_user_list), on la
//   classe par destination (vidéothèque / liste d'envies / déjà présente), et
//   on construit un aperçu que l'utilisateur ajuste avant de valider.
//
//   Phase 2 — IMPORT : n'écrit que ce que l'aperçu, éventuellement corrigé par
//   l'utilisateur (statuts décochés, formats exclus, séries décochées à la
//   main…), a retenu. Aiguillage par statut de liste Anilist, cf. tableau de
//   correspondance dans la fonction anilist_import_apply_entry() plus bas.
//
// Pas de campagne façon Babengas ici : Anilist répond en quelques secondes par
// tranche de 250 entrées (anilist_fetch_user_list), la progression tient dans
// une seule requête HTTP par phase, sans file d'attente externe. La « reprise
// après fermeture de page » demandée par la feuille de route est assurée par
// la persistance de l'aperçu en base (table anilist_import_state) : fermer
// l'onglet ne perd pas le travail déjà récupéré.
//
// Dépendances : includes/anilist.php (connecteur), fonctions/anime.php
// (add_anime_series), fonctions/episodes.php (anime_episodes_from_media),
// fonctions/wishlist.php (add_to_wishlist), includes/helpers.php,
// config.php (load_data/save_data, get_db).
// ────────────────────────────────────────────────────────────────────────────

// ────────────────────────────────────────────────────────────────────────────
// 1. Persistance de l'aperçu (reprise après fermeture de page)
// ────────────────────────────────────────────────────────────────────────────
// Une seule campagne d'aperçu à la fois. Stockée dans la table `options`
// (clé 'anilist_import_state', valeur JSON) : rien de nouveau à migrer.
//
// ⚠️ Le pseudo et les réglages ne sont PAS mémorisés d'une campagne à l'autre :
// cette persistance ne sert qu'à survivre à un rechargement PENDANT une même
// campagne, jamais à préremplir la suivante. anilist_import_clear_state() est
// appelée à chaque nouvelle campagne ET après un import réussi.

function anilist_import_state_key(): string {
    return 'anilist_import_state';
}

// Enregistre l'aperçu construit en phase 1.
function anilist_import_save_state(array $state): void {
    $state['saved_at'] = time();
    save_options([anilist_import_state_key() => json_encode($state, JSON_UNESCAPED_UNICODE)]);
}

// Relit l'aperçu en cours, ou null si aucune campagne n'est en attente de
// validation.
function anilist_import_load_state(): ?array {
    $opts = load_options();
    $raw  = trim((string)($opts[anilist_import_state_key()] ?? ''));
    if ($raw === '') return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

// Efface l'aperçu (nouvelle campagne, ou import terminé).
function anilist_import_clear_state(): void {
    delete_options([anilist_import_state_key()]);
}

// ────────────────────────────────────────────────────────────────────────────
// 2. Aiguillage par statut de liste Anilist
// ────────────────────────────────────────────────────────────────────────────

// Statuts de liste qui vont en VIDÉOTHÈQUE (tous les autres — PLANNING mis à
// part, cf. ci-dessous — n'existent pas sur Anilist pour le type ANIME).
function anilist_import_library_statuses(): array {
    return ['CURRENT', 'COMPLETED', 'REPEATING', 'DROPPED', 'PAUSED'];
}

// Destination d'une entrée, indépendamment des réglages de l'utilisateur
// (filtres de statut/format/adulte à sélectionner en phase 1) : 'library',
// 'wishlist' ou 'unknown' (statut de liste imprévu — ne devrait pas arriver,
// Anilist n'ayant que six statuts pour le type ANIME).
//
// Règle absolue, rappelée par la feuille de route : une série NOT_YET_RELEASED
// part TOUJOURS en liste d'envies, quel que soit son statut de liste (un
// PLANNING non encore diffusé y va de toute façon ; un compte peut aussi avoir
// classé par erreur une série à venir en CURRENT/COMPLETED — Anilist fait
// autorité sur la diffusion, pas le classement personnel de l'utilisateur).
function anilist_import_destination(array $media, string $list_status): string {
    if (!empty($media['not_yet_released'])) {
        return 'wishlist';
    }
    $list_status = strtoupper(trim($list_status));
    if ($list_status === 'PLANNING') {
        return 'wishlist';
    }
    if (in_array($list_status, anilist_import_library_statuses(), true)) {
        return 'library';
    }
    return 'unknown';
}

// ────────────────────────────────────────────────────────────────────────────
// 3. Phase 1 — Construction de l'aperçu
// ────────────────────────────────────────────────────────────────────────────

// Index des anilist_id déjà présents, vidéothèque ET liste d'envies (une
// même série ne doit pas se voir proposer deux destinations à la fois).
// Retour : ['library' => [anilist_id => série], 'wishlist' => [anilist_id => item]]
function anilist_import_known_index(array $data, array $wishlist): array {
    $library = [];
    foreach ($data as $series) {
        if (!is_anime($series)) continue;
        $aid = (int)($series['anilist_id'] ?? 0);
        if ($aid > 0) $library[$aid] = $series;
    }
    $wl = [];
    foreach ($wishlist as $item) {
        if (sanitize_series_type($item['type'] ?? '') !== 'anime') continue;
        $aid = (int)($item['anilist_id'] ?? 0);
        if ($aid > 0) $wl[$aid] = $item;
    }
    return ['library' => $library, 'wishlist' => $wl];
}

// Construit l'aperçu complet à partir de la liste Anilist déjà récupérée
// (anilist_fetch_user_list()) et de l'état actuel de la collection.
//
// Ne fait AUCUNE écriture, ni en base ni sur le disque : uniquement de la
// lecture et du classement. Le résultat est ce qui sera persisté par
// anilist_import_save_state() et affiché par l'écran d'aperçu.
//
// Retour :
//   [
//     'username'      => pseudo Anilist normalisé,
//     'fetched_at'    => timestamp,
//     'entries'       => [ [anilist_id, title, cover, format, format_label,
//                           list_status, list_status_label, destination,
//                           progress, episodes, is_adult, not_yet_released,
//                           custom_lists, already_library, already_wishlist,
//                           already_title], … ],
//     'custom_lists'  => listes personnalisées déclarées (mediaListOptions),
//     'favourites'    => [['anilist_id','title'], …] (favoris natifs),
//     'counts_by_destination' => ['library'=>n,'wishlist'=>n,'existing'=>n,'error'=>n],
//     'counts_by_status'      => [STATUS => n, …] (tous statuts confondus),
//     'counts_by_format'      => [FORMAT => n, …],
//     'estimated_duration'    => secondes estimées pour l'import complet,
//   ]
function anilist_import_build_preview(string $username, array $data, array $wishlist): array {
    $fetch = anilist_fetch_user_list($username);
    if (!$fetch['ok']) {
        return ['ok' => false, 'message' => $fetch['error'], 'error_type' => $fetch['error_type']];
    }

    $known = anilist_import_known_index($data, $wishlist);

    // Listes personnalisées DÉCLARÉES (sélecteur de favoris) : peuvent exister
    // sans qu'aucune entrée n'y figure encore, d'où un second appel dédié.
    $declared_lists = anilist_fetch_user_custom_lists($username);
    $favourites     = anilist_fetch_user_favourites($username);
    $favourite_ids  = $favourites['ok'] ? array_flip(array_map('intval', $favourites['ids'])) : [];

    $entries          = [];
    $counts_dest      = ['library' => 0, 'wishlist' => 0, 'existing' => 0, 'error' => 0];
    $counts_status    = array_fill_keys(anilist_list_status_keys(), 0);
    $counts_format    = array_fill_keys(anilist_format_keys(), 0);
    $requests_needed  = 0; // pour l'estimation de durée (une requête par lot de 50 fiches à réimporter)

    foreach ($fetch['entries'] as $entry) {
        $media      = $entry['media'];
        $aid        = $media['anilist_id'];
        $list_status = $entry['list_status'];

        $counts_status[$list_status] = ($counts_status[$list_status] ?? 0) + 1;
        if (isset($counts_format[$media['format']])) {
            $counts_format[$media['format']]++;
        }

        $already_library  = isset($known['library'][$aid]);
        $already_wishlist = isset($known['wishlist'][$aid]);

        if ($already_library) {
            $destination = 'existing';
            $counts_dest['existing']++;
        } else {
            $destination = anilist_import_destination($media, $list_status);
            if ($destination === 'unknown') {
                $counts_dest['error']++;
            } else {
                $counts_dest[$destination]++;
            }
        }

        $entries[] = [
            'anilist_id'        => $aid,
            'title'             => $media['title'],
            'title_english'     => $media['title_english'],
            'title_native'      => $media['title_native'],
            'alt_titles'        => $media['alt_titles'],
            'cover'             => $media['cover'],
            'format'            => $media['format'],
            'format_label'      => $media['format_label'],
            'is_adult'          => $media['is_adult'],
            'not_yet_released'  => $media['not_yet_released'],
            'status_label'      => $media['status_label'],
            'list_status'       => $list_status,
            'list_status_label' => $entry['list_status_label'],
            'progress'          => $entry['progress'],
            'episodes'          => $media['episodes'],
            'repeat'            => $entry['repeat'],
            'score'             => $entry['score'],
            // Note déjà traduite par le connecteur (anilist_score_to_rating(),
            // à partir de score(format: POINT_100) — fiable quel que soit le
            // format d'affichage du compte). Sans cette ligne, seul le score
            // BRUT ('score' ci-dessus) survit jusqu'à l'aperçu : 'rating'
            // n'existait pas dans ce tableau, et la note ne pouvait jamais
            // être reprise à l'écriture, quoi qu'applique la phase 2.
            'rating'            => $entry['rating'],
            // Date de visionnage retenue par le site : completedAt, repli sur
            // updatedAt. Déjà calculée par le connecteur, simplement propagée
            // jusqu'à la phase d'import.
            'watched_at'        => $entry['watched_at'],
            'custom_lists'      => $entry['custom_lists'],
            // Favori NATIF Anilist (cœur), distinct des listes personnalisées
            // ci-dessus. Calculé une bonne fois ici : la phase de réglages
            // (anilist_import_apply_settings) n'a plus qu'à le lire.
            'is_native_favourite' => isset($favourite_ids[$aid]),
            'destination'       => $destination,
            'already_library'   => $already_library,
            'already_wishlist'  => $already_wishlist,
            'already_title'     => $already_library
                ? $known['library'][$aid]['name']
                : ($already_wishlist ? $known['wishlist'][$aid]['name'] : ''),
        ];

        // Une fiche déjà en cache (récupérée par anilist_fetch_user_list) ne
        // recoûte rien à l'import ; seule une éventuelle fiche manquante au
        // cache en coûterait une, mais on ne peut pas le savoir avant coup —
        // on compte prudemment 1 requête par entrée à créer ou mettre à jour,
        // amorti par lots de 50 (anilist_fetch_media_batch), pour une
        // estimation réaliste sans être optimiste.
        $requests_needed++;
    }

    // ⚠️ Ordre stable : trié par titre pour un affichage prévisible, la
    // pagination/le défilement virtuel du front s'appuient dessus.
    usort($entries, fn($a, $b) => strcasecmp($a['title'], $b['title']));

    $estimated_duration = anilist_estimate_duration((int)ceil($requests_needed / 50) + 5);

    return [
        'ok'                    => true,
        'username'              => $username,
        'fetched_at'            => time(),
        'entries'               => $entries,
        'custom_lists'          => $declared_lists['ok'] ? $declared_lists['lists'] : [],
        'favourites'            => $favourites['ok'] ? $favourites['anime'] : [],
        'counts_by_destination' => $counts_dest,
        'counts_by_status'      => $counts_status,
        'counts_by_format'      => $counts_format,
        'estimated_duration'    => $estimated_duration,
        'estimated_duration_label' => anilist_import_format_duration($estimated_duration),
        'total'                 => count($entries),
    ];
}

// ────────────────────────────────────────────────────────────────────────────
// 4. Phase 1 → Phase 2 : application des réglages avant écriture
// ────────────────────────────────────────────────────────────────────────────

// Filtre les entrées de l'aperçu selon les réglages retenus par l'utilisateur.
// Ne fait AUCUNE écriture ; c'est la liste finale qui sera réellement traitée
// par anilist_import_run(). $settings :
//   'statuses'      => [STATUS, …] statuts de liste retenus
//   'formats'       => [FORMAT, …] formats retenus (MUSIC décoché par défaut
//                      côté front, mais c'est bien la liste REÇUE qui décide)
//   'include_adult' => bool, inclure les séries isAdult
//   'update_existing' => bool, si false : les séries déjà présentes sont
//                         intégralement ignorées (ni créées, ni mises à jour)
//   'excluded_ids'  => [anilist_id, …] décochage individuel
//   'favourite_lists' => [nom de liste personnalisée, …] retenues
//   'favourite_native' => bool, inclure les favoris natifs (cœurs) Anilist
function anilist_import_apply_settings(array $entries, array $settings): array {
    $statuses   = array_map('strtoupper', (array)($settings['statuses'] ?? anilist_list_status_keys()));
    $formats    = array_map('strtoupper', (array)($settings['formats']  ?? anilist_format_keys()));
    $adult_ok   = !empty($settings['include_adult']);
    $update_existing = array_key_exists('update_existing', $settings) ? (bool)$settings['update_existing'] : true;
    $excluded   = array_flip(array_map('intval', (array)($settings['excluded_ids'] ?? [])));
    $fav_lists  = array_map('mb_strtolower', array_map('trim', (array)($settings['favourite_lists'] ?? [])));
    $fav_native = !empty($settings['favourite_native']);

    $out = [];
    foreach ($entries as $entry) {
        if (isset($excluded[$entry['anilist_id']])) continue;
        if (!in_array($entry['list_status'], $statuses, true)) continue;
        if (!in_array($entry['format'], $formats, true)) continue;
        if (!$adult_ok && !empty($entry['is_adult'])) continue;
        if ($entry['destination'] === 'existing' && !$update_existing) continue;
        if ($entry['destination'] === 'unknown') continue;

        // Favori si appartenance à une liste perso retenue OU présence dans
        // les favoris natifs sélectionnés.
        $is_favourite = false;
        if (!empty($fav_lists)) {
            foreach ((array)($entry['custom_lists'] ?? []) as $list_name) {
                if (in_array(mb_strtolower(trim($list_name)), $fav_lists, true)) {
                    $is_favourite = true;
                    break;
                }
            }
        }
        if ($fav_native && !empty($entry['is_native_favourite'])) {
            $is_favourite = true;
        }
        $entry['will_be_favourite'] = $is_favourite;

        $out[] = $entry;
    }
    return $out;
}

// ────────────────────────────────────────────────────────────────────────────
// 5. Phase 2 — Import
// ────────────────────────────────────────────────────────────────────────────

// Importe UNE entrée déjà filtrée (anilist_import_apply_settings()) dans la
// vidéothèque. Recharge systématiquement la fiche complète depuis Anilist (ou
// son cache 24 h) : Anilist fait autorité, la fiche embarquée dans l'aperçu
// n'est qu'un instantané d'affichage.
//
// Une requête par entrée plutôt qu'un lot (anilist_fetch_media_batch) : la
// plupart des fiches sont déjà en cache depuis la récupération de la liste en
// phase 1 (anilist_fetch_user_list les y dépose au passage), donc l'essentiel
// de la campagne ne recoûte rien en requêtes réseau — seules les fiches dont
// le cache a expiré entre-temps en déclenchent une. Le quota reste de toute
// façon respecté dans tous les cas par le limiteur intégré au connecteur
// (includes/anilist.php::anilist_rate_wait()).
//
// Retour : ['status' => 'created'|'updated'|'error', 'message' => …,
//           'data' => $data (si modifié)]
function anilist_import_process_library_entry(array $data, array $entry, bool $force_refresh = false): array {
    $fetch = anilist_fetch_media((int)$entry['anilist_id'], $force_refresh);
    if (!$fetch['ok']) {
        return ['status' => 'error', 'message' => $entry['title'] . ' — ' . $fetch['error'], 'data' => $data];
    }
    $media = $fetch['media'];

    $existing = find_series_by_anilist_id($data, $entry['anilist_id']);

    if ($existing === null) {
        return anilist_import_create_library_entry($data, $media, $entry);
    }
    return anilist_import_update_library_entry($data, $existing, $media, $entry);
}

// Crée une série animée à partir d'une entrée d'import, avec l'AIGUILLAGE
// PROPRE À L'IMPORT (progression, revisionnage, abandon…) — add_anime_series()
// seul ne suffit pas : il pose une liste d'épisodes fraîche mais ignore
// `progress`/`repeat`/statut de liste, qui n'existent que dans ce contexte de
// campagne. On appelle donc add_anime_series() pour la fiche factuelle, puis
// on complète avec ce que l'import seul sait faire.
function anilist_import_create_library_entry(array $data, array $media, array $entry): array {
    $result = add_anime_series($data, $media, true);
    if (!$result['success']) {
        // Doublon détecté entre-temps (import concurrent, ajout manuel pendant
        // la campagne) ou série non diffusée malgré l'aiguillage : on le
        // signale sans faire échouer toute la campagne.
        return ['status' => 'error', 'message' => $entry['title'] . ' — ' . $result['message'], 'data' => $result['data']];
    }

    $data = $result['data'];
    $key  = null;
    foreach ($data as $i => $s) {
        if ($s['id'] === $result['series_id']) { $key = $i; break; }
    }
    if ($key === null) {
        // Ne devrait pas arriver : la série vient d'être ajoutée par la ligne
        // ci-dessus. Garde-fou silencieux plutôt qu'une notice PHP.
        return ['status' => 'created', 'message' => $result['message'], 'data' => $data];
    }

    $data[$key] = anilist_import_apply_watch_progress($data[$key], $entry, $media);
    if (!empty($entry['will_be_favourite'])) {
        // La coche favorite n'est posée qu'À LA CRÉATION : c'est justement
        // le cas ici. Un ré-import ne la coche ni ne la décoche jamais.
        $data[$key]['favorite'] = true;
    }

    // Note : add_anime_series() pose toujours 'rating' => '' (aucun score à
    // sa disposition, c'est un ajout unitaire hors contexte de liste). Ici, en
    // revanche, $entry['rating'] a déjà été traduit par le connecteur
    // (anilist_score_to_rating(), à partir de score(format: POINT_100) — donc
    // fiable quel que soit le format d'affichage du compte, y compris les
    // smileys). Sans cette ligne, la note Anilist n'est JAMAIS reprise à
    // l'import, quel que soit le format de notation du compte.
    $data[$key]['rating'] = sanitize_rating($entry['rating'] ?? '');

    return ['status' => 'created', 'message' => $entry['title'] . ' ajoutée.', 'data' => $data];
}

// Met à jour une série déjà présente. Ne touche JAMAIS aux personnalisations
// (titre choisi, vignette perso, note, coches, éditions) — seuls les champs
// factuels et la progression de visionnage sont concernés, exactement comme
// add_anime_series() le fait à la création.
function anilist_import_update_library_entry(array $data, array $existing, array $media, array $entry): array {
    $key = null;
    foreach ($data as $i => $s) {
        if ($s['id'] === $existing['id']) { $key = $i; break; }
    }
    if ($key === null) {
        return ['status' => 'error', 'message' => $entry['title'] . ' — série introuvable en mémoire.', 'data' => $data];
    }

    // Champs factuels : identiques à ce qu'add_anime_series() pose à la
    // création, jamais les champs personnalisables.
    $data[$key]['anilist_url']   = $media['site_url'] ?? '';
    $data[$key]['studios']       = $media['studios'] ?? [];
    $data[$key]['anime_format']  = $media['format'] ?? '';
    $data[$key]['alt_titles']    = $media['alt_titles'] ?? [];
    $data[$key]['categories']    = [$media['format_label'] ?? ''];
    $data[$key]['genres']        = $media['genres_fr'] ?? [];
    $data[$key]['status']        = $media['status_tag'] ?? $data[$key]['status'];
    $data[$key]['episode_duration'] = max(0, (int)($media['duration'] ?? 0));

    // Épisodes : reconstruits à partir de l'existant (aucune perte de
    // visionnage), puis la progression de la campagne s'applique par-dessus.
    $data[$key]['volumes'] = anime_episodes_from_media($media, $data[$key]['volumes'] ?? []);
    $data[$key] = anilist_import_apply_watch_progress($data[$key], $entry, $media);

    // La coche favorite n'est JAMAIS posée NI retirée par une mise à jour.

    return ['status' => 'updated', 'message' => $entry['title'] . ' mise à jour.', 'data' => $data];
}

// Applique la progression de visionnage d'après le statut de liste Anilist de
// l'entrée d'import, en suivant strictement le tableau d'aiguillage suivant :
//   COMPLETED           → tous les épisodes en « terminé »
//   CURRENT             → 1..progress en « terminé », le reste en « à voir »
//   REPEATING           → tous les épisodes en « terminé » + rewatch_count
//   DROPPED / PAUSED    → progression + coche « Visionnage abandonné »
//   PLANNING            → liste d'envies, aucun épisode créé
//   (NOT_YET_RELEASED)  → liste d'envies, prioritaire sur le statut de liste
//
// ⚠️ REPEATING : ne JAMAIS appliquer la règle de CURRENT ici. `progress` sur
// un revisionnage reflète l'avancement du REVISIONNAGE en cours, pas la
// progression réelle de la série — l'utiliser reviendrait à repasser en
// « à voir » des épisodes déjà vus la première fois. COMPLETED et REPEATING
// partagent donc la même règle : tout terminé.
function anilist_import_apply_watch_progress(array $series, array $entry, array $media): array {
    $status   = strtoupper((string)($entry['list_status'] ?? ''));
    $episodes = $series['volumes'] ?? [];

    switch ($status) {
        case 'COMPLETED':
        case 'REPEATING':
            // Tous les épisodes en « terminé ». Note : le pendant générique
            // apply_status_to_all_episodes() (fonctions/episodes.php) opère
            // sur la collection complète via find_series_by_id() ; ici on ne
            // dispose que de la série isolée en cours de construction, d'où
            // l'application directe de la même règle.
            $series['volumes'] = anilist_import_mark_all_done($episodes, $entry['watched_at'] ?? '');
            break;

        case 'CURRENT':
        case 'DROPPED':
        case 'PAUSED':
            $progress = max(0, (int)($entry['progress'] ?? 0));
            $series['volumes'] = anilist_import_mark_progress($episodes, $progress, $entry['watched_at'] ?? '');
            break;

        case 'PLANNING':
        default:
            // Ne devrait pas arriver ici (PLANNING part en liste d'envies) :
            // laissé tel quel par prudence, aucun épisode ne serait marqué.
            break;
    }

    // watching_abandoned suit fidèlement le statut de liste Anilist à chaque
    // (ré)import : ce n'est pas une personnalisation manuelle (contrairement à
    // mature/favorite/rating), donc un retour à CURRENT/COMPLETED/REPEATING
    // après une pause ou un abandon doit bien décocher la case, pas seulement
    // la cocher pour DROPPED/PAUSED.
    $series['watching_abandoned'] = in_array($status, ['DROPPED', 'PAUSED'], true);

    $series['rewatch_count'] = max(0, (int)($entry['repeat'] ?? 0));

    // Le tag « dernier épisode » ne dépend que du statut de diffusion et du
    // compte complet — jamais du statut de liste de l'utilisateur.
    $series['volumes'] = anime_refresh_last_episode($series['volumes'], anime_airing_finished($series));

    return $series;
}

// Marque TOUS les épisodes comme vus (COMPLETED, REPEATING).
function anilist_import_mark_all_done(array $episodes, string $watched_at): array {
    $date = $watched_at !== '' ? $watched_at : date('Y-m-d');
    foreach ($episodes as &$episode) {
        $episode['status']  = episode_status_done();
        $episode['read_at'] = ($episode['read_at'] ?? '') !== '' ? $episode['read_at'] : $date;
    }
    return $episodes;
}

// Marque les épisodes 1..$progress comme vus, le reste « à voir »
// (CURRENT, DROPPED, PAUSED).
function anilist_import_mark_progress(array $episodes, int $progress, string $watched_at): array {
    $date = $watched_at !== '' ? $watched_at : date('Y-m-d');
    foreach ($episodes as &$episode) {
        $number = (int)($episode['number'] ?? 0);
        if ($number > 0 && $number <= $progress) {
            $episode['status']  = episode_status_done();
            $episode['read_at'] = ($episode['read_at'] ?? '') !== '' ? $episode['read_at'] : $date;
        } else {
            $episode['status']  = episode_status_todo();
            $episode['read_at'] = '';
        }
    }
    return $episodes;
}

// Envoie une entrée vers la liste d'envies. N'écrase jamais une entrée déjà
// présente (add_to_wishlist() refuse déjà les doublons par anilist_id).
// Retour : ['status' => 'wishlist'|'error'|'skipped', 'message', 'wishlist']
function anilist_import_process_wishlist_entry(array $wishlist, array $entry): array {
    $result = add_to_wishlist(
        $wishlist,
        $entry['title'],
        '',
        '',
        'anime',
        '',
        (string)$entry['anilist_id']
    );
    if (!$result['success']) {
        // Déjà présente : ce n'est pas une erreur de campagne, juste rien à
        // faire de plus pour cette entrée.
        return ['status' => 'skipped', 'message' => $entry['title'] . ' — déjà en liste d\'envies.', 'wishlist' => $wishlist];
    }
    return ['status' => 'wishlist', 'message' => $entry['title'] . ' envoyée en liste d\'envies.', 'wishlist' => $result['wishlist']];
}

// ────────────────────────────────────────────────────────────────────────────
// 6. Orchestrateur de la phase 2
// ────────────────────────────────────────────────────────────────────────────

// Traite l'intégralité des entrées retenues (déjà filtrées par
// anilist_import_apply_settings()), en appelant $on_progress après chaque
// entrée pour alimenter une progression SSE. N'écrit PAS elle-même en base :
// c'est l'appelant (endpoint de page-outils.php) qui fait le save_data() /
// save_wishlist() final, une fois la boucle terminée — un seul aller-retour
// SQLite pour toute la campagne plutôt qu'un par série.
//
// $on_progress : callable(int $current, int $total, string $title) — facultatif.
//
// Retour :
//   [
//     'data'          => collection mise à jour (à passer à save_data()),
//     'wishlist'      => liste d'envies mise à jour (à passer à save_wishlist()),
//     'created'       => [titres…], 'updated' => [titres…],
//     'wishlisted'    => [titres…], 'skipped' => [titres…],
//     'errors'        => [['title','message'], …],
//     'favourite_count' => nombre de séries marquées favorites à la création,
//   ]
function anilist_import_run(array $data, array $wishlist, array $entries, $on_progress = null): array {
    $created    = [];
    $updated    = [];
    $wishlisted = [];
    $skipped    = [];
    $errors     = [];
    $fav_count  = 0;

    $total   = count($entries);
    $current = 0;

    foreach ($entries as $entry) {
        $current++;
        if (is_callable($on_progress)) {
            call_user_func($on_progress, $current, $total, $entry['title']);
        }

        if ($entry['destination'] === 'wishlist') {
            $result = anilist_import_process_wishlist_entry($wishlist, $entry);
            $wishlist = $result['wishlist'];
            if ($result['status'] === 'wishlist')  $wishlisted[] = $entry['title'];
            if ($result['status'] === 'skipped')    $skipped[]    = $entry['title'];
            if ($result['status'] === 'error')      $errors[]     = ['title' => $entry['title'], 'message' => $result['message']];
            continue;
        }

        // 'library' (création) ou 'existing' (mise à jour) : même fonction,
        // qui distingue elle-même les deux cas via find_series_by_anilist_id().
        $result = anilist_import_process_library_entry($data, $entry);
        $data   = $result['data'];

        if ($result['status'] === 'created') {
            $created[] = $entry['title'];
            if (!empty($entry['will_be_favourite'])) $fav_count++;
        } elseif ($result['status'] === 'updated') {
            $updated[] = $entry['title'];
        } else {
            $errors[] = ['title' => $entry['title'], 'message' => $result['message']];
        }
    }

    return [
        'data'            => $data,
        'wishlist'        => $wishlist,
        'created'         => $created,
        'updated'         => $updated,
        'wishlisted'      => $wishlisted,
        'skipped'         => $skipped,
        'errors'          => $errors,
        'favourite_count' => $fav_count,
    ];
}

// ────────────────────────────────────────────────────────────────────────────
// 7. Estimation de durée (affichage de l'aperçu)
// ────────────────────────────────────────────────────────────────────────────

// Estimation lisible (« environ 3 minutes ») à partir d'un nombre de secondes.
function anilist_import_format_duration(int $seconds): string {
    if ($seconds < 60) return "moins d'une minute";
    $minutes = (int)ceil($seconds / 60);
    if ($minutes < 60) return "environ " . $minutes . " minute" . ($minutes > 1 ? 's' : '');
    $hours = round($minutes / 60, 1);
    return "environ " . str_replace('.', ',', (string)$hours) . " heure" . ($hours > 1 ? 's' : '');
}
