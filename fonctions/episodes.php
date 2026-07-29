<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/episodes.php — Épisodes des séries animées
//
// Les épisodes réutilisent la table `volumes` : même structure, même stockage,
// mêmes gabarits d'affichage. Ce qui change, ce sont les règles.
//
//   • Anilist est la seule source. Aucun ajout, aucune suppression manuels,
//     nulle part dans le site : la liste des épisodes est le reflet de ce qui
//     a été diffusé, pas une saisie.
//   • Un épisode ne s'achète pas : ni tag collector, ni prêt, ni date d'achat
//     à l'affichage. Seuls le statut de visionnage et sa date sont à la main de
//     l'utilisateur.
//   • Le tag « dernier épisode » n'est jamais coché à la main : il est posé
//     automatiquement quand la diffusion est terminée côté Anilist et que le
//     compte est complet.
//
// Les statuts sont stockés LITTÉRALEMENT (`à voir` / `en cours` / `terminé`) et
// lus depuis le registre central des types (includes/helpers.php) : aucun de ces
// mots n'est écrit en dur ici.
//
// Dépendances : includes/helpers.php (registre des types, find_series_by_id),
// fonctions/volumes.php (apply_status_to_all_volumes, mécanique partagée),
// includes/anilist.php pour les fiches normalisées passées en argument.
// ────────────────────────────────────────────────────────────────────────────

// ────────────────────────────────────────────────────────────────────────────
// 1. Statuts de visionnage
// ────────────────────────────────────────────────────────────────────────────

// Statut d'un épisode fraîchement créé.
function episode_status_todo(): string {
    return type_vocab('anime', 'todo');       // « à voir »
}

// Statut d'un épisode vu.
function episode_status_done(): string {
    return type_vocab('anime', 'done');       // « terminé »
}

// Les trois statuts admis, dans l'ordre d'affichage du sélecteur.
function episode_statuses(): array {
    $vocab = type_vocab('anime');
    return [$vocab['todo'], $vocab['doing'], $vocab['done']];
}

// Normalise un statut reçu du navigateur. Une valeur inconnue (POST forgé, ou
// donnée héritée d'une base antérieure) retombe sur « à voir ».
function sanitize_episode_status($status): string {
    $status = trim((string)$status);
    $known  = episode_statuses();
    return in_array($status, $known, true) ? $status : $known[0];
}

// L'épisode est-il vu ?
function episode_is_watched($episode): bool {
    return (($episode['status'] ?? '') === episode_status_done());
}

// ────────────────────────────────────────────────────────────────────────────
// 2. Création et mise à niveau de la liste des épisodes
// ────────────────────────────────────────────────────────────────────────────

// Gabarit d'un épisode encore à voir.
// `collector` reste à false : le tag n'a aucun sens pour un épisode, c'est
// l'édition physique de la série entière qui est collector. Le champ est
// conservé parce que la table `volumes` est partagée.
function anime_episode_template(int $number): array {
    return [
        'number'    => $number,
        'status'    => episode_status_todo(),
        'collector' => false,
        'last'      => false,
        'added_at'  => date('Y-m-d'),
        'read_at'   => '',
    ];
}

