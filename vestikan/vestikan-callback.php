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
require __DIR__ . '/../config.php';
// Rétablit le dossier de travail à la racine du projet : ce script vit dans
// vestikan/ mais config.php et get_db() résolvent leurs chemins (bdd/, uploads/…)
// depuis la racine.
chdir(__DIR__ . '/..');

require __DIR__ . '/vestikan.php';

if (!vestikan_enabled()) {
    header('Location: ../login.php');
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
    header('Location: ../login.php?sso_error=1');
    exit;
}

// Identité maître prouvée → on ouvre l'accès exactement comme le login classique.
// La session PHP est déjà active (complete() l'a démarrée via le handler SQLite).
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
session_regenerate_id(true); // rotation de l'ID de session après authentification

// Fenêtre « 7 jours glissants » : on pose immédiatement un cookie de 7 jours sur
// la réponse du callback. Le maintien du glissement est ensuite assuré, à chaque
// requête authentifiée, par refresh_session_cookie() appelé dans includes/auth.php.
// (NB : contrairement à ce qu'indiquait l'ancien commentaire ici, le cookie ne
//  « retombait » pas en cookie de session — la vraie limite était l'absence de
//  ré-émission périodique, corrigée de façon centralisée.)
refresh_session_cookie();

$_SESSION['logged_in']   = true;
$_SESSION['vestikan_id'] = $vestikanId; // informatif (traçabilité), non requis

// Destination mémorisée dans begin() (ici 'admin.php'), sinon repli.
$dest = $vk->popReturnTo() ?: '../admin.php';

// Garde-fou anti-open-redirect : on n'autorise qu'une cible interne relative.
if (!preg_match('#^[A-Za-z0-9._/-]+$#', $dest) || str_starts_with($dest, '//')) {
    $dest = '../admin.php';
}

header('Location: ' . $dest);
exit;
