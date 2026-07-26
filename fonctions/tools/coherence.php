<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/coherence.php — Outil « Incohérences »
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

// ── Décompte de tomes de référence pour une série (Babengas → MU) ─────────────
// Renvoie la meilleure source disponible pour le NOMBRE DE TOMES paru :
//   1. Babengas (décompte VF réellement paru, lu dans le cache Babelio — aucun
//      appel réseau : les données proviennent des campagnes Babengas). Priorité,
//      car c'est le décompte le plus fiable pour l'édition française.
//   2. Fallback MangaUpdates (décompte souvent VO), issu du cache pré-chargé.
//
// Un one-shot (fiche de TOME Babelio, /livres/…) n'a pas de décompte en cache
// mais vaut par définition un tome ; on le renseigne localement.
//
// Retourne ['volumes'=>int, 'source'=>'babelio'|'babelio-oneshot'|'mangaupdates',
//           'source_label'=>string] ou null si aucune référence exploitable.
function coherence_reference_volumes(array $series, array $mu_cache_map = []): ?array {
    // 1) Babengas / Babelio (VF) — prioritaire, s'il est configuré et disponible.
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

    // 2) Fallback MangaUpdates (décompte souvent VO).
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
function check_collection_coherence(array $data): array {
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

    return $issues;
}


// ── Édition rapide depuis l'outil « Incohérences » ──────────────────────────
// Applique en une passe : statut de publication, « lue ailleurs », suppressions
// de tomes (par index), mises à jour de tomes existants et ajouts de tomes.
// Retourne la série mise à jour pour permettre un rafraîchissement sans rechargement.
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

    save_data($data);

    return ['success' => true, 'series' => $data[$idx]];
}
