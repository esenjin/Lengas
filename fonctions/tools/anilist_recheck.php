<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/anilist_recheck.php — Outil « Vérification des animés »
//
// Complète la synchronisation automatique : là où celle-ci ne touche qu'aux
// épisodes et au statut de diffusion, cet outil compare TOUS les autres
// champs factuels d'une série animée à sa fiche Anilist actuelle — titres
// alternatifs, studios, format, genres, nombre d'épisodes annoncé, vignette
// Anilist — et ne les corrige qu'après validation explicite, série par série.
//
// Périmètre strictement disjoint de la synchronisation automatique (voir
// fonctions/tools/anilist_sync.php) : aucune des deux mécaniques ne touche
// aux champs de l'autre. Les épisodes eux-mêmes ne sont PAS reconstruits
// ici — un nombre d'épisodes modifié est seulement SIGNALÉ, la mise à jour
// de la liste d'épisodes proprement dite reste du ressort de la synchro
// automatique (ou de la prochaine synchro éligible).
//
// Ne sont JAMAIS proposés à l'écrasement : titre choisi, vignette
// personnalisée, note, coches mature / favori / visionnage abandonné,
// éditions physiques, rewatch_count. Ces champs restent la propriété de
// l'utilisateur.
//
// Dépendances : includes/anilist.php (connecteur, anilist_fetch_media_batch),
// fonctions/anime.php (anime_download_cover, anime_purge_cover, is_anime),
// includes/helpers.php (find_series_by_id, series_alt_titles), config.php
// (load_data(), upsert_series_row()).
// ────────────────────────────────────────────────────────────────────────────

// ────────────────────────────────────────────────────────────────────────────
// 1. Ciblage
// ────────────────────────────────────────────────────────────────────────────

// Séries animées avec un anilist_id exploitable : seules cibles possibles de
// la revérification (sans identifiant, rien à comparer).
function anilist_recheck_targets(array $data): array {
    return array_values(array_filter($data, function ($s) {
        return is_anime($s) && (int)($s['anilist_id'] ?? 0) > 0;
    }));
}

// ────────────────────────────────────────────────────────────────────────────
// 2. Comparaison champ par champ
// ────────────────────────────────────────────────────────────────────────────

// Compare deux listes de chaînes sans tenir compte de l'ordre ni de la casse.
// Renvoie true si elles diffèrent réellement (pas seulement dans l'ordre).
function anilist_recheck_lists_differ(array $a, array $b): bool {
    $norm = function (array $list): array {
        $out = array_map(fn($v) => mb_strtolower(trim((string)$v)), $list);
        $out = array_values(array_filter($out, fn($v) => $v !== ''));
        sort($out);
        return $out;
    };
    return $norm($a) !== $norm($b);
}

