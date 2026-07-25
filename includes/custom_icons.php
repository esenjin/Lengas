<?php
// includes/custom_icons.php
// ──────────────────────────────────────────────────────────────────────────────
// Jeu d'icônes disponibles pour les liens personnalisés du menu latéral public.
// La clé est stockée en base (option custom_button_icon*), la valeur est le nom
// Iconify (jeu "mdi") utilisé pour construire l'URL de l'icône SVG.
//
// Pour ajouter une icône : ajoutez une entrée dans custom_link_icons() (clé =>
// nom Iconify), le libellé correspondant dans custom_link_icon_labels(), et
// rattachez la clé à un groupe dans custom_link_icon_groups(). Les clés
// existantes ne doivent jamais changer : elles sont stockées en base.
// ──────────────────────────────────────────────────────────────────────────────

function custom_link_icons(): array {
    return [
        // Général
        'link'        => 'mdi:link-variant',
        'web'         => 'mdi:web',
        'bookmark'    => 'mdi:bookmark',
        'star'        => 'mdi:star',
        'heart'       => 'mdi:heart',
        'home'        => 'mdi:home',
        'information' => 'mdi:information',
        'email'       => 'mdi:email',
        'account'     => 'mdi:account-circle',
        'shopping'    => 'mdi:shopping',
        'gift'        => 'mdi:gift',
        'calendar'    => 'mdi:calendar',
        'map'         => 'mdi:map-marker',
        'tag'         => 'mdi:tag',
        'pencil'      => 'mdi:pencil',
        'camera'      => 'mdi:camera',

        // Lecture & collection
        'book'        => 'mdi:book-open-page-variant',
        'library'     => 'mdi:bookshelf',
        'newspaper'   => 'mdi:newspaper-variant',

        // Médias & flux
        'video'       => 'mdi:video',
        'movie'       => 'mdi:movie-open',
        'play'        => 'mdi:play-circle',
        'music'       => 'mdi:music',
        'podcast'     => 'mdi:podcast',
        'headphones'  => 'mdi:headphones',
        'rss'         => 'mdi:rss',
        'image'       => 'mdi:image-multiple',

        // Réseaux & communautés
        'discord'     => 'mdi:discord',
        'message'     => 'mdi:message-text',
        'chat'        => 'mdi:forum',
        'youtube'     => 'mdi:youtube',
        'twitch'      => 'mdi:twitch',
        'mastodon'    => 'mdi:mastodon',
        'reddit'      => 'mdi:reddit',
        'github'      => 'mdi:github',

        // Divers
        'download'    => 'mdi:download',
        'coffee'      => 'mdi:coffee',
        'cash'        => 'mdi:cash-multiple',
    ];
}

// Libellés lisibles pour la modale de sélection
function custom_link_icon_labels(): array {
    return [
        // Général
        'link'        => 'Lien',
        'web'         => 'Web',
        'bookmark'    => 'Marque-page',
        'star'        => 'Étoile',
        'heart'       => 'Cœur',
        'home'        => 'Accueil',
        'information' => 'Information',
        'email'       => 'E-mail',
        'account'     => 'Compte',
        'shopping'    => 'Panier',
        'gift'        => 'Cadeau',
        'calendar'    => 'Calendrier',
        'map'         => 'Carte',
        'tag'         => 'Étiquette',
        'pencil'      => 'Crayon',
        'camera'      => 'Appareil photo',

        // Lecture & collection
        'book'        => 'Livre',
        'library'     => 'Bibliothèque',
        'newspaper'   => 'Journal',

        // Médias & flux
        'video'       => 'Vidéo',
        'movie'       => 'Film',
        'play'        => 'Lecture',
        'music'       => 'Musique',
        'podcast'     => 'Podcast',
        'headphones'  => 'Casque audio',
        'rss'         => 'Flux RSS',
        'image'       => 'Images',

        // Réseaux & communautés
        'discord'     => 'Discord',
        'message'     => 'Message',
        'chat'        => 'Forum',
        'youtube'     => 'YouTube',
        'twitch'      => 'Twitch',
        'mastodon'    => 'Mastodon',
        'reddit'      => 'Reddit',
        'github'      => 'GitHub',

        // Divers
        'download'    => 'Téléchargement',
        'coffee'      => 'Café',
        'cash'        => 'Don',
    ];
}

// Regroupement des clés par catégorie, pour l'affichage de la modale de sélection.
// L'ordre des groupes et des clés définit l'ordre d'affichage.
function custom_link_icon_groups(): array {
    return [
        'Général'               => ['link', 'web', 'bookmark', 'star', 'heart', 'home', 'information', 'email', 'account', 'shopping', 'gift', 'calendar', 'map', 'tag', 'pencil', 'camera'],
        'Lecture & collection'  => ['book', 'library', 'newspaper'],
        'Médias & flux'         => ['video', 'movie', 'play', 'music', 'podcast', 'headphones', 'rss', 'image'],
        'Réseaux & communautés' => ['discord', 'message', 'chat', 'youtube', 'twitch', 'mastodon', 'reddit', 'github'],
        'Divers'                => ['download', 'coffee', 'cash'],
    ];
}

// Renvoie le nom Iconify pour une clé donnée (repli sur "link")
function custom_link_icon_name(?string $key): string {
    $icons = custom_link_icons();
    return $icons[$key] ?? $icons['link'];
}

// Libellé lisible pour une clé donnée (repli sur "Lien")
function custom_link_icon_label(?string $key): string {
    $labels = custom_link_icon_labels();
    return $labels[$key] ?? $labels['link'];
}