// Construit la liste des épisodes 1..N.
//
// $existing permet de repasser sur une liste déjà constituée sans rien perdre :
// les épisodes connus gardent leur statut et leur date, seuls les manquants sont
// créés. Un épisode déjà présent au-delà de N est CONSERVÉ : une liste
// d'épisodes ne se raccourcit pas toute seule, sous peine d'effacer sans
// prévenir des visionnages enregistrés. Une divergence de ce genre relève de
// l'outil de revérification, qui la signale et demande validation.
function anime_build_episodes(int $count, array $existing = []): array {
    $count = max(0, $count);

    $known = [];
    foreach ($existing as $episode) {
        $number = (int)($episode['number'] ?? 0);
        if ($number > 0) $known[$number] = $episode;
    }
    $highest = empty($known) ? 0 : max(array_keys($known));
    $total   = max($count, $highest);

    $episodes = [];
    for ($number = 1; $number <= $total; $number++) {
        if (!isset($known[$number])) {
            $episodes[] = anime_episode_template($number);
            continue;
        }

        $episode = $known[$number];
        $status  = sanitize_episode_status($episode['status'] ?? '');
        $watched = ($status === episode_status_done());

        $episodes[] = [
            'number'    => $number,
            'status'    => $status,
            'collector' => false,
            'last'      => !empty($episode['last']),
            'added_at'  => ($episode['added_at'] ?? '') !== '' ? $episode['added_at'] : date('Y-m-d'),
            // Un épisode vu sans date connue est daté du jour plutôt que de
            // laisser un trou ; un épisode non vu n'a jamais de date.
            'read_at'   => $watched
                ? (($episode['read_at'] ?? '') !== '' ? $episode['read_at'] : date('Y-m-d'))
                : '',
        ];
    }

    return $episodes;
}

// Nombre d'épisodes à créer pour une fiche Anilist normalisée.
//
// Le connecteur ne compte que les épisodes RÉELLEMENT DIFFUSÉS (voir
// anilist_aired_episodes()). Un seul garde-fou est ajouté ici : une œuvre dont
// la diffusion est terminée compte forcément au moins un épisode. Anilist laisse
// parfois `episodes` à null sur les films, les OAV unitaires et les vieux
// spéciaux — sans ce filet, la fiche arriverait sans le moindre épisode.
function anime_episode_count_from_media(array $media): int {
    $count = max(0, (int)($media['aired_episodes'] ?? 0));

    if ($count === 0
        && empty($media['not_yet_released'])
        && ($media['status_tag'] ?? '') === 'terminée') {
        $count = 1;
    }

    return $count;
}

// La liste créée couvre-t-elle tout ce qu'Anilist annonce ?
// Sans nombre d'épisodes annoncé, il n'y a rien à confronter : on considère le
// compte complet et c'est le statut de diffusion qui tranche.
function anime_episode_count_is_complete(array $media, int $created): bool {
    $announced = $media['episodes'] ?? null;
    if ($announced === null || (int)$announced <= 0) return true;
    return $created >= (int)$announced;
}

// ────────────────────────────────────────────────────────────────────────────
// 3. Tag « dernier épisode »
// ────────────────────────────────────────────────────────────────────────────

// Pose (ou retire) le tag sur l'épisode de plus haut numéro.
//
// Jamais de saisie manuelle : le tag ne dépend que de deux faits, la diffusion
// terminée côté Anilist et le compte complet. Il vaut pour la série ce que la
// coche « dernier tome » vaut pour un manga — la différence, c'est que personne
// ne le coche.
function anime_refresh_last_episode(array $episodes, bool $airing_finished): array {
    $last_index  = -1;
    $last_number = 0;
    foreach ($episodes as $index => $episode) {
        $number = (int)($episode['number'] ?? 0);
        if ($number >= $last_number) {
            $last_number = $number;
            $last_index  = $index;
        }
    }

    foreach ($episodes as $index => $episode) {
        $episodes[$index]['last'] = false;
    }
    if ($airing_finished && $last_index >= 0) {
        $episodes[$last_index]['last'] = true;
    }

    return $episodes;
}

// La diffusion de la série est-elle terminée ? Le statut vient d'Anilist et de
// nulle part ailleurs : il n'est pas déduit des épisodes, contrairement aux
// mangas où cocher « dernier tome » termine la série.
function anime_airing_finished($series): bool {
    return (($series['status'] ?? '') === 'terminée');
}

// ────────────────────────────────────────────────────────────────────────────
// 4. Épisodes d'une fiche Anilist
// ────────────────────────────────────────────────────────────────────────────

