<?php
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers.php';

$pdo = db();
$config = loadConfig();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : null;
    $sql = 'SELECT p.id, p.team_id, p.name, p.age, p.position, p.role, p.ovr, p.available_for_trade FROM players p';
    $params = [];
    if ($teamId) {
        $sql .= ' WHERE p.team_id = ?';
        $params[] = $teamId;
    }
    $sql .= ' ORDER BY p.ovr DESC, p.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $players = $stmt->fetchAll();
    jsonResponse(200, ['players' => $players]);
}

if ($method === 'POST') {
    $body = readJsonBody();
    $teamId = (int) ($body['team_id'] ?? 0);
    $name = trim($body['name'] ?? '');
    $age = (int) ($body['age'] ?? 0);
    $position = trim($body['position'] ?? '');
    $role = $body['role'] ?? 'Titular';
    $ovr = (int) ($body['ovr'] ?? 0);
    $availableForTrade = isset($body['available_for_trade']) ? (int) ((bool) $body['available_for_trade']) : 0;

    if (!$teamId || $name === '' || !$age || $position === '' || !$ovr) {
        jsonResponse(422, ['error' => 'Campos obrigatórios: team_id, nome, idade, posição, ovr.']);
    }

    $teamExists = $pdo->prepare('SELECT id FROM teams WHERE id = ?');
    $teamExists->execute([$teamId]);
    if (!$teamExists->fetch()) {
        jsonResponse(404, ['error' => 'Time não encontrado.']);
    }

    $prospectiveCap = capWithCandidate($pdo, $teamId, $ovr);
    if ($prospectiveCap > $config['app']['cap_max']) {
        jsonResponse(409, ['error' => 'CAP excedido. Máx: ' . $config['app']['cap_max'], 'cap_after' => $prospectiveCap]);
    }

    $stmt = $pdo->prepare('INSERT INTO players (team_id, name, age, position, role, ovr, available_for_trade) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$teamId, $name, $age, $position, $role, $ovr, $availableForTrade]);

    $newCap = topEightCap($pdo, $teamId);
    $warning = $newCap < $config['app']['cap_min'] ? 'CAP abaixo do mínimo. Reforce seu elenco.' : null;

    jsonResponse(201, [
        'message' => 'Jogador adicionado.',
        'player_id' => $pdo->lastInsertId(),
        'cap_top8' => $newCap,
        'warning' => $warning,
    ]);
}

jsonResponse(405, ['error' => 'Method not allowed']);
