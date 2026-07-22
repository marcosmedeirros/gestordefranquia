<?php
/**
 * API de Salary Cap — exclusiva da liga ELITE.
 * action=summary&team_id= → folha salarial, cap flex, cap máximo, espaço e status do time.
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/salary_cap.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = getUserSession();

$action = $_GET['action'] ?? 'summary';

if ($action === 'summary') {
    $teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
    if (!$teamId) {
        $stmtOwn = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? LIMIT 1');
        $stmtOwn->execute([$user['id']]);
        $teamId = (int)($stmtOwn->fetchColumn() ?: 0);
    }
    if (!$teamId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
        exit;
    }

    $stmtLeague = $pdo->prepare("
        SELECT t.league, ls.cap_mode
        FROM teams t
        LEFT JOIN league_settings ls ON ls.league = t.league
        WHERE t.id = ?
    ");
    $stmtLeague->execute([$teamId]);
    $leagueInfo = $stmtLeague->fetch(PDO::FETCH_ASSOC);
    if (!$leagueInfo) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
        exit;
    }
    if (($leagueInfo['cap_mode'] ?? 'ovr_sum') !== 'salary') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Esta liga ainda usa o sistema de Cap antigo (soma de OVR). O Salary Cap novo vale só para a ELITE.']);
        exit;
    }

    $summary = getTeamCapSummary($pdo, $teamId);
    $summary['suggestions'] = getCapSuggestions($summary);
    echo json_encode(['success' => true, 'summary' => $summary]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação inválida']);
