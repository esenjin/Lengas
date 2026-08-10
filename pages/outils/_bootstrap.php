<?php

// Rétablit le dossier de travail à la racine du projet : cette page vit dans
// pages/outils/ (deux crans sous la racine) et tous les chemins relatifs
// (config.php, includes/, bdd/, uploads/…) sont résolus depuis la racine.
chdir(__DIR__ . '/../..');
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/_bootstrap.php — Socle commun des pages « Outils »
//
// Chaque page pages/outils/outil-*.php commence par :
//
//     require __DIR__ . '/_bootstrap.php';
//
// Ce fichier centralise ce qui est identique à tous les outils : mise en
// place du dossier de travail, chargement des dépendances PHP, puis lecture
// de la collection et des options. Garder ce socle à un seul endroit évite
// que les neuf pages outils ne divergent au fil des évolutions du site.
// ────────────────────────────────────────────────────────────────────────────

require 'config.php';
require 'includes/auth.php';
require 'includes/helpers.php';
require 'includes/mangaupdates.php';
require_once 'includes/babengas.php';
require_once 'includes/anilist.php';
require 'fonctions/series.php';
require 'fonctions/anime.php';
require 'fonctions/episodes.php';
require 'fonctions/volumes.php';
require 'fonctions/wishlist.php';
require 'fonctions/loans.php';
require 'fonctions/read.php';
require 'fonctions/options.php';
require 'fonctions/tools.php';
require 'includes/themes.php';
require_once 'includes/opengraph.php';

$data    = load_data();
$options = load_options();
