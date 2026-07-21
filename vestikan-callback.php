<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 *  Callback Vestikan (redirect_uri) — Pattern A (site mono-utilisateur)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Reçoit le retour de Vestikan, vérifie le state, échange le code, et récupère
 *  le vestikan_id. Comme Lengas est mono-utilisateur, une identité maître
 *  validée SUFFIT à ouvrir l'accès : on positionne le même drapeau de session
 *  que la connexion par mot de passe ($_SESSION['logged_in']), donc auth.php
 *  et le reste de l'app fonctionnent sans modification.
 * ─────────────────────────────────────────────────────────────────────────────
 */
require 'config.php';
require 'includes/vestikan.php';

if (!vestikan_enabled()) {
    header('Location: login.php');
    exit;
}

// Même handler de session que begin() : indispensable pour retrouver le state.
register_session_handler();

$vk = vestikan_client();

try {
    // Lève une VestikanException si le flow échoue (state, code, réseau…).
    $vestikanId = $vk->complete();
} catch (VestikanException $e) {
    // Un échec Vestikan = refus d'authentification : on n'ouvre AUCUNE session.
    error_log('[Lengas] Connexion Vestikan refusée : ' . $e->getMessage());
    header('Location: login.php?sso_error=1');
    exit;
}

// Identité maître prouvée → on ouvre l'accès exactement comme le login classique.
// La session PHP est déjà active (complete() l'a démarrée via le handler SQLite).
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
session_regenerate_id(true); // rotation de l'ID de session après authentification

// session_regenerate_id() ré-émet le cookie de session en reprenant les
// paramètres de cookie COURANTS. Après le flow Vestikan (plusieurs session_start
// successifs dont ceux du SDK, qui n'appliquent pas nos cookie params), le
// lifetime de 7 jours peut être perdu et le cookie retombe en cookie de session
// (expire à la fermeture du navigateur) — d'où l'absence de « 7 jours flottants »
// constatée uniquement en connexion Vestikan. On force donc explicitement le
// renouvellement du cookie avec le bon lifetime.
$lifetime = 7 * 24 * 60 * 60; // 7 jours (identique à register_session_handler())
setcookie(session_name(), session_id(), [
    'expires'  => time() + $lifetime,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
$_SESSION['logged_in']   = true;
$_SESSION['vestikan_id'] = $vestikanId; // informatif (traçabilité), non requis

// Destination mémorisée dans begin() (ici 'admin.php'), sinon repli.
$dest = $vk->popReturnTo() ?: 'admin.php';

// Garde-fou anti-open-redirect : on n'autorise qu'une cible interne relative.
if (!preg_match('#^[A-Za-z0-9._/-]+$#', $dest) || str_starts_with($dest, '//')) {
    $dest = 'admin.php';
}

header('Location: ' . $dest);
exit;
