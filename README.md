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
Publique
![Lengas p1](https://concepts.esenjin.xyz/cyla/fichiers/69653f4a90bce_1768243018.png)
![Lengas p2](https://concepts.esenjin.xyz/cyla/fichiers/69653f4a70bf5_1768243018.png)
![Lengas p3](https://concepts.esenjin.xyz/cyla/fichiers/69653f4a48c53_1768243018.png)
Administration
![Lengas a1](https://concepts.esenjin.xyz/cyla/fichiers/696540839be53_1768243331.png)
![Lengas a2](https://concepts.esenjin.xyz/cyla/fichiers/69654083b56e5_1768243331.png)
![Lengas a3](https://concepts.esenjin.xyz/cyla/fichiers/6965408390bc3_1768243331.png)
![Lengas a4](https://concepts.esenjin.xyz/cyla/fichiers/6965408397e85_1768243331.png)
![Lengas a5](https://concepts.esenjin.xyz/cyla/fichiers/69654083a04e1_1768243331.png)
![Lengas a6](https://concepts.esenjin.xyz/cyla/fichiers/69654083a5f10_1768243331.png)
![Lengas a7](https://concepts.esenjin.xyz/cyla/fichiers/69654083a99e8_1768243331.png)
*Captures effectuées en v.1.4.0*

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
├── index.php          # Page publique
├── admin.php          # Interface d'administration
├── stats.php          # Page des statistiques
├── config.php         # Configuration du site
├── anilist.php        # API Anilist
├── login.php          # Connexion
├── logout.php         # Déconnexion
├── styles.css         # Styles CSS
├── uploads/           # Images des séries (chmod 774)
├── scripts/           # Scripts JS
├── saves/             # Sauvegardes de la base de données (chmod 774)
└── bdd/               # Fichiers de données (chmod 774)
   ├── data.json          # Base de données des séries et tomes possédés (chmod 660)
   ├── list.json          # Base de données de la liste d'envies (chmod 660)
   ├── loan.json          # Base de données des prêts (chmod 660)
   ├── anilist.json       # Cache des requêtes API Anilist (chmod 660)
   ├── options.json       # Options principales éditables (chmod 660)
   └── mdp.json           # Contient le mdp hashé (chmod 660)
```

## Utilisation
1. Connectez-vous à l'interface d'administration via `admin.php`.
2. Utilisez les modales pour ajouter, modifier ou supprimer des séries et des tomes.
3. Consultez la liste d'envies pour gérer les séries que vous souhaitez acquérir.
4. Utilisez les options de tri et de recherche pour naviguer dans votre collection.

## Crédits
- Développé avec l'aide de [Mistral](https://chat.mistral.ai/)
- Utilise l'API d'[Anilist](https://docs.anilist.co/)