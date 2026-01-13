<?php
// Configuration du site
define('SITE_VERSION', '1.4.2');
define('URL_GITEA', 'https://git.crystalyx.net/Esenjin_Asakha/Lengas');

// Logs d'erreurs
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
function upload_image($file, &$error_message = null) {
    // Vérifier si un fichier a été soumis
    if (!isset($file) || $file['error'] == UPLOAD_ERR_NO_FILE) {
        $error_message = "Aucun fichier n'a été téléversé.";
        return false;
    }

    // Vérifier les erreurs d'upload
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
                $error_message = "Erreur inconnue lors de l'upload du fichier.";
        }
        return false;
    }

    // Vérifier le type MIME
    $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $detected_mime_type = mime_content_type($file['tmp_name']);
    if (!in_array($detected_mime_type, $allowed_mime_types)) {
        $error_message = "Type de fichier non autorisé. Seuls les fichiers JPEG, PNG, GIF et WebP sont acceptés.";
        return false;
    }

    // Vérifier l'extension
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $original_name = basename($file['name']);
    $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_extensions)) {
        $error_message = "Extension de fichier non autorisée.";
        return false;
    }

    // Vérifier la taille du fichier
    $max_file_size = 5 * 1024 * 1024; // 5 Mo
    if ($file['size'] > $max_file_size) {
        $error_message = "Le fichier est trop volumineux (max. 5 Mo).";
        return false;
    }

    // Générer un nom unique et déplacer le fichier
    $target_dir = UPLOAD_DIR;
    $unique_name = uniqid() . '.' . $file_extension;
    $target_file = $target_dir . $unique_name;

    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        return $target_file;
    } else {
        $error_message = "Impossible de déplacer le fichier téléversé.";
        return false;
    }
}

// Fonctions CSRF Token
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
?>