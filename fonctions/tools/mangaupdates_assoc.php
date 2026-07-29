<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/mangaupdates_assoc.php — Outils « Association MangaUpdates »
//
// Deux outils partagent ce fichier :
//   • Associer MangaUpdates : recherche une fiche (titre + auteur) pour chaque
//     série sans URL, puis enregistre les correspondances validées.
//   • Associer les genres   : récupère les genres de la fiche MangaUpdates des
//     séries qui ont une URL mais aucun genre renseigné.
//
// La recherche elle-même se fait en flux (SSE) depuis page-outils.php afin
// d'afficher la progression ; ce fichier fournit les helpers de ciblage et
// l'enregistrement des résultats validés.
// ────────────────────────────────────────────────────────────────────────────

if (!function_exists('mangaupdates_get_id_from_url')) {
    require_once __DIR__ . '/../../includes/mangaupdates.php';
}

// ── Ciblage ─────────────────────────────────────────────────────────────────

// Séries dépourvues d'URL MangaUpdates (cibles de « Associer MangaUpdates »).
// Périmètre V4 : Mangathèque uniquement (MangaUpdates ne référence pas d'animés).
function mu_assoc_targets(array $data): array {
    return array_values(array_filter(
        series_of_type($data, 'manga'),
        fn($s) => empty($s['mangaupdates_url'])
    ));
}

// Une série possède-t-elle au moins un genre non vide ?
function mu_series_has_genres(array $series): bool {
    $genres = $series['genres'] ?? [];
    if (!is_array($genres)) $genres = [$genres];
    foreach ($genres as $one) {
        if (trim((string)$one) !== '') return true;
    }
    return false;
}

// Séries avec URL MangaUpdates mais sans genre (cibles de « Associer les genres »).
// Périmètre V4 : Mangathèque uniquement.
function mu_genres_targets(array $data): array {
    return array_values(array_filter(
        series_of_type($data, 'manga'),
        fn($s) => !empty($s['mangaupdates_url']) && !mu_series_has_genres($s)
    ));
}

// ── Enregistrement ──────────────────────────────────────────────────────────

// Enregistre les URL validées. Format attendu : $associations[series_id] = url
// Réchauffe au passage le cache des séries nouvellement associées.
//
// Écriture ciblée (Bloc 5 de la migration save_data(), cf.
// MIGRATION_SAVE_DATA.md) : seule la ligne série de chaque série réellement
// associée est upsertée (upsert_series_row()), au fil de la boucle — plus de
// save_data() global sur toute la collection en fin de traitement.
function mu_save_associations(array &$data, array $associations): array {
    $saved    = 0;
    $warm_ids = [];

    foreach ($data as &$series) {
        if (!isset($associations[$series['id']])) continue;

        $url   = trim((string)$associations[$series['id']]);
        $mu_id = $url !== '' ? mangaupdates_get_id_from_url($url) : null;

        if ($mu_id !== null) {
            $series['mangaupdates_url'] = $url;
            upsert_series_row($series);
            $warm_ids[] = $mu_id;
            $saved++;
        }
    }
    unset($series);

    if ($saved > 0) {
        foreach ($warm_ids as $wid) {
            @mangaupdates_get_volumes($wid);
        }
    }

    return ['success' => true, 'saved' => $saved];
}

// Enregistre les genres validés. Format attendu : $genres_in[series_id] = "G1, G2"
//
// Écriture ciblée (Bloc 5) : upsert_series_row() sur chaque série dont les
// genres ont effectivement été renseignés, au fil de la boucle.
function mu_save_genres(array &$data, array $genres_in): array {
    $saved = 0;

    foreach ($data as &$series) {
        if (!isset($genres_in[$series['id']])) continue;

        $raw = clean_comma_separated((string)$genres_in[$series['id']]);
        $series['genres'] = $raw === '' ? [] : explode(',', $raw);
        upsert_series_row($series);
        $saved++;
    }
    unset($series);

    return ['success' => true, 'saved' => $saved];
}
