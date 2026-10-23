<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/anilist_export.php — Outil « Export vers Anilist »
//
// Produit des fichiers d'export importables sur Anilist depuis
// « Paramètres → Import » (https://anilist.co/settings/import), à partir de la
// collection Lengas (et, au choix, de la liste d'envies).
//
// Format : Anilist n'accepte en import QUE le format d'export XML de
// MyAnimeList (un fichier pour les animés, un autre pour les mangas). Ce
// format identifie chaque série par son identifiant MyAnimeList (idMal) — pas
// par son identifiant Anilist. L'outil procède donc en deux temps :
//
//   1. Association (réseau, Anilist uniquement) :
//        - animés : l'identifiant Anilist est déjà connu (Anilist fait
//          autorité sur l'Animethèque) → simple récupération groupée de
//          l'idMal, 50 fiches par requête ;
//        - mangas : aucun identifiant Anilist n'est stocké → recherche par
//          titre (type MANGA), avec un score de confiance (titre + auteur +
//          catégorie manga/light-novel). Les correspondances incertaines sont
//          décochées par défaut, et chacune peut être corrigée à la main
//          (URL ou identifiant Anilist).
//      Les associations sont MÉMORISÉES (option `anilist_export_matches`) :
//      un nouvel export ne refait que les recherches manquantes. Elles ne
//      touchent JAMAIS à la table `series` — l'outil est strictement en
//      lecture vis-à-vis de la collection.
//
//   2. Génération du fichier (aucun appel réseau) : les statuts, la
//      progression, les dates, les relectures/revisionnages et (au choix) la
//      notation sont relus depuis la collection AU MOMENT du téléchargement,
//      puis combinés aux associations mémorisées.
//
// Une série dont la fiche Anilist n'a pas d'équivalent MyAnimeList (idMal
// absent) ne peut pas être importée par Anilist via ce format : elle est
// signalée comme « non exportable » plutôt que silencieusement ignorée.
// ────────────────────────────────────────────────────────────────────────────

// ────────────────────────────────────────────────────────────────────────────
// 1. Persistance des associations
// ────────────────────────────────────────────────────────────────────────────

function anilist_export_matches_key(): string {
    return 'anilist_export_matches';
}

// Score minimal (0-100) d'une correspondance automatique pour qu'elle soit
// cochée par défaut dans l'aperçu. En dessous, la série reste visible mais
// décochée (« à vérifier »).
function anilist_export_confidence_threshold(): float {
    return 70.0;
}

function anilist_export_load_matches(): array {
    $opts = load_options();
    $raw  = trim((string)($opts[anilist_export_matches_key()] ?? ''));
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function anilist_export_save_matches(array $matches): void {
    save_options([anilist_export_matches_key() => json_encode($matches, JSON_UNESCAPED_UNICODE)]);
}

function anilist_export_clear_matches(): void {
    delete_options([anilist_export_matches_key()]);
}

// ────────────────────────────────────────────────────────────────────────────
// 2. Cibles de l'export (collection + liste d'envies)
// ────────────────────────────────────────────────────────────────────────────

// Clé stable d'une cible : identifiant de série pour la collection, empreinte
// type + nom pour la liste d'envies (qui n'a pas d'identifiant durable : elle
// est réécrite en bloc par save_wishlist()).
function anilist_export_wishlist_key(array $item): string {
    $type = sanitize_series_type($item['type'] ?? '');
    $name = mb_strtolower(trim((string)($item['name'] ?? '')));
    return 'w:' . substr(sha1($type . '|' . $name), 0, 20);
}

// Liste des cibles à exporter, dans l'ordre d'affichage (collection triée par
// nom, puis liste d'envies). Chaque cible porte tout ce qu'il faut pour
// l'aperçu ET pour le fichier : kind ('anime' | 'manga'), source
// ('collection' | 'wishlist'), key, name, thumbnail, anilist_id connu (animés),
// auteurs (mangas, pour fiabiliser la recherche), catégorie light-novel, et
// les données de suivi converties au format MyAnimeList.
function anilist_export_targets(array $data, array $wishlist, bool $include_wishlist): array {
    $targets = [];

    $sorted = $data;
    usort($sorted, fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));

    foreach ($sorted as $series) {
        $kind = is_anime($series) ? 'anime' : 'manga';
        $targets[] = [
            'key'          => 's:' . (string)$series['id'],
            'kind'         => $kind,
            'source'       => 'collection',
            'series_id'    => (string)$series['id'],
            'name'         => (string)($series['name'] ?? ''),
            'alt_titles'   => $kind === 'anime' ? series_alt_titles($series) : [],
            'thumbnail'    => series_thumbnail($series),
            'anilist_id'   => (int)($series['anilist_id'] ?? 0),
            'authors'      => $kind === 'manga'
                                ? array_values(array_filter(array_map(fn($c) => trim((string)($c['name'] ?? '')), series_contributors_by_role($series, 'auteur'))))
                                : [],
            'light_novel'  => $kind === 'manga' && anilist_export_is_light_novel($series),
            'tracking'     => anilist_export_tracking($series, $kind),
        ];
    }

    if ($include_wishlist) {
        foreach ($wishlist as $item) {
            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') continue;
            $kind = sanitize_series_type($item['type'] ?? '') === 'anime' ? 'anime' : 'manga';
            $targets[] = [
                'key'          => anilist_export_wishlist_key($item),
                'kind'         => $kind,
                'source'       => 'wishlist',
                'series_id'    => '',
                'name'         => $name,
                'alt_titles'   => [],
                'thumbnail'    => 'assets/img/logo.png',
                'anilist_id'   => (int)($item['anilist_id'] ?? 0),
                'authors'      => $kind === 'manga' && trim((string)($item['author'] ?? '')) !== ''
                                    ? array_map('trim', explode(',', (string)$item['author']))
                                    : [],
                'light_novel'  => false,
                'tracking'     => [
                    'status'   => $kind === 'anime' ? 'Plan to Watch' : 'Plan to Read',
                    'progress' => 0,
                    'owned'    => 0,
                    'start'    => '',
                    'finish'   => '',
                    'times'    => 0,
                    'rating'   => '',
                ],
            ];
        }
    }

    return $targets;
}

