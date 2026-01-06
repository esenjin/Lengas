<?php
// Mot de passe pour l'admin
define('ADMIN_PASSWORD', 'ton_mot_de_passe');

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
    $target_file = UPLOAD_DIR . basename($file["name"]);
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return $target_file;
    }
    return false;
}
?>