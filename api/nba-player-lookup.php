<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';
require_once dirname(__DIR__) . '/backend/nba_lookup.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$playerId = (int)($body['player_id'] ?? 0);
$playerName = trim($body['player_name'] ?? '');

if (!$playerId && $playerName === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'player_id ou player_name obrigatório']);
    exit;
}

$pdo = db();

try {
    if ($playerId) {
        $stmt = $pdo->prepare('SELECT id, name, nba_player_id FROM players WHERE id = ? LIMIT 1');
        $stmt->execute([$playerId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$player) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Jogador não encontrado']);
            exit;
        }
        if (!empty($player['nba_player_id'])) {
            echo json_encode([
                'success' => true,
                'nba_player_id' => $player['nba_player_id'],
                'source' => 'cache',
                'matched_name' => $player['name']
            ]);
            exit;
        }
        $playerName = $player['name'];
    }

    if ($playerName === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Nome do jogador obrigatório']);
        exit;
    }

    $lookupResult = fetchNbaPlayerIdByName($playerName);
    if (!$lookupResult) {
        echo json_encode(['success' => true, 'nba_player_id' => null, 'matched_name' => null]);
        exit;
    }

    if ($playerId) {
        $stmtUpdate = $pdo->prepare('UPDATE players SET nba_player_id = ? WHERE id = ?');
        $stmtUpdate->execute([$lookupResult['id'], $playerId]);
    }

    echo json_encode([
        'success' => true,
        'nba_player_id' => $lookupResult['id'],
        'matched_name' => $lookupResult['name'],
        'headshot_url' => buildHeadshotUrl($lookupResult['id'])
    ]);
} catch (Exception $e) {
    error_log('nba-player-lookup error: ' . $e->getMessage());
    http_response_code(200);
    // Nunca derruba o front: retorna success=true com nulls.
    echo json_encode([
        'success' => true,
        'nba_player_id' => null,
        'matched_name' => null,
        'headshot_url' => null,
        'source' => 'error'
    ]);
}
