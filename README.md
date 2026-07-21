# Lengas - Gestion de collection de mangas/light-novels

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
- Vous connecter avec Vestikan (SSO facultatif)

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

## Fonctionnalités
### Gestion des séries
- Ajout, modification et suppression de séries
- Association à une fiche MangaUpdates (URL) pour le suivi du nombre de tomes et du statut de publication
- Remplissage automatique des URL MangaUpdates en masse via l'outil « Associer MangaUpdates » (recherche par titre + auteur)

### Suivi des tomes
- Ajout, modification et suppression de tomes
- Statut de lecture personnalisable
- Gestion des tomes collectors et des derniers tomes

### Statuts de lecture
- Suivi du statut de lecture par série : à débuter, en cours, terminée, abandonnée
- Marquage « Lue ailleurs » pour les séries lues sans les posséder (bibliothèque, ami, revendue…)
- Marquage « Lecture abandonnée » pour les séries dont on a arrêté la lecture

### Critiques
- Rédaction d'un avis par série via un éditeur Markdown dédié (page « Critiques »)
- Éditeur avec aperçu en direct, barre de mise en forme flottante (gras, italique, souligné, barré, titres, listes, citations, code, liens, images et médias) et raccourcis annuler/rétablir
- Mise en forme réversible : re-cliquer sur un style déjà appliqué à la sélection le retire
- Insertion de médias externes (YouTube, Vimeo, SoundCloud, ou fichiers audio/vidéo directs)
- Alerte lorsque la série n'est pas (ou pas entièrement) marquée comme lue
- Filtre « Avec critique » et badge ✏️ sur la page publique ; les visiteurs consultent la critique dans une modale dédiée

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
- Recherche des séries incomplètes (via MangaUpdates)

### Thèmes
- Thèmes de base fournis : « Sombre » (par défaut) et « Clair »
- Thèmes personnalisés : déposez un fichier `assets/css/_variables-<nom>.css` pour l'ajouter automatiquement à la liste

