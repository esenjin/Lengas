<?php
// Configuration du site
define('SITE_VERSION', '2.1.3');
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
define('READ_FILE', 'bdd/read.json');

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
function upload_image(array $file, &$error_message = null) {

    // Vérifier la structure du fichier
    if (
        !isset($file['error'], $file['tmp_name'], $file['name'], $file['size']) ||
        $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        $error_message = "Aucun fichier n'a été téléversé.";
        return false;
    }

    // Vérifier les erreurs d'upload PHP
    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error_message = "Le fichier est trop volumineux (max. 5 Mo).";
                break;
            case UPLOAD_ERR_PARTIAL:
                $error_message = "Le fichier n'a été que partiellement téléversé.";
                break;
            default:
                $error_message = "Erreur inconnue lors du téléversement.";
        }
        return false;
    }

    // Vérifier que le fichier est bien un upload PHP
    if (!is_uploaded_file($file['tmp_name'])) {
        $error_message = "Fichier invalide ou corrompu.";
        return false;
    }

    // Vérifier la taille du fichier (double sécurité)
    $max_file_size = 5 * 1024 * 1024; // 5 Mo
    if ($file['size'] > $max_file_size) {
        $error_message = "Le fichier est trop volumineux (max. 5 Mo).";
        return false;
    }

    // Vérifier le type MIME réel (FileInfo)
    $allowed_mime_types = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp'
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        $error_message = "Impossible d'initialiser la détection MIME.";
        return false;
    }

    $detected_mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($detected_mime_type === false || !in_array($detected_mime_type, $allowed_mime_types, true)) {
        $error_message = "Type de fichier non autorisé.";
        return false;
    }

    // Vérifier que c'est vraiment une image
    if (getimagesize($file['tmp_name']) === false) {
        $error_message = "Le fichier n'est pas une image valide.";
        return false;
    }

    // Vérifier l'extension
    $allowed_extensions = ['jpeg', 'jpg', 'png', 'gif', 'webp'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($file_extension, $allowed_extensions, true)) {
        $error_message = "Extension de fichier non autorisée.";
        return false;
    }

    // Vérifier le dossier de destination
    $target_dir = rtrim(UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (!is_dir($target_dir) || !is_writable($target_dir)) {
        $error_message = "Le dossier de destination est invalide ou non accessible.";
        return false;
    }

    // Générer un nom unique sécurisé
    $unique_name = bin2hex(random_bytes(16)) . '.' . $file_extension;
    $target_file = $target_dir . $unique_name;

    // Déplacer le fichier
    if (!move_uploaded_file($file['tmp_name'], $target_file)) {
        $error_message = "Impossible de déplacer le fichier téléversé.";
        return false;
    }

    return $target_file;
}