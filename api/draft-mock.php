<?php
/**
 * API de Mock Draft
 * Fila de pré-seleção com auto-pick após 30 min
 */

require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/push.php';

header('Content-Type: application/json');

try { requireAuth(); } catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$user = getUserSession();
$pdo  = db();

// Garante tabelas e coluna
$pdo->exec("CREATE TABLE IF NOT EXISTS draft_mock_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  team_id INT NOT NULL,
  draft_session_id INT NOT NULL,
  player_id INT NOT NULL,
  priority INT NOT NULL DEFAULT 1,
  KEY idx_tms (team_id, draft_session_id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS draft_mock_settings (
  team_id INT NOT NULL,
  draft_session_id INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (team_id, draft_session_id)
)");

try {
    $pdo->exec("ALTER TABLE draft_sessions ADD COLUMN current_pick_started_at DATETIME NULL");
} catch (Exception $e) {} // já existe

$isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';

$stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$userTeam = $stmtTeam->fetch();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data   = [];

if ($method === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? $action;
}

switch ($action) {

    // ── GET: fila + configuração ──────────────────────
    case 'get':
        $draftSessionId = (int)($_GET['draft_session_id'] ?? 0);
        $teamId = (int)($userTeam['id'] ?? 0);

        if (!$draftSessionId || !$teamId) {
            echo json_encode(['success' => true, 'queue' => [], 'is_active' => false]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT mq.id, mq.player_id, mq.priority,
                   dp.name as player_name, dp.position as player_position,
                   dp.ovr as player_ovr, dp.age as player_age,
                   dp.draft_status
            FROM draft_mock_queue mq
            JOIN draft_pool dp ON mq.player_id = dp.id
            WHERE mq.team_id = ? AND mq.draft_session_id = ?
            ORDER BY mq.priority ASC
        ");
        $stmt->execute([$teamId, $draftSessionId]);
        $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtS = $pdo->prepare("SELECT is_active FROM draft_mock_settings WHERE team_id = ? AND draft_session_id = ?");
        $stmtS->execute([$teamId, $draftSessionId]);
        $settings = $stmtS->fetch();

        echo json_encode([
            'success'   => true,
            'queue'     => $queue,
            'is_active' => (bool)($settings['is_active'] ?? false),
        ]);
        break;

    // ── POST: salvar fila ─────────────────────────────
    case 'save':
        $draftSessionId = (int)($data['draft_session_id'] ?? 0);
        $teamId = (int)($userTeam['id'] ?? 0);
        $playerIds = array_values(array_filter(array_map('intval', $data['player_ids'] ?? []), fn($v) => $v > 0));

        if (!$draftSessionId || !$teamId) {
            echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
            exit;
        }
        if (count($playerIds) > 8) {
            echo json_encode(['success' => false, 'error' => 'Máximo 8 jogadores no mock']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM draft_mock_queue WHERE team_id = ? AND draft_session_id = ?")
                ->execute([$teamId, $draftSessionId]);

            foreach ($playerIds as $idx => $playerId) {
                $pdo->prepare("INSERT INTO draft_mock_queue (team_id, draft_session_id, player_id, priority) VALUES (?, ?, ?, ?)")
                    ->execute([$teamId, $draftSessionId, $playerId, $idx + 1]);
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']);
        }
        break;

    // ── POST: ligar/desligar auto-pick ────────────────
    case 'toggle':
        $draftSessionId = (int)($data['draft_session_id'] ?? 0);
        $teamId = (int)($userTeam['id'] ?? 0);
        $isActive = !empty($data['is_active']);

        if (!$draftSessionId || !$teamId) {
            echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
            exit;
        }

        $pdo->prepare("
            INSERT INTO draft_mock_settings (team_id, draft_session_id, is_active)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)
        ")->execute([$teamId, $draftSessionId, $isActive ? 1 : 0]);

        echo json_encode(['success' => true, 'is_active' => $isActive]);
        break;

    // ── GET: verificar e executar auto-pick ───────────
    case 'check_autopick':
        $draftSessionId = (int)($_GET['draft_session_id'] ?? ($data['draft_session_id'] ?? 0));
        if (!$draftSessionId) {
            echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
            exit;
        }

        $stmtSess = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "in_progress"');
        $stmtSess->execute([$draftSessionId]);
        $session = $stmtSess->fetch();
        if (!$session) {
            echo json_encode(['success' => true, 'autopicked' => false, 'reason' => 'not_in_progress']);
            exit;
        }

        // Pick atual
        $stmtPick = $pdo->prepare('
            SELECT * FROM draft_order
            WHERE draft_session_id = ? AND round = ? AND pick_position = ? AND picked_player_id IS NULL
        ');
        $stmtPick->execute([$draftSessionId, (int)$session['current_round'], (int)$session['current_pick']]);
        $currentPick = $stmtPick->fetch();
        if (!$currentPick) {
            echo json_encode(['success' => true, 'autopicked' => false, 'reason' => 'no_pending_pick']);
            exit;
        }

        $currentTeamId = (int)$currentPick['team_id'];

        // Mock ativo para o time atual?
        $stmtSettings = $pdo->prepare("SELECT is_active FROM draft_mock_settings WHERE team_id = ? AND draft_session_id = ?");
        $stmtSettings->execute([$currentTeamId, $draftSessionId]);
        $settings = $stmtSettings->fetch();
        if (empty($settings['is_active'])) {
            echo json_encode(['success' => true, 'autopicked' => false, 'reason' => 'mock_disabled']);
            exit;
        }

        // Primeiro disponível na fila
        $stmtQueue = $pdo->prepare("
            SELECT mq.player_id, dp.name, dp.position, dp.age, dp.ovr
            FROM draft_mock_queue mq
            JOIN draft_pool dp ON mq.player_id = dp.id
            WHERE mq.team_id = ? AND mq.draft_session_id = ?
              AND dp.draft_status = 'available'
            ORDER BY mq.priority ASC
            LIMIT 1
        ");
        $stmtQueue->execute([$currentTeamId, $draftSessionId]);
        $playerToPick = $stmtQueue->fetch();

        if (!$playerToPick) {
            echo json_encode(['success' => true, 'autopicked' => false, 'reason' => 'no_available_player_in_queue']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $playerId = (int)$playerToPick['player_id'];

            // Atualiza pick na ordem
            $pdo->prepare('UPDATE draft_order SET picked_player_id = ?, picked_at = NOW(), team_id = ? WHERE id = ?')
                ->execute([$playerId, $currentTeamId, (int)$currentPick['id']]);

            // Calcula número da pick
            $stmtTotalRound = $pdo->prepare('SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ? AND round = ?');
            $stmtTotalRound->execute([$draftSessionId, (int)$currentPick['round']]);
            $roundSize = (int)$stmtTotalRound->fetchColumn();
            $pickNumber = (((int)$currentPick['round'] - 1) * $roundSize) + (int)$currentPick['pick_position'];

            // Marca jogador como selecionado
            $pdo->prepare('UPDATE draft_pool SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ? WHERE id = ?')
                ->execute([$currentTeamId, $pickNumber, $playerId]);

            // Insere no elenco
            $stmtChk = $pdo->prepare('SELECT id FROM players WHERE team_id = ? AND name = ? LIMIT 1');
            $stmtChk->execute([$currentTeamId, $playerToPick['name']]);
            if (!$stmtChk->fetchColumn()) {
                $pdo->prepare('INSERT INTO players (team_id, drafted_by_team_id, name, position, age, ovr, role, available_for_trade) VALUES (?, ?, ?, ?, ?, ?, "Banco", 0)')
                    ->execute([$currentTeamId, $currentTeamId, $playerToPick['name'], $playerToPick['position'], (int)$playerToPick['age'], (int)$playerToPick['ovr']]);
            }

            // Avança para próxima pick
            $stmtNext = $pdo->prepare('SELECT round, pick_position FROM draft_order WHERE draft_session_id = ? AND picked_player_id IS NULL ORDER BY round ASC, pick_position ASC LIMIT 1');
            $stmtNext->execute([$draftSessionId]);
            $next = $stmtNext->fetch(PDO::FETCH_ASSOC);

            $nextTeamId = null;
            if ($next) {
                $pdo->prepare('UPDATE draft_sessions SET current_round = ?, current_pick = ?, current_pick_started_at = NOW() WHERE id = ?')
                    ->execute([(int)$next['round'], (int)$next['pick_position'], $draftSessionId]);
                $stmtNextTeam = $pdo->prepare('SELECT team_id FROM draft_order WHERE draft_session_id = ? AND round = ? AND pick_position = ? LIMIT 1');
                $stmtNextTeam->execute([$draftSessionId, (int)$next['round'], (int)$next['pick_position']]);
                $nextTeamId = (int)($stmtNextTeam->fetchColumn() ?: 0);
            } else {
                $pdo->prepare('UPDATE draft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')
                    ->execute([$draftSessionId]);
            }

            $pdo->commit();

            if ($nextTeamId && $next) {
                $stmtUser = $pdo->prepare('SELECT u.id FROM teams t JOIN users u ON t.user_id = u.id WHERE t.id = ? LIMIT 1');
                $stmtUser->execute([$nextTeamId]);
                $nextUserId = (int)($stmtUser->fetchColumn() ?: 0);
                if ($nextUserId) {
                    sendPushToUser($pdo, $nextUserId, [
                        'title'      => '🏀 É a sua vez no Draft!',
                        'body'       => "Rodada {$next['round']} · Pick #{$next['pick_position']} — É a sua vez!",
                        'url'        => '/drafts.php',
                        'primaryKey' => 'draft_pick_' . $nextTeamId . '_' . $next['round'] . '_' . $next['pick_position'],
                    ]);
                }
            }

            echo json_encode(['success' => true, 'autopicked' => true, 'player_name' => $playerToPick['name']]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']);
        }
        break;

    // ── GET: todos os mocks do draft (admin) ─────────
    case 'admin_all_mocks':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Apenas administradores']); exit; }
        $draftSessionId = (int)($_GET['draft_session_id'] ?? 0);
        if (!$draftSessionId) { echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']); exit; }

        $stmtLeague = $pdo->prepare('SELECT league FROM draft_sessions WHERE id = ?');
        $stmtLeague->execute([$draftSessionId]);
        $sessionLeague = $stmtLeague->fetchColumn();

        $stmtTeams = $pdo->prepare('
            SELECT t.id, TRIM(CONCAT(t.city," ",t.name)) AS team_name,
                   COALESCE(ms.is_active, 0) AS is_active
            FROM teams t
            LEFT JOIN draft_mock_settings ms ON ms.team_id = t.id AND ms.draft_session_id = ?
            WHERE t.league = ?
            ORDER BY t.name ASC
        ');
        $stmtTeams->execute([$draftSessionId, $sessionLeague]);
        $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($teams as $team) {
            $stmtQ = $pdo->prepare('
                SELECT mq.priority, dp.name AS player_name, dp.position, dp.ovr, dp.draft_status
                FROM draft_mock_queue mq
                JOIN draft_pool dp ON mq.player_id = dp.id
                WHERE mq.team_id = ? AND mq.draft_session_id = ?
                ORDER BY mq.priority ASC
            ');
            $stmtQ->execute([$team['id'], $draftSessionId]);
            $queue = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($queue) || $team['is_active']) {
                $result[] = [
                    'team_id'   => (int)$team['id'],
                    'team_name' => $team['team_name'],
                    'is_active' => (bool)$team['is_active'],
                    'queue'     => $queue,
                ];
            }
        }
        echo json_encode(['success' => true, 'mocks' => $result]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}
