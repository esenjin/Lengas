<?php
// ──────────────────────────────────────────────────────────────────────────────
// includes/babengas.php — Intégration du microservice Babengas (données Babelio)
//
// Babengas est un microservice Docker tournant sur un homelab (IP résidentielle)
// qui interroge Babelio pour connaître le nombre de tomes VF RÉELLEMENT PARUS
// d'une série. Babelio référençant les tomes AVANT leur sortie, Babengas corrige
// le décompte en vérifiant les dates de parution.
//
// L'intégration est ENTIÈREMENT FACULTATIVE (même principe que Vestikan) :
// sans les trois options `babengas_url`, `babengas_key` et `babengas_enabled`,
// la fonctionnalité reste invisible et Lengas fonctionne exactement comme avant.
//
// Contrat API consommé (header X-Babengas-Key sauf /sante) :
//   GET    /sante            → état du service
//   POST   /campagne         → crée une campagne, retourne un campagne_id
//   GET    /campagne/{id}    → avancement + résultats
//   DELETE /campagne/{id}    → annule
//
// ⚠️ Babengas ne remonte PAS le statut de publication : celui de Babelio affiche
// « En cours » y compris sur des séries terminées depuis des années. Le statut
// reste du ressort de MangaUpdates ou d'une saisie manuelle.
// ──────────────────────────────────────────────────────────────────────────────

// ── Configuration ─────────────────────────────────────────────────────────────

// URL de base du service, sans barre oblique finale ('' si non configuré).
function babengas_url(): string {
    $opts = function_exists('load_options') ? load_options() : [];
    return rtrim(trim((string)($opts['babengas_url'] ?? '')), '/');
}

// Clé partagée avec le service ('' si non configurée).
function babengas_key(): string {
    $opts = function_exists('load_options') ? load_options() : [];
    return trim((string)($opts['babengas_key'] ?? ''));
}

// L'intégration est-elle utilisable ? (case cochée + URL + clé renseignées)
function babengas_enabled(): bool {
    $opts = function_exists('load_options') ? load_options() : [];
    if (empty($opts['babengas_enabled'])) return false;
    return babengas_url() !== '' && babengas_key() !== '';
}

// ── Validation des URL Babelio ────────────────────────────────────────────────
//
// Deux formes d'URL sont acceptées, qui ne se traitent PAS de la même façon :
//
//   • Fiche SÉRIE  (/serie/SLUG/ID)  → envoyée à Babengas, qui lit le bloc
//     « Série de N livres » pour compter les tomes VF parus.
//
//   • Fiche TOME   (/livres/SLUG/ID) → cas des one-shots. Un one-shot n'a pas
//     de fiche série sur Babelio, seulement la fiche de son unique tome.
//     Babengas ne saurait rien en faire (il cherche un bloc « Série de N
//     livres » qui n'existe pas) : on ne la lui envoie donc jamais. Lengas la
//     traite localement — une fiche de tome vaut un seul tome paru.

// Extrait l'ID d'une fiche SÉRIE : /serie/SLUG/54358 → "54358". null sinon.
function babelio_serie_id_from_url(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    if (preg_match('#babelio\.com/serie/[^/]+/(\d+)#i', $url, $m)) {
        return $m[1];
    }
    return null;
}

// Extrait l'ID d'une fiche TOME : /livres/SLUG/1883854 → "1883854". null sinon.
function babelio_livre_id_from_url(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    if (preg_match('#babelio\.com/livres/[^/]+/(\d+)#i', $url, $m)) {
        return $m[1];
    }
    return null;
}

// L'URL est-elle une fiche SÉRIE ? (celle que Babengas sait traiter)
function babelio_is_serie_url(string $url): bool {
    return babelio_serie_id_from_url($url) !== null;
}

// L'URL est-elle une fiche TOME ? (one-shot, traité localement)
function babelio_is_livre_url(string $url): bool {
    return babelio_livre_id_from_url($url) !== null;
}

