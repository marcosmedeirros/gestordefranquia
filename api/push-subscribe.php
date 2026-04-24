<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

$pdo = db();

// Garantir que a tabela existe
$pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    endpoint   TEXT NOT NULL,
    p256dh     TEXT NOT NULL,
    auth       TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_endpoint (endpoint(512))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$method = $_SERVER['REQUEST_METHOD'];

// GET — retorna a chave pública VAPID para o front-end
if ($method === 'GET') {
    $configFile = dirname(__DIR__) . '/backend/vapid-config.php';
    if (!file_exists($configFile)) {
        echo json_encode(['success' => false, 'publicKey' => null]);
        exit;
    }
    $config = require $configFile;
    echo json_encode(['success' => true, 'publicKey' => $config['vapid_public_key']]);
    exit;
}

// POST e DELETE precisam de autenticação
$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

// POST — salva ou atualiza subscription
if ($method === 'POST') {
    $data     = json_decode(file_get_contents('php://input'), true);
    $endpoint = $data['endpoint'] ?? null;
    $p256dh   = $data['keys']['p256dh'] ?? null;
    $auth     = $data['keys']['auth'] ?? null;

    if (!$endpoint || !$p256dh || !$auth) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth)
    ");
    $stmt->execute([$user['id'], $endpoint, $p256dh, $auth]);
    echo json_encode(['success' => true]);
    exit;
}

// DELETE — remove subscription
if ($method === 'DELETE') {
    $data     = json_decode(file_get_contents('php://input'), true);
    $endpoint = $data['endpoint'] ?? null;
    if ($endpoint) {
        $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?')
            ->execute([$user['id'], $endpoint]);
    }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido']);
