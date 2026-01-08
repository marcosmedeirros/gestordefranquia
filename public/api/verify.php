<?php
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers.php';

$token = $_GET['token'] ?? '';
if ($token === '') {
    header('Location: /login.php?error=token_missing');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM users WHERE verification_token = ? LIMIT 1');
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: /login.php?error=invalid_token');
    exit;
}

$update = $pdo->prepare('UPDATE users SET email_verified = 1, verification_token = NULL WHERE id = ?');
$update->execute([$user['id']]);

header('Location: /login.php?verified=1');
exit;
