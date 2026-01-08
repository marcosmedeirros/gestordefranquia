<?php
session_start();
require_once __DIR__ . '/backend/auth.php';

// Se não estiver logado, redireciona para login
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// Redireciona para dashboard
header('Location: /dashboard.php');
exit;
