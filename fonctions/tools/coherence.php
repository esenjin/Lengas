<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/coherence.php — Outil « Vérification des mangas »
//
// Analyse des anomalies internes de la collection (doublons, numéros
// manquants, mauvais tag « dernier tome », statut divergent de MangaUpdates,
// prêts orphelins…) et génération des notifications par série.
// ────────────────────────────────────────────────────────────────────────────

// Générer les notifications pour une série
function generate_notifications(array $volumes, ?int $ref_volumes = null): array {
    $notifications = [];
    if (empty($volumes)) return $notifications;

    $numbers      = array_map(fn($v) => $v['number'], $volumes);
    $min          = min($numbers);
    $max          = max($numbers);
    $last_volumes = array_filter($volumes, fn($v) => !empty($v['last']));

    $missing = [];
    for ($i = $min; $i <= $max; $i++) {
        if (!in_array($i, $numbers)) $missing[] = $i;
    }
    if (!empty($missing)) {
        $notifications[] = count($missing) == 1
            ? "Attention, le tome " . implode(', ', $missing) . " est manquant."
            : "Attention, les tomes " . implode(', ', $missing) . " sont manquants.";
    }

    if (!empty($last_volumes)) {
        $last_numbers = array_map(fn($v) => $v['number'], $last_volumes);
        foreach ($last_numbers as $num) {
            if ($num != $max) {
                $notifications[] = "Attention, le tome tagué dernier ($num) est incorrect.";
            }
        }
        if (count($last_volumes) > 1) {
            $notifications[] = "Attention, plusieurs tomes sont tagués comme dernier (" . implode(', ', $last_numbers) . ").";
        }
    }

    if ($ref_volumes !== null && $max > $ref_volumes) {
        $notifications[] = "Attention, votre série contient plus de tomes que ce qui est indiqué sur MangaUpdates.";
    }
    if ($ref_volumes !== null && $max < $ref_volumes) {
        $missing = range($max + 1, $ref_volumes);
        $notifications[] = count($missing) == 1
            ? "Attention, il manque le tome " . implode(', ', $missing) . " pour compléter cette série."
            : "Attention, il manque les tomes " . implode(', ', $missing) . " pour compléter cette série.";
    }
    if ($ref_volumes !== null && $max == $ref_volumes && empty($last_volumes)) {
        $notifications[] = "Attention, cette série semble complète mais le dernier tome n'est pas tagué comme tel.";
    }

    return $notifications;
}

