<?php
/**
 * API de Draft
 * Gerencia sessões de draft, ordem de picks e seleções
 */

require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';

header('Content-Type: application/json');

try {
    requireAuth();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$user = getUserSession();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

$isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';

// Função utilitária: compacta posições (1..N) em todas as rodadas, mantendo ordem atual
function recalculateOrderPositions(PDO $pdo, int $draftSessionId): void {
    $stmtRounds = $pdo->prepare('SELECT total_rounds FROM draft_sessions WHERE id = ?');
    $stmtRounds->execute([$draftSessionId]);
    $totalRounds = (int)($stmtRounds->fetchColumn() ?: 0);
    if ($totalRounds < 1) return;

    for ($round = 1; $round <= $totalRounds; $round++) {
        $stmt = $pdo->prepare('SELECT id FROM draft_order WHERE draft_session_id = ? AND round = ? ORDER BY pick_position ASC, id ASC');
        $stmt->execute([$draftSessionId, $round]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pos = 1;
        foreach ($rows as $row) {
            $pdo->prepare('UPDATE draft_order SET pick_position = ? WHERE id = ?')->execute([$pos, $row['id']]);
            $pos++;
        }
    }
}

// ========== GET ==========
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'active_draft';

    switch ($action) {
        // Buscar draft ativo da liga
        case 'active_draft':
            $league = $_GET['league'] ?? ($team['league'] ?? null);
            if (!$league) {
                echo json_encode(['success' => false, 'error' => 'Liga não especificada']);
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT ds.*, s.season_number, s.year
                 FROM draft_sessions ds
                 INNER JOIN seasons s ON ds.season_id = s.id
                 WHERE ds.league = ? AND ds.status IN ('setup', 'in_progress')
                 ORDER BY ds.created_at DESC LIMIT 1"
            );
            $stmt->execute([$league]);
            $draft = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'draft' => $draft]);
            break;

        // Buscar ordem de draft e status das picks
        case 'draft_order':
            $draftSessionId = $_GET['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT do.*, 
                        t.city as team_city, t.name as team_name, t.photo_url as team_photo,
                        ot.city as original_city, ot.name as original_name,
                        tf.city as traded_from_city, tf.name as traded_from_name,
                        dp.name as player_name, dp.position as player_position, dp.ovr as player_ovr
                 FROM draft_order do
                 INNER JOIN teams t ON do.team_id = t.id
                 INNER JOIN teams ot ON do.original_team_id = ot.id
                 LEFT JOIN teams tf ON do.traded_from_team_id = tf.id
                 LEFT JOIN draft_pool dp ON do.picked_player_id = dp.id
                 WHERE do.draft_session_id = ?
                 ORDER BY do.round ASC, do.pick_position ASC"
            );
            $stmt->execute([$draftSessionId]);
            $order = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmtSession = $pdo->prepare(
                "SELECT ds.*, s.season_number, s.year
                 FROM draft_sessions ds
                 INNER JOIN seasons s ON ds.season_id = s.id
                 WHERE ds.id = ?"
            );
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'session' => $session,
                'order' => $order
            ]);
            break;

        // Buscar jogadores disponíveis para draft
        case 'available_players':
            $seasonId = $_GET['season_id'] ?? null;
            if (!$seasonId) {
                echo json_encode(['success' => false, 'error' => 'season_id obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT * FROM draft_pool 
                 WHERE season_id = ? AND draft_status = 'available'
                 ORDER BY ovr DESC, name ASC"
            );
            $stmt->execute([$seasonId]);
            $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'players' => $players]);
            break;

        // Verificar se é a vez do time
        case 'my_turn':
            $draftSessionId = $_GET['draft_session_id'] ?? null;
            if (!$draftSessionId || !$team) {
                echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "in_progress"');
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                echo json_encode(['success' => true, 'is_my_turn' => false, 'reason' => 'Draft não está em andamento']);
                exit;
            }

            $stmtPick = $pdo->prepare(
                "SELECT do.*, t.city, t.name
                 FROM draft_order do
                 INNER JOIN teams t ON do.team_id = t.id
                 WHERE do.draft_session_id = ?
                   AND do.round = ?
                   AND do.pick_position = ?
                   AND do.picked_player_id IS NULL"
            );
            $stmtPick->execute([$draftSessionId, $session['current_round'], $session['current_pick']]);
            $currentPick = $stmtPick->fetch(PDO::FETCH_ASSOC);

            $isMyTurn = $currentPick && (int)$currentPick['team_id'] === (int)$team['id'];

            echo json_encode([
                'success' => true,
                'is_my_turn' => $isMyTurn,
                'current_pick' => $currentPick,
                'session' => $session
            ]);
            break;

        // Buscar histórico de draft de uma temporada
        case 'draft_history':
            $seasonId = $_GET['season_id'] ?? null;
            $league = $_GET['league'] ?? ($team['league'] ?? null);
            if (!$seasonId && !$league) {
                echo json_encode(['success' => false, 'error' => 'season_id ou league obrigatório']);
                exit;
            }

            if ($seasonId) {
                $stmt = $pdo->prepare(
                    "SELECT s.*, ds.status as draft_status, ds.id as draft_session_id
                     FROM seasons s
                     LEFT JOIN draft_sessions ds ON ds.season_id = s.id
                     WHERE s.id = ?"
                );
                $stmt->execute([$seasonId]);
                $season = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$season) {
                    echo json_encode(['success' => false, 'error' => 'Temporada não encontrada']);
                    exit;
                }

                if (!empty($season['draft_order_snapshot'])) {
                    $snapshot = json_decode($season['draft_order_snapshot'], true);
                    echo json_encode([
                        'success' => true,
                        'season' => $season,
                        'draft_order' => $snapshot,
                        'from_snapshot' => true
                    ]);
                    exit;
                }

                $stmtSession = $pdo->prepare('SELECT id FROM draft_sessions WHERE season_id = ?');
                $stmtSession->execute([$seasonId]);
                $sessionData = $stmtSession->fetch();

                if ($sessionData) {
                    $stmtOrder = $pdo->prepare(
                        "SELECT do.*, 
                                t.city as team_city, t.name as team_name, t.photo_url as team_photo,
                                ot.city as original_city, ot.name as original_name,
                                tf.city as traded_from_city, tf.name as traded_from_name,
                                dp.name as player_name, dp.position as player_position, dp.ovr as player_ovr
                         FROM draft_order do
                         INNER JOIN teams t ON do.team_id = t.id
                         INNER JOIN teams ot ON do.original_team_id = ot.id
                         LEFT JOIN teams tf ON do.traded_from_team_id = tf.id
                         LEFT JOIN draft_pool dp ON do.picked_player_id = dp.id
                         WHERE do.draft_session_id = ?
                         ORDER BY do.round ASC, do.pick_position ASC"
                    );
                    $stmtOrder->execute([$sessionData['id']]);
                    $order = $stmtOrder->fetchAll(PDO::FETCH_ASSOC);

                    echo json_encode([
                        'success' => true,
                        'season' => $season,
                        'draft_order' => $order,
                        'draft_session_id' => $sessionData['id'],
                        'from_snapshot' => false
                    ]);
                    exit;
                }

                echo json_encode(['success' => true, 'season' => $season, 'draft_order' => [], 'from_snapshot' => false]);
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT s.id, s.season_number, s.year, s.league, s.status,
                        CASE WHEN s.draft_order_snapshot IS NOT NULL THEN 1 ELSE 0 END as has_snapshot,
                        ds.status as draft_status, ds.id as draft_session_id
                 FROM seasons s
                 LEFT JOIN draft_sessions ds ON ds.season_id = s.id
                 WHERE s.league = ?
                 ORDER BY s.year DESC, s.season_number DESC"
            );
            $stmt->execute([$league]);
            $seasons = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'seasons' => $seasons]);
            break;

        // Jogadores disponíveis para preencher draft passado
        case 'available_players_for_past_draft':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $_GET['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT season_id FROM draft_sessions WHERE id = ?');
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão de draft não encontrada']);
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT * FROM draft_pool 
                 WHERE season_id = ? AND draft_status = 'available'
                 ORDER BY ovr DESC, name ASC"
            );
            $stmt->execute([$session['season_id']]);
            $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'players' => $players]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

