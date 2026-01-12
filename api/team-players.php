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
        // Buscar time do usuário da sessão
        $user = getUserSession();
        if (!$user || !isset($user['id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }
        $stmtTeam = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? LIMIT 1');
        $stmtTeam->execute([$user['id']]);
        $row = $stmtTeam->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Time não encontrado para o usuário']);
            exit;
        }
        $teamId = (int)$row['id'];
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