// Construit la liste des écarts détectés entre une série locale et sa fiche
// Anilist actuelle ($media = fiche normalisée par anilist_normalize_media()).
//
// Chaque écart : ['field' => clé technique, 'label' => libellé affichable,
//                 'before' => valeur actuelle (affichable), 'after' => valeur
//                 Anilist (affichable)]
//
// Ne compare QUE les champs factuels non personnalisables. Le titre n'est
// jamais proposé comme un écart à corriger : ce n'est pas une divergence, un
// nouveau titre alternatif est une ADDITION au sélecteur de titres, traitée
// à part (voir anilist_recheck_new_alt_titles ci-dessous).
function anilist_recheck_diff_series(array $series, array $media): array {
    $diffs = [];

    // ── Studios ──────────────────────────────────────────────────────────
    $local_studios = (array)($series['studios'] ?? []);
    $remote_studios = (array)($media['studios'] ?? []);
    if (anilist_recheck_lists_differ($local_studios, $remote_studios)) {
        $diffs[] = [
            'field'  => 'studios',
            'label'  => 'Studios',
            'before' => implode(', ', $local_studios) ?: '(aucun)',
            'after'  => implode(', ', $remote_studios) ?: '(aucun)',
        ];
    }

    // ── Format ───────────────────────────────────────────────────────────
    $local_format  = trim((string)($series['anime_format'] ?? ''));
    $remote_format = trim((string)($media['format'] ?? ''));
    if (strtoupper($local_format) !== strtoupper($remote_format) && $remote_format !== '') {
        $diffs[] = [
            'field'  => 'format',
            'label'  => 'Format',
            'before' => $series['categories'][0] ?? anilist_format_label($local_format),
            'after'  => $media['format_label'] ?? anilist_format_label($remote_format),
        ];
    }

    // ── Durée d'un épisode (alimente le temps de visionnage des statistiques) ───
    $local_duration  = max(0, (int)($series['episode_duration'] ?? 0));
    $remote_duration = max(0, (int)($media['duration'] ?? 0));
    if ($remote_duration > 0 && $remote_duration !== $local_duration) {
        $diffs[] = [
            'field'  => 'episode_duration',
            'label'  => 'Durée d\'un épisode',
            'before' => $local_duration > 0 ? $local_duration . ' min' : '(inconnue)',
            'after'  => $remote_duration . ' min',
        ];
    }

    // ── Genres ───────────────────────────────────────────────────────────
    $local_genres  = (array)($series['genres'] ?? []);
    $remote_genres = (array)($media['genres_fr'] ?? []);
    if (anilist_recheck_lists_differ($local_genres, $remote_genres)) {
        $diffs[] = [
            'field'  => 'genres',
            'label'  => 'Genres',
            'before' => implode(', ', array_filter($local_genres)) ?: '(aucun)',
            'after'  => implode(', ', $remote_genres) ?: '(aucun)',
        ];
    }

    // ── Statut de diffusion ──────────────────────────────────────────────
    // Rappel : la synchro automatique tient déjà ce champ à jour, mais
    // seulement pour les séries en cours de diffusion ET de visionnage.
    // Une série en pause, terminée ou abandonnée peut très bien avoir bougé
    // sur Anilist sans jamais être passée par la synchro automatique —
    // c'est justement le rôle de cet outil de le rattraper.
    $local_status  = trim((string)($series['status'] ?? ''));
    $remote_status = $media['status_tag'] ?? null;
    if ($remote_status !== null && $remote_status !== $local_status) {
        $diffs[] = [
            'field'  => 'status',
            'label'  => 'Statut de diffusion',
            'before' => $local_status !== '' ? ucfirst($local_status) : '(inconnu)',
            'after'  => ucfirst($remote_status),
        ];
    }

    // ── Nombre d'épisodes DIFFUSÉS ───────────────────────────────────────
    // Signalé, mais PAS appliqué ici : reconstruire la liste des épisodes est
    // le rôle de la synchro automatique, pas de cet outil. On se contente
    // d'avertir qu'un nouvel épisode a été diffusé sur Anilist.
    //
    // ⚠️ On compare à `aired_episodes` (déjà diffusés à ce jour), PAS à
    // `episodes` (le total annoncé pour toute la série). Pour une série TV en
    // cours de diffusion, `episodes` vaut souvent le total prévu (ex. 24) bien
    // avant que le 24e épisode n'existe : comparer à ce total ferait remonter
    // un écart en permanence tant que la série n'est pas terminée, alors que la
    // collection est parfaitement à jour avec ce qui a réellement été diffusé.
    $local_count  = count($series['volumes'] ?? []);
    $remote_count = $media['aired_episodes'] ?? null;
    if ($remote_count !== null && (int)$remote_count > $local_count) {
        $diffs[] = [
            'field'    => 'episode_count',
            'label'    => "Épisodes diffusés",
            'before'   => $local_count . ' en collection',
            'after'    => $remote_count . ' diffusé(s) à ce jour selon Anilist',
            'info_only'=> true, // pas de case "avant/après" à appliquer : voir plus bas
        ];
    }

    // ── Vignette Anilist ─────────────────────────────────────────────────
    // On ne compare pas les URL (Anilist en régénère régulièrement sans que
    // l'image change réellement) : on ne signale que l'absence totale de
    // vignette Anilist locale alors qu'Anilist en propose une, ou l'inverse.
    $has_local_cover  = trim((string)($series['anilist_image'] ?? '')) !== '' && file_exists($series['anilist_image']);
    $has_remote_cover = trim((string)($media['cover'] ?? '')) !== '';
    if (!$has_local_cover && $has_remote_cover) {
        $diffs[] = [
            'field'  => 'cover',
            'label'  => 'Vignette Anilist',
            'before' => 'Absente ou introuvable localement',
            'after'  => 'Une vignette est disponible sur Anilist',
        ];
    }

    return $diffs;
}

