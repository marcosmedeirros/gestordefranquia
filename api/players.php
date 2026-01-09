<?php
session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';

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

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'players' => $players]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao buscar jogadores', 'details' => $e->getMessage()]);
        exit;
    }
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

    // Verificar propriedade do time pelo usuário logado
    $sessionUser = getUserSession();
    if (!isset($sessionUser['id'])) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }
    $teamExists = $pdo->prepare('SELECT id, user_id FROM teams WHERE id = ?');
    $teamExists->execute([$teamId]);
    $teamRow = $teamExists->fetch();
    if (!$teamRow) {
        jsonResponse(404, ['error' => 'Time não encontrado.']);
    }
    if ((int)$teamRow['user_id'] !== (int)$sessionUser['id']) {
        jsonResponse(403, ['error' => 'Sem permissão para alterar este time.']);
    }

    // Validar limitadores de função
    $roleCountStmt = $pdo->prepare('SELECT role, COUNT(*) as count FROM players WHERE team_id = ? GROUP BY role');
    $roleCountStmt->execute([$teamId]);
    $roleCounts = [];
    while ($row = $roleCountStmt->fetch()) {
        $roleCounts[$row['role']] = (int)$row['count'];
    }
    
    $titularCount = $roleCounts['Titular'] ?? 0;
    $bancoCount = $roleCounts['Banco'] ?? 0;
    $gleagueCount = $roleCounts['G-League'] ?? 0;
    
    // Validar limites
    if ($role === 'Titular' && $titularCount >= 5) {
        jsonResponse(409, ['error' => 'Limite de Titulares atingido (máximo 5).']);
    }
    if ($role === 'G-League' && $gleagueCount >= 2) {
        jsonResponse(409, ['error' => 'Limite de G-League atingido (máximo 2).']);
    }
    
    // Validar elegibilidade para G-League
    if ($role === 'G-League' && $age >= 25) {
        jsonResponse(409, ['error' => 'Jogador não elegível para G-League (deve ter menos de 25 anos).']);
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

if ($method === 'PUT') {
    $body = readJsonBody();
    $playerId = (int) ($body['id'] ?? 0);
    if (!$playerId) jsonResponse(422, ['error' => 'ID do jogador é obrigatório.']);

    $sessionUser = getUserSession();
    if (!isset($sessionUser['id'])) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }

    $stmt = $pdo->prepare('SELECT p.*, t.user_id FROM players p INNER JOIN teams t ON t.id = p.team_id WHERE p.id = ?');
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();
    if (!$player) jsonResponse(404, ['error' => 'Jogador não encontrado.']);
    if ((int)$player['user_id'] !== (int)$sessionUser['id']) {
        jsonResponse(403, ['error' => 'Sem permissão para alterar este jogador.']);
    }

    $name = isset($body['name']) ? trim($body['name']) : $player['name'];
    $age = isset($body['age']) ? (int)$body['age'] : (int)$player['age'];
    $position = isset($body['position']) ? trim($body['position']) : $player['position'];
    $secondaryPosition = isset($body['secondary_position']) ? trim($body['secondary_position']) : ($player['secondary_position'] ?? '');
    $seasonsInLeague = isset($body['seasons_in_league']) ? (int)$body['seasons_in_league'] : (int)($player['seasons_in_league'] ?? 0);
    $role = isset($body['role']) ? $body['role'] : $player['role'];
    $ovr = isset($body['ovr']) ? (int)$body['ovr'] : (int)$player['ovr'];
    $availableForTrade = isset($body['available_for_trade']) ? (int)((bool)$body['available_for_trade']) : (int)$player['available_for_trade'];

    if ($name === '' || !$age || $position === '' || !$ovr) {
        jsonResponse(422, ['error' => 'Campos obrigatórios: nome, idade, posição, ovr.']);
    }

    // Validar limitadores de função se mudou o role
    if ($role !== $player['role']) {
        $roleCountStmt = $pdo->prepare('SELECT role, COUNT(*) as count FROM players WHERE team_id = ? AND id <> ? GROUP BY role');
        $roleCountStmt->execute([(int)$player['team_id'], $playerId]);
        $roleCounts = [];
        while ($row = $roleCountStmt->fetch()) {
            $roleCounts[$row['role']] = (int)$row['count'];
        }
        
        $titularCount = $roleCounts['Titular'] ?? 0;
        $bancoCount = $roleCounts['Banco'] ?? 0;
        $gleagueCount = $roleCounts['G-League'] ?? 0;
        
        if ($role === 'Titular' && $titularCount >= 5) {
            jsonResponse(409, ['error' => 'Limite de Titulares atingido (máximo 5).']);
        }
        if ($role === 'G-League' && $gleagueCount >= 2) {
            jsonResponse(409, ['error' => 'Limite de G-League atingido (máximo 2).']);
        }
        
        // Validar elegibilidade para G-League
        if ($role === 'G-League') {
            if ($seasonsInLeague >= 4 && $age >= 25) {
                jsonResponse(409, ['error' => 'Jogador não elegível para G-League (deve ter menos de 4 temporadas OU menos de 25 anos).']);
            }
        }
    }

    // CAP check: recalcular considerando o novo OVR substituindo o anterior
    $ovrsStmt = $pdo->prepare('SELECT ovr FROM players WHERE team_id = ? AND id <> ? ORDER BY ovr DESC LIMIT 8');
    $ovrsStmt->execute([(int)$player['team_id'], $playerId]);
    $ovrs = $ovrsStmt->fetchAll(PDO::FETCH_COLUMN);
    $ovrs[] = $ovr;
    rsort($ovrs, SORT_NUMERIC);
    $capAfter = array_sum(array_slice($ovrs, 0, 8));
    if ($capAfter > $config['app']['cap_max']) {
        jsonResponse(409, ['error' => 'CAP excedido. Máx: ' . $config['app']['cap_max'], 'cap_after' => $capAfter]);
    }

    $upd = $pdo->prepare('UPDATE players SET name = ?, age = ?, position = ?, secondary_position = ?, seasons_in_league = ?, role = ?, ovr = ?, available_for_trade = ? WHERE id = ?');
    $upd->execute([$name, $age, $position, $secondaryPosition ?: null, $seasonsInLeague, $role, $ovr, $availableForTrade, $playerId]);

    $newCap = topEightCap($pdo, (int)$player['team_id']);
    jsonResponse(200, ['message' => 'Jogador atualizado.', 'cap_top8' => $newCap]);
}

