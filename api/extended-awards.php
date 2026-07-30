<?php
/**
 * Prêmios estendidos (Finals MVP, All-NBA 1º/2º/3º, All-Defensive 1º/2º).
 * Gravados em season_awards com award_type próprio; o motor de cap
 * (capAwardBonusTable) aplica o bônus temporário na temporada seguinte.
 * Admin-only. GET lista; POST grava (substitui os estendidos da temporada).
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
header('Content-Type: application/json');

requireAuth();
$user = getUserSession();
$pdo  = db();
if (!hasAdminAccess($pdo, (int)$user['id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
    exit;
}

const EXT_AWARD_TYPES = ['finals_mvp', 'all_nba_1', 'all_nba_2', 'all_nba_3', 'all_def_1', 'all_def_2'];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $league = strtoupper((string)($_GET['league'] ?? 'ELITE'));
    $stmtS = $pdo->prepare("SELECT id, season_number, year FROM seasons WHERE league = ? ORDER BY sprint_id DESC, season_number DESC");
    $stmtS->execute([$league]);
    $seasons = $stmtS->fetchAll(PDO::FETCH_ASSOC);

    $stmtT = $pdo->prepare("SELECT id, CONCAT(city,' ',name) AS name FROM teams WHERE league = ? ORDER BY city, name");
    $stmtT->execute([$league]);
    $teams = $stmtT->fetchAll(PDO::FETCH_ASSOC);

    $awards = [];
    $seasonId = (int)($_GET['season_id'] ?? 0);
    if ($seasonId > 0) {
        $ph = implode(',', array_fill(0, count(EXT_AWARD_TYPES), '?'));
        $stmtA = $pdo->prepare("SELECT award_type, team_id, player_name FROM season_awards
                                WHERE season_id = ? AND award_type IN ($ph) ORDER BY id");
        $stmtA->execute(array_merge([$seasonId], EXT_AWARD_TYPES));
        $awards = $stmtA->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['success' => true, 'league' => $league, 'seasons' => $seasons, 'teams' => $teams, 'awards' => $awards]);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $seasonId = (int)($data['season_id'] ?? 0);
    $items = $data['awards'] ?? [];
    if ($seasonId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Temporada obrigatória']);
        exit;
    }
    if (!is_array($items)) $items = [];

    try {
        $pdo->beginTransaction();
        $ph = implode(',', array_fill(0, count(EXT_AWARD_TYPES), '?'));
        $pdo->prepare("DELETE FROM season_awards WHERE season_id = ? AND award_type IN ($ph)")
            ->execute(array_merge([$seasonId], EXT_AWARD_TYPES));

        $ins = $pdo->prepare("INSERT INTO season_awards (season_id, team_id, award_type, player_name) VALUES (?, ?, ?, ?)");
        $count = 0;
        foreach ($items as $it) {
            $type   = (string)($it['award_type'] ?? '');
            $teamId = (int)($it['team_id'] ?? 0);
            $player = trim((string)($it['player_name'] ?? ''));
            if (!in_array($type, EXT_AWARD_TYPES, true)) continue;
            if ($teamId <= 0 || $player === '') continue;
            $ins->execute([$seasonId, $teamId, $type, $player]);
            $count++;
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'saved' => $count]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao salvar prêmios estendidos']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não suportado']);
