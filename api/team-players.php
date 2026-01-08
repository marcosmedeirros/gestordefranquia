<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

$pdo = db();

// GET - Listar jogadores de um time
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $teamId = $_GET['team_id'] ?? null;

    if (!$teamId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Team ID não informado']);
        exit;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT id, name, age, position, role, ovr, available_for_trade
            FROM players
            WHERE team_id = ?
            ORDER BY role, ovr DESC
        ');
        $stmt->execute([$teamId]);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'players' => $players]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao carregar jogadores']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não suportado']);
