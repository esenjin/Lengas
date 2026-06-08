<?php
// logout.php
require_once 'config.php';
register_session_handler();
session_start();
session_unset();
session_destroy();
header('Location: login.php');
exit;
