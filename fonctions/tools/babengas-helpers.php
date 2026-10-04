<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/babengas-helpers.php — Outil « Vérification via Babengas »
//
// Second mode de l'outil « Séries incomplètes » : au lieu d'interroger
// MangaUpdates (décompte VO, souvent sans édition française), on délègue à
// Babengas — microservice qui lit Manga News et retourne le nombre de tomes VF
// réellement parus, ainsi que le statut de publication.
//
// Le traitement est ASYNCHRONE : Babengas interroge Manga News à raison d'une
// série toutes les 30 secondes environ (≈ 120 séries par heure). Lengas crée
// une campagne, puis en suit l'avancement (sondage de l'interface + webhook
// horaire du service).
//
// Ce fichier ne contient que les helpers de l'outil ; le client HTTP et le
// cache SQLite vivent dans includes/babengas.php.
// ────────────────────────────────────────────────────────────────────────────

if (!function_exists('babengas_enabled')) {
    require_once __DIR__ . '/../../includes/babengas.php';
}

// ── Lancement d'une campagne ────────────────────────────────────────────────
// $all = true → forcer toutes les séries éligibles, sans tenir compte du cache.
// $force = true → vérifier VRAIMENT toutes les séries sans exception (ignore
//   aussi l'exclusion du « dernier tome »). Implique $all.
// Retourne ['success'=>bool, 'message'=>string, 'campagne_id'=>…, 'total'=>…].
function babengas_launch_campaign(array $data, bool $all = false, bool $force = false): array {
    // Périmètre V4 : Manga News ne référence que la Mangathèque. Filtrage sur la
    // copie locale uniquement ($data est reçu par valeur), tout le ciblage en
    // aval — cibles, cache — hérite donc de cette restriction.
    $data = series_of_type($data, 'manga');

    if ($force) $all = true;
    if (!babengas_enabled()) {
        return ['success' => false, 'message' => "Babengas n'est pas configuré."];
    }

    // Une seule campagne à la fois : si l'ancienne tourne toujours, on refuse.
    //
    // En cas de doute, on refuse AUSSI. Si le service est momentanément
    // injoignable (redémarrage du conteneur, hoquet du reverse proxy), on ne
    // peut pas savoir si la campagne précédente tourne encore : lancer par
    // défaut doublerait les requêtes vers Manga News, précisément ce qu'il faut
    // éviter par courtoisie envers le site. L'utilisateur peut
    // toujours annuler explicitement la campagne pour débloquer la situation.
    $current = babengas_get_current_campaign();
    if ($current !== null) {
        $state = babengas_get_campaign($current['campagne_id']);

        if (!$state['ok']) {
            return [
                'success'     => false,
                'message'     => "Une campagne est enregistrée mais son état n'a pas pu être vérifié ("
                                 . $state['error'] . "). Par précaution, aucune nouvelle campagne n'est lancée : "
                                 . "réessayez plus tard, ou annulez la campagne en cours.",
                'campagne_id' => $current['campagne_id'],
                'in_progress' => true,
            ];
        }

        if (in_array($state['statut'], ['en_attente', 'en_cours'], true)) {
            return [
                'success'     => false,
                'message'     => 'Une campagne est déjà en cours.',
                'campagne_id' => $current['campagne_id'],
                'in_progress' => true,
            ];
        }

        // Campagne close côté service mais encore enregistrée ici : on nettoie.
        babengas_clear_current_campaign();
    }

    $targets = babengas_targets($data, $all, $force);
    if ($targets === []) {
        // Aucune série à envoyer à Babengas. On affiche tout de même la vue
        // complète des séries déjà connues comme incomplètes via le cache
        // (aucune n'était à rafraîchir, mais leur décompte connu doit rester
        // visible), sans créer de campagne.
        $from_cache = babengas_cached_incomplete($data);
        $incomplete = $from_cache['incomplete'];

        if ($incomplete !== []) {
            return [
                'success'           => true,
                'local_only'        => true,
                'termine'           => true,
                'incomplete_series' => $incomplete,
                'failed_series'     => [],
                'ok_count'          => count($incomplete),
                'no_reference_series' => babengas_series_without_url($data),
                'message'           => 'Aucune série à rafraîchir : voici ce que le cache connaît déjà (aucune campagne lancée).',
            ];
        }

        return [
            'success' => false,
            'message' => $force
                ? "Aucune série à vérifier : renseignez des URL Manga News (…/serie/Nom-de-la-serie)."
                : ($all
                    ? "Aucune série éligible : renseignez des URL Manga News (les séries avec un « dernier tome » sont exclues)."
                    : "Aucune série à rafraîchir. Toutes les séries éligibles ont été vérifiées il y a moins de 30 jours, ou sont terminées et complètes."),
        ];
    }

    // Babengas accepte 1000 séries au maximum par campagne.
    if (count($targets) > 1000) {
        $targets = array_slice($targets, 0, 1000);
    }

    // Le service ne veut que {id, url} ; on garde name/author pour l'affichage local.
    $payload = array_map(fn($t) => ['id' => $t['id'], 'url' => $t['url']], $targets);

    $res = babengas_create_campaign($payload, babengas_callback_url());
    if (!$res['ok']) {
        return ['success' => false, 'message' => 'Babengas est injoignable : ' . $res['error']];
    }

    babengas_set_current_campaign($res['campagne_id'], $res['total']);

    return [
        'success'       => true,
        'campagne_id'   => $res['campagne_id'],
        'total'         => $res['total'],
        'duree_estimee' => $res['duree_estimee'],
        'message'       => sprintf(
            'Campagne lancée sur %d série%s. Durée estimée : %s.',
            $res['total'],
            $res['total'] > 1 ? 's' : '',
            $res['duree_estimee'] !== '' ? $res['duree_estimee'] : 'inconnue'
        ),
    ];
}

