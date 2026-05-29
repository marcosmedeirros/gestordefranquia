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

$pdo      = db();
$pdoGames = dbGames();
$method   = $_SERVER['REQUEST_METHOD'];
$action   = $_GET['action'] ?? '';
$isAdmin  = ($user['user_type'] ?? 'jogador') === 'admin';

function out(array $data): void { echo json_encode($data); exit; }
function err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function ensureTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tapas_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_id INT NOT NULL,
        player_id INT NOT NULL,
        player_name VARCHAR(255) NOT NULL,
        league VARCHAR(20) NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        action_type ENUM('tapa','badge') NULL,
        badge_name VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        INDEX idx_tr_team (team_id),
        INDEX idx_tr_player (player_id),
        INDEX idx_tr_status (status),
        INDEX idx_tr_league (league)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $cols = $pdo->query("SHOW COLUMNS FROM players")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tapa_count', $cols)) {
        $pdo->exec("ALTER TABLE players ADD COLUMN tapa_count INT NOT NULL DEFAULT 0");
    }
    if (!in_array('badge_name', $cols)) {
        $pdo->exec("ALTER TABLE players ADD COLUMN badge_name VARCHAR(255) NULL");
    }
}

ensureTables($pdo);

// ── helpers games DB ──────────────────────────────────────────────────────────