// L'URL est-elle exploitable, quelle que soit sa forme (série OU tome) ?
// C'est le contrôle à utiliser partout où l'on valide une saisie utilisateur.
function babelio_url_is_valid(string $url): bool {
    return babelio_is_serie_url($url) || babelio_is_livre_url($url);
}

// ── Requête cURL vers Babengas ────────────────────────────────────────────────
// Retourne ['ok'=>bool, 'http'=>int, 'data'=>array|null, 'error'=>string].
function babengas_request(string $method, string $path, ?array $payload = null, int $timeout = 15): array {
    $base = babengas_url();
    $key  = babengas_key();

    if ($base === '' || $key === '') {
        return ['ok' => false, 'http' => 0, 'data' => null,
                'error' => 'Babengas n\'est pas configuré (URL ou clé manquante).'];
    }

    $ch   = curl_init($base . '/' . ltrim($path, '/'));
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Babengas-Key: ' . $key,
        ],
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => 'Lengas (gestion de collection de mangas)',
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        return ['ok' => false, 'http' => $code, 'data' => null, 'error' => $err];
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'http' => $code, 'data' => null,
                'error' => 'Réponse illisible du service (JSON invalide).'];
    }

    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'http' => $code, 'data' => $data,
                'error' => (string)($data['message'] ?? $data['erreur'] ?? "Erreur HTTP $code")];
    }

    return ['ok' => true, 'http' => $code, 'data' => $data, 'error' => ''];
}

// ── Sonde /sante (sans authentification côté service, mais on réutilise cURL) ──
// Retourne ['ok'=>bool,'http'=>int,'version'=>string,'actif'=>bool,'error'=>string].
function babengas_check_service(): array {
    $base = babengas_url();
    if ($base === '') {
        return ['ok' => false, 'http' => 0, 'version' => '', 'actif' => false,
                'error' => 'Aucune URL Babengas renseignée.'];
    }

    $ch = curl_init($base . '/sante');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Lengas (gestion de collection de mangas)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '' || $code !== 200) {
        return ['ok' => false, 'http' => $code, 'version' => '', 'actif' => false,
                'error' => $err !== '' ? $err : "Erreur HTTP $code"];
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data) || ($data['statut'] ?? '') !== 'ok') {
        return ['ok' => false, 'http' => $code, 'version' => '', 'actif' => false,
                'error' => 'Réponse inattendue du service.'];
    }

    return [
        'ok'      => true,
        'http'    => $code,
        'version' => (string)($data['version'] ?? ''),
        'actif'   => !empty($data['actif']),
        'error'   => '',
    ];
}

// ── Campagnes ─────────────────────────────────────────────────────────────────

// Crée une campagne. $series = [['id'=>…, 'url'=>…], …]
// Retourne ['ok'=>bool,'campagne_id'=>string,'total'=>int,'duree_estimee'=>string,'error'=>string].
function babengas_create_campaign(array $series, ?string $callback_url = null): array {
    if ($series === []) {
        return ['ok' => false, 'error' => 'Aucune série à vérifier.'];
    }

    $payload = ['series' => array_values($series)];
    if ($callback_url !== null && $callback_url !== '') {
        $payload['callback_url'] = $callback_url;
    }

    $res = babengas_request('POST', '/campagne', $payload, 20);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error']];
    }

    $d = $res['data'];
    return [
        'ok'            => true,
        'campagne_id'   => (string)($d['campagne_id'] ?? ''),
        'statut'        => (string)($d['statut'] ?? ''),
        'total'         => (int)($d['total'] ?? 0),
        'deja_traites'  => (int)($d['deja_traites'] ?? 0),
        'duree_estimee' => (string)($d['duree_estimee'] ?? ''),
        'error'         => '',
    ];
}