// ── Décompte de tomes de référence pour une série (Syngas → Babengas → MU) ────
// Renvoie la meilleure source disponible pour le NOMBRE DE TOMES paru :
//   1. Syngas (décompte VF mutualisé, lu dans le cache local
//      syngas_volumes_count — aucun appel réseau : la valeur est alimentée
//      par la recherche/réception, jamais interrogée à chaud ici). Priorité
//      la plus haute pour une série liée : c'est la source la plus
//      spécifiquement pensée pour le suivi VF (section 6.4 du cahier des
//      charges Syngas).
//   2. Babengas (décompte VF réellement paru, lu dans le cache Babelio — aucun
//      appel réseau : les données proviennent des campagnes Babengas).
//   3. Fallback MangaUpdates (décompte souvent VO), issu du cache pré-chargé.
//
// Un one-shot (fiche de TOME Babelio, /livres/…) n'a pas de décompte en cache
// mais vaut par définition un tome ; on le renseigne localement.
//
// Retourne ['volumes'=>int, 'source'=>'syngas'|'babelio'|'babelio-oneshot'|'mangaupdates',
//           'source_label'=>string] ou null si aucune référence exploitable.
function coherence_reference_volumes(array $series, array $mu_cache_map = []): ?array {
    // 1) Syngas (VF mutualisée) — prioritaire, si la série y est liée et
    // qu'un décompte est déjà connu localement.
    if (!empty($series['syngas_uid']) && isset($series['syngas_volumes_count']) && $series['syngas_volumes_count'] !== null && (int)$series['syngas_volumes_count'] > 0) {
        return [
            'volumes'      => (int)$series['syngas_volumes_count'],
            'source'       => 'syngas',
            'source_label' => 'Syngas (VF mutualisée)',
        ];
    }

    // 2) Babengas / Babelio (VF) — s'il est configuré et disponible.
    if (!function_exists('babengas_enabled') || babengas_enabled()) {
        $burl = trim((string)($series['babelio_url'] ?? ''));
        if ($burl !== '') {
            // Fiche SÉRIE : décompte VF issu du cache Babelio (campagnes Babengas).
            // max_age = 0 → on accepte tout décompte déjà connu, quelle que soit
            // son ancienneté (comme babengas_cached_incomplete) : mieux vaut un
            // décompte VF un peu ancien qu'un fallback VO. Le rafraîchissement
            // reste du ressort des campagnes Babengas.
            if (function_exists('babelio_get_volumes_for_url')) {
                $cached = babelio_get_volumes_for_url($burl, 0);
                if ($cached !== null && (int)$cached['nb_tomes'] > 0) {
                    return [
                        'volumes'      => (int)$cached['nb_tomes'],
                        'source'       => 'babelio',
                        'source_label' => 'Babelio (VF, via Babengas)',
                    ];
                }
            }
            // Fiche de TOME : one-shot, résolu localement (un tome paru).
            if (function_exists('babelio_is_livre_url') && babelio_is_livre_url($burl)) {
                return [
                    'volumes'      => 1,
                    'source'       => 'babelio-oneshot',
                    'source_label' => 'Babelio (one-shot)',
                ];
            }
        }
    }

    // 3) Fallback MangaUpdates (décompte souvent VO).
    if (!empty($series['mangaupdates_url']) && function_exists('mangaupdates_get_id_from_url')) {
        $mu_id = mangaupdates_get_id_from_url($series['mangaupdates_url']);
        if ($mu_id !== null) {
            $mu_info = $mu_cache_map[$mu_id]
                ?? (function_exists('mangaupdates_get_volumes') ? mangaupdates_get_volumes($mu_id) : null);
            if ($mu_info !== null && ($mu_info['volumes'] ?? null) !== null && (int)$mu_info['volumes'] > 0) {
                return [
                    'volumes'      => (int)$mu_info['volumes'],
                    'source'       => 'mangaupdates',
                    'source_label' => 'MangaUpdates',
                ];
            }
        }
    }

    return null;
}

// Vérification des incohérences de la collection
//
// Couvre désormais les deux types : les règles mangas (ci-dessous) restent
// strictement inchangées, appliquées à la seule Mangathèque ; les règles
// animés (check_anime_coherence()) sont calculées séparément puis fusionnées.
// Chaque ligne du résultat porte un tag 'type' ('manga' ou 'anime'), lu par
// le front pour le badge et le filtre par type.
function check_collection_coherence(array $data): array {
    $manga_issues = check_manga_coherence($data);
    $anime_issues = check_anime_coherence($data);
    return array_merge($manga_issues, $anime_issues);
}

