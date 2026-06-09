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
- Récupérer automatiquement les tomes VF publiés en France via Nautiljon

## Fonctionnalités
### Gestion des séries
- Ajout, modification et suppression de séries
- Importation de données depuis l'API Anilist
- Lien optionnel vers la fiche Nautiljon (URL VF)

### Intégration Nautiljon
- Scraping automatique des tomes VF publiés en France via Browserless.io
- Cache SQLite avec délai configurable (30 jours par défaut)
- Prioritaire sur Anilist pour la détection des séries incomplètes
- Rafraîchissement asynchrone en arrière-plan (indicateur sablier)

### Suivi des tomes
- Ajout, modification et suppression de tomes
- Statut de lecture personnalisable
- Gestion des tomes collectors et des derniers tomes

### Liste d'envies
- Ajout et suppression de séries dans une liste d'envies
- Possibilité d'ajouter une série de la liste d'envies à votre collection

### Séries à lire
- Affiche les séries qui ne sont pas entièrement lues.

### Gestion des lues ailleurs
- Suivi des séries lues non-présentes dans la bibliothèque

### Gestion des prêts
- Suivi des tomes prêtés et à qui

### Statistiques
- Nombre de séries, tomes, répartition par statut
- Recherche des séries incomplètes (tomes VF Nautiljon en priorité, Anilist en fallback)

### Interface intuitive
- Design sombre et responsive
- Modales pour les actions
- Tri et filtrage des séries

### Sécurité
- Mode privé pour cacher votre bibliothèque
- Gestion des mots de passe et des sessions

## Aperçu visuel
Publique


![Lengas p1](https://concepts.esenjin.xyz/cyla/fichiers/6a26b58034a35_1780921728.png)
![Lengas p1](https://concepts.esenjin.xyz/cyla/fichiers/6a26b5800db38_1780921728.png)
![Lengas p1](https://concepts.esenjin.xyz/cyla/fichiers/6a26b57fe8034_1780921727.png)


Administration


![Lengas a1](https://concepts.esenjin.xyz/cyla/fichiers/6a26b65b4967d_1780921947.png)
![Lengas a1](https://concepts.esenjin.xyz/cyla/fichiers/6a26b65b3c49a_1780921947.png)
![Lengas a1](https://concepts.esenjin.xyz/cyla/fichiers/6a26b65b5d86a_1780921947.png)
![Lengas a1](https://concepts.esenjin.xyz/cyla/fichiers/6a26b65b50547_1780921947.png)


*Captures effectuées en v.3.0.0*

## Prérequis
- Serveur web (Apache, Nginx)
- PHP 7.4 ou supérieur
- (Optionnel) Compte [Browserless.io](https://www.browserless.io) gratuit pour l'intégration Nautiljon 
- Extension **pdo_sqlite** activée

## Installation
1. Télécharger la dernière publication
2. Éditer le fichier `generate_password.php` en y indiquant le mot de passe souhaité
3. Téléverser les fichiers sur votre serveur
4. Exécuter le fichier `generate_password.php`
5. SUPPRIMER LE FICHIER `generate_password.php`
6. C'est tout bon ! Vous pouvez profiter.

## Mise à jour depuis d'anciennes versions majeures
NE JAMAIS SAUTER PLUSIEURS VERSIONS MAJEURES, merci de les faire une par une. D'abord de 1.x vers 2.0, puis 2.0 vers 3.0 par exemple.
- 1.x vers 2.0 suivre les instructions de [la publication de la version](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/2.0.0).
- 2.x vers 3.0 suivre les instructions de [la publication de la version](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/3.0.0).

## Importer une base de données
1. Créer une sauvegarde avec l'outil dédié (modale "Outils")
2. Extraire l'archive
3. (facultatif) Supprimer le dossier `uploads/` et le fichier `lengas.db` du dossier `bdd/` de votre site
4. Déplacer les dossiers `bdd/` et `uploads/` que vous venez d'extraire à la racine de votre site (écraser les fichiers si nécessaire)
5. (facultatif) Utiliser l'outil de vérification de l'intégrité du site (modale "Outils")
6. Félicitation, votre base de données est de nouveau là !

## Structure des fichiers

```
lengas/
├── index.php            # Page publique
├── admin.php            # Interface d'administration
├── stats.php            # Page des statistiques
├── config.php           # Configuration du site
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
│   │   ├── _public.css
│   │   ├── _responsive.css
│   │   ├── _series.css
│   │   ├── _stats.css
│   │   ├── _utils.css
│   │   ├── _variables.css
│   │   └── main.css
│   ├── img/             # Images statiques
│   │   ├── anilistlogo.png
│   │   ├── favicon.ico
│   │   ├── logo.png
│   │   └── nautiljonlogo.png
│   └── js/              # Scripts JavaScript
│       ├── admin/
│       │   ├── autocomplete.js
│       │   ├── loans.js
│       │   ├── main.js
│       │   ├── modals.js
│       │   ├── nautiljon.js
│       │   ├── pagination.js
│       │   ├── read.js
│       │   ├── series.js
│       │   ├── tools.js
│       │   ├── unread.js
│       │   ├── volumes.js
│       │   └── wishlist.js
│       ├── public.js
│       └── stats.js
├── includes/
│   ├── anilist.php       # API Anilist
│   ├── auth.php          # Gestion de l'authentification et des sessions
│   ├── helpers.php       # Fonctions utilitaires générales
│   └── nautiljon.php     # Intégration Nautiljon via Browserless.io
├── fonctions/
│   ├── loans.php         # Fonctions de gestion des prêts
│   ├── options.php       # Fonctions de gestion des options du site
│   ├── read.php          # Fonctions de gestion des lues ailleurs
│   ├── series.php        # Fonctions de gestion des séries
│   ├── tools.php         # Fonctions de gestion des outils (sauvegardes, intégrité, etc.)
│   ├── unread.php        # Fonctions de gestion des séries à lire
│   ├── volumes.php       # Fonctions de gestion des tomes
│   └── wishlist.php      # Fonctions de gestion de la liste d'envies
├── uploads/             # Images des séries (chmod 0774)
├── saves/               # Sauvegardes de la base de données (chmod 0774)
└── bdd/                 # Données (chmod 0774)
   └── lengas.db         # Base de données SQLite (chmod 0660)
```

## Crédits
- Développé avec l'aide de [Mistral](https://chat.mistral.ai/) (pour les versions ≤ 2.2.2)
- Développé avec l'aide de [Claude](https://claude.ai/) (pour les versions > 2.2.2)
- Utilise l'API d'[Anilist](https://docs.anilist.co/)
- Utilise [JSDelivr](https://www.jsdelivr.com/)
- Utilise [Browserless](https://browserless.io/)