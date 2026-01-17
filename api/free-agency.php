<?php
/**
 * API de Free Agency
 * Gerencia jogadores dispensados e propostas de contratação
 */
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$pdo = db();
$config = loadConfig();
$method = $_SERVER['REQUEST_METHOD'];

ensureTeamFreeAgencyColumns($pdo);

// Limites
$MAX_WAIVERS = 3; // Máximo de dispensas por temporada
$MAX_SIGNINGS = 3; // Máximo de contratações FA por temporada

// Pegar time do usuário
$stmtTeam = $pdo->prepare('SELECT t.*, 
    COALESCE(t.waivers_used, 0) as waivers_used,
    COALESCE(t.fa_signings_used, 0) as fa_signings_used
    FROM teams t WHERE t.user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();
$teamId = $team['id'] ?? null;
$userLeague = $team['league'] ?? $user['league'];
$validLeagues = ['ELITE','NEXT','RISE','ROOKIE'];

// GET - Listar free agents ou propostas
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action === 'list') {
        // Listar todos os free agents da liga (admin pode escolher liga)
        $targetLeague = $userLeague;
        if (($user['user_type'] ?? '') === 'admin') {
            $requestedLeague = strtoupper($_GET['league'] ?? $targetLeague);
            if (in_array($requestedLeague, $validLeagues, true)) {
                $targetLeague = $requestedLeague;
            }
        }

        $stmt = $pdo->prepare('
            SELECT fa.*, 
                   (SELECT COUNT(*) FROM free_agent_offers WHERE free_agent_id = fa.id AND status = "pending") as pending_offers
            FROM free_agents fa 
            WHERE fa.league = ?
            ORDER BY fa.ovr DESC, fa.name ASC
        ');
        $stmt->execute([$targetLeague]);
        $freeAgents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'league' => $targetLeague, 'free_agents' => $freeAgents]);
        exit;
    }
    
    if ($action === 'my_offers') {
        // Minhas propostas enviadas
        if (!$teamId) {
            echo json_encode(['success' => true, 'offers' => []]);
            exit;
        }
        
        $stmt = $pdo->prepare('
            SELECT fao.*, fa.name as player_name, fa.position, fa.ovr, fa.age
            FROM free_agent_offers fao
            JOIN free_agents fa ON fao.free_agent_id = fa.id
            WHERE fao.team_id = ?
            ORDER BY fao.created_at DESC
        ');
        $stmt->execute([$teamId]);
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'offers' => $offers]);
        exit;
    }
    
    if ($action === 'admin_offers') {
        // Admin: Ver todas as propostas pendentes
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $league = $_GET['league'] ?? $userLeague;
        
        $stmt = $pdo->prepare('
            SELECT fao.*, 
                   fa.name as player_name, fa.position, fa.ovr, fa.age, fa.original_team_name,
                   t.name as team_name, t.city as team_city
            FROM free_agent_offers fao
            JOIN free_agents fa ON fao.free_agent_id = fa.id
            JOIN teams t ON fao.team_id = t.id
            WHERE fa.league = ? AND fao.status = "pending"
            ORDER BY fa.name, fao.created_at ASC
        ');
        $stmt->execute([$league]);
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Agrupar por jogador
        $grouped = [];
        foreach ($offers as $offer) {
            $faId = $offer['free_agent_id'];
            if (!isset($grouped[$faId])) {
                $grouped[$faId] = [
                    'player' => [
                        'id' => $faId,
                        'name' => $offer['player_name'],
                        'position' => $offer['position'],
                        'ovr' => $offer['ovr'],
                        'age' => $offer['age'],
                        'original_team' => $offer['original_team_name']
                    ],
                    'offers' => []
                ];
            }
            $grouped[$faId]['offers'][] = [
                'id' => $offer['id'],
                'team_id' => $offer['team_id'],
                'team_name' => $offer['team_city'] . ' ' . $offer['team_name'],
                'notes' => $offer['notes'],
                'created_at' => $offer['created_at']
            ];
        }
        
        echo json_encode(['success' => true, 'players' => array_values($grouped)]);
        exit;
    }
    
    if ($action === 'limits') {
        $waiversUsed = (int)($team['waivers_used'] ?? 0);
        $signingsUsed = (int)($team['fa_signings_used'] ?? 0);
        // Retornar limites do time
        echo json_encode([
            'success' => true,
            'waivers_used' => $waiversUsed,
            'waivers_max' => $MAX_WAIVERS,
            'waivers_remaining' => max(0, $MAX_WAIVERS - $waiversUsed),
            'signings_used' => $signingsUsed,
            'signings_max' => $MAX_SIGNINGS,
            'signings_remaining' => max(0, $MAX_SIGNINGS - $signingsUsed)
        ]);
        exit;
    }
}

