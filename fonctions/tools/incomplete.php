<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/incomplete.php — Outil « Séries incomplètes »
//
// Détection des tomes manquants en comparant la collection au nombre de tomes
// annoncé par MangaUpdates (le décompte VF est privilégié quand il est connu).
//
// Note : le coeur de l'analyse — get_incomplete_series() — vit dans
// includes/mangaupdates.php, car il est indissociable du client d'API et de son
// cache SQLite. Ce fichier regroupe les helpers propres à l'outil et garantit
// que la dépendance est chargée, afin que page-outils.php n'ait à inclure que
// fonctions/tools.php.
// ────────────────────────────────────────────────────────────────────────────

if (!function_exists('get_incomplete_series')) {
    require_once __DIR__ . '/../../includes/mangaupdates.php';
}

// Regroupe le résultat brut de get_incomplete_series() en une structure prête à
// être renvoyée en JSON par les endpoints de page-outils.php.
function build_incomplete_report(array $data): array {
    $result = get_incomplete_series($data);

    return [
        'incomplete_series'   => $result['incomplete']    ?? [],
        'no_reference_series' => $result['no_reference']  ?? [],
        'failed_series'       => $result['failed']        ?? [],
    ];
}

// Ajoute un tome manquant à une série (action « Ajouter » de l'outil).
//
// Écriture ciblée : add_volume_to_series() écrit elle-même les tomes de la
// série en base via replace_series_volumes(), aucune écriture
// supplémentaire n'est donc nécessaire ici.
function add_missing_volume(array &$data, string $series_id, int $volume_number): array {
    if ($series_id === '' || $volume_number <= 0) {
        return ['success' => false, 'message' => 'Série ou numéro de tome invalide.'];
    }

    $result = add_volume_to_series($data, $series_id, $volume_number, 'à lire', false, false);
    if (!empty($result['success'])) {
        $data = $result['data'];
        return ['success' => true];
    }

    return ['success' => false, 'message' => $result['message'] ?? "Impossible d'ajouter le tome."];
}

// Ajoute d'un coup tous les tomes manquants d'une série.
//
// Écriture ciblée : chaque appel à add_volume_to_series() écrit déjà les
// tomes de la série en base, aucune écriture supplémentaire n'est donc
// nécessaire ici (même logique qu'add_missing_volume() ci-dessus).
function add_all_missing_volumes(array &$data, string $series_id, array $missing_volumes): array {
    $missing_volumes = array_values(array_filter(array_map('intval', $missing_volumes), fn($n) => $n > 0));

    if ($series_id === '' || empty($missing_volumes)) {
        return ['success' => false, 'message' => 'Aucun tome à ajouter.'];
    }

    foreach ($missing_volumes as $volume) {
        $result = add_volume_to_series($data, $series_id, $volume, 'à lire', false, false);
        if (empty($result['success'])) {
            return ['success' => false, 'message' => $result['message'] ?? "Impossible d'ajouter le tome $volume."];
        }
        $data = $result['data'];
    }

    return ['success' => true];
}
