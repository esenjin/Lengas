<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/syngas.php — Outil « Synchronisation Syngas »
//
// Deux sections dans une seule page (pages/outils/outil-syngas.php), sur le
// modèle exact d'outil-associations-mu.php (section 6 du cahier des charges) :
//   • Envoi     : séries manga sans syngas_uid → soumises à Syngas.
//   • Réception : séries manga avec syngas_uid → comparées champ par champ,
//                 validation sélective avant écriture.
//
// La recherche/l'envoi/la comparaison se font en flux (SSE) depuis
// pages/outils/outil-syngas.php pour afficher la progression ; ce fichier
// fournit les helpers de ciblage et l'enregistrement des résultats validés.
// ────────────────────────────────────────────────────────────────────────────

if (!function_exists('syngas_search')) {
    require_once __DIR__ . '/../../includes/syngas.php';
}

// ── Ciblage ─────────────────────────────────────────────────────────────────

// Séries manga sans syngas_uid ET dont les catégories permettent de dériver
// un type Syngas (section 6.1, point 1-2). $data reste la collection
// complète : le filtrage par type est appliqué ici.
function syngas_sync_send_targets(array $data): array {
    return array_values(array_filter(
        series_of_type($data, 'manga'),
        fn($s) => empty($s['syngas_uid']) && syngas_series_is_eligible($s)
    ));
}

// Séries manga sans syngas_uid ET dont les catégories NE permettent PAS de
// dériver un type Syngas (exclues de l'envoi, signalées séparément — section
// 6.1, point 2 : « ajoutez le tag "manga" ou "light-novel" »).
function syngas_sync_excluded_targets(array $data): array {
    return array_values(array_filter(
        series_of_type($data, 'manga'),
        fn($s) => empty($s['syngas_uid']) && !syngas_series_is_eligible($s)
    ));
}

// Séries manga déjà liées à Syngas (cibles de la réception, section 6.2).
function syngas_sync_receive_targets(array $data): array {
    return array_values(array_filter(
        series_of_type($data, 'manga'),
        fn($s) => !empty($s['syngas_uid'])
    ));
}

// ── Résolution automatique des soumissions déjà suivies ─────────────────────
//
// À chaque relance de l'outil, on interroge GET /submissions/{id} (voir
// includes/syngas.php, syngas_submission_status()) pour chaque soumission
// encore en attente localement (table syngas_submissions). Si Syngas l'a
// entre-temps créée ou fusionnée, syngas_uid est posé automatiquement — sans
// que l'utilisateur ait à repasser par la « Recherche Syngas » (section 6.1,
// point 5, tel qu'amélioré par l'endpoint de suivi ajouté après la V1
// initiale de Syngas). Une soumission encore en_attente ou rejetée reste
// simplement dans le journal (rejetée : elle en est retirée, rien à
// retenter). Ne bloque jamais le reste de l'outil : une erreur réseau sur une
// soumission n'empêche pas de vérifier les suivantes.
//
// Retourne un résumé ['resolved' => int, 'still_pending' => int] à afficher.
//
// Plafonné à SYNGAS_RESOLVE_BATCH_LIMIT soumissions par appel : cette
// fonction tourne automatiquement à CHAQUE chargement de la page (voir
// outil-syngas.php, endpoint syngas_resolve_pending, appelé au
// DOMContentLoaded côté JS) — sans plafond, une longue liste de soumissions
// encore en attente ferait un aller-retour réseau par soumission à chaque
// simple visite de la page, ce qui est à la fois lent pour l'utilisateur et
// coûteux en ressources serveur (timeout cumulé). Les soumissions non
// traitées à ce passage restent dans le journal et seront reprises au
// prochain chargement — rien n'est perdu, seulement étalé dans le temps.
function syngas_resolve_tracked_submissions(array &$data): array {
    $tracked = array_slice(syngas_tracked_submissions(), 0, SYNGAS_RESOLVE_BATCH_LIMIT);
    $resolved = 0;
    $still_pending = 0;

    foreach ($tracked as $row) {
        // Timeout réduit : appel en boucle, même raison que
        // syngas_sync_compute_diff() / syngas_sync_send_one().
        $status_res = syngas_submission_status($row['submission_id'], 6);
        if (!$status_res['ok']) {
            // Introuvable (404) ou erreur réseau : on laisse le journal tel
            // quel, sans acharnement — un identifiant devenu invalide sera
            // simplement ignoré indéfiniment, ce qui est sans conséquence.
            continue;
        }

        $status = $status_res['status'];
        if ($status === 'en_attente') {
            $still_pending++;
            continue;
        }

        if ($status === 'rejetee') {
            syngas_untrack_submission($row['submission_id']);
            continue;
        }

        if (($status === 'creee' || $status === 'fusionnee') && $status_res['series'] !== null) {
            $found = find_series_by_id($data, $row['series_id']);
            if ($found !== null && empty($found['data']['syngas_uid'])) {
                $syngas_id = (string)($status_res['series']['id'] ?? '');
                if ($syngas_id !== '') {
                    $data[$found['key']]['syngas_uid'] = $syngas_id;
                    upsert_series_row($data[$found['key']]);
                    $resolved++;
                }
            }
            syngas_untrack_submission($row['submission_id']);
        }
    }

    return ['resolved' => $resolved, 'still_pending' => $still_pending];
}

