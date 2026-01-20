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
                UPDATE picks SET team_id = ?, last_owner_team_id = ?, auto_generated = 0 WHERE id = ?
            ');
            $stmtUpdate->execute([$teamId, $teamId, $existingId]);
            echo json_encode(['success' => true, 'id' => $existingId, 'action' => 'transferred']);
        } else {
            $stmtInsert = $pdo->prepare('
                INSERT INTO picks (team_id, original_team_id, season_year, round, auto_generated, last_owner_team_id)
                VALUES (?, ?, ?, ?, 0, ?)
            ');
            $stmtInsert->execute([$teamId, $originalTeamId, $year, $round, $teamId]);
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
        SELECT p.id
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

    // Excluir pick
    $stmt = $pdo->prepare('DELETE FROM picks WHERE id = ?');
    $stmt->execute([$pickId]);
    
    echo json_encode(['success' => true]);
    exit;
}

// PUT - Atualizar pick manual
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $pickId = $input['id'] ?? null;
    $year = isset($input['year']) ? (int)$input['year'] : null;
    $round = $input['round'] ?? null;
    $originalTeamId = $input['original_team_id'] ?? null;
    $notes = trim((string)($input['notes'] ?? ''));

    if (!$pickId || !$year || !$round || !$originalTeamId) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT p.id, p.team_id, p.auto_generated
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

    // Verificar conflito de duplicidade
    $stmtDup = $pdo->prepare('
        SELECT id FROM picks 
        WHERE original_team_id = ? AND season_year = ? AND round = ? AND id != ?
    ');
    $stmtDup->execute([$originalTeamId, $year, $round, $pickId]);
    if ($stmtDup->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Já existe uma pick para este time/ano/rodada']);
        exit;
    }

    $stmtUpdate = $pdo->prepare('
        UPDATE picks 
        SET season_year = ?, round = ?, original_team_id = ?, last_owner_team_id = team_id, notes = ?, auto_generated = 0
        WHERE id = ?
    ');
    $stmtUpdate->execute([$year, $round, $originalTeamId, $notes ?: null, $pickId]);

    echo json_encode(['success' => true, 'message' => 'Pick atualizada']);
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
        SELECT p.*, 
               orig.city as original_team_city, orig.name as original_team_name,
               last_t.city as last_owner_city, last_t.name as last_owner_name
        FROM picks p
        LEFT JOIN teams orig ON p.original_team_id = orig.id
        LEFT JOIN teams last_t ON p.last_owner_team_id = last_t.id
        WHERE p.team_id = ?
        ORDER BY p.season_year, p.round
    ');
    $stmt->execute([$teamId]);
    $picks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'picks' => $picks]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);
