<?php
/**
 * includes/nautiljon.php
 * Intégration Nautiljon via Browserless.io
 *
 * Fonctions exposées :
 *   nautiljon_refresh_series(string $series_id, bool $force) : ?int
 *   nautiljon_update_cache(string $series_id, ?int $vf_volumes) : void
 *   nautiljon_is_stale(int $last_checked, int $cache_days) : bool
 */

define('BL_API_BASE', 'https://production-sfo.browserless.io');

// ─────────────────────────────────────────────────────────────────────────────
// Browserless HTTP
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Appelle Browserless /unblock et retourne le HTML rendu de l'URL cible.
 * Bypasse Cloudflare et autres protections bot via un Chrome cloud.
 */
function bl_unblock(string $url, string $token): ?string {
    if (empty($token)) return null;

    $payload = json_encode([
        'url'               => $url,
        'content'           => true,
        'cookies'           => false,
        'screenshot'        => false,
        'browserWSEndpoint' => false,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => BL_API_BASE . '/unblock?token=' . urlencode($token),
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $body    = curl_exec($ch);
    $blCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $blCode !== 200 || !$body) {
        error_log("[Nautiljon/Browserless] Erreur HTTP $blCode sur $url : $curlErr");
        return null;
    }

    $data = json_decode($body, true);
    return $data['content'] ?? null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Parsing Nautiljon
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Scrape le nombre de tomes VF depuis une fiche manga/light novel Nautiljon.
 * Recherche le pattern : "Nb volumes VF : </span> 12 (En cours)"
 *
 * @return int|null Nombre de tomes VF, ou null si introuvable ou erreur
 */
function nautiljon_fetch_vf_volumes(string $nautiljon_url, string $token): ?int {
    $html = bl_unblock($nautiljon_url, $token);
    if (!$html) return null;

    if (preg_match('/Nb\s+volumes\s+VF\s*:?\s*<\/span>\s*(\d+)/u', $html, $m)) {
        return (int)$m[1];
    }

    // Label non trouvé — peut arriver sur les light novels (structure légèrement différente)
    error_log("[Nautiljon] Label 'Nb volumes VF' introuvable dans : $nautiljon_url");
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Cache SQLite
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Vérifie si le cache Nautiljon d'une série est périmé.
 *
 * @param int $last_checked Timestamp Unix du dernier scrape (0 = jamais)
 * @param int $cache_days   TTL en jours
 */
function nautiljon_is_stale(int $last_checked, int $cache_days): bool {
    if ($last_checked === 0) return true;
    return (time() - $last_checked) > ($cache_days * 86400);
}

/**
 * Met à jour le cache Nautiljon d'une série (tomes VF + timestamp).
 * Appelé après chaque scrape réussi ou échoué pour mettre à jour le timestamp
 * et éviter de re-scraper immédiatement en cas d'erreur.
 */
function nautiljon_update_cache(string $series_id, ?int $vf_volumes): void {
    try {
        get_db()->prepare("
            UPDATE series
            SET nautiljon_vf_volumes = ?, nautiljon_last_checked = ?
            WHERE id = ?
        ")->execute([$vf_volumes, time(), $series_id]);
    } catch (Exception $e) {
        error_log("[Nautiljon] Erreur mise à jour cache : " . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Point d'entrée principal
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Rafraîchit les données Nautiljon d'une série si nécessaire.
 *
 * Logique :
 *  1. Pas de nautiljon_url → return null
 *  2. Pas de token Browserless configuré → return null
 *  3. Cache frais (< nautiljon_cache_days) → return valeur en cache
 *  4. Cache périmé ou absent → appel Browserless → mise en cache → return tomes VF
 *
 * @param string $series_id ID de la série Lengas
 * @param bool   $force     Forcer le refresh même si le cache est frais
 * @return int|null Nombre de tomes VF (ou null si données indisponibles)
 */
function nautiljon_refresh_series(string $series_id, bool $force = false): ?int {
    $db  = get_db();
    $stmt = $db->prepare("
        SELECT nautiljon_url, nautiljon_vf_volumes, nautiljon_last_checked
        FROM series WHERE id = ?
    ");
    $stmt->execute([$series_id]);
    $row = $stmt->fetch();

    if (!$row || empty($row['nautiljon_url'])) return null;

    $opts       = load_options();
    $token      = trim($opts['browserless_token'] ?? '');
    $cache_days = max(1, (int)($opts['nautiljon_cache_days'] ?? 30));

    if (empty($token)) return null;

    $last = (int)($row['nautiljon_last_checked'] ?? 0);

    // Cache frais : pas d'appel réseau
    if (!$force && !nautiljon_is_stale($last, $cache_days)) {
        return ($row['nautiljon_vf_volumes'] !== null) ? (int)$row['nautiljon_vf_volumes'] : null;
    }

    // Scrape + mise en cache (même en cas d'échec on met à jour le timestamp)
    $vf = nautiljon_fetch_vf_volumes($row['nautiljon_url'], $token);
    nautiljon_update_cache($series_id, $vf);

    return $vf;
}