// ── Réception : comparaison champ par champ (section 6.2) ──────────────────
//
// Pour une série locale déjà liée, récupère sa fiche Syngas actuelle et
// calcule les différences (même mapping que la recherche, section 4). Ne
// renvoie que les champs qui CHANGERAIENT réellement — une série sans aucun
// changement ne doit pas apparaître dans le récapitulatif (section 6.2,
// point 3).
//
// Retourne null si la série n'a rien à mettre à jour, ou en cas d'erreur
// réseau (traité comme "rien à afficher" : l'outil continue sur les
// suivantes, cohérent avec le traitement d'erreur des autres outils SSE).
// Sinon : ['series_id'=>…, 'name'=>…, 'diff'=>[champ => ['old'=>…,'new'=>…]], 'thumbnail_url'=>…].
function syngas_sync_compute_diff(array $series): ?array {
    $syngas_id = trim((string)($series['syngas_uid'] ?? ''));
    if ($syngas_id === '') return null;

    // Timeout réduit : cette fonction est appelée série par série dans une
    // boucle SSE (potentiellement des dizaines d'appels) — un timeout de 15s
    // par appel, en cas de lenteur réseau, ferait tourner tout le script
    // anormalement longtemps sur un hébergement mutualisé aux ressources
    // limitées. Une série en échec est simplement ignorée pour ce passage,
    // elle réapparaîtra à la prochaine relance de l'outil.
    $fetch = syngas_get_series($syngas_id, 6);
    if (!$fetch['ok']) return null;

    $mapped = syngas_map_to_lengas_fields($fetch['series'], $series['categories'] ?? []);
    $fields = $mapped['fields'];

    $diff = [];
    foreach ($fields as $key => $new_value) {
        $old_value = $series[$key] ?? null;

        // Normalisation pour comparaison : les champs "liste" (categories,
        // other_contributors, genres côté Lengas) sont stockés en tableau.
        // array_map('trim', ...) AVANT l'implode : un élément peut porter un
        // espace de tête résiduel (ex. la colonne `genres` en base contient
        // "Action, Aventure" pour une série saisie/importée avant une
        // normalisation plus stricte — explode(',', …) laisse alors cet
        // espace sur les éléments suivant le premier). Sans ce trim, la
        // comparaison de chaînes détectait un "changement" purement
        // cosmétique (espace en trop) là où les deux valeurs, mot pour mot,
        // sont identiques — un faux positif systématique sur ces séries.
        $old_cmp = is_array($old_value)
            ? implode(',', array_filter(array_map('trim', $old_value), fn($v) => $v !== ''))
            : trim((string)$old_value);
        $new_cmp = is_array($new_value)
            ? implode(',', array_filter(array_map('trim', $new_value), fn($v) => $v !== ''))
            : trim((string)$new_value);
        if ($key === 'mature') {
            $old_cmp = (string)(int)(bool)$old_value;
            $new_cmp = (string)(int)(bool)$new_value;
        }

        if ($old_cmp === $new_cmp) continue;

        $diff[$key] = [
            'old' => is_array($old_value) ? implode(', ', array_filter(array_map('trim', $old_value), fn($v) => $v !== '')) : (string)$old_value,
            'new' => is_array($new_value) ? implode(', ', array_filter(array_map('trim', $new_value), fn($v) => $v !== '')) : (string)$new_value,
        ];
    }

    // Vignette : signalée à part (pas dans $fields), seulement si Syngas en
    // propose une différente de l'actuelle ET que Lengas n'a pas déjà sa
    // propre vignette personnalisée — même principe que pour Anilist
    // (« les champs personnalisés… ne sont jamais proposés à
    // l'écrasement », voir l'outil « Vérification des animés ») : une
    // vignette perso téléversée à la main par l'utilisateur ne doit jamais
    // être signalée comme "à mettre à jour" simplement parce que Syngas en
    // propose une autre. $series['image'] est le champ perso brut (avant la
    // cascade d'affichage de series_thumbnail()) : vide seulement si
    // aucune vignette perso n'a jamais été définie pour cette série.
    if ($mapped['thumbnail_url'] !== '' && empty($series['image'])) {
        $diff['thumbnail'] = ['old' => $series['image'] ?? '', 'new' => $mapped['thumbnail_url']];
    }

    // Nombre de tomes VF : n'écrase jamais une donnée de la fiche série
    // elle-même (section 6.4) — signalé séparément, uniquement s'il diffère
    // du cache local déjà connu (syngas_volumes_count), pour que le
    // récapitulatif ne remonte pas un "changement" sur une valeur identique.
    $old_volumes = $series['syngas_volumes_count'] ?? null;
    if ($mapped['volumes_count'] !== null && $mapped['volumes_count'] !== $old_volumes) {
        $diff['volumes_count'] = ['old' => $old_volumes !== null ? (string)$old_volumes : '(inconnu)', 'new' => (string)$mapped['volumes_count']];
    }

    if (empty($diff)) return null;

    return [
        'series_id'     => $series['id'],
        'name'          => $series['name'],
        'diff'          => $diff,
        'fields'        => $fields,
        // Ne transmet l'URL de vignette que si elle a effectivement été
        // retenue dans $diff ci-dessus (donc si Lengas n'a pas déjà sa
        // propre vignette personnalisée) — sans ce garde-fou,
        // syngas_sync_save_selected() téléchargerait et écraserait quand
        // même la vignette perso à la validation, alors même qu'elle
        // n'apparaît plus dans le récapitulatif affiché à l'utilisateur :
        // un écrasement silencieux serait pire qu'une simple notification
        // superflue.
        'thumbnail_url' => isset($diff['thumbnail']) ? $mapped['thumbnail_url'] : '',
        'volumes_count' => $mapped['volumes_count'],
    ];
}

