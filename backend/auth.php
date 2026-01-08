<?php
session_start();
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';

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
        'photo_url' => $_SESSION['user_photo'] ?? null,
    ];
}

function setUserSession($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['user_photo'] = $user['photo_url'] ?? null;
}

function destroyUserSession() {
    session_destroy();
    $_SESSION = [];
}