// La série relève-t-elle des light-novels (catégorie saisie dans Lengas) ?
// Sert uniquement à départager les candidats Anilist (format NOVEL).
function anilist_export_is_light_novel(array $series): bool {
    foreach ((array)($series['categories'] ?? []) as $cat) {
        $c = mb_strtolower(trim((string)$cat));
        if ($c !== '' && (str_contains($c, 'light-novel') || str_contains($c, 'light novel') || str_contains($c, 'roman'))) {
            return true;
        }
    }
    return false;
}

// Date 'Y-m-d' extraite d'une valeur read_at ('Y-m-d' ou 'Y-m-d H:i:s'),
// ou '' si absente / illisible.
function anilist_export_date(string $value): string {
    $value = substr(trim($value), 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

// Données de suivi d'une série de la collection, au format MyAnimeList.
// Le statut reprend EXACTEMENT les règles du site (anime_watching_status() /
// manga_reading_status()), pour que l'export dise la même chose que les
// filtres « À débuter / En cours / Terminée / Abandonnée ».
function anilist_export_tracking(array $series, string $kind): array {
    $status_key = $kind === 'anime' ? anime_watching_status($series) : manga_reading_status($series);

    $map = $kind === 'anime'
        ? ['not_started' => 'Plan to Watch', 'in_progress' => 'Watching', 'completed' => 'Completed', 'abandoned' => 'Dropped']
        : ['not_started' => 'Plan to Read',  'in_progress' => 'Reading',  'completed' => 'Completed', 'abandoned' => 'Dropped'];
    $status = $map[$status_key] ?? ($kind === 'anime' ? 'Plan to Watch' : 'Plan to Read');

    $progress = 0;
    $owned    = 0;
    $dates    = [];
    foreach ((array)($series['volumes'] ?? []) as $vol) {
        $owned++;
        if (($vol['status'] ?? '') === 'terminé') {
            $progress++;
            $d = anilist_export_date((string)($vol['read_at'] ?? ''));
            if ($d !== '') $dates[] = $d;
        }
    }
    sort($dates);

    return [
        'status'   => $status,
        'progress' => $progress,
        'owned'    => $owned,
        'start'    => $progress > 0 && !empty($dates) ? $dates[0] : '',
        'finish'   => $status === 'Completed' && !empty($dates) ? $dates[count($dates) - 1] : '',
        'times'    => $kind === 'anime' ? (int)($series['rewatch_count'] ?? 0) : (int)($series['reread_count'] ?? 0),
        'rating'   => (string)($series['rating'] ?? ''),
    ];
}

// Libellés français des statuts MyAnimeList, pour l'aperçu.
function anilist_export_status_label(string $status): string {
    return [
        'Watching'      => 'En cours',
        'Reading'       => 'En cours',
        'Completed'     => 'Terminée',
        'On-Hold'       => 'En pause',
        'Dropped'       => 'Abandonnée',
        'Plan to Watch' => 'À voir',
        'Plan to Read'  => 'À lire',
    ][$status] ?? $status;
}

// Notation Lengas → note MyAnimeList (0-10, 0 = pas de note). Valeurs choisies
// pour retomber dans la même tranche lors d'un éventuel ré-import Lengas
// (anilist_score_to_rating() : ≥ 70 apprécié, 40–69 mitigé, < 40 détesté).
function anilist_export_score(string $rating): int {
    return ['apprecie' => 8, 'mitige' => 5, 'deteste' => 3][$rating] ?? 0;
}

// ────────────────────────────────────────────────────────────────────────────
// 3. Requêtes Anilist propres à l'export
// ────────────────────────────────────────────────────────────────────────────
// Le connecteur (includes/anilist.php) ne récupère ni l'idMal ni les fiches
// MANGA : l'outil a donc ses propres requêtes, légères, qui passent par le
// même anilist_graphql() (limiteur de quota, gestion d'erreurs, contrat
// « jamais de fatale »).

function anilist_export_media_fields(): string {
    return '
        id
        idMal
        type
        format
        episodes
        volumes
        siteUrl
        title { romaji english native }
        synonyms
        startDate { year }
        coverImage { medium }
    ';
}

// Champs supplémentaires pour la recherche de mangas : les auteurs servent à
// départager deux œuvres homonymes.
function anilist_export_search_fields(): string {
    return anilist_export_media_fields() . '
        staff(perPage: 6, sort: [RELEVANCE]) { nodes { name { full native alternative } } }
    ';
}

// Fiche brute → association normalisée (sans score).
function anilist_export_normalize_media($media): ?array {
    if (!is_array($media)) return null;
    $id = (int)($media['id'] ?? 0);
    if ($id <= 0) return null;

    $titles = is_array($media['title'] ?? null) ? $media['title'] : [];
    $romaji  = trim((string)($titles['romaji']  ?? ''));
    $english = trim((string)($titles['english'] ?? ''));
    $native  = trim((string)($titles['native']  ?? ''));

    $type  = strtoupper(trim((string)($media['type'] ?? '')));
    $total = $type === 'ANIME' ? (int)($media['episodes'] ?? 0) : (int)($media['volumes'] ?? 0);

    return [
        'anilist_id' => $id,
        'id_mal'     => (int)($media['idMal'] ?? 0),
        'type'       => $type,
        'format'     => strtoupper(trim((string)($media['format'] ?? ''))),
        'title'      => $romaji !== '' ? $romaji : ($english !== '' ? $english : $native),
        'titles'     => array_values(array_unique(array_filter(
                            array_merge([$romaji, $english, $native], array_map('strval', (array)($media['synonyms'] ?? []))),
                            fn($t) => trim($t) !== ''
                        ))),
        'year'       => (int)($media['startDate']['year'] ?? 0),
        'total'      => max(0, $total),
        'url'        => trim((string)($media['siteUrl'] ?? '')) ?: ('https://anilist.co/' . strtolower($type ?: 'anime') . '/' . $id),
        'cover'      => trim((string)($media['coverImage']['medium'] ?? '')),
    ];
}

// Récupère idMal & co pour une liste d'identifiants Anilist (50 par requête).
// Retour : ['ok', 'media' => [anilist_id => association], 'error'].
function anilist_export_fetch_batch(array $anilist_ids, string $type = 'ANIME'): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $anilist_ids), fn($i) => $i > 0)));
    $out = [];
    if (empty($ids)) return ['ok' => true, 'media' => [], 'error' => ''];

    $query = '
        query ($ids: [Int], $perPage: Int, $type: MediaType) {
            Page(page: 1, perPage: $perPage) {
                media(id_in: $ids, type: $type) {
                    ' . anilist_export_media_fields() . '
                }
            }
        }
    ';

    foreach (array_chunk($ids, 50) as $chunk) {
        $res = anilist_graphql($query, ['ids' => $chunk, 'perPage' => count($chunk), 'type' => $type]);
        if (!$res['ok']) {
            return ['ok' => false, 'media' => $out, 'error' => $res['error']];
        }
        foreach ((array)($res['data']['Page']['media'] ?? []) as $media) {
            $rec = anilist_export_normalize_media($media);
            if ($rec !== null) $out[$rec['anilist_id']] = $rec;
        }
    }
    return ['ok' => true, 'media' => $out, 'error' => ''];
}