// ── Enregistrement (envoi) ──────────────────────────────────────────────────
//
// Envoie UNE série à Syngas (POST /series/submit) et journalise le
// submission_id retourné pour résolution ultérieure (voir
// syngas_resolve_tracked_submissions()). N'écrit jamais syngas_uid ici — la
// détection de doublon se fait côté Syngas, Lengas n'a pas à en chercher une
// lui-même (section 6.1, point 5).
function syngas_sync_send_one(array $series): array {
    // Timeout réduit pour la même raison que syngas_sync_compute_diff() :
    // appelée série par série dans une boucle SSE.
    $result = syngas_submit_series($series, 6);
    if ($result['ok'] && $result['submission_id'] !== '') {
        syngas_track_submission($result['submission_id'], $series['id']);
    }
    return $result;
}

// ── Enregistrement (réception) ──────────────────────────────────────────────
//
// Écrit les séries cochées par l'utilisateur. Format attendu :
// $selections[series_id] = true (case cochée, incluse dans cette validation).
// $diffs_by_series : résultat de syngas_sync_compute_diff() pour chaque
// série concernée (recalculé par l'appelant juste avant, pour ne jamais
// écrire une valeur périmée entre l'affichage du récapitulatif et la
// validation).
//
// Écriture ciblée (section 6.2, point 5) : upsert sur la seule ligne series
// concernée, jamais de resynchronisation globale.
function syngas_sync_save_selected(array &$data, array $selections, array $diffs_by_series): array {
    $saved = 0;
    $warm_mu_ids = [];

    foreach ($diffs_by_series as $series_id => $diff_info) {
        if (empty($selections[$series_id])) continue;

        $found = find_series_by_id($data, $series_id);
        if ($found === null) continue;

        $key = $found['key'];
        foreach ($diff_info['fields'] as $field => $value) {
            $data[$key][$field] = $value;
        }
        if (array_key_exists('volumes_count', $diff_info) && $diff_info['volumes_count'] !== null) {
            $data[$key]['syngas_volumes_count'] = $diff_info['volumes_count'];
        }

        // Vignette : téléchargée localement à la validation, jamais
        // laissée en simple URL distante (même principe que la recherche,
        // section 4, note vignette).
        if (!empty($diff_info['thumbnail_url'])) {
            $dl = syngas_download_thumbnail($diff_info['thumbnail_url']);
            if ($dl['ok']) {
                $old_image = $data[$key]['image'] ?? '';
                if ($old_image !== '' && file_exists($old_image)) {
                    @unlink($old_image);
                }
                $data[$key]['image'] = $dl['path'];
            }
        }

        upsert_series_row($data[$key]);
        $saved++;

        if (!empty($diff_info['fields']['mangaupdates_url'])) {
            $mu_id = mangaupdates_get_id_from_url($diff_info['fields']['mangaupdates_url']);
            if ($mu_id !== null) $warm_mu_ids[] = $mu_id;
        }
    }

    foreach ($warm_mu_ids as $wid) {
        @mangaupdates_get_volumes($wid, true);
    }

    return ['success' => true, 'saved' => $saved];
}

