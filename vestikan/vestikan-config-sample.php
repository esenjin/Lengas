<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 *  Configuration Vestikan (SSO maison) — OPTIONNELLE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Le SSO Vestikan est ACTIVÉ uniquement si le fichier vestikan/vestikan-config.php
 *  existe ET contient un client_id + un client_secret non vides.
 *  Sans ce fichier, Lengas fonctionne exactement comme avant (mot de passe seul).
 *
 *  MISE EN PLACE
 *  -------------
 *   1. Enregistrez le site dans l'admin Vestikan (Sites satellites).
 *      redirect_uri à déclarer = l'URL EXACTE de vestikan/vestikan-callback.php sur votre
 *      serveur (en HTTPS), par ex. https://mon-domaine.tld/vestikan/vestikan-callback.php
 *   2. Copiez ce fichier en « vestikan-config.php » (sans -sample).
 *   3. Renseignez les 4 valeurs ci-dessous.
 *   4. NE VERSIONNEZ PAS vestikan-config.php (il est déjà dans .gitignore).
 *
 *  Le client_secret ne doit jamais transiter par le navigateur : il n'est
 *  utilisé que côté serveur, dans l'échange back-channel du SDK.
 * ─────────────────────────────────────────────────────────────────────────────
 */

return [
    'base_url'      => 'https://concepts.esenjin.xyz/vestikan',
    'client_id'     => 'vk_client_xxxxxxxxxxxxxxxx',
    'client_secret' => 'xxxxxxxx...(64 caractères hex)...',
    // Doit correspondre CARACTÈRE POUR CARACTÈRE à la redirect_uri enregistrée
    // dans l'admin Vestikan.
    'redirect_uri'  => 'https://mon-domaine.tld/vestikan/vestikan-callback.php',
];
