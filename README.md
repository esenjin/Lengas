# Lengas - Gestion de collection de mangas/light-novels

## Sommaire
- [Description](#description)
- [Aperçu visuel](#aperçu-visuel)
- [Fonctionnalités](#fonctionnalités)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Mise à jour classique](#mise-à-jour-classique)
- [Mise à jour depuis d'anciennes versions majeures](#mise-à-jour-depuis-danciennes-versions-majeures)
- [Importer une base de données](#importer-une-base-de-données)
- [Comment vérifier les sorties françaises avec Babengas](#comment-vérifier-les-sorties-françaises-avec-babengas)
- [Comment se connecter avec Vestikan](#comment-se-connecter-avec-vestikan)
- [Structure des fichiers](#structure-des-fichiers)
- [Crédits](#crédits)

---

## Description
Lengas est une application web légère et intuitive pour gérer et suivre votre collection de mangas et light-novels. Elle vous permet de :

- Visualiser et organiser votre collection
- Suivre l'état de lecture de chaque tome (à lire, en cours, terminé)
- Ajouter, modifier et supprimer des séries et des tomes
- Consulter des statistiques détaillées sur votre collection
- Gérer une liste d'envies pour les séries que vous souhaitez acquérir
- Recevoir des notifications pour les tomes manquants ou incorrectement étiquetés
- Marquer les tomes collectors et les derniers tomes
- Gérer les prêts de tomes à vos amis
- Rédiger des critiques (avis) sur vos séries, mises en forme en Markdown et visibles par vos visiteurs
- Activer un mode privé pour cacher votre bibliothèque
- Choisir un thème (clair, sombre ou personnalisé)
- Vérifier le nombre de tomes parus en France avec Babengas (Babelio, facultatif)
- Vous connecter avec Vestikan (SSO facultatif)

---

## Aperçu visuel
Publique

![Lengas p1](https://concepts.esenjin.xyz/cyla/fichiers/6a311cb71a7ef_1781603511.png)
![Lengas p2](https://concepts.esenjin.xyz/cyla/fichiers/6a311cb6d9c84_1781603510.png)
![Lengas p3](https://concepts.esenjin.xyz/cyla/fichiers/6a311cb6e03dd_1781603510.png)

Administration

![Lengas a1](https://concepts.esenjin.xyz/cyla/fichiers/6a311c674c52f_1781603431.png)
![Lengas a2](https://concepts.esenjin.xyz/cyla/fichiers/6a311c76b7051_1781603446.png)
![Lengas a3](https://concepts.esenjin.xyz/cyla/fichiers/6a311c8164e07_1781603457.png)
![Lengas a4](https://concepts.esenjin.xyz/cyla/fichiers/6a311c8da4f5e_1781603469.png)

Mobile

![Lengas m1](https://concepts.esenjin.xyz/cyla/fichiers/6a311ca6cb4d1_1781603494.png)

*Captures effectuées en v.3.4.0*

---

## Fonctionnalités
### Gestion des séries
- Ajout, modification et suppression de séries
- Association à une fiche MangaUpdates (URL) pour le suivi du nombre de tomes et du statut de publication
- Remplissage automatique des URL MangaUpdates en masse via l'outil « Associer MangaUpdates » de la page « Outils » (recherche par titre + auteur)
- Association à une fiche Babelio (URL) pour connaître le nombre de tomes réellement parus en France, via le service Babengas

### Suivi des tomes
- Ajout, modification et suppression de tomes
- Statut de lecture personnalisable
- Gestion des tomes collectors et des derniers tomes

### Statuts de lecture
- Suivi du statut de lecture par série : à débuter, en cours, terminée, abandonnée
- Marquage « Lue ailleurs » pour les séries lues sans les posséder (bibliothèque, ami, revendue…)
- Marquage « Lecture abandonnée » pour les séries dont on a arrêté la lecture

### Profil de l'administrateur
- Page « Profil » dédiée (icône compte du menu latéral admin) pour se présenter aux visiteurs
- Photo de profil (téléversée dans `uploads/`), pseudo, biographie en Markdown (même éditeur et même rendu que les critiques) et liens sociaux illimités (même sélecteur d'icône et de couleur que les liens personnalisés du menu latéral)
- Le pseudo, auparavant réglé dans les Options, se gère désormais ici (il continue de créditer les critiques)
- Sur la page d'accueil publique, un bouton « Qui suis-je ? » ouvre une modale présentant le profil (affiché uniquement si au moins un champ est renseigné)

### Critiques- Rédaction d'un avis par série via un éditeur Markdown dédié (page « Critiques »)
- Éditeur avec aperçu en direct, barre de mise en forme flottante (gras, italique, souligné, barré, titres, listes, citations, code, liens, images et médias) et raccourcis annuler/rétablir
- Mise en forme réversible : re-cliquer sur un style déjà appliqué à la sélection le retire
- Insertion de médias externes (YouTube, Vimeo, SoundCloud, ou fichiers audio/vidéo directs)
- Alerte lorsque la série n'est pas (ou pas entièrement) marquée comme lue
- Filtre « Avec critique » et badge ✏️ sur la page publique ; les visiteurs consultent la critique dans une modale dédiée

### Notation
- Note subjective facultative par série, au choix parmi trois valeurs : ☺️ « J'ai apprécié », 😑 « Mi-figue mi-raisin », 😠 « Je n'ai pas aimé »
- Réglable à l'ajout et à la modification d'une série (menu déroulant « Notation »)
- Affichée sous forme de badge emoji (texte au survol) : dans la carte de série côté administration, et dans la modale de détails côté public

### Liste d'envies
- Ajout et suppression de séries dans une liste d'envies
- Possibilité d'ajouter une série de la liste d'envies à votre collection

### Gestion des prêts
- Prêt d'un tome unique ou d'une plage de tomes d'une même série
- Retour de prêt unitaire ou en masse par série

### Séries à lire
- Affiche les séries qui ne sont pas entièrement lues.

### Gestion des lues ailleurs
- Suivi des séries lues non-présentes dans la bibliothèque

### Gestion des prêts
- Suivi des tomes prêtés et à qui

### Statistiques
- Nombre de séries, tomes, répartition par statut, etc.

### Thèmes
- Thèmes de base fournis : « Sombre » (par défaut) et « Clair »
- Thèmes personnalisés : déposez un fichier `assets/css/_variables-<nom>.css` pour l'ajouter automatiquement à la liste

### Outils (page dédiée « Outils », organisée en onglets)
Tous les outils du site sont regroupés sur la page `pages/page-outils.php`, accessible via l'icône clé à molette du menu latéral. L'onglet actif est mémorisé dans l'URL (ex. `pages/page-outils.php#integrity`), ce qui permet de partager un lien direct.

- **Séries incomplètes** : détecte les tomes manquants en comparant votre collection au nombre de tomes indiqué par MangaUpdates (le décompte VF est privilégié lorsqu'il est disponible), avec progression en direct, filtres et ajout des tomes manquants
- **Incohérences** : repère les anomalies (doublons, numéros manquants, mauvais tag « dernier tome », statut différent de MangaUpdates, prêts orphelins, etc.) et propose une édition rapide de la série concernée
- **Sauvegardes** : création et téléchargement d'archives de vos données, ainsi que l'export JSON complet
- **Association MangaUpdates** : recherche automatique d'une fiche pour chaque série sans URL (corrélation titre + auteur), avec progression en direct et validation avant enregistrement ; un second outil récupère de la même façon les genres manquants
- **Vérification d'intégrité** : compare automatiquement votre instance au dépôt Gitea, **au tag correspondant à votre version installée** (si aucun tag ne correspond, la comparaison se fait avec la version la plus récente et le signale). Pour chaque fichier versionné, elle vérifie la **présence** ET le **contenu** (comparaison d'empreinte : « OK », « Modifié » ou « Manquant ») — plus besoin de maintenir une liste de fichiers à la main. Elle repère aussi les **fichiers étrangers au dépôt** (présents sur l'instance mais absents du dépôt, hors données `uploads/` `saves/` `bdd/`, config Vestikan, thèmes personnalisés et photo de profil de l'admin), l'**état des modules facultatifs** Vestikan et Babengas (installés ? réellement activés ? service distant fonctionnel ?), les permissions, les fichiers interdits, les doublons, les images orphelines (la photo de profil de l'admin n'est jamais considérée comme orpheline), l'accès externe aux dossiers sensibles, la structure de la base de données, les thèmes personnalisés présents et la connectivité à l'API MangaUpdates

### Options (page dédiée « Options »)
Toutes les options du site sont regroupées sur la page `pages/page-options.php`, accessible via l'icône engrenage du menu latéral.

- Nom, description et titres de pages personnalisables
- Nombre illimité de liens personnalisés affichés dans le menu latéral public (bouton « Ajouter un lien personnalisé »), chacun avec une icône choisie via un sélecteur visuel (aperçu, recherche, catégories ; une trentaine d'icônes : médias, flux RSS, réseaux, etc.) et une couleur au choix dans une palette prédéfinie accordée au thème
- Réglages des statistiques (temps de lecture et valeur d'un tome, par catégorie)
- Choix du thème (clair, sombre ou personnalisé)
- Mode privé, masquage des séries matures, masquage des critiques
- Remplacement de la vignette par défaut
- Configuration du service Babengas (facultatif)
- Modification du mot de passe administrateur

### Interface intuitive
- Design sombre et responsive
- Modales pour les actions
- Tri et filtrage des séries

### Sécurité
- Mode privé pour cacher votre bibliothèque
- Gestion des mots de passe et des sessions
- Connexion SSO Vestikan facultative

---

## Prérequis
- Serveur web (Apache, Nginx)
- PHP 7.4 ou supérieur

---

## Installation
1. Télécharger la dernière publication
2. Éditer le fichier `generate_password.php` en y indiquant le mdp souhaité
3. Téléverser les fichiers sur votre serveur
4. Exécuter le fichier `generate_password.php`
5. SUPPRIMER LE FICHIER `generate_password.php`
6. C'est tout bon ! Vous pouvez profiter.

---

## Mise à jour classique
1. Télécharger la dernière publication
2. Extraire l'archive téléchargée
3. Y SUPPRIMER LE FICHIER `generate_password.php`
4. Sur votre serveur, tout supprimer SAUF les dossiers `bdd/`, `saves/` et `uploads/` (ni ce qu'ils contiennent)
5. Téléverser les fichiers/dossiers extrait sur votre serveur
6. Bien joué, c'est à jour !

---

## Mise à jour depuis d'anciennes versions majeures
Lors d'une mise à jour, **NE JAMAIS SAUTER PLUSIEURS VERSIONS MAJEURES**, merci de les faire une par une. Voici l'ordre à respecter :

- **1.x vers 2.0** suivre les instructions de [la publication de la version](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/2.0.0). 
  - Point important : Refacto complet du code.
- **2.x vers 3.0** suivre les instructions de [la publication de la version](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/3.0.0). 
  - Points importants : Passage de l'enregistrement des données en base de JSON à SQlite. Récupération des données des séries via Nautiljon.
- **3.0 vers 3.1** suivre les instructions de [la publication de la version](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/3.1.0). 
  - Point important : Suppression de la récupération des informations via Nautiljon (ne fonctionne pas).

> Exemple : Je suis en 2.2.1, la dernière version est la 3.9.0, je dois d'abord installer la 3.0, puis la 3.1 et enfin passer sur la dernière, la 3.9.0.

Elles ne sont pas obligatoire, mais il est recommandé de passer par les versions suivantes, si vous venez d'une version antérieur à celles-ci :

- [3.3.0](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/3.3.0), pour migrer vos séries "lues ailleurs" vers le nouveau système (uniquement si vous êtes sur une version 2.1.0 ou supérieur, les "lues ailleurs" n'existaient pas avant).
- [3.6.0](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/3.6.0), pour enregistrer en masse les dates de lecture des séries.
- [3.9.0](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/3.9.0), pour ajouter en masse des urls Babelio aux séries.

---

## Importer une base de données
1. Créer une sauvegarde avec l'outil dédié (page "Outils", onglet "Sauvegardes")
2. Extraire l'archive
3. (facultatif) Supprimer le dossier `uploads/` et le fichier `bdd/lengas.db` de votre site
4. Déplacer les dossiers `bdd/` et `uploads/` que vous venez d'extraire à la racine de votre site (écraser les fichiers si nécessaire)
5. (facultatif) Utiliser l'outil de vérification de l'intégrité du site (page "Outils", onglet "Vérification d'intégrité")
6. Félicitation, votre base de données est de nouveau là !

---

## Comment vérifier les sorties françaises avec Babengas

[Babengas](https://git.crystalyx.net/Esenjin_Asakha/Babengas) est un microservice Docker qui interroge Babelio pour connaître le nombre de tomes **réellement parus en France**. Il complète MangaUpdates, dont le décompte se base surtout sur l'édition l'origine (VO) et renseigne rarement l'édition française.

Son intégration à Lengas est **entièrement facultative** : sans les fichiers Babengas ni la configuration dans les options, la fonctionnalité reste invisible et le site fonctionne normalement.

Babelio filtrant les IP d'hébergeurs, Babengas doit tourner sur une machine à IP résidentielle (un homelab), exposée en HTTPS via un reverse proxy. Une fois le service en ligne, renseignez son URL et sa clé partagée dans les options du site (page « Options », section « Babengas ») : un onglet « Vérification Babelio » apparaît alors sur la page « Outils ».

Chaque série à vérifier doit disposer d'une **URL de fiche série Babelio** (champ dédié à l'ajout et à la modification), au format `/serie/SLUG/ID` :

```
https://www.babelio.com/serie/Silent-Witch/54358
```

Pour un **one-shot** (série d'un seul tome, qui n'a pas de fiche série sur Babelio), collez plutôt l'adresse de la fiche du tome unique (`/livres/SLUG/ID`) : Lengas la reconnaît et la traite localement, sans passer par Babengas.

Le traitement est volontairement lent — une série toutes les cinq minutes, par courtoisie envers Babelio. Une campagne se lance puis se poursuit en arrière-plan : vous pouvez fermer la page, le suivi reprend à votre retour. L'état des fichiers Babengas apparaît dans l'outil de vérification d'intégrité (une absence y est signalée en orange « Absent », car non bloquante), qui indique en plus si le module est **réellement activé** (URL + clé renseignées, case cochée) et si le **microservice répond** (sonde `/sante`, avec sa version).

> ⚠️ Babengas ne remonte **pas** le statut de publication : la fiche Babelio affiche « En cours » y compris sur des séries terminées depuis des années. Ce statut reste géré par MangaUpdates ou saisi manuellement.

Pour installer Babengas sur son homelab :
- Lire : [Babengas/README.md](https://git.crystalyx.net/Esenjin_Asakha/Babengas/src/branch/main/README.md)

---

## Comment se connecter avec Vestikan

Vestikan est un système de connexion SSO (« Se connecter avec Vestikan »). Son intégration à Lengas est **entièrement facultative** : sans les fichiers Vestikan ni le fichier `vestikan/vestikan-config.php`, le site reste **100 % fonctionnel** et la connexion se fait par mot de passe comme d'habitude.

Lorsqu'il est configuré, un bouton « Se connecter avec Vestikan » apparaît sur la page de connexion, en complément du mot de passe. L'état de la connexion (active / inactive) est visible dans les options du site, sous le champ de mot de passe, et le détail des fichiers Vestikan apparaît dans l'outil de vérification d'intégrité (page « Outils ») (une absence y est signalée en orange « Absent », car non bloquante). L'outil indique en plus si le SSO est **réellement activé** (fichier `vestikan/vestikan-config.php` présent et complet) et si le **serveur Vestikan répond** (sonde de l'URL d'autorisation).

Pour l'activer :
- Guide d'intégration : [Vestikan/INTEGRATION.md](https://git.crystalyx.net/Esenjin_Asakha/Vestikan/src/branch/main/INTEGRATION.md)
- Installer sa propre instance de Vestikan : [Vestikan/README.md](https://git.crystalyx.net/Esenjin_Asakha/Vestikan/src/branch/main/README.md)

---

## Structure des fichiers

```
lengas/
├── index.php              # Page publique
├── admin.php              # Interface d'administration
├── stats.php              # Page des statistiques
├── notation.php           # Notation rapide en masse (script autonome, facultatif)
├── config.php             # Configuration du site
├── login.php              # Connexion
├── logout.php             # Déconnexion
├── babengas-ping.php      # Endpoint de test Babengas (facultatif)
├── .htaccess
├── pages/                 # Pages secondaires de l'administration
│   ├── page-prets.php     # Page de gestion des prêts
│   ├── page-wishlist.php  # Page de la liste d'envies
│   ├── page-critiques.php # Page de rédaction des critiques + rendu Markdown
│   ├── page-profil.php    # Page du profil de l'admin (photo, pseudo, bio, liens sociaux)
│   ├── page-outils.php    # Page des outils (+ endpoints SSE/POST associés)
│   └── page-options.php   # Page des options du site (configuration + mise à jour)
├── vestikan/              # Connexion SSO Vestikan (facultatif, non versionné pour la config)
│   ├── vestikan-login.php    # Démarrage de la connexion Vestikan
│   ├── vestikan-callback.php # Callback OAuth Vestikan
│   ├── vestikan.php          # Point d'entrée SSO Vestikan
│   ├── vestikan-sdk.php      # SDK Vestikan
│   └── vestikan-config.php   # Configuration SSO Vestikan (non versionné)
├── assets/
│   ├── css/               # Fichiers CSS
│   │   ├── _admin.css
│   │   ├── _base.css
│   │   ├── _buttons.css
│   │   ├── _forms.css
│   │   ├── _layout.css
│   │   ├── _modals.css
│   │   ├── _pages.css
│   │   ├── _public.css
│   │   ├── _responsive.css
│   │   ├── _reviews.css
│   │   ├── _series.css
│   │   ├── _sidebar.css
│   │   ├── _stats.css
│   │   ├── _utils.css
│   │   ├── _variables.css
│   │   ├── _variables-light.css
│   │   └── main.css
│   ├── img/               # Images (logo, favicon)
│   │   ├── logo.png
│   │   ├── favicon.ico
│   │   ├── babelogo.png
│   │   └── mulogo.png
│   └── js/                # Scripts JavaScript
│       ├── admin/
│       │   ├── modals.js
│       │   ├── autocomplete.js
│       │   ├── series.js
│       │   ├── volumes.js
│       │   ├── wishlist.js
│       │   ├── loans.js
│       │   ├── pagination.js
│       │   ├── reviews.js
│       │   ├── main.js
│       │   └── tools/                    # Un fichier par outil
│       │       ├── page.js               # Socle commun (onglets, modales, helpers)
│       │       ├── incomplete.js         # Séries incomplètes
│       │       ├── coherence.js          # Incohérences
│       │       ├── backups.js            # Sauvegardes et export JSON
│       │       ├── mangaupdates-assoc.js # Association fiches + genres MangaUpdates
│       │       └── integrity.js          # Vérification d'intégrité
│       ├── stats.js
│       └── public.js
├── includes/
│   ├── auth.php              # Gestion de l'authentification et des sessions
│   ├── helpers.php           # Fonctions utilitaires générales
│   ├── mangaupdates.php      # API MangaUpdates (suivi des tomes et du statut)
│   ├── sidebar.php           # Menu latéral à icônes de l'administration
│   ├── public-sidebar.php    # Menu latéral à icônes des pages publiques (accueil et statistiques)
│   ├── custom_icons.php      # Icônes, couleurs et lecture des liens personnalisés (partagé options/sidebar)
│   ├── themes.php            # Gestion des thèmes (base + personnalisés)
│   └── status_filter.php     # Filtrage des séries par statut
├── fonctions/
│   ├── series.php        # Fonctions de gestion des séries
│   ├── volumes.php       # Fonctions de gestion des tomes
│   ├── wishlist.php      # Fonctions de gestion de la liste d'envies
│   ├── loans.php         # Fonctions de gestion des prêts
│   ├── read.php          # Fonctions de gestion des lues ailleurs
│   ├── options.php       # Fonctions de gestion des options du site
│   ├── reviews.php       # Fonctions de gestion des critiques (stockage + rendu Markdown)
│   ├── tools.php         # Chargeur des outils (inclut fonctions/tools/)
│   └── tools/            # Un fichier de fonctions par outil
│       ├── backups.php            # Sauvegardes ZIP et export JSON
│       ├── integrity.php          # Vérification d'intégrité + infos serveur
│       ├── cleanup.php            # Nettoyages (doublons, images orphelines, fichiers interdits)
│       ├── mangaupdates_assoc.php # Association des fiches et des genres MangaUpdates
│       ├── incomplete.php         # Séries incomplètes (tomes manquants)
│       └── coherence.php          # Incohérences de la collection
├── uploads/              # Images des séries (chmod 0774)
├── saves/                # Sauvegardes de la base de données (chmod 0774)
└── bdd/                  # Fichiers de données (chmod 0774)
   └── lengas.db          # Base de données SQLite (chmod 0660)
```

> Note : `vestikan/vestikan-config.php` contient le `client_secret` et ne doit jamais être versionné (il est dans `.gitignore`). Son absence désactive simplement le SSO.

---

## Crédits
- Développé avec l'aide de [Claude](https://claude.ai/)
- Utilise l'API de [MangaUpdates](https://api.mangaupdates.com/)
- Utilise [JSDelivr](https://www.jsdelivr.com/)
- Icônes via [Iconify / Material Design Icons](https://iconify.design/)
- Connexion SSO facultative via [Vestikan](https://git.crystalyx.net/Esenjin_Asakha/Vestikan)
- Extension Docker facultative [Babengas](https://git.crystalyx.net/Esenjin_Asakha/Babengas)