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

            $stmt = $pdo->prepare("
                SELECT ds.*, s.season_number, s.year
                FROM draft_sessions ds
                INNER JOIN seasons s ON ds.season_id = s.id
                WHERE ds.league = ? AND ds.status IN ('setup', 'in_progress')
                ORDER BY ds.created_at DESC LIMIT 1
            ");
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

            // Buscar todas as picks ordenadas
            $stmt = $pdo->prepare("
                SELECT do.*,
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
                ORDER BY do.round ASC, do.pick_position ASC
            ");
            $stmt->execute([$draftSessionId]);
            $order = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Buscar info da sessão
            $stmtSession = $pdo->prepare("SELECT * FROM draft_sessions WHERE id = ?");
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

            $stmt = $pdo->prepare("
                SELECT * FROM draft_pool 
                WHERE season_id = ? AND draft_status = 'available'
                ORDER BY ovr DESC, name ASC
            ");
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

            // Buscar sessão atual
            $stmtSession = $pdo->prepare("SELECT * FROM draft_sessions WHERE id = ? AND status = 'in_progress'");
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                echo json_encode(['success' => true, 'is_my_turn' => false, 'reason' => 'Draft não está em andamento']);
                exit;
            }

            // Verificar pick atual
            $stmtPick = $pdo->prepare("
                SELECT do.*, t.city, t.name
                FROM draft_order do
                INNER JOIN teams t ON do.team_id = t.id
                WHERE do.draft_session_id = ? 
                  AND do.round = ? 
                  AND do.pick_position = ?
                  AND do.picked_player_id IS NULL
            ");
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

        // Buscar histórico de draft de uma temporada (snapshot salvo)
        case 'draft_history':
            $seasonId = $_GET['season_id'] ?? null;
            $league = $_GET['league'] ?? ($team['league'] ?? null);
            
            if (!$seasonId && !$league) {
                echo json_encode(['success' => false, 'error' => 'season_id ou league obrigatório']);
                exit;
            }
            
            // Se tem season_id, buscar snapshot dessa temporada específica
            if ($seasonId) {
                $stmt = $pdo->prepare("
                    SELECT s.*, ds.status as draft_status
                    FROM seasons s
                    LEFT JOIN draft_sessions ds ON ds.season_id = s.id
                    WHERE s.id = ?
                ");
                $stmt->execute([$seasonId]);
                $season = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$season) {
                    echo json_encode(['success' => false, 'error' => 'Temporada não encontrada']);
                    exit;
                }
                
                // Se tem snapshot salvo, usar ele
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
                
                // Senão, buscar da tabela draft_order (draft ainda ativo)
                $stmtSession = $pdo->prepare("SELECT id FROM draft_sessions WHERE season_id = ?");
                $stmtSession->execute([$seasonId]);
                $sessionData = $stmtSession->fetch();
                
                if ($sessionData) {
                    $stmtOrder = $pdo->prepare("
                        SELECT do.*,
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
                        ORDER BY do.round ASC, do.pick_position ASC
                    ");
                    $stmtOrder->execute([$sessionData['id']]);
                    $order = $stmtOrder->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'success' => true,
                        'season' => $season,
                        'draft_order' => $order,
                        'from_snapshot' => false
                    ]);
                    exit;
                }
                
                echo json_encode(['success' => true, 'season' => $season, 'draft_order' => [], 'from_snapshot' => false]);
                exit;
            }
            
            // Se só tem league, listar todas temporadas que têm draft
            $stmt = $pdo->prepare("
                SELECT s.id, s.season_number, s.year, s.league, s.status,
                       CASE WHEN s.draft_order_snapshot IS NOT NULL THEN 1 ELSE 0 END as has_snapshot,
                       ds.status as draft_status
                FROM seasons s
                LEFT JOIN draft_sessions ds ON ds.season_id = s.id
                WHERE s.league = ?
                ORDER BY s.year DESC, s.season_number DESC
            ");
            $stmt->execute([$league]);
            $seasons = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'seasons' => $seasons]);
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

            // Buscar liga da temporada
            $stmtSeason = $pdo->prepare("SELECT league FROM seasons WHERE id = ?");
            $stmtSeason->execute([$seasonId]);
            $seasonData = $stmtSeason->fetch();
            
            if (!$seasonData) {
                echo json_encode(['success' => false, 'error' => 'Temporada não encontrada']);
                exit;
            }
            
            $league = $seasonData['league'];

            // Verificar se já existe
            $stmtCheck = $pdo->prepare("SELECT id FROM draft_sessions WHERE season_id = ?");
            $stmtCheck->execute([$seasonId]);
            if ($stmtCheck->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Já existe uma sessão de draft para esta temporada']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO draft_sessions (season_id, league, total_rounds) VALUES (?, ?, 2)");
            $stmt->execute([$seasonId, $league]);
            $draftSessionId = $pdo->lastInsertId();

            echo json_encode(['success' => true, 'draft_session_id' => $draftSessionId]);
            break;
        
        // ADMIN: Adicionar time à ordem de draft
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

            // Buscar sessão
            $stmtSession = $pdo->prepare("SELECT * FROM draft_sessions WHERE id = ? AND status = 'setup'");
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou já iniciada']);
                exit;
            }

            // Buscar ano da temporada
            $stmtYear = $pdo->prepare("SELECT year FROM seasons WHERE id = ?");
            $stmtYear->execute([$session['season_id']]);
            $yearData = $stmtYear->fetch();
            $draftYear = $yearData['year'] ?? date('Y');

            // Contar posição atual
            $stmtCount = $pdo->prepare("SELECT COALESCE(MAX(pick_position), 0) as max_pos FROM draft_order WHERE draft_session_id = ? AND round = 1");
            $stmtCount->execute([$draftSessionId]);
            $maxPos = (int)$stmtCount->fetch()['max_pos'];
            $newPos = $maxPos + 1;

            try {
                $pdo->beginTransaction();

                // Para cada rodada, adicionar o time
                for ($round = 1; $round <= $session['total_rounds']; $round++) {
                    // Verificar se a pick foi trocada
                    $stmtPick = $pdo->prepare("
                        SELECT team_id, last_owner_team_id 
                        FROM picks 
                        WHERE original_team_id = ? AND season_year = ? AND round = ?
                    ");
                    $stmtPick->execute([$teamId, $draftYear, $round]);
                    $pickData = $stmtPick->fetch();

                    if ($pickData) {
                        $currentOwnerId = $pickData['team_id'];
                        $tradedFromId = ($currentOwnerId != $teamId) ? $pickData['last_owner_team_id'] : null;
                    } else {
                        $currentOwnerId = $teamId;
                        $tradedFromId = null;
                    }

                    // Calcular posição na rodada (snake)
                    if ($round == 1) {
                        $roundPos = $newPos;
                    } else {
                        // Na rodada 2, a posição é invertida
                        // Primeiro precisamos saber quantos times teremos no total
                        $roundPos = $newPos; // Será ajustado quando o draft iniciar
                    }

                    $stmtInsert = $pdo->prepare("
                        INSERT INTO draft_order (draft_session_id, team_id, original_team_id, pick_position, round, traded_from_team_id)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmtInsert->execute([
                        $draftSessionId,
                        $currentOwnerId,
                        $teamId,
                        $roundPos,
                        $round,
                        $tradedFromId
                    ]);
                }

                $pdo->commit();

                // Recalcular ordem snake
                recalculateSnakeOrder($pdo, $draftSessionId);

                echo json_encode(['success' => true, 'message' => 'Time adicionado']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro: ' . $e->getMessage()]);
            }
            break;

        // ADMIN: Remover time da ordem
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

            // Buscar o original_team_id para remover de todas as rodadas
            $stmtPick = $pdo->prepare("SELECT original_team_id FROM draft_order WHERE id = ?");
            $stmtPick->execute([$pickId]);
            $pick = $stmtPick->fetch();

            if (!$pick) {
                echo json_encode(['success' => false, 'error' => 'Pick não encontrada']);
                exit;
            }

            // Remover todas as picks desse time
            $pdo->prepare("DELETE FROM draft_order WHERE draft_session_id = ? AND original_team_id = ?")
                ->execute([$draftSessionId, $pick['original_team_id']]);

            // Recalcular ordem
            recalculateSnakeOrder($pdo, $draftSessionId);

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

            $pdo->prepare("DELETE FROM draft_order WHERE draft_session_id = ?")->execute([$draftSessionId]);

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

                // Limpar ordem
                $pdo->prepare("DELETE FROM draft_order WHERE draft_session_id = ?")->execute([$draftSessionId]);

                // Excluir sessão
                $pdo->prepare("DELETE FROM draft_sessions WHERE id = ?")->execute([$draftSessionId]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Sessão excluída']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro: ' . $e->getMessage()]);
            }
            break;

        // ADMIN: Definir ordem de draft
        case 'set_draft_order':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            $teamOrder = $data['team_order'] ?? []; // Array de team_id na ordem desejada

            if (!$draftSessionId || empty($teamOrder)) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            // Buscar sessão
            $stmtSession = $pdo->prepare("SELECT * FROM draft_sessions WHERE id = ? AND status = 'setup'");
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou já iniciada']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                // Limpar ordem anterior
                $pdo->prepare("DELETE FROM draft_order WHERE draft_session_id = ?")->execute([$draftSessionId]);

                // Buscar picks da tabela picks para verificar trocas
                // Formato: original_team_id -> quem é o dono atual (team_id)
                $seasonYear = $session['season_id']; // ou buscar o ano da temporada
                
                // Buscar ano da temporada
                $stmtYear = $pdo->prepare("SELECT year FROM seasons WHERE id = ?");
                $stmtYear->execute([$session['season_id']]);
                $seasonData = $stmtYear->fetch();
                $draftYear = $seasonData['year'] ?? date('Y');

                // Para cada rodada
                $totalTeams = count($teamOrder);
                for ($round = 1; $round <= $session['total_rounds']; $round++) {
                    // Snake draft: rodada 1 = ordem normal, rodada 2 = ordem invertida
                    $orderForRound = ($round % 2 === 1) ? $teamOrder : array_reverse($teamOrder);
                    
                    foreach ($orderForRound as $position => $originalTeamId) {
                        $pickPosition = $position + 1;
                        
                        // Verificar se a pick foi trocada
                        $stmtPick = $pdo->prepare("
                            SELECT team_id, last_owner_team_id 
                            FROM picks 
                            WHERE original_team_id = ? AND season_year = ? AND round = ?
                        ");
                        $stmtPick->execute([$originalTeamId, $draftYear, $round]);
                        $pickData = $stmtPick->fetch();

                        if ($pickData) {
                            // Pick existe na tabela - pode ter sido trocada
                            $currentOwnerId = $pickData['team_id'];
                            $tradedFromId = ($currentOwnerId != $originalTeamId) ? $pickData['last_owner_team_id'] : null;
                        } else {
                            // Pick não existe na tabela - pertence ao time original
                            $currentOwnerId = $originalTeamId;
                            $tradedFromId = null;
                        }

                        $stmtInsert = $pdo->prepare("
                            INSERT INTO draft_order (draft_session_id, team_id, original_team_id, pick_position, round, traded_from_team_id)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmtInsert->execute([
                            $draftSessionId,
                            $currentOwnerId,
                            $originalTeamId,
                            $pickPosition,
                            $round,
                            $tradedFromId
                        ]);
                    }
                }

                $pdo->commit();
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

            $stmtSession = $pdo->prepare("SELECT * FROM draft_sessions WHERE id = ? AND status = 'setup'");
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou já iniciada']);
                exit;
            }

            // Verificar se tem ordem definida
            $stmtOrder = $pdo->prepare("SELECT COUNT(*) as total FROM draft_order WHERE draft_session_id = ?");
            $stmtOrder->execute([$draftSessionId]);
            $orderCount = $stmtOrder->fetch()['total'];

            if ($orderCount === 0) {
                echo json_encode(['success' => false, 'error' => 'Defina a ordem do draft antes de iniciar']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE draft_sessions SET status = 'in_progress', started_at = NOW() WHERE id = ?");
            $stmt->execute([$draftSessionId]);

            echo json_encode(['success' => true, 'message' => 'Draft iniciado!']);
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

            // Buscar sessão em andamento
            $stmtSession = $pdo->prepare("SELECT * FROM draft_sessions WHERE id = ? AND status = 'in_progress'");
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Draft não está em andamento']);
                exit;
            }

            // Buscar pick atual
            $stmtPick = $pdo->prepare("
                SELECT * FROM draft_order 
                WHERE draft_session_id = ? AND round = ? AND pick_position = ? AND picked_player_id IS NULL
            ");
            $stmtPick->execute([$draftSessionId, $session['current_round'], $session['current_pick']]);
            $currentPick = $stmtPick->fetch();

            if (!$currentPick) {
                echo json_encode(['success' => false, 'error' => 'Nenhuma pick pendente']);
                exit;
            }

            // Verificar se é a vez do time (ou admin pode forçar)
            $pickingTeamId = $isAdmin && $teamIdOverride ? $teamIdOverride : $team['id'];
            
            if (!$isAdmin && (int)$currentPick['team_id'] !== (int)$team['id']) {
                echo json_encode(['success' => false, 'error' => 'Não é a sua vez de escolher']);
                exit;
            }

            // Verificar se jogador está disponível
            $stmtPlayer = $pdo->prepare("SELECT * FROM draft_pool WHERE id = ? AND draft_status = 'available'");
            $stmtPlayer->execute([$playerId]);
            $player = $stmtPlayer->fetch();

            if (!$player) {
                echo json_encode(['success' => false, 'error' => 'Jogador não disponível']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                // Registrar pick
                $stmtUpdate = $pdo->prepare("
                    UPDATE draft_order SET picked_player_id = ?, picked_at = NOW() WHERE id = ?
                ");
                $stmtUpdate->execute([$playerId, $currentPick['id']]);

                // Marcar jogador como draftado
                $stmtDrafted = $pdo->prepare("
                    UPDATE draft_pool SET draft_status = 'drafted', drafted_by_team_id = ?, draft_order = ?
                    WHERE id = ?
                ");
                    // Calcular número absoluto da pick na classe
                    $stmtTotalRound = $pdo->prepare("SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ? AND round = ?");
                    $stmtTotalRound->execute([$draftSessionId, $session['current_round']]);
                    $roundSize = (int) $stmtTotalRound->fetchColumn();
                    $pickNumber = (($session['current_round'] - 1) * $roundSize) + $session['current_pick'];
                $stmtDrafted->execute([$currentPick['team_id'], $pickNumber, $playerId]);

                // Adicionar jogador ao elenco do time
                $stmtAddPlayer = $pdo->prepare("
                    INSERT INTO players (team_id, name, position, age, ovr, role, available_for_trade)
                    VALUES (?, ?, ?, ?, ?, 'Banco', 0)
                ");
                $stmtAddPlayer->execute([
                    $currentPick['team_id'],
                    $player['name'],
                    $player['position'],
                    $player['age'],
                    $player['ovr']
                ]);

                // Avançar para próxima pick
                $nextPick = $session['current_pick'] + 1;
                $nextRound = $session['current_round'];

                // Contar total de picks por rodada
                $stmtCount = $pdo->prepare("SELECT COUNT(*) as total FROM draft_order WHERE draft_session_id = ? AND round = ?");
                $stmtCount->execute([$draftSessionId, $nextRound]);
                $totalPicks = $stmtCount->fetch()['total'];

                if ($nextPick > $totalPicks) {
                    // Próxima rodada
                    $nextRound++;
                    $nextPick = 1;

                    if ($nextRound > $session['total_rounds']) {
                        // Draft concluído
                        $stmtComplete = $pdo->prepare("UPDATE draft_sessions SET status = 'completed', completed_at = NOW() WHERE id = ?");
                        $stmtComplete->execute([$draftSessionId]);
                    } else {
                        $stmtAdvance = $pdo->prepare("UPDATE draft_sessions SET current_round = ?, current_pick = ? WHERE id = ?");
                        $stmtAdvance->execute([$nextRound, $nextPick, $draftSessionId]);
                    }
                } else {
                    $stmtAdvance = $pdo->prepare("UPDATE draft_sessions SET current_pick = ? WHERE id = ?");
                    $stmtAdvance->execute([$nextPick, $draftSessionId]);
                }

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => "Pick realizada! {$player['name']} foi selecionado.",
                    'player' => $player
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro ao fazer pick: ' . $e->getMessage()]);
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

                // Limpar picks
                $pdo->prepare("UPDATE draft_order SET picked_player_id = NULL, picked_at = NULL WHERE draft_session_id = ?")->execute([$draftSessionId]);

                // Resetar sessão
                $pdo->prepare("UPDATE draft_sessions SET status = 'setup', current_round = 1, current_pick = 1, started_at = NULL WHERE id = ?")->execute([$draftSessionId]);

                // Buscar season_id
                $stmtSession = $pdo->prepare("SELECT season_id FROM draft_sessions WHERE id = ?");
                $stmtSession->execute([$draftSessionId]);
                $session = $stmtSession->fetch();

                // Resetar jogadores do pool
                $pdo->prepare("UPDATE draft_pool SET draft_status = 'available', drafted_by_team_id = NULL, draft_order = NULL WHERE season_id = ?")->execute([$session['season_id']]);

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

/**
 * Recalcula a ordem snake do draft após adicionar/remover times
 */
function recalculateSnakeOrder($pdo, $draftSessionId) {
    // Buscar todos os times da rodada 1 ordenados pela posição atual
    $stmt = $pdo->prepare("
        SELECT id, original_team_id, team_id, traded_from_team_id
        FROM draft_order 
        WHERE draft_session_id = ? AND round = 1
        ORDER BY pick_position ASC
    ");
    $stmt->execute([$draftSessionId]);
    $round1 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalTeams = count($round1);
    if ($totalTeams === 0) return;
    
    // Atualizar posições da rodada 1 (ordem normal: 1, 2, 3...)
    $pos = 1;
    foreach ($round1 as $pick) {
        $pdo->prepare("UPDATE draft_order SET pick_position = ? WHERE id = ?")->execute([$pos, $pick['id']]);
        $pos++;
    }
    
    // Atualizar posições da rodada 2 (ordem invertida/snake: n, n-1, n-2...)
    $stmt2 = $pdo->prepare("
        SELECT id, original_team_id 
        FROM draft_order 
        WHERE draft_session_id = ? AND round = 2
    ");
    $stmt2->execute([$draftSessionId]);
    $round2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    // Criar mapa de original_team_id -> posição snake
    $snakePosition = [];
    foreach ($round1 as $idx => $pick) {
        $snakePosition[$pick['original_team_id']] = $totalTeams - $idx; // Inverter
    }
    
    // Atualizar rodada 2
    foreach ($round2 as $pick) {
        $newPos = $snakePosition[$pick['original_team_id']] ?? 1;
        $pdo->prepare("UPDATE draft_order SET pick_position = ? WHERE id = ?")->execute([$newPos, $pick['id']]);
    }
}