// POST - Dispensar jogador ou enviar proposta
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? '';
    $adminOnlyActions = ['approve', 'reject', 'reject_all', 'create_free_agent'];

    if (!$teamId && !in_array($action, $adminOnlyActions, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Você não possui um time']);
        exit;
    }
    if ($action === 'create_free_agent') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }

        $name = trim($data['name'] ?? '');
        $age = isset($data['age']) ? (int)$data['age'] : null;
        $position = strtoupper(trim($data['position'] ?? ''));
        $secondaryPosition = $data['secondary_position'] ?? null;
        $ovr = isset($data['ovr']) ? (int)$data['ovr'] : null;
        $league = strtoupper($data['league'] ?? $userLeague);
        $originalTeamName = trim($data['original_team_name'] ?? '');

        if (!in_array($league, $validLeagues, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Liga inválida']);
            exit;
        }

        if ($name === '' || !$age || !$position || !$ovr) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Preencha nome, idade, posição e OVR']);
            exit;
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO free_agents (name, age, position, secondary_position, ovr, league, original_team_id, original_team_name)
                VALUES (?, ?, ?, ?, ?, ?, NULL, ?)
            ');
            $stmt->execute([
                $name,
                $age,
                $position,
                $secondaryPosition ?: null,
                $ovr,
                $league,
                $originalTeamName ?: null
            ]);

            echo json_encode(['success' => true, 'message' => 'Free agent criado com sucesso!']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao criar free agent: ' . $e->getMessage()]);
        }
        exit;
    }

    
    // Dispensar jogador (waive)
    if ($action === 'waive') {
        $playerId = $data['player_id'] ?? null;
        
        if (!$playerId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID do jogador não informado']);
            exit;
        }
        
        // Verificar limite de dispensas
        $waiversUsed = (int)($team['waivers_used'] ?? 0);
        if ($waiversUsed >= $MAX_WAIVERS) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Limite de {$MAX_WAIVERS} dispensas por temporada atingido"]);
            exit;
        }
        
        // Verificar se o jogador pertence ao time
        $stmtPlayer = $pdo->prepare('SELECT * FROM players WHERE id = ? AND team_id = ?');
        $stmtPlayer->execute([$playerId, $teamId]);
        $player = $stmtPlayer->fetch();
        
        if (!$player) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Jogador não encontrado ou não pertence ao seu time']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Adicionar à free agency
            $stmtFA = $pdo->prepare('
                INSERT INTO free_agents (name, age, position, secondary_position, ovr, league, original_team_id, original_team_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmtFA->execute([
                $player['name'],
                $player['age'],
                $player['position'],
                $player['secondary_position'] ?? null,
                $player['ovr'],
                $team['league'],
                $teamId,
                $team['city'] . ' ' . $team['name']
            ]);
            
            // Remover jogador do time
            $stmtDel = $pdo->prepare('DELETE FROM players WHERE id = ?');
            $stmtDel->execute([$playerId]);
            
            // Incrementar contador de dispensas
            $stmtUpd = $pdo->prepare('UPDATE teams SET waivers_used = COALESCE(waivers_used, 0) + 1 WHERE id = ?');
            $stmtUpd->execute([$teamId]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Jogador dispensado e adicionado à Free Agency',
                'waivers_remaining' => $MAX_WAIVERS - $waiversUsed - 1
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao dispensar jogador: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Enviar proposta para free agent
    if ($action === 'offer') {
        $freeAgentId = $data['free_agent_id'] ?? null;
        $notes = $data['notes'] ?? '';
        
        if (!$freeAgentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID do free agent não informado']);
            exit;
        }
        
        // Verificar se o free agent existe e é da mesma liga
        $stmtFA = $pdo->prepare('SELECT * FROM free_agents WHERE id = ? AND league = ?');
        $stmtFA->execute([$freeAgentId, $team['league']]);
        $freeAgent = $stmtFA->fetch();
        
        if (!$freeAgent) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Free agent não encontrado']);
            exit;
        }

        // Verificar limite de contratações do time
        $signingsUsed = (int)($team['fa_signings_used'] ?? 0);
        if ($signingsUsed >= $MAX_SIGNINGS) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Você atingiu o limite de contratações na temporada']);
            exit;
        }
        
        // Verificar se já enviou proposta
        $stmtCheck = $pdo->prepare('SELECT id FROM free_agent_offers WHERE free_agent_id = ? AND team_id = ?');
        $stmtCheck->execute([$freeAgentId, $teamId]);
        if ($stmtCheck->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Você já enviou uma proposta para este jogador']);
            exit;
        }
        
        // Verificar CAP
        $currentCap = topEightCap($pdo, $teamId);
        $capAfter = $currentCap + $freeAgent['ovr'];
        // Nota: não verificamos CAP aqui pois o admin decide, mas podemos avisar
        
        try {
            $stmtOffer = $pdo->prepare('
                INSERT INTO free_agent_offers (free_agent_id, team_id, notes)
                VALUES (?, ?, ?)
            ');
            $stmtOffer->execute([$freeAgentId, $teamId, $notes]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Proposta enviada! Aguarde a decisão do administrador.'
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao enviar proposta']);
        }
        exit;
    }
    
    // Admin: Aprovar proposta
    if ($action === 'approve') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $offerId = $data['offer_id'] ?? null;
        
        if (!$offerId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID da proposta não informado']);
            exit;
        }
        
        // Buscar proposta e free agent
        $stmtOffer = $pdo->prepare('
            SELECT fao.*, fa.*, t.league as team_league,
                   COALESCE(t.fa_signings_used, 0) as signings_used
            FROM free_agent_offers fao
            JOIN free_agents fa ON fao.free_agent_id = fa.id
            JOIN teams t ON fao.team_id = t.id
            WHERE fao.id = ? AND fao.status = "pending"
        ');
        $stmtOffer->execute([$offerId]);
        $offer = $stmtOffer->fetch();
        
        if (!$offer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Proposta não encontrada ou já processada']);
            exit;
        }
        
        // Verificar limite de contratações do time
        if ((int)$offer['signings_used'] >= $MAX_SIGNINGS) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Time já atingiu o limite de contratações FA']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Criar jogador no time
            $stmtPlayer = $pdo->prepare('
                INSERT INTO players (team_id, name, age, position, secondary_position, ovr, role, available_for_trade)
                VALUES (?, ?, ?, ?, ?, ?, "Banco", 0)
            ');
            $stmtPlayer->execute([
                $offer['team_id'],
                $offer['name'],
                $offer['age'],
                $offer['position'],
                $offer['secondary_position'],
                $offer['ovr']
            ]);
            
            // Atualizar proposta para aceita
            $stmtAccept = $pdo->prepare('UPDATE free_agent_offers SET status = "accepted" WHERE id = ?');
            $stmtAccept->execute([$offerId]);
            
            // Rejeitar outras propostas para o mesmo jogador
            $stmtReject = $pdo->prepare('UPDATE free_agent_offers SET status = "rejected" WHERE free_agent_id = ? AND id != ?');
            $stmtReject->execute([$offer['free_agent_id'], $offerId]);
            
            // Remover da free agency
            $stmtDelFA = $pdo->prepare('DELETE FROM free_agents WHERE id = ?');
            $stmtDelFA->execute([$offer['free_agent_id']]);
            
            // Incrementar contador de contratações
            $stmtUpd = $pdo->prepare('UPDATE teams SET fa_signings_used = COALESCE(fa_signings_used, 0) + 1 WHERE id = ?');
            $stmtUpd->execute([$offer['team_id']]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Jogador contratado com sucesso!'
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao aprovar proposta: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Admin: Rejeitar proposta
    if ($action === 'reject') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $offerId = $data['offer_id'] ?? null;
        
        if (!$offerId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID da proposta não informado']);
            exit;
        }
        
        $stmtReject = $pdo->prepare('UPDATE free_agent_offers SET status = "rejected" WHERE id = ? AND status = "pending"');
        $stmtReject->execute([$offerId]);
        
        if ($stmtReject->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Proposta rejeitada']);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Proposta não encontrada ou já processada']);
        }
        exit;
    }
    
    // Admin: Rejeitar todas as propostas de um jogador
    if ($action === 'reject_all') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $freeAgentId = $data['free_agent_id'] ?? null;
        
        if (!$freeAgentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID do free agent não informado']);
            exit;
        }
        
        $stmtReject = $pdo->prepare('UPDATE free_agent_offers SET status = "rejected" WHERE free_agent_id = ? AND status = "pending"');
        $stmtReject->execute([$freeAgentId]);
        
        echo json_encode(['success' => true, 'message' => 'Todas as propostas foram rejeitadas', 'count' => $stmtReject->rowCount()]);
        exit;
    }
}

// DELETE - Cancelar proposta ou limpar free agency (admin)
if ($method === 'DELETE') {
    $action = $_GET['action'] ?? '';
    
    // Admin: remover um free agent manualmente
    if ($action === 'delete_free_agent') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }

        $freeAgentId = isset($_GET['free_agent_id']) ? (int)$_GET['free_agent_id'] : null;
        if (!$freeAgentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID do free agent não informado']);
            exit;
        }

        $leagueFilter = null;
        if (isset($_GET['league'])) {
            $requestedLeague = strtoupper($_GET['league']);
            if (in_array($requestedLeague, $validLeagues, true)) {
                $leagueFilter = $requestedLeague;
            }
        }

        try {
            $query = 'DELETE FROM free_agents WHERE id = ?';
            $params = [$freeAgentId];
            if ($leagueFilter) {
                $query .= ' AND league = ?';
                $params[] = $leagueFilter;
            }
            $stmtDelete = $pdo->prepare($query);
            $stmtDelete->execute($params);

            if ($stmtDelete->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Free agent não encontrado']);
                exit;
            }

            echo json_encode(['success' => true, 'message' => 'Free agent removido com sucesso']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao remover free agent: ' . $e->getMessage()]);
        }
        exit;
    }

    // Cancelar minha proposta
    if ($action === 'cancel_offer') {
        $offerId = $_GET['offer_id'] ?? null;
        
        if (!$offerId || !$teamId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
            exit;
        }
        
        $stmtDel = $pdo->prepare('DELETE FROM free_agent_offers WHERE id = ? AND team_id = ? AND status = "pending"');
        $stmtDel->execute([$offerId, $teamId]);
        
        if ($stmtDel->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Proposta cancelada']);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Proposta não encontrada']);
        }
        exit;
    }
    
    // Admin: Resetar free agency (limpar tudo)
    if ($action === 'reset') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $league = $_GET['league'] ?? $userLeague;
        
        try {
            $pdo->beginTransaction();
            
            // Deletar propostas de free agents da liga
            $pdo->prepare('
                DELETE fao FROM free_agent_offers fao
                JOIN free_agents fa ON fao.free_agent_id = fa.id
                WHERE fa.league = ?
            ')->execute([$league]);
            
            // Deletar free agents da liga
            $pdo->prepare('DELETE FROM free_agents WHERE league = ?')->execute([$league]);
            
            // Resetar contadores dos times
            $pdo->prepare('UPDATE teams SET waivers_used = 0, fa_signings_used = 0 WHERE league = ?')->execute([$league]);
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Free Agency resetada com sucesso']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao resetar: ' . $e->getMessage()]);
        }
        exit;
    }
}

