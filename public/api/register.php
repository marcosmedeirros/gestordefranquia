<?php
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers.php';

requireMethod('POST');
$pdo = db();
$body = readJsonBody();

$name = trim($body['name'] ?? '');
$email = strtolower(trim($body['email'] ?? ''));
$password = $body['password'] ?? '';
$userType = $body['user_type'] ?? 'jogador';
$photoUrl = trim($body['photo_url'] ?? '');

if ($name === '' || $email === '' || $password === '') {
    jsonResponse(422, ['error' => 'Nome, e-mail e senha são obrigatórios.']);
}

$exists = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$exists->execute([$email]);
if ($exists->fetch()) {
    jsonResponse(409, ['error' => 'E-mail já cadastrado.']);
}

$token = bin2hex(random_bytes(16));
$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, user_type, verification_token, photo_url) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->execute([$name, $email, $hash, $userType, $token, $photoUrl ?: null]);

sendVerificationEmail($email, $token);

jsonResponse(201, ['message' => 'Usuário criado. Verifique seu e-mail.', 'user_id' => $pdo->lastInsertId()]);
