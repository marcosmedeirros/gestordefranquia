<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$pdo = db();

try {
    $league = $_GET['league'] ?? null;
    
    if (!$league) {
        echo json_encode(['success' => false, 'error' => 'Liga não especificada']);
        exit;
    }

    // Teste 1: Verificar conexão com banco
    $testConn = $pdo->query("SELECT 1 as test");
    $connResult = $testConn->fetch();
    
    // Teste 2: Contar temporadas completas
    $stmtCount = $pdo->prepare("SELECT COUNT(*) as total FROM seasons WHERE league = ? AND status = 'completed'");
    $stmtCount->execute([$league]);
    $countResult = $stmtCount->fetch();
    
    // Teste 3: Listar temporadas (todas, não apenas completas)
    $stmtAll = $pdo->prepare("SELECT id, season_number, status, league FROM seasons WHERE league = ? LIMIT 10");
    $stmtAll->execute([$league]);
    $allSeasons = $stmtAll->fetchAll();
    
    // Teste 4: Executar a query real de full_history
    $stmt = $pdo->prepare("
        SELECT s.id as season_id, s.season_number, s.year, s.league
        FROM seasons s
        WHERE s.league = ? AND s.status = 'completed'
        ORDER BY s.id DESC
    ");
    $stmt->execute([$league]);
    $completedSeasons = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'database_connection' => ($connResult ? 'OK' : 'FAIL'),
        'league_queried' => $league,
        'completed_seasons_count' => $countResult['total'] ?? 0,
        'all_seasons_count' => count($allSeasons),
        'all_seasons_sample' => $allSeasons,
        'completed_seasons' => $completedSeasons,
        'debug_info' => [
            'php_version' => phpversion(),
            'error_reporting' => error_reporting(),
            'display_errors' => ini_get('display_errors')
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
