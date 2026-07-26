<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 *  Intégration Vestikan pour Lengas — point d'entrée commun
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Lengas est un site MONO-UTILISATEUR protégé par un simple mot de passe :
 *  c'est le cas « Pattern A » de Vestikan (aucun compte local, une identité
 *  maître validée suffit à ouvrir l'accès).
 *
 *  Ce fichier :
 *    - charge le SDK ;
 *    - détecte si le SSO est configuré (fichier de config présent + complet) ;
 *    - fournit vestikan_client() qui instancie le SDK à la demande.
 *
 *  Si la config est absente, vestikan_enabled() renvoie false et Lengas se
 *  comporte comme avant (connexion par mot de passe uniquement).
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/vestikan-sdk.php';

/**
 * Charge la configuration Vestikan si elle existe et qu'elle est complète.
 * @return array<string,string>|null
 */
function vestikan_config(): ?array {
    static $cfg = false; // false = pas encore chargé
    if ($cfg !== false) {
        return $cfg;
    }
    $path = __DIR__ . '/vestikan-config.php';
    if (!is_file($path)) {
        return $cfg = null;
    }
    $data = require $path;
    // Config valable seulement si les champs critiques sont non vides.
    $required = ['base_url', 'client_id', 'client_secret', 'redirect_uri'];
    foreach ($required as $k) {
        if (!is_array($data) || empty($data[$k])) {
            return $cfg = null;
        }
    }
    return $cfg = $data;
}

/** Le SSO Vestikan est-il configuré et actif ? */
function vestikan_enabled(): bool {
    return vestikan_config() !== null;
}

/**
 * Instancie le client Vestikan. À n'appeler que si vestikan_enabled().
 * @throws VestikanException si la config est incomplète.
 */
function vestikan_client(): Vestikan {
    $cfg = vestikan_config();
    if ($cfg === null) {
        throw new VestikanException('Vestikan non configuré.');
    }
    return new Vestikan($cfg);
}