// Fiche unique par identifiant (correction manuelle), tous types confondus :
// le contrôle de cohérence animé/manga est fait par l'appelant.
function anilist_export_fetch_one(int $anilist_id): array {
    if ($anilist_id <= 0) {
        return ['ok' => false, 'media' => null, 'error' => "Identifiant Anilist invalide."];
    }
    $query = '
        query ($id: Int) {
            Media(id: $id) {
                ' . anilist_export_media_fields() . '
            }
        }
    ';
    $res = anilist_graphql($query, ['id' => $anilist_id]);
    if (!$res['ok']) {
        $msg = $res['error_type'] === 'not_found'
            ? "Fiche Anilist introuvable (identifiant " . $anilist_id . ")."
            : $res['error'];
        return ['ok' => false, 'media' => null, 'error' => $msg];
    }
    $rec = anilist_export_normalize_media($res['data']['Media'] ?? null);
    if ($rec === null) {
        return ['ok' => false, 'media' => null, 'error' => "Fiche Anilist introuvable (identifiant " . $anilist_id . ")."];
    }
    return ['ok' => true, 'media' => $rec, 'error' => ''];
}

// Extrait un identifiant Anilist d'une saisie libre : identifiant seul, ou
// URL de fiche (https://anilist.co/manga/30013/One-Piece/…).
function anilist_export_parse_id(string $input): int {
    $input = trim($input);
    if ($input === '') return 0;
    if (preg_match('/^\d{1,9}$/', $input)) return (int)$input;
    if (preg_match('#anilist\.co/(?:anime|manga)/(\d{1,9})#i', $input, $m)) return (int)$m[1];
    return 0;
}

