<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools.php — Chargeur des outils
//
// Les fonctions de chaque outil vivent désormais dans fonctions/tools/, à
// raison d'un fichier par outil. Ce fichier ne fait que les charger : les
// pages continuent donc d'inclure simplement « fonctions/tools.php ».
//
//   backups.php            → Sauvegardes (ZIP) et export JSON
//   integrity.php          → Vérification d'intégrité du site
//   cleanup.php            → Nettoyages proposés par la vérification
//   mangaupdates_assoc.php → Association des fiches et des genres MangaUpdates
//   incomplete.php          → Séries incomplètes (tomes manquants)
//   babengas-helpers.php   → Vérification du décompte VF via Babelio (Babengas)
//   coherence.php          → Incohérences de la collection
//   anilist_import.php     → Import de masse de la liste Anilist
//   anilist_sync.php       → Synchronisation automatique Anilist
//   anilist_recheck.php    → Vérification manuelle des animés
//   grouping.php           → Groupage de licences (suggestions de regroupement)
// ────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/tools/backups.php';
require_once __DIR__ . '/tools/integrity.php';
require_once __DIR__ . '/tools/cleanup.php';
require_once __DIR__ . '/tools/mangaupdates_assoc.php';
require_once __DIR__ . '/tools/incomplete.php';
require_once __DIR__ . '/tools/babengas-helpers.php';
require_once __DIR__ . '/tools/coherence.php';
require_once __DIR__ . '/tools/anilist_import.php';
require_once __DIR__ . '/tools/anilist_sync.php';
require_once __DIR__ . '/tools/anilist_recheck.php';
require_once __DIR__ . '/tools/grouping.php';