// Avancement + résultats d'une campagne.
function babengas_get_campaign(string $campagne_id): array {
    $campagne_id = trim($campagne_id);
    if ($campagne_id === '' || !preg_match('/^[A-Za-z0-9_]+$/', $campagne_id)) {
        return ['ok' => false, 'error' => 'Identifiant de campagne invalide.'];
    }

    $res = babengas_request('GET', '/campagne/' . $campagne_id, null, 20);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error']];
    }

    $d = $res['data'];
    return [
        'ok'          => true,
        'campagne_id' => (string)($d['campagne_id'] ?? $campagne_id),
        'statut'      => (string)($d['statut'] ?? ''),
        'total'       => (int)($d['total'] ?? 0),
        'traites'     => (int)($d['traites'] ?? 0),
        'progression' => (int)($d['progression'] ?? 0),
        'fini_le'     => isset($d['fini_le']) ? (int)$d['fini_le'] : null,
        'resultats'   => is_array($d['resultats'] ?? null) ? $d['resultats'] : [],
        'error'       => '',
    ];
}

// Annule une campagne.
function babengas_cancel_campaign(string $campagne_id): array {
    $campagne_id = trim($campagne_id);
    if ($campagne_id === '' || !preg_match('/^[A-Za-z0-9_]+$/', $campagne_id)) {
        return ['ok' => false, 'error' => 'Identifiant de campagne invalide.'];
    }
    $res = babengas_request('DELETE', '/campagne/' . $campagne_id, null, 15);
    return ['ok' => $res['ok'], 'error' => $res['error']];
}

// ── Cache SQLite des décomptes Babelio ────────────────────────────────────────
// TTL de 30 jours, aligné sur le seuil de rafraîchissement des campagnes.
// Les échecs ne sont JAMAIS mis en cache : la série sera retentée à la campagne
// suivante, et l'ancienne valeur est conservée.

if (!defined('BABELIO_CACHE_TTL')) {
    define('BABELIO_CACHE_TTL', 30 * 24 * 3600); // 30 jours
}

