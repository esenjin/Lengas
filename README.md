# Lengas - Gestion de collection de mangas/light-novels et d'animés

## Sommaire
- [Description](#description)
- [Aperçu visuel](#aperçu-visuel)
- [Fonctionnalités](#fonctionnalités)
- [Intégration Anilist (Animethèque)](#intégration-anilist-animethèque)
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
Lengas est une application web légère et intuitive pour gérer et suivre votre collection de mangas, de light-novels et d'animés. Elle vous permet de :

- Visualiser et organiser votre collection, répartie en deux collections cloisonnées : la **Mangathèque** (mangas et light-novels) et l'**Animethèque** (animés)
- Suivre l'état de lecture de chaque tome et l'état de visionnage de chaque épisode (à lire/à voir, en cours, terminé)
- Ajouter, modifier et supprimer des séries, des tomes et des épisodes
- Importer et tenir à jour l'Animethèque automatiquement grâce à **Anilist**, qui fait autorité sur toutes les données factuelles d'un animé
- Consulter des statistiques détaillées sur chacune des deux collections
- Gérer une liste d'envies typée pour les séries (mangas ou animés) que vous souhaitez acquérir ou dont la diffusion n'a pas encore commencé
- Recevoir des notifications pour les tomes manquants ou incorrectement étiquetés
- Marquer les tomes collectors et les derniers tomes/épisodes
- Gérer les prêts de tomes à vos amis (les animés ne se prêtent jamais, même en édition physique)
- Rédiger des critiques (avis) sur vos séries, mises en forme en Markdown et visibles par vos visiteurs
- Activer un mode privé pour cacher votre bibliothèque, réglable séparément pour chaque collection
- Choisir un thème (clair, sombre ou personnalisé)
- Vérifier le nombre de tomes parus en France avec Babengas (Babelio, facultatif)
- Vous connecter avec Vestikan (SSO facultatif)

---

## Aperçu visuel
Publique

![Aperçu de la collection](https://concepts.esenjin.xyz/cyla/fichiers/6a69f028e2540_1785327656.png)
![Détails d'une fiche de série](https://concepts.esenjin.xyz/cyla/fichiers/6a69f028c1296_1785327656.png)
![Critique d'une série](https://concepts.esenjin.xyz/cyla/fichiers/6a69f028ac765_1785327656.png)
![Aperçu des statistiques](https://concepts.esenjin.xyz/cyla/fichiers/6a69f028929ca_1785327656.png)

Administration

![Aperçu de la collection - admin](https://concepts.esenjin.xyz/cyla/fichiers/6a69f06c992d5_1785327724.png)
![Modale d'édition d'une série](https://concepts.esenjin.xyz/cyla/fichiers/6a69f06c8d312_1785327724.png)
![Page des livres prêtés](https://concepts.esenjin.xyz/cyla/fichiers/6a69f06c64816_1785327724.png)
![Page de la liste d'envies](https://concepts.esenjin.xyz/cyla/fichiers/6a69f06c6448c_1785327724.png)
![Pages des critiques](https://concepts.esenjin.xyz/cyla/fichiers/6a69f06c84ee7_1785327724.png)
![Page des outils](https://concepts.esenjin.xyz/cyla/fichiers/6a69f06c6b6e4_1785327724.png)

*Captures effectuées en v.4.0.0*

---

## Fonctionnalités

### Deux collections cloisonnées
Depuis la version 4.0, chaque série porte un **type** : `manga` (regroupant aussi les light-novels) ou `anime`. Les deux collections sont accessibles depuis des sections dédiées du menu latéral — **Mangathèque** et **Animethèque** — et restent séparées de bout en bout : recherche, tri, pagination, compteurs, filtres, statistiques et options de visibilité sont propres à chacune. Seule l'autocomplétion de la barre de recherche traverse les deux collections, avec un badge de type coloré sur chaque suggestion et une bascule automatique vers la bonne vue à la sélection.

### Gestion des séries (Mangathèque)
- Ajout, modification et suppression de séries
- Association à une fiche MangaUpdates (URL) pour le suivi du nombre de tomes et du statut de publication
- Remplissage automatique des URL MangaUpdates en masse via l'outil « Association MangaUpdates » (recherche par titre + auteur), avec possibilité d'exclure une catégorie de la recherche (ex. les light-novels, dont la publication FR ne suit pas MangaUpdates)
- Association à une fiche Babelio (URL) pour connaître le nombre de tomes réellement parus en France, via le service Babengas

### Gestion des séries (Animethèque)
- Ajout d'un animé par recherche Anilist (titre (ou ID Anilist) → jusqu'à 10 résultats → sélection → import automatique complet des données)
- Toutes les données factuelles (titres, studios, format, genres, statut de diffusion, vignette, nombre d'épisodes…) proviennent d'Anilist et ne se saisissent jamais à la main — voir la section [Intégration Anilist](#intégration-anilist-animethèque)
- Éditions physiques : jusqu'à 5 par série, chacune avec un commentaire libre (100 caractères max)
- Une série non encore diffusée sur Anilist (`NOT_YET_RELEASED`) ne peut pas rejoindre l'Animethèque : elle est automatiquement dirigée vers la liste d'envies

### Suivi des tomes et des épisodes
- Ajout, modification et suppression de tomes (Mangathèque)
- Statut de lecture personnalisable
- Gestion des tomes collectors et des derniers tomes/épisodes
- Côté Animethèque, les épisodes sont entièrement gérés par Anilist : aucun ajout ni suppression manuelle n'est possible, nulle part dans le site. Seul leur **statut** (à voir / en cours / terminé) et leur **date de visionnage** sont modifiables
- Sur une carte d'animé, le bouton « + » fait passer le premier épisode non terminé en « terminé » (clics successifs = progression épisode par épisode) ; l'action « tout marquer comme terminé » reste disponible

### Statuts de lecture / de visionnage
- Suivi du statut par série : à débuter/à voir, en cours, terminée, abandonnée
- Marquage « Lue ailleurs » pour les séries mangas lues sans les posséder (bibliothèque, ami, revendue…) — sans équivalent côté Animethèque
- Marquage « Lecture abandonnée » / « Visionnage abandonné » pour les séries dont on a arrêté le suivi

### Profil de l'administrateur
- Page « Profil » dédiée (icône compte du menu latéral admin) pour se présenter aux visiteurs
- Photo de profil (téléversée dans `uploads/`), pseudo (crédite les critiques), biographie en Markdown (même éditeur et même rendu que les critiques) et liens sociaux illimités (même sélecteur d'icône et de couleur que les liens personnalisés du menu latéral)
- **Mise en lumière** : jusqu'à 5 mangas/light-novels et 5 animés de votre collection, choisis via une recherche instantanée et rangés dans un panier réordonnable (boutons ↑/↓) ; chaque changement est enregistré immédiatement
- Le bouton « Qui suis-je ? » ouvre une modale présentant le profil (affiché uniquement si au moins un champ est renseigné), avec la mise en lumière entre la biographie et les liens sociaux ; disponible dans le menu latéral des pages publiques Accueil, Statistiques et Historique. Un clic sur une série mise en lumière ouvre directement sa fiche de détail, depuis les trois pages. Une série mise en lumière dont la collection est en mode privé ou masquage mature n'apparaît pas côté public

### Critiques
- Rédaction d'un avis par série (manga ou animé) via un éditeur Markdown dédié (page « Critiques »)
- Éditeur avec aperçu en direct, barre de mise en forme flottante (gras, italique, souligné, barré, titres, listes, citations, code, liens, images et médias) et raccourcis annuler/rétablir
- Mise en forme réversible : re-cliquer sur un style déjà appliqué à la sélection le retire
- Insertion de médias externes (YouTube, Vimeo, SoundCloud, ou fichiers audio/vidéo directs)
- Alerte lorsque la série n'est pas (ou pas entièrement) lue/visionnée, adaptée au vocabulaire du type de série
- Filtre par type (mangas / animés / les deux, réinitialisé à « les deux » à chaque visite) et filtre « Avec critique »
- Badge ✏️ sur la page publique ; les visiteurs consultent la critique dans une modale dédiée
- Boutons dédiés « Critiques mangas » / « Critiques animés » dans le menu latéral public, à côté de Mangathèque/Animethèque : redirigent directement vers la collection concernée avec le filtre « Avec critique » déjà appliqué

### Licences
- Regroupement libre de plusieurs séries (mangas et/ou animés) sous une même « licence » nommée par vous (page « Licences »), par exemple le manga, la saison 1 et la saison 2 animées d'une même œuvre
- Une série ne peut appartenir qu'à une seule licence à la fois ; seules les séries déjà en collection peuvent être ajoutées
- Vignette de la licence : celle de la première série membre qui en possède une (sinon la suivante, puis le logo par défaut), titre et nombre de séries
- Modale de détail d'une licence : liste ordonnée des séries membres, réordonnable (boutons ↑/↓), avec ajout et retrait de séries
- Bouton « 📚 Licence » dans la modale de détail publique d'une série (sous le bouton « Critique »), visible uniquement si la série appartient à une licence : ouvre la liste ordonnée des séries de la licence, chacune menant à sa propre fiche
- L'outil « Groupage de licences » (voir [Outils](#outils-une-page-dédiée-par-outil)) suggère automatiquement des regroupements pour les séries qui n'ont pas encore de licence

### Notation
- Note subjective facultative par série, au choix parmi trois valeurs : ☺️ « J'ai apprécié », 😑 « Mi-figue mi-raisin », 😠 « Je n'ai pas aimé »
- Réglable à l'ajout et à la modification d'une série (menu déroulant « Notation »)
- Pour les animés importés depuis Anilist, la note de la fiche est traduite automatiquement (score 0-100 → note du site)
- Affichée sous forme de badge emoji (texte au survol) : dans la carte de série côté administration, et dans la modale de détails côté public

### Liste d'envies
- Ajout et suppression de séries dans une liste d'envies, désormais typée (manga ou animé)
- Pour un animé, une recherche Anilist préremplit et mémorise l'identifiant de la fiche ; un champ **studio** remplace le champ auteur, sans champ éditeur
- Une série animée pas encore diffusée y est automatiquement dirigée depuis la modale d'ajout d'animé
- Possibilité d'ajouter une série de la liste d'envies à votre collection ; pour un animé déjà associé à une fiche Anilist, l'import est immédiat, sans nouvelle recherche
- La vignette Anilist n'est téléchargée qu'au moment du passage en collection, jamais tant que la série reste en liste d'envies
- **Déplacer dans la liste** : mouvement inverse, retire une série de votre collection (manga ou animé) pour la replacer dans la liste d'envies, préremplie avec ses informations déjà connues (auteur/éditeur ou studio) ; si la série a des tomes actuellement prêtés, un avertissement bloquant est affiché avant de continuer

### Gestion des prêts
- Prêt d'un tome unique ou d'une plage de tomes d'une même série
- Retour de prêt unitaire ou en masse par série
- Fonctionnalité réservée à la Mangathèque : les animés, y compris en édition physique, ne sont jamais prêtables

### Séries à lire / à finaliser
- « Séries à lire » (Mangathèque) et « À finaliser » (Animethèque) affichent les séries qui ne sont pas entièrement lues/visionnées

### Gestion des lues ailleurs
- Suivi des séries mangas lues non présentes dans la bibliothèque (sans équivalent côté animés)

### Statistiques
Page dédiée organisée en deux onglets, **Mangathèque** (par défaut) et **Animethèque** :
- **Mangathèque** : nombre de séries, de tomes, valeur de la collection, tomes collectors, prêts, lues ailleurs, répartition par statut, etc.
- **Animethèque** : nombre de séries, d'épisodes, répartition par statut de visionnage/genre/format/studio, favoris, notations, revisionnages, et un temps de visionnage total calculé à partir de la durée réelle des épisodes fournie par Anilist (avec un repli paramétrable par format dans les options).

### Historique (page publique « Historique »)
- Page dédiée (`historique.php`, lien dans le menu latéral public, section « Divers », et dans le menu latéral admin depuis lequel elle s'ouvre dans un nouvel onglet) listant, jour après jour et du plus récent au plus ancien, les tomes lus et épisodes vus, en se basant sur leur date de lecture/visionnage
- Une carte par série et par jour (vignette, nom, numéros concernés), cliquable pour ouvrir la même modale de détail que sur l'accueil. Les numéros consécutifs sont condensés en plages (« 1 à 5, 8, 10 et 11 » plutôt que « 1, 2, 3, 4, 5, 8, 10, 11 »)
- Les relectures (mangas) et revisionnages (animés) apparaissent également, dans une carte dédiée au liseré discret (« 4ème relecture », « 3ème revisionnage ») à la date de leur dernière augmentation. Cette date se pose automatiquement, sans saisie manuelle, dès que le compteur de relectures/revisionnages augmente (modale d'édition ou import Anilist) ; elle n'est jamais posée rétroactivement pour un compteur déjà supérieur à 0 avant l'introduction de cette fonctionnalité, ni modifiée si le compteur baisse ou reste stable
- Barre de recherche par nom de série (titre affiché et titres alternatifs Anilist inclus pour les animés) : filtre instantanément les cartes déjà chargées et, si besoin, charge automatiquement les jours plus anciens par lots de 30 jusqu'à trouver une correspondance ou épuiser tout l'historique
- Filtre Mangathèque / Animethèque / les deux (par défaut)
- 30 jours affichés initialement, avec un bouton « Afficher plus » qui en charge 30 de plus à chaque clic
- Respecte le mode privé et le masquage des séries matures de chaque collection (un tome d'une collection privée n'apparaît jamais dans l'historique)
- Peut être entièrement masquée au public depuis les options du site (page « Options », section « Visibilité »), indépendamment du mode privé des deux collections

### Thèmes
- Thèmes de base fournis : « Sombre » (par défaut) et « Clair »
- Thèmes personnalisés : déposez un fichier `assets/css/_variables-<nom>.css` pour l'ajouter automatiquement à la liste (suivre le même shéma que les thèmes natifs)

### Outils (une page dédiée par outil)
Tous les outils du site sont accessibles depuis `pages/page-outils.php`, accessible via l'icône clé à molette du menu latéral : cette page liste chaque outil (icône, nom, description, bouton d'accès) et renvoie vers sa propre page, dans `pages/outils/`. Chaque carte est teintée selon la fonction de l'outil : rose pour la Mangathèque, bleu pour l'Animethèque, brun pour le mutualisé (mangas et animés), violet pour ce qui touche au site lui-même.

- **Vérification via MangaUpdates** (`pages/outils/outil-mangaupdates.php`) : détecte les tomes manquants en comparant votre collection au nombre de tomes indiqué par MangaUpdates (le décompte VF est privilégié lorsqu'il est disponible), avec progression en direct, filtres et ajout des tomes manquants
- **Vérification via Babengas** (`pages/outils/outil-babengas.php`, visible uniquement si Babengas est configuré et activé) : voir la section dédiée plus bas
- **Synchronisation via Anilist** (`pages/outils/outil-anilist-sync.php`, visible uniquement si l'Animethèque contient au moins une série) : déclenche la synchronisation automatique des séries animées éligibles (diffusion et visionnage tous deux « en cours »), avec un bouton de forçage qui ignore le verrou de 24 h — voir [Intégration Anilist](#intégration-anilist-animethèque)
- **Import Anilist** (`pages/outils/outil-anilist-import.php`) : importe en masse la liste ANIME d'un compte Anilist (par pseudo public), avec un écran d'aperçu détaillé avant toute écriture — voir [Intégration Anilist](#intégration-anilist-animethèque)
- **Vérification des animés** (`pages/outils/outil-anilist-recheck.php`, visible uniquement si l'Animethèque contient au moins une série) : compare chaque série animée à sa fiche Anilist actuelle sur tout ce que la synchronisation automatique ne couvre pas (titres alternatifs, studios, format, genres, vignette…), avec validation explicite avant toute correction
- **Vérification des mangas** (`pages/outils/outil-coherences.php`) : repère les anomalies (doublons, numéros manquants, mauvais tag « dernier tome »/« dernier épisode », statut différent de MangaUpdates ou d'Anilist, prêts orphelins, série animée sans identifiant Anilist, épisode terminé sans date, vignette Anilist introuvable, etc.) et propose une édition rapide de la série concernée ; les anomalies factuelles d'une série animée renvoient vers sa fiche Anilist pour correction à la source
- **Sauvegardes** (`pages/outils/outil-sauvegardes.php`) : création et téléchargement d'archives de vos données, ainsi que l'export JSON complet (inclut les tables et les vignettes propres à l'Animethèque)
- **Association MangaUpdates** (`pages/outils/outil-associations-mu.php`) : recherche automatique d'une fiche pour chaque série sans URL (corrélation titre + auteur), avec progression en direct et validation avant enregistrement ; un second outil récupère de la même façon les genres manquants
- **Groupage de licences** (`pages/outils/outil-groupage-licences.php`) : repère les séries sans licence qui semblent appartenir à la même œuvre (comparaison du nom et, pour les animés, des titres alternatifs Anilist, avec bonus si deux mangas partagent le même auteur ou si deux animés partagent le même studio) et propose de les regrouper. Chaque suggestion se valide individuellement : création d'une nouvelle licence, rattachement à une licence existante détectée automatiquement (avec consultation de son contenu actuel avant de confirmer), rattachement à une autre licence choisie manuellement, ou ignorée. Seuil de similarité ajustable, avec un repère calculé sur le score moyen des licences déjà existantes. Analyse entièrement locale (aucun appel réseau)
- **Vérification d'intégrité du site** (`pages/outils/outil-integrite.php`) : compare automatiquement votre instance au dépôt Gitea, **au tag correspondant à votre version installée** (si aucun tag ne correspond, la comparaison se fait avec la version la plus récente et le signale). Pour chaque fichier versionné, elle vérifie la **présence** ET le **contenu** (comparaison d'empreinte : « OK », « Modifié » ou « Manquant »). Elle repère aussi les **fichiers étrangers au dépôt** (présents sur l'instance mais absents du dépôt, hors données `uploads/` `saves/` `bdd/`, config Vestikan, thèmes personnalisés et photo de profil de l'admin), l'**état des modules facultatifs** Vestikan et Babengas (installés ? réellement activés ? service distant fonctionnel ?), la **connectivité à l'API Anilist**, les permissions, les fichiers interdits, les doublons, les images orphelines (la photo de profil de l'admin et les vignettes Anilist actives ne sont jamais considérées comme orphelines), l'accès externe aux dossiers sensibles, la structure de la base de données (y compris les tables et colonnes propres à l'Animethèque), les thèmes personnalisés présents

### Aperçu de lien (OpenGraph)
Lorsqu'un lien du site est partagé (Discord, réseaux sociaux, messageries…), un aperçu (titre, description, vignette) est généré automatiquement via `includes/opengraph.php`, inclus dans le `<head>` de chaque page :
- Sur les pages publiques (Accueil, Statistiques, Historique), l'aperçu reprend le nom et la description du site (page « Options »), sa vignette par défaut, ainsi qu'un résumé du nombre de séries et de tomes/épisodes de chaque collection — en respectant le mode privé et le masquage des séries matures : une collection privée n'expose jamais sa taille
- Sur toute page d'administration (connexion, `admin.php`, Outils, Options, Profil, Critiques, Licences, Prêts, Liste d'envies…), l'aperçu généré est systématiquement celui de l'accueil : un lien admin partagé par erreur n'expose donc jamais le contenu de la page elle-même

### Options (page dédiée « Options »)
Toutes les options du site sont regroupées sur la page `pages/page-options.php`, accessible via l'icône engrenage du menu latéral.

- Nom, description et titres de pages personnalisables
- Nombre illimité de liens personnalisés affichés dans le menu latéral public (bouton « Ajouter un lien personnalisé »), chacun avec une icône choisie via un sélecteur visuel (aperçu, recherche, catégories ; une trentaine d'icônes : médias, flux RSS, réseaux, etc.) et une couleur au choix dans une palette prédéfinie accordée au thème
- Réglages des statistiques : temps de lecture et valeur d'un tome par catégorie (Mangathèque), durée d'un épisode par format Anilist (Animethèque)
- Choix du thème (clair, sombre ou personnalisé)
- Mode privé, masquage des séries matures et masquage des critiques, chacun réglable **séparément** pour la Mangathèque et l'Animethèque. En mode privé côté public, le bouton d'accès à la collection concernée reste visible dans le menu, mais la page affiche uniquement un message indiquant que la collection est privée, sans le moindre décompte
- Masquage de la page « Historique » (réglage unique, la page mélangeant les deux collections) : masquée, son lien disparaît aussi du menu latéral public
- Vignette par défaut (partagée entre les deux collections)
- Configuration du service Babengas (facultatif)
- Modification du mot de passe administrateur

### Interface intuitive
- Design sombre et responsive
- Modales pour les actions
- Tri et filtrage des séries
- Menu latéral organisé en sections thématiques (Mangathèque, Animethèque, Hors collection, Mutualisé, Divers, Gestion), avec titre de section et libellé au-dessus de chaque icône, y compris sur mobile
- « Mangas à lire » et « Animés à visionner » (menu latéral admin) trient automatiquement par date de lecture/visionnage descendante ; « Animés à visionner » inclut en plus les animés pas encore commencés (« à voir »), en plus de ceux en cours
- Pied du menu latéral admin : actualiser et se déconnecter côte à côte, puis numéro de version cliquable vers le dépôt Gitéa (même présentation que le menu latéral public)

### Sécurité
- Mode privé pour cacher votre bibliothèque (réglable par collection)
- Gestion des mots de passe et des sessions
- Connexion SSO Vestikan facultative

---

## Intégration Anilist (Animethèque)

L'Animethèque de Lengas s'appuie entièrement sur [Anilist](https://anilist.co) : **Anilist fait autorité** sur toutes les données factuelles d'un animé. Le principe est simple — on interroge, on récupère, on prend pour argent comptant. Aucune donnée factuelle d'animé ne se saisit à la main dans Lengas ; une erreur constatée se corrige à la source, sur Anilist elle-même, puis remonte dans Lengas via la synchronisation ou la revérification.

Cette intégration ne nécessite **aucune clé d'API ni configuration** : l'endpoint public d'Anilist (`https://graphql.anilist.co`) est interrogé directement. Seule une liste utilisateur Anilist réglée en privé reste inaccessible (et signalée comme telle lors d'un import).

### Ce qui est récupéré depuis Anilist
Pour chaque animé : titres (romaji, anglais, natif, synonymes), studios, format (TV, film, OAV, ONA, spécial, musique…), genres (traduits en français, tout genre inconnu étant ignoré), statut de diffusion, nombre d'épisodes et durée par épisode, indicateur de contenu adulte, vignette, lien vers la fiche, prochaine diffusion et, lors d'un import de liste utilisateur, le statut de suivi, la progression, la note, le nombre de revisionnages, les dates de début/fin et l'appartenance aux listes personnalisées ou aux favoris du compte.

### Champs éditables ou non sur une fiche animé
| Champ | Éditable dans Lengas |
|---|---|
| Titre affiché | Oui, mais uniquement par **sélection** parmi les titres alternatifs récupérés (jamais de saisie libre) |
| Studios, format, genres, statut de diffusion, lien Anilist | Non — reflet direct d'Anilist |
| Vignette | Oui (téléversement d'une image personnelle, qui masque la vignette Anilist sans la supprimer ; sa suppression réaffiche automatiquement celle d'Anilist) |
| Contenu mature | Oui — précochée d'après Anilist, décochable, jamais recochée automatiquement ensuite |
| Série favorite, visionnage abandonné | Oui |
| Note | Oui (traduite automatiquement lors d'un import, modifiable ensuite) |
| Éditions physiques (jusqu'à 5, un commentaire chacune) | Oui |
| Statut et date de chaque épisode | Oui, mais la liste des épisodes elle-même (création/suppression) est intégralement gérée par Anilist |

### Quota et robustesse
Anilist autorise 90 requêtes par minute ; le connecteur applique une fenêtre glissante avec une temporisation intégrée pour rester en dessous de ce plafond en toute circonstance, et respecte un éventuel `Retry-After` en cas de dépassement. Toute indisponibilité de l'API (erreur réseau, délai dépassé, réponse malformée) se traduit par un message clair, jamais par une erreur fatale du site.

### Ce qui est automatique
- **Synchronisation automatique** : les séries dont la diffusion **et** le visionnage sont tous deux « en cours » se tiennent à jour toutes seules. Déclenchée uniquement côté administration, avec un verrou de 24 h par série (ramené à 1 h en cas d'échec de l'API), elle se limite strictement aux **épisodes** (création des nouveaux épisodes diffusés, en statut « à voir ») et au **statut de diffusion**. Elle s'exécute en arrière-plan (AJAX) sans jamais bloquer l'affichage de la page ; l'outil dédié « Synchronisation via Anilist » permet aussi de la déclencher ou de la forcer manuellement (tous verrous ignorés)
- **Import de masse** : l'outil « Import Anilist » récupère la liste ANIME complète d'un compte, par pseudo public saisi à chaque campagne (jamais mémorisé). Il se déroule en deux temps : un écran d'aperçu complet (décompte par destination, sélection des séries favorites, des statuts et formats à importer, traitement des séries déjà présentes, exclusion des séries classées adultes, liste détaillée décochable série par série) puis, après validation explicite, l'écriture proprement dite. L'aiguillage suit le statut de liste Anilist de chaque entrée :

  | Statut Anilist | Destination | Traitement |
  |---|---|---|
  | Terminé (`COMPLETED`) | Vidéothèque | Tous les épisodes en « terminé » |
  | En cours (`CURRENT`) | Vidéothèque | 1..progression en « terminé », le reste en « à voir » |
  | Revisionnage (`REPEATING`) | Vidéothèque | **Tous** les épisodes en « terminé » + compteur de revisionnages |
  | Abandonné (`DROPPED`) / En pause (`PAUSED`) | Vidéothèque | Progression + coche « Visionnage abandonné » |
  | À voir (`PLANNING`) | Liste d'envies | Identifiant Anilist mémorisé, aucun épisode créé |
  | *Série non encore diffusée* | Liste d'envies | Prioritaire sur le statut de liste, quel qu'il soit |

  L'import est idempotent : le relancer met à jour les séries déjà présentes sans jamais écraser vos personnalisations (titre choisi, vignette personnelle, note, coches, éditions physiques).
- **Vérification des animés** : parcourt toute l'Animethèque et compare chaque champ factuel non couvert par la synchronisation automatique (titres alternatifs, studios, format, genres, nombre d'épisodes annoncé, vignette) à la fiche Anilist actuelle. Un rapport détaillé liste précisément ce qui diverge ; rien n'est jamais corrigé sans validation explicite, série par série, et les champs personnalisés (titre choisi, vignette perso, note, coches, éditions physiques) ne sont jamais proposés à l'écrasement.

---

## Prérequis
- Serveur web (Apache, Nginx)
- PHP 8.0 ou supérieur
- Extensions PHP : `pdo_sqlite` (base de données), `curl` (Anilist, MangaUpdates, Babengas, Vestikan), `zip` (sauvegardes), `fileinfo` (validation des images téléversées)
- Droits d'écriture pour le serveur web sur les dossiers `bdd/`, `saves/` et `uploads/` (chmod 0774, voir [Structure des fichiers](#structure-des-fichiers))

Facultatif, selon les fonctionnalités utilisées :
- Un microservice [Babengas](https://git.crystalyx.net/Esenjin_Asakha/Babengas) (Docker) pour la vérification des sorties françaises via Babelio
- Une instance [Vestikan](https://git.crystalyx.net/Esenjin_Asakha/Vestikan) pour la connexion SSO

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
4. Sur votre serveur, tout supprimer SAUF les dossiers `bdd/`, `saves/` et `uploads/` (ni ce qu'ils contiennent), ni (si vous avez configuré Vestikan) le fichier `vestikan/vestikan-config.php`
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
- **3.1+ vers 4.0** suivre les instructions de [la publication de la version](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/4.0.0).
  - Points importants : Introduction de l'Animethèque et de l'intégration Anilist. Refacto du code.

> Exemple : Je suis en 2.2.1, si la dernière version est la 3.9.0, je dois d'abord installer la 3.0, puis la 3.1 et enfin passer sur la dernière, la 3.9.0.

Elles ne sont pas obligatoire, mais il est recommandé de passer par les versions suivantes, si vous venez d'une version antérieur à celles-ci :

- [3.3.0](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/3.3.0), pour migrer vos séries "lues ailleurs" vers le nouveau système (uniquement si vous êtes sur une version 2.1.0 ou supérieur, les "lues ailleurs" n'existaient pas avant).
- [3.6.0](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/3.6.0), pour enregistrer en masse les dates de lecture des séries.
- [3.9.0](https://git.crystalyx.net/Esenjin_Asakha/Lengas/releases/tag/3.9.0), pour ajouter en masse des urls Babelio aux séries.

---

## Importer une base de données
1. Créer une sauvegarde avec l'outil dédié (« Outils » → « Sauvegardes »)
2. Extraire l'archive
3. (facultatif) Supprimer le dossier `uploads/` et le fichier `bdd/lengas.db` de votre site
4. Déplacer les dossiers `bdd/` et `uploads/` que vous venez d'extraire à la racine de votre site (écraser les fichiers si nécessaire)
5. (facultatif) Utiliser l'outil de vérification de l'intégrité du site (« Outils » → « Vérification d'intégrité du site »)
6. Félicitation, votre base de données est de nouveau là !

---

## Comment vérifier les sorties françaises avec Babengas

[Babengas](https://git.crystalyx.net/Esenjin_Asakha/Babengas) est un microservice Docker qui interroge Babelio pour connaître le nombre de tomes **réellement parus en France**. Il complète MangaUpdates, dont le décompte se base surtout sur l'édition l'origine (VO) et renseigne rarement l'édition française. Cet outil est réservé à la Mangathèque : l'Animethèque n'a pas d'équivalent, Anilist ne recensant pas les sorties françaises.

Son intégration à Lengas est **entièrement facultative** : sans les fichiers Babengas ni la configuration dans les options, la fonctionnalité reste invisible et le site fonctionne normalement.

Babelio filtrant les IP d'hébergeurs, Babengas doit tourner sur une machine à IP résidentielle (un homelab), exposée en HTTPS via un reverse proxy. Une fois le service en ligne, renseignez son URL et sa clé partagée dans les options du site (page « Options », section « Babengas ») : l'outil dédié « Vérification via Babengas » apparaît alors dans la liste des outils.

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

Lorsqu'il est configuré, un bouton « Se connecter avec Vestikan » apparaît sur la page de connexion, en complément du mot de passe. L'état de la connexion (active / inactive) est visible dans les options du site, sous le champ de mot de passe, et le détail des fichiers Vestikan apparaît dans l'outil de vérification d'intégrité (une absence y est signalée en orange « Absent », car non bloquante). L'outil indique en plus si le SSO est **réellement activé** (fichier `vestikan/vestikan-config.php` présent et complet) et si le **serveur Vestikan répond** (sonde de l'URL d'autorisation).

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
├── historique.php         # Page publique « Historique » (journal chronologique)
├── config.php             # Configuration du site
├── login.php              # Connexion
├── logout.php             # Déconnexion
├── babengas-ping.php      # Endpoint de test Babengas (facultatif)
├── .htaccess
├── pages/                 # Pages secondaires de l'administration
│   ├── page-prets.php     # Page de gestion des prêts
│   ├── page-wishlist.php  # Page de la liste d'envies (mangas et animés)
│   ├── page-critiques.php # Page de rédaction des critiques + rendu Markdown
│   ├── page-licences.php  # Page de gestion des licences (regroupement de séries)
│   ├── page-profil.php    # Page du profil de l'admin (photo, pseudo, bio, liens sociaux)
│   ├── page-options.php   # Page des options du site (configuration + mise à jour)
│   ├── page-outils.php    # Index des outils (icône + nom + description + bouton d'accès)
│   └── outils/            # Un fichier par outil (page complète + endpoints SSE/POST)
│       ├── _bootstrap.php          # Socle commun (chdir + requires + $data/$options)
│       ├── _layout_head.php        # En-tête HTML commun (sidebar, titre, lien retour)
│       ├── _layout_foot.php        # Pied HTML commun (back-to-top, scripts)
│       ├── _tools-modals.php       # Modales partagées entre plusieurs outils
│       ├── outil-mangaupdates.php     # Vérification via MangaUpdates (tomes manquants)
│       ├── outil-babengas.php         # Vérification via Babengas (Babelio)
│       ├── outil-anilist-sync.php     # Synchronisation via Anilist
│       ├── outil-anilist-import.php   # Import Anilist
│       ├── outil-anilist-recheck.php  # Vérification des animés
│       ├── outil-coherences.php       # Vérification des mangas
│       ├── outil-sauvegardes.php      # Sauvegardes et export JSON
│       ├── outil-associations-mu.php  # Association MangaUpdates (fiches + genres)
│       ├── outil-groupage-licences.php # Groupage de licences (suggestions de regroupement)
│       └── outil-integrite.php        # Vérification d'intégrité du site
├── vestikan/              # Connexion SSO Vestikan (facultatif, non versionné pour la config)
│   ├── vestikan-login.php    # Démarrage de la connexion Vestikan
│   ├── vestikan-callback.php # Callback OAuth Vestikan
│   ├── vestikan.php          # Point d'entrée SSO Vestikan
│   ├── vestikan-sdk.php       # SDK Vestikan
│   └── vestikan-config.php   # Configuration SSO Vestikan (non versionné)
├── assets/
│   ├── css/               # Fichiers CSS
│   │   ├── _admin.css
│   │   ├── _anime.css
│   │   ├── _babengas.css
│   │   ├── _base.css
│   │   ├── _buttons.css
│   │   ├── _forms.css
│   │   ├── _layout.css
│   │   ├── _modals.css
│   │   ├── _pages.css
│   │   ├── _profil.css
│   │   ├── _public.css
│   │   ├── _responsive.css
│   │   ├── _reviews.css
│   │   ├── _licenses.css
│   │   ├── _historique.css
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
│   │   ├── mulogo.png
│   │   └── physique.png
│   └── js/                # Scripts JavaScript
│       ├── admin/
│       │   ├── modals.js
│       │   ├── autocomplete.js
│       │   ├── series.js
│       │   ├── volumes.js
│       │   ├── anime.js
│       │   ├── episodes.js
│       │   ├── wishlist.js
│       │   ├── loans.js
│       │   ├── pagination.js
│       │   ├── profil.js
│       │   ├── highlights.js
│       │   ├── reviews.js
│       │   ├── licenses.js
│       │   ├── main.js
│       │   └── tools/                    # Un fichier par outil
│       │       ├── page.js
│       │       ├── incomplete.js
│       │       ├── coherence.js
│       │       ├── babengas.js
│       │       ├── backups.js
│       │       ├── mangaupdates-assoc.js
│       │       ├── integrity.js
│       │       ├── anilist-import.js
│       │       ├── anilist-sync.js
│       │       ├── anilist-recheck.js
│       │       └── grouping.js
│       ├── stats.js
│       ├── historique.js
│       └── public.js
├── includes/
│   ├── auth.php              # Gestion de l'authentification et des sessions
│   ├── helpers.php           # Fonctions utilitaires générales + registre central des types de séries
│   ├── mangaupdates.php      # API MangaUpdates (suivi des tomes et du statut)
│   ├── anilist.php           # Connecteur API Anilist (GraphQL) : recherche, fiches, listes utilisateur
│   ├── babengas.php          # Intégration du microservice Babengas
│   ├── sidebar.php           # Menu latéral à icônes de l'administration
│   ├── public-sidebar.php    # Menu latéral à icônes des pages publiques (accueil, statistiques, historique)
│   ├── public-profil-modal.php # Modale « Qui suis-je ? », partagée par les pages publiques ci-dessus
│   ├── custom_icons.php      # Icônes, couleurs et lecture des liens personnalisés (partagé options/sidebar)
│   ├── themes.php            # Gestion des thèmes (base + personnalisés)
│   ├── opengraph.php         # Balises OpenGraph/Twitter Card communes à toutes les pages (aperçu de lien)
│   └── status_filter.php     # Filtrage des séries par statut, adapté au type de série affiché
├── fonctions/
│   ├── series.php        # Fonctions de gestion des séries
│   ├── volumes.php       # Fonctions de gestion des tomes
│   ├── anime.php         # Fonctions de gestion des séries animées (ajout, édition, vignette, éditions physiques)
│   ├── episodes.php      # Fonctions de gestion des épisodes
│   ├── wishlist.php      # Fonctions de gestion de la liste d'envies (mangas et animés)
│   ├── loans.php         # Fonctions de gestion des prêts
│   ├── read.php          # Fonctions de gestion des lues ailleurs
│   ├── options.php       # Fonctions de gestion des options du site
│   ├── reviews.php       # Fonctions de gestion des critiques (stockage + rendu Markdown)
│   ├── licenses.php      # Fonctions de gestion des licences (regroupement de séries)
│   ├── stats_compute.php # Moteur de calcul des statistiques de la bibliothèque
│   ├── tools.php         # Chargeur des outils (inclut fonctions/tools/)
│   └── tools/            # Un fichier de fonctions par outil
│       ├── backups.php            # Sauvegardes ZIP et export JSON
│       ├── integrity.php          # Vérification d'intégrité du site + infos serveur
│       ├── cleanup.php            # Nettoyages (doublons, images orphelines, fichiers interdits)
│       ├── mangaupdates_assoc.php # Association des fiches et des genres MangaUpdates
│       ├── babengas-helpers.php   # Helpers de l'outil Babengas
│       ├── incomplete.php         # Séries incomplètes (tomes manquants)
│       ├── coherence.php          # Vérification des mangas
│       ├── anilist_import.php     # Import de masse de la liste Anilist
│       ├── anilist_sync.php       # Synchronisation automatique des animés en cours
│       ├── anilist_recheck.php    # Vérification manuelle des animés
│       └── grouping.php           # Groupage de licences (suggestions de regroupement)
├── uploads/              # Images des séries, dont les vignettes Anilist téléchargées (chmod 0774)
├── saves/                # Sauvegardes de la base de données (chmod 0774)
└── bdd/                  # Fichiers de données (chmod 0774)
   └── lengas.db          # Base de données SQLite (chmod 0660)
```

> Note : `vestikan/vestikan-config.php` contient le `client_secret` et ne doit jamais être versionné (il est dans `.gitignore`). Son absence désactive simplement le SSO.

---

## Crédits
- Développé avec l'aide de [Claude](https://claude.ai/)
- Utilise l'API de [MangaUpdates](https://api.mangaupdates.com/)
- Utilise l'API de [Anilist](https://anilist.co/)
- Utilise [JSDelivr](https://www.jsdelivr.com/)
- Icônes via [Iconify / Material Design Icons](https://iconify.design/)
- Connexion SSO (facultative) via [Vestikan](https://git.crystalyx.net/Esenjin_Asakha/Vestikan)
- Extension Docker (facultative) [Babengas](https://git.crystalyx.net/Esenjin_Asakha/Babengas)