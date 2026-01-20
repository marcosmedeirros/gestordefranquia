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

// POST - Desabilitado: sistema gera picks automaticamente
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Edição manual de picks desabilitada. As picks são geradas automaticamente.']);
    exit;
}

// DELETE - Desabilitado
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Exclusão manual de picks desabilitada. As picks são geridas automaticamente.']);
    exit;
}

// PUT - Desabilitado
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Atualização manual de picks desabilitada. As picks são geradas automaticamente.']);
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
