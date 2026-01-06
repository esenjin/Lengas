# Lengas - Gestion de collection de mangas/light-novels

## Description
Lengas est une application web légère pour gérer et suivre votre collection de mangas et light-novels. Elle permet de :
- Visualiser votre collection
- Suivre l'état de lecture de chaque tome
- Ajouter/modifier/supprimer des séries et des tomes
- Consulter des statistiques sur votre collection

## Fonctionnalités
✅ **Gestion des séries** : Ajout, modification et suppression
✅ **Suivi des tomes** : Statut (à lire, en cours, terminé), tomes collectors
✅ **Statistiques** : Nombre de séries, tomes, répartition par statut
✅ **Interface intuitive** : Design sombre et responsive

## Aperçu visuel

![Lengas admin](https://url.img)
![Lengas public](https://url.img)

## Prérequis
- Serveur web (Apache, Nginx)
- PHP 7.4 ou supérieur

## Installation
1. Copier les fichiers sur votre serveur
2. Créer un dossier `uploads/` et le rendre accessible en écriture
3. Accéder à `index.php` pour la partie publique
4. Accéder à `admin.php` pour la gestion (mot de passe requis)

## Structure des fichiers

lengas/
├── index.php          # Page publique
├── admin.php          # Interface d'administration
├── stats.php          # Statistiques
├── config.php         # Configuration
├── styles.css         # Styles CSS
├── data.json          # Base de données
└── uploads/           # Images des séries

## Configuration
Modifiez `config.php` pour :
- Définir le mot de passe admin
- Configurer les chemins si nécessaire

## Crédits
Développé avec l'aide de [Mistral](https://chat.mistral.ai/)