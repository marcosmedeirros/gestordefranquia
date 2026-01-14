<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

// Verificar autenticação
$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$pdo = db();

// POST - Adicionar pick
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $teamId = $input['team_id'] ?? null;
    $originalTeamId = $input['original_team_id'] ?? null;
    $year = $input['year'] ?? null;
    $round = $input['round'] ?? null;

    if (!$teamId || !$originalTeamId || !$year || !$round) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        exit;
    }

    // Verificar se o time pertence ao usuário
    $stmt = $pdo->prepare('SELECT id FROM teams WHERE id = ? AND user_id = ?');
    $stmt->execute([$teamId, $user['id']]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
        exit;
    }

    // Inserir ou transferir pick
    try {
        $stmtExisting = $pdo->prepare('
            SELECT id FROM picks WHERE original_team_id = ? AND season_year = ? AND round = ?
        ');
        $stmtExisting->execute([$originalTeamId, $year, $round]);
        $existingId = $stmtExisting->fetchColumn();

        if ($existingId) {
            $stmtUpdate = $pdo->prepare('
                UPDATE picks SET team_id = ?, auto_generated = 0 WHERE id = ?
            ');
            $stmtUpdate->execute([$teamId, $existingId]);
            echo json_encode(['success' => true, 'id' => $existingId, 'action' => 'transferred']);
        } else {
            $stmtInsert = $pdo->prepare('
                INSERT INTO picks (team_id, original_team_id, season_year, round, auto_generated)
                VALUES (?, ?, ?, ?, 0)
            ');
            $stmtInsert->execute([$teamId, $originalTeamId, $year, $round]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'action' => 'created']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao salvar ou transferir pick: ' . $e->getMessage()]);
    }
    exit;
}

// DELETE - Excluir pick
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $pickId = $_GET['id'] ?? null;

    if (!$pickId) {
        echo json_encode(['success' => false, 'error' => 'ID não informado']);
        exit;
    }

    // Verificar se a pick pertence a um time do usuário
    $stmt = $pdo->prepare('
        SELECT p.id, COALESCE(p.auto_generated, 0) as auto_generated
        FROM picks p
        INNER JOIN teams t ON p.team_id = t.id
        WHERE p.id = ? AND t.user_id = ?
    ');
    $stmt->execute([$pickId, $user['id']]);
    $pick = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pick) {
        echo json_encode(['success' => false, 'error' => 'Pick não encontrada']);
        exit;
    }

    if ((int)$pick['auto_generated'] === 1) {
        echo json_encode(['success' => false, 'error' => 'Picks automáticas não podem ser removidas']);
        exit;
    }

    // Excluir pick
    $stmt = $pdo->prepare('DELETE FROM picks WHERE id = ?');
    $stmt->execute([$pickId]);
    
    echo json_encode(['success' => true]);
    exit;
}

// GET - Listar picks
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $teamId = $_GET['team_id'] ?? null;

    if (!$teamId) {
        echo json_encode(['success' => false, 'error' => 'Team ID não informado']);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT p.*, t.city as original_team_city, t.name as original_team_name 
        FROM picks p
        LEFT JOIN teams t ON p.original_team_id = t.id
        WHERE p.team_id = ?
        ORDER BY p.season_year, p.round
    ');
    $stmt->execute([$teamId]);
    $picks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'picks' => $picks]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);