// ── Règles mangas (inchangées depuis avant la V4) ────────────────────────────
function check_manga_coherence(array $data): array {
    // Périmètre : ces vérifications ne concernent que la Mangathèque.
    // $data est reçu PAR VALEUR : le filtrage ne touche que cette copie locale,
    // le tableau de l'appelant reste intact pour d'éventuelles écritures
    // ultérieures, qui passent toujours par les fonctions ciblées de
    // config.php sur les seules séries concernées.
    $data = series_of_type($data, 'manga');

    $issues = [];

    // ── Pré-charger le cache MangaUpdates en lot (appel réseau si nécessaire) ──
    // Même logique que get_incomplete_series : on récupère les données MU pour
    // toutes les séries qui ont une URL, afin que les checks mu_* fonctionnent
    // sans avoir eu besoin de lancer "Séries incomplètes" au préalable.
    $mu_cache_map = [];
    if (function_exists('mangaupdates_get_id_from_url') && function_exists('mangaupdates_get_volumes_batch')) {
        $ids_needed = [];
        foreach ($data as $series) {
            $url = $series['mangaupdates_url'] ?? '';
            if ($url !== '') {
                $id = mangaupdates_get_id_from_url($url);
                if ($id !== null) $ids_needed[] = $id;
            }
        }
        $mu_cache_map = mangaupdates_get_volumes_batch($ids_needed);
    }

    foreach ($data as $series) {
        $series_issues = [];
        $name    = $series['name'] ?? '(sans nom)';
        $volumes = $series['volumes'] ?? [];

        if (empty($volumes)) {
            $series_issues[] = ['type' => 'no_volumes', 'message' => 'La série ne possède aucun tome.'];
            $issues[] = ['series' => $name, 'series_id' => $series['id'], 'problems' => $series_issues];
            continue;
        }

        $numbers      = array_map(fn($v) => (int)$v['number'], $volumes);
        $max          = max($numbers);
        $min          = min($numbers);
        $last_volumes = array_values(array_filter($volumes, fn($v) => !empty($v['last'])));

        if (count($last_volumes) > 1) {
            $last_nums = array_map(fn($v) => $v['number'], $last_volumes);
            $series_issues[] = ['type' => 'multiple_last', 'message' => 'Plusieurs tomes sont tagués comme dernier : tome(s) ' . implode(', ', $last_nums) . '.'];
        }

        foreach ($last_volumes as $lv) {
            if ((int)$lv['number'] !== $max) {
                $series_issues[] = ['type' => 'wrong_last', 'message' => 'Le tome ' . $lv['number'] . ' est tagué dernier mais le tome le plus élevé est le ' . $max . '.'];
            }
        }

        $missing = [];
        for ($i = $min; $i <= $max; $i++) {
            if (!in_array($i, $numbers, true)) $missing[] = $i;
        }
        if (!empty($missing)) {
            $series_issues[] = ['type' => 'missing_volumes', 'message' => 'Tome(s) manquant(s) dans la séquence : ' . implode(', ', $missing) . '.'];
        }

        $duplicates = array_keys(array_filter(array_count_values($numbers), fn($c) => $c > 1));
        if (!empty($duplicates)) {
            $series_issues[] = ['type' => 'duplicate_volumes', 'message' => 'Numéro(s) de tome en double : ' . implode(', ', $duplicates) . '.'];
        }

        $invalid = array_filter($numbers, fn($n) => $n <= 0);
        if (!empty($invalid)) {
            $series_issues[] = ['type' => 'invalid_number', 'message' => 'Tome(s) avec un numéro invalide (≤ 0) : ' . implode(', ', $invalid) . '.'];
        }

        $status = $series['status'] ?? '';
        if ($status === 'terminée' && empty($last_volumes)) {
            $series_issues[] = ['type' => 'finished_no_last', 'message' => "La série est marquée comme terminée mais aucun tome n'est tagué dernier."];
        }

        if (!empty($last_volumes) && $status !== 'terminée' && $status !== 'abandonnée' && $status !== 'en pause') {
            $series_issues[] = ['type' => 'last_but_not_finished', 'message' => "Un tome est tagué dernier mais la série n'est pas marquée comme terminée."];
        }

        if ($min > 1) {
            $series_issues[] = ['type' => 'sequence_not_starting_at_1', 'message' => 'La collection ne commence pas au tome 1 (premier tome possédé : ' . $min . ').'];
        }

        // ── Tomes non lus dans une série "lue ailleurs" ──────────────────────
        if (!empty($series['read_elsewhere'])) {
            $unread = array_values(array_filter($volumes, fn($v) => ($v['status'] ?? '') !== 'terminé'));
            if (!empty($unread)) {
                $unread_nums = array_map(fn($v) => $v['number'], $unread);
                $series_issues[] = [
                    'type'    => 'read_elsewhere_unread',
                    'message' => 'Série marquée « lue ailleurs » mais ' . count($unread_nums) . ' tome(s) non lu(s) : ' . implode(', ', $unread_nums) . '.',
                ];
            }
        }

        // ── Cohérence avec le statut de publication MangaUpdates ────────────────
        // On utilise d'abord mu_cache_map (pré-chargé en lot, avec appels réseau
        // si nécessaire), puis on se rabat sur mangaupdates_get_cached_status en
        // lecture seule si la série n'y figure pas (URL invalide, échec réseau…).
        //
        // ⚠️ Deux natures de contrôle, deux sources :
        //   • Le STATUT de publication (en cours / terminé) vient TOUJOURS de
        //     MangaUpdates : Babengas ne remonte pas le statut (Babelio affiche
        //     « En cours » même sur des séries terminées).
        //   • Le NOMBRE DE TOMES de référence privilégie Babengas (décompte VF
        //     réellement paru, via le cache Babelio) et se rabat sur MangaUpdates
        //     quand Babengas n'a pas de décompte pour cette série.
        if (!empty($series['mangaupdates_url']) && function_exists('mangaupdates_get_id_from_url')) {
            $mu_id = mangaupdates_get_id_from_url($series['mangaupdates_url']);
            if ($mu_id !== null) {
                $mu_info = $mu_cache_map[$mu_id]
                    ?? (function_exists('mangaupdates_get_volumes') ? mangaupdates_get_volumes($mu_id) : null);

                if ($mu_info !== null) {
                    $mu_completed     = !empty($mu_info['completed']);
                    $mu_volumes       = $mu_info['volumes'] ?? null;
                    $mu_status_text   = $mu_info['status'] ?? null;
                    $is_finished_here = ($status === 'terminée') || !empty($last_volumes);

                    // mu_still_ongoing et mu_complete_unmarked nécessitent le statut textuel
                    if ($mu_status_text !== null && $mu_status_text !== '') {
                        // Une série « Cancelled » (arrêtée) est, dans les faits, terminée :
                        // on ne déclenche l'alerte que si la publication est réellement en cours.
                        $mu_cancelled = (stripos($mu_status_text, 'cancel') !== false);
                        if ($is_finished_here && !$mu_completed && !$mu_cancelled) {
                            $series_issues[] = ['type' => 'mu_still_ongoing', 'message' => 'Vous avez marqué la série comme terminée (ou tagué un tome comme dernier), mais MangaUpdates indique une publication toujours en cours (« ' . $mu_status_text . ' »).'];
                        }

                        if ($mu_completed && !$is_finished_here && $mu_volumes !== null && $max >= (int)$mu_volumes) {
                            $series_issues[] = ['type' => 'mu_complete_unmarked', 'message' => 'MangaUpdates indique la série comme terminée (« ' . $mu_status_text . ' », ' . (int)$mu_volumes . ' tomes) et vous semblez la posséder entièrement, mais elle n\'est pas marquée comme terminée.'];
                        }
                    }
                }
            }
        }

        // ── Nombre de tomes de référence : Babengas (VF) prioritaire, sinon MU ──
        // On centralise le décompte de référence via coherence_reference_volumes(),
        // qui privilégie le cache Babelio (données Babengas, VF réellement parue)
        // et se rabat sur MangaUpdates. Le contrôle « vous possédez plus de tomes
        // que la référence » s'appuie ensuite sur cette source, en le mentionnant
        // dans le message pour lever toute ambiguïté.
        //
        // On utilise count($volumes) pour être cohérent avec la modale
        // « Séries incomplètes ».
        $ref = coherence_reference_volumes($series, $mu_cache_map);
        if ($ref !== null) {
            $owned_count = count($volumes);
            if ($owned_count > $ref['volumes']) {
                $series_issues[] = [
                    'type'    => 'ref_more_volumes',
                    'message' => 'Vous possédez plus de tomes (' . $owned_count . ') que ce qu\'indique ' . $ref['source_label'] . ' (' . $ref['volumes'] . ').',
                ];
            }
        }

        if (!empty($series_issues)) {
            $issues[] = [
                'series'           => $name,
                'series_id'        => $series['id'],
                'mangaupdates_url' => $series['mangaupdates_url'] ?? '',
                'babelio_url'      => $series['babelio_url'] ?? '',
                'problems'         => $series_issues,
            ];
        }
    }

    // ── Prêts vers des séries inexistantes ou "lues ailleurs" ────────────────
    if (function_exists('load_loans')) {
        $loans          = load_loans();
        $loans_by_series = [];
        foreach ($loans as $loan) {
            $loans_by_series[$loan['series_id']][] = $loan;
        }

        // Indexer les séries existantes par ID pour recherche rapide
        $series_map = [];
        foreach ($data as $s) {
            $series_map[$s['id']] = $s;
        }

        foreach ($loans_by_series as $sid => $sid_loans) {
            $n = count($sid_loans);
            $vols = implode(', ', array_map(fn($l) => 'T' . $l['volume_number'], $sid_loans));

            if (!isset($series_map[$sid])) {
                // Série supprimée
                $issues[] = [
                    'series'    => '(Série supprimée)',
                    'series_id' => $sid,
                    'problems'  => [[
                        'type'    => 'loan_deleted_series',
                        'message' => $n . ' tome(s) prêté(s) (' . $vols . ') pour une série qui n\'existe plus dans la collection.',
                    ]],
                ];
            } elseif (!empty($series_map[$sid]['read_elsewhere'])) {
                // Série marquée "lue ailleurs" (physiquement absente)
                $issues[] = [
                    'series'    => $series_map[$sid]['name'],
                    'series_id' => $sid,
                    'problems'  => [[
                        'type'    => 'loan_read_elsewhere',
                        'message' => $n . ' tome(s) prêté(s) (' . $vols . ') pour une série marquée « lue ailleurs » — elle n\'est pas physiquement dans votre collection.',
                    ]],
                ];
            }
        }
    }

    // Tag de type : cette fonction ne traite que la Mangathèque, chaque ligne
    // porte donc 'manga' (lu par le front pour le badge et le filtre par type).
    foreach ($issues as &$issue) {
        $issue['type'] = 'manga';
    }
    unset($issue);

    return $issues;
}

