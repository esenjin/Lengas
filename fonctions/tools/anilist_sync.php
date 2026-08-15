<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/anilist_sync.php — Synchronisation automatique
//
// Tient à jour, sans intervention, les séries animées dont la diffusion ET le
// visionnage sont tous deux « en cours ». Périmètre volontairement étroit et
// strictement disjoint de l'outil de revérification (fonctions/tools/
// anilist_recheck.php) :
//
//   TOUCHE   → épisodes (nouveaux épisodes diffusés, statuts « à voir »
//              conservés, tag « dernier épisode ») et statut de diffusion.
//   NE TOUCHE JAMAIS → titre choisi, vignette personnalisée, note, coches
//              mature / favori / visionnage abandonné, éditions physiques,
//              studios, genres, format, titres alternatifs, rewatch_count.
//              Tout cela relève de la revérification manuelle, qui demande
//              une validation explicite avant écriture.
//
// Le moteur ci-dessous (anilist_sync_series_now) est le SEUL point d'écriture
// d'une synchronisation. Il est appelé par deux entrées distinctes :
//   • l'endpoint AJAX d'admin.php (pages/admin.php), une série à la fois — le
//     quota de séries par visite et le verrou d'1h y sont vérifiés
//     directement autour de l'appel ;
//   • anilist_sync_run_batch(), utilisée par le sous-onglet « Vérification
//     via Anilist » de la page Outils (bouton de forçage : verrous ignorés,
//     aucun plafond de visite — action explicite de l'administrateur).
//
// Dépendances : includes/anilist.php (connecteur), fonctions/anime.php
// (find_series_by_anilist_id), fonctions/episodes.php
// (anime_episodes_from_media, anime_refresh_last_episode,
// anime_airing_finished), includes/helpers.php (is_anime, find_series_by_id,
// anime_watching_status), config.php (load_data(), upsert_series_row(),
// replace_series_volumes()).
// ────────────────────────────────────────────────────────────────────────────

// ────────────────────────────────────────────────────────────────────────────
// 1. Réglages du verrou
// ────────────────────────────────────────────────────────────────────────────

// Verrou normal après une synchronisation réussie : 1 heure, par série.
// Anciennement 24h ; réduit car l'API Anilist supporte largement une
// fréquence de vérification plus élevée, et le nombre de séries en cours de
// diffusion ET de visionnage simultanément reste toujours limité en
// pratique (jamais des centaines) — le coût en requêtes reste négligeable.
function anilist_sync_lock_seconds(): int {
    return 3600;
}

// Report du verrou après un échec API : 15 minutes, pour retenter bientôt
// sans pour autant marteler l'API à chaque page vue en cas de panne.
function anilist_sync_retry_lock_seconds(): int {
    return 15 * 60;
}

// ────────────────────────────────────────────────────────────────────────────
// 2. Quota de séries synchronisées par visite — SUPPRIMÉ
// ────────────────────────────────────────────────────────────────────────────
// Un plafond par visite existait ici (5, puis 200). Retiré : le connecteur
// Anilist (includes/anilist.php) respecte déjà le quota de l'API (90
// requêtes/minute) avec sa propre fenêtre glissante, et le front
// (assets/js/admin/anime.js) espace ses appels d'1 à 2 secondes par égard
// pour Anilist — ces deux mécanismes suffisent, un plafond par visite
// supplémentaire n'apportait qu'un risque de blocage silencieux (une série
// dont le sync_due n'était pas correctement propagé pouvait rester bloquée
// à 'skipped' sans jamais avancer) pour un bénéfice nul en pratique : une
// collection compte rarement, voire jamais, des centaines de séries à la
// fois en diffusion ET en visionnage « en cours ».

// ────────────────────────────────────────────────────────────────────────────
// 3. Éligibilité
// ────────────────────────────────────────────────────────────────────────────

// Une série est éligible à la synchro automatique si diffusion ET visionnage
// sont tous deux « en cours ». Diffusion terminée / en pause / abandonnée, ou
// visionnage terminé / abandonné / pas commencé : la série ne bouge plus
// (ou plus vite qu'une fois par jour ne change rien), inutile de la traiter.
function anilist_sync_is_eligible(array $series): bool {
    if (!is_anime($series)) return false;
    if (empty($series['anilist_id'])) return false;
    if (($series['status'] ?? '') !== 'en cours') return false;
    return anime_watching_status($series) === 'in_progress';
}

// La série peut-elle être (re)synchronisée MAINTENANT, verrou compris ?
// $ignore_lock = true pour le bouton de forçage de la page Outils.
function anilist_sync_is_due(array $series, bool $ignore_lock = false): bool {
    if (!anilist_sync_is_eligible($series)) return false;
    if ($ignore_lock) return true;

    $synced_at = (int)($series['anilist_synced_at'] ?? 0);
    if ($synced_at <= 0) return true;

    return (time() - $synced_at) >= anilist_sync_lock_seconds();
}

