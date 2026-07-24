<?php
// ────────────────────────────────────────────────────────────────────────────
// babengas-ping.php — Webhook entrant de Babengas
//
// Babengas y poste l'avancement d'une campagne au plus une fois par heure,
// puis une fois à la clôture. Le corps est du JSON :
//   { "campagne_id": "...", "statut": "en_cours", "total": 144,
//     "traites": 58, "progression": 40 }
//
// L'appel est authentifié par le header X-Babengas-Key, comparé à la clé
// enregistrée dans les options de Lengas.
//
// Ce webhook est un CONFORT, pas une dépendance : un ping perdu n'a aucune
// conséquence, le sondage de GET /campagne/{id} reste la source de vérité.
// C'est pourquoi ce fichier ne fait qu'enregistrer la progression, sans
// toucher à la collection.
//
// Ce point d'entrée est PUBLIC (Babengas n'a pas de session Lengas) : il
// n'inclut donc PAS includes/auth.php. La clé partagée fait foi.
// ────────────────────────────────────────────────────────────────────────────

require 'config.php';
require_once 'includes/babengas.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Méthode ──────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['erreur' => 'methode_non_autorisee']);
    exit;
}

// ── Intégration configurée ? ─────────────────────────────────────────────────
$key = babengas_key();
if ($key === '') {
    http_response_code(503);
    echo json_encode(['erreur' => 'non_configure']);
    exit;
}

// ── Authentification par clé partagée ────────────────────────────────────────
// Les en-têtes personnalisés arrivent en HTTP_X_BABENGAS_KEY ; certaines
// configurations (CGI/FastCGI) les préfixent différemment, d'où le repli.
$received = $_SERVER['HTTP_X_BABENGAS_KEY']
         ?? $_SERVER['REDIRECT_HTTP_X_BABENGAS_KEY']
         ?? '';

if (!is_string($received) || !hash_equals($key, trim($received))) {
    http_response_code(401);
    echo json_encode(['erreur' => 'cle_invalide']);
    exit;
}

// ── Corps JSON ───────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['erreur' => 'json_invalide']);
    exit;
}

$campagne_id = trim((string)($body['campagne_id'] ?? ''));
if ($campagne_id === '') {
    http_response_code(400);
    echo json_encode(['erreur' => 'campagne_id_manquant']);
    exit;
}

// ── On n'accepte que les pings de la campagne suivie ──────────────────────────
// Un ping d'une campagne inconnue (ancienne, annulée localement) est accusé en
// réception mais ignoré : Babengas n'a pas à réémettre pour autant.
$current = babengas_get_current_campaign();
if ($current === null || $current['campagne_id'] !== $campagne_id) {
    echo json_encode(['statut' => 'ignore']);
    exit;
}

// ── Enregistrement de la progression ─────────────────────────────────────────
babengas_set_ping(
    (int)($body['traites']     ?? 0),
    (int)($body['total']       ?? 0),
    (int)($body['progression'] ?? 0),
    (string)($body['statut']   ?? '')
);

echo json_encode(['statut' => 'ok']);
