# Lengas - Gestion de collection de mangas/light-novels

## Description
Lengas est une application web légère pour gérer et suivre votre collection de mangas et light-novels. Elle permet de :
- Visualiser votre collection
- Suivre l'état de lecture de chaque tome (à lire, en cours, terminé)
- Ajouter/modifier/supprimer des séries et des tomes
- Consulter des statistiques sur votre collection
- Gérer une liste d'envies pour les séries que vous souhaitez acquérir
- Recevoir des notifications pour les tomes manquants ou incorrectement étiquetés
- Marquer les tomes collectors et les derniers tomes

## Fonctionnalités
- **Gestion des séries** : Ajout, modification et suppression de séries.
- **Suivi des tomes** : Ajout, modification et suppression de tomes, statut de lecture, gestion des tomes collectors et des derniers tomes.
- **Liste d'envies** : Ajout et suppression de séries dans une liste d'envies, possibilité d'ajouter une série de la liste d'envies à votre collection.
- **Gestion des prêts** : Savoir quels tomes ont été prêtés et à qui.
- **Statistiques** : Nombre de séries, tomes, répartition par statut.
- **Notifications** : Alertes pour les tomes manquants ou incorrectement étiquetés.
- **Séries incomplètes** : Recherche des séries (terminées) incomplètes.
- **Interface intuitive** : Design sombre et responsive, modales pour les actions, tri et filtrage des séries.
- **Mode privé** : Pour cacher votre bibliothèque.

## Aperçu visuel
Captures effectuées en v.1.4.0
![Lengas public](https://concepts.esenjin.xyz/cyla/fichiers/6965222c214b0_1768235564.mp4)
![Lengas admin](https://concepts.esenjin.xyz/cyla/fichiers/6965223352f1f_1768235571.mp4)

## Prérequis
- Serveur web (Apache, Nginx)
- PHP 7.4 ou supérieur

## Installation
1. Télécharger la dernière publication
2. Éditer le mot de passe dans `config.php`
3. Téléverser les fichiers sur votre serveur

## Mise à jour
1. Télécharger la dernière publication
2. Éditer le mot de passe dans `config.php`
3. Supprimez les dossiers `bdd/` et `uploads/`
3. Téléverser les fichiers/dossiers restants sur votre serveur (écraser ceux présents)

## Structure des fichiers

```
lengas/
├── index.php          # Page publique
├── admin.php          # Interface d'administration
├── stats.php          # Page des statistiques
├── config.php         # Configuration du site
├── anilist.php        # API Anilist
├── login.php          # Connexion
├── styles.css         # Styles CSS
├── uploads/           # Images des séries (chmod 777)
├── scripts/           # Scripts JS
└── bdd/               # Fichiers de données (chmod 777)
   ├── data.json          # Base de données des séries et tomes possédés (chmod 666)
   ├── list.json          # Base de données de la liste d'envies (chmod 666)
   ├── loan.json          # Base de données des prêts (chmod 666)
   ├── anilist.json       # Cache des requêtes API Anilist (chmod 666)
   └── options.json       # Options principales éditables (chmod 666)
```


## Configuration
Modifiez `config.php` pour :
- Définir le mot de passe admin
- Configurer les chemins si nécessaire

## Utilisation
1. Connectez-vous à l'interface d'administration via `admin.php`.
2. Utilisez les modales pour ajouter, modifier ou supprimer des séries et des tomes.
3. Consultez la liste d'envies pour gérer les séries que vous souhaitez acquérir.
4. Utilisez les options de tri et de recherche pour naviguer dans votre collection.

## Crédits
- Développé avec l'aide de [Mistral](https://chat.mistral.ai/)
- Utilise l'API d'[Anilist](https://docs.anilist.co/)