<?php
// Configuration du site
define('SITE_VERSION', '1.4.1');
define('URL_GITEA', 'https://git.crystalyx.net/Esenjin_Asakha/Lengas');

// Chemin vers le fichier de mot de passe
define('PASSWORD_FILE', 'bdd/mdp.json');

// Fonction pour charger le hash du mot de passe
function load_password_hash() {
    if (file_exists(PASSWORD_FILE)) {
        $data = json_decode(file_get_contents(PASSWORD_FILE), true);
        return $data['admin_password_hash'] ?? null;
    }
    return null;
}

// Fonction pour vérifier le mot de passe
function check_password($password) {
    $hash = load_password_hash();
    return password_verify($password, $hash);
}

// Chemin vers le dossier d'upload
define('UPLOAD_DIR', 'uploads/');

// Chemin vers les fichiers JSON dans le dossier bdd/
define('DATA_FILE', 'bdd/data.json');
define('OPTIONS_FILE', 'bdd/options.json');
define('LOAN_FILE', 'bdd/loan.json');
define('WISHLIST_FILE', 'bdd/list.json');

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