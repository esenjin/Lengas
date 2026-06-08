<?php
// logout.php
ini_set('session.gc_maxlifetime', 7 * 24 * 60 * 60);

session_set_cookie_params([
    'lifetime' => 7 * 24 * 60 * 60,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
session_unset();
session_destroy();
header('Location: login.php');
exit;
?>