// URL absolue du webhook de Lengas, transmise à Babengas à la création.
// Retourne null si l'URL ne peut pas être déterminée (le webhook est un confort,
// pas une dépendance : le sondage reste la source de vérité).
function babengas_callback_url(): ?string {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return null;

    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';

    // Répertoire du script courant (Lengas peut vivre dans un sous-dossier)
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    return $scheme . '://' . $host . $dir . '/babengas-ping.php';
}

// ── Suivi de la campagne en cours ───────────────────────────────────────────
// Retourne l'état + les résultats intégrés si la campagne est terminée.
function babengas_campaign_status(array $data, ?string $campagne_id = null): array {
    // Périmètre V4 : Manga News ne référence que la Mangathèque. Filtrage sur la
    // copie locale uniquement ($data est reçu par valeur) — même restriction
    // que babengas_launch_campaign() et babengas_series_without_url().
    $data = series_of_type($data, 'manga');

    if (!babengas_enabled()) {
        return ['success' => false, 'message' => "Babengas n'est pas configuré."];
    }

    if ($campagne_id === null || $campagne_id === '') {
        $current = babengas_get_current_campaign();
        if ($current === null) {
            return ['success' => true, 'none' => true, 'message' => 'Aucune campagne en cours.'];
        }
        $campagne_id = $current['campagne_id'];
    }

    $state = babengas_get_campaign($campagne_id);
    if (!$state['ok']) {
        return ['success' => false, 'message' => 'Suivi impossible : ' . $state['error']];
    }

    $report = babengas_integrate_results($data, $state['resultats']);
    $done   = in_array($state['statut'], ['terminee', 'annulee'], true);

    if ($done) {
        babengas_clear_current_campaign();
    }

    $incomplete = $report['incomplete'];
    $ok_count   = $report['ok_count'];
    if ($done) {
        // Vue complète : une campagne ne renvoie que les séries qu'elle a
        // (re)vérifiées. On complète avec les séries déjà connues comme
        // incomplètes via le cache Manga News (vérifiées récemment, donc hors
        // ciblage), afin d'afficher TOUT ce qui manque réellement — pas
        // seulement le delta de cette campagne. On exclut les séries déjà
        // présentes dans le rapport (traitées ou en échec) pour éviter les
        // doublons ; les résultats frais priment sur leur cache.
        $seen = [];
        foreach ($incomplete as $s)          $seen[] = (string)$s['id'];
        foreach ($report['failed'] as $s)    $seen[] = (string)$s['id'];

        $from_cache = babengas_cached_incomplete($data, $seen);
        $incomplete = array_merge($incomplete, $from_cache['incomplete']);
    }

    return [
        'success'             => true,
        'campagne_id'         => $state['campagne_id'],
        'statut'              => $state['statut'],
        'total'               => $state['total'],
        'traites'             => $state['traites'],
        'progression'         => $state['progression'],
        'termine'             => $done,
        'incomplete_series'   => $incomplete,
        'failed_series'       => $report['failed'],
        'ok_count'            => $ok_count,
        'no_reference_series' => $done ? babengas_series_without_url($data) : [],
    ];
}