// ========== POST ==========
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    switch ($action) {
        // ADMIN: Criar sessão de draft
        case 'create_session':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $seasonId = $data['season_id'] ?? null;
            if (!$seasonId) {
                echo json_encode(['success' => false, 'error' => 'season_id obrigatório']);
                exit;
            }

            $stmtSeason = $pdo->prepare('SELECT league FROM seasons WHERE id = ?');
            $stmtSeason->execute([$seasonId]);
            $seasonData = $stmtSeason->fetch();
            if (!$seasonData) {
                echo json_encode(['success' => false, 'error' => 'Temporada não encontrada']);
                exit;
            }

            $league = $seasonData['league'];

            $stmtCheck = $pdo->prepare('SELECT id FROM draft_sessions WHERE season_id = ?');
            $stmtCheck->execute([$seasonId]);
            if ($stmtCheck->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Já existe uma sessão de draft para esta temporada']);
                exit;
            }

            $stmt = $pdo->prepare('INSERT INTO draft_sessions (season_id, league, total_rounds) VALUES (?, ?, 2)');
            $stmt->execute([$seasonId, $league]);
            $draftSessionId = $pdo->lastInsertId();

            echo json_encode(['success' => true, 'draft_session_id' => $draftSessionId]);
            break;

        // ADMIN: Adicionar time à ordem de draft (sem "via", permite repetição)
        case 'add_to_order':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            $teamId = $data['team_id'] ?? null;
            if (!$draftSessionId || !$teamId) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "setup"');
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou já iniciada']);
                exit;
            }

            $stmtCount = $pdo->prepare('SELECT COALESCE(MAX(pick_position), 0) as max_pos FROM draft_order WHERE draft_session_id = ? AND round = 1');
            $stmtCount->execute([$draftSessionId]);
            $maxPos = (int)($stmtCount->fetch()['max_pos'] ?? 0);
            $newPos = $maxPos + 1;

            try {
                $pdo->beginTransaction();
                for ($round = 1; $round <= (int)$session['total_rounds']; $round++) {
                    $pdo->prepare(
                        'INSERT INTO draft_order (draft_session_id, team_id, original_team_id, pick_position, round, traded_from_team_id)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    )->execute([
                        (int)$draftSessionId,
                        (int)$teamId,
                        (int)$teamId,
                        (int)$newPos,
                        (int)$round,
                        null
                    ]);
                }
                $pdo->commit();

                recalculateOrderPositions($pdo, (int)$draftSessionId);

                echo json_encode(['success' => true, 'message' => 'Time adicionado']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro: ' . $e->getMessage()]);
            }
            break;

        // ADMIN: Remover time da ordem (por posição em todas as rodadas)
        case 'remove_from_order':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $pickId = $data['pick_id'] ?? null;
            $draftSessionId = $data['draft_session_id'] ?? null;
            if (!$pickId || !$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtPick = $pdo->prepare('SELECT pick_position FROM draft_order WHERE id = ?');
            $stmtPick->execute([$pickId]);
            $pick = $stmtPick->fetch();
            if (!$pick) {
                echo json_encode(['success' => false, 'error' => 'Pick não encontrada']);
                exit;
            }

            $pdo->prepare('DELETE FROM draft_order WHERE draft_session_id = ? AND pick_position = ?')->execute([(int)$draftSessionId, (int)$pick['pick_position']]);

            recalculateOrderPositions($pdo, (int)$draftSessionId);

            echo json_encode(['success' => true, 'message' => 'Time removido']);
            break;

        // ADMIN: Limpar ordem
        case 'clear_order':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }

            $pdo->prepare('DELETE FROM draft_order WHERE draft_session_id = ?')->execute([(int)$draftSessionId]);
            echo json_encode(['success' => true, 'message' => 'Ordem limpa']);
            break;

        // ADMIN: Excluir sessão de draft
        case 'delete_session':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }

            try {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM draft_order WHERE draft_session_id = ?')->execute([(int)$draftSessionId]);
                $pdo->prepare('DELETE FROM draft_sessions WHERE id = ?')->execute([(int)$draftSessionId]);
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Sessão excluída']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro: ' . $e->getMessage()]);
            }
            break;

        // ADMIN: Definir ordem completa (sem "via", permite repetição, sem snake)
        case 'set_draft_order':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            $teamOrder = $data['team_order'] ?? [];
            if (!$draftSessionId || empty($teamOrder) || !is_array($teamOrder)) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "setup"');
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou já iniciada']);
                exit;
            }

            try {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM draft_order WHERE draft_session_id = ?')->execute([(int)$draftSessionId]);

                for ($round = 1; $round <= (int)$session['total_rounds']; $round++) {
                    foreach (array_values($teamOrder) as $position => $teamIdInOrder) {
                        $pdo->prepare(
                            'INSERT INTO draft_order (draft_session_id, team_id, original_team_id, pick_position, round, traded_from_team_id)
                             VALUES (?, ?, ?, ?, ?, ?)'
                        )->execute([
                            (int)$draftSessionId,
                            (int)$teamIdInOrder,
                            (int)$teamIdInOrder,
                            (int)($position + 1),
                            (int)$round,
                            null
                        ]);
                    }
                }

                $pdo->commit();
                recalculateOrderPositions($pdo, (int)$draftSessionId);
                echo json_encode(['success' => true, 'message' => 'Ordem definida com sucesso']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro ao definir ordem: ' . $e->getMessage()]);
            }
            break;

        // ADMIN: Iniciar draft
        case 'start_draft':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "setup"');
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou já iniciada']);
                exit;
            }

            $stmtOrder = $pdo->prepare('SELECT COUNT(*) as total FROM draft_order WHERE draft_session_id = ?');
            $stmtOrder->execute([$draftSessionId]);
            $orderCount = (int)($stmtOrder->fetch()['total'] ?? 0);
            if ($orderCount === 0) {
                echo json_encode(['success' => false, 'error' => 'Defina a ordem do draft antes de iniciar']);
                exit;
            }

            $pdo->prepare('UPDATE draft_sessions SET status = "in_progress", started_at = NOW() WHERE id = ?')->execute([(int)$draftSessionId]);
            echo json_encode(['success' => true, 'message' => 'Draft iniciado!']);
            break;

        // ADMIN: Finalizar draft manualmente
        case 'finalize_draft':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ?');
            $stmtSession->execute([(int)$draftSessionId]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada']);
                exit;
            }

            $pdo->prepare('UPDATE draft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')
                ->execute([(int)$draftSessionId]);

            echo json_encode(['success' => true, 'message' => 'Draft finalizado!']);
            break;

        // ADMIN: Adicionar jogador ao draft pool
        case 'add_draft_player':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            $name = trim((string)($data['name'] ?? ''));
            $position = strtoupper(trim((string)($data['position'] ?? '')));
            $age = (int)($data['age'] ?? 0);
            $ovr = (int)($data['ovr'] ?? 0);

            if (!$draftSessionId || $name === '' || $position === '' || $age <= 0 || $ovr <= 0) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ?');
            $stmtSession->execute([(int)$draftSessionId]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada']);
                exit;
            }

            $stmt = $pdo->prepare('INSERT INTO draft_pool (season_id, name, position, age, ovr, draft_status) VALUES (?, ?, ?, ?, ?, "available")');
            $stmt->execute([
                (int)$session['season_id'],
                $name,
                $position,
                $age,
                $ovr
            ]);

            echo json_encode(['success' => true, 'message' => 'Jogador adicionado ao draft!']);
            break;

        // JOGADOR/ADMIN: Fazer pick
        case 'make_pick':
            $draftSessionId = $data['draft_session_id'] ?? null;
            $playerId = $data['player_id'] ?? null;
            $teamIdOverride = $data['team_id'] ?? null; // Admin pode definir outro time
            if (!$draftSessionId || !$playerId) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "in_progress"');
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Draft não está em andamento']);
                exit;
            }

            $stmtPick = $pdo->prepare('SELECT * FROM draft_order WHERE draft_session_id = ? AND round = ? AND pick_position = ? AND picked_player_id IS NULL');
            $stmtPick->execute([(int)$draftSessionId, (int)$session['current_round'], (int)$session['current_pick']]);
            $currentPick = $stmtPick->fetch();
            if (!$currentPick) {
                echo json_encode(['success' => false, 'error' => 'Nenhuma pick pendente']);
                exit;
            }

            $pickingTeamId = $isAdmin && $teamIdOverride ? (int)$teamIdOverride : (int)$team['id'];
            if (!$isAdmin && (int)$currentPick['team_id'] !== $pickingTeamId) {
                echo json_encode(['success' => false, 'error' => 'Não é a sua vez de escolher']);
                exit;
            }

            $stmtPlayer = $pdo->prepare('SELECT * FROM draft_pool WHERE id = ? AND draft_status = "available"');
            $stmtPlayer->execute([(int)$playerId]);
            $player = $stmtPlayer->fetch();
            if (!$player) {
                echo json_encode(['success' => false, 'error' => 'Jogador não disponível']);
                exit;
            }

            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE draft_order SET picked_player_id = ?, picked_at = NOW() WHERE id = ?')->execute([(int)$playerId, (int)$currentPick['id']]);

                $stmtTotalRound = $pdo->prepare('SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ? AND round = ?');
                $stmtTotalRound->execute([(int)$draftSessionId, (int)$session['current_round']]);
                $roundSize = (int)$stmtTotalRound->fetchColumn();
                $pickNumber = (($session['current_round'] - 1) * $roundSize) + $session['current_pick'];

                $pdo->prepare('UPDATE draft_pool SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ? WHERE id = ?')
                    ->execute([(int)$currentPick['team_id'], (int)$pickNumber, (int)$playerId]);

                $pdo->prepare('INSERT INTO players (team_id, name, position, age, ovr, role, available_for_trade) VALUES (?, ?, ?, ?, ?, "Banco", 0)')
                    ->execute([(int)$currentPick['team_id'], $player['name'], $player['position'], (int)$player['age'], (int)$player['ovr']]);

                $nextPick = (int)$session['current_pick'] + 1;
                $nextRound = (int)$session['current_round'];

                $stmtCount = $pdo->prepare('SELECT COUNT(*) as total FROM draft_order WHERE draft_session_id = ? AND round = ?');
                $stmtCount->execute([(int)$draftSessionId, (int)$nextRound]);
                $totalPicks = (int)($stmtCount->fetch()['total'] ?? 0);

                if ($nextPick > $totalPicks) {
                    $nextRound++;
                    $nextPick = 1;
                    if ($nextRound > (int)$session['total_rounds']) {
                        $pdo->prepare('UPDATE draft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')->execute([(int)$draftSessionId]);
                    } else {
                        $pdo->prepare('UPDATE draft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')->execute([(int)$nextRound, (int)$nextPick, (int)$draftSessionId]);
                    }
                } else {
                    $pdo->prepare('UPDATE draft_sessions SET current_pick = ? WHERE id = ?')->execute([(int)$nextPick, (int)$draftSessionId]);
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Pick realizada!', 'player' => $player]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro ao fazer pick: ' . $e->getMessage()]);
            }
            break;

        // ADMIN: Preencher pick de draft passado/completado
        case 'fill_past_pick':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $pickId = $data['pick_id'] ?? null;
            $playerId = $data['player_id'] ?? null;
            $draftSessionId = $data['draft_session_id'] ?? null;
            if (!$pickId || !$playerId || !$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                $stmtPick = $pdo->prepare('SELECT * FROM draft_order WHERE id = ?');
                $stmtPick->execute([(int)$pickId]);
                $pick = $stmtPick->fetch();
                if (!$pick) {
                    throw new Exception('Pick não encontrada');
                }

                $stmtPlayer = $pdo->prepare('SELECT * FROM draft_pool WHERE id = ? AND draft_status = "available"');
                $stmtPlayer->execute([(int)$playerId]);
                $player = $stmtPlayer->fetch();
                if (!$player) {
                    throw new Exception('Jogador não disponível no draft pool');
                }

                $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ?');
                $stmtSession->execute([(int)$draftSessionId]);
                $session = $stmtSession->fetch();
                if (!$session) {
                    throw new Exception('Sessão de draft não encontrada');
                }

                $pdo->prepare('UPDATE draft_order SET picked_player_id = ?, picked_at = NOW() WHERE id = ?')->execute([(int)$playerId, (int)$pickId]);

                $stmtTotalRound = $pdo->prepare('SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ? AND round = ?');
                $stmtTotalRound->execute([(int)$draftSessionId, (int)$pick['round']]);
                $roundSize = (int)$stmtTotalRound->fetchColumn();
                $pickNumber = (($pick['round'] - 1) * $roundSize) + $pick['pick_position'];

                $pdo->prepare('UPDATE draft_pool SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ? WHERE id = ?')
                    ->execute([(int)$pick['team_id'], (int)$pickNumber, (int)$playerId]);

                $pdo->prepare('INSERT INTO players (team_id, name, position, age, ovr, role, available_for_trade) VALUES (?, ?, ?, ?, ?, "Banco", 0)')
                    ->execute([(int)$pick['team_id'], $player['name'], $player['position'], (int)$player['age'], (int)$player['ovr']]);

                if (($session['status'] ?? '') === 'in_progress'
                    && (int)$pick['round'] === (int)$session['current_round']
                    && (int)$pick['pick_position'] === (int)$session['current_pick']) {

                    $nextPick = (int)$session['current_pick'] + 1;
                    $nextRound = (int)$session['current_round'];

                    $stmtCount = $pdo->prepare('SELECT COUNT(*) as total FROM draft_order WHERE draft_session_id = ? AND round = ?');
                    $stmtCount->execute([(int)$draftSessionId, (int)$nextRound]);
                    $totalPicks = (int)($stmtCount->fetch()['total'] ?? 0);

                    if ($nextPick > $totalPicks) {
                        $nextRound++;
                        $nextPick = 1;
                        if ($nextRound > (int)$session['total_rounds']) {
                            $pdo->prepare('UPDATE draft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')->execute([(int)$draftSessionId]);
                        } else {
                            $pdo->prepare('UPDATE draft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')
                                ->execute([(int)$nextRound, (int)$nextPick, (int)$draftSessionId]);
                        }
                    } else {
                        $pdo->prepare('UPDATE draft_sessions SET current_pick = ? WHERE id = ?')
                            ->execute([(int)$nextPick, (int)$draftSessionId]);
                    }
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Pick preenchida!', 'player' => $player]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        // ADMIN: Resetar draft
        case 'reset_draft':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE draft_order SET picked_player_id = NULL, picked_at = NULL WHERE draft_session_id = ?')->execute([(int)$draftSessionId]);
                $pdo->prepare('UPDATE draft_sessions SET status = "setup", current_round = 1, current_pick = 1, started_at = NULL WHERE id = ?')->execute([(int)$draftSessionId]);

                $stmtSession = $pdo->prepare('SELECT season_id FROM draft_sessions WHERE id = ?');
                $stmtSession->execute([(int)$draftSessionId]);
                $session = $stmtSession->fetch();
                $pdo->prepare('UPDATE draft_pool SET draft_status = "available", drafted_by_team_id = NULL, draft_order = NULL WHERE season_id = ?')->execute([(int)$session['season_id']]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Draft resetado']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro ao resetar: ' . $e->getMessage()]);
            }
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);

?>