// Liste d'épisodes prête à être enregistrée pour une fiche normalisée, tag
// « dernier épisode » compris. $existing permet de repasser sur une série déjà
// importée sans perdre les visionnages (synchronisation, ré-import).
function anime_episodes_from_media(array $media, array $existing = []): array {
    $count    = anime_episode_count_from_media($media);
    $episodes = anime_build_episodes($count, $existing);

    $finished = (($media['status_tag'] ?? '') === 'terminée')
             && anime_episode_count_is_complete($media, count($episodes));

    return anime_refresh_last_episode($episodes, $finished);
}

// ────────────────────────────────────────────────────────────────────────────
// 5. Décomptes
// ────────────────────────────────────────────────────────────────────────────

// ['total' => n, 'watched' => n, 'remaining' => n] pour une série.
function anime_episode_counts($series): array {
    $episodes = $series['volumes'] ?? [];
    $watched  = 0;
    foreach ($episodes as $episode) {
        if (episode_is_watched($episode)) $watched++;
    }
    $total = count($episodes);
    return [
        'total'     => $total,
        'watched'   => $watched,
        'remaining' => max(0, $total - $watched),
    ];
}

// Index du premier épisode non terminé de la liste, -1 s'ils sont tous vus.
// C'est ce que vise le bouton « + » d'une carte de série animée.
function anime_next_episode_index($series): int {
    foreach ($series['volumes'] ?? [] as $index => $episode) {
        if (!episode_is_watched($episode)) return (int)$index;
    }
    return -1;
}

// ────────────────────────────────────────────────────────────────────────────
// 6. Écritures
// ────────────────────────────────────────────────────────────────────────────

// Met à jour le statut de visionnage d'un épisode, et sa date le cas échéant.
//
// Volontairement plus étroit qu'update_volume() : ni collector, ni « dernier
// épisode », et surtout aucune répercussion sur le statut de la série — celui-ci
// est le statut de DIFFUSION, il vient d'Anilist et ne se déduit pas de ce que
// l'utilisateur a vu.
//
// Règles de date, identiques à celles des tomes :
//   • une date fournie explicitement prime, tant que le statut reste « terminé »
//   • passage à « terminé » sans date → date du jour
//   • épisode déjà « terminé » qui le reste → date conservée
//   • sortie du statut « terminé » → date effacée
// Retour : ['success', 'data', 'message']
//
// Écriture ciblée : ne touche jamais au `status` de la série (contrairement
// à update_volume()) — le statut de diffusion vient d'Anilist et ne se
// déduit pas des épisodes vus. Un seul replace_series_volumes() suffit, pas
// d'upsert_series_row().
function update_episode(array $data, string $series_id, int $episode_index, string $status, ?string $watched_at = null): array {
    $found = find_series_by_id($data, $series_id);
    if (!$found) {
        return ['success' => false, 'data' => $data, 'message' => "Série introuvable."];
    }

    $key = $found['key'];
    if (!is_anime($found['data'])) {
        return ['success' => false, 'data' => $data, 'message' => "Cette série n'est pas une série animée."];
    }
    if (!isset($data[$key]['volumes'][$episode_index])) {
        return ['success' => false, 'data' => $data, 'message' => "Épisode introuvable."];
    }

    $episode  = $data[$key]['volumes'][$episode_index];
    $status   = sanitize_episode_status($status);
    $previous = $episode['status'] ?? '';
    $existing = $episode['read_at'] ?? '';

    if ($status === episode_status_done()) {
        if ($watched_at !== null && $watched_at !== '') {
            $new_watched_at = $watched_at;
        } elseif ($previous === episode_status_done() && $existing !== '') {
            $new_watched_at = $existing;
        } else {
            $new_watched_at = date('Y-m-d');
        }
    } else {
        $new_watched_at = '';
    }

    $data[$key]['volumes'][$episode_index]['status']  = $status;
    $data[$key]['volumes'][$episode_index]['read_at'] = $new_watched_at;

    // Le tag « dernier épisode » est réévalué à chaque écriture : il ne dépend
    // que du statut de diffusion, mais rien ne coûte à le vérifier ici plutôt
    // que de le laisser dériver.
    $data[$key]['volumes'] = anime_refresh_last_episode(
        $data[$key]['volumes'],
        anime_airing_finished($data[$key])
    );

    replace_series_volumes($series_id, $data[$key]['volumes']);

    return ['success' => true, 'data' => $data, 'message' => ''];
}