// Nouveaux titres alternatifs proposés par Anilist et absents des titres déjà
// connus de la série. Un ajout n'écrase jamais le titre choisi ni les titres
// déjà présents dans le sélecteur : il vient s'y ajouter.
function anilist_recheck_new_alt_titles(array $series, array $media): array {
    $known = array_map(fn($t) => mb_strtolower(trim($t)), series_alt_titles($series));
    $new   = [];
    foreach ((array)($media['alt_titles'] ?? []) as $title) {
        $title = trim((string)$title);
        if ($title === '') continue;
        if (!in_array(mb_strtolower($title), $known, true)) {
            $new[] = $title;
        }
    }
    return $new;
}

// ────────────────────────────────────────────────────────────────────────────
// 3. Construction du rapport
// ────────────────────────────────────────────────────────────────────────────

// Récupère les fiches Anilist courantes de toutes les cibles en un minimum de
// requêtes (anilist_fetch_media_batch : 50 identifiants par appel), puis
// construit le rapport ligne par ligne. $force = true ignore le cache 24h du
// connecteur (bouton "revérifier malgré le cache").
//
// $on_progress : callable(int $current, int $total, string $title) — reçoit
// une notification par série TRAITÉE (comparaison faite), pas par requête
// réseau (celles-ci sont groupées par lots de 50).
//
// Retour : ['success', 'report' => [par série avec écarts], 'unchanged_count',
//           'missing' => [séries dont la fiche Anilist est introuvable],
//           'errors' => [['title','message']], 'checked' => n]
//
// ⚠️ La clé de statut de CE tableau de retour s'appelle 'success' (convention
// des autres outils SSE du site — anilist_import_*, anilist_sync_*). Ne pas
// confondre avec les 'ok' internes plus bas, qui sont ceux du CONNECTEUR
// Anilist ($batch['ok'], $fetch['ok'], $cover['ok']) : deux conventions
// différentes qui cohabitent dans ce fichier, l'une pour ce que renvoie cette
// fonction, l'autre pour ce que renvoie includes/anilist.php.
function anilist_recheck_build_report(array $data, bool $force = false, $on_progress = null): array {
    $targets = anilist_recheck_targets($data);
    $total   = count($targets);

    if ($total === 0) {
        return ['success' => true, 'report' => [], 'unchanged_count' => 0, 'missing' => [], 'errors' => [], 'checked' => 0];
    }

    $ids = array_values(array_unique(array_map(fn($s) => (int)$s['anilist_id'], $targets)));

    $batch = anilist_fetch_media_batch($ids, $force);
    if (empty($batch['ok']) && empty($batch['media'])) {
        // Échec complet (aucune fiche récupérée, même partiellement) : on
        // remonte l'erreur telle quelle, rien à comparer.
        return [
            'success' => false, 'report' => [], 'unchanged_count' => 0, 'missing' => [],
            'errors' => [['title' => '', 'message' => $batch['error'] ?? "Impossible d'interroger Anilist."]],
            'checked' => 0,
        ];
    }

    $media_by_id = $batch['media'] ?? [];
    // ⚠️ anilist_fetch_media_batch() range dans 'missing' à la fois les fiches
    // réellement absentes chez Anilist ET, en cas d'échec réseau sur un chunk,
    // tous les identifiants jamais tentés ensuite. On ne peut pas distinguer
    // les deux depuis ce seul tableau : le libellé du rapport reste donc
    // volontairement prudent ("non récupérée"), sans affirmer que la fiche a
    // été supprimée d'Anilist quand il s'agit peut-être d'une simple panne
    // réseau partielle (auquel cas $batch['error'] est renseigné).
    $missing_ids       = array_flip($batch['missing'] ?? []);
    $batch_had_network_error = empty($batch['ok']) && !empty($batch['error']);

    $report          = [];
    $unchanged_count = 0;
    $missing         = [];
    $errors          = [];
    $i               = 0;

    foreach ($targets as $series) {
        $i++;
        if (is_callable($on_progress)) {
            call_user_func($on_progress, $i, $total, $series['name']);
        }

        $anilist_id = (int)$series['anilist_id'];

        if (isset($missing_ids[$anilist_id])) {
            $missing[] = [
                'id'         => $series['id'],
                'name'       => $series['name'],
                'anilist_id' => $anilist_id,
                'reason'     => $batch_had_network_error
                    ? ($batch['error'] ?? "Non récupérée (erreur réseau).")
                    : "Fiche introuvable sur Anilist (identifiant supprimé ?).",
            ];
            continue;
        }

        $media = $media_by_id[$anilist_id] ?? null;
        if ($media === null) {
            $errors[] = ['title' => $series['name'], 'message' => "Fiche Anilist non récupérée."];
            continue;
        }

        $diffs         = anilist_recheck_diff_series($series, $media);
        $new_titles    = anilist_recheck_new_alt_titles($series, $media);

        if (empty($diffs) && empty($new_titles)) {
            $unchanged_count++;
            continue;
        }

        $report[] = [
            'series_id'      => $series['id'],
            'name'           => $series['name'],
            'anilist_id'     => $anilist_id,
            'anilist_url'    => $series['anilist_url'] ?? '',
            'diffs'          => $diffs,
            'new_alt_titles' => $new_titles,
        ];
    }

    return [
        'success'         => true,
        'report'          => $report,
        'unchanged_count' => $unchanged_count,
        'missing'         => $missing,
        'errors'          => $errors,
        'checked'         => $total,
    ];
}

