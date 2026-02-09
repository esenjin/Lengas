# Lengas - Gestion de collection de mangas/light-novels

## Description
Lengas est une application web légère et intuitive pour gérer et suivre votre collection de mangas et light-novels. Elle vous permet de :

- Visualiser et organiser votre collection
- Suivre l’état de lecture de chaque tome (à lire, en cours, terminé)
- Ajouter, modifier et supprimer des séries et des tomes
- Consulter des statistiques détaillées sur votre collection
- Gérer une liste d’envies pour les séries que vous souhaitez acquérir
- Recevoir des notifications pour les tomes manquants ou incorrectement étiquetés
- Marquer les tomes collectors et les derniers tomes
- Gérer les prêts de tomes à vos amis
- Activer un mode privé pour cacher votre bibliothèque

## Fonctionnalités
### Gestion des séries
- Ajout, modification et suppression de séries
- Importation de données depuis l’API Anilist

### Suivi des tomes
- Ajout, modification et suppression de tomes
- Statut de lecture personnalisable
- Gestion des tomes collectors et des derniers tomes

### Liste d’envies
- Ajout et suppression de séries dans une liste d’envies
- Possibilité d’ajouter une série de la liste d’envies à votre collection

### Gestion des lues ailleurs
- Suivi des séries lues non-présentes dans la bibliothèque

### Gestion des prêts
- Suivi des tomes prêtés et à qui

### Statistiques
- Nombre de séries, tomes, répartition par statut
- Recherche des séries incomplètes

### Interface intuitive
- Design sombre et responsive
- Modales pour les actions
- Tri et filtrage des séries

### Sécurité
- Mode privé pour cacher votre bibliothèque
- Gestion des mots de passe et des sessions

## Aperçu visuel
Publique
![Lengas p1](https://concepts.esenjin.xyz/cyla/fichiers/6989c7f4a197c_1770637300.gif)
Administration
![Lengas a1](https://concepts.esenjin.xyz/cyla/fichiers/6989c7f52bb63_1770637301.gif)
*Captures effectuées en v.2.1.0*

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

## Mise à jour
1. Télécharger la dernière publication
2. SUPPRIMER LE FICHIER `generate_password.php`
3. Supprimer les dossiers `bdd/` et `uploads/`
4. Téléverser les fichiers/dossiers restants sur votre serveur (écraser ceux présents)
5. Bien joué, c'est à jour !

## Structure des fichiers

```
lengas/
├── index.php            # Page publique
├── admin.php            # Interface d'administration
├── stats.php            # Page des statistiques
├── config.php           # Configuration du site
├── login.php            # Connexion
├── logout.php           # Déconnexion
├── assets/
│   ├── css/             # Fichiers CSS
│   │   ├── _admin.css
│   │   ├── _base.css
│   │   ├── _buttons.css
│   │   ├── _forms.css
│   │   ├── _layout.css
│   │   ├── _modals.css
│   │   ├── _public.css
│   │   ├── _responsive.css
│   │   ├── _series.css
│   │   ├── _utils.css
│   │   ├── _variables.css
│   │   └── main.css
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
│       │   ├── read.js
│       │   └── main.js
│       ├── stats.js
│       └── public.js
├── includes/
│   ├── auth.php          # Gestion de l'authentification et des sessions
│   ├── helpers.php       # Fonctions utilitaires générales
│   └── anilist.php       # API Anilist
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
   ├── data.json         # Base de données des séries et tomes possédés (chmod 0660)
   ├── list.json         # Base de données de la liste d'envies (chmod 0660)
   ├── loan.json         # Base de données des prêts (chmod 0660)
   ├── read.json         # Base de données des prêts (chmod 0660)
   ├── anilist.json      # Cache des requêtes API Anilist (chmod 0660)
   ├── options.json      # Options principales éditables (chmod 0660)
   └── mdp.json          # Contient le mot de passe hashé (chmod 0660)
```

## Crédits
- Développé avec l'aide de [Mistral](https://chat.mistral.ai/)
- Utilise l'API d'[Anilist](https://docs.anilist.co/)
- Utilise [JSDelivr](https://www.jsdelivr.com/)