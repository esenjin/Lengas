<?php
// Configuration du site
define('SITE_NAME', 'Lengas');
define('SITE_DESCRIPTION', 'Gestion de la collection de mangas d\'Esenjin.');
define('INDEX_PAGE_TITLE', 'Lengas - La mangathèque d\'Esenjin !');
define('ADMIN_PAGE_TITLE', 'Gestion de ma collection');
define('STATS_PAGE_TITLE', 'Statistiques de Lengas');
define('ADMIN_PASSWORD', 'mot_de_passe');
define('SITE_VERSION', '1.3.1');
define('URL_GITEA', 'https://git.crystalyx.net/Esenjin_Asakha/Lengas');

// Chemin vers le fichier JSON
define('DATA_FILE', 'data.json');

// Chemin vers le dossier d'upload
define('UPLOAD_DIR', 'uploads/');

// Initialisation du fichier JSON si inexistant
if (!file_exists(DATA_FILE)) {
    file_put_contents(DATA_FILE, json_encode([]));
}

// Fonction pour charger les données
function load_data() {
    return json_decode(file_get_contents(DATA_FILE), true);
}

// Fonction pour sauvegarder les données
function save_data($data) {
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT));
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