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

// ──────────────────────────────────────────────────────────────────────────────
// Couleurs des icônes (palette prédéfinie, accordée au thème — non criardes)
// La clé est stockée avec chaque lien, la valeur est le code hexadécimal utilisé
// pour teinter l'icône SVG via l'API Iconify (paramètre ?color=).
// ──────────────────────────────────────────────────────────────────────────────
function custom_link_colors(): array {
    return [
        'red'    => '#f87171',
        'orange' => '#fb923c',
        'yellow' => '#fbbf24',
        'green'  => '#4ade80',
        'blue'   => '#38bdf8',
        'purple' => '#c084fc',
        'brown'  => '#b08968',
        'gray'   => '#9ca3af',
        'white'  => '#f0f0ff',
    ];
}

function custom_link_color_labels(): array {
    return [
        'red'    => 'Rouge',
        'orange' => 'Orange',
        'yellow' => 'Jaune',
        'green'  => 'Vert',
        'blue'   => 'Bleu',
        'purple' => 'Violet',
        'brown'  => 'Brun',
        'gray'   => 'Gris',
        'white'  => 'Blanc',
    ];
}

// Couleur par défaut d'une icône de lien personnalisé
function custom_link_default_color(): string {
    return 'green';
}

// Renvoie le code hexadécimal pour une clé de couleur (repli sur la couleur par défaut)
function custom_link_color_hex(?string $key): string {
    $colors = custom_link_colors();
    return $colors[$key] ?? $colors[custom_link_default_color()];
}

// Valide une clé de couleur contre la palette (repli sur la couleur par défaut)
function custom_link_normalize_color(?string $key): string {
    $colors = custom_link_colors();
    return isset($colors[$key]) ? $key : custom_link_default_color();
}

// ──────────────────────────────────────────────────────────────────────────────
// Lecture centralisée des liens personnalisés.
//
// Les liens sont désormais stockés sous une clé JSON unique « custom_links »
// (tableau d'objets {name, url, icon, color}), ce qui permet un nombre variable
// de liens. Pour rester rétrocompatible, si cette clé est absente, on retombe
// sur les anciennes clés fixes custom_button_name / _url / _icon (max 3).
//
// Renvoie un tableau de liens normalisés : [['name'=>…, 'url'=>…, 'icon'=>…,
// 'color'=>…], …]. Les liens sans nom ou sans URL sont ignorés.
// ──────────────────────────────────────────────────────────────────────────────
function custom_link_get_links(array $options): array {
    $links = [];

    // Source moderne : clé JSON « custom_links »
    if (!empty($options['custom_links'])) {
        $decoded = json_decode($options['custom_links'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) continue;
                $name = trim((string)($item['name'] ?? ''));
                $url  = trim((string)($item['url']  ?? ''));
                if ($name === '' || $url === '') continue;
                $links[] = [
                    'name'  => $name,
                    'url'   => $url,
                    'icon'  => (string)($item['icon'] ?? 'link'),
                    'color' => custom_link_normalize_color($item['color'] ?? null),
                ];
            }
            return $links;
        }
    }

    // Repli : anciennes clés fixes (custom_button_name / _url / _icon, max 3)
    for ($i = 1; $i <= 3; $i++) {
        $suffix = $i === 1 ? '' : $i;
        $name = trim((string)($options["custom_button_name$suffix"] ?? ''));
        $url  = trim((string)($options["custom_button_url$suffix"]  ?? ''));
        if ($name === '' || $url === '') continue;
        $links[] = [
            'name'  => $name,
            'url'   => $url,
            'icon'  => (string)($options["custom_button_icon$suffix"] ?? 'link'),
            'color' => custom_link_default_color(), // les anciens liens n'avaient pas de couleur
        ];
    }
    return $links;
}

// ──────────────────────────────────────────────────────────────────────────────
// Liens sociaux du profil de l'administrateur.
//
// Même structure et même validation que les liens personnalisés, stockés sous
// la clé JSON « admin_social_links » (tableau d'objets {name, url, icon, color}).
// Réutilise le même jeu d'icônes/couleurs. Les liens sans nom ou sans URL sont
// ignorés.
// ──────────────────────────────────────────────────────────────────────────────
function profil_get_social_links(array $options): array {
    $links = [];
    if (empty($options['admin_social_links'])) {
        return $links;
    }
    $decoded = json_decode($options['admin_social_links'], true);
    if (!is_array($decoded)) {
        return $links;
    }
    foreach ($decoded as $item) {
        if (!is_array($item)) continue;
        $name = trim((string)($item['name'] ?? ''));
        $url  = trim((string)($item['url']  ?? ''));
        if ($name === '' || $url === '') continue;
        $links[] = [
            'name'  => $name,
            'url'   => $url,
            'icon'  => (string)($item['icon'] ?? 'link'),
            'color' => custom_link_normalize_color($item['color'] ?? null),
        ];
    }
    return $links;
}