// Score de correspondance (0-100) entre une cible Lengas et un candidat.
//   - titre : meilleure similarité entre le nom (et titres alternatifs pour
//     un animé) et tous les titres du candidat (romaji, anglais, natif,
//     synonymes), via le moteur du groupage de licences ;
//   - auteur : +15 si l'un des auteurs Lengas figure dans le staff ;
//   - format : +5 si light-novel ↔ NOVEL concordent, −10 s'ils divergent.
function anilist_export_candidate_score(array $target, array $candidate, array $staff_names = []): float {
    $mine = array_merge([(string)$target['name']], (array)($target['alt_titles'] ?? []));
    $best = 0.0;
    foreach ($mine as $a) {
        $na = grouping_normalize_title((string)$a);
        if ($na === '') {
            // Titre non latin : comparaison exacte normalisée en repli.
            foreach ($candidate['titles'] as $b) {
                if (mangaupdates_titles_match((string)$a, (string)$b)) { $best = 100.0; break 2; }
            }
            continue;
        }
        foreach ($candidate['titles'] as $b) {
            $nb = grouping_normalize_title((string)$b);
            if ($nb === '') continue;
            $best = max($best, grouping_normalized_title_score($na, $nb));
            if ($best >= 100.0) break 2;
        }
    }

    $score = $best;

    if (!empty($target['authors']) && !empty($staff_names)) {
        foreach ($target['authors'] as $author) {
            foreach ($staff_names as $staff) {
                if (mangaupdates_authors_match((string)$author, (string)$staff)) {
                    $score += 15.0;
                    break 2;
                }
            }
        }
    }

    if (($target['kind'] ?? '') === 'manga') {
        $is_novel = ($candidate['format'] ?? '') === 'NOVEL';
        if (!empty($target['light_novel'])) {
            $score += $is_novel ? 5.0 : -10.0;
        } elseif ($is_novel) {
            $score -= 10.0;
        }
    }

    return max(0.0, min(100.0, round($score, 1)));
}

// Recherche Anilist par titre d'une cible (manga, ou animé sans identifiant).
// Retour : ['ok', 'best' => association|null, 'candidates' => [..], 'error'].
// `best` porte en plus 'score' et 'match' => 'auto'.
function anilist_export_search(array $target): array {
    $type  = ($target['kind'] ?? 'manga') === 'anime' ? 'ANIME' : 'MANGA';
    $title = trim((string)($target['name'] ?? ''));
    if ($title === '') {
        return ['ok' => true, 'best' => null, 'candidates' => [], 'error' => ''];
    }

    $query = '
        query ($search: String, $type: MediaType) {
            Page(page: 1, perPage: 6) {
                media(search: $search, type: $type, sort: [SEARCH_MATCH, POPULARITY_DESC]) {
                    ' . anilist_export_search_fields() . '
                }
            }
        }
    ';
    $res = anilist_graphql($query, ['search' => $title, 'type' => $type]);
    if (!$res['ok']) {
        return ['ok' => false, 'best' => null, 'candidates' => [], 'error' => $res['error']];
    }

    $candidates = [];
    foreach ((array)($res['data']['Page']['media'] ?? []) as $media) {
        $rec = anilist_export_normalize_media($media);
        if ($rec === null) continue;

        $staff_names = [];
        foreach ((array)($media['staff']['nodes'] ?? []) as $node) {
            $n = is_array($node['name'] ?? null) ? $node['name'] : [];
            foreach (['full', 'native'] as $k) {
                $v = trim((string)($n[$k] ?? ''));
                if ($v !== '') $staff_names[] = $v;
            }
            foreach ((array)($n['alternative'] ?? []) as $alt) {
                $alt = trim((string)$alt);
                if ($alt !== '') $staff_names[] = $alt;
            }
        }

        $rec['score'] = anilist_export_candidate_score($target, $rec, $staff_names);
        unset($rec['titles']); // inutile au-delà du calcul : allège l'option persistée
        $candidates[] = $rec;
    }

    // Tri stable par score décroissant (à égalité, l'ordre de pertinence
    // d'Anilist est conservé).
    $indexed = [];
    foreach ($candidates as $i => $c) $indexed[] = [$i, $c];
    usort($indexed, fn($x, $y) => ($y[1]['score'] <=> $x[1]['score']) ?: ($x[0] <=> $y[0]));
    $candidates = array_map(fn($x) => $x[1], $indexed);

    $best = $candidates[0] ?? null;
    if ($best !== null) $best['match'] = 'auto';

    return ['ok' => true, 'best' => $best, 'candidates' => array_slice($candidates, 0, 4), 'error' => ''];
}

// ────────────────────────────────────────────────────────────────────────────
// 4. Préparation (association) — appelée par l'endpoint SSE
// ────────────────────────────────────────────────────────────────────────────

