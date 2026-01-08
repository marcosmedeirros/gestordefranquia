<?php
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers.php';

$token = $_GET['token'] ?? '';
if ($token === '') {
    jsonResponse(400, ['error' => 'Token ausente.']);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM users WHERE verification_token = ? LIMIT 1');
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    jsonResponse(404, ['error' => 'Token inválido.']);
}

$update = $pdo->prepare('UPDATE users SET email_verified = 1, verification_token = NULL WHERE id = ?');
$update->execute([$user['id']]);

jsonResponse(200, ['message' => 'E-mail verificado com sucesso.']);