// ────────────────────────────────────────────────────────────────────────────
// 4. Moteur de synchronisation d'UNE série
// ────────────────────────────────────────────────────────────────────────────

// Synchronise une série déjà chargée en mémoire ($data en contient la version
// courante) : nouveaux épisodes diffusés, tag « dernier épisode », statut de
// diffusion.
//
// Écriture ciblée : dès qu'une synchronisation modifie réellement la série
// (statut ou épisodes) ou avance seulement anilist_synced_at (cas
// 'unchanged'), la fonction écrit elle-même en base — upsert_series_row()
// pour les champs série (status, anilist_synced_at) et
// replace_series_volumes() pour les épisodes. En cas d'erreur ou de skip,
// rien n'est écrit ici : c'est à l'appelant de décider (voir
// anilist_sync_apply_retry_lock()).
//
// Retour :
//   ['status' => 'synced'|'unchanged'|'error'|'skipped',
//    'data'   => collection éventuellement modifiée (en mémoire, pour
//                l'affichage uniquement — déjà persistée ci-dessus),
//    'message'=> string,
//    'series' => la série à jour (ou telle quelle en cas d'échec/skip),
//    'retry_lock' => bool (true si un échec doit reporter le verrou d'1h)]
function anilist_sync_series_now(array $data, string $series_id, bool $force = false): array {
    $found = find_series_by_id($data, $series_id);
    if (!$found) {
        return ['status' => 'error', 'data' => $data, 'message' => "Série introuvable.", 'series' => null, 'retry_lock' => false];
    }

    $key    = $found['key'];
    $series = $data[$key];

    if (!anilist_sync_is_eligible($series)) {
        return ['status' => 'skipped', 'data' => $data, 'message' => "Série non éligible à la synchronisation automatique.", 'series' => $series, 'retry_lock' => false];
    }

    if (!$force && !anilist_sync_is_due($series, false)) {
        return ['status' => 'skipped', 'data' => $data, 'message' => "Verrou de 24 h non écoulé.", 'series' => $series, 'retry_lock' => false];
    }

    // Anilist fait autorité : on force toujours le contournement du cache de
    // 24h du connecteur (anilist_cache_ttl()) — sinon une synchro pourrait
    // relire une fiche mise en cache avant le dernier épisode diffusé. Le
    // verrou PROPRE à la synchro (anilist_synced_at) est le seul qui compte
    // ici ; le cache du connecteur n'a pas à s'en mêler.
    $fetch = anilist_fetch_media((int)$series['anilist_id'], true);
    if (!$fetch['ok']) {
        return [
            'status'     => 'error',
            'data'       => $data,
            'message'    => $series['name'] . ' — ' . $fetch['error'],
            'series'     => $series,
            'retry_lock' => true,
        ];
    }
    $media = $fetch['media'];

    $episodes_before = count($series['volumes'] ?? []);
    $status_before   = $series['status'] ?? '';

    // Périmètre strict de la synchronisation automatique : épisodes + statut
    // de diffusion, rien d'autre (cf. en-tête de ce fichier). Les autres
    // champs factuels (studios, genres, format, titres alternatifs…)
    // relèvent de la revérification manuelle.
    $data[$key]['status']  = $media['status_tag'] ?? $data[$key]['status'];
    $data[$key]['volumes'] = anime_episodes_from_media($media, $data[$key]['volumes'] ?? []);
    $data[$key]['volumes'] = anime_refresh_last_episode(
        $data[$key]['volumes'],
        anime_airing_finished($data[$key])
    );
    $data[$key]['anilist_synced_at'] = time();

    $episodes_after = count($data[$key]['volumes']);
    $new_episodes   = max(0, $episodes_after - $episodes_before);
    $status_changed = ($data[$key]['status'] !== $status_before);

    if ($new_episodes > 0 || $status_changed) {
        $bits = [];
        if ($new_episodes > 0)   $bits[] = $new_episodes . ' nouvel' . ($new_episodes > 1 ? 's' : '') . ' épisode' . ($new_episodes > 1 ? 's' : '') . ' diffusé' . ($new_episodes > 1 ? 's' : '');
        if ($status_changed)     $bits[] = 'statut de diffusion mis à jour (' . $data[$key]['status'] . ')';
        $message = $series['name'] . ' — ' . implode(', ', $bits) . '.';
        $status  = 'synced';
    } else {
        $message = $series['name'] . ' — rien de nouveau.';
        $status  = 'unchanged';
    }

    // Écriture ciblée : dans les deux cas ('synced' et 'unchanged'), au moins
    // anilist_synced_at a avancé — on écrit systématiquement la ligne série
    // (upsert_series_row) et les épisodes (replace_series_volumes), qu'il y
    // ait ou non de nouveaux épisodes : replace_series_volumes() sur une
    // liste inchangée est sans effet visible, mais reste nécessaire pour
    // couvrir le cas où seul le tag « dernier épisode » a bougé.
    upsert_series_row($data[$key]);
    replace_series_volumes($series_id, $data[$key]['volumes']);

    return ['status' => $status, 'data' => $data, 'message' => $message, 'series' => $data[$key], 'retry_lock' => false];
}