// Complète les associations manquantes. $on_progress(string $message,
// int $current, int $total) est appelé avant chaque appel réseau.
// $retry_unmatched : relance aussi la recherche des séries restées sans
// correspondance (ou à faible score) lors d'une préparation précédente.
// Retour : ['ok', 'errors' => [messages], 'requests' => int].
function anilist_export_prepare(array $targets, bool $retry_unmatched, ?callable $on_progress = null): array {
    $matches = anilist_export_load_matches();
    $errors  = [];
    $requests = 0;
    $threshold = anilist_export_confidence_threshold();

    // Purge des associations orphelines (série supprimée, envie retirée) :
    // l'option ne grossit pas indéfiniment au fil des exports.
    $live = [];
    foreach ($targets as $t) $live[$t['key']] = true;
    foreach (array_keys($matches) as $k) {
        if (!isset($live[$k])) unset($matches[$k]);
    }

    // ── a. Cibles à identifiant Anilist connu (animés) : récupération groupée ─
    $by_id = [];
    foreach ($targets as $t) {
        if ($t['anilist_id'] <= 0) continue;
        $m = $matches[$t['key']] ?? null;
        // Déjà à jour : même identifiant Anilist que lors de la préparation
        // précédente (une correction de fiche côté Lengas invalide le cache).
        if (is_array($m) && ($m['match'] ?? '') === 'exact' && (int)($m['anilist_id'] ?? 0) === $t['anilist_id']) continue;
        $by_id[$t['anilist_id']][] = $t['key'];
    }

    if (!empty($by_id)) {
        $ids    = array_keys($by_id);
        $chunks = array_chunk($ids, 50);
        foreach ($chunks as $i => $chunk) {
            if ($on_progress) {
                $on_progress("Récupération des identifiants MyAnimeList des animés…", $i + 1, count($chunks));
            }
            $res = anilist_export_fetch_batch($chunk, 'ANIME');
            $requests++;
            if (!$res['ok']) {
                $errors[] = $res['error'];
                break; // Anilist indisponible : inutile d'insister sur les lots suivants
            }
            foreach ($chunk as $id) {
                $rec = $res['media'][$id] ?? null;
                foreach ($by_id[$id] as $key) {
                    $matches[$key] = $rec !== null
                        ? array_merge(array_diff_key($rec, ['titles' => 1]), ['match' => 'exact', 'score' => 100.0, 'candidates' => []])
                        : ['match' => 'none', 'anilist_id' => $id, 'error' => "Fiche Anilist introuvable (identifiant " . $id . ")."];
                }
            }
        }
    }

    // ── b. Cibles sans identifiant (mangas, envies animées non liées) : recherche ─
    $to_search = [];
    foreach ($targets as $t) {
        if ($t['anilist_id'] > 0) continue;
        $m = $matches[$t['key']] ?? null;
        if (is_array($m)) {
            $kind_ok = ($m['kind'] ?? $t['kind']) === $t['kind'];
            $type    = ($m['match'] ?? '');
            if ($kind_ok && $type === 'manual') continue; // choix de l'utilisateur : jamais remis en cause
            if ($kind_ok && !$retry_unmatched) continue;  // déjà cherché une fois
            if ($kind_ok && $retry_unmatched && $type === 'auto' && (float)($m['score'] ?? 0) >= $threshold) continue;
        }
        $to_search[] = $t;
    }

    $total = count($to_search);
    if (!empty($errors)) {
        // Anilist vient d'échouer sur la récupération groupée : on ne lance
        // pas des centaines de recherches vouées au même échec.
        $total = 0;
        $to_search = [];
    }

    foreach ($to_search as $i => $t) {
        if ($on_progress) {
            $on_progress("Recherche de « " . $t['name'] . " » sur Anilist…", $i + 1, $total);
        }
        $res = anilist_export_search($t);
        $requests++;
        if (!$res['ok']) {
            $errors[] = $res['error'];
            break;
        }
        if ($res['best'] === null) {
            $matches[$t['key']] = ['match' => 'none', 'kind' => $t['kind'], 'candidates' => []];
        } else {
            $matches[$t['key']] = array_merge($res['best'], [
                'kind'       => $t['kind'],
                'candidates' => $res['candidates'],
            ]);
        }
        // Sauvegarde au fil de l'eau : une coupure (onglet fermé, délai
        // serveur) ne perd pas les recherches déjà faites.
        if ($i % 10 === 9) anilist_export_save_matches($matches);
    }

    anilist_export_save_matches($matches);

    return ['ok' => empty($errors), 'errors' => array_values(array_unique($errors)), 'requests' => $requests];
}

// Nombre approximatif de requêtes qu'une préparation lancerait (estimation
// de durée affichée avant le lancement).
function anilist_export_pending_requests(array $targets): int {
    $matches = anilist_export_load_matches();
    $ids = 0;
    $searches = 0;
    foreach ($targets as $t) {
        $m = $matches[$t['key']] ?? null;
        if ($t['anilist_id'] > 0) {
            if (!(is_array($m) && ($m['match'] ?? '') === 'exact' && (int)($m['anilist_id'] ?? 0) === $t['anilist_id'])) $ids++;
        } elseif (!is_array($m)) {
            $searches++;
        }
    }
    return (int)ceil($ids / 50) + $searches;
}

