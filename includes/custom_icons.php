<?php
// includes/custom_icons.php
// ──────────────────────────────────────────────────────────────────────────────
// Jeu d'icônes disponibles pour les liens personnalisés du menu latéral public.
// La clé est stockée en base (option custom_button_icon*), la valeur est le nom
// Iconify (jeu "mdi") utilisé pour construire l'URL de l'icône SVG.
// ──────────────────────────────────────────────────────────────────────────────

function custom_link_icons(): array {
    return [
        'link'      => 'mdi:link-variant',
        'star'      => 'mdi:star',
        'heart'     => 'mdi:heart',
        'book'      => 'mdi:book-open-page-variant',
        'shopping'  => 'mdi:shopping',
        'account'   => 'mdi:account-circle',
        'web'       => 'mdi:web',
        'discord'   => 'mdi:message-text',
        'rss'       => 'mdi:rss',
        'bookmark'  => 'mdi:bookmark',
    ];
}

// Libellés lisibles pour le <select> des options
function custom_link_icon_labels(): array {
    return [
        'link'      => 'Lien',
        'star'      => 'Étoile',
        'heart'     => 'Cœur',
        'book'      => 'Livre',
        'shopping'  => 'Panier',
        'account'   => 'Compte',
        'web'       => 'Web',
        'discord'   => 'Message',
        'rss'       => 'Flux RSS',
        'bookmark'  => 'Marque-page',
    ];
}

// Renvoie le nom Iconify pour une clé donnée (repli sur "link")
function custom_link_icon_name(?string $key): string {
    $icons = custom_link_icons();
    return $icons[$key] ?? $icons['link'];
}
