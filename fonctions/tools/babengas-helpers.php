<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/babengas-helpers.php — Outil « Vérification via Babengas »
//
// Second mode de l'outil « Séries incomplètes » : au lieu d'interroger
// MangaUpdates (décompte VO, souvent sans édition française), on délègue à
// Babengas — microservice qui lit Babelio et retourne le nombre de tomes VF
// réellement parus.
//
// Le traitement est ASYNCHRONE : Babengas interroge Babelio à raison d'une
// série toutes les cinq minutes. Lengas crée une campagne, puis en suit
// l'avancement (sondage de l'interface + webhook horaire du service).
//
// Ce fichier ne contient que les helpers de l'outil ; le client HTTP et le
// cache SQLite vivent dans includes/babengas.php.
// ────────────────────────────────────────────────────────────────────────────

if (!function_exists('babengas_enabled')) {
    require_once __DIR__ . '/../../includes/babengas.php';
}

// ── Lancement d'une campagne ────────────────────────────────────────────────
// $all = true → forcer toutes les séries éligibles, sans tenir compte du cache.
// Retourne ['success'=>bool, 'message'=>string, 'campagne_id'=>…, 'total'=>…].
function babengas_launch_campaign(array $data, bool $all = false): array {
    if (!babengas_enabled()) {
        return ['success' => false, 'message' => "Babengas n'est pas configuré."];
    }

    // Une seule campagne à la fois : si l'ancienne tourne toujours, on refuse.
    //
    // En cas de doute, on refuse AUSSI. Si le service est momentanément
    // injoignable (redémarrage du conteneur, hoquet du reverse proxy), on ne
    // peut pas savoir si la campagne précédente tourne encore : lancer par
    // défaut doublerait les requêtes vers Babelio, précisément ce qu'il faut
    // éviter face à un site qui filtre déjà les robots. L'utilisateur peut
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

    $targets = babengas_targets($data, $all);
    if ($targets === []) {
        // Aucune fiche série à envoyer à Babengas. Peut-être reste-t-il des
        // one-shots (fiches de tome), qui se résolvent localement sans campagne.
        $oneshots = babengas_local_oneshots($data);

        // Vue complète : on complète avec les séries déjà connues comme
        // incomplètes via le cache Babelio (aucune n'était à rafraîchir, mais
        // leur décompte connu doit rester visible). On exclut les one-shots
        // déjà listés pour éviter les doublons.
        $seen = [];
        foreach ($oneshots['incomplete'] as $s) $seen[] = (string)$s['id'];
        $from_cache = babengas_cached_incomplete($data, $seen);
        $incomplete = array_merge($oneshots['incomplete'], $from_cache['incomplete']);

        if ($incomplete !== [] || $oneshots['ok_count'] > 0) {
            return [
                'success'           => true,
                'local_only'        => true,
                'termine'           => true,
                'incomplete_series' => $incomplete,
                'failed_series'     => [],
                'ok_count'          => $oneshots['ok_count'],
                'no_reference_series' => babengas_series_without_url($data),
                'message'           => sprintf(
                    '%d one-shot%s vérifié%s localement (aucune fiche série à envoyer à Babengas).',
                    $oneshots['ok_count'],
                    $oneshots['ok_count'] > 1 ? 's' : '',
                    $oneshots['ok_count'] > 1 ? 's' : ''
                ),
            ];
        }

        return [
            'success' => false,
            'message' => $all
                ? "Aucune série éligible : renseignez des URL Babelio (les séries avec un « dernier tome » sont exclues)."
                : "Aucune série à rafraîchir. Toutes les séries éligibles ont été vérifiées il y a moins de 30 jours.",
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

    // Les one-shots (fiche de tome) ne passent pas par Babengas : on les résout
    // localement et on les fusionne dans le rapport, mais seulement une fois la
    // campagne terminée, pour ne pas les afficher en boucle pendant le suivi.
    $incomplete = $report['incomplete'];
    $ok_count   = $report['ok_count'];
    if ($done) {
        $oneshots = babengas_local_oneshots($data);
        $incomplete = array_merge($incomplete, $oneshots['incomplete']);
        $ok_count  += $oneshots['ok_count'];

        // Vue complète : une campagne ne renvoie que les séries qu'elle a
        // (re)vérifiées. On complète avec les séries déjà connues comme
        // incomplètes via le cache Babelio (vérifiées récemment, donc hors
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

// ── Séries dépourvues d'URL Babelio (affichées dans le récapitulatif) ────────
function babengas_series_without_url(array $data): array {
    $out = [];
    foreach ($data as $series) {
        $url = trim((string)($series['babelio_url'] ?? ''));
        if ($url !== '' && babelio_url_is_valid($url)) continue;

        $out[] = [
            'id'             => $series['id'],
            'name'           => $series['name'],
            'author'         => $series['author'] ?? '',
            'read_elsewhere' => !empty($series['read_elsewhere']),
            'invalid_url'    => $url !== '', // URL présente mais hors format /serie/…
        ];
    }
    return $out;
}

// ── Enregistrement d'URL Babelio validées ───────────────────────────────────
// Format attendu : $associations[series_id] = url  (même contrat que MangaUpdates)
function babelio_save_associations(array &$data, array $associations): array {
    $saved = 0;

    foreach ($data as &$series) {
        if (!isset($associations[$series['id']])) continue;

        $url = trim((string)$associations[$series['id']]);

        // Chaîne vide : on autorise le retrait de l'association
        if ($url === '') {
            $series['babelio_url'] = '';
            $saved++;
            continue;
        }

        if (babelio_url_is_valid($url)) {
            $series['babelio_url'] = $url;
            $saved++;
        }
    }
    unset($series);

    if ($saved > 0) {
        save_data($data);
    }

    return ['success' => true, 'saved' => $saved];
}
