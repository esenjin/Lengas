<?php
require 'config.php';
require 'includes/themes.php';
require_once 'includes/helpers.php';
require_once 'includes/opengraph.php';
require 'vestikan/vestikan.php';
$options = load_options();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (check_password($password)) {
        register_session_handler();
        session_start();
        $_SESSION['logged_in'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $error = 'Mot de passe incorrect.';
    }
}
$expired   = isset($_GET['expired']);
$sso_error = isset($_GET['sso_error']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Connexion</title>
    <meta name="description" content="<?= htmlspecialchars($options['site_description']) ?>">
    <?= opengraph_tags($options) ?>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/main.css">
    <?= theme_link_tag($options) ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        h1 {
            text-align: center;
            color: #b6b6b6;
            margin-bottom: 1.5em;
        }

        form {
            padding: 2em;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        label {
            display: block;
            margin-bottom: 0.5em;
            font-weight: bold;
            color: #555;
        }

        input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 1.5em;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1em;
            box-sizing: border-box;
        }

        button {
            width: 100%;
            padding: 12px;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            cursor: pointer;
        }

        .error {
            color: red;
            text-align: center;
            margin-bottom: 1em;
        }

        .sso-separator {
            display: flex;
            align-items: center;
            text-align: center;
            color: #888;
            margin: 1.25em 0;
            font-size: 0.85em;
        }

        .sso-separator::before,
        .sso-separator::after {
            content: "";
            flex: 1;
            border-bottom: 1px solid #ddd;
        }

        .sso-separator span {
            padding: 0 0.75em;
        }

        .sso-button {
            display: block;
            width: 100%;
            padding: 12px;
            text-align: center;
            border: 1px solid #888;
            border-radius: 4px;
            font-size: 1em;
            text-decoration: none;
            box-sizing: border-box;
            cursor: pointer;
        }

        .home-link {
            display: block;
            margin-top: 1.5em;
            text-align: center;
            color: #888;
            font-size: 0.9em;
            text-decoration: none;
        }

        .home-link:hover {
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 768px) {
            form {
                width: 95%;
                padding: 1.5em;
            }

            h1 {
                font-size: 1.4em;
            }
        }
    </style>
</head>
<body>
    <h1>Connexion</h1>
    <?php if ($expired): ?>
        <p style="color: orange; text-align: center;">Votre session a expiré. Veuillez vous reconnecter.</p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p style="color: red; text-align: center;"><?= $error ?></p>
    <?php endif; ?>
    <?php if ($sso_error): ?>
        <p style="color: red; text-align: center;">Échec de la connexion avec Vestikan. Réessayez ou utilisez le mot de passe.</p>
    <?php endif; ?>
    <form method="post">
        <label for="password">Mot de passe :</label>
        <input type="password" id="password" name="password" required>
        <button type="submit">Se connecter</button>
        <?php if (vestikan_enabled()): ?>
            <div class="sso-separator"><span>ou</span></div>
            <a href="vestikan/vestikan-login.php" class="sso-button">Se connecter avec Vestikan</a>
        <?php endif; ?>
    </form>
    <a href="index.php" class="home-link">← Retour à l'accueil</a>
</body>
</html>
