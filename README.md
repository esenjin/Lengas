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
- Activer un mode privé pour cacher votre bibliothèque

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
- Statuts de publication : en cours, terminée, en pause, abandonnée
- Association à une fiche MangaUpdates (URL) pour le suivi du nombre de tomes et du statut de publication
- Remplissage automatique des URL MangaUpdates en masse via l'outil « Associer MangaUpdates » (recherche par titre + auteur)

### Suivi des tomes
- Ajout, modification et suppression de tomes
- Statut de lecture personnalisable (à lire, en cours, terminé)
- Gestion des tomes collectors et des derniers tomes

### Statuts de lecture
- Suivi du statut de lecture par série : à débuter, en cours, terminée, abandonnée
- Marquage « Lue ailleurs » pour les séries lues sans les posséder (bibliothèque, ami, revendue…)
- Marquage « Lecture abandonnée » pour les séries dont on a arrêté la lecture

### Liste d'envies
- Page dédiée (`page-wishlist.php`) avec recherche et tri
- Ajout, modification et suppression de séries dans la liste
- Ajout rapide vers la collection avec pré-remplissage automatique du formulaire

### Gestion des prêts
- Page dédiée (`page-prets.php`) pour suivre les tomes prêtés et à qui
- Prêt d'un tome unique ou d'une plage de tomes d'une même série
- Retour de prêt unitaire ou en masse par série

### Gestion des lues ailleurs
- Suivi des séries lues sans les posséder physiquement

### Statistiques
- Nombre de séries, tomes, répartition par statut
- Statistiques détaillées : auteurs, éditeurs, formats, progression de lecture

### Vérifications de la collection
- **Séries incomplètes** : détecte les tomes manquants en comparant votre collection au nombre de tomes indiqué par MangaUpdates (le décompte VF est privilégié lorsqu'il est disponible) ; filtrage et tri des résultats, option de forçage pour ignorer le cache
- **Incohérences** : repère les anomalies (doublons, numéros manquants, mauvais tag « dernier tome », statut différent de MangaUpdates, etc.) avec possibilité d'édition rapide directement depuis la modale

### Navigation
- Sidebar verticale à icônes, accessible sur toutes les pages d'administration
- Drawer mobile avec bouton hamburger et fermeture par overlay ou touche Échap
- Ouverture directe des modales via les liens de la sidebar (ou redirection depuis les pages secondaires)

### Outils (modale « Outils », organisée en onglets)
- **Sauvegardes** : création et téléchargement d'archives de vos données, liste des sauvegardes existantes
- **Association MangaUpdates** : recherche automatique d'une fiche pour chaque série sans URL (corrélation titre + auteur), avec progression en direct et validation avant enregistrement
- **Vérification d'intégrité** : contrôle des fichiers, des permissions, de la structure de la base de données, de la connectivité à l'API MangaUpdates, de l'accessibilité externe des dossiers sensibles et de la version installée

### Options
- Nom, description et titres de pages personnalisables
- Jusqu'à 3 boutons de liens personnalisés affichés sur la page publique
- Mode privé, masquage des séries matures
- Remplacement de la vignette par défaut
- Modification du mot de passe administrateur

### Interface
- Design sombre et responsive
- Modales pour toutes les actions de gestion
- Tri et filtrage des séries (nom, auteur, éditeur, catégories, nombre de tomes)
- Filtres par statut de publication, statut de lecture, contenu mature, favoris, lues ailleurs
- Indicateur de mise à jour disponible (vérification automatique sur Gitea)

### Sécurité
- Mode privé pour cacher votre bibliothèque
- Sessions stockées en base SQLite (7 jours, cookie sécurisé HttpOnly/SameSite)
- Gestion des mots de passe et des sessions
- Blocage de l'accès direct aux dossiers `bdd/` et `saves/` via `.htaccess`

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
5. Téléverser les fichiers/dossiers extraits sur votre serveur
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

## Structure des fichiers

```
lengas/
├── index.php            # Page publique
├── admin.php            # Interface d'administration
├── stats.php            # Page des statistiques
├── page-prets.php       # Page de gestion des prêts
├── page-wishlist.php    # Page de la liste d'envies
├── config.php           # Configuration et initialisation de la base SQLite
├── login.php            # Connexion
├── logout.php           # Déconnexion
├── .htaccess
├── assets/
│   ├── css/             # Fichiers CSS
│   │   ├── _admin.css
│   │   ├── _base.css
│   │   ├── _buttons.css
│   │   ├── _forms.css
│   │   ├── _layout.css
│   │   ├── _modals.css
│   │   ├── _pages.css       # Mise en page des pages dédiées (prêts, wishlist)
│   │   ├── _public.css
│   │   ├── _responsive.css
│   │   ├── _series.css
│   │   ├── _sidebar.css     # Sidebar de navigation
│   │   ├── _stats.css       # Page des statistiques
│   │   ├── _utils.css
│   │   ├── _variables.css
│   │   └── main.css
│   ├── img/             # Images (logo, favicon)
│   │   ├── logo.png
│   │   ├── favicon.ico
│   │   └── mulogo.png
│   └── js/              # Scripts JavaScript
│       ├── admin/
│       │   ├── modals.js
│       │   ├── autocomplete.js
│       │   ├── series.js
│       │   ├── volumes.js
│       │   ├── wishlist.js
│       │   ├── loans.js
│       │   ├── tools.js
│       │   ├── pagination.js
│       │   └── main.js
│       ├── stats.js
│       └── public.js
├── includes/
│   ├── auth.php          # Gestion de l'authentification et des sessions
│   ├── helpers.php       # Fonctions utilitaires générales
│   ├── mangaupdates.php  # API MangaUpdates (suivi des tomes et du statut)
│   └── sidebar.php       # Composant de navigation latérale
├── fonctions/
│   ├── series.php        # Fonctions de gestion des séries
│   ├── volumes.php       # Fonctions de gestion des tomes
│   ├── wishlist.php      # Fonctions de gestion de la liste d'envies
│   ├── loans.php         # Fonctions de gestion des prêts
│   ├── read.php          # Fonctions de gestion des lues ailleurs
│   ├── options.php       # Fonctions de gestion des options du site
│   └── tools.php         # Fonctions de gestion des outils (sauvegardes, intégrité, etc.)
├── uploads/             # Images des séries (chmod 0774)
├── saves/               # Sauvegardes de la base de données (chmod 0774)
└── bdd/                 # Fichiers de données (chmod 0774)
   └── lengas.db         # Base de données SQLite (chmod 0660)
```

## Crédits
- Développé avec l'aide de [Mistral](https://chat.mistral.ai/) et [Claude](https://claude.ai/)
- Utilise l'API de [MangaUpdates](https://api.mangaupdates.com/)
- Utilise [JSDelivr](https://www.jsdelivr.com/)
- Icônes via [Iconify / Material Design Icons](https://iconify.design/)