// ────────────────────────────────────────────────────────────────────────────
// Règles animés
// ────────────────────────────────────────────────────────────────────────────
// Périmètre disjoint des mangas : numéro d'épisode manquant/doublon (même
// logique que les tomes), mauvais tag « dernier épisode », statut de diffusion
// divergent d'Anilist, série sans anilist_id, épisode terminé sans date,
// vignette Anilist introuvable. Aucune anomalie MangaUpdates/Babelio/prêts/
// collector : ces notions n'ont pas de sens pour un animé.
//
// Toute correction reste soumise aux mêmes règles que le reste du site : ce
// qui vient d'Anilist se corrige à la source (le rapport renvoie vers la
// fiche Anilist, jamais de saisie manuelle) ; seuls le statut de visionnage et
// sa date, de nature purement locale, sont proposés à la correction via la
// modale dédiée (cf. coherence_quick_edit_anime()).
function check_anime_coherence(array $data): array {
    $data = series_of_type($data, 'anime');

    $issues = [];

    foreach ($data as $series) {
        $series_issues = [];
        $name     = $series['name'] ?? '(sans nom)';
        $episodes = $series['volumes'] ?? [];

        // ── Série sans identifiant Anilist ───────────────────────────────────
        // Un animé s'importe toujours depuis Anilist : sans identifiant, la
        // série ne peut plus être ni synchronisée, ni revérifiée. Purement
        // informatif : rien à corriger localement, l'anomalie vient d'une
        // absence de source, pas d'une divergence avec elle.
        $anilist_id = (int)($series['anilist_id'] ?? 0);
        if ($anilist_id <= 0) {
            $series_issues[] = [
                'type'    => 'anime_no_anilist_id',
                'message' => "Cette série animée n'a aucun identifiant Anilist : elle ne peut plus être synchronisée ni revérifiée.",
            ];
        }

        if (empty($episodes)) {
            if (!empty($series_issues)) {
                $issues[] = [
                    'series'     => $name,
                    'series_id'  => $series['id'],
                    'anilist_id' => $anilist_id,
                    'anilist_url'=> $series['anilist_url'] ?? '',
                    'problems'   => $series_issues,
                ];
            }
            continue;
        }

        $numbers      = array_map(fn($e) => (int)$e['number'], $episodes);
        $max          = max($numbers);
        $min          = min($numbers);
        $last_episodes = array_values(array_filter($episodes, fn($e) => !empty($e['last'])));

        // ── Doublons / manquants dans la séquence (localement corrigible : le
        // numéro d'un épisode ne se change pas, mais le signalement aide à
        // repérer un import corrompu qu'un ré-import ou une revérification
        // résoudra). ──────────────────────────────────────────────────────────
        $missing = [];
        for ($i = $min; $i <= $max; $i++) {
            if (!in_array($i, $numbers, true)) $missing[] = $i;
        }
        if (!empty($missing)) {
            $series_issues[] = ['type' => 'anime_missing_episodes', 'message' => 'Épisode(s) manquant(s) dans la séquence : ' . implode(', ', $missing) . '.'];
        }

        $duplicates = array_keys(array_filter(array_count_values($numbers), fn($c) => $c > 1));
        if (!empty($duplicates)) {
            $series_issues[] = ['type' => 'anime_duplicate_episodes', 'message' => 'Numéro(s) d\'épisode en double : ' . implode(', ', $duplicates) . '.'];
        }

        // ── Tag « dernier épisode » mal placé (localement corrigible : la
        // synchronisation ou la modale dédiée réévaluent ce tag). ────────────
        if (count($last_episodes) > 1) {
            $last_nums = array_map(fn($e) => $e['number'], $last_episodes);
            $series_issues[] = [
                'type'    => 'anime_multiple_last',
                'message' => 'Plusieurs épisodes sont tagués comme dernier : épisode(s) ' . implode(', ', $last_nums) . '.',
                'fixable' => true,
            ];
        }
        foreach ($last_episodes as $le) {
            if ((int)$le['number'] !== $max) {
                $series_issues[] = [
                    'type'    => 'anime_wrong_last',
                    'message' => 'L\'épisode ' . $le['number'] . ' est tagué dernier mais l\'épisode le plus élevé est le ' . $max . '.',
                    'fixable' => true,
                ];
            }
        }

        // ── Épisode « terminé » sans date de visionnage (localement
        // corrigible : la modale dédiée permet de renseigner une date). ──────
        $done = function_exists('episode_status_done') ? episode_status_done() : 'terminé';
        $watched_without_date = array_values(array_filter($episodes, fn($e) => ($e['status'] ?? '') === $done && trim((string)($e['read_at'] ?? '')) === ''));
        if (!empty($watched_without_date)) {
            $nums = array_map(fn($e) => $e['number'], $watched_without_date);
            $series_issues[] = [
                'type'    => 'anime_done_without_date',
                'message' => count($nums) === 1
                    ? "L'épisode " . $nums[0] . " est marqué terminé mais n'a aucune date de visionnage."
                    : "Les épisodes " . implode(', ', $nums) . " sont marqués terminés mais n'ont aucune date de visionnage.",
                'fixable' => true,
            ];
        }

        // ── Statut de diffusion divergent d'Anilist ──────────────────────────
        // Purement informatif ici : la comparaison fine (avec appel réseau et
        // cache 24h) relève de l'outil de revérification et de la
        // synchronisation automatique. On se contente de signaler les
        // cas structurellement incohérents, sans aucun appel réseau :
        //   • diffusion marquée terminée sans le moindre épisode tagué dernier ;
        //   • un épisode est tagué dernier mais la diffusion n'est ni terminée,
        //     ni en pause, ni abandonnée.
        $status = $series['status'] ?? '';
        if ($status === 'terminée' && empty($last_episodes)) {
            $series_issues[] = ['type' => 'anime_finished_no_last', 'message' => "La diffusion est marquée comme terminée mais aucun épisode n'est tagué dernier."];
        }
        if (!empty($last_episodes) && !in_array($status, ['terminée', 'abandonnée', 'en pause'], true)) {
            $series_issues[] = ['type' => 'anime_last_but_not_finished', 'message' => "Un épisode est tagué dernier mais la diffusion n'est pas marquée comme terminée."];
        }

        // ── Vignette Anilist introuvable ──────────────────────────────────────
        // Le champ est renseigné mais le fichier a disparu du serveur (purge
        // manuelle, restauration partielle…). Purement informatif : se corrige
        // via une revérification, pas de saisie manuelle possible.
        $anilist_image = trim((string)($series['anilist_image'] ?? ''));
        if ($anilist_image !== '' && !file_exists($anilist_image)) {
            $series_issues[] = ['type' => 'anime_cover_missing', 'message' => "La vignette Anilist enregistrée est introuvable sur le serveur."];
        }

        if (!empty($series_issues)) {
            $issues[] = [
                'series'      => $name,
                'series_id'   => $series['id'],
                'anilist_id'  => $anilist_id,
                'anilist_url' => $series['anilist_url'] ?? '',
                'problems'    => $series_issues,
            ];
        }
    }

    foreach ($issues as &$issue) {
        $issue['type'] = 'anime';
    }
    unset($issue);

    return $issues;
}