// ── Envoi de mises à jour (Lengas → Syngas, « Propositions de modification ») ─
//
// Symétrique de la réception ci-dessus, mais dans l'autre sens : compare
// chaque série locale déjà liée à Syngas à sa fiche Syngas ACTUELLE, et
// signale les champs qui diffèrent côté LENGAS (donc que Syngas ne connaît
// pas encore). Contrairement à l'envoi d'une nouvelle série
// (syngas_sync_send_one(), section 6.1), qui crée une entrée dans « Séries
// en attente » directement modérable, ceci dépose une PROPOSITION DE
// MODIFICATION sur une fiche existante (syngas_propose_edit(), voir
// includes/syngas.php) — visible côté Syngas dans « Propositions de
// modification », jamais dans « Séries en attente ».
//
// Nécessite la route API POST /series/{id}/propose-edit côté Syngas, ajoutée
// avec cette fonctionnalité (absente du Syngas initial, qui ne permettait ce
// type de proposition que depuis un compte web connecté — voir le patch
// Syngas fourni séparément).

// Séries manga déjà liées à Syngas — mêmes cibles que la réception
// (syngas_sync_receive_targets()), réutilisée telle quelle : la seule
// différence est le SENS de la comparaison, pas l'ensemble des séries
// concernées.
function syngas_sync_send_updates_targets(array $data): array {
    return syngas_sync_receive_targets($data);
}

