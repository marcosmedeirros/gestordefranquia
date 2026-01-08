<?php
session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonResponse(405, ['error' => 'Method not allowed']);
}

$user = getUserSession();
if (!$user) jsonResponse(401, ['error' => 'Não autenticado']);

$body = readJsonBody();
$current = $body['current_password'] ?? '';
$new = $body['new_password'] ?? '';

if ($current === '' || $new === '') {
    jsonResponse(422, ['error' => 'Informe a senha atual e a nova senha.']);
}

// Verificar senha atual
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
if (!$row || !password_verify($current, $row['password_hash'])) {
    jsonResponse(403, ['error' => 'Senha atual incorreta.']);
}

// Atualizar senha
$newHash = password_hash($new, PASSWORD_DEFAULT);
$upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
$upd->execute([$newHash, $user['id']]);

jsonResponse(200, ['message' => 'Senha alterada com sucesso.']);