// Une série animée a-t-elle au moins une anomalie « localement corrigible » ?
// Sert à décider, côté front, si le bouton « Corriger » doit être proposé en
// plus du badge Anilist (toujours présent, lui, dès qu'un anilist_id existe).
function anime_coherence_has_fixable(array $problems): bool {
    foreach ($problems as $p) {
        if (!empty($p['fixable'])) return true;
    }
    return false;
}

// ────────────────────────────────────────────────────────────────────────────
// Édition rapide d'une série animée depuis l'outil « Vérification des mangas »
// ────────────────────────────────────────────────────────────────────────────
// Volontairement plus étroit que coherence_quick_edit() (mangas) : seuls le
// statut de visionnage et la date de chaque épisode sont modifiables. Aucun
// ajout ni suppression d'épisode (Anilist en est la seule source), aucune
// case « dernier épisode » (le tag se réévalue tout seul, cf.
// anime_refresh_last_episode() dans fonctions/episodes.php).
//
// Format attendu : $input['series_id'], $input['episodes_updates'] (JSON :
// [{ "index": int, "status": "à voir"|"en cours"|"terminé", "watched_at": "" }]).
//
// Écriture ciblée : update_episode() écrit elle-même les épisodes de la
// série en base via replace_series_volumes() à chaque itération — aucune
// réécriture supplémentaire n'est nécessaire ici.
function coherence_quick_edit_anime(array &$data, array $input): array {
    $series_id = trim($input['series_id'] ?? '');
    $found     = find_series_by_id($data, $series_id);
    if (!$found) {
        return ['success' => false, 'message' => 'Série introuvable.'];
    }
    if (!is_anime($found['data'])) {
        return ['success' => false, 'message' => "Cette série n'est pas une série animée."];
    }

    $updates = json_decode($input['episodes_updates'] ?? '[]', true);
    if (!is_array($updates)) $updates = [];

    foreach ($updates as $u) {
        $index      = (int)($u['index'] ?? -1);
        $status     = (string)($u['status'] ?? '');
        $watched_at = isset($u['watched_at']) ? (string)$u['watched_at'] : null;
        if ($index < 0) continue;

        $result = update_episode($data, $series_id, $index, $status, $watched_at);
        if (!empty($result['success'])) {
            $data = $result['data'];
        }
    }

    $refound = find_series_by_id($data, $series_id);
    return ['success' => true, 'series' => $refound ? $refound['data'] : null];
}