// Applique un statut de visionnage (et sa date) à TOUS les épisodes d'une série.
// C'est le pendant de l'action « tout marquer comme terminé » des tomes : la
// mécanique est strictement la même, elle n'est donc pas réécrite ici.
//
// Écriture ciblée : s'appuie sur apply_status_to_all_volumes() (qui écrit
// déjà les épisodes une première fois), mais réapplique ensuite
// anime_refresh_last_episode() en mémoire pour le tag « dernier épisode ».
// Une SECONDE replace_series_volumes() est donc nécessaire après ce
// recalcul — sans elle, le tag recalculé resterait en mémoire sans jamais
// atteindre la base. Léger travail redondant (deux réécritures des mêmes
// lignes `volumes` en un seul appel), sans conséquence fonctionnelle.
function apply_status_to_all_episodes(array $data, string $series_id, string $status, ?string $watched_at = null): array {
    $found = find_series_by_id($data, $series_id);
    if (!$found) {
        return ['success' => false, 'data' => $data, 'message' => "Série introuvable."];
    }
    if (!is_anime($found['data'])) {
        return ['success' => false, 'data' => $data, 'message' => "Cette série n'est pas une série animée."];
    }

    $result = apply_status_to_all_volumes($data, $series_id, sanitize_episode_status($status), $watched_at);
    if (empty($result['success'])) {
        return ['success' => false, 'data' => $data, 'message' => $result['message'] ?? "Mise à jour impossible."];
    }

    $data = $result['data'];
    $key  = $found['key'];
    $data[$key]['volumes'] = anime_refresh_last_episode(
        $data[$key]['volumes'],
        anime_airing_finished($data[$key])
    );

    replace_series_volumes($series_id, $data[$key]['volumes']);

    return ['success' => true, 'data' => $data, 'message' => ''];
}

// Fait passer le PREMIER épisode non terminé en « terminé » (bouton « + »).
//
// Des clics successifs font donc progresser le visionnage épisode par épisode,
// là où le même bouton, sur un manga, ouvre la modale d'ajout de tomes. C'est le
// même geste pour deux collections qui ne s'alimentent pas de la même façon :
// on ajoute des tomes, on regarde des épisodes.
//
// Écriture ciblée : ne fait qu'appeler update_episode() ci-dessus, donc
// aucune écriture supplémentaire nécessaire à son propre niveau.
//
// Retour : ['success', 'data', 'message', 'episode_index', 'episode',
//           'counts' => ['total','watched','remaining']]
function anime_mark_next_episode(array $data, string $series_id): array {
    $found = find_series_by_id($data, $series_id);
    if (!$found) {
        return ['success' => false, 'data' => $data, 'message' => "Série introuvable."];
    }

    $key = $found['key'];
    if (!is_anime($data[$key])) {
        return ['success' => false, 'data' => $data, 'message' => "Cette série n'est pas une série animée."];
    }

    $index = anime_next_episode_index($data[$key]);
    if ($index < 0) {
        return [
            'success' => false,
            'data'    => $data,
            'message' => "Tous les épisodes de cette série sont déjà marqués comme vus.",
        ];
    }

    $result = update_episode($data, $series_id, $index, episode_status_done(), null);
    if (empty($result['success'])) {
        return $result;
    }

    $data    = $result['data'];
    $episode = $data[$key]['volumes'][$index];

    return [
        'success'       => true,
        'data'          => $data,
        'message'       => "Épisode " . $episode['number'] . " marqué comme vu.",
        'episode_index' => $index,
        'episode'       => $episode,
        'counts'        => anime_episode_counts($data[$key]),
    ];
}