function getGamesUserId(PDO $pdo, int $userId): ?int {
    $stmt = $pdo->prepare("SELECT games_user_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $val = $stmt->fetchColumn();
    return ($val !== false && $val !== null) ? (int)$val : null;
}

function getGamesTapas(?PDO $pdoGames, ?int $gamesUserId): int {
    if (!$pdoGames || !$gamesUserId) return 0;
    $stmt = $pdoGames->prepare("SELECT COALESCE(numero_tapas,0) FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([$gamesUserId]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (int)$val : 0;
}

function decrementGamesTapas(?PDO $pdoGames, ?int $gamesUserId): void {
    if (!$pdoGames || !$gamesUserId) return;
    $pdoGames->prepare("UPDATE usuarios SET numero_tapas = GREATEST(COALESCE(numero_tapas,0)-1,0) WHERE id = ?")
        ->execute([$gamesUserId]);
}

// ── GET my_status ─────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'my_status') {
    $stmt = $pdo->prepare("SELECT t.id, t.tapas_used FROM teams t WHERE t.user_id = ? LIMIT 1");
    $stmt->execute([$user['id']]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$team) out(['tapas' => 0, 'tapas_used' => 0, 'pending' => []]);

    $gamesUserId = getGamesUserId($pdo, $user['id']);
    $tapas       = getGamesTapas($pdoGames, $gamesUserId);

    $pend = $pdo->prepare("SELECT r.*, p.tapa_count, p.badge_name AS player_badge FROM tapas_requests r
        LEFT JOIN players p ON p.id = r.player_id
        WHERE r.team_id = ? ORDER BY r.created_at DESC");
    $pend->execute([$team['id']]);
    $requests = $pend->fetchAll(PDO::FETCH_ASSOC);

    out(['success' => true, 'tapas' => $tapas, 'tapas_used' => (int)$team['tapas_used'], 'requests' => $requests]);
}

// ── GET league_recipients ─────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'league_recipients') {
    $league = $_GET['league'] ?? $user['league'] ?? 'ELITE';
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.position, p.ovr, p.tapa_count, p.badge_name,
               t.city, t.name AS team_name
        FROM players p
        INNER JOIN teams t ON t.id = p.team_id
        WHERE t.league = ?
          AND (p.tapa_count > 0 OR p.badge_name IS NOT NULL)
        ORDER BY p.tapa_count DESC, p.name ASC
    ");
    $stmt->execute([$league]);
    out(['success' => true, 'players' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── POST request_tapa ─────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'request_tapa') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $playerId = (int)($body['player_id'] ?? 0);
    if (!$playerId) err('Jogador inválido');

    $stmtT = $pdo->prepare("SELECT id, league FROM teams WHERE user_id = ? LIMIT 1");
    $stmtT->execute([$user['id']]);
    $team = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$team) err('Time não encontrado');

    // check balance in games DB
    $gamesUserId = getGamesUserId($pdo, $user['id']);
    $tapas       = getGamesTapas($pdoGames, $gamesUserId);
    if ($tapas <= 0) err('Sem tapas disponíveis');

    $stmtP = $pdo->prepare("SELECT id, name FROM players WHERE id = ? AND team_id = ?");
    $stmtP->execute([$playerId, $team['id']]);
    $player = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!$player) err('Jogador não encontrado no seu elenco');

    $stmtEx = $pdo->prepare("SELECT id FROM tapas_requests WHERE team_id = ? AND player_id = ? AND status = 'pending'");
    $stmtEx->execute([$team['id'], $playerId]);
    if ($stmtEx->fetch()) err('Já existe uma solicitação pendente para este jogador');

    $actionType = $body['action_type'] ?? 'tapa';
    $badgeName  = trim($body['badge_name'] ?? '');
    if (!in_array($actionType, ['tapa', 'badge'])) err('Tipo de ação inválido');
    if ($actionType === 'badge' && $badgeName === '') err('Nome do badge obrigatório');

    $ins = $pdo->prepare("INSERT INTO tapas_requests (team_id, player_id, player_name, league, status, action_type, badge_name)
        VALUES (?, ?, ?, ?, 'pending', ?, ?)");
    $ins->execute([$team['id'], $playerId, $player['name'], $team['league'], $actionType, $actionType === 'badge' ? $badgeName : null]);

    out(['success' => true, 'message' => 'Solicitação enviada com sucesso']);
}

// ── GET admin_get_all ─────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'admin_get_all') {
    if (!$isAdmin) err('Acesso negado', 403);
    $league = $_GET['league'] ?? 'ELITE';

    $stmtTeams = $pdo->prepare("
        SELECT t.id, t.city, t.name, t.tapas_used,
               u.name AS owner_name, u.games_user_id
        FROM teams t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.league = ?
        ORDER BY t.city, t.name
    ");
    $stmtTeams->execute([$league]);
    $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

    // bulk fetch tapas from games DB
    $gamesIds = array_values(array_filter(array_map(fn($t) => (int)($t['games_user_id'] ?? 0), $teams)));
    $gamesTapasMap = [];
    if ($pdoGames && !empty($gamesIds)) {
        $ph = implode(',', array_fill(0, count($gamesIds), '?'));
        $stmtG = $pdoGames->prepare("SELECT id, COALESCE(numero_tapas,0) AS numero_tapas FROM usuarios WHERE id IN ($ph)");
        $stmtG->execute($gamesIds);
        foreach ($stmtG->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $gamesTapasMap[(int)$row['id']] = (int)$row['numero_tapas'];
        }
    }

    foreach ($teams as &$team) {
        $gid = (int)($team['games_user_id'] ?? 0);
        $team['tapas'] = $gid ? ($gamesTapasMap[$gid] ?? 0) : 0;
        unset($team['games_user_id']);
    }
    unset($team);

    $stmtReq = $pdo->prepare("
        SELECT r.*, t.city AS team_city, t.name AS team_name,
               u.name AS owner_name,
               p.ovr AS player_ovr, p.position AS player_position
        FROM tapas_requests r
        INNER JOIN teams t ON t.id = r.team_id
        LEFT JOIN users u ON u.id = t.user_id
        INNER JOIN players p ON p.id = r.player_id
        WHERE r.league = ? AND r.status = 'pending'
        ORDER BY r.created_at ASC
    ");
    $stmtReq->execute([$league]);
    $requests = $stmtReq->fetchAll(PDO::FETCH_ASSOC);

    out(['success' => true, 'teams' => $teams, 'requests' => $requests]);
}

// ── POST admin_set_tapas ──────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'admin_set_tapas') {
    if (!$isAdmin) err('Acesso negado', 403);
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $teamId    = (int)($body['team_id'] ?? 0);
    $amount    = (int)($body['amount'] ?? 0);
    $operation = $body['operation'] ?? 'set';
    if (!$teamId) err('Time inválido');

    // resolve games_user_id for this team
    $rowU = $pdo->prepare("SELECT u.games_user_id FROM teams t JOIN users u ON u.id = t.user_id WHERE t.id = ? LIMIT 1");
    $rowU->execute([$teamId]);
    $gamesUserId = (int)($rowU->fetchColumn() ?? 0) ?: null;

    if ($pdoGames && $gamesUserId) {
        if ($operation === 'add') {
            $pdoGames->prepare("UPDATE usuarios SET numero_tapas = GREATEST(0, COALESCE(numero_tapas,0) + ?) WHERE id = ?")
                ->execute([$amount, $gamesUserId]);
        } elseif ($operation === 'remove') {
            $pdoGames->prepare("UPDATE usuarios SET numero_tapas = GREATEST(0, COALESCE(numero_tapas,0) - ?) WHERE id = ?")
                ->execute([$amount, $gamesUserId]);
        } else {
            $pdoGames->prepare("UPDATE usuarios SET numero_tapas = ? WHERE id = ?")
                ->execute([$amount, $gamesUserId]);
        }
        $newVal = getGamesTapas($pdoGames, $gamesUserId);
    } else {
        // fallback: update teams.tapas if games DB unavailable
        if ($operation === 'add') {
            $pdo->prepare("UPDATE teams SET tapas = tapas + ? WHERE id = ?")->execute([$amount, $teamId]);
        } elseif ($operation === 'remove') {
            $pdo->prepare("UPDATE teams SET tapas = GREATEST(0, tapas - ?) WHERE id = ?")->execute([$amount, $teamId]);
        } else {
            $pdo->prepare("UPDATE teams SET tapas = ? WHERE id = ?")->execute([$amount, $teamId]);
        }
        $stmtNew = $pdo->prepare("SELECT tapas FROM teams WHERE id = ?");
        $stmtNew->execute([$teamId]);
        $newVal = (int)$stmtNew->fetchColumn();
    }

    out(['success' => true, 'new_tapas' => $newVal]);
}

// ── POST admin_approve ────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'admin_approve') {
    if (!$isAdmin) err('Acesso negado', 403);
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $requestId = (int)($body['request_id'] ?? 0);
    if (!$requestId) err('Solicitação inválida');

    $stmtR = $pdo->prepare("SELECT * FROM tapas_requests WHERE id = ? AND status = 'pending'");
    $stmtR->execute([$requestId]);
    $req = $stmtR->fetch(PDO::FETCH_ASSOC);
    if (!$req) err('Solicitação não encontrada ou já processada');

    $actionType = $req['action_type'] ?? 'tapa';
    $badgeName  = $req['badge_name'] ?? '';

    // resolve games_user_id for the team owner
    $rowU = $pdo->prepare("SELECT u.games_user_id FROM teams t JOIN users u ON u.id = t.user_id WHERE t.id = ? LIMIT 1");
    $rowU->execute([$req['team_id']]);
    $gamesUserId = (int)($rowU->fetchColumn() ?? 0) ?: null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE tapas_requests SET status='approved', processed_at=NOW() WHERE id=?")
            ->execute([$requestId]);

        if ($actionType === 'tapa') {
            $pdo->prepare("UPDATE players SET tapa_count = tapa_count + 1 WHERE id = ?")
                ->execute([$req['player_id']]);
            // deduct from games DB
            decrementGamesTapas($pdoGames, $gamesUserId);
        } else {
            $pdo->prepare("UPDATE players SET badge_name = ? WHERE id = ?")
                ->execute([$badgeName, $req['player_id']]);
        }

        $pdo->prepare("UPDATE teams SET tapas_used = tapas_used + 1 WHERE id = ?")
            ->execute([$req['team_id']]);

        $pdo->commit();
        out(['success' => true, 'message' => 'Solicitação aprovada']);
    } catch (Exception $e) {
        $pdo->rollBack();
        err('Erro ao processar: ' . $e->getMessage());
    }
}

// ── POST admin_reject ─────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'admin_reject') {
    if (!$isAdmin) err('Acesso negado', 403);
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $requestId = (int)($body['request_id'] ?? 0);
    if (!$requestId) err('Solicitação inválida');

    $pdo->prepare("UPDATE tapas_requests SET status='rejected', processed_at=NOW() WHERE id = ? AND status='pending'")
        ->execute([$requestId]);

    out(['success' => true, 'message' => 'Solicitação rejeitada']);
}

err('Ação inválida');
