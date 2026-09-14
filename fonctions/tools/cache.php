<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/cache.php — Outil « Vider le cache »
//
// Le site n'utilise ni localStorage, ni sessionStorage, ni Service Worker :
// le seul « cache » à vider est celui du NAVIGATEUR sur les fichiers
// statiques (CSS/JS), servis avec un paramètre de version basé sur leur date
// de modification (voir includes/helpers.php, asset_url()). Tant que cette
// date ne change pas, un navigateur peut légitimement continuer à servir sa
// copie locale, y compris après une mise à jour du site (qui republie les
// fichiers avec une date fraîche... mais celle-ci n'est pas toujours fiable :
// un simple ré-upload FTP peut par exemple conserver l'horodatage d'origine).
//
// « Vider le cache » retouche donc explicitement les fichiers CSS/JS du site
// (date de modification uniquement, contenu inchangé) : la prochaine visite
// de n'importe quelle page régénère automatiquement des URLs ?v=... fraîches,
// et le navigateur va rechercher chaque fichier au lieu de servir sa copie.
// Rien d'autre n'est touché : ni la session (cookie, table `sessions`), ni
// les données de la collection, ni aucun fichier hors assets/css et
// assets/js.
// ────────────────────────────────────────────────────────────────────────────

// Liste tous les fichiers .css et .js sous un dossier (récursif).
function cache_bust_collect_files(string $dir): array {
    $files = [];
    if (!is_dir($dir)) return $files;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile()) continue;
        $ext = strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION));
        if ($ext === 'css' || $ext === 'js') {
            $files[] = $item->getPathname();
        }
    }
    return $files;
}

/**
 * Force le renouvellement du cache navigateur sur tous les CSS/JS du site.
 *
 * Deux mécanismes, complémentaires :
 *
 *  1. Incrémente l'option 'cache_bust_version' (table `options`) : c'est ce
 *     compteur qu'asset_url() (includes/helpers.php) utilise comme PLANCHER
 *     minimum pour le paramètre « ?v=... » de chaque <link>/<script>. Cette
 *     seule écriture en base — déjà nécessaire au fonctionnement du site,
 *     donc jamais bloquée par des droits d'écriture manquants sur assets/ —
 *     suffit à invalider le cache de tous les visiteurs.
 *  2. Tente en plus de rafraîchir la date de modification (touch) de chaque
 *     fichier assets/css et assets/js, à titre de bonus si le serveur y a
 *     effectivement les droits d'écriture : sans effet sur le résultat côté
 *     navigateur (déjà garanti par le compteur ci-dessus), mais rapproche
 *     l'horodatage affiché de la réalité pour qui inspecterait les fichiers.
 *     Un échec sur ce point n'est donc jamais bloquant.
 *
 * Le contenu des fichiers CSS/JS n'est jamais modifié, uniquement leur
 * horodatage (le cas échéant) ; aucune donnée de la collection ni la
 * session admin ne sont concernées.
 */
function clear_site_cache(): array {
    // ── 1. Compteur en base : le mécanisme qui garantit le résultat ────────
    $new_version = 0;
    try {
        $options = load_options();
        $new_version = max((int)($options['cache_bust_version'] ?? 0) + 1, time());
        save_options(['cache_bust_version' => (string)$new_version]);
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Le cache n'a pas pu être vidé : impossible d'écrire en base de données (" . $e->getMessage() . ').',
        ];
    }

    // ── 2. Rafraîchissement disque : bonus, jamais bloquant ────────────────
    $dirs = ['assets/css', 'assets/js'];
    $files = [];
    foreach ($dirs as $dir) {
        $files = array_merge($files, cache_bust_collect_files($dir));
    }

    $now         = time();
    $touched     = 0;
    $last_error  = '';
    foreach ($files as $path) {
        // Le message d'erreur exact (droits, propriétaire...) est capturé
        // via un handler d'erreur ponctuel : @touch() seul ne renseigne que
        // le succès/échec, pas la raison, ce qui rendait le diagnostic
        // impossible à affiner pour l'utilisateur en cas de souci serveur.
        set_error_handler(function ($errno, $errstr) use (&$last_error) {
            $last_error = $errstr;
            return true;
        });
        $ok = touch($path, $now);
        restore_error_handler();

        if ($ok) {
            $touched++;
            clearstatcache(true, $path);
        }
    }

    $message = "Cache vidé pour tous les visiteurs (version $new_version).";
    if ($touched > 0) {
        $message .= " $touched fichier(s) CSS/JS ont aussi été actualisés sur le disque.";
    } elseif (!empty($files)) {
        $message .= " Le serveur n'a en revanche pas les droits d'écriture sur assets/css et assets/js"
                   . ($last_error !== '' ? " ($last_error)" : '')
                   . " : cela n'empêche pas le vidage de fonctionner, mais vous pouvez ajuster les permissions"
                   . " (ou le propriétaire) de ces dossiers si vous voulez aussi que l'horodatage des fichiers soit à jour.";
    }

    return [
        'success' => true,
        'message' => $message,
        'version' => $new_version,
        'touched' => $touched,
    ];
}
