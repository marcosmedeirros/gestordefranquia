<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

requireAuth();

$pdo = getDB();
$user = $_SESSION['user'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $league = $_GET['league'] ?? $user['league'];
    $draftId = $_GET['draft_id'] ?? null;

    if ($draftId) {
        // Obter jogadores de um draft específico
        $stmt = $pdo->prepare("SELECT * FROM draft_players WHERE draft_id = ? ORDER BY id");
        $stmt->execute([$draftId]);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'players' => $players]);
    } else {
        // Listar todos os drafts da liga
        $stmt = $pdo->prepare("SELECT * FROM drafts WHERE league = ? ORDER BY year DESC");
        $stmt->execute([$league]);
        $drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'drafts' => $drafts]);
    }
} 
elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $year = $input['year'] ?? null;
    $league = $user['league'];

    if (!$year) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ano do draft é obrigatório']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO drafts (year, league) VALUES (?, ?)");
        $stmt->execute([$year, $league]);
        $draftId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'draft_id' => $draftId]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Draft deste ano já existe']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao criar draft']);
        }
    }
}
elseif ($method === 'DELETE') {
    $draftId = $_GET['id'] ?? null;

    if (!$draftId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID do draft é obrigatório']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM drafts WHERE id = ?");
        $stmt->execute([$draftId]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao remover draft']);
    }
}
else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
}