// Reporte le verrou d'1 heure après un échec API, SANS toucher au reste de la
// série. Séparé de anilist_sync_series_now() : le report d'échec n'est pas
// une synchronisation, c'est juste une façon d'éviter de remarteler l'API à
// chaque page vue pendant l'heure qui suit.
//
// Écriture ciblée (Bloc 5) : upsert_series_row() sur la seule série
// concernée — un échec API ne touche jamais aux tomes/épisodes, un simple
// upsert de la ligne série (avec son anilist_synced_at reculé) suffit.
function anilist_sync_apply_retry_lock(array $data, string $series_id): array {
    $found = find_series_by_id($data, $series_id);
    if (!$found) return $data;
    // anilist_synced_at ne mémorise qu'UN seul horodatage, sur lequel
    // anilist_sync_is_due() applique toujours la même durée de verrou (24h).
    // Pour obtenir une réouverture dans 1h plutôt que 24h, on recule donc
    // l'horodatage posé de la différence entre les deux durées : 24h plus
    // tard, anilist_sync_is_due() ne verra que l'équivalent d'1h écoulée.
    $backdated = time() - (anilist_sync_lock_seconds() - anilist_sync_retry_lock_seconds());
    $data[$found['key']]['anilist_synced_at'] = $backdated;
    upsert_series_row($data[$found['key']]);
    return $data;
}

// ────────────────────────────────────────────────────────────────────────────
// 5. Traitement en lot (sous-onglet Outils)
// ────────────────────────────────────────────────────────────────────────────

// Synchronise jusqu'à $limit séries parmi $series_ids (dans l'ordre donné),
// en respectant l'éligibilité et le verrou (sauf $force).
//
// Écriture ciblée : chaque série traitée est déjà écrite en base au fil du
// lot, par anilist_sync_series_now() ('synced'/'unchanged') ou
// anilist_sync_apply_retry_lock() ('error'). $data ne sert plus qu'à
// l'affichage de la progression (titres) et à la lecture de l'état courant
// série par série.
//
// $on_progress : callable(int $current, int $total, string $title) — facultatif,
// pour une utilisation SSE (sous-onglet outils).
//
// Retour :
//   ['synced' => [...], 'unchanged' => [...], 'skipped' => [...],
//    'errors' => [['title','message'], ...], 'processed' => n]
function anilist_sync_run_batch(array $series_ids, int $limit, bool $force, $on_progress = null): array {
    $data = load_data();

    $synced    = [];
    $unchanged = [];
    $skipped   = [];
    $errors    = [];
    $processed = 0;

    $total = min($limit, count($series_ids));
    $i     = 0;

    foreach ($series_ids as $series_id) {
        if ($processed >= $limit) break;

        $found = find_series_by_id($data, $series_id);
        $title = $found ? $found['data']['name'] : $series_id;
        $i++;
        if (is_callable($on_progress)) {
            call_user_func($on_progress, $i, $total, $title);
        }

        $result = anilist_sync_series_now($data, $series_id, $force);
        $data   = $result['data'];

        switch ($result['status']) {
            case 'synced':
                $synced[]  = $result['message'];
                $processed++;
                break;
            case 'unchanged':
                $unchanged[] = $result['message'];
                $processed++;
                break;
            case 'error':
                $errors[] = ['title' => $title, 'message' => $result['message']];
                $data     = anilist_sync_apply_retry_lock($data, $series_id);
                $processed++;
                break;
            case 'skipped':
            default:
                $skipped[] = $title;
                break;
        }
    }

    return [
        'synced'    => $synced,
        'unchanged' => $unchanged,
        'skipped'   => $skipped,
        'errors'    => $errors,
        'processed' => $processed,
    ];
}

// Liste des identifiants de séries éligibles À CE JOUR (indépendamment du
// verrou) — utilisée par le sous-onglet Outils pour afficher un décompte et
// par le bouton de forçage pour connaître son périmètre complet.
function anilist_sync_eligible_series_ids(array $data): array {
    $ids = [];
    foreach ($data as $series) {
        if (anilist_sync_is_eligible($series)) {
            $ids[] = $series['id'];
        }
    }
    return $ids;
}

// Parmi les séries éligibles, celles dont le verrou d'1h est écoulé (ce que
// la synchro automatique traiterait réellement sans forçage).
function anilist_sync_due_series_ids(array $data): array {
    $ids = [];
    foreach ($data as $series) {
        if (anilist_sync_is_due($series, false)) {
            $ids[] = $series['id'];
        }
    }
    return $ids;
}