// ────────────────────────────────────────────────────────────────────────────
// 4. Application des corrections validées
// ────────────────────────────────────────────────────────────────────────────

// Applique, pour UNE série, les champs sélectionnés par l'utilisateur.
// $fields_to_apply : liste des clés 'field' retenues (cf. anilist_recheck_diff_series)
// $accept_new_titles : ajoute les nouveaux titres alternatifs au sélecteur,
//                      sans jamais changer le titre choisi.
//
// Récupère elle-même la fiche Anilist actuelle (cache 24h du connecteur :
// c'est la même fenêtre que celle utilisée pour construire le rapport, la
// revalidation ne re-sollicite donc quasiment jamais le réseau).
//
// Écriture ciblée : dès qu'au moins un champ est réellement appliqué (ou
// qu'un nouveau titre alternatif est accepté), la fonction upserte
// elle-même la ligne série concernée (upsert_series_row()). Cette fonction ne
// touche jamais aux tomes/épisodes (voir en-tête du fichier) : pas besoin de
// replace_series_volumes() ici.
//
// Retour : ['success', 'data', 'message']
function anilist_recheck_apply_series(array $data, string $series_id, array $fields_to_apply, bool $accept_new_titles): array {
    $found = find_series_by_id($data, $series_id);
    if (!$found) {
        return ['success' => false, 'data' => $data, 'message' => "Série introuvable."];
    }
    $key    = $found['key'];
    $series = $data[$key];

    if (!is_anime($series)) {
        return ['success' => false, 'data' => $data, 'message' => "Cette série n'est pas une série animée."];
    }

    $anilist_id = (int)($series['anilist_id'] ?? 0);
    if ($anilist_id <= 0) {
        return ['success' => false, 'data' => $data, 'message' => "Aucun identifiant Anilist pour cette série."];
    }

    $fetch = anilist_fetch_media($anilist_id, false);
    if (!$fetch['ok']) {
        return ['success' => false, 'data' => $data, 'message' => $series['name'] . ' — ' . $fetch['error']];
    }
    $media = $fetch['media'];

    $applied = [];
    $failed  = []; // cases cochées mais dont l'application a concrètement échoué
    $wanted  = array_flip($fields_to_apply);

    if (isset($wanted['studios'])) {
        $data[$key]['studios'] = (array)($media['studios'] ?? []);
        $applied[] = 'studios';
    }

    if (isset($wanted['format'])) {
        $data[$key]['anime_format'] = $media['format'] ?? '';
        // La « catégorie » affichée d'un animé reprend le libellé du format
        // (cf. fonctions/anime.php, add_anime_series) : les deux avancent
        // toujours ensemble.
        $data[$key]['categories'] = [$media['format_label'] ?? ''];
        $applied[] = 'format';
    }

    if (isset($wanted['episode_duration'])) {
        $data[$key]['episode_duration'] = max(0, (int)($media['duration'] ?? 0));
        $applied[] = 'episode_duration';
    }

    if (isset($wanted['genres'])) {
        $data[$key]['genres'] = (array)($media['genres_fr'] ?? []);
        $applied[] = 'genres';
    }

    if (isset($wanted['status'])) {
        if (!empty($media['status_tag'])) {
            $data[$key]['status'] = $media['status_tag'];
            $applied[] = 'statut de diffusion';
        } else {
            $failed[] = "statut de diffusion (aucun statut exploitable reçu d'Anilist)";
        }
    }

    // Le nombre d'épisodes n'est jamais appliqué ici (voir en-tête du
    // fichier) : seulement signalé dans le rapport. 'episode_count' est donc
    // ignoré même s'il apparaît dans $fields_to_apply (case affichée en
    // lecture seule côté JS, sans case à cocher réelle).

    if (isset($wanted['cover'])) {
        if (empty($media['cover'])) {
            $failed[] = "vignette Anilist (Anilist ne fournit plus d'image pour cette fiche)";
        } else {
            $cover = anime_download_cover($media['cover']);
            if ($cover['ok']) {
                // On ne supprime l'éventuel ancien fichier qu'après succès du
                // téléchargement : un échec réseau ne doit jamais laisser la
                // série sans aucune vignette.
                $old = trim((string)($data[$key]['anilist_image'] ?? ''));
                if ($old !== '' && file_exists($old)) {
                    @unlink($old);
                }
                $data[$key]['anilist_image'] = $cover['path'];
                $applied[] = 'vignette Anilist';
            } else {
                // Échec concret (réseau, écriture disque, image invalide…) :
                // on le dit explicitement plutôt que de laisser croire que la
                // case n'avait jamais été cochée.
                $failed[] = 'vignette Anilist (' . $cover['error'] . ')';
            }
        }
    }

    if ($accept_new_titles) {
        $known = array_map(fn($t) => mb_strtolower(trim($t)), series_alt_titles($data[$key]));
        $current_alt = (array)($data[$key]['alt_titles'] ?? []);
        foreach ((array)($media['alt_titles'] ?? []) as $title) {
            $title = trim((string)$title);
            if ($title === '') continue;
            if (!in_array(mb_strtolower($title), $known, true)) {
                $current_alt[] = $title;
                $known[]       = mb_strtolower($title);
            }
        }
        $data[$key]['alt_titles'] = $current_alt;
        $applied[] = 'titres alternatifs';
    }

    if (empty($applied) && empty($failed)) {
        // Cas réel d'absence de sélection (aucun $fields_to_apply reconnu et
        // $accept_new_titles faux) : le seul cas où ce message est exact.
        // Rien n'a été modifié en mémoire : aucune écriture en base non plus.
        return ['success' => true, 'data' => $data, 'message' => $series['name'] . ' — aucune case sélectionnée, rien appliqué.'];
    }

    // Au moins un champ a été appliqué (même si certains ont échoué à côté) :
    // on écrit la ligne série mise à jour. Un échec seul (aucun $applied) ne
    // modifie rien en mémoire, donc rien à upserter dans ce cas.
    if (!empty($applied)) {
        upsert_series_row($data[$key]);
    }

    $bits = [];
    if (!empty($applied)) $bits[] = 'mis à jour (' . implode(', ', $applied) . ')';
    if (!empty($failed))  $bits[] = 'échec sur : ' . implode(', ', $failed);

    return [
        // Un échec partiel (ex. téléchargement de vignette qui échoue) reste
        // un succès d'application au sens de l'appelant (les autres champs
        // cochés, eux, ont bien été écrits) — le détail figure dans le
        // message, à charge pour l'administrateur de relancer si besoin.
        'success' => true,
        'data'    => $data,
        'message' => $series['name'] . ' — ' . implode(' ; ', $bits) . '.',
    ];
}