// Écriture d'un décompte validé. $nb_tomes null → on n'écrase rien.
function babelio_cache_store(string $serie_id, string $url, ?int $nb_tomes, ?int $nb_reference, bool $incertain = false): void {
    $serie_id = trim($serie_id);
    if ($serie_id === '') return;

    // Un décompte non fiable ne doit jamais écraser une valeur existante.
    if ($nb_tomes === null || $incertain) return;

    get_db()->prepare("
        INSERT OR REPLACE INTO babelio_cache
            (serie_id, url, nb_tomes, nb_reference, incertain, erreur, timestamp)
        VALUES (?, ?, ?, ?, 0, NULL, ?)
    ")->execute([$serie_id, $url, $nb_tomes, $nb_reference, time()]);
}

// Lecture du cache. $max_age = 0 → ignore l'âge.
// Retourne ['nb_tomes'=>int,'nb_reference'=>int|null,'timestamp'=>int] ou null.
function babelio_get_cached(string $serie_id, int $max_age = 0): ?array {
    $serie_id = trim($serie_id);
    if ($serie_id === '') return null;

    $stmt = get_db()->prepare(
        "SELECT nb_tomes, nb_reference, incertain, timestamp FROM babelio_cache WHERE serie_id = ?"
    );
    $stmt->execute([$serie_id]);
    $row = $stmt->fetch();
    if (!$row) return null;

    if ($max_age > 0 && (time() - (int)$row['timestamp']) >= $max_age) return null;
    if ($row['nb_tomes'] === null || !empty($row['incertain']))       return null;

    return [
        'nb_tomes'     => (int)$row['nb_tomes'],
        'nb_reference' => $row['nb_reference'] !== null ? (int)$row['nb_reference'] : null,
        'timestamp'    => (int)$row['timestamp'],
    ];
}

// Décompte Babelio d'une série de la collection, depuis son URL (cache seul —
// aucun appel réseau : les données viennent des campagnes Babengas).
function babelio_get_volumes_for_url(string $url, int $max_age = BABELIO_CACHE_TTL): ?array {
    $sid = babelio_serie_id_from_url($url);
    if ($sid === null) return null;
    return babelio_get_cached($sid, $max_age);
}

// ── Ciblage des séries à rafraîchir ───────────────────────────────────────────
// Critères :
//   • URL Babelio de SÉRIE renseignée et valide (les fiches de tome — one-shots
//     — sont traitées localement, pas par Babengas : voir babengas_local_oneshots)
//   • EXCLURE les séries possédant un tome tagué « dernier tome ». C'est le SEUL
//     critère d'exclusion : le statut de publication n'entre PAS en compte.
//     Une série peut être terminée de publier tout en étant incomplète dans la
//     collection ; on veut justement savoir ce qu'il manque pour la finaliser.
//   • EXCLURE celles vérifiées il y a moins d'un mois ET sans tome ajouté depuis
//
// $all = true → ignore les critères d'ancienneté (mais garde l'exclusion du
// « dernier tome », seule série n'ayant plus rien à apprendre de Babelio).

function babengas_targets(array $data, bool $all = false): array {
    $targets = [];

    foreach ($data as $series) {
        $url = trim((string)($series['babelio_url'] ?? ''));
        if ($url === '') continue;

        // Seules les fiches SÉRIE partent à Babengas. Une fiche de tome
        // (one-shot) ne serait pas exploitable par le service.
        if (!babelio_is_serie_url($url)) continue;

        // Exclusion : un tome est tagué « dernier tome ». C'est le SEUL critère
        // qui écarte une série. Le statut de publication n'entre pas en compte :
        // une série peut être « terminée » côté publication mais incomplète dans
        // la collection, et l'on veut alors savoir ce qu'il manque pour la finir.
        $has_last = false;
        foreach ($series['volumes'] ?? [] as $v) {
            if (!empty($v['last'])) { $has_last = true; break; }
        }
        if ($has_last) continue;

        if (!$all) {
            $sid    = babelio_serie_id_from_url($url);
            $cached = $sid !== null ? babelio_get_cached($sid, 0) : null;

            if ($cached !== null) {
                $age = time() - (int)$cached['timestamp'];
                // Vérifiée il y a moins d'un mois : on passe, sauf si un tome a
                // été ajouté depuis la dernière vérification.
                if ($age < BABELIO_CACHE_TTL && !babengas_volume_added_since($series, (int)$cached['timestamp'])) {
                    continue;
                }
            }
        }

        $targets[] = [
            'id'     => (string)$series['id'],
            'url'    => $url,
            'name'   => (string)($series['name'] ?? ''),
            'author' => (string)($series['author'] ?? ''),
        ];
    }

    return $targets;
}

// Un tome a-t-il été ajouté à la série depuis le timestamp donné ?
function babengas_volume_added_since(array $series, int $since): bool {
    foreach ($series['volumes'] ?? [] as $v) {
        $added = trim((string)($v['added_at'] ?? ''));
        if ($added === '') continue;
        $ts = strtotime($added);
        if ($ts !== false && $ts > $since) return true;
    }
    return false;
}

// ── Intégration des résultats d'une campagne ──────────────────────────────────
// Écrit en cache les décomptes fiables, laisse les échecs intacts (l'ancienne
// valeur et l'ancien timestamp sont conservés) et retourne un rapport prêt à
// être affiché : séries incomplètes, en surplus, en échec.
//
// $resultats = tableau renvoyé par GET /campagne/{id} (clé « resultats »).
function babengas_integrate_results(array $data, array $resultats): array {
    // Index des séries par ID pour un accès direct
    $by_id = [];
    foreach ($data as $s) {
        $by_id[(string)$s['id']] = $s;
    }

    $incomplete = [];
    $failed     = [];
    $ok_count   = 0;

    foreach ($resultats as $r) {
        $lengas_id = (string)($r['id'] ?? '');
        $statut    = (string)($r['statut'] ?? '');

        // Séries encore en file : rien à intégrer pour l'instant
        if ($statut !== 'fait' && $statut !== 'echec') continue;

        $series = $by_id[$lengas_id] ?? null;
        if ($series === null) continue;

        $nb_tomes  = isset($r['nb_tomes'])  && $r['nb_tomes']  !== null ? (int)$r['nb_tomes']  : null;
        $nb_ref    = isset($r['nb_reference']) && $r['nb_reference'] !== null ? (int)$r['nb_reference'] : null;
        $incertain = !empty($r['incertain']);
        $erreur    = $r['erreur'] ?? null;

        // ── Échec ou décompte non fiable : on n'écrase rien ────────────────────
        if ($statut === 'echec' || $nb_tomes === null || $incertain) {
            $failed[] = [
                'id'          => $series['id'],
                'name'        => $series['name'],
                'author'      => $series['author'] ?? '',
                'ref'         => 'babelio',
                'reason'      => babengas_error_message($erreur !== null ? (string)$erreur : ($incertain ? 'incertain' : '')),
                'erreur'      => $erreur,
                'babelio_url' => $series['babelio_url'] ?? '',
                'read_elsewhere' => !empty($series['read_elsewhere']),
            ];
            continue;
        }

        // ── Succès : mise en cache du décompte ────────────────────────────────
        $sid = babelio_serie_id_from_url((string)($series['babelio_url'] ?? ''));
        if ($sid !== null) {
            babelio_cache_store($sid, (string)$series['babelio_url'], $nb_tomes, $nb_ref, false);
        }
        $ok_count++;

        $owned = count($series['volumes'] ?? []);
        $series['ref_volumes_source'] = 'babelio';
        $series['ref_volumes']        = $nb_tomes;
        $series['ref_reference']      = $nb_ref;
        $series['babelio_title']      = (string)($r['message'] ?? '');

        if ($owned < $nb_tomes) {
            $missing = [];
            for ($i = $owned + 1; $i <= $nb_tomes; $i++) $missing[] = $i;
            $series['missing_volumes'] = $missing;
            $incomplete[] = $series;
        } elseif ($owned > $nb_tomes) {
            $series['has_more_volumes'] = true;
            $series['missing_volumes']  = [];
            $incomplete[] = $series;
        }
        // else : série à jour → non retournée
    }

    return [
        'incomplete' => $incomplete,
        'failed'     => $failed,
        'ok_count'   => $ok_count,
    ];
}

// ── One-shots (fiche de TOME) : décompte local, sans Babengas ─────────────────
// Un one-shot n'a pas de fiche série sur Babelio, seulement la fiche de son
// unique tome. Babengas ne saurait rien en faire. On tranche donc localement :
// une fiche de tome vaut un tome paru. La règle est simple et sûre — un one-shot,
// par définition, ne comporte qu'un volume.
//
// On ne met rien en cache : il n'y a pas de serie_id, et le « décompte » (1)
// est une constante, pas une donnée à rafraîchir. On applique les mêmes
// exclusions de statut que pour les séries : une série figée n'a rien à signaler.
//
// Retourne les mêmes structures que babengas_integrate_results (incomplete +
// ok_count), pour un affichage homogène.
function babengas_local_oneshots(array $data): array {
    $incomplete = [];
    $ok_count   = 0;

    foreach ($data as $series) {
        $url = trim((string)($series['babelio_url'] ?? ''));

        // On ne traite ici QUE les fiches de tome ; les fiches série passent
        // par Babengas.
        if ($url === '' || !babelio_is_livre_url($url)) continue;

        // Même critère que le ciblage Babengas : un « dernier tome » posé →
        // rien à signaler. Le statut de publication n'entre pas en compte.
        //
        // NB : pour un one-shot, l'unique tome est par définition le dernier ;
        // s'il est possédé et tagué « dernier », la série est déjà finalisée et
        // ne remonte pas. Le cas utile ici est celui du one-shot manquant.
        $has_last = false;
        foreach ($series['volumes'] ?? [] as $v) {
            if (!empty($v['last'])) { $has_last = true; break; }
        }
        if ($has_last) continue;

        $owned    = count($series['volumes'] ?? []);
        $expected = 1; // un one-shot = un tome

        $ok_count++;

        if ($owned < $expected) {
            $series['ref_volumes_source'] = 'babelio-oneshot';
            $series['ref_volumes']        = $expected;
            $series['ref_reference']      = $expected;
            $series['missing_volumes']    = [1];
            $incomplete[] = $series;
        } elseif ($owned > $expected) {
            // Plus d'un tome alors que l'URL pointe un one-shot : sans doute une
            // URL de tome collée sur une vraie série. On le signale.
            $series['ref_volumes_source'] = 'babelio-oneshot';
            $series['ref_volumes']        = $expected;
            $series['ref_reference']      = $expected;
            $series['has_more_volumes']   = true;
            $series['missing_volumes']    = [];
            $incomplete[] = $series;
        }
        // else : le one-shot est possédé → rien à signaler
    }

    return [
        'incomplete' => $incomplete,
        'ok_count'   => $ok_count,
    ];
}

// Traduit un code d'erreur Babengas en message lisible.
function babengas_error_message(string $code): string {
    switch ($code) {
        case 'url_absente':    return 'Aucune fiche Babelio associée';
        case 'url_invalide':   return 'URL Babelio invalide (attendu : /serie/…)';
        case 'introuvable':    return 'Fiche introuvable sur Babelio (supprimée ?)';
        case 'inaccessible':   return 'Babelio inaccessible, réessai à la prochaine campagne';
        case 'parsing_echoue': return 'Structure de page inattendue — à signaler';
        case 'incertain':      return 'Décompte incertain, vérification manuelle conseillée';
        default:               return $code !== '' ? $code : 'Erreur inconnue';
    }
}

// ── Suivi de la campagne en cours (persisté dans les options) ─────────────────
// Une seule campagne active à la fois : on stocke son ID pour pouvoir reprendre
// le suivi après un rechargement de page ou une déconnexion.

function babengas_set_current_campaign(string $campagne_id, int $total): void {
    save_options([
        'babengas_campaign_id'    => $campagne_id,
        'babengas_campaign_total' => (string)$total,
        'babengas_campaign_start' => (string)time(),
    ]);
}

function babengas_get_current_campaign(): ?array {
    $opts = load_options();
    $id   = trim((string)($opts['babengas_campaign_id'] ?? ''));
    if ($id === '') return null;
    return [
        'campagne_id' => $id,
        'total'       => (int)($opts['babengas_campaign_total'] ?? 0),
        'start'       => (int)($opts['babengas_campaign_start'] ?? 0),
    ];
}

function babengas_clear_current_campaign(): void {
    save_options([
        'babengas_campaign_id'    => '',
        'babengas_campaign_total' => '0',
        'babengas_campaign_start' => '0',
    ]);
}

// Dernière progression connue, alimentée par le webhook horaire de Babengas.
function babengas_set_ping(int $traites, int $total, int $progression, string $statut): void {
    save_options([
        'babengas_ping_traites'     => (string)$traites,
        'babengas_ping_total'       => (string)$total,
        'babengas_ping_progression' => (string)$progression,
        'babengas_ping_statut'      => $statut,
        'babengas_ping_time'        => (string)time(),
    ]);
}

function babengas_get_ping(): ?array {
    $opts = load_options();
    if (trim((string)($opts['babengas_ping_time'] ?? '')) === '') return null;
    return [
        'traites'     => (int)($opts['babengas_ping_traites'] ?? 0),
        'total'       => (int)($opts['babengas_ping_total'] ?? 0),
        'progression' => (int)($opts['babengas_ping_progression'] ?? 0),
        'statut'      => (string)($opts['babengas_ping_statut'] ?? ''),
        'time'        => (int)($opts['babengas_ping_time'] ?? 0),
    ];
}