// Annule la campagne en cours.
function babengas_cancel_current(): array {
    $current = babengas_get_current_campaign();
    if ($current === null) {
        return ['success' => false, 'message' => 'Aucune campagne en cours.'];
    }

    $res = babengas_cancel_campaign($current['campagne_id']);
    babengas_clear_current_campaign();

    return $res['ok']
        ? ['success' => true,  'message' => 'Campagne annulée.']
        : ['success' => false, 'message' => "L'annulation a échoué : " . $res['error']];
}

// ── Séries dépourvues d'URL Manga News (affichées dans le récapitulatif) ─────
function babengas_series_without_url(array $data): array {
    // Périmètre V4 : ces vérifications ne concernent que la Mangathèque.
    // $data est reçu PAR VALEUR : le filtrage ne touche que cette copie locale,
    // le tableau de l'appelant reste intact pour d'éventuelles écritures
    // ultérieures, qui passent toujours par les fonctions ciblées de
    // config.php sur les seules séries concernées.
    $data = series_of_type($data, 'manga');

    $out = [];
    foreach ($data as $series) {
        $url = trim((string)($series['manganews_url'] ?? ''));
        if ($url !== '' && manganews_url_is_valid($url)) continue;

        $out[] = [
            'id'             => $series['id'],
            'name'           => $series['name'],
            'author'         => series_contributors_names_text($series, 'auteur'),
            'read_elsewhere' => !empty($series['read_elsewhere']),
            'invalid_url'    => $url !== '', // URL présente mais hors format /serie/…
        ];
    }
    return $out;
}

// ── Enregistrement d'URL Manga News validées ─────────────────────────────────
// Format attendu : $associations[series_id] = url  (même contrat que MangaUpdates)
//
// Les URL sont normalisées vers la fiche série principale. Écriture ciblée :
// upsert_series_row() sur chaque série dont l'URL a effectivement changé
// (association ou retrait), au fil de la boucle — jamais de resynchronisation
// de la collection complète. Le décompte VF lui-même (babengas_cache) reste
// dans son propre cache, distinct de la table `series` : il ne transite ni par
// cette fonction ni par aucune écriture sur `series`.
function manganews_save_associations(array &$data, array $associations): array {
    $saved = 0;

    foreach ($data as &$series) {
        if (!isset($associations[$series['id']])) continue;

        $url = trim((string)$associations[$series['id']]);

        // Chaîne vide : on autorise le retrait de l'association
        if ($url === '') {
            $series['manganews_url'] = '';
            upsert_series_row($series);
            $saved++;
            continue;
        }

        $normalized = manganews_normalize_url($url);
        if ($normalized !== null) {
            $series['manganews_url'] = $normalized;
            upsert_series_row($series);
            $saved++;
        }
    }
    unset($series);

    return ['success' => true, 'saved' => $saved];
}
