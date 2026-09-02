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

try {
    $pdo->exec("ALTER TABLE draft_sessions ADD COLUMN round1_clock_start_at DATETIME NULL");
} catch (Exception $e) {} // já existe

// Admin global (user_type='admin') OU admin da liga via league_admins — mesmo critério
// usado em drafts.php (a página) e em api/leilao.php e api/market.php.
$isAdmin = hasAdminAccess($pdo, (int)$user['id']);

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

        /* A REGRA MORA EM backend/draft_autopick.php.

           Este bloco tinha a própria cópia dela, e a cópia checava o RELÓGIO
           ANTES de olhar a fila do time: quem tinha mock ativo e lista pronta
           esperava 5 ou 30 minutos por uma escolha que já estava decidida.
           Agora fila ativa escolhe na hora, e segue encadeando enquanto os
           próximos também tiverem lista. */
        require_once __DIR__ . '/../backend/draft_autopick.php';
        $feitas = draftAutopickSessao($pdo, $draftSessionId);

        echo json_encode([
            'success'     => true,
            'autopicked'  => count($feitas) > 0,
            'picks'       => $feitas,
            // A tela lê player_name pra anunciar quem saiu; com a cascata,
            // manda o último — é o que está na vez agora.
            'player_name' => $feitas ? end($feitas)['player'] : null,
        ]);
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