// Association manuelle : $input = URL ou identifiant Anilist, '' = aucune
// correspondance (la série sera exclue de l'export).
function anilist_export_set_manual_match(array $target, string $input): array {
    $matches = anilist_export_load_matches();
    $input   = trim($input);

    if ($input === '') {
        $matches[$target['key']] = ['match' => 'manual_none', 'kind' => $target['kind'], 'candidates' => $matches[$target['key']]['candidates'] ?? []];
        anilist_export_save_matches($matches);
        return ['ok' => true, 'match' => $matches[$target['key']], 'message' => "Association retirée."];
    }

    $id = anilist_export_parse_id($input);
    if ($id <= 0) {
        return ['ok' => false, 'message' => "Saisissez une URL de fiche Anilist (https://anilist.co/…) ou un identifiant numérique."];
    }

    $res = anilist_export_fetch_one($id);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => $res['error']];
    }
    $rec = $res['media'];
    $expected = $target['kind'] === 'anime' ? 'ANIME' : 'MANGA';
    if ($rec['type'] !== $expected) {
        return ['ok' => false, 'message' => $expected === 'ANIME'
            ? "Cette fiche Anilist est un manga : choisissez une fiche animée."
            : "Cette fiche Anilist est un animé : choisissez une fiche manga ou light-novel."];
    }

    unset($rec['titles']);
    $matches[$target['key']] = array_merge($rec, [
        'match'      => 'manual',
        'score'      => 100.0,
        'kind'       => $target['kind'],
        'candidates' => $matches[$target['key']]['candidates'] ?? [],
    ]);
    anilist_export_save_matches($matches);

    return ['ok' => true, 'match' => $matches[$target['key']], 'message' => ''];
}

// ────────────────────────────────────────────────────────────────────────────
// 5. Aperçu (aucun appel réseau)
// ────────────────────────────────────────────────────────────────────────────

// Une entrée prête à exporter a un idMal et une association jugée fiable.
function anilist_export_entry(array $target, ?array $match): array {
    $threshold = anilist_export_confidence_threshold();
    $type      = is_array($match) ? (string)($match['match'] ?? 'none') : 'pending';

    $has_media = is_array($match) && (int)($match['anilist_id'] ?? 0) > 0
              && in_array($type, ['exact', 'auto', 'manual'], true);
    $id_mal    = $has_media ? (int)($match['id_mal'] ?? 0) : 0;
    $score     = $has_media ? (float)($match['score'] ?? 0) : 0.0;

    if (!$has_media) {
        $state = $type === 'pending' ? 'pending' : 'none';
    } elseif ($id_mal <= 0) {
        $state = 'no_mal';
    } elseif ($type === 'auto' && $score < $threshold) {
        $state = 'uncertain';
    } else {
        $state = 'ok';
    }

    $tracking = $target['tracking'];

    return [
        'key'          => $target['key'],
        'kind'         => $target['kind'],
        'source'       => $target['source'],
        'name'         => $target['name'],
        'thumbnail'    => $target['thumbnail'],
        'status'       => $tracking['status'],
        'status_label' => anilist_export_status_label($tracking['status']),
        'progress'     => $tracking['progress'],
        'owned'        => $tracking['owned'],
        'rating'       => $tracking['rating'],
        'match_type'   => $type,
        'state'        => $state,
        'checked'      => $state === 'ok',
        'exportable'   => $id_mal > 0,
        'anilist'      => $has_media ? [
            'id'     => (int)$match['anilist_id'],
            'id_mal' => $id_mal,
            'title'  => (string)($match['title'] ?? ''),
            'format' => (string)($match['format'] ?? ''),
            'year'   => (int)($match['year'] ?? 0),
            'total'  => (int)($match['total'] ?? 0),
            'url'    => (string)($match['url'] ?? ''),
            'cover'  => (string)($match['cover'] ?? ''),
            'score'  => $score,
        ] : null,
        'candidates'   => array_values(array_map(fn($c) => [
            'id'     => (int)($c['anilist_id'] ?? 0),
            'id_mal' => (int)($c['id_mal'] ?? 0),
            'title'  => (string)($c['title'] ?? ''),
            'format' => (string)($c['format'] ?? ''),
            'year'   => (int)($c['year'] ?? 0),
            'url'    => (string)($c['url'] ?? ''),
            'score'  => (float)($c['score'] ?? 0),
        ], is_array($match) ? (array)($match['candidates'] ?? []) : [])),
        'error'        => is_array($match) ? (string)($match['error'] ?? '') : '',
    ];
}