// ============================================
// SISTEMA DE LEILÃO - AUCTION SYSTEM
// ============================================

// GET - Leilões ativos
if ($method === 'GET' && ($action ?? '') === 'active_auctions') {
    $league = $_GET['league'] ?? $userLeague;
    
    if (($user['user_type'] ?? '') === 'admin') {
        $requestedLeague = strtoupper($_GET['league'] ?? $userLeague);
        if (in_array($requestedLeague, $validLeagues, true)) {
            $league = $requestedLeague;
        }
    }
    
    // Verificar e finalizar leilões expirados
    $stmtExpired = $pdo->prepare("
        UPDATE free_agency_auctions 
        SET status = 'completed'
        WHERE status = 'active' 
        AND end_time <= NOW()
        AND league = ?
    ");
    $stmtExpired->execute([$league]);
    
    // Buscar leilões ativos e recentes
    $stmt = $pdo->prepare("
        SELECT a.*,
               TIMESTAMPDIFF(SECOND, NOW(), a.end_time) as seconds_remaining,
               (SELECT COUNT(*) FROM free_agency_bids WHERE auction_id = a.id) as total_bids,
               wb.team_id as winning_team_id,
               wt.name as winning_team_name
        FROM free_agency_auctions a
        LEFT JOIN free_agency_bids wb ON wb.auction_id = a.id AND wb.is_winning = 1
        LEFT JOIN teams wt ON wt.id = wb.team_id
        WHERE a.league = ?
        AND (a.status = 'active' OR (a.status = 'completed' AND a.end_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)))
        ORDER BY 
            CASE a.status WHEN 'active' THEN 0 ELSE 1 END,
            a.end_time ASC
    ");
    $stmt->execute([$league]);
    $auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Adicionar informação de lance do time atual
    if ($teamId) {
        foreach ($auctions as &$auction) {
            $stmtMyBid = $pdo->prepare("
                SELECT bid_amount FROM free_agency_bids 
                WHERE auction_id = ? AND team_id = ?
            ");
            $stmtMyBid->execute([$auction['id'], $teamId]);
            $myBid = $stmtMyBid->fetch();
            $auction['my_bid'] = $myBid ? (int)$myBid['bid_amount'] : null;
        }
    }
    
    echo json_encode(['success' => true, 'league' => $league, 'auctions' => $auctions]);
    exit;
}

// GET - Pontos do time (ranking points)
if ($method === 'GET' && ($action ?? '') === 'team_points') {
    if (!$teamId) {
        echo json_encode(['success' => true, 'points' => 0]);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT COALESCE(ranking_points, 0) as points FROM teams WHERE id = ?");
    $stmt->execute([$teamId]);
    $result = $stmt->fetch();
    
    // Calcular pontos já usados em leilões ativos
    $stmtUsed = $pdo->prepare("
        SELECT COALESCE(SUM(b.bid_amount), 0) as used_points
        FROM free_agency_bids b
        JOIN free_agency_auctions a ON a.id = b.auction_id
        WHERE b.team_id = ? 
        AND a.status = 'active'
    ");
    $stmtUsed->execute([$teamId]);
    $usedResult = $stmtUsed->fetch();
    
    echo json_encode([
        'success' => true, 
        'total_points' => (int)($result['points'] ?? 0),
        'used_points' => (int)($usedResult['used_points'] ?? 0),
        'available_points' => (int)($result['points'] ?? 0) - (int)($usedResult['used_points'] ?? 0)
    ]);
    exit;
}

// GET - get_team_points (alias for team_points for compatibility)
if ($method === 'GET' && ($action ?? '') === 'get_team_points') {
    if (!$teamId) {
        echo json_encode(['success' => true, 'available_points' => 0]);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT COALESCE(ranking_points, 0) as points FROM teams WHERE id = ?");
    $stmt->execute([$teamId]);
    $result = $stmt->fetch();
    
    echo json_encode([
        'success' => true, 
        'available_points' => (int)($result['points'] ?? 0)
    ]);
    exit;
}

// GET - Leilões recentes (finalizados)
if ($method === 'GET' && ($action ?? '') === 'recent_auctions') {
    $league = $_GET['league'] ?? $userLeague;
    
    $stmt = $pdo->prepare("
        SELECT a.*,
               wt.name as winner_team_name,
               wt.city as winner_team_city,
               (SELECT MAX(bid_amount) FROM free_agency_bids WHERE auction_id = a.id) as winning_bid
        FROM free_agency_auctions a
        LEFT JOIN free_agency_bids wb ON wb.auction_id = a.id AND wb.is_winning = 1
        LEFT JOIN teams wt ON wt.id = wb.team_id
        WHERE a.league = ?
        AND a.status IN ('completed', 'cancelled')
        ORDER BY a.end_time DESC
        LIMIT 20
    ");
    $stmt->execute([$league]);
    $auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar saída
    foreach ($auctions as &$a) {
        $a['winner_team_name'] = $a['winner_team_city'] ? ($a['winner_team_city'] . ' ' . $a['winner_team_name']) : $a['winner_team_name'];
        unset($a['winner_team_city']);
    }
    
    echo json_encode(['success' => true, 'auctions' => $auctions]);
    exit;
}

// POST - Ações de leilão
if ($method === 'POST') {
    $action = $_POST['action'] ?? ($data['action'] ?? '');
    
    // Admin: Iniciar leilão
    if ($action === 'start_auction') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $freeAgentId = $data['free_agent_id'] ?? null;
        $duration = (int)($data['duration'] ?? 20); // minutos, padrão 20
        $minBid = (int)($data['min_bid'] ?? 1);
        $league = $data['league'] ?? $userLeague;
        
        if (!$freeAgentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID do jogador não informado']);
            exit;
        }
        
        // Verificar se o jogador existe na free agency
        $stmtFA = $pdo->prepare("SELECT * FROM free_agents WHERE id = ? AND league = ?");
        $stmtFA->execute([$freeAgentId, $league]);
        $freeAgent = $stmtFA->fetch();
        
        if (!$freeAgent) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Jogador não encontrado na Free Agency']);
            exit;
        }
        
        // Verificar se já existe leilão ativo para este jogador
        $stmtCheck = $pdo->prepare("
            SELECT id FROM free_agency_auctions 
            WHERE free_agent_id = ? AND status IN ('pending', 'active')
        ");
        $stmtCheck->execute([$freeAgentId]);
        if ($stmtCheck->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Já existe um leilão ativo para este jogador']);
            exit;
        }
        
        try {
            $endTime = date('Y-m-d H:i:s', strtotime("+{$duration} minutes"));
            
            $stmtInsert = $pdo->prepare("
                INSERT INTO free_agency_auctions 
                (free_agent_id, player_name, player_position, player_ovr, player_age, league, status, start_time, end_time, min_bid, current_bid, created_by_admin_id)
                VALUES (?, ?, ?, ?, ?, ?, 'active', NOW(), ?, ?, 0, ?)
            ");
            $stmtInsert->execute([
                $freeAgentId,
                $freeAgent['name'],
                $freeAgent['position'],
                $freeAgent['ovr'],
                $freeAgent['age'],
                $league,
                $endTime,
                $minBid,
                $user['id']
            ]);
            
            $auctionId = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true, 
                'message' => "Leilão iniciado! Termina em {$duration} minutos.",
                'auction_id' => $auctionId,
                'end_time' => $endTime
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao iniciar leilão: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Usuário: Fazer lance
    if ($action === 'place_bid') {
        if (!$teamId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Você não tem um time']);
            exit;
        }
        
        $auctionId = (int)($data['auction_id'] ?? 0);
        $bidAmount = (int)($data['bid_amount'] ?? 0);
        
        if (!$auctionId || $bidAmount < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Buscar leilão
            $stmtAuction = $pdo->prepare("
                SELECT * FROM free_agency_auctions 
                WHERE id = ? AND status = 'active' AND end_time > NOW()
                FOR UPDATE
            ");
            $stmtAuction->execute([$auctionId]);
            $auction = $stmtAuction->fetch();
            
            if (!$auction) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Leilão não encontrado ou já encerrado']);
                exit;
            }
            
            // Verificar se lance é maior que o atual
            if ($bidAmount <= $auction['current_bid']) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Lance deve ser maior que {$auction['current_bid']} pontos"]);
                exit;
            }
            
            // Verificar se lance atinge o mínimo
            if ($bidAmount < $auction['min_bid']) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Lance mínimo é {$auction['min_bid']} pontos"]);
                exit;
            }
            
            // Buscar pontos do time
            $stmtPoints = $pdo->prepare("SELECT COALESCE(ranking_points, 0) as points FROM teams WHERE id = ?");
            $stmtPoints->execute([$teamId]);
            $teamPoints = (int)$stmtPoints->fetchColumn();
            
            // Calcular pontos já comprometidos em outros leilões (exceto este)
            $stmtUsed = $pdo->prepare("
                SELECT COALESCE(SUM(b.bid_amount), 0) as used_points
                FROM free_agency_bids b
                JOIN free_agency_auctions a ON a.id = b.auction_id
                WHERE b.team_id = ? 
                AND a.status = 'active'
                AND a.id != ?
            ");
            $stmtUsed->execute([$teamId, $auctionId]);
            $usedPoints = (int)$stmtUsed->fetchColumn();
            
            $availablePoints = $teamPoints - $usedPoints;
            
            if ($bidAmount > $availablePoints) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Pontos insuficientes. Disponível: {$availablePoints}"]);
                exit;
            }
            
            // Remover lance anterior vencedor
            $stmtRemoveWinning = $pdo->prepare("
                UPDATE free_agency_bids SET is_winning = 0 WHERE auction_id = ?
            ");
            $stmtRemoveWinning->execute([$auctionId]);
            
            // Verificar se já existe lance deste time
            $stmtExisting = $pdo->prepare("
                SELECT id FROM free_agency_bids WHERE auction_id = ? AND team_id = ?
            ");
            $stmtExisting->execute([$auctionId, $teamId]);
            $existingBid = $stmtExisting->fetch();
            
            if ($existingBid) {
                // Atualizar lance existente
                $stmtUpdate = $pdo->prepare("
                    UPDATE free_agency_bids 
                    SET bid_amount = ?, is_winning = 1, created_at = NOW()
                    WHERE auction_id = ? AND team_id = ?
                ");
                $stmtUpdate->execute([$bidAmount, $auctionId, $teamId]);
            } else {
                // Inserir novo lance
                $stmtInsert = $pdo->prepare("
                    INSERT INTO free_agency_bids (auction_id, team_id, bid_amount, is_winning)
                    VALUES (?, ?, ?, 1)
                ");
                $stmtInsert->execute([$auctionId, $teamId, $bidAmount]);
            }
            
            // Atualizar lance atual no leilão
            $stmtUpdateAuction = $pdo->prepare("
                UPDATE free_agency_auctions 
                SET current_bid = ?, winning_team_id = ?, winning_bid = ?
                WHERE id = ?
            ");
            $stmtUpdateAuction->execute([$bidAmount, $teamId, $bidAmount, $auctionId]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => "Lance de {$bidAmount} pontos registrado!",
                'bid_amount' => $bidAmount
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao registrar lance: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Admin: Finalizar leilão manualmente
    if ($action === 'finalize_auction') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $auctionId = (int)($data['auction_id'] ?? 0);
        
        if (!$auctionId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID do leilão não informado']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Buscar leilão
            $stmtAuction = $pdo->prepare("
                SELECT a.*, b.team_id as winner_team_id, b.bid_amount as winner_bid
                FROM free_agency_auctions a
                LEFT JOIN free_agency_bids b ON b.auction_id = a.id AND b.is_winning = 1
                WHERE a.id = ? AND a.status = 'active'
            ");
            $stmtAuction->execute([$auctionId]);
            $auction = $stmtAuction->fetch();
            
            if (!$auction) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Leilão não encontrado ou não está ativo']);
                exit;
            }
            
            if ($auction['winner_team_id']) {
                // Tem vencedor - transferir jogador e deduzir pontos
                
                // Adicionar jogador ao time vencedor
                $stmtPlayer = $pdo->prepare("
                    INSERT INTO players (team_id, name, age, position, ovr, role, available_for_trade)
                    VALUES (?, ?, ?, ?, ?, 'Banco', 0)
                ");
                $stmtPlayer->execute([
                    $auction['winner_team_id'],
                    $auction['player_name'],
                    $auction['player_age'],
                    $auction['player_position'],
                    $auction['player_ovr']
                ]);
                
                // Deduzir pontos do time vencedor
                $stmtDeduct = $pdo->prepare("
                    UPDATE teams 
                    SET ranking_points = GREATEST(0, COALESCE(ranking_points, 0) - ?)
                    WHERE id = ?
                ");
                $stmtDeduct->execute([$auction['winner_bid'], $auction['winner_team_id']]);
                
                // Incrementar contador de contratações FA
                $stmtFA = $pdo->prepare("
                    UPDATE teams SET fa_signings_used = COALESCE(fa_signings_used, 0) + 1 WHERE id = ?
                ");
                $stmtFA->execute([$auction['winner_team_id']]);
                
                // Remover da free agency
                $stmtDelFA = $pdo->prepare("DELETE FROM free_agents WHERE id = ?");
                $stmtDelFA->execute([$auction['free_agent_id']]);
            }
            
            // Marcar leilão como completo
            $stmtComplete = $pdo->prepare("
                UPDATE free_agency_auctions 
                SET status = 'completed', end_time = NOW()
                WHERE id = ?
            ");
            $stmtComplete->execute([$auctionId]);
            
            $pdo->commit();
            
            if ($auction['winner_team_id']) {
                $stmtTeamName = $pdo->prepare("SELECT name FROM teams WHERE id = ?");
                $stmtTeamName->execute([$auction['winner_team_id']]);
                $winnerName = $stmtTeamName->fetchColumn();
                
                echo json_encode([
                    'success' => true, 
                    'message' => "{$auction['player_name']} foi para {$winnerName} por {$auction['winner_bid']} pontos!",
                    'winner' => $winnerName,
                    'bid' => $auction['winner_bid']
                ]);
            } else {
                echo json_encode([
                    'success' => true, 
                    'message' => "Leilão encerrado sem lances. {$auction['player_name']} permanece na Free Agency."
                ]);
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao finalizar leilão: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Admin: Cancelar leilão
    if ($action === 'cancel_auction') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $auctionId = (int)($data['auction_id'] ?? 0);
        
        if (!$auctionId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID do leilão não informado']);
            exit;
        }
        
        $stmtCancel = $pdo->prepare("
            UPDATE free_agency_auctions 
            SET status = 'cancelled'
            WHERE id = ? AND status = 'active'
        ");
        $stmtCancel->execute([$auctionId]);
        
        if ($stmtCancel->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Leilão cancelado']);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Leilão não encontrado ou não está ativo']);
        }
        exit;
    }
    
    // Cron/Auto: Processar leilões expirados
    if ($action === 'process_expired_auctions') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $league = $data['league'] ?? null;
        
        try {
            // Buscar leilões expirados
            $query = "
                SELECT a.*, b.team_id as winner_team_id, b.bid_amount as winner_bid
                FROM free_agency_auctions a
                LEFT JOIN free_agency_bids b ON b.auction_id = a.id AND b.is_winning = 1
                WHERE a.status = 'active' AND a.end_time <= NOW()
            ";
            if ($league) {
                $query .= " AND a.league = ?";
            }
            
            $stmtExpired = $pdo->prepare($query);
            $stmtExpired->execute($league ? [$league] : []);
            $expiredAuctions = $stmtExpired->fetchAll();
            
            $processed = 0;
            foreach ($expiredAuctions as $auction) {
                $pdo->beginTransaction();
                try {
                    if ($auction['winner_team_id']) {
                        // Adicionar jogador ao time
                        $stmtPlayer = $pdo->prepare("
                            INSERT INTO players (team_id, name, age, position, ovr, role, available_for_trade)
                            VALUES (?, ?, ?, ?, ?, 'Banco', 0)
                        ");
                        $stmtPlayer->execute([
                            $auction['winner_team_id'],
                            $auction['player_name'],
                            $auction['player_age'],
                            $auction['player_position'],
                            $auction['player_ovr']
                        ]);
                        
                        // Deduzir pontos
                        $stmtDeduct = $pdo->prepare("
                            UPDATE teams 
                            SET ranking_points = GREATEST(0, COALESCE(ranking_points, 0) - ?)
                            WHERE id = ?
                        ");
                        $stmtDeduct->execute([$auction['winner_bid'], $auction['winner_team_id']]);
                        
                        // Incrementar contador FA
                        $stmtFA = $pdo->prepare("
                            UPDATE teams SET fa_signings_used = COALESCE(fa_signings_used, 0) + 1 WHERE id = ?
                        ");
                        $stmtFA->execute([$auction['winner_team_id']]);
                        
                        // Remover da free agency
                        $stmtDelFA = $pdo->prepare("DELETE FROM free_agents WHERE id = ?");
                        $stmtDelFA->execute([$auction['free_agent_id']]);
                    }
                    
                    // Marcar como completo
                    $stmtComplete = $pdo->prepare("UPDATE free_agency_auctions SET status = 'completed' WHERE id = ?");
                    $stmtComplete->execute([$auction['id']]);
                    
                    $pdo->commit();
                    $processed++;
                } catch (Exception $e) {
                    $pdo->rollBack();
                }
            }
            
            echo json_encode([
                'success' => true, 
                'message' => "{$processed} leilões processados",
                'processed' => $processed
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao processar leilões: ' . $e->getMessage()]);
        }
        exit;
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido']);
