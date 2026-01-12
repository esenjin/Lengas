<?php
// Configuration du site
define('ADMIN_PASSWORD', 'mot_de_passe');
define('SITE_VERSION', '1.4.0');
define('URL_GITEA', 'https://git.crystalyx.net/Esenjin_Asakha/Lengas');

// Chemin vers le fichier JSON
define('DATA_FILE', 'data.json');

// Chemin vers le dossier d'upload
define('UPLOAD_DIR', 'uploads/');

// Chemin vers le fichier de configuration des options
define('OPTIONS_FILE', 'options.json');

// Initialisation du fichier JSON si inexistant
if (!file_exists(DATA_FILE)) {
    file_put_contents(DATA_FILE, json_encode([]));
}

// Initialisation du fichier de configuration des options si inexistant
if (!file_exists(OPTIONS_FILE)) {
    file_put_contents(OPTIONS_FILE, json_encode([
        'site_name' => 'Lengas',
        'site_description' => 'Gestion de la collection de mangas d\'Esenjin.',
        'index_page_title' => 'Lengas - La mangathèque d\'Esenjin !',
        'admin_page_title' => 'Gestion de ma collection',
        'stats_page_title' => 'Statistiques de Lengas',
        'private_mode' => false,
        'hide_mature' => false
    ]));
}

// Fonction pour charger les données
function load_data() {
    return json_decode(file_get_contents(DATA_FILE), true);
}

// Fonction pour sauvegarder les données
function save_data($data) {
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

// Fonction pour charger les options
function load_options() {
    if (file_exists(OPTIONS_FILE)) {
        return json_decode(file_get_contents(OPTIONS_FILE), true);
    }
    return [
        'site_name' => 'Lengas',
        'site_description' => 'Gestion de la collection de mangas d\'Esenjin.',
        'index_page_title' => 'Lengas - La mangathèque d\'Esenjin !',
        'admin_page_title' => 'Gestion de ma collection',
        'stats_page_title' => 'Statistiques de Lengas',
        'private_mode' => false,
        'hide_mature' => false
    ];
}

// Fonction pour sauvegarder les options
function save_options($options) {
    file_put_contents(OPTIONS_FILE, json_encode($options, JSON_PRETTY_PRINT));
}

// Fonction pour vérifier le mot de passe
function check_password($password) {
    return $password === ADMIN_PASSWORD;
}

// Fonction pour uploader une image
function upload_image($file) {
    $target_dir = UPLOAD_DIR;
    $original_name = basename($file["name"]);
    $imageFileType = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $unique_name = uniqid() . '.' . $imageFileType;
    $target_file = $target_dir . $unique_name;

    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return $target_file;
    }
    return false;
}
?>