### Vérifications de la collection
- **Séries incomplètes** : détecte les tomes manquants en comparant votre collection au nombre de tomes indiqué par MangaUpdates (le décompte VF est privilégié lorsqu'il est disponible)
- **Incohérences** : repère les anomalies (doublons, numéros manquants, mauvais tag « dernier tome », statut différent de MangaUpdates, etc.)

### Outils (modale « Outils », organisée en onglets)
- **Sauvegardes** : création et téléchargement d'archives de vos données
- **Association MangaUpdates** : recherche automatique d'une fiche pour chaque série sans URL (corrélation titre + auteur), avec progression en direct et validation avant enregistrement
- **Vérification d'intégrité** : contrôle des fichiers (dont les thèmes et les fichiers Vestikan), des permissions, de la structure de la base de données, des thèmes personnalisés présents et de la connectivité à l'API MangaUpdates

### Options
- Nom, description et titres de pages personnalisables
- Jusqu'à 3 boutons de liens personnalisés affichés sur la page publique
- Mode privé, masquage des séries matures
- Remplacement de la vignette par défaut
- Modification du mot de passe administrateur

### Interface intuitive
- Design sombre et responsive
- Modales pour les actions
- Tri et filtrage des séries

### Sécurité
- Mode privé pour cacher votre bibliothèque
- Gestion des mots de passe et des sessions
- Connexion SSO Vestikan facultative

## Prérequis
- Serveur web (Apache, Nginx)
- PHP 7.4 ou supérieur

## Installation
1. Télécharger la dernière publication
2. Éditer le fichier `generate_password.php` en y indiquant le mdp souhaité
3. Téléverser les fichiers sur votre serveur
4. Exécuter le fichier `generate_password.php`
5. SUPPRIMER LE FICHIER `generate_password.php`
6. C'est tout bon ! Vous pouvez profiter.

## Mise à jour classique
1. Télécharger la dernière publication
2. Extraire l'archive téléchargée
3. Y SUPPRIMER LE FICHIER `generate_password.php`
4. Sur votre serveur, tout supprimer SAUF les dossiers `bdd/`, `saves/` et `uploads/` (ni ce qu'ils contiennent)
5. Téléverser les fichiers/dossiers extrait sur votre serveur
6. Bien joué, c'est à jour !

## Mise à jour depuis d'anciennes versions majeures
NE JAMAIS SAUTER PLUSIEURS VERSIONS MAJEURES, merci de les faire une par une. D'abord de 1.x vers 2.0, puis 2.0 vers 3.0 par exemple.
- 1.x vers 2.0 suivre les instructions de [la publication de la version](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/2.0.0).
- 2.x vers 3.0 suivre les instructions de [la publication de la version](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/3.0.0).

## Importer une base de données
1. Créer une sauvegarde avec l'outil dédié (modale "Outils")
2. Extraire l'archive
3. (facultatif) Supprimer le dossier `uploads/` et le fichier `bdd/lengas.db` de votre site
4. Déplacer les dossiers `bdd/` et `uploads/` que vous venez d'extraire à la racine de votre site (écraser les fichiers si nécessaire)
5. (facultatif) Utiliser l'outil de vérification de l'intégrité du site (modale "Outils")
6. Félicitation, votre base de données est de nouveau là !

## Comment se connecter avec Vestikan

Vestikan est un système de connexion SSO (« Se connecter avec Vestikan »). Son intégration à Lengas est **entièrement facultative** : sans les fichiers Vestikan ni le fichier `includes/vestikan-config.php`, le site reste **100 % fonctionnel** et la connexion se fait par mot de passe comme d'habitude.

Lorsqu'il est configuré, un bouton « Se connecter avec Vestikan » apparaît sur la page de connexion, en complément du mot de passe. L'état de la connexion (active / inactive) est visible dans les options du site, sous le champ de mot de passe, et le détail des fichiers Vestikan apparaît dans l'outil de vérification d'intégrité (une absence y est signalée en orange « Absent », car non bloquante).

Pour l'activer :
- Guide d'intégration : [INTEGRATION.md](https://git.crystalyx.net/Esenjin_Asakha/Vestikan/src/branch/main/INTEGRATION.md)
- Installer sa propre instance de Vestikan : [README.md](https://git.crystalyx.net/Esenjin_Asakha/Vestikan/src/branch/main/README.md)

## Structure des fichiers

```
lengas/
├── index.php              # Page publique
├── admin.php              # Interface d'administration
├── stats.php              # Page des statistiques
├── page-prets.php         # Page de gestion des prêts
├── page-wishlist.php      # Page de la liste d'envies
├── page-critiques.php     # Page de rédaction des critiques + rendu Markdown
├── config.php             # Configuration du site
├── login.php              # Connexion
├── logout.php             # Déconnexion
├── vestikan-login.php     # Démarrage de la connexion Vestikan (facultatif)
├── vestikan-callback.php  # Callback OAuth Vestikan (facultatif)
├── .htaccess
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
│   │   └── mulogo.png
│   └── js/                # Scripts JavaScript
│       ├── admin/
│       │   ├── modals.js
│       │   ├── autocomplete.js
│       │   ├── series.js
│       │   ├── volumes.js
│       │   ├── wishlist.js
│       │   ├── loans.js
│       │   ├── tools.js
│       │   ├── pagination.js
│       │   ├── read.js
│       │   ├── reviews.js
│       │   └── main.js
│       ├── stats.js
│       └── public.js
├── includes/
│   ├── auth.php              # Gestion de l'authentification et des sessions
│   ├── helpers.php           # Fonctions utilitaires générales
│   ├── mangaupdates.php      # API MangaUpdates (suivi des tomes et du statut)
│   ├── sidebar.php           # Menu latéral à icônes de l'administration
│   ├── public-sidebar.php    # Menu latéral à icônes des pages publiques (accueil et statistiques)
│   ├── custom_icons.php      # Jeu d'icônes des liens personnalisés (partagé options/sidebar)
│   ├── themes.php            # Gestion des thèmes (base + personnalisés)
│   ├── status_filter.php     # Filtrage des séries par statut
│   ├── vestikan.php          # Point d'entrée SSO Vestikan (facultatif)
│   ├── vestikan-sdk.php      # SDK Vestikan (facultatif)
│   └── vestikan-config.php   # Configuration SSO Vestikan (facultatif, non versionné)
├── fonctions/
│   ├── series.php        # Fonctions de gestion des séries
│   ├── volumes.php       # Fonctions de gestion des tomes
│   ├── wishlist.php      # Fonctions de gestion de la liste d'envies
│   ├── loans.php         # Fonctions de gestion des prêts
│   ├── read.php          # Fonctions de gestion des lues ailleurs
│   ├── options.php       # Fonctions de gestion des options du site
│   ├── reviews.php       # Fonctions de gestion des critiques (stockage + rendu Markdown)
│   └── tools.php         # Fonctions de gestion des outils (sauvegardes, intégrité, etc.)
├── uploads/              # Images des séries (chmod 0774)
├── saves/                # Sauvegardes de la base de données (chmod 0774)
└── bdd/                  # Fichiers de données (chmod 0774)
   └── lengas.db          # Base de données SQLite (chmod 0660)
```

> Note : `includes/vestikan-config.php` contient le `client_secret` et ne doit jamais être versionné (il est dans `.gitignore`). Son absence désactive simplement le SSO.

## Crédits
- Développé avec l'aide de [Mistral](https://chat.mistral.ai/) et [Claude](https://claude.ai/)
- Utilise l'API de [MangaUpdates](https://api.mangaupdates.com/)
- Utilise [JSDelivr](https://www.jsdelivr.com/)
- Icônes via [Iconify / Material Design Icons](https://iconify.design/)
- Connexion SSO facultative via [Vestikan](https://git.crystalyx.net/Esenjin_Asakha/Vestikan)