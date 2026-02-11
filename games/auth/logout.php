<?php
session_start();

// Limpa tudo
$_SESSION = array();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Redireciona para a tela de login do games
header("Location: https://games.fbabrasil.com.br/auth/login.php");
exit;
?>
