<?php
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers.php';

requireMethod('POST');
$pdo = db();
$body = readJsonBody();
$email = strtolower(trim($body['email'] ?? ''));
$password = $body['password'] ?? '';

if ($email === '' || $password === '') {
    jsonResponse(422, ['error' => 'E-mail e senha são obrigatórios.']);
}

$stmt = $pdo->prepare('SELECT id, name, password_hash, user_type, email_verified, photo_url FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    jsonResponse(401, ['error' => 'Credenciais inválidas.']);
}

jsonResponse(200, [
    'user' => [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $email,
        'user_type' => $user['user_type'],
        'email_verified' => (bool) $user['email_verified'],
        'photo_url' => $user['photo_url'] ?? null,
    ],
]);
