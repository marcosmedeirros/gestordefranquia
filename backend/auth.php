<?php
// Inicia sessão apenas se ainda não foi iniciada, mantendo usuário logado por mais tempo
if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 60 * 60 * 24 * 30; // 30 dias
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);

    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function requireAuth() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
}

function getUserSession() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'user_type' => $_SESSION['user_type'] ?? 'jogador',
        'league' => $_SESSION['user_league'] ?? 'ROOKIE',
        'photo_url' => $_SESSION['user_photo'] ?? null,
        'phone' => $_SESSION['user_phone'] ?? null,
    ];
}

function setUserSession($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['user_league'] = $user['league'];
    $_SESSION['user_photo'] = $user['photo_url'] ?? null;
    $_SESSION['user_phone'] = $user['phone'] ?? null;
}

function destroyUserSession() {
    session_destroy();
    $_SESSION = [];
}