// Applique plusieurs séries d'un coup (bouton « Appliquer les modifications
// sélectionnées » du rapport). $selections : [series_id => ['fields' => [...],
// 'accept_new_titles' => bool]].
//
// Écriture ciblée : chaque série traitée est déjà upsertée par
// anilist_recheck_apply_series() en cas de succès. $data ne sert plus qu'à
// faire progresser le lot en mémoire (retrouver le titre d'une série en
// erreur, notamment).
//
// Retour : ['success', 'applied' => [messages], 'errors' => [['title','message']]]
function anilist_recheck_apply_batch(array $selections): array {
    $data    = load_data();
    $applied = [];
    $errors  = [];

    foreach ($selections as $series_id => $selection) {
        $fields            = (array)($selection['fields'] ?? []);
        $accept_new_titles = !empty($selection['accept_new_titles']);

        if (empty($fields) && !$accept_new_titles) {
            continue; // rien de coché pour cette série : on ne la touche pas
        }

        $result = anilist_recheck_apply_series($data, (string)$series_id, $fields, $accept_new_titles);
        if (!empty($result['success'])) {
            $data      = $result['data'];
            $applied[] = $result['message'];
        } else {
            $found     = find_series_by_id($data, (string)$series_id);
            $errors[] = ['title' => $found ? $found['data']['name'] : (string)$series_id, 'message' => $result['message']];
        }
    }

    return ['success' => true, 'applied' => $applied, 'errors' => $errors];
}