function anilist_export_build_preview(array $targets): array {
    $matches = anilist_export_load_matches();
    $entries = [];
    $counts  = [
        'anime' => ['total' => 0, 'ok' => 0, 'uncertain' => 0, 'no_mal' => 0, 'none' => 0, 'pending' => 0],
        'manga' => ['total' => 0, 'ok' => 0, 'uncertain' => 0, 'no_mal' => 0, 'none' => 0, 'pending' => 0],
    ];
    foreach ($targets as $t) {
        $entry = anilist_export_entry($t, $matches[$t['key']] ?? null);
        $entries[] = $entry;
        $counts[$t['kind']]['total']++;
        $counts[$t['kind']][$entry['state']]++;
    }
    return [
        'entries'          => $entries,
        'counts'           => $counts,
        'pending_requests' => anilist_export_pending_requests($targets),
        'has_matches'      => !empty($matches),
    ];
}

// ────────────────────────────────────────────────────────────────────────────
// 6. Génération du fichier XML (format d'export MyAnimeList)
// ────────────────────────────────────────────────────────────────────────────

// Section CDATA sûre : neutralise une éventuelle séquence « ]]> » et retire
// les caractères interdits en XML 1.0 (caractères de contrôle).
function anilist_export_cdata(string $value): string {
    $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
}

function anilist_export_xml_date(string $date): string {
    return $date !== '' ? $date : '0000-00-00';
}

// Format Anilist → libellé MyAnimeList de `series_type` (purement informatif,
// l'import se fonde sur l'identifiant).
function anilist_export_mal_series_type(string $format): string {
    return [
        'TV' => 'TV', 'TV_SHORT' => 'TV', 'MOVIE' => 'Movie', 'SPECIAL' => 'Special',
        'OVA' => 'OVA', 'ONA' => 'ONA', 'MUSIC' => 'Music',
    ][$format] ?? 'Unknown';
}

