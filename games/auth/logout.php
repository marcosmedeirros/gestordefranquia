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

// AQUI ESTÁ A MUDANÇA: Manda para a sua URL da Hostinger
header("Location: https://jogos.fbabrasil.com.br/");
exit;
?>