if ($method === 'DELETE') {
    $body = readJsonBody();
    $playerId = (int) ($body['id'] ?? 0);
    if (!$playerId) jsonResponse(422, ['error' => 'ID do jogador é obrigatório.']);

    $sessionUser = getUserSession();
    if (!isset($sessionUser['id'])) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }

    $stmt = $pdo->prepare('SELECT p.id, p.name, p.team_id, t.user_id FROM players p INNER JOIN teams t ON t.id = p.team_id WHERE p.id = ?');
    $stmt->execute([$playerId]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(404, ['error' => 'Jogador não encontrado.']);
    if ((int)$row['user_id'] !== (int)$sessionUser['id']) {
        jsonResponse(403, ['error' => 'Sem permissão para remover este jogador.']);
    }

    // Verificar limite de dispensas (waiver) por temporada
    $currentYear = (int)date('Y');
    $waiverCountStmt = $pdo->prepare('SELECT COUNT(*) as count FROM waivers WHERE team_id = ? AND season_year = ?');
    $waiverCountStmt->execute([(int)$row['team_id'], $currentYear]);
    $waiverCount = (int)$waiverCountStmt->fetchColumn();
    
    if ($waiverCount >= 3) {
        jsonResponse(409, ['error' => 'Limite de 3 dispensas por temporada atingido.']);
    }

    // Registrar a dispensa no waiver
    $waiverStmt = $pdo->prepare('INSERT INTO waivers (team_id, player_name, season_year) VALUES (?, ?, ?)');
    $waiverStmt->execute([(int)$row['team_id'], $row['name'], $currentYear]);

    $del = $pdo->prepare('DELETE FROM players WHERE id = ?');
    $del->execute([$playerId]);
    $newCap = topEightCap($pdo, (int)$row['team_id']);
    jsonResponse(200, ['message' => 'Jogador dispensado.', 'cap_top8' => $newCap, 'waivers_used' => $waiverCount + 1]);
}

jsonResponse(405, ['error' => 'Method not allowed']);