// Construit le fichier pour un type ('anime' | 'manga').
// $excluded : clés décochées dans l'aperçu.
// Retour : ['xml' => string, 'count' => int, 'skipped' => int].
function anilist_export_build_xml(array $targets, string $kind, array $excluded, bool $include_scores): array {
    $kind     = $kind === 'anime' ? 'anime' : 'manga';
    $matches  = anilist_export_load_matches();
    $excluded = array_flip(array_map('strval', $excluded));

    $rows   = [];
    $totals = [];
    $skipped = 0;
    $seen_mal = [];

    foreach ($targets as $t) {
        if ($t['kind'] !== $kind) continue;
        if (isset($excluded[$t['key']])) { $skipped++; continue; }

        $entry = anilist_export_entry($t, $matches[$t['key']] ?? null);
        // Les associations incertaines ne partent que si l'utilisateur ne les
        // a pas décochées — c'est-à-dire s'il les a explicitement recochées :
        // le front n'envoie dans $excluded que ce qui est décoché, et une
        // incertaine est décochée par défaut. Côté serveur, on n'exporte donc
        // que ce qui a un idMal, sans autre filtre.
        if (!$entry['exportable']) { $skipped++; continue; }

        $mal_id = $entry['anilist']['id_mal'];
        // Une même fiche MyAnimeList ne peut figurer qu'une fois dans le
        // fichier (deux séries Lengas associées à la même œuvre, ou une série
        // à la fois en collection et en liste d'envies) : la collection,
        // listée en premier, l'emporte.
        if (isset($seen_mal[$mal_id])) { $skipped++; continue; }
        $seen_mal[$mal_id] = true;

        $tr    = $t['tracking'];
        $score = $include_scores ? anilist_export_score((string)$tr['rating']) : 0;
        $totals[$tr['status']] = ($totals[$tr['status']] ?? 0) + 1;

        if ($kind === 'anime') {
            $rows[] = "\t<anime>\n"
                . "\t\t<series_animedb_id>" . $mal_id . "</series_animedb_id>\n"
                . "\t\t<series_title>" . anilist_export_cdata($entry['anilist']['title'] !== '' ? $entry['anilist']['title'] : $t['name']) . "</series_title>\n"
                . "\t\t<series_type>" . anilist_export_mal_series_type($entry['anilist']['format']) . "</series_type>\n"
                . "\t\t<series_episodes>" . (int)$entry['anilist']['total'] . "</series_episodes>\n"
                . "\t\t<my_id>0</my_id>\n"
                . "\t\t<my_watched_episodes>" . (int)$tr['progress'] . "</my_watched_episodes>\n"
                . "\t\t<my_start_date>" . anilist_export_xml_date($tr['start']) . "</my_start_date>\n"
                . "\t\t<my_finish_date>" . anilist_export_xml_date($tr['finish']) . "</my_finish_date>\n"
                . "\t\t<my_rated></my_rated>\n"
                . "\t\t<my_score>" . $score . "</my_score>\n"
                . "\t\t<my_storage></my_storage>\n"
                . "\t\t<my_storage_value>0.00</my_storage_value>\n"
                . "\t\t<my_status>" . $tr['status'] . "</my_status>\n"
                . "\t\t<my_comments>" . anilist_export_cdata('') . "</my_comments>\n"
                . "\t\t<my_times_watched>" . max(0, (int)$tr['times']) . "</my_times_watched>\n"
                . "\t\t<my_rewatch_value></my_rewatch_value>\n"
                . "\t\t<my_priority>LOW</my_priority>\n"
                . "\t\t<my_tags>" . anilist_export_cdata('') . "</my_tags>\n"
                . "\t\t<my_rewatching>0</my_rewatching>\n"
                . "\t\t<my_rewatching_ep>0</my_rewatching_ep>\n"
                . "\t\t<my_discuss>1</my_discuss>\n"
                . "\t\t<my_sns>default</my_sns>\n"
                . "\t\t<update_on_import>1</update_on_import>\n"
                . "\t</anime>\n";
        } else {
            $rows[] = "\t<manga>\n"
                . "\t\t<manga_mangadb_id>" . $mal_id . "</manga_mangadb_id>\n"
                . "\t\t<manga_title>" . anilist_export_cdata($entry['anilist']['title'] !== '' ? $entry['anilist']['title'] : $t['name']) . "</manga_title>\n"
                . "\t\t<manga_volumes>" . (int)$entry['anilist']['total'] . "</manga_volumes>\n"
                . "\t\t<manga_chapters>0</manga_chapters>\n"
                . "\t\t<my_id>0</my_id>\n"
                . "\t\t<my_read_volumes>" . (int)$tr['progress'] . "</my_read_volumes>\n"
                . "\t\t<my_read_chapters>0</my_read_chapters>\n"
                . "\t\t<my_start_date>" . anilist_export_xml_date($tr['start']) . "</my_start_date>\n"
                . "\t\t<my_finish_date>" . anilist_export_xml_date($tr['finish']) . "</my_finish_date>\n"
                . "\t\t<my_scanalation_group>" . anilist_export_cdata('') . "</my_scanalation_group>\n"
                . "\t\t<my_score>" . $score . "</my_score>\n"
                . "\t\t<my_storage></my_storage>\n"
                . "\t\t<my_retail_volumes>" . (int)$tr['owned'] . "</my_retail_volumes>\n"
                . "\t\t<my_status>" . $tr['status'] . "</my_status>\n"
                . "\t\t<my_comments>" . anilist_export_cdata('') . "</my_comments>\n"
                . "\t\t<my_times_read>" . max(0, (int)$tr['times']) . "</my_times_read>\n"
                . "\t\t<my_tags>" . anilist_export_cdata('') . "</my_tags>\n"
                . "\t\t<my_priority>Low</my_priority>\n"
                . "\t\t<my_reread_value></my_reread_value>\n"
                . "\t\t<my_rereading>NO</my_rereading>\n"
                . "\t\t<my_discuss>YES</my_discuss>\n"
                . "\t\t<my_sns>default</my_sns>\n"
                . "\t\t<update_on_import>1</update_on_import>\n"
                . "\t</manga>\n";
        }
    }

    $count = count($rows);
    if ($kind === 'anime') {
        $info = "\t\t<user_export_type>1</user_export_type>\n"
              . "\t\t<user_total_anime>" . $count . "</user_total_anime>\n"
              . "\t\t<user_total_watching>"     . ($totals['Watching']      ?? 0) . "</user_total_watching>\n"
              . "\t\t<user_total_completed>"    . ($totals['Completed']     ?? 0) . "</user_total_completed>\n"
              . "\t\t<user_total_onhold>"       . ($totals['On-Hold']       ?? 0) . "</user_total_onhold>\n"
              . "\t\t<user_total_dropped>"      . ($totals['Dropped']       ?? 0) . "</user_total_dropped>\n"
              . "\t\t<user_total_plantowatch>"  . ($totals['Plan to Watch'] ?? 0) . "</user_total_plantowatch>\n";
    } else {
        $info = "\t\t<user_export_type>2</user_export_type>\n"
              . "\t\t<user_total_manga>" . $count . "</user_total_manga>\n"
              . "\t\t<user_total_reading>"      . ($totals['Reading']      ?? 0) . "</user_total_reading>\n"
              . "\t\t<user_total_completed>"    . ($totals['Completed']    ?? 0) . "</user_total_completed>\n"
              . "\t\t<user_total_onhold>"       . ($totals['On-Hold']      ?? 0) . "</user_total_onhold>\n"
              . "\t\t<user_total_dropped>"      . ($totals['Dropped']      ?? 0) . "</user_total_dropped>\n"
              . "\t\t<user_total_plantoread>"   . ($totals['Plan to Read'] ?? 0) . "</user_total_plantoread>\n";
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
         . "<!-- Export Lengas " . SITE_VERSION . " du " . date('Y-m-d H:i') . " — format MyAnimeList, importable sur https://anilist.co/settings/import -->\n"
         . "<myanimelist>\n"
         . "\t<myinfo>\n"
         . "\t\t<user_id>0</user_id>\n"
         . "\t\t<user_name>" . anilist_export_cdata('Lengas') . "</user_name>\n"
         . $info
         . "\t</myinfo>\n"
         . implode('', $rows)
         . "</myanimelist>\n";

    return ['xml' => $xml, 'count' => $count, 'skipped' => $skipped];
}
