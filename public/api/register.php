<?php
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers.php';

requireMethod('POST');
$pdo = db();
$body = readJsonBody();

$name = trim($body['name'] ?? '');
$email = strtolower(trim($body['email'] ?? ''));
$password = $body['password'] ?? '';
$league = strtoupper(trim($body['league'] ?? ''));
$userType = 'jogador'; // Sempre jogador por padrão
$photoUrl = trim($body['photo_url'] ?? '');

if ($name === '' || $email === '' || $password === '' || $league === '') {
    jsonResponse(422, ['error' => 'Nome, e-mail, senha e liga são obrigatórios.']);
}

if (!in_array($league, ['ELITE', 'PRIME', 'RISE', 'ROOKIE'])) {
    jsonResponse(422, ['error' => 'Liga inválida.']);
}

$exists = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$exists->execute([$email]);
if ($exists->fetch()) {
    jsonResponse(409, ['error' => 'E-mail já cadastrado.']);
}

$token = bin2hex(random_bytes(16));
$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, user_type, league, verification_token, photo_url) VALUES (?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([$name, $email, $hash, $userType, $league, $token, $photoUrl ?: null]);

sendVerificationEmail($email, $token);

jsonResponse(201, ['message' => 'Usuário criado. Verifique seu e-mail.', 'user_id' => $pdo->lastInsertId()]);