// Calcule ce qui, côté LENGAS, diffère de la fiche Syngas actuelle — sens
// inverse de syngas_sync_compute_diff(). Retourne null si rien ne diffère
// (ou en cas d'erreur réseau, traité comme "rien à proposer" — l'outil
// continue sur les séries suivantes) : une série sans différence ne doit pas
// apparaître dans le récapitulatif, exactement comme pour la réception.
//
// Contrairement à syngas_sync_compute_diff() (qui construit des champs
// LENGAS prêts à écrire), cette fonction construit des champs bruts LENGAS
// tels quels (chaînes/tableaux natifs de la série) : c'est
// syngas_propose_edit() (includes/syngas.php) qui se charge de la
// conversion vers le format attendu par Syngas au moment de l'envoi, jamais
// ici — même séparation des responsabilités que syngas_submit_series().
//
// Retourne :
//   ['series_id'=>…, 'name'=>…, 'diff'=>[champ => ['old'=>…,'new'=>…]],
//    'fields'=>[champ => valeur Lengas brute], 'thumbnail_changed'=>bool]
function syngas_sync_compute_reverse_diff(array $series): ?array {
    $syngas_id = trim((string)($series['syngas_uid'] ?? ''));
    if ($syngas_id === '') return null;

    // Une série non éligible (catégories sans tag manga/light-novel) n'a
    // plus de type Syngas dérivable : rien à proposer, même logique
    // d'éligibilité que l'envoi d'une nouvelle série (section 6.1).
    if (!syngas_series_is_eligible($series)) return null;

    // Timeout réduit : appelée série par série dans une boucle SSE, même
    // raison que syngas_sync_compute_diff().
    $fetch = syngas_get_series($syngas_id, 6);
    if (!$fetch['ok']) return null;
    $syngas_series = $fetch['series'];

    // Comparaison champ par champ, LENGAS → Syngas cette fois : pour chaque
    // champ, la valeur LOCALE fait foi si elle diffère de celle que Syngas
    // connaît actuellement. Un champ local VIDE n'est jamais proposé (on ne
    // propose pas d'effacer un champ que Syngas a déjà rempli — cohérent
    // avec la règle « champ vide n'écrase jamais » appliquée partout
    // ailleurs dans cette intégration).
    $comparable = [
        'name'               => (string)($series['name'] ?? ''),
        'author'             => (string)($series['author'] ?? ''),
        'publisher'          => (string)($series['publisher'] ?? ''),
        // array_map('trim', …) AVANT l'implode : même raison que
        // syngas_sync_compute_diff() ci-dessus — un élément de la colonne
        // `genres`/`other_contributors` peut porter un espace de tête
        // résiduel selon l'origine de la donnée (saisie ancienne, import),
        // ce qui produirait un faux "changement" purement cosmétique.
        'other_contributors' => is_array($series['other_contributors'] ?? null)
                                  ? implode(',', array_filter(array_map('trim', $series['other_contributors']), fn($v) => $v !== ''))
                                  : trim((string)($series['other_contributors'] ?? '')),
        'genres'             => is_array($series['genres'] ?? null)
                                  ? implode(',', array_filter(array_map('trim', $series['genres']), fn($v) => $v !== ''))
                                  : trim((string)($series['genres'] ?? '')),
        'status'             => syngas_status_from_lengas((string)($series['status'] ?? '')),
        'mangaupdates_url'   => (string)($series['mangaupdates_url'] ?? ''),
        'babelio_url'        => (string)($series['babelio_url'] ?? ''),
        'mature'             => (string)(int)(bool)($series['mature'] ?? false),
    ];

    $syngas_values = [
        'name'               => (string)($syngas_series['name'] ?? ''),
        'author'             => (string)($syngas_series['author'] ?? ''),
        'publisher'          => (string)($syngas_series['publisher'] ?? ''),
        'other_contributors' => trim((string)($syngas_series['other_contributors'] ?? '')),
        'genres'             => is_array($syngas_series['genres'] ?? null)
                                  ? implode(',', array_filter(array_map('trim', $syngas_series['genres']), fn($v) => $v !== ''))
                                  : trim((string)($syngas_series['genres'] ?? '')),
        'status'             => (string)($syngas_series['status'] ?? ''),
        'mangaupdates_url'   => (string)($syngas_series['mangaupdates_url'] ?? ''),
        'babelio_url'        => (string)($syngas_series['babelio_url'] ?? ''),
        'mature'             => (string)(int)(bool)($syngas_series['mature'] ?? false),
    ];

    $diff = [];
    $fields = [];
    foreach ($comparable as $key => $local_value) {
        if (trim($local_value) === '') continue; // champ local vide : rien à proposer
        if ($local_value === $syngas_values[$key]) continue; // déjà identique

        $diff[$key] = ['old' => $syngas_values[$key], 'new' => $local_value];
        // $fields conserve la valeur LENGAS BRUTE (pas syngas_status_from_lengas()
        // pour 'status', par exemple) : syngas_propose_edit() refait cette
        // conversion lui-même, comme syngas_submit_series() le fait pour un
        // nouvel envoi — une seule fonction responsable de cette conversion.
        $fields[$key] = $series[$key] ?? '';
    }
    // 'status' : $fields doit porter la valeur Lengas brute (ex. "en cours"),
    // pas la valeur convertie utilisée pour la comparaison ci-dessus.
    if (isset($diff['status'])) {
        $fields['status'] = $series['status'] ?? '';
    }

    // Nombre de tomes VF : comparé à part, jamais dans $fields (n'a pas de
    // sens de "proposer" un nombre de tomes sur la base du décompte de la
    // collection Lengas — Syngas garde volumes_count comme une donnée qui
    // lui est propre, alimentée par ses propres modérateurs).
    // Vignette : signalée séparément, proposée seulement si Lengas a une
    // vignette personnalisée/Anilist ET que Syngas n'en a pas encore, ou si
    // les deux fichiers diffèrent visiblement (impossible à comparer
    // fiablement par simple URL — on propose donc dès qu'une vignette locale
    // existe et que Syngas n'en a aucune).
    $local_thumb = function_exists('series_thumbnail') ? series_thumbnail($series, '') : ($series['image'] ?? '');
    $syngas_thumb = trim((string)($syngas_series['thumbnail_url'] ?? ''));
    $thumbnail_changed = ($local_thumb !== '' && $syngas_thumb === '');
    if ($thumbnail_changed) {
        $diff['thumbnail'] = ['old' => '(aucune)', 'new' => 'Vignette Lengas actuelle'];
    }

    if (empty($diff)) return null;

    return [
        'series_id'          => $series['id'],
        'name'               => $series['name'],
        'syngas_uid'         => $syngas_id,
        'diff'               => $diff,
        'fields'             => $fields,
        'thumbnail_changed'  => $thumbnail_changed,
    ];
}

// Envoie UNE proposition de modification à Syngas pour la série donnée.
// $diff_info : résultat de syngas_sync_compute_reverse_diff() pour cette
// série (recalculé par l'appelant juste avant l'envoi effectif, jamais
// réutilisé tel quel depuis un récapitulatif potentiellement périmé — même
// principe que syngas_sync_save_selected() côté réception, où $diffs_by_series
// est également fourni fraîchement par le client à partir des évènements SSE
// déjà reçus).
function syngas_sync_send_update_one(array $series, array $diff_info): array {
    return syngas_propose_edit(
        $diff_info['syngas_uid'],
        $diff_info['fields'],
        $diff_info['thumbnail_changed'],
        $series,
        6 // timeout réduit : appelée série par série dans une boucle SSE.
    );
}
