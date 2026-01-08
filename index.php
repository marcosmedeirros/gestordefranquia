<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Não carrega nada desnecessário, apenas redireciona
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

header('Location: /dashboard.php');
exit;
