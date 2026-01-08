<?php
session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $teamId = isset($_GET['id']) ? (int) $_GET['id'] : null;
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
    
    // Obter league do usuário da sessão
    $user = getUserSession();
    $league = $user['league'] ?? 'ROOKIE';
    
    $sql = 'SELECT t.id, t.name, t.city, t.mascot, t.photo_url, t.league, t.division_id, d.name AS division_name, t.user_id, u.photo_url AS user_photo
            FROM teams t
            LEFT JOIN divisions d ON d.id = t.division_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.league = ?';
    $params = [$league];
    $params = [$league];
    $clauses = [];
    if ($teamId) {
        $clauses[] = 't.id = ?';
        $params[] = $teamId;
    }
    if ($userId) {
        $clauses[] = 't.user_id = ?';
        $params[] = $userId;
    }
    if ($clauses) {
        $sql .= ' AND ' . implode(' AND ', $clauses);
    }
    $sql .= ' ORDER BY t.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $teams = $stmt->fetchAll();

    foreach ($teams as &$team) {
        $team['cap_top8'] = topEightCap($pdo, (int) $team['id']);
    }

    jsonResponse(200, ['teams' => $teams]);
}

if ($method === 'POST') {
    $body = readJsonBody();
    $name = trim($body['name'] ?? '');
    $city = trim($body['city'] ?? '');
    $mascot = trim($body['mascot'] ?? '');
    $divisionId = $body['division_id'] ?? null;
    $userId = $body['user_id'] ?? null;
    $photoUrl = trim($body['photo_url'] ?? '');
    
    // Obter league do usuário
    $userStmt = $pdo->prepare('SELECT league FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch();
    if (!$userRow) {
        jsonResponse(404, ['error' => 'Usuário não encontrado.']);
    }
    $league = $userRow['league'];

    if ($name === '' || $city === '' || $mascot === '' || !$userId) {
        jsonResponse(422, ['error' => 'Nome, cidade, mascote e user_id são obrigatórios.']);
    }

    if ($divisionId) {
        $checkDiv = $pdo->prepare('SELECT id FROM divisions WHERE id = ?');
        $checkDiv->execute([$divisionId]);
        if (!$checkDiv->fetch()) {
            jsonResponse(404, ['error' => 'Divisão não encontrada.']);
        }
    }

    $stmt = $pdo->prepare('INSERT INTO teams (user_id, league, name, city, mascot, division_id, photo_url) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $league, $name, $city, $mascot, $divisionId, $photoUrl ?: null]);
    $teamId = (int) $pdo->lastInsertId();

    // Cada time ganha 1 pick de 1ª e 2ª rodada para o ano corrente.
    $seasonYear = (int) date('Y');
    $pickStmt = $pdo->prepare('INSERT INTO picks (team_id, original_team_id, season_year, round, notes) VALUES (?, ?, ?, ?, ?)');
    $pickStmt->execute([$teamId, $teamId, $seasonYear, '1', 'Pick de 1ª rodada gerada automaticamente.']);
    $pickStmt->execute([$teamId, $teamId, $seasonYear, '2', 'Pick de 2ª rodada gerada automaticamente.']);

    jsonResponse(201, ['message' => 'Time criado.', 'team_id' => $teamId]);
}

jsonResponse(405, ['error' => 'Method not allowed']);
