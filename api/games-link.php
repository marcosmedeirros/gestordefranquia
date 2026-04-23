<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';

$pdo = db();
$action = $_GET['action'] ?? '';

if ($action === 'start') {
    requireAuth();
    $user = getUserSession();
    $token = bin2hex(random_bytes(16));
    $expiresAt = (new DateTime('+10 minutes', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('UPDATE users SET games_link_token = ?, games_link_expires_at = ? WHERE id = ?');
    $stmt->execute([$token, $expiresAt, $user['id']]);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'fbabrasil.com.br';
    $returnUrl = $scheme . '://' . $host . '/dashboard.php';

    $gamesUrl = 'https://games.fbabrasil.com.br/auth/link-fba.php?token=' . urlencode($token) . '&return=' . urlencode($returnUrl);
    header('Location: ' . $gamesUrl);
    exit;
}

if ($action === 'complete') {
    $token = $_POST['token'] ?? $_GET['token'] ?? '';
    $gamesUserId = (int)($_POST['games_user_id'] ?? $_GET['games_user_id'] ?? 0);

    if ($token === '' || $gamesUserId <= 0) {
        jsonResponse(400, ['success' => false, 'error' => 'token e games_user_id obrigatórios']);
    }

    $stmt = $pdo->prepare('SELECT id, games_link_expires_at FROM users WHERE games_link_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonResponse(404, ['success' => false, 'error' => 'Token inválido']);
    }

    if (!empty($row['games_link_expires_at']) && strtotime($row['games_link_expires_at']) < time()) {
        jsonResponse(410, ['success' => false, 'error' => 'Token expirado']);
    }

    $pdo->prepare('UPDATE users SET games_user_id = ?, games_linked_at = NOW(), games_link_token = NULL, games_link_expires_at = NULL WHERE id = ?')
        ->execute([$gamesUserId, $row['id']]);

    jsonResponse(200, ['success' => true]);
}

jsonResponse(400, ['success' => false, 'error' => 'Ação inválida']);
