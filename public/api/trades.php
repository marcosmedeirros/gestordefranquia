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
    $userTeamId = $_GET['team_id'] ?? null;
    
    // Se não foi especificado, obter o time do usuário
    if (!$userTeamId) {
        $stmt = $pdo->prepare("SELECT id FROM teams WHERE user_id = ? AND league = ? LIMIT 1");
        $stmt->execute([$user['id'], $user['league']]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        $userTeamId = $team['id'] ?? null;
    }

    if (!$userTeamId) {
        echo json_encode(['success' => false, 'trades' => []]);
        exit;
    }

    // Obter negociações do time
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            tf.name as team_from,
            tt.name as team_to
        FROM trades t
        LEFT JOIN teams tf ON t.team_from_id = tf.id
        LEFT JOIN teams tt ON t.team_to_id = tt.id
        WHERE t.team_from_id = ? OR t.team_to_id = ?
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$userTeamId, $userTeamId]);
    $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Preparar resposta com informações adicionais
    foreach ($trades as &$trade) {
        $trade['is_receiver'] = $trade['team_to_id'] == $userTeamId;
    }

    echo json_encode(['success' => true, 'trades' => $trades]);
}
else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
}