// ── Édition rapide depuis l'outil « Vérification des mangas » ─────────────────
// Applique en une passe : statut de publication, « lue ailleurs », suppressions
// de tomes (par index), mises à jour de tomes existants et ajouts de tomes.
// Retourne la série mise à jour pour permettre un rafraîchissement sans rechargement.
//
// Écriture ciblée : upsert_series_row() sur la seule ligne série modifiée
// (statut, lue ailleurs) et replace_series_volumes() sur les seuls tomes de
// cette série — jamais de resynchronisation de la collection complète.
function coherence_quick_edit(array &$data, array $input): array {
    $series_id      = trim($input['series_id'] ?? '');
    $new_status     = trim($input['series_status'] ?? '');
    $read_elsewhere = isset($input['read_elsewhere']) ? (bool)$input['read_elsewhere'] : null;

    $series_ref = find_series_by_id($data, $series_id);
    if (!$series_ref) {
        return ['success' => false, 'message' => 'Série introuvable.'];
    }
    $idx = $series_ref['key'];

    // Statut de publication
    if ($new_status !== '') {
        $data[$idx]['status'] = $new_status;
    }

    // Lue ailleurs
    if ($read_elsewhere !== null) {
        $data[$idx]['read_elsewhere'] = $read_elsewhere;
    }

    // Suppressions de tomes (index décroissants pour ne pas décaler la liste)
    $delete_indexes = json_decode($input['delete_volumes'] ?? '[]', true);
    if (is_array($delete_indexes) && !empty($delete_indexes)) {
        rsort($delete_indexes);
        foreach ($delete_indexes as $vi) {
            if (isset($data[$idx]['volumes'][(int)$vi])) {
                array_splice($data[$idx]['volumes'], (int)$vi, 1);
            }
        }
    }

    // Mises à jour des tomes existants
    $volumes_updates = json_decode($input['volumes_updates'] ?? '[]', true);
    if (is_array($volumes_updates)) {
        foreach ($volumes_updates as $vu) {
            $vi = (int)($vu['index'] ?? -1);
            if (!isset($data[$idx]['volumes'][$vi])) continue;

            $new_vol_status  = $vu['status'] ?? $data[$idx]['volumes'][$vi]['status'];
            $prev_vol_status = $data[$idx]['volumes'][$vi]['status'];
            $data[$idx]['volumes'][$vi]['status'] = $new_vol_status;
            $data[$idx]['volumes'][$vi]['last']   = !empty($vu['last']);

            // read_at : on date le passage à « terminé » (ou on comble une
            // ancienne donnée jamais migrée), on efface quand on en sort.
            if ($new_vol_status === 'terminé') {
                $prev_read_at = $data[$idx]['volumes'][$vi]['read_at'] ?? '';
                if ($prev_vol_status !== 'terminé' || $prev_read_at === '') {
                    $data[$idx]['volumes'][$vi]['read_at'] = date('Y-m-d');
                }
            } else {
                $data[$idx]['volumes'][$vi]['read_at'] = '';
            }
        }
    }

    // Ajouts de tomes
    $add_volumes = json_decode($input['add_volumes'] ?? '[]', true);
    if (is_array($add_volumes)) {
        $existing_numbers = array_column($data[$idx]['volumes'], 'number');
        foreach ($add_volumes as $av) {
            $num = (int)($av['number'] ?? 0);
            if ($num > 0 && !in_array($num, $existing_numbers, true)) {
                $av_status = $av['status'] ?? 'à lire';
                $data[$idx]['volumes'][] = [
                    'number'    => $num,
                    'status'    => $av_status,
                    'collector' => false,
                    'last'      => !empty($av['last']),
                    'added_at'  => date('Y-m-d'),
                    'read_at'   => ($av_status === 'terminé') ? date('Y-m-d') : '',
                ];
                $existing_numbers[] = $num;
            }
        }
        usort($data[$idx]['volumes'], fn($a, $b) => $a['number'] - $b['number']);
    }

    // Synchroniser le statut de la série avec l'état du tag "dernier tome",
    // comme le fait update_volume() (fonctions/volumes.php) — sans quoi un
    // tome coché/décoché "dernier" depuis cette modale peut se retrouver en
    // désaccord avec le statut, et se faire aussitôt re-signaler par l'outil
    // ("Un tome est tagué dernier mais la série n'est pas marquée comme
    // terminée."). On ne touche cependant pas un statut déjà "abandonnée" ou
    // "en pause" fourni explicitement ci-dessus (ligne "Statut de
    // publication") : seule l'oscillation "en cours" <-> "terminée" est prise
    // en charge automatiquement, exactement comme côté fiche série.
    if ($new_status === '') {
        $current_series_status = $data[$idx]['status'] ?? 'en cours';
        $has_last = false;
        foreach ($data[$idx]['volumes'] as $v) {
            if (!empty($v['last'])) { $has_last = true; break; }
        }

        if ($has_last && $current_series_status === 'en cours') {
            $data[$idx]['status'] = 'terminée';
        }
        if (!$has_last && $current_series_status === 'terminée') {
            $data[$idx]['status'] = 'en cours';
        }
    }

    upsert_series_row($data[$idx]);
    replace_series_volumes($series_id, $data[$idx]['volumes']);

    return ['success' => true, 'series' => $data[$idx]];
}
