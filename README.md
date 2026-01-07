# Lengas - Gestion de collection de mangas/light-novels

## Description
Lengas est une application web légère pour gérer et suivre votre collection de mangas et light-novels. Elle permet de :
- Visualiser votre collection
- Suivre l'état de lecture de chaque tome
- Ajouter/modifier/supprimer des séries et des tomes
- Consulter des statistiques sur votre collection

## Fonctionnalités
- **Gestion des séries** : Ajout, modification et suppression
- **Suivi des tomes** : Statut (à lire, en cours, terminé), tomes collectors
- **Statistiques** : Nombre de séries, tomes, répartition par statut
- **Interface intuitive** : Design sombre et responsive

## Aperçu visuel

![Lengas admin](https://concepts.esenjin.xyz/cyla/fichiers/695d6fdbe1f96_1767731163.png)
![Lengas public](https://concepts.esenjin.xyz/cyla/fichiers/695d6fdbe8e9a_1767731163.png)

## Prérequis
- Serveur web (Apache, Nginx)
- PHP 7.4 ou supérieur

## Installation
1. Télécharger le dépôt
2. Éditer le mot de passe dans `config.php`
3. (optionnel) Modifier les différentes informations à votre convenance (titre des pages, etc.)
4. Téléverser les fichiers sur votre serveur

## Structure des fichiers

```
lengas/
├── index.php          # Page publique
├── admin.php          # Interface d'administration
├── stats.php          # Statistiques
├── config.php         # Configuration
├── styles.css         # Styles CSS
├── data.json          # Base de données
├── scripts/           # Scripts JS
└── uploads/           # Images des séries
```

## Configuration
Modifiez `config.php` pour :
- Définir le mot de passe admin
- Configurer les chemins si nécessaire

## Crédits
Développé avec l'aide de [Mistral](https://chat.mistral.